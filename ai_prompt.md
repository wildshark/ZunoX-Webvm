# AI Project Prompt — WebVM

## Role

You are a senior full-stack and infrastructure engineer. Build, document, and verify a
self-contained browser terminal project called **WebVM**. Work directly in the repository
root, keep the implementation small and readable, and verify everything by actually running
it with Docker.

---

## Objective

Create a Dockerized **Alpine Linux + Apache + PHP + OpenCode AI** environment with two
browser-facing surfaces:

1. A PHP site served by Apache at <http://localhost:8080/>.
2. A full browser terminal at <http://localhost:7681/> that attaches to a real root shell in
   the same container, with the OpenCode AI coding agent installed and ready to run.

This is **not** a simulated shell. The browser must be bridged over WebSocket to a real PTY
inside the container. The terminal user must get a genuine root shell.

The project is for **local development on a Windows host using Docker Desktop**, but the
image and Compose file must be portable to Linux and macOS.

---

## Hard requirements

- One container runs Alpine Linux, Apache with `mod_php`, PHP 8.3, Node.js, supervisord, and
  the OpenCode CLI.
- Two host ports: `8080` for the PHP site and `7681` for the terminal.
- Both ports bind to `127.0.0.1` by default.
- The terminal runs `/bin/bash --login` as **root** inside the container.
- Use a real PTY (`node-pty`) and xterm.js over WebSocket. Do **not** use SSH.
- Serve the PHP site from a host bind mount (`./site`) so edits appear on refresh.
- Install the OpenCode V2 CLI as a native binary in the image, available on `PATH` as
  `opencode`.
- Persist OpenCode credentials, sessions, config, state, and cache in named Docker volumes.
- Bundle all browser assets locally. No CDN dependencies.
- Do **not** mount the host Docker socket. The browser shell must not control the host.
- Include optional HTTP basic auth for the terminal, page, API, and WebSocket.
- Provide a small helper command `webvm` and complete project documentation.

---

## Architecture

```text
Browser
  ├── HTTP  :8080 ──► Apache httpd ──► mod_php ──► ./site
  └── WS    :7681 ──► Node + ws + node-pty ──► /bin/bash (root)
                              │                    └── opencode (AI agent)
                    Alpine Linux container
                    supervised by supervisord
```

Inside the container, supervisord runs exactly two long-lived programs:

- `apache`: `/usr/sbin/httpd -D FOREGROUND`
- `webvm-terminal`: `/usr/bin/node /app/server.js`

The entrypoint runs `/sbin/tini` as PID 1 and starts supervisord.

---

## Stack

| Component | Version / choice |
| --- | --- |
| Base image | `alpine:3.20` |
| Web server | Apache 2.4 (`apache2`) |
| PHP | PHP 8.3 with `mod_php` (`php83`, `php83-apache2`) |
| PHP extensions | `curl`, `json`, `mbstring`, `openssl`, `session` |
| Runtime | Node.js 20 + npm |
| PTY | `node-pty` |
| WebSocket | `ws` |
| Frontend terminal | `@xterm/xterm` 5.5 with `addon-fit` and `addon-web-links` |
| Process manager | supervisord |
| Init | `tini` |
| AI agent | OpenCode CLI V2, native musl build (`@opencode/cli` distribution) |
| AI support tools | `git`, `ripgrep` |
| Other packages | `bash`, `curl`, `ca-certificates`, `tzdata` |

Image name: `webvm:alpine-php-apache`.

---

## Repository layout

```text
webvm_dk/
├── docker-compose.yml       # ports, env, site bind mount, OpenCode volumes
├── Dockerfile               # Alpine + Apache + PHP + Node + node-pty + OpenCode
├── .env.example             # copy to .env
├── .dockerignore
├── .gitignore
├── README.md                # setup, configuration, OpenCode, security, troubleshooting
├── ai_prompt.md             # this prompt
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
│   └── public/              # xterm.js UI (HTML, CSS, JS, favicon)
└── site/
    ├── index.php            # PHP landing page with live runtime details
    ├── assets/              # site.css, site.js, favicon.svg
    └── test/index.php       # session-based login demo
```

---

## Implementation details

### 1. Docker image

- Start from `alpine:3.20`.
- Install all runtime packages in one cached `apk add --no-cache` layer.
- Set sensible image env defaults:
  - `NODE_ENV=production`
  - `WEBVM_TERMINAL_PORT=7681`
  - `WEBVM_SHELL=/bin/bash`
  - `WEBVM_CWD=/var/www/localhost/htdocs`
  - `LANG=C.UTF-8`
- Install OpenCode with:

  ```sh
  curl -fsSL https://opencode.ai/v2/install | HOME=/opt/opencode-install bash -s -- --no-modify-path
  install -m 0755 /opt/opencode-install/.opencode/bin/opencode /usr/local/bin/opencode
  ```

  The installer auto-detects Alpine/musl. Keep a build arg `OPENCODE_VERSION` so builds can
  be pinned (`--build-arg OPENCODE_VERSION=2.3.0`); when empty, install the latest V2.
- Write the installed version to `/etc/opencode/version` for the PHP site to display.
- Install Node dependencies with `npm ci --omit=dev`. `node-pty` is native, so install
  `build-base`, `linux-headers`, `nodejs-dev`, and `python3` as virtual build dependencies,
  use `npm_config_nodedir=/usr`, then remove the build dependencies and npm cache.
- Copy xterm.js and addon assets from `node_modules` into `server/public/vendor/` at build
  time. The UI must work with no network access to a CDN.
- Copy Apache, PHP, supervisor, entrypoint, helper, profile, OpenCode config, and site files.
- Expose `80` and `7681`.
- Add a health check that curls `http://127.0.0.1:7681/healthz`.
- Entrypoint: `/sbin/tini -- /usr/local/bin/entrypoint.sh`; default command:
  `/usr/bin/supervisord -c /etc/supervisord.conf -n`.

### 2. Terminal server (`server/server.js`)

Implement a dependency-light Node HTTP + WebSocket server:

- Read configuration from environment:
  - `WEBVM_LISTEN_PORT`, fallback `WEBVM_TERMINAL_PORT`, default `7681`
  - `WEBVM_TERMINAL_PORT` (public port shown in UI)
  - `WEBVM_HTTP_PORT` (site port shown in UI)
  - `WEBVM_SHELL` (default `/bin/bash`)
  - `WEBVM_CWD` (default `/var/www/localhost/htdocs`)
  - `WEBVM_USER` (default `admin`)
  - `WEBVM_PASSWORD` (empty disables auth)
  - `WEBVM_MAX_SESSIONS` (default `8`)
- HTTP endpoints:
  - `GET /healthz` — unauthenticated JSON health/status with version, uptime, sessions.
  - `GET /config.js` — unauthenticated JS bootstrap with ports, hostname, Alpine version,
    whether OpenCode is installed, whether auth is enabled, and version. It must contain no
    secrets.
  - `GET /api/info` — authenticated when a password is set; returns runtime details
    including Alpine, kernel, PHP, Apache, OpenCode version, Node version, shell, ports,
    session counts, uptime.
  - Static file serving from `server/public`, safe against path traversal.
- WebSocket:
  - Only the `/ws` path; reject others with 404.
  - Require basic auth when a password is configured; otherwise reject with 401.
  - Enforce `WEBVM_MAX_SESSIONS`.
  - Spawn a PTY with `xterm-256color`, `COLORTERM=truecolor`, `TERM=xterm-256color`, inherited
    environment, `HOME=/root`, and `WEBVM=1`.
  - Spawn bash with `--login` when the shell is bash/zsh/ksh.
  - Protocol messages are JSON:
    - server → client: `ready` (session ID + info), `data`, `exit`, `error`, `pong`
    - client → server: `input`, `resize`, `ping`
  - Clamp columns/rows to safe ranges (`20..400` × `5..200`).
  - Limit input payloads and buffered output; pause/resume the PTY when the WebSocket backs
    up.
  - Terminate the PTY when the socket closes; heartbeat all clients every 30 seconds.
  - Handle `SIGTERM`/`SIGINT` gracefully.
- Startup logs must include the listening address, stack versions, and an explicit warning
  when auth is disabled.

### 3. Web terminal frontend (`server/public/`)

Build a polished dark terminal UI using xterm.js:

- Header with brand, connection status pill, **PHP site** link, **OpenCode** button,
  **Clear**, **Reconnect**, and **Fullscreen** controls.
- Terminal chrome bar showing `root@<hostname>` and `xterm-256color`.
- Footer status bar: connection state, hostname, shell, terminal dimensions, and a live
  clock.
- Auto-connect on load; automatic reconnect with exponential backoff after disconnects.
- Overlay for connecting/offline/session-ended states with a "Start a new session" action.
- Fit the terminal to the window, resync dimensions after resize/orientation/fullscreen
  changes, and request the correct PTY size on connect.
- Print a colored welcome banner with hostname, Alpine, PHP, Apache, OpenCode version, shell,
  and a hint to run `webvm` and `opencode`.
- The **OpenCode** button must:
  - Be disabled unless the terminal is online.
  - Send the literal input `opencode\r` to the PTY.
  - Refocus the terminal.
  - Be hidden if `/config.js` says OpenCode is not installed.
- **Clear** sends `Ctrl+L`; **Reconnect** starts a fresh shell; **Fullscreen** uses the
  Fullscreen API.
- Use truecolor-friendly theming and the bundled xterm addons. Responsive down to phone
  widths.

### 4. Apache and PHP

- Apache config: `ServerName webvm`, minimal tokens/signature, logs to stdout/stderr,
  `Options -Indexes +FollowSymLinks`, `AllowOverride All`.
- Add security headers (`X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`).
- PHP hardening: `expose_php=Off`, errors logged not displayed, 256 MB memory, 64 MB upload
  limits, 30-second max execution.
- PHP landing page: dark, useful, and server-rendered. Show live Alpine, PHP, Apache,
  OpenCode and kernel versions, hostname, document root, timezone clock, auth state, terminal
  URL, and a small name-greeting form.
- Include a `/test/` session-based login demo with a hashed demo password, attempt limiting,
  CSRF-safe patterns, and no credentials in client-side code. Document the demo account in
  the source comment.
- The site must gracefully handle OpenCode not being installed (show "not installed" rather
  than crashing).

### 5. OpenCode integration

- Use **OpenCode V2** only.
- Install the native musl binary at build time, not via npm at runtime.
- Add `/etc/opencode/opencode.json` as a managed configuration:

  ```json
  {
    "$schema": "https://opencode.ai/config.json",
    "update": "disable"
  }
  ```

  Self-updates are disabled so the image remains reproducible.
- Add Compose volumes for:
  - `/root/.local/share/opencode` — database, sessions, logs, repos
  - `/root/.config/opencode` — global settings and service config
  - `/root/.local/state/opencode` — service registration
  - `/root/.cache/opencode` — cached binaries/downloads
- Pass optional provider keys from `.env` into the container:
  `ANTHROPIC_API_KEY`, `OPENAI_API_KEY`, `OPENROUTER_API_KEY`,
  `GOOGLE_GENERATIVE_AI_API_KEY`, `XAI_API_KEY`, `GROQ_API_KEY`. Empty values must be
  harmless.
- The entrypoint must create the OpenCode directories, print its version, and warn if the
  binary is missing.
- Users must be able to authenticate either with `opencode auth login` inside the PTY or via
  environment variables. Free models must work without any credentials.
- Document that OAuth localhost callbacks can be awkward inside a container and that API keys
  are preferred.

### 6. Helper command and shell profile

`webvm` must support:

```text
webvm info             # Alpine, PHP, Apache, OpenCode, shell, auth, URLs
webvm site             # print the PHP site URL
webvm terminal         # print the terminal URL
webvm status           # supervisor process status
webvm restart-apache   # supervisorctl restart apache
webvm logs             # follow the Apache error log
webvm opencode [args]  # exec opencode (also `webvm ai`)
webvm opencode-auth    # opencode auth login
webvm opencode-status  # version, paths, connected accounts
webvm help
```

The login profile should set a colored prompt, `EDITOR`, `PAGER`, useful aliases (`ll`, `la`,
`grep --color=auto`, `oc=opencode`), and load for root shells.

### 7. Configuration and environment

`.env.example` must document:

| Variable | Default | Purpose |
| --- | --- | --- |
| `WEBVM_HTTP_PORT` | `8080` | Host port for the PHP site |
| `WEBVM_TERMINAL_PORT` | `7681` | Host port for the terminal |
| `WEBVM_BIND_ADDR` | `127.0.0.1` | Host bind address |
| `WEBVM_USER` | `admin` | Basic-auth username |
| `WEBVM_PASSWORD` | empty | Basic-auth password; empty disables auth |
| `WEBVM_MAX_SESSIONS` | `8` | Max simultaneous terminal sessions |
| `WEBVM_SHELL` | `/bin/bash` | Login shell |
| `WEBVM_CONTAINER_NAME` | `webvm` | Container name |
| `WEBVM_HOSTNAME` | `webvm` | Hostname in the prompt |
| `TZ` | `UTC` | Container/PHP timezone |
| `ANTHROPIC_API_KEY` etc. | empty | Optional OpenCode provider keys |

Compose must map ports as `${WEBVM_BIND_ADDR:-127.0.0.1}:host:container`, use
`restart: unless-stopped`, a short stop grace period, the site bind mount, and the four
OpenCode named volumes.

### 8. Security and robustness

- Default to localhost binding. Document that changing it exposes a root shell.
- Optional HTTP basic auth must protect the terminal page, static assets, API, and WebSocket.
  Use constant-time comparisons.
- Do not mount `/var/run/docker.sock`. Explain that doing so would give the browser root
  access to the host.
- Explain that OpenCode runs as root and can edit the bind-mounted site and spend connected
  provider credits; anyone with terminal access can use those accounts.
- Enforce session limits, payload limits, and output backpressure.
- Validate Apache configuration at startup and fail fast instead of restart-looping.
- Keep secrets out of the unauthenticated `/config.js` and `/healthz`.
- Avoid CDNs and external runtime assets for the terminal.

---

## Verification / acceptance criteria

Run the project for real and confirm each item. Do not claim success without evidence.

### Build and start

```powershell
docker compose build
docker compose up -d --force-recreate
docker compose ps
```

Expected: container `webvm` is `healthy`; ports `127.0.0.1:8080` and `127.0.0.1:7681` are
published.

### HTTP surfaces

```powershell
curl.exe -fsS http://localhost:7681/healthz
curl.exe -fsS http://localhost:7681/config.js
curl.exe -fsS http://localhost:7681/api/info
curl.exe -fsS http://localhost:8080/
```

Expected:

- Health returns `status: ok`.
- `config.js` contains no secrets and reports `opencode: true`.
- `/api/info` reports correct Alpine/PHP/Apache/OpenCode versions.
- The PHP site renders and includes the OpenCode version.

### Root shell over the real WebSocket

Connect to `/ws` with a WebSocket client and send `id`, `whoami`, `pwd`, and
`opencode --version`. Expected output includes `uid=0(root)`, `root`, and
`opencode v2.3.0` (or the installed V2 version).

### OpenCode

```sh
docker compose exec webvm opencode --version
docker compose exec webvm webvm info
docker compose exec webvm webvm opencode-status
docker compose exec -T webvm sh -lc "cd /var/www/localhost/htdocs && opencode run 'Reply with exactly: hello'"
```

Expected:

- Version prints.
- `webvm info` includes an OpenCode line.
- Status prints version, paths, and account list without errors.
- A non-interactive prompt succeeds with a free model or a configured provider.

### TUI through the browser

Connect to the terminal over `/ws`, send `opencode\r`, wait a few seconds, and confirm the
full-screen TUI renders (OpenCode branding, "Ask anything…", model/agent bar). Send `Ctrl+C`
and `exit`; the session must end cleanly.

### Auth

Create a `.env` with `WEBVM_PASSWORD=test-password` and recreate. Expected:

- `curl.exe -i http://localhost:7681/healthz` → `200`.
- `curl.exe -i http://localhost:7681/` → `401` plus `WWW-Authenticate`.
- `curl.exe -i -u admin:test-password http://localhost:7681/` → `200`.
- The WebSocket handshake without credentials → `401`.

### Persistence

Recreate the container and confirm the OpenCode database in
`/root/.local/share/opencode/opencode.db` still exists. `docker compose down` (without `-v`)
must preserve accounts and sessions.

---

## Definition of done

- All acceptance checks pass.
- `docker compose config --quiet` succeeds.
- `node --check server/server.js` and `node --check server/public/app.js` succeed.
- The UI works without any CDN, and the OpenCode button launches the TUI.
- README covers quick start, terminal usage, OpenCode auth/providers/persistence/updating,
  configuration, LAN exposure, running without Compose, security, troubleshooting, and the
  project layout.
- `.env.example` documents every supported variable and provider key.
- No secrets are committed, and `.env` is ignored.

---

## Constraints and pitfalls

- `node-pty` needs native compilation on Alpine; always install and remove build
  dependencies in the same Docker layer.
- OpenCode on Alpine must use the musl native build; do not install a glibc binary.
- The OpenCode installer defaults to `$HOME/.opencode/bin`; install into a temporary HOME,
  copy the binary to `/usr/local/bin`, and write the version file for the PHP site.
- OpenCode's SQLite database is several MB and changes on use; keep it in a named volume.
- Do not put OpenCode self-updates in the running container; rebuild the image instead.
- xterm.js assets must be copied at build time or the terminal will be blank.
- The terminal server must not serve arbitrary files outside `server/public`.
- Windows PowerShell users need `curl.exe`, not the `curl` alias.
- The container is intentionally root; do not weaken the documentation warnings.
