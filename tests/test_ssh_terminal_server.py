import asyncio
import json
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

import asyncssh
import websockets
from ssh_terminal_server import (
    configured_origins,
    handle_connection,
    valid_api_url,
    valid_client_message,
    valid_ssh_host,
)


class SSHBridgeValidationTests(unittest.TestCase):
    def test_origins_are_exact_and_paths_are_rejected(self):
        self.assertEqual(
            configured_origins(
                "https://netpulse.example, http://localhost:8080/, http://10.99.99.19:8080"
            ),
            {"https://netpulse.example", "http://localhost:8080", "http://10.99.99.19:8080"},
        )
        with self.assertRaises(ValueError):
            configured_origins("*")
        with self.assertRaises(ValueError):
            configured_origins("https://netpulse.example/ssh-ws")

    def test_connect_message_requires_positive_integer_id(self):
        self.assertEqual(
            valid_client_message({"type": "connect", "device_id": 7}),
            ("connect", {"device_id": 7}),
        )
        for device_id in (0, -1, True, "7"):
            with self.subTest(device_id=device_id), self.assertRaises(ValueError):
                valid_client_message({"type": "connect", "device_id": device_id})

    def test_terminal_input_and_resize_are_bounded(self):
        self.assertEqual(
            valid_client_message({"type": "input", "data": "show version\r"}),
            ("input", {"data": "show version\r"}),
        )
        with self.assertRaises(ValueError):
            valid_client_message({"type": "input", "data": "x" * 5000})
        self.assertEqual(
            valid_client_message({"type": "resize", "cols": 120, "rows": 40}),
            ("resize", {"cols": 120, "rows": 40}),
        )
        with self.assertRaises(ValueError):
            valid_client_message({"type": "resize", "cols": 10000, "rows": 40})

    def test_host_validation_blocks_shell_input(self):
        self.assertTrue(valid_ssh_host("192.0.2.1"))
        self.assertTrue(valid_ssh_host("router.example"))
        self.assertFalse(valid_ssh_host("router;touch-bad"))
        self.assertFalse(valid_ssh_host("-oProxyCommand=bad"))

    def test_credentials_endpoint_requires_secure_trusted_url(self):
        self.assertTrue(valid_api_url("https://netpulse.example/api.php?action=ssh_credentials"))
        self.assertTrue(valid_api_url("http://127.0.0.1/api.php?action=ssh_credentials"))
        self.assertTrue(valid_api_url("http://10.99.99.19:8080/api.php?action=ssh_credentials"))
        self.assertTrue(valid_api_url("http://[fd00::19]/api.php?action=ssh_credentials"))
        self.assertFalse(valid_api_url("http://netpulse.example/api.php?action=ssh_credentials"))
        self.assertFalse(valid_api_url("http://8.8.8.8/api.php?action=ssh_credentials"))
        self.assertFalse(valid_api_url("https://netpulse.example/api.php?action=devices"))
        self.assertFalse(valid_api_url("https://user:pass@netpulse.example/api.php?action=ssh_credentials"))


class SSHTerminalFlowTests(unittest.IsolatedAsyncioTestCase):
    async def test_authenticated_websocket_connects_and_relays_to_local_ssh(self):
        class LocalSSHServer(asyncssh.SSHServer):
            def begin_auth(self, _username):
                return False

        async def shell(process):
            process.stdout.write("READY\r\n")
            while True:
                data = await process.stdin.read(1024)
                if not data:
                    return
                process.stdout.write("ACK:" + data)

        async def bridge_handler(websocket):
            await handle_connection(
                websocket,
                api_url="https://netpulse.example/api.php?action=ssh_credentials",
                bridge_token="test-bridge-token",
                known_hosts=known_hosts,
            )

        with tempfile.TemporaryDirectory() as temp_dir:
            host_key = asyncssh.generate_private_key("ssh-ed25519")
            ssh_server = await asyncssh.create_server(
                LocalSSHServer,
                "127.0.0.1",
                0,
                server_host_keys=[host_key],
                process_factory=shell,
            )
            ssh_port = ssh_server.get_port()
            known_hosts_path = Path(temp_dir) / "known_hosts"
            known_hosts_path.write_text(
                f"[127.0.0.1]:{ssh_port} {host_key.export_public_key('openssh').decode().strip()}\n",
                encoding="utf-8",
            )
            known_hosts = str(known_hosts_path)

            async def credentials(*_args):
                return {
                    "host": "127.0.0.1",
                    "port": ssh_port,
                    "username": "netadmin",
                    "password": "",
                    "name": "Local test device",
                }

            try:
                async with websockets.serve(
                    bridge_handler,
                    "127.0.0.1",
                    0,
                    origins=["http://localhost"],
                ) as bridge:
                    bridge_port = bridge.sockets[0].getsockname()[1]
                    with patch("ssh_terminal_server.fetch_credentials", credentials):
                        async with websockets.connect(
                            f"ws://127.0.0.1:{bridge_port}",
                            origin="http://localhost",
                            additional_headers={"Cookie": "PHPSESSID=authorized-admin-session"},
                        ) as browser:
                            await browser.send('{"type":"connect","device_id":7}')
                            connected = json.loads(await asyncio.wait_for(browser.recv(), timeout=3))
                            self.assertEqual(
                                connected,
                                {"type": "connected", "device": "Local test device"},
                            )

                            async def read_until(expected):
                                output = ""
                                while expected not in output:
                                    message = json.loads(await asyncio.wait_for(browser.recv(), timeout=3))
                                    self.assertEqual(message["type"], "output")
                                    output += message["data"]
                                return output

                            await read_until("READY")
                            await browser.send('{"type":"input","data":"probe\\r"}')
                            self.assertIn("ACK:probe", await read_until("ACK:probe"))
                            await browser.send('{"type":"disconnect"}')
            finally:
                ssh_server.close()
                await ssh_server.wait_closed()


if __name__ == "__main__":
    unittest.main()
