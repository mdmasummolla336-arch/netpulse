#!/usr/bin/env python3
"""Loopback-only WebSocket bridge from an authenticated NetPulse session to SSH."""

import asyncio
import ipaddress
import json
import os
import re
import urllib.error
import urllib.request
from urllib.parse import parse_qs, urlsplit
from pathlib import Path

import asyncssh
from websockets.asyncio.server import ServerConnection, serve
from websockets.exceptions import ConnectionClosed

MAX_FRAME_SIZE = 16 * 1024
MAX_INPUT_SIZE = 4096
MAX_SESSION_SECONDS = 30 * 60
USERNAME_RE = re.compile(r"^[A-Za-z0-9._@-]{1,64}$")


class NoRedirectHandler(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, _request, _response, _code, _message, _headers, _new_url):
        return None


def configured_origins(value: str) -> set[str]:
    origins = set()
    for raw_origin in value.split(","):
        origin = raw_origin.strip().rstrip("/")
        parsed = urlsplit(origin)
        if (parsed.scheme not in ("http", "https") or not parsed.netloc
                or parsed.username or parsed.password or parsed.path or parsed.query or parsed.fragment):
            raise ValueError("NETPULSE_SSH_ALLOWED_ORIGINS must contain exact HTTP(S) origins.")
        origins.add(origin)
    if not origins:
        raise ValueError("Configure at least one allowed WebSocket origin.")
    return origins


def valid_api_url(value: str) -> bool:
    try:
        parsed = urlsplit(value)
        hostname = parsed.hostname or ""
        port = parsed.port
    except ValueError:
        return False
    if (parsed.scheme not in ("http", "https") or not hostname
            or parsed.username or parsed.password or parsed.fragment
            or port == 0
            or not parsed.path.endswith("/api.php")
            or parse_qs(parsed.query).get("action") != ["ssh_credentials"]):
        return False
    if parsed.scheme == "https":
        return True
    try:
        address = ipaddress.ip_address(hostname)
    except ValueError:
        return False
    trusted_networks = (
        ipaddress.ip_network("127.0.0.0/8"),
        ipaddress.ip_network("10.0.0.0/8"),
        ipaddress.ip_network("172.16.0.0/12"),
        ipaddress.ip_network("192.168.0.0/16"),
        ipaddress.ip_network("::1/128"),
        ipaddress.ip_network("fc00::/7"),
    )
    return any(address.version == network.version and address in network for network in trusted_networks)


def valid_client_message(message: object) -> tuple[str, dict]:
    if not isinstance(message, dict) or not isinstance(message.get("type"), str):
        raise ValueError("Invalid terminal message.")

    kind = message["type"]
    if kind == "connect":
        device_id = message.get("device_id")
        if isinstance(device_id, bool) or not isinstance(device_id, int) or device_id < 1:
            raise ValueError("Invalid device.")
        return kind, {"device_id": device_id}
    if kind == "input":
        data = message.get("data")
        if not isinstance(data, str) or len(data.encode("utf-8")) > MAX_INPUT_SIZE or "\x00" in data:
            raise ValueError("Invalid terminal input.")
        return kind, {"data": data}
    if kind == "resize":
        cols, rows = message.get("cols"), message.get("rows")
        if (isinstance(cols, bool) or not isinstance(cols, int) or not 20 <= cols <= 300
                or isinstance(rows, bool) or not isinstance(rows, int) or not 5 <= rows <= 100):
            raise ValueError("Invalid terminal size.")
        return kind, {"cols": cols, "rows": rows}
    if kind == "disconnect":
        return kind, {}
    raise ValueError("Unsupported terminal message.")


def valid_ssh_host(host: str) -> bool:
    if not isinstance(host, str) or not host or len(host) > 253:
        return False
    try:
        ipaddress.ip_address(host)
        return True
    except ValueError:
        return bool(re.fullmatch(
            r"[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?"
            r"(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)*\.?",
            host,
        ))


async def fetch_credentials(api_url: str, bridge_token: str, cookie: str, device_id: int) -> dict:
    payload = json.dumps({"device_id": device_id}).encode("utf-8")
    request = urllib.request.Request(api_url, data=payload, method="POST", headers={
        "Content-Type": "application/json",
        "X-NetPulse": "1",
        "X-NetPulse-SSH-Bridge": bridge_token,
        "Cookie": cookie,
    })

    def get_response() -> dict:
        try:
            opener = urllib.request.build_opener(NoRedirectHandler())
            with opener.open(request, timeout=10) as response:
                result = json.loads(response.read(16 * 1024))
        except (urllib.error.URLError, TimeoutError, json.JSONDecodeError) as exc:
            raise RuntimeError("Could not authorize the SSH session.") from exc
        if not isinstance(result, dict):
            raise RuntimeError("Could not authorize the SSH session.")
        return result

    credentials = await asyncio.to_thread(get_response)
    host = credentials.get("host")
    username = credentials.get("username")
    port = credentials.get("port")
    password = credentials.get("password")
    if (not valid_ssh_host(host)
            or not isinstance(username, str) or not USERNAME_RE.fullmatch(username)
            or isinstance(port, bool) or not isinstance(port, int) or not 1 <= port <= 65535
            or not isinstance(password, str)):
        raise RuntimeError("The configured SSH device is invalid.")
    return credentials


async def send_json(websocket: ServerConnection, message: dict) -> None:
    await websocket.send(json.dumps(message, separators=(",", ":")))


async def pipe_output(websocket: ServerConnection, reader) -> None:
    while True:
        chunk = await reader.read(4096)
        if not chunk:
            return
        await send_json(websocket, {"type": "output", "data": chunk.decode("utf-8", "replace")})


async def run_terminal(websocket: ServerConnection, process) -> None:
    async def receive_input() -> None:
        async for raw_message in websocket:
            try:
                message = json.loads(raw_message)
                kind, data = valid_client_message(message)
            except (json.JSONDecodeError, ValueError):
                await send_json(websocket, {"type": "error", "message": "Invalid terminal input."})
                continue

            if kind == "input":
                process.stdin.write(data["data"].encode("utf-8"))
                await process.stdin.drain()
            elif kind == "resize":
                process.change_terminal_size(data["cols"], data["rows"])
            elif kind == "disconnect":
                return

    tasks = [
        asyncio.create_task(receive_input()),
        asyncio.create_task(pipe_output(websocket, process.stdout)),
        asyncio.create_task(pipe_output(websocket, process.stderr)),
    ]
    try:
        done, pending = await asyncio.wait(tasks, return_when=asyncio.FIRST_COMPLETED)
        for task in done:
            task.result()
    finally:
        for task in tasks:
            if not task.done():
                task.cancel()
        await asyncio.gather(*tasks, return_exceptions=True)


async def handle_connection(
    websocket: ServerConnection,
    *,
    api_url: str,
    bridge_token: str,
    known_hosts: str,
) -> None:
    cookie = websocket.request.headers.get("Cookie", "")
    if not cookie:
        await send_json(websocket, {"type": "error", "message": "Sign in as an administrator to use SSH."})
        await websocket.close(code=4401)
        return

    try:
        raw = await asyncio.wait_for(websocket.recv(), timeout=10)
        if not isinstance(raw, str) or len(raw.encode("utf-8")) > MAX_FRAME_SIZE:
            raise ValueError("Invalid connect request.")
        kind, data = valid_client_message(json.loads(raw))
        if kind != "connect":
            raise ValueError("A device must be selected before connecting.")
        credentials = await fetch_credentials(api_url, bridge_token, cookie, data["device_id"])
        async with asyncssh.connect(
            credentials["host"],
            port=credentials["port"],
            username=credentials["username"],
            password=credentials["password"] or None,
            client_keys=[],
            known_hosts=known_hosts,
            connect_timeout=10,
            agent_forwarding=False,
        ) as connection:
            process = await connection.create_process(
                term_type="xterm",
                term_size=(24, 80),
                encoding=None,
            )
            await send_json(websocket, {
                "type": "connected",
                "device": credentials["name"],
            })
            await asyncio.wait_for(run_terminal(websocket, process), timeout=MAX_SESSION_SECONDS)
            process.close()
            await process.wait_closed()
    except asyncio.TimeoutError:
        await send_json(websocket, {"type": "error", "message": "SSH session timed out."})
    except ConnectionClosed:
        pass
    except (asyncssh.Error, OSError, RuntimeError, ValueError, json.JSONDecodeError):
        try:
            await send_json(websocket, {
                "type": "error",
                "message": "SSH connection failed. Check device credentials and the server known_hosts file.",
            })
        except ConnectionClosed:
            pass
    finally:
        await websocket.close()


async def main() -> None:
    bridge_token = os.environ.get("NETPULSE_SSH_BRIDGE_TOKEN", "")
    api_url = os.environ.get("NETPULSE_SSH_API_URL", "")
    origins = configured_origins(os.environ.get("NETPULSE_SSH_ALLOWED_ORIGINS", ""))
    known_hosts = os.path.expanduser(os.environ.get("NETPULSE_SSH_KNOWN_HOSTS", "~/.ssh/known_hosts"))
    if len(bridge_token) < 32:
        raise RuntimeError("NETPULSE_SSH_BRIDGE_TOKEN must be a random secret of at least 32 characters.")
    if not valid_api_url(api_url):
        raise RuntimeError("Configure NETPULSE_SSH_API_URL to the internal PHP credentials endpoint.")
    if not Path(known_hosts).is_file() or not os.access(known_hosts, os.R_OK):
        raise RuntimeError("SSH host key verification requires an existing known_hosts file.")

    host = os.environ.get("NETPULSE_SSH_BIND", "127.0.0.1")
    if host not in ("127.0.0.1", "::1", "localhost"):
        raise RuntimeError("The SSH WebSocket service must bind to loopback only.")
    port = int(os.environ.get("NETPULSE_SSH_PORT", "8765"))

    async def handler(websocket: ServerConnection) -> None:
        await handle_connection(
            websocket,
            api_url=api_url,
            bridge_token=bridge_token,
            known_hosts=known_hosts,
        )

    async with serve(
        handler,
        host,
        port,
        origins=origins,
        max_size=MAX_FRAME_SIZE,
        max_queue=8,
        ping_interval=20,
        ping_timeout=20,
    ):
        await asyncio.Future()


if __name__ == "__main__":
    try:
        asyncio.run(main())
    except KeyboardInterrupt:
        pass
