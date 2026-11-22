# Bug Diagnosis and Resolution — WebVM + OpenCode

## Summary

After adding Python, Go, C++/g++, and SQLite toolchain support to WebVM, plus widening the terminal frame, the project was rebuilt and redeployed. All changes were verified against the acceptance criteria.

## Diagnosis

### 1. Toolchain package installation
- **Issue**: Adding `python3`, `go`, `g++`, and `sqlite` packages to Alpine image required `py3-pip`, `build-base`, `musl-dev`, `sqlite` packages for native compilation.
- **Resolution**: Added packages in single `apk add` layer: `python3`, `py3-pip`, `go`, `sqlite`, `php83-pdo_sqlite`, `php83-sqlite3`, `build-base`, `ripgrep`. Version files written at build time to `/etc/webvm/python`, `/etc/webvm/sqlite`, `/etc/webvm/go`, `/etc/webvm/cpp` using `awk/sed` post-install. Verified all versions render correctly in `webvm info`, `webvm tools`, and the landing page toolchain cards.

### 2. Go module cache persistence
- **Issue**: Go toolchain installed but `go install` binaries and module cache lost on `docker compose down`/rebuild.
- **Resolution**: Added named volume `go-path:/root/go` to `docker-compose.yml`. Verified volume creation and persistence across container recreation.

### 3. Terminal frame width
- **Issue**: `.terminal-frame` CSS had no explicit `max-width`, constraining the terminal within the grid layout.
- **Resolution**: Changed `.terminal-frame` in `server/public/style.css` from implicit to `max-width: 100%`, allowing full container width. Rebuilt image and verified terminal loads at `http://localhost:7681/`.

### 4. OpenCode + toolchain integration in UI
- **Issue**: `/api/info` and `/config.js` did not report Python/Go/C++/SQLite status; landing page had no toolchain cards.
- **Resolution**: Extended `collectInfo()` in `server/server.js` to include `python`, `go`, `cpp`, `sqlite` rows; extended `server/public/app.js` to display them in the banner; extended `site/index.php` with a `/toolchains` section featuring icon cards for each toolchain with correct versions from `/etc/webvm/` files.

### 5. Helper command completeness
- **Issue**: `scripts/webvm` lacked `tools`, `ai`, and updated `help` subcommand entries for new features.
- **Resolution**: Added `webvm tools`, `webvm opencode [args]`, `webvm opencode-auth`, `webvm opencode-status` commands; updated `help` output with examples for Python venv, Go run, g++, and sqlite3.

### 6. Environment variable passthrough
- **Issue**: Provider keys (`ANTHROPIC_API_KEY`, etc.) needed to pass through to OpenCode background service.
- **Resolution**: Added `ANTHROPIC_API_KEY`, `OPENAI_API_KEY`, `OPENROUTER_API_KEY`, `GOOGLE_GENERATIVE_AI_API_KEY`, `XAI_API_KEY`, `GROQ_API_KEY` to `.env.example` and `docker-compose.yml` environment block. Verified `opencode --version` and `webvm opencode-status` work with and without keys.

## Resolution

All changes were built and deployed with:
```
docker compose build
docker compose up -d --force-recreate
```

**Verification results:**
- Container `webvm` status: `healthy`
- `curl -fsS http://localhost:7681/healthz` → `{"status":"ok",...}`
- `curl -fsS http://localhost:7681/config.js` → reports `opencode: true`
- `curl -fsS http://localhost:7681/api/info` → reports Alpine, PHP, Apache, OpenCode v2.3.0, Python 3.12.13, Go 1.22.10, g++ 13.2.1, SQLite 3.45.3
- `webvm info` → includes OpenCode, Python, Go, C++, SQLite lines
- `webvm tools` → prints all four toolchains with examples
- Landing page `/toolchains` section renders 4 card-icons (Python/Go/C++/SQLite) with correct versions
- `docker compose exec webvm opencode --version` → `opencode v2.3.0`
- `docker compose exec webvm webvm tools` → version-printed output
- `docker compose exec -T webvm sh -lc "python3 -c 'print(\"ok\")'"` → works
- `docker compose exec -T webvm sh -lc "go run ."` → works (with `/tmp/gohello` example)
- `docker compose exec -T webvm sh -lc "g++ -O2 -o /tmp/app /tmp/test.cpp && /tmp/app"` → works
- `docker compose exec -T webvm sh -lc "sqlite3 /tmp/test.db \"SELECT 1\""` → works
- PHP site `http://localhost:8080/` renders with OpenCode + toolchain badges
- OpenCode TUI launches via WebSocket `/ws` input `opencode\r`
- Named volumes `webvm_opencode-data`, `webvm_opencode-config`, `webvm_opencode-state`, `webvm_opencode-cache`, `webvm_go-path` persist data across `docker compose down`/rebuild

## Known Limitations

- OAuth localhost callbacks from inside the container are awkward; prefer API keys or environment variables
- OpenCode runs as root; anyone with terminal access can use connected provider accounts and spend API credits
- Python virtualenvs and Go module caches in-container are ephemeral unless placed in mounted paths
- C++ builds produce native binaries that persist only in `/tmp` unless mounted out

## Files Modified

- `Dockerfile` — added toolchain packages, version-file generation, `go-path` mount point
- `docker-compose.yml` — added `go-path` volume, OpenCode env vars
- `server/server.js` — added `python`, `go`, `cpp`, `sqlite` to `/api/info` and `/config.js`
- `server/public/app.js` — added toolchain rows to banner, OpenCode button input logic
- `site/index.php` — added `/toolchains` section, version reads, updated landing page banner
- `scripts/webvm` — added `webvm tools`, `webvm opencode`/`ai`/`auth`/`status` commands
- `scripts/entrypoint.sh` — creates OpenCode directories, prints version
- `server/public/style.css` — added toolchain card styling, `.terminal-frame { max-width: 100% }`
- `.env.example` — documented provider key variables
- `README.md` — documented languages/toolchains section, persistence, updated stack diagram
- `ai_prompt.md` — full build spec prompt

## Bug Check - 2026-11-22 (v2.1.0)

- **PHP (`site/admin.php`):** No syntax errors (`php -l`).
- **Node.js (`server/server.js`):** No syntax errors (`node --check`).
- **Apache (`apache/webvm.conf`):** `httpd -t` Syntax OK.
- **Known Edge Cases / Notes:**
  - `tenantSessions` in `server/server.js` is stored in memory, so restarting `webvm-terminal` clears active tenant browser sessions (tenants simply re-login).
  - Tenants without a `TENANT_PASSWORD` set cannot log in via the `/api/tenant-login` form; set a password in Admin Dashboard -> Create Tenant or Reset PW.
