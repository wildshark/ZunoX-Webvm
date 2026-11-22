# WebVM Admin & CLI Guide

## 1. Using the Admin Interface (`http://localhost:8082/admin.php`)

Open **http://localhost:8082/admin.php** in your browser to manage tenants, monitor system health, and inspect logs.

### Dashboard Tab
- **Real-Time Metrics:** Automatically refreshes every 3 seconds to show **Total/Active/Blocked Tenants**, **CPU Load (1m)**, **Memory Usage**, **Disk Usage**, and **Container Uptime**.
- **Quick Tenant Overview:** View recent tenants and quickly **Block**, **Unblock**, or **Delete** them, or click **+ New Tenant** to create one.

### Tenants & Users Tab
- Lists all registered tenants from `/etc/webvm/tenants.db` (and syncs `.conf` files in `/etc/webvm/tenants/`).
- **Row Actions:**
  - **Terminal:** Opens `http://localhost:7681/?tenant=<name>` in a new tab.
  - **Block / Unblock:** Immediately toggles user access (`BLOCKED=1` in `/etc/webvm/tenants/<name>.conf`).
  - **Delete:** Permanently removes the tenant, its environment variables, and its `.conf` file.

### Create Tenant Tab
- **Tenant / Username (required):** Alphanumeric, `_`, or `-` (e.g., `acme_dev`).
- **Password (optional):** If set, the tenant must log in with HTTP Basic Auth (`username` + `password`) before accessing their terminal.
- **Hostname (optional):** Defaults to `<name>.localhost`.
- **Environment:** Choose `default`, `development`, `staging`, or `production`.
- **Environment Variables (optional):** One `KEY=VALUE` per line (e.g., `APP_DEBUG=true`, `PHP_MEMORY_LIMIT=256M`, `TZ=UTC`).

### User Logs Tab
- Select a log source: **Admin & User Actions**, **Apache Access Log**, **Apache Error Log**, or **Supervisor Log**.
- Use the **Filter logs...** input to search by keyword or tenant name.

### Help & Tips Tab
- Built-in reference for all GUI tabs, form fields, and example environment configurations.

---

## 2. Using the `webvm` CLI

Inside the Web Terminal (`http://localhost:7681/`) or via `docker exec -it webvm bash`, you can run the `webvm` helper command:

```sh
webvm info             # Show Alpine, PHP, Apache, auth, and URL details
webvm site             # Print the PHP site URL
webvm terminal         # Print the terminal URL
webvm status           # Show Apache and terminal process status
webvm tools            # Show Python, Go, C/C++, and SQLite versions
webvm restart-apache   # Restart Apache through supervisord
webvm logs             # Follow the Apache error log
```

### OpenCode AI CLI
```sh
opencode                               # Launch interactive AI coding TUI
opencode run "Explain this PHP site"   # Run a one-off prompt
webvm opencode-status                  # Check OpenCode version and auth status
```

### Docker Compose Commands (Host)
```powershell
docker compose up -d --build   # Build and start the container
docker compose ps              # Check container health and ports
docker compose logs -f         # Stream Apache and terminal logs
docker compose restart         # Restart all services
docker compose down            # Stop and remove the container
```
