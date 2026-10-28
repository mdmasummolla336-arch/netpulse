# NetPulse

A modern, self-hosted **network monitoring & mapping** web application — a spiritual successor to MikroTik's *The Dude*, rebuilt in plain PHP with a clean web UI.

NetPulse renders your network as interactive maps (devices, links, sub-maps), monitors availability in real time (ICMP / TCP / SNMP), shows **live Rx/Tx traffic per interface** with utilization-based link colors, records traffic history into graphs, and sends **Telegram** alerts — all from a single PHP app you run on your own server.

It can **import an existing `The Dude` database** (`dude.db`) so you don't have to rebuild your topology from scratch, and you can keep editing maps, devices and links directly from the browser.

> Built by a WISP operator to replace an aging Dude install on a real network. No Java, no Windows agent — just PHP + your browser.

## Screenshots

> The screenshots below use a **fictional example network** — no real device data.

![Network map (dark theme)](docs/screenshots/example-map-dark.png)

*Interactive map with device icons, sub-maps, three-state status colors, live per-interface Rx/Tx and utilization-based link colors (blue -> green -> yellow -> orange -> red).*

![Network map (light theme)](docs/screenshots/example-map-light.png)

*The same map in light theme.*

![Traffic graph](docs/screenshots/example-graph.png)

*Per-link traffic history recorded from SNMP polling.*

![Sign-in](docs/screenshots/login-en.png)

*Sign-in screen with a language picker — pick your language on first launch.*

## Features

- **Interactive maps** — devices, links and sub-maps rendered as SVG, faithfully following the Dude layout (positions, link styles: solid / dotted / dashed, thickness). Auto-sized sub-map circles.
- **Import from The Dude** — one-click import from an existing `dude.db` (maps, devices, device types, icons, probes, services, links, SNMP profiles). Maps keep their original Dude order.
- **Live monitoring** — three-state status (up / pending / down) like Dude. Non-OLT devices can use automatic mode (Dude-style service selection), ping plus services, or services only; the built-in HTTPS reachability probe checks TCP port 443. Selected OLT devices always use ICMP only and are marked down after three consecutive failed ping checks; other service checks never override OLT reachability. If a required ICMP probe is unavailable, the monitor preserves the existing device status instead of reporting a false outage. Devices or services with monitoring disabled appear as unknown in status views.
- **Real per-interface traffic** — SNMP polling of `ifHCInOctets`/`ifHCOutOctets` (64-bit, with 32-bit fallback) turned into live Rx/Tx bps on each link.
- **Core Router MX80 categories** — a dedicated section for device `1000004` (`10.255.240.9`) discovers names and descriptions from `ifName` and `ifAlias`, exposes only non-empty descriptions, and polls only administrator-selected interfaces. It uses SNMPv1 for this module without changing the shared SNMP profile. Admins can create nested categories, assign interfaces, and grant individual users category access; the API enforces those grants for graph lists and history. The MX80 is excluded from generic Ports, Optical/SFP and legacy graph sources; existing history is retained.
- **Port-down whistle alerts** — the open NetPulse page plays a whistle when a monitored port changes to down. The sound toggle is saved in the browser; browser audio rules may require one click or key press after opening the page.
- **OLT monitoring policy** — only OLT devices enabled with **Monitor device** are polled, using ICMP only. OLTs are excluded from SNMP traffic/interface polling, optical polling, manual SNMP interface discovery, and generic interface graphs. Existing OLT records, map links, and historical data are retained; existing Map nodes and Dashboard entries continue to show the device's ICMP status. Other device types retain their existing polling behavior.
- **Optical/SFP power history** — SNMP Rx/Tx optical power templates for Cisco Nexus, MikroTik RouterOS, BDCOM and VSOL V1600D4-DP. BDCOM reads the `ifSfpParamTable` (`1.3.6.1.4.1.3320.9.63.1.7.1`) up to four lanes per port; readings are hundredths of dBm and `-65535` means unavailable. VSOL reads its four GE interface power columns as dBm strings and excludes PON interfaces.
- **Utilization-based link colors** — link color shifts across a full spectrum based on interface utilization %, visible even at low load.
- **Cacti-style graphs** — searchable SNMP map-link and discovered-port sources, multi-card Rx/Tx charts, hour/day/week/month/year ranges, rate summaries, hover details, favorites and per-user graph permissions. MX80 category graphs are managed separately in Core Router MX80.
- **Telegram notifications** — native PHP (no `wget`/`curl` shell-out), fully configurable in the UI. Alerts are queued: if Telegram is unreachable they are retried with back-off instead of being lost, and a down/up pair that piles up while Telegram is offline is merged into one *"was down from X to Y"* message.
- **User roles** — `administrator` (full access incl. backup/restore & resetting others' passwords), `admin` (user management), `employee` (map dashboard and explicitly granted MX80 categories, without live map-link traffic values), `user` (Graphs and explicitly granted MX80 categories + change own password).
- **Login history** — admins can review the 200 most recent successful logins under Settings -> Users; only usernames and timestamps are stored, with history capped at 500 entries.
- **Editing from the web** — add/edit/delete devices, links (line type via dialog) and maps directly in the browser.
- **Admin SSH terminal** — `admin` and `administrator` users can open a device shell from anywhere through the NetPulse HTTPS site and its WSS reverse proxy. The WebSocket bridge stays loopback-only; device credentials remain server-side and SSH host keys must be trusted in `known_hosts`.
- **Backup / restore** and re-import from the Settings screen.
- **Multi-language** — UI available in English, Slovak, Czech, German, Polish and Hungarian; pick your language on the login screen or later in Settings -> Appearance (saved in your browser).
- **Automatic time zone** — detected from the server on first run, overridable in Settings -> Appearance. Event times and Telegram messages always match your local clock.
- **Self-maintaining database** — status and traffic history are sampled and pruned automatically, so the database stays small and fast no matter how long NetPulse runs.
- **Crash-resistant workers** — each monitoring cycle probes the whole network first and then records all results in one short transaction, so a busy database never leaves half-written state, duplicate events or dangling outages.
- **Modern UI** — consistent dark theme, responsive layout with touch controls, sortable tables with sticky headers, clean SVG device icons.

## Requirements

- **PHP 8.1+** (CLI + FPM) with **PDO**: `pdo_sqlite` (default) or `pdo_mysql`
- A web server — **nginx + php-fpm** recommended (Apache + mod_php works too)
- For live **ICMP** status: **`fping`** (`apt install fping`) and PHP's `exec()` must be enabled (not listed in `disable_functions`). Without it, NetPulse automatically falls back to TCP-connect checks, so it still works on locked-down/shared hosting.
- For **SNMP traffic**: the **net-snmp** CLI tools **`snmpget` / `snmpwalk`** (`apt install snmp`) *or* the PHP `snmp` extension
- SQLite works out of the box (no DB server); MySQL 8 / MariaDB 10.6+ (including MariaDB 11.8) is optional for larger / multi-user setups. For MySQL create a separate database with `CHARACTER SET utf8mb4` and set `DB_DRIVER` to `mysql` directly in `config.php` (PHP-FPM clears environment variables by default). Changing the driver only points NetPulse at that separate database: it does not migrate, copy, or delete the existing SQLite database. Importing from The Dude needs `pdo_sqlite` either way, because `dude.db` itself is SQLite.

On Debian/Ubuntu a typical install:

```bash
sudo apt install php-cli php-fpm php-sqlite3 fping snmp
# optional: php-mysql (for MySQL) ,  php-snmp (instead of the net-snmp CLI tools)
```

### MariaDB setup without changing SQLite

Install `mariadb-server` and `php-mysql`, create a new empty database and a dedicated application user, and grant that user access only to the new database. Then set `DB_DRIVER` to `mysql` and enter its host, database, user, and password in `config.php`. NetPulse creates its base schema automatically on first startup; no Dude import is required. Keep `SQLITE_PATH` unchanged: the existing SQLite file is left in place and is not migrated automatically. To return to the original database, set `DB_DRIVER` back to `sqlite`. Test schema initialization with `php tests/schema_smoke.php` and an isolated, empty database; never point that test at production data.

## Quick start (SQLite)

```bash
git clone https://github.com/<your-user>/netpulse.git
cd netpulse

# make the data dir writable by the web server
mkdir -p data && chmod 775 data

# serve it (dev only) — or point nginx/apache at this folder
php -S 0.0.0.0:8080
```

Open `http://localhost:8080/`. On first run the schema is created automatically.
Default login is **admin / admin** — **change the password immediately** in Settings.

The PHP development server does not provide the WebSocket reverse proxy required by the browser SSH terminal. To use SSH, configure the separate SSH bridge service and a `/ssh-ws/` reverse-proxy route as described in [Production (nginx + php-fpm)](#production-nginx--php-fpm).

### Import your existing Dude database

Copy your `dude.db` into `data/` and run:

```bash
php import_dude.php data/dude.db
```

...or use **Settings -> Backup & import -> Import from Dude** in the web UI. Your `dude.db` is private and is **git-ignored** — it never ends up in the repository.

## Production (nginx + php-fpm)

**1. Web app** — point an nginx `server {}` block at the project folder using a PHP-FPM pool:

```nginx
server {
    listen 80;
    server_name netpulse.example.com;
    root /home/youruser/netpulse;
    index index.php;
    client_max_body_size 64m;                 # matches .user.ini (import dude.db / restore backup)

    location / { try_files $uri $uri/ /index.php?$query_string; }
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;   # adjust to your FPM socket
    }
    location ~ ^/(data|\.git) { deny all; }   # never serve the database / sessions / git
    # CLI workers and libraries are not web pages (they also refuse to run outside the CLI)
    location ~ ^/(monitor|snmp_poll|cleanup|diag2?|import_dude|importer|DudeParser|db|config|telegram|snmp_lib|probe_lib|auth|cli_guard)\.php$ { deny all; }
}
```

**2. Permissions** — `data/` must be writable by the FPM pool user (sessions are auto-created in `data/sessions`):

```bash
chmod -R u+rwX data && sudo chown -R www-data:www-data data   # use your FPM user
```

**3. Background workers** — monitoring runs from the CLI, **not** from web requests. There are two:

- **Availability monitor** — `monitor.php` does **one pass per run** (it does *not* loop). Run it on a schedule.

  Cron (60-second granularity):

  ```cron
  * * * * * cd /home/youruser/netpulse && php monitor.php >/dev/null 2>&1
  ```

  …or, for faster (~15 s) down-detection, a systemd service that loops:

  ```ini
  # /etc/systemd/system/netpulse-monitor.service
  [Unit]
  Description=NetPulse availability monitor
  After=network.target
  [Service]
  User=www-data
  WorkingDirectory=/home/youruser/netpulse
  ExecStart=/bin/sh -c 'while true; do php monitor.php; sleep 15; done'
  Restart=always
  [Install]
  WantedBy=multi-user.target
  ```

- **SNMP traffic poller** — `snmp_poll.php` **has a built-in loop**; the interval comes from Settings:

  ```ini
  # /etc/systemd/system/netpulse-snmp.service
  [Unit]
  Description=NetPulse SNMP traffic poller
  After=network.target
  [Service]
  User=www-data
  WorkingDirectory=/home/youruser/netpulse
  ExecStart=/usr/bin/php snmp_poll.php loop
  Restart=always
  [Install]
  WantedBy=multi-user.target
  ```

Enable both:

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now netpulse-monitor netpulse-snmp
```

### Browser SSH terminal (admin and administrator roles)

The SSH terminal needs a separate Python service. Both `admin` and `administrator` roles can use it. To connect while away from the trusted LAN, publish NetPulse behind a publicly reachable domain with valid HTTPS, then proxy `/ssh-ws/` as WSS on that same HTTPS site. The SSH service itself must continue listening only on loopback; never open its port (default `8765`) on the firewall or expose it directly to the network.

Install the service dependencies in a dedicated virtual environment:

```bash
sudo apt install python3-venv
cd /home/youruser/netpulse
python3 -m venv .venv-ssh
.venv-ssh/bin/pip install -r requirements-ssh.txt
```

Provision the same random secret (at least 32 characters) to PHP-FPM as `NETPULSE_SSH_BRIDGE_TOKEN` and to the SSH service environment file. Set `NETPULSE_SSH_API_URL` to this installation's `api.php?action=ssh_credentials` URL, `NETPULSE_SSH_ALLOWED_ORIGINS` to the exact browser origin (scheme and hostname, with a port only if non-standard), and `NETPULSE_SSH_KNOWN_HOSTS` to a readable file containing device host keys trusted out-of-band.

For remote access over the public Internet, use the exact HTTPS address that you open in the browser in both settings. A domain name is usually easiest:

```ini
NETPULSE_SSH_API_URL=https://netpulse.example.com/api.php?action=ssh_credentials
NETPULSE_SSH_ALLOWED_ORIGINS=https://netpulse.example.com
NETPULSE_SSH_KNOWN_HOSTS=/home/youruser/.ssh/known_hosts
```

If you use port forwarding and open NetPulse by its public IP instead of a domain, HTTPS must have a valid certificate whose subject alternative name includes that exact IP address. For example, if the public IP is `203.0.113.10`:

```ini
NETPULSE_SSH_API_URL=https://203.0.113.10/api.php?action=ssh_credentials
NETPULSE_SSH_ALLOWED_ORIGINS=https://203.0.113.10
NETPULSE_SSH_KNOWN_HOSTS=/home/youruser/.ssh/known_hosts
```

Forward TCP port `443` on the router to port `443` on the NetPulse/nginx server, and configure nginx to serve both the web app and `/ssh-ws/` over HTTPS. If you expose a non-standard public port, include it in both URLs/origins (for example, `https://203.0.113.10:8443`) and forward that port to nginx. Reserve the server's private LAN IP so the forwarding target does not change. Do not forward the SSH bridge port (`8765`) or expose it outside loopback. If you cannot get a trusted certificate for the public IP, use a domain/DDNS name pointed at that IP and a certificate for the domain instead; do not use public HTTP for admin access.

The public address/domain must reach the NetPulse server, HTTPS must be working, and the API URL must be reachable by the SSH bridge service without redirecting. Keep SSH devices reachable from the server as required. For a private-IP HTTP installation restricted to a trusted LAN or VPN, use the same private address and port as the browser, for example:

```ini
NETPULSE_SSH_API_URL=http://10.99.99.19:8080/api.php?action=ssh_credentials
NETPULSE_SSH_ALLOWED_ORIGINS=http://10.99.99.19:8080
NETPULSE_SSH_KNOWN_HOSTS=/home/youruser/.ssh/known_hosts
```

Replace the example address and port with your NetPulse server's actual private IP and HTTP port. Plain HTTP is accepted only for loopback and RFC1918/IPv6 unique-local IP addresses; never use HTTP for Internet access because session cookies, terminal contents, and bridge credentials could be intercepted. The browser origin must match `NETPULSE_SSH_ALLOWED_ORIGINS` exactly. The bridge refuses API redirects so session cookies and the bridge secret are not forwarded to another host. Never disable SSH host-key checking or accept unknown keys automatically. Protect the environment file so only root and the required service account can read it. Use strong, unique NetPulse admin passwords and, if available at the HTTPS reverse proxy, additional MFA.

Example systemd unit:

```ini
# /etc/systemd/system/netpulse-ssh.service
[Unit]
Description=NetPulse SSH WebSocket bridge
After=network.target
[Service]
User=www-data
WorkingDirectory=/home/youruser/netpulse
EnvironmentFile=/etc/netpulse/ssh-bridge.env
ExecStart=/home/youruser/netpulse/.venv-ssh/bin/python ssh_terminal_server.py
Restart=on-failure
NoNewPrivileges=true
PrivateTmp=true
[Install]
WantedBy=multi-user.target
```

Add this WebSocket reverse-proxy location to the same nginx HTTPS `server` block that serves the public NetPulse hostname, before the PHP location:

```nginx
location ^~ /ssh-ws/ {
    proxy_pass http://127.0.0.1:8765/;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_read_timeout 1800s;
}
```

Ensure PHP-FPM receives `NETPULSE_SSH_BRIDGE_TOKEN` from a protected environment source, then enable the service with `sudo systemctl enable --now netpulse-ssh`. The web interface does not expose device passwords or private keys. Device host keys must already be trusted in the configured `known_hosts` file; this guide does not claim an SSH connection succeeds until a device is actually tested.

> Note: `fping` needs raw-socket rights. The Debian `fping` package ships with the needed capability by default; if ICMP still fails, NetPulse falls back to TCP checks automatically.

Configuration lives in `config.php` — DB driver, MySQL credentials (if used), check method (`auto` / `tcp` / `icmp`) and the down-after grace period. Telegram, SNMP profiles and the poll interval are set in the **Settings** screen.

## Configuration highlights (`config.php`)

| Key | Purpose |
|---|---|
| `APP_NAME` | Name shown in header, title and login |
| `DB_DRIVER` | `sqlite` (default) or `mysql` |
| `CHECK_METHOD` | `auto` (ping if possible else TCP), `tcp`, or `icmp` |
| `TCP_FALLBACK_PORTS` | Ports probed for TCP availability (Winbox 8291, 80, 443, 22, ...) |
| `DOWN_AFTER` | Seconds of no response before a device is marked down |
| `USE_FPING` | Use `fping` for fast parallel ICMP |
| `APP_TIMEZONE` | Time zone; empty = detect from the server automatically |
| `HISTORY_DAYS` | How many days of status history to keep (default 14) |
| `HISTORY_EVERY` | Latency sampling interval in seconds (default 60); status changes are always recorded |
| `TRAFFIC_DAYS` | How many days of per-link traffic history to keep (default 90) |

The values in `config.php` are starting defaults. Time zone, history retention, Telegram, SNMP profiles and poll intervals can all be changed at runtime in the **Settings** screen, which stores them in the database and takes precedence over `config.php`.

## Upgrading

Replace the application files (keep `config.php` and `data/`) and restart the workers:

```bash
sudo systemctl restart netpulse-monitor netpulse-snmp
```

Database migrations run automatically on the first request or monitoring cycle — new columns, tables and indexes are added in place, existing data is kept.

## Maintenance

NetPulse prunes its own history during monitoring cycles, so under normal operation there is nothing to do. Traffic-history retention uses the configured `TRAFFIC_DAYS` value and preserves favorite graph data. Two things are worth knowing.

**Why pruning matters.** Every monitoring cycle writes one row per device. On a 150-device network checked every 25 seconds that is roughly half a million rows a day, so without retention the database grows into gigabytes and queries slow down until writes start timing out. Defaults keep 14 days of status history (sampled once a minute) and 90 days of traffic history; tune them in Settings -> Traffic measurement.

**One-time cleanup.** If you are upgrading from an older version whose database has already grown, run the bundled maintenance script. It deletes expired history (without deleting favorite graph history), creates any missing indexes, removes stale session files and, for SQLite, compacts the file with `VACUUM`:

```bash
# dry run - shows row counts and what would be deleted, changes nothing
php cleanup.php

# for real: keep 14 days of status history and 30 days of traffic history
sudo systemctl stop netpulse-monitor netpulse-snmp
php cleanup.php --run 14 --tok 30
sudo systemctl start netpulse-monitor netpulse-snmp
```

Run it as the same user the web server uses (`sudo -u www-data php cleanup.php …`) — the maintenance scripts refuse to run as root, because root-owned `-wal`/`-shm` files would lock the web app and the monitor out of the database. And **always stop the workers first** — `VACUUM` cannot shrink the file while another process holds the database open, and it will take far longer.

## Troubleshooting

Two diagnostic scripts ship with the project. Neither modifies anything.

```bash
php diag.php                    # environment, fping, device counts, Telegram config, recent events
php diag.php "Device name"      # plus the status history of one device
php diag2.php 2026-09-19        # outage detection lag and gaps in monitoring for a given day
```

**Alerts arrive late, or not at all.** Run `php diag2.php <date>`. The *lag* column is the delay between a device going silent and the outage being declared; roughly `DOWN_AFTER` plus one cycle is normal. Large *gaps* mean the monitor was not running at all — check `journalctl -u netpulse-monitor` for that window.

**Monitoring a port behind another device.** Each device has a *check method* (device window → General): *Automatic* (default, like The Dude — a device without a ping service is not pinged, only its ports decide), *Ping + services* (up if ping or any service answers) and *Services only* (up only if **all** its services answer, never pinged). Use *Services only* for things like a camera or switch reached through a port-forward on an antenna's IP — otherwise the antenna answering ping would hide the outage.

**A service is red but the device is green.** The device answers ping, but one of its services does not — e.g. an imported *dude* probe that checks TCP port 2210 of a server that no longer runs The Dude. The *Services* list shows what each service checks, and *Tools → Test now* in the device window runs ping and every service check on the spot. Delete services you no longer need.

**No alert for a short outage.** Outages shorter than `DOWN_AFTER` never turn red and never notify, by design. Lower it in `config.php` if you want to catch brief drops, at the cost of more messages from flapping devices.

**Telegram messages stopped arriving.** `php diag.php` shows how many messages are waiting in the queue. While Telegram is unreachable, messages are retried with increasing delay (30 s up to 15 min) and dropped after 24 hours; the log shows *"odoslanie zlyhalo"* with the HTTP code. A wrong bot token or a bot that is not a member of the chat also shows up here.

**`database is locked` in the logs.** Make sure `journal_mode` is `wal` (`php diag.php` prints it) and that the indexes exist — run `php cleanup.php --run`. An occasional *"výsledky NEZAPÍSANÉ (DB zamknutá)"* line is harmless: that cycle's results are discarded as a whole and the next cycle measures again, so nothing is half-written.

**Monitoring cycle is slow.** Time it with `time php monitor.php`. Install `fping` if it is missing — without it devices are pinged one at a time. Each unreachable device also costs one second per port in `TCP_FALLBACK_PORTS`, so trimming that list speeds up large outages.

## Schema smoke test

Run `php tests/schema_smoke.php` to create a temporary empty SQLite database, initialize the complete base schema twice, and run core queries plus graph, port-monitoring, optical-history and SMS smoke checks. To also test MySQL/MariaDB, set `NETPULSE_TEST_MYSQL_DSN`, `NETPULSE_TEST_MYSQL_USER`, and `NETPULSE_TEST_MYSQL_PASS` for a separate empty test database; the test refuses a database that already contains tables and drops only the tables it creates there after the test. The test avoids opening the configured production SQLite database. The MySQL check requires `pdo_mysql`.

## Languages

The interface ships in **English, Slovencina, Cestina, Deutsch, Polski and Magyar**. Translations live in `assets/i18n.js` — add a language by copying one of the dictionaries and translating the values. Contributions welcome.

## Project layout

```
index.php          SPA shell + navigation
api.php            JSON API (maps, devices, links, users, settings, graphs...)
assets/app.js      Frontend SPA (map rendering, dialogs, tables)
assets/i18n.js     UI translations + language switcher
assets/style.css   Themes (light/dark/auto), responsive layout
DudeParser.php     Reverse-engineered parser for The Dude's binary object blobs
importer.php       Shared import logic (dude.db -> app schema)
import_dude.php    CLI import entry point
monitor.php        Availability monitoring (one pass per run)
snmp_lib.php       SNMP get/walk helpers (v1/v2c/v3)
snmp_poll.php      SNMP traffic poller (loop worker)
mx80_lib.php       Dedicated MX80 category, SNMP traffic, graph and permission logic
telegram.php       Native Telegram sender
db.php / config.php  PDO layer, retry-on-busy helpers & configuration
probe_lib.php      Availability probes (fping, parallel TCP) shared by the monitor and "Test now"
cli_guard.php      Keeps CLI scripts from running via the web or as root
cleanup.php        Maintenance: prune history, add indexes, VACUUM (--siroty: devices on no map)
diag.php           Diagnostics: environment, monitoring and Telegram health
diag2.php          Diagnostics: detection lag and gaps in monitoring
schema_*.sql       SQLite / MySQL schema
```

## Security notes

- Real network data (`dude.db`, `data/app.db`, sessions) is **git-ignored** — this repo ships clean, with no topology or credentials.
- Change default credentials on first login — the app shows a warning banner until you do. New passwords must be at least 8 characters.
- Sign-in is rate-limited: after 10 failed attempts from one IP address, that address is blocked for 15 minutes. (Behind a reverse proxy such as Cloudflare every visitor shares the proxy's address — configure nginx `real_ip` so PHP sees the real client IP.)
- Database backups (which contain password hashes, device credentials, SNMP secrets and the Telegram token) can only be downloaded by an `administrator`. SNMP communities and v3 passwords are likewise visible to administrators only.
- All state-changing API calls require `POST` with a custom header, so a foreign website cannot trigger them through a logged-in browser (CSRF).
- SNMP community strings / v3 credentials and Telegram tokens are stored in your local database, not in the code.
- SMS credentials are sent to the administrator-configured HTTP(S) gateway endpoint; redirects are rejected. HTTPS is strongly preferred because HTTP exposes API credentials, recipient numbers, and message contents to network observers. Use plain HTTP only when required by the provider and only across a trusted network.
## Roadmap

- Optional ping/latency graphs and PNG export of graphs.
- More UI languages.

## License

Released under the [MIT License](LICENSE). (c) 2026 Juraj Chudy.
