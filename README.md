# ZunoX

> &copy; 2026 ZunoX &mdash; Developed by **iQuipe Digital** (Cloud Enterprise). Licensed under the [MIT License](LICENSE).

A lightweight WebVM and browser terminal into a Docker container running **Alpine Linux + Apache + PHP + OpenCode AI**,
with **Python, Go, C/C++, R, Node.js, and SQLite** toolchains included.

Three services are exposed from one small container:

| URL | What it is |
| --- | --- |
| <http://localhost:8081/> | A PHP site served by Apache (also proves `mod_php` works) |
| <http://localhost:8082/admin.php> | **Admin Dashboard** — real-time metrics, tenant management, logs, block/unblock, and environment cleanup |
| <http://localhost:7681/> | A full xterm.js terminal attached to a root shell, with the OpenCode AI agent installed (supports `?tenant=<name>` with optional password login) |

The terminal is not an emulator and not a fake shell: a WebSocket bridges xterm.js to a real
PTY (`node-pty`) running `/bin/bash` inside the container.

```text
Browser
  ├── HTTP  :8081 ──► Apache httpd ──► mod_php ──► ./site & /var/www/tenants
  ├── HTTP  :8082 ──► Apache httpd ──► admin.php (Isolated Admin Dashboard)
  └── WS    :7681 ──► Node + ws + node-pty ──► /bin/bash (per-tenant / root)
                                  │                └── opencode (AI agent)
                        Alpine Linux container
                        supervised by supervisord
```

Both ports are bound to `127.0.0.1` by default, so nothing is exposed to your network unless you
explicitly opt in.

---

## Quick start

Requirements: Docker with Compose v2.

```powershell
# 1. Optional but recommended: set a terminal password
Copy-Item .env.example .env
#    then edit .env and set WEBVM_PASSWORD=something-long

# 2. Build and start
docker compose up -d --build

# 3. Open the apps
Start-Process http://localhost:8081/
Start-Process http://localhost:8082/admin.php
Start-Process http://localhost:7681/
```

See also: [GUIDE.md](GUIDE.md) · [Architecture.html](Architecture.html) · [uml.html](uml.html)

Once the terminal is open, run `opencode` (or click **OpenCode**) to start the AI agent. It
works out of the box with free models; connect your own provider in
[OpenCode AI](#opencode-ai) for more models.

Useful commands:

```powershell
docker compose ps                 # health / published ports
docker compose logs -f            # Apache + terminal logs
docker compose restart            # restart both services
docker compose down               # remove the container and network
```

The PHP site lives in [`site/`](site) and is bind-mounted, so edits on the host appear on refresh:

```powershell
notepad .\site\index.php
```

The container filesystem (installed `apk` packages, files under `/root`, etc.) is ephemeral and
resets when the container is recreated. The `./site` directory is not.

---

## Using the terminal

Type `webvm` for a small helper:

```text
webvm info             Show Alpine, PHP, Apache, auth and URL details
webvm site             Print the PHP site URL
webvm terminal         Print the terminal URL
webvm status           Show Apache and terminal process status
webvm tools            Show Python, Go, C/C++ and SQLite versions
webvm restart-apache   Restart Apache through supervisord
webvm logs             Follow the Apache error log
```

Typical things to do inside:

```sh
# stack versions
php -v
httpd -v

# inspect / edit the served site
ls -la /var/www/localhost/htdocs
vi /var/www/localhost/htdocs/index.php

# install more Alpine packages (they last until the container is recreated)
apk add --no-cache vim git sqlite

# look at the PHP modules compiled into this image
php -m

# restart Apache after changing its config
webvm restart-apache
```

`Ctrl+L` clears the screen, `Ctrl+D` or `exit` ends the shell. Ending the shell stops the
session; click **Start a new session** (or **Reconnect**) to get a fresh one.

The terminal includes a **PHP site** button, reconnect, clear and fullscreen controls. It is
responsive and works from a phone on the same network.

---

## OpenCode AI

The image ships the [OpenCode](https://opencode.ai) CLI (V2) as a self-contained native
binary built for Alpine/musl, plus `git` and `ripgrep` so its file and search tools work.

Launch it from the terminal in any of these ways:

```sh
opencode                       # full-screen TUI, rooted at the PHP site
opencode run "Explain this PHP site"   # non-interactive prompt
webvm opencode                 # same as `opencode`
webvm opencode-status          # version, data paths, connected accounts
oc                             # short shell alias
```

The web terminal also has an **OpenCode** button that types `opencode` into the current
session.

### Providers and authentication

OpenCode includes free models, so `opencode` and `opencode run` work immediately with no
configuration. To use your own provider, either connect from inside the terminal:

```sh
opencode auth login            # interactive provider picker
opencode auth login anthropic --method key
opencode auth list
```

…or use `/connect` and `/models` inside the TUI. Saved accounts are written to the OpenCode
SQLite database in the `opencode-data` volume, so they survive container recreation.

Alternatively, put API keys in `.env` before starting the container:

```dotenv
ANTHROPIC_API_KEY=sk-ant-...
OPENAI_API_KEY=sk-...
OPENROUTER_API_KEY=sk-or-...
GOOGLE_GENERATIVE_AI_API_KEY=...
XAI_API_KEY=...
GROQ_API_KEY=...
```

The image passes these through to the OpenCode background service. Add other provider
variables to `docker-compose.yml` if you need them. Recreate after editing `.env`:

```powershell
docker compose up -d --force-recreate
```

OAuth flows that redirect to a localhost callback can be awkward from inside a container.
Prefer an API key or an environment variable. Inside the TUI the authorization URL is shown
in the terminal, so you can open it in your host browser.

### Persistence

Named Docker volumes keep OpenCode state across `docker compose down` and image rebuilds:

| Volume | Container path | Holds |
| --- | --- | --- |
| `webvm_opencode-data` | `/root/.local/share/opencode` | SQLite database, sessions, logs, repos |
| `webvm_opencode-config` | `/root/.config/opencode` | Global OpenCode settings and service config |
| `webvm_opencode-state` | `/root/.local/state/opencode` | Background service registration |
| `webvm_opencode-cache` | `/root/.cache/opencode` | Cached binaries and downloads |

The PHP site remains a host bind mount (`./site`), so OpenCode edits the real files on your
machine when you ask it to.

### Updating OpenCode

Self-updates are disabled in [`opencode/opencode.json`](opencode/opencode.json) so the image
stays reproducible. Rebuild to upgrade, optionally pinning a version:

```powershell
docker compose build --build-arg OPENCODE_VERSION=2.3.0
docker compose up -d --force-recreate
```

Without the build argument the latest V2 release is installed at build time. Check what is
running with `opencode --version` or `webvm info`.

---

## Languages and toolchains

The image includes general-purpose development toolchains so the terminal is useful beyond
PHP:

| Tool | Version source | Notes |
| --- | --- | --- |
| Python 3 | `python3 --version` | `py3-pip` installed; use a venv for installs |
| Go | `go version` | Full toolchain; `GOPATH` is `/root/go` and persisted |
| C / C++ | `g++ -dumpversion` | `gcc`, `g++`, `make`, `musl-dev`, `binutils` |
| SQLite | `sqlite3 --version` | `sqlite3` CLI plus PHP `pdo_sqlite` and `sqlite3` |

`webvm tools` prints the installed versions and quick examples. `webvm info` includes them
too, and the web terminal banner lists them on connect.

```sh
# Python — Alpine marks the system environment as externally managed,
# so create a virtualenv instead of installing globally.
python3 -m venv .venv && . .venv/bin/activate
pip install requests

# Go
mkdir -p /root/go/src/demo && cd /root/go/src/demo
go mod init demo && go run .

# C / C++
g++ -O2 -Wall -o app app.cpp && ./app

# SQLite
sqlite3 /var/www/localhost/htdocs/data.db "create table t(id integer primary key, name text);"
```

PHP code can use SQLite through PDO:

```php
$db = new PDO('sqlite:/var/www/localhost/htdocs/data.db');
```

The Go module cache and `go install` binaries live in the `webvm_go-path` volume, so they
survive `docker compose down` and rebuilds. Python virtualenvs and other files in the
container filesystem are ephemeral unless you place them in a mounted path.

---

## Configuration

Copy `.env.example` to `.env` and adjust:

| Variable | Default | Purpose |
| --- | --- | --- |
| `WEBVM_HTTP_PORT` | `8080` | Host port for the Apache/PHP site |
| `WEBVM_TERMINAL_PORT` | `7681` | Host port for the web terminal |
| `WEBVM_BIND_ADDR` | `127.0.0.1` | Host bind address for both ports |
| `WEBVM_USER` | `admin` | Basic-auth username |
| `WEBVM_PASSWORD` | *(empty)* | Basic-auth password; empty disables auth |
| `WEBVM_MAX_SESSIONS` | `8` | Maximum simultaneous terminal sessions |
| `WEBVM_SHELL` | `/bin/bash` | Login shell spawned in the terminal |
| `WEBVM_HOSTNAME` | `webvm` | Container hostname shown in the prompt |
| `WEBVM_CONTAINER_NAME` | `webvm` | Compose container name |
| `TZ` | `UTC` | Container / PHP timezone |

Ports are mapped as `binding:host:container`. The container always listens on `80` and `7681`
internally; only the host side changes.

### OpenCode provider keys

These are optional; without them you can still use the bundled free models or run
`opencode auth login` once.

| Variable | Default | Purpose |
| --- | --- | --- |
| `ANTHROPIC_API_KEY` | *(empty)* | Anthropic / Claude API key |
| `OPENAI_API_KEY` | *(empty)* | OpenAI API key |
| `OPENROUTER_API_KEY` | *(empty)* | OpenRouter API key |
| `GOOGLE_GENERATIVE_AI_API_KEY` | *(empty)* | Google Gemini API key |
| `XAI_API_KEY` | *(empty)* | xAI / Grok API key |
| `GROQ_API_KEY` | *(empty)* | Groq API key |

Add more provider variables to the `environment:` block in
[`docker-compose.yml`](docker-compose.yml); OpenCode reads them from the service process.

### Exposing it on your LAN

```dotenv
WEBVM_BIND_ADDR=0.0.0.0
WEBVM_PASSWORD=use-a-long-random-password
```

Then recreate:

```powershell
docker compose up -d --force-recreate
```

HTTP basic auth protects the terminal, API and terminal page. It does **not** encrypt traffic.
For anything beyond a trusted LAN, put a TLS reverse proxy (Caddy, nginx, Traefik) in front and
keep the container ports bound to localhost.

---

## Without Compose

```powershell
docker build -t webvm:alpine-php-apache .

docker run -d --name webvm --hostname webvm `
  -p 127.0.0.1:8080:80 `
  -p 127.0.0.1:7681:7681 `
  -e WEBVM_HTTP_PORT=8080 `
  -e WEBVM_PASSWORD=change-me `
  -v "${PWD}/site:/var/www/localhost/htdocs" `
  -v webvm-opencode-data:/root/.local/share/opencode `
  -v webvm-opencode-config:/root/.config/opencode `
  -v webvm-opencode-state:/root/.local/state/opencode `
  -v webvm-opencode-cache:/root/.cache/opencode `
  -v webvm-go-path:/root/go `
  --restart unless-stopped `
  webvm:alpine-php-apache
```

Health check:

```powershell
curl.exe -s http://localhost:7681/healthz
```

---

## Customizing the image

The image is intentionally small and readable:

- **Alpine 3.20**, **Apache 2.4**, **PHP 8.3** (`mod_php`), Node 20, supervisord, xterm.js,
  and the **OpenCode 2** CLI plus `git` and `ripgrep`.
- Toolchains: **Python 3 + pip**, **Go**, **gcc/g++/make**, and the **SQLite** CLI with PHP
  `pdo_sqlite` and `sqlite3`.
- PHP extensions currently installed: `curl`, `json`, `mbstring`, `openssl`, `session`,
  `pdo_sqlite`, `sqlite3`.
- Add extensions in the `apk add` list in the [`Dockerfile`](Dockerfile), e.g.
  `php83-pdo_sqlite`, `php83-gd`, `php83-zip`, then rebuild:

```powershell
docker compose build --no-cache
docker compose up -d
```

Frontend and server code:

- [`server/server.js`](server/server.js) — HTTP server, optional basic auth, WebSocket/PTY bridge.
- [`server/public/`](server/public) — xterm.js UI (assets are bundled at build time; no CDN).
- [`site/`](site) — the PHP site served by Apache.
- [`apache/webvm.conf`](apache/webvm.conf) — Apache tuning and headers.
- [`supervisord.conf`](supervisord.conf) — runs Apache and the terminal server side by side.

The terminal server has a small JSON API:

```text
GET /healthz    liveness + session count (unauthenticated)
GET /api/info   container/stack information (authenticated when a password is set)
GET /config.js  UI bootstrap values (contains no secrets)
WS  /ws         interactive terminal
```

---

## Security notes

This container intentionally hands out a **root shell**. Treat the terminal port like SSH:

- Keep the default `WEBVM_BIND_ADDR=127.0.0.1`. Use a VPN or TLS reverse proxy for remote access.
- Set `WEBVM_PASSWORD` before changing the bind address. Basic auth over plain HTTP is only
  suitable for a trusted network.
- The shell is confined to the container. The host Docker socket is **not** mounted, so the
  terminal cannot start or control other containers on the host.
- If you want the Docker CLI inside the terminal, you must install `docker-cli` and mount
  `/var/run/docker.sock` yourself — that is equivalent to giving the browser root access to the
  host. Do not expose such a setup publicly.
- `WEBVM_MAX_SESSIONS` limits how many PTYs can exist at once.
- OpenCode runs as **root** inside the container and has the same access as the terminal. Anyone
  who can reach the terminal can use your connected provider accounts and spend your API credits.
  Provider keys passed through `.env` are visible to processes inside the container.
- The AI can edit anything the container can: the bind-mounted `./site` directory, files you add
  under it, and the OpenCode volumes. Review its changes, especially if you mount other host
  directories into the container. Treat model output and repository instructions as untrusted.

---

## Troubleshooting

**Port already in use** — change `WEBVM_HTTP_PORT` / `WEBVM_TERMINAL_PORT` in `.env`, then
`docker compose up -d`.

**Container restarts immediately** — `docker compose logs` first. The entrypoint runs `httpd -t`
and refuses to start on an invalid Apache config; supervisor logs show which process failed.

**Terminal says "Offline" / keeps reconnecting** — check
`curl http://localhost:7681/healthz` and `docker compose logs webvm-terminal`. A 401 means basic
auth is enabled and the browser prompt was cancelled; reload the page and enter the credentials.

**Blank terminal** — hard-refresh (`Ctrl+Shift+R`). The xterm assets are served locally from
`/vendor/`, so an incomplete image build is usually the cause: `docker compose build --no-cache`.

**PHP file changes do not appear** — confirm the bind mount with
`docker compose exec webvm ls -la /var/www/localhost/htdocs`. On Linux, make sure the files are
readable by Apache (`chmod -R a+r site`).

**`opencode: command not found`** — rebuild the image (`docker compose build`) and recreate the
container. Verify with `docker compose exec webvm opencode --version`.

**OpenCode has no models / authentication errors** — run `opencode auth login` (or
`webvm opencode-auth`) inside the terminal, or set a provider key in `.env` and recreate the
container. `opencode auth list` shows saved and environment connections.

**OpenCode OAuth login cannot reach localhost** — the browser callback runs on your host, not in
the container. Use an API key (`opencode auth login <provider> --method key`) or an environment
variable instead.

**OpenCode state disappeared** — make sure the `opencode-data`, `opencode-config`,
`opencode-state` and `opencode-cache` volumes are present in `docker-compose.yml`. Removing
volumes with `docker compose down -v` deletes provider accounts and sessions.

**Reset everything** — `docker compose down`, then `docker compose up -d --build`. Use
`docker compose down -v` only if you also want to delete OpenCode credentials and sessions.

---

## Project layout

```text
webvm_dk/
├── docker-compose.yml       # ports, env, site bind mount, OpenCode volumes
├── Dockerfile               # Alpine + Apache + PHP + Node + node-pty + OpenCode
├── .env.example             # copy to .env
├── apache/webvm.conf        # Apache config, headers, stdout logging
├── opencode/opencode.json   # managed OpenCode config (self-updates disabled)
├── php/webvm.ini            # PHP hardening / limits
├── supervisord.conf         # Apache + terminal processes
├── scripts/
│   ├── entrypoint.sh        # config check, startup banner, warnings
│   ├── profile.sh           # shell prompt and aliases
│   └── webvm                # in-container helper command
├── server/
│   ├── server.js            # HTTP + WebSocket + PTY bridge
│   ├── package.json
│   └── public/              # xterm.js UI
└── site/                    # PHP site served by Apache
    ├── index.php
    └── assets/
```
