# ZunoX v2.1.0 — Project Overview

> &copy; 2026 ZunoX &mdash; Developed by **iQuipe Digital** (Cloud Enterprise). Licensed under the [MIT License](LICENSE).

## 1. Summary
**ZunoX** is a lightweight, multi-tenant WebVM and browser terminal platform packaged in a single Alpine Linux 3.20 Docker container. It combines an isolated **Admin Dashboard**, per-tenant **Apache + PHP** web hosting, and a real-time **xterm.js Web Terminal** (`node-pty` + `/bin/bash`) with pre-installed development toolchains and the **OpenCode AI** CLI.

---

## 2. Exposed Services & Ports

| Host Port | Container Port | Service | Purpose & Isolation |
| --- | --- | --- | --- |
| `8081` | `80` | Apache (`httpd` + `mod_php`) | Serves `./site` and per-tenant VirtualHosts (`/var/www/tenants/<name>`). `admin.php` is blocked (`403 Forbidden`). |
| `8082` | `8082` | Apache (`httpd` + `mod_php`) | Isolated **Admin Dashboard** (`admin.php`), protected by PHP session login (`adminman`). |
| `7681` | `7681` | Node.js (`server.js` + `ws` + `node-pty`) | **ZunoX Web Terminal** with tenant login screen, session cookies, and real-time CPU/memory status bar. |

---

## 3. Runtime & Toolchains
- **OS:** Alpine Linux `3.20.10`
- **Web Server:** Apache `2.4.67` + PHP `8.3.36` (`mod_php`, `pdo_sqlite`, `sqlite3`, `openssl`, `curl`, `mbstring`)
- **Process Supervisor:** `supervisord` (managed under `tini` PID 1)
- **Languages & Tools:**
  - **Python** `3.12.13` (`pip`)
  - **Go** `1.22.10`
  - **C/C++** (`gcc`/`g++` `13.2.1`, `make`, `musl-dev`)
  - **R** `4.4.0` (`Rscript`)
  - **Node.js** `20.15.1` (`npm`)
  - **SQLite** `3.45.3`
  - **OpenCode AI** CLI (`opencode`)

---

## 4. Multi-Tenant Architecture & Isolation
1. **Database (`/etc/webvm/tenants.db`):**
   - `users`: Admin accounts (`adminman` seeded with hashed password).
   - `tenants`: Registered tenants (`id`, `name`, `hostname`, `environment`, `is_blocked`, `created_at`).
   - `tenant_env`: Per-tenant environment variables (`TENANT_ENV`, `TENANT_PASSWORD`, custom `KEY=VALUE` pairs).
   - `admin_logs`: Audit trail of admin and tenant actions.
2. **Per-Tenant Config & Logs (`/etc/webvm/tenants/`):**
   - `<name>.conf`: Environment variables + `BLOCKED=1` flag read by `server.js`.
   - `<name>.log`: Per-tenant action log file.
3. **Apache VirtualHost Isolation (`/etc/apache2/conf.d/tenants/` & `/var/www/tenants/`):**
   - Creating a tenant provisions `/var/www/tenants/<name>/index.php` and `/etc/apache2/conf.d/tenants/<name>.conf` (`<VirtualHost *:80>`).
   - Deleting a tenant removes its database records, `.conf`, `.log`, Apache VirtualHost config, and `/var/www/tenants/<name>/` directory.
4. **Terminal Isolation (`server.js` & `scripts/profile.sh`):**
   - Root `/` and `/?tenant=<name>` require **ZunoX Tenant Login** (`POST /api/tenant-login`, `zunox_tenant_session` cookie).
   - Spawns `/bin/bash --login` in `/var/www/tenants/<name>` with prompt `root@<name>/webvm:<cwd># `.
   - `webvm info` and `webvm admin` hide/block the Admin Dashboard URL from tenant sessions.

---

## 5. Admin Dashboard (`site/admin.php`)
- **Authentication:** Session-based login (`adminman` / `adminman` default) with **Change PW** and **Logout**.
- **Real-Time Dashboard:** Polls `?api=metrics` every 2 seconds for Total/Active/Blocked tenants, CPU load, Memory usage, Disk usage, and Uptime with color-coded progress bars.
- **Tenant Management:** Create tenants, list/sync tenants, block/unblock access, reset tenant passwords, launch tenant terminals, and delete tenants with full environment cleanup.
- **User & System Logs:** Filterable viewer for Admin actions, per-tenant `.log` files, Apache access/error logs, and Supervisor logs.
- **Help & Tips:** Built-in GUI guide with field references and example environment configurations.

---

## 6. Key Project Files
- [Dockerfile](Dockerfile) & [docker-compose.yml](docker-compose.yml) — Container build and port/volume configuration.
- [supervisord.conf](supervisord.conf) — Supervises `apache` and `webvm-terminal`.
- [apache/webvm.conf](apache/webvm.conf) — Port `80` isolation, port `8082` admin vhost, and `IncludeOptional /etc/apache2/conf.d/tenants/*.conf`.
- [site/admin.php](site/admin.php) — Admin Dashboard, REST API, and SQLite/Apache provisioning.
- [server/server.js](server/server.js) & [server/public/](server/public/) — Terminal WebSocket server, tenant login UI, and real-time status bar.
- [scripts/webvm](scripts/webvm) & [scripts/profile.sh](scripts/profile.sh) — Container CLI helper and shell prompt configuration.
- [README.md](README.md), [GUIDE.md](GUIDE.md), [Architecture.html](Architecture.html), [uml.html](uml.html), [bug.md](bug.md), [LICENSE](LICENSE).

---

## 7. Future Roadmap — ZunoX v3.0.0 (Container-Level Isolation)
- **Per-Tenant Containers:** Each tenant will be provisioned as a dedicated, fully isolated Docker container instead of sharing a single container.
- **Resource Quotas & Security:** Independent CPU, memory, and disk limits per tenant container, with isolated network namespaces and root filesystems.
- **Dynamic Orchestration & Routing:** The Admin Dashboard will orchestrate container lifecycle (create, start, stop, block/pause, delete) and route traffic dynamically via a reverse proxy.
