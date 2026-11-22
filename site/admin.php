<?php
/**
 * WebVM Admin Dashboard
 * Real-time metrics, tenant management, user logs, block/unblock, and environment cleanup.
 */

declare(strict_types=1);
if (($_SERVER['SERVER_PORT'] ?? '') !== '8082') {
    http_response_code(403);
    exit('403 Forbidden: Admin interface is isolated to port 8082.');
}
session_start();

const DB_PATH = '/etc/webvm/tenants.db';
const TENANT_CONF_DIR = '/etc/webvm/tenants';
const APACHE_TENANT_CONF_DIR = '/etc/apache2/conf.d/tenants';
const TENANT_WWW_DIR = '/var/www/tenants';

// Ensure tenant config directory exists
if (!is_dir(TENANT_CONF_DIR)) {
    @mkdir(TENANT_CONF_DIR, 0755, true);
}

/**
 * Get SQLite PDO connection and ensure schema exists.
 */
function getDb(): PDO {
    $pdo = new PDO('sqlite:' . DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec("PRAGMA foreign_keys = ON;");
    $pdo->exec("CREATE TABLE IF NOT EXISTS tenants (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT UNIQUE,
        hostname TEXT UNIQUE,
        environment TEXT DEFAULT 'default',
        is_blocked INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS tenant_env (
        tenant_id INTEGER,
        key TEXT,
        value TEXT,
        PRIMARY KEY (tenant_id, key),
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS admin_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        tenant_name TEXT,
        action TEXT,
        details TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT UNIQUE,
        password_hash TEXT,
        role TEXT DEFAULT 'admin',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $chk = $pdo->prepare("SELECT id FROM users WHERE username = ?");
    $chk->execute(['adminman']);
    if (!$chk->fetch()) {
        $ins = $pdo->prepare("INSERT INTO users (username, password_hash, role) VALUES (?, ?, 'admin')");
        $ins->execute(['adminman', password_hash('adminman', PASSWORD_DEFAULT)]);
    }
    return $pdo;
}

/**
 * Record an admin/user action in admin_logs and per-tenant log file.
 */
function logAction(PDO $pdo, string $tenantName, string $action, string $details = ''): void {
    $stmt = $pdo->prepare("INSERT INTO admin_logs (tenant_name, action, details) VALUES (?, ?, ?)");
    $stmt->execute([$tenantName, $action, $details]);
    $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '', $tenantName);
    if ($safeName !== '') {
        $line = sprintf("[%s] %s: %s\n", date('Y-m-d H:i:s'), $action, $details);
        @file_put_contents(TENANT_CONF_DIR . "/{$safeName}.log", $line, FILE_APPEND);
    }
}

/**
 * Sync existing .conf files from /etc/webvm/tenants/ into SQLite if not already present.
 */
function syncConfFilesToDb(PDO $pdo): void {
    $files = glob(TENANT_CONF_DIR . '/*.conf') ?: [];
    foreach ($files as $file) {
        $name = basename($file, '.conf');
        $stmt = $pdo->prepare("SELECT id FROM tenants WHERE name = ?");
        $stmt->execute([$name]);
        if (!$stmt->fetch()) {
            $content = file_get_contents($file) ?: '';
            $isBlocked = str_contains($content, 'BLOCKED=1') ? 1 : 0;
            $ins = $pdo->prepare("INSERT OR IGNORE INTO tenants (name, hostname, environment, is_blocked) VALUES (?, ?, 'default', ?)");
            $ins->execute([$name, $name . '.localhost', $isBlocked]);
            $tenantId = (int)$pdo->lastInsertId();
            if ($tenantId > 0) {
                foreach (explode("\n", $content) as $line) {
                    $line = trim($line);
                    if ($line === '' || str_starts_with($line, '#')) continue;
                    $pos = strpos($line, '=');
                    if ($pos !== false) {
                        $k = trim(substr($line, 0, $pos));
                        $v = trim(substr($line, $pos + 1));
                        if ($k !== '' && $k !== 'BLOCKED') {
                            $envStmt = $pdo->prepare("INSERT OR REPLACE INTO tenant_env (tenant_id, key, value) VALUES (?, ?, ?)");
                            $envStmt->execute([$tenantId, $k, $v]);
                        }
                    }
                }
            }
        }
    }
}

/**
 * Write tenant .conf file for server.js
 */
function writeTenantConf(string $name, array $envVars, bool $isBlocked): void {
    $lines = ["# WebVM Tenant Configuration: {$name}"];
    foreach ($envVars as $k => $v) {
        $k = preg_replace('/[^A-Za-z0-9_]/', '', (string)$k);
        if ($k !== '' && $k !== 'BLOCKED') {
            $lines[] = "{$k}={$v}";
        }
    }
    if ($isBlocked) {
        $lines[] = "BLOCKED=1";
    }
    file_put_contents(TENANT_CONF_DIR . "/{$name}.conf", implode("\n", $lines) . "\n");
}

/**
 * Collect real-time system & tenant metrics.
 */
function getSystemMetrics(PDO $pdo): array {
    $load = function_exists('sys_getloadavg') ? sys_getloadavg() : [0, 0, 0];

    $memTotal = 0;
    $memAvail = 0;
    if (is_readable('/proc/meminfo')) {
        $meminfo = file_get_contents('/proc/meminfo') ?: '';
        if (preg_match('/MemTotal:\s+(\d+)\s+kB/', $meminfo, $m)) $memTotal = (int)$m[1];
        if (preg_match('/MemAvailable:\s+(\d+)\s+kB/', $meminfo, $m)) $memAvail = (int)$m[1];
    }
    $memUsedPct = $memTotal > 0 ? round((($memTotal - $memAvail) / $memTotal) * 100, 1) : 0;

    $diskTotal = @disk_total_space('/') ?: 1;
    $diskFree = @disk_free_space('/') ?: 0;
    $diskUsedPct = round((($diskTotal - $diskFree) / $diskTotal) * 100, 1);

    $uptimeSec = 0;
    if (is_readable('/proc/uptime')) {
        $parts = explode(' ', trim((string)file_get_contents('/proc/uptime')));
        $uptimeSec = (int)floor((float)($parts[0] ?? 0));
    }

    $totalTenants = (int)$pdo->query("SELECT COUNT(*) FROM tenants")->fetchColumn();
    $blockedTenants = (int)$pdo->query("SELECT COUNT(*) FROM tenants WHERE is_blocked = 1")->fetchColumn();
    $activeTenants = $totalTenants - $blockedTenants;

    return [
        'timestamp' => date('H:i:s'),
        'cpu_load' => round((float)($load[0] ?? 0), 2),
        'mem_used_pct' => $memUsedPct,
        'mem_total_mb' => round($memTotal / 1024, 0),
        'disk_used_pct' => $diskUsedPct,
        'uptime_sec' => $uptimeSec,
        'total_tenants' => $totalTenants,
        'active_tenants' => $activeTenants,
        'blocked_tenants' => $blockedTenants,
    ];
}

// ============================================================
// API Router (JSON endpoints for real-time updates & actions)
// ============================================================
if (isset($_GET['api'])) {
    header('Content-Type: application/json');
    try {
        $pdo = getDb();
        $api = $_GET['api'];

        if ($api === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $input = json_decode(file_get_contents('php://input') ?: '{}', true) ?: $_POST;
            $username = trim((string)($input['username'] ?? ''));
            $password = (string)($input['password'] ?? '');
            $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
            $stmt->execute([$username]);
            $user = $stmt->fetch();
            if ($user && password_verify($password, $user['password_hash'])) {
                $_SESSION['admin_user'] = $user['username'];
                logAction($pdo, 'admin', 'ADMIN_LOGIN', "Admin '{$user['username']}' logged in");
                echo json_encode(['ok' => true]);
            } else {
                http_response_code(401);
                echo json_encode(['ok' => false, 'error' => 'Invalid username or password.']);
            }
            exit;
        }

        if ($api === 'logout') {
            session_destroy();
            echo json_encode(['ok' => true]);
            exit;
        }

        if (empty($_SESSION['admin_user'])) {
            http_response_code(401);
            echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
            exit;
        }

        if ($api === 'change_admin_password' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $input = json_decode(file_get_contents('php://input') ?: '{}', true) ?: $_POST;
            $current = (string)($input['current_password'] ?? '');
            $newPass = (string)($input['new_password'] ?? '');
            if (strlen($newPass) < 4) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'New password must be at least 4 characters.']);
                exit;
            }
            $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
            $stmt->execute([$_SESSION['admin_user']]);
            $user = $stmt->fetch();
            if (!$user || !password_verify($current, $user['password_hash'])) {
                http_response_code(403);
                echo json_encode(['ok' => false, 'error' => 'Current password is incorrect.']);
                exit;
            }
            $upd = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
            $upd->execute([password_hash($newPass, PASSWORD_DEFAULT), $user['id']]);
            logAction($pdo, 'admin', 'CHANGE_ADMIN_PASSWORD', "Admin '{$user['username']}' changed password");
            echo json_encode(['ok' => true, 'message' => 'Admin password updated.']);
            exit;
        }

        syncConfFilesToDb($pdo);

        if ($api === 'metrics') {
            echo json_encode(['ok' => true, 'data' => getSystemMetrics($pdo)]);
            exit;
        }

        if ($api === 'tenants') {
            $rows = $pdo->query("SELECT * FROM tenants ORDER BY created_at DESC")->fetchAll();
            foreach ($rows as &$row) {
                $stmt = $pdo->prepare("SELECT key, value FROM tenant_env WHERE tenant_id = ?");
                $stmt->execute([$row['id']]);
                $row['env'] = $stmt->fetchAll();
            }
            echo json_encode(['ok' => true, 'data' => $rows]);
            exit;
        }

        if ($api === 'create_tenant' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $input = json_decode(file_get_contents('php://input') ?: '{}', true) ?: $_POST;
            $name = preg_replace('/[^a-zA-Z0-9_-]/', '', trim((string)($input['name'] ?? '')));
            $hostname = trim((string)($input['hostname'] ?? ($name . '.localhost')));
            $environment = trim((string)($input['environment'] ?? 'default'));
            $password = trim((string)($input['password'] ?? ''));
            $envRaw = trim((string)($input['env_vars'] ?? ''));

            if ($name === '') {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'Tenant name is required (alphanumeric, _ or -).']);
                exit;
            }

            $stmt = $pdo->prepare("INSERT INTO tenants (name, hostname, environment, is_blocked) VALUES (?, ?, ?, 0)");
            $stmt->execute([$name, $hostname, $environment]);
            $tenantId = (int)$pdo->lastInsertId();

            $envMap = ['TENANT_ENV' => $environment];
            if ($password !== '') {
                $envMap['TENANT_PASSWORD'] = $password;
            }
            if ($envRaw !== '') {
                foreach (explode("\n", $envRaw) as $line) {
                    $line = trim($line);
                    $pos = strpos($line, '=');
                    if ($pos !== false) {
                        $k = preg_replace('/[^A-Za-z0-9_]/', '', trim(substr($line, 0, $pos)));
                        $v = trim(substr($line, $pos + 1));
                        if ($k !== '' && $k !== 'BLOCKED') {
                            $envMap[$k] = $v;
                        }
                    }
                }
            }

            $envStmt = $pdo->prepare("INSERT OR REPLACE INTO tenant_env (tenant_id, key, value) VALUES (?, ?, ?)");
            foreach ($envMap as $k => $v) {
                $envStmt->execute([$tenantId, $k, $v]);
            }

            writeTenantConf($name, $envMap, false);

            // Provision isolated Apache DocumentRoot & VirtualHost
            $docRoot = TENANT_WWW_DIR . "/{$name}";
            if (!is_dir($docRoot)) {
                @mkdir($docRoot, 0755, true);
                @file_put_contents("{$docRoot}/index.php", "<?php\necho 'ZunoX Tenant: " . htmlspecialchars($name, ENT_QUOTES) . "';\n");
            }
            if (!is_dir(APACHE_TENANT_CONF_DIR)) {
                @mkdir(APACHE_TENANT_CONF_DIR, 0755, true);
            }
            $vhost = "<VirtualHost *:80>\n    ServerName {$hostname}\n    DocumentRoot {$docRoot}\n    <Directory \"{$docRoot}\">\n        Options -Indexes +FollowSymLinks\n        AllowOverride All\n        Require all granted\n    </Directory>\n</VirtualHost>\n";
            @file_put_contents(APACHE_TENANT_CONF_DIR . "/{$name}.conf", $vhost);

            logAction($pdo, $name, 'CREATE_TENANT', "Created tenant '{$name}' ({$environment})");

            echo json_encode(['ok' => true, 'message' => "Tenant '{$name}' created."]);
            exit;
        }

        if ($api === 'toggle_block' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $input = json_decode(file_get_contents('php://input') ?: '{}', true) ?: $_POST;
            $id = (int)($input['id'] ?? 0);
            $stmt = $pdo->prepare("SELECT * FROM tenants WHERE id = ?");
            $stmt->execute([$id]);
            $tenant = $stmt->fetch();
            if (!$tenant) {
                http_response_code(404);
                echo json_encode(['ok' => false, 'error' => 'Tenant not found.']);
                exit;
            }

            $newBlocked = $tenant['is_blocked'] ? 0 : 1;
            $pdo->prepare("UPDATE tenants SET is_blocked = ? WHERE id = ?")->execute([$newBlocked, $id]);

            $envStmt = $pdo->prepare("SELECT key, value FROM tenant_env WHERE tenant_id = ?");
            $envStmt->execute([$id]);
            $envMap = [];
            foreach ($envStmt->fetchAll() as $e) {
                $envMap[$e['key']] = $e['value'];
            }
            writeTenantConf($tenant['name'], $envMap, (bool)$newBlocked);

            $action = $newBlocked ? 'BLOCK_USER' : 'UNBLOCK_USER';
            logAction($pdo, $tenant['name'], $action, $newBlocked ? 'Access blocked' : 'Access unblocked');

            echo json_encode(['ok' => true, 'is_blocked' => $newBlocked]);
            exit;
        }

        if ($api === 'reset_password' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $input = json_decode(file_get_contents('php://input') ?: '{}', true) ?: $_POST;
            $id = (int)($input['id'] ?? 0);
            $newPass = trim((string)($input['password'] ?? ''));

            $stmt = $pdo->prepare("SELECT * FROM tenants WHERE id = ?");
            $stmt->execute([$id]);
            $tenant = $stmt->fetch();
            if (!$tenant) {
                http_response_code(404);
                echo json_encode(['ok' => false, 'error' => 'Tenant not found.']);
                exit;
            }

            if ($newPass === '') {
                $pdo->prepare("DELETE FROM tenant_env WHERE tenant_id = ? AND key = 'TENANT_PASSWORD'")->execute([$id]);
            } else {
                $pdo->prepare("INSERT OR REPLACE INTO tenant_env (tenant_id, key, value) VALUES (?, 'TENANT_PASSWORD', ?)")->execute([$id, $newPass]);
            }

            $envStmt = $pdo->prepare("SELECT key, value FROM tenant_env WHERE tenant_id = ?");
            $envStmt->execute([$id]);
            $envMap = [];
            foreach ($envStmt->fetchAll() as $e) {
                $envMap[$e['key']] = $e['value'];
            }
            writeTenantConf($tenant['name'], $envMap, (bool)$tenant['is_blocked']);

            $msg = $newPass === '' ? 'Password cleared' : 'Password reset';
            logAction($pdo, $tenant['name'], 'RESET_PASSWORD', $msg);
            echo json_encode(['ok' => true, 'message' => "{$msg} for '{$tenant['name']}'."]);
            exit;
        }

        if ($api === 'delete_tenant' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $input = json_decode(file_get_contents('php://input') ?: '{}', true) ?: $_POST;
            $id = (int)($input['id'] ?? 0);
            $stmt = $pdo->prepare("SELECT * FROM tenants WHERE id = ?");
            $stmt->execute([$id]);
            $tenant = $stmt->fetch();
            if (!$tenant) {
                http_response_code(404);
                echo json_encode(['ok' => false, 'error' => 'Tenant not found.']);
                exit;
            }

            $pdo->prepare("DELETE FROM tenant_env WHERE tenant_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM tenants WHERE id = ?")->execute([$id]);

            $confFile = TENANT_CONF_DIR . '/' . $tenant['name'] . '.conf';
            if (file_exists($confFile)) {
                @unlink($confFile);
            }
            $logFile = TENANT_CONF_DIR . '/' . $tenant['name'] . '.log';
            if (file_exists($logFile)) {
                @unlink($logFile);
            }
            $apacheConf = APACHE_TENANT_CONF_DIR . '/' . $tenant['name'] . '.conf';
            if (file_exists($apacheConf)) {
                @unlink($apacheConf);
            }
            $docRoot = TENANT_WWW_DIR . '/' . $tenant['name'];
            if (is_dir($docRoot)) {
                @array_map('unlink', glob("{$docRoot}/*") ?: []);
                @rmdir($docRoot);
            }

            logAction($pdo, $tenant['name'], 'DELETE_TENANT', "Deleted tenant '{$tenant['name']}' and its environment");
            echo json_encode(['ok' => true, 'message' => "Tenant '{$tenant['name']}' and environment deleted."]);
            exit;
        }

        if ($api === 'logs') {
            $source = $_GET['source'] ?? 'admin';
            $filter = strtolower(trim((string)($_GET['q'] ?? '')));

            if ($source === 'admin') {
                $logs = $pdo->query("SELECT * FROM admin_logs ORDER BY id DESC LIMIT 100")->fetchAll();
                if ($filter !== '') {
                    $logs = array_values(array_filter($logs, fn($l) =>
                        str_contains(strtolower($l['tenant_name'] . ' ' . $l['action'] . ' ' . $l['details']), $filter)
                    ));
                }
                echo json_encode(['ok' => true, 'source' => 'admin', 'data' => $logs]);
                exit;
            }

            if ($source === 'tenant') {
                $tName = preg_replace('/[^a-zA-Z0-9_-]/', '', trim((string)($_GET['tenant'] ?? '')));
                $path = $tName !== '' ? (TENANT_CONF_DIR . "/{$tName}.log") : '';
                $lines = [];
                if ($path !== '' && is_readable($path)) {
                    $raw = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
                    $lines = array_reverse(array_slice($raw, -100));
                    if ($filter !== '') {
                        $lines = array_values(array_filter($lines, fn($l) => str_contains(strtolower($l), $filter)));
                    }
                }
                echo json_encode(['ok' => true, 'source' => 'tenant', 'data' => $lines]);
                exit;
            }

            $logFiles = [
                'apache_access' => '/var/log/apache2/access.log',
                'apache_error'  => '/var/log/apache2/error.log',
                'supervisor'    => '/var/log/supervisor/supervisord.log',
            ];
            $path = $logFiles[$source] ?? '';
            $lines = [];
            if ($path !== '' && is_readable($path)) {
                $raw = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
                $lines = array_slice($raw, -100);
                $lines = array_reverse($lines);
                if ($filter !== '') {
                    $lines = array_values(array_filter($lines, fn($l) => str_contains(strtolower($l), $filter)));
                }
            }
            echo json_encode(['ok' => true, 'source' => $source, 'data' => $lines]);
            exit;
        }

        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Unknown API endpoint']);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if (empty($_SESSION['admin_user'])) {
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ZunoX Admin Login</title>
<style>
  body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background:#0f172a; color:#f8fafc; display:flex; align-items:center; justify-content:center; min-height:100vh; margin:0; }
  .login-card { background:#1e293b; border:1px solid #334155; border-radius:12px; padding:28px; width:100%; max-width:360px; }
  h1 { font-size:1.3rem; margin:0 0 6px; }
  p { font-size:0.85rem; color:#94a3b8; margin:0 0 20px; }
  label { display:block; font-size:0.8rem; color:#94a3b8; margin-bottom:5px; }
  input { width:100%; box-sizing:border-box; padding:9px 12px; border-radius:8px; border:1px solid #334155; background:#0f172a; color:#f8fafc; margin-bottom:14px; font-size:0.9rem; }
  button { width:100%; padding:10px; border:none; border-radius:8px; background:#3b82f6; color:#fff; font-weight:600; cursor:pointer; font-size:0.92rem; }
  button:hover { background:#2563eb; }
  .err { color:#ef4444; font-size:0.84rem; margin-bottom:12px; display:none; }
</style>
</head>
<body>
  <div class="login-card">
    <h1>ZunoX Admin</h1>
    <p>Sign in to manage tenants &amp; system</p>
    <div class="err" id="err"></div>
    <form onsubmit="doLogin(event)">
      <label>Username</label>
      <input type="text" id="u" value="adminman" required autofocus>
      <label>Password</label>
      <input type="password" id="p" required>
      <button type="submit">Sign In</button>
    </form>
  </div>
<script>
async function doLogin(e) {
  e.preventDefault();
  const res = await fetch('?api=login', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({ username: document.getElementById('u').value.trim(), password: document.getElementById('p').value })
  });
  const json = await res.json();
  if (json.ok) { location.reload(); }
  else { const el = document.getElementById('err'); el.textContent = json.error || 'Login failed'; el.style.display = 'block'; }
}
</script>
</body>
</html>
<?php
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ZunoX Admin Dashboard</title>
<style>
  :root {
    --bg: #0f172a;
    --surface: #1e293b;
    --surface-hover: #334155;
    --border: #334155;
    --text: #f8fafc;
    --muted: #94a3b8;
    --primary: #3b82f6;
    --primary-hover: #2563eb;
    --success: #10b981;
    --warning: #f59e0b;
    --danger: #ef4444;
  }
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body {
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    background: var(--bg);
    color: var(--text);
    display: flex;
    min-height: 100vh;
  }
  /* Sidebar */
  .sidebar {
    width: 240px;
    background: var(--surface);
    border-right: 1px solid var(--border);
    display: flex;
    flex-direction: column;
    padding: 20px 14px;
    flex-shrink: 0;
  }
  .brand {
    font-size: 1.25rem;
    font-weight: 700;
    color: var(--text);
    padding: 0 10px 20px;
    border-bottom: 1px solid var(--border);
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    justify-content: space-between;
  }
  .badge-live {
    font-size: 0.7rem;
    background: rgba(16, 185, 129, 0.15);
    color: var(--success);
    padding: 2px 8px;
    border-radius: 999px;
    border: 1px solid rgba(16, 185, 129, 0.3);
  }
  .nav-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 12px;
    border-radius: 8px;
    color: var(--muted);
    cursor: pointer;
    font-size: 0.92rem;
    font-weight: 500;
    margin-bottom: 4px;
    transition: background 0.15s, color 0.15s;
    border: none;
    background: transparent;
    width: 100%;
    text-align: left;
  }
  .nav-item:hover { background: var(--surface-hover); color: var(--text); }
  .nav-item.active { background: var(--primary); color: #fff; }
  .sidebar-footer {
    margin-top: auto;
    padding-top: 16px;
    border-top: 1px solid var(--border);
    font-size: 0.8rem;
    color: var(--muted);
  }
  .sidebar-footer a { color: var(--primary); text-decoration: none; }

  /* Main Content */
  .main {
    flex: 1;
    padding: 28px 32px;
    overflow-y: auto;
  }
  .header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
  }
  .header h1 { font-size: 1.5rem; font-weight: 600; }
  .last-updated { font-size: 0.82rem; color: var(--muted); }

  /* Metric Cards */
  .metrics-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 16px;
    margin-bottom: 28px;
  }
  .card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 18px 20px;
  }
  .card-label { font-size: 0.8rem; color: var(--muted); text-transform: uppercase; letter-spacing: 0.04em; }
  .card-value { font-size: 1.8rem; font-weight: 700; margin-top: 6px; }
  .card-sub { font-size: 0.8rem; color: var(--muted); margin-top: 4px; }

  /* Panels */
  .panel {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 24px;
  }
  .panel-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 16px;
    gap: 12px;
    flex-wrap: wrap;
  }
  .panel-title { font-size: 1.1rem; font-weight: 600; }

  /* Forms & Buttons */
  .btn {
    padding: 8px 14px;
    border-radius: 8px;
    border: none;
    font-size: 0.85rem;
    font-weight: 500;
    cursor: pointer;
    transition: opacity 0.15s, background 0.15s;
    color: #fff;
    background: var(--primary);
  }
  .btn:hover { background: var(--primary-hover); }
  .btn-sm { padding: 5px 10px; font-size: 0.78rem; }
  .btn-success { background: var(--success); }
  .btn-warning { background: var(--warning); color: #0f172a; }
  .btn-danger { background: var(--danger); }
  .btn-ghost { background: transparent; border: 1px solid var(--border); color: var(--text); }
  .btn-ghost:hover { background: var(--surface-hover); }

  input, select, textarea {
    background: var(--bg);
    border: 1px solid var(--border);
    color: var(--text);
    padding: 8px 12px;
    border-radius: 8px;
    font-size: 0.88rem;
    width: 100%;
  }
  .form-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 14px;
    margin-bottom: 14px;
  }
  label { display: block; font-size: 0.8rem; color: var(--muted); margin-bottom: 5px; }

  /* Table */
  table { width: 100%; border-collapse: collapse; font-size: 0.88rem; }
  th, td { text-align: left; padding: 12px 10px; border-bottom: 1px solid var(--border); }
  th { color: var(--muted); font-weight: 600; font-size: 0.78rem; text-transform: uppercase; }
  tr:last-child td { border-bottom: none; }
  .status-pill {
    display: inline-block;
    padding: 2px 9px;
    border-radius: 999px;
    font-size: 0.75rem;
    font-weight: 600;
  }
  .status-active { background: rgba(16, 185, 129, 0.15); color: var(--success); }
  .status-blocked { background: rgba(239, 68, 68, 0.15); color: var(--danger); }
  .actions-cell { display: flex; gap: 6px; flex-wrap: wrap; }

  /* Logs */
  .log-box {
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 14px;
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
    font-size: 0.8rem;
    max-height: 440px;
    overflow-y: auto;
    line-height: 1.5;
  }
  .log-line { padding: 3px 0; border-bottom: 1px solid rgba(51, 65, 85, 0.4); }
  .section { display: none; }
  .section.active { display: block; }
  .toast {
    position: fixed;
    bottom: 20px;
    right: 20px;
    background: var(--surface);
    border: 1px solid var(--primary);
    padding: 12px 18px;
    border-radius: 10px;
    font-size: 0.88rem;
    display: none;
    z-index: 100;
  }
</style>
</head>
<body>

<aside class="sidebar">
  <div class="brand">
    <span>ZunoX Admin</span>
    <span class="badge-live">LIVE</span>
  </div>
  <button class="nav-item active" onclick="switchTab('dashboard', this)">Dashboard</button>
  <button class="nav-item" onclick="switchTab('tenants', this)">Tenants &amp; Users</button>
  <button class="nav-item" onclick="switchTab('create', this)">Create Tenant</button>
  <button class="nav-item" onclick="switchTab('logs', this)">User Logs</button>
  <button class="nav-item" onclick="switchTab('help', this)">Help &amp; Tips</button>
  <div class="sidebar-footer">
    <div>ZunoX v2.1.0 &middot; Port 8082</div>
    <div style="margin-top:2px; font-size:0.75rem;">&copy; 2026 ZunoX. All rights reserved.</div>
    <div style="margin-top:6px;"><a href="http://localhost:7681/" target="_blank">Open Web Terminal &rarr;</a></div>
    <div style="margin-top:10px; display:flex; gap:6px;">
      <button class="btn btn-sm btn-ghost" onclick="changeAdminPassword()">Change PW</button>
      <button class="btn btn-sm btn-danger" onclick="doLogout()">Logout</button>
    </div>
  </div>
</aside>

<main class="main">
  <div class="header">
    <h1 id="pageTitle">Real-Time Dashboard</h1>
    <div class="last-updated">Last sync: <span id="lastSync">--:--:--</span></div>
  </div>

  <!-- DASHBOARD SECTION -->
  <section id="sec-dashboard" class="section active">
    <div class="metrics-grid">
      <div class="card">
        <div class="card-label">Total Tenants</div>
        <div class="card-value" id="m-total">-</div>
        <div class="card-sub"><span id="m-active">-</span> active &middot; <span id="m-blocked">-</span> blocked</div>
      </div>
      <div class="card">
        <div class="card-label">CPU Load (1m)</div>
        <div class="card-value" id="m-cpu">-</div>
        <div style="background:var(--bg); height:6px; border-radius:999px; margin-top:8px; overflow:hidden;">
          <div id="cpu-bar" style="height:100%; width:0%; background:var(--primary); transition:width 0.3s;"></div>
        </div>
        <div class="card-sub">System load average</div>
      </div>
      <div class="card">
        <div class="card-label">Memory Usage</div>
        <div class="card-value" id="m-mem">-</div>
        <div style="background:var(--bg); height:6px; border-radius:999px; margin-top:8px; overflow:hidden;">
          <div id="mem-bar" style="height:100%; width:0%; background:var(--success); transition:width 0.3s;"></div>
        </div>
        <div class="card-sub" id="m-mem-sub">- MB total</div>
      </div>
      <div class="card">
        <div class="card-label">Disk Usage</div>
        <div class="card-value" id="m-disk">-</div>
        <div class="card-sub">Uptime: <span id="m-uptime">-</span></div>
      </div>
    </div>

    <div class="panel">
      <div class="panel-header">
        <div class="panel-title">Quick Tenant Overview</div>
        <button class="btn btn-sm" onclick="switchTab('create', document.querySelectorAll('.nav-item')[2])">+ New Tenant</button>
      </div>
      <table>
        <thead>
          <tr><th>Name</th><th>Environment</th><th>Status</th><th>Actions</th></tr>
        </thead>
        <tbody id="dash-tenants-body"></tbody>
      </table>
    </div>
  </section>

  <!-- TENANTS LIST SECTION -->
  <section id="sec-tenants" class="section">
    <div class="panel">
      <div class="panel-header">
        <div class="panel-title">All Tenants &amp; Users</div>
        <button class="btn btn-sm" onclick="loadTenants()">Refresh</button>
      </div>
      <table>
        <thead>
          <tr>
            <th>Name</th>
            <th>Hostname</th>
            <th>Environment</th>
            <th>Status</th>
            <th>Created</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody id="tenants-body"></tbody>
      </table>
    </div>
  </section>

  <!-- CREATE TENANT SECTION -->
  <section id="sec-create" class="section">
    <div class="panel">
      <div class="panel-header">
        <div class="panel-title">Create New Tenant</div>
      </div>
      <form id="createForm" onsubmit="createTenant(event)">
        <div class="form-grid">
          <div>
            <label>Tenant / Username *</label>
            <input type="text" id="t-name" placeholder="e.g. acme_dev" required pattern="[A-Za-z0-9_-]+">
          </div>
          <div>
            <label>Password (for tenant login)</label>
            <input type="password" id="t-password" placeholder="Leave blank for no password">
          </div>
          <div>
            <label>Hostname</label>
            <input type="text" id="t-host" placeholder="e.g. acme_dev.localhost">
          </div>
          <div>
            <label>Environment</label>
            <select id="t-env">
              <option value="default">default</option>
              <option value="development">development</option>
              <option value="staging">staging</option>
              <option value="production">production</option>
            </select>
          </div>
        </div>
        <div style="margin-bottom: 14px;">
          <label>Environment Variables (KEY=VALUE, one per line)</label>
          <textarea id="t-vars" rows="4" placeholder="APP_DEBUG=true&#10;PHP_MEMORY_LIMIT=256M"></textarea>
        </div>
        <button type="submit" class="btn">Create Tenant &amp; Provision Environment</button>
      </form>
    </div>
  </section>

  <!-- USER LOGS SECTION -->
  <section id="sec-logs" class="section">
    <div class="panel">
      <div class="panel-header">
        <div class="panel-title">User &amp; System Logs</div>
        <div style="display:flex; gap:8px; align-items:center;">
          <select id="log-source" onchange="onLogSourceChange()" style="width:auto;">
            <option value="admin">Admin &amp; User Actions</option>
            <option value="tenant">Tenant Log File</option>
            <option value="apache_access">Apache Access Log</option>
            <option value="apache_error">Apache Error Log</option>
            <option value="supervisor">Supervisor Log</option>
          </select>
          <select id="log-tenant" onchange="loadLogs()" style="width:auto; display:none;"></select>
          <input type="text" id="log-filter" placeholder="Filter logs..." oninput="loadLogs()" style="width:180px;">
          <button class="btn btn-sm" onclick="loadLogs()">Refresh</button>
        </div>
      </div>
      <div class="log-box" id="log-box">Loading logs...</div>
    </div>
  </section>

  <!-- HELP & TIPS SECTION -->
  <section id="sec-help" class="section">
    <div class="panel">
      <div class="panel-header">
        <div class="panel-title">How to Use the Admin GUI</div>
      </div>
      <div style="display:grid; gap:16px; line-height:1.6; font-size:0.9rem;">
        <div>
          <h3 style="font-size:1rem; margin-bottom:4px; color:var(--primary);">1. Dashboard Tab</h3>
          <p style="color:var(--muted);">View real-time system metrics (Total/Active/Blocked tenants, CPU load, Memory, Disk, and Uptime) that auto-refresh every 3 seconds. The <strong>Quick Tenant Overview</strong> table lets you block, unblock, or delete recent tenants directly, or click <strong>+ New Tenant</strong> to jump to creation.</p>
        </div>
        <div>
          <h3 style="font-size:1rem; margin-bottom:4px; color:var(--primary);">2. Tenants &amp; Users Tab</h3>
          <p style="color:var(--muted);">Browse all tenants with their hostname, environment, status badge (<strong>Active</strong> or <strong>Blocked</strong>), and creation date. Use the row buttons to:</p>
          <ul style="color:var(--muted); padding-left:18px; margin-top:4px;">
            <li><strong>Terminal:</strong> Open that tenant's web terminal in a new tab.</li>
            <li><strong>Block / Unblock:</strong> Toggle user access immediately.</li>
            <li><strong>Delete:</strong> Permanently remove the tenant and its environment.</li>
          </ul>
        </div>
        <div>
          <h3 style="font-size:1rem; margin-bottom:4px; color:var(--primary);">3. Create Tenant Tab</h3>
          <p style="color:var(--muted);">Fill out the form fields to provision a new tenant account and its environment:</p>
          <ul style="color:var(--muted); padding-left:18px; margin-top:6px;">
            <li><strong>Tenant / Username (required):</strong> Unique identifier using letters, numbers, <code>_</code>, or <code>-</code> (e.g. <code>acme_dev</code>).</li>
            <li><strong>Hostname (optional):</strong> Subdomain used to route to this tenant (defaults to <code>&lt;name&gt;.localhost</code>).</li>
            <li><strong>Environment:</strong> Select from <code>default</code>, <code>development</code>, <code>staging</code>, or <code>production</code>.</li>
            <li><strong>Environment Variables (optional):</strong> <code>KEY=VALUE</code> pairs (one per line) injected into the tenant's shell. Common options:
              <ul style="padding-left:18px; margin-top:4px;">
                <li><code>APP_DEBUG=true</code> &mdash; Enable/disable debug mode (<code>true</code> / <code>false</code>)</li>
                <li><code>PHP_MEMORY_LIMIT=256M</code> &mdash; Custom PHP memory limit (e.g. <code>128M</code>, <code>256M</code>, <code>512M</code>)</li>
                <li><code>WEBVM_CWD=/var/www/localhost/htdocs</code> &mdash; Starting working directory for the terminal</li>
                <li><code>TZ=UTC</code> &mdash; Timezone for the tenant session (e.g. <code>UTC</code>, <code>America/New_York</code>)</li>
              </ul>
            </li>
          </ul>
          <div style="margin-top:8px; padding:10px 12px; background:var(--bg); border:1px solid var(--border); border-radius:8px; font-family:monospace; font-size:0.82rem; color:var(--text);">
            <div style="color:var(--muted); margin-bottom:4px;"># Example setup:</div>
            <div>Tenant / Username: <strong>acme_dev</strong></div>
            <div>Hostname: <strong>acme_dev.localhost</strong></div>
            <div>Environment: <strong>development</strong></div>
            <div>Environment Variables:</div>
            <div style="padding-left:12px; color:var(--success);">APP_DEBUG=true<br>PHP_MEMORY_LIMIT=256M<br>TZ=UTC</div>
          </div>
          <p style="color:var(--muted); margin-top:6px;">Click <strong>Create Tenant &amp; Provision Environment</strong> to save and open the Tenants list.</p>
        </div>
        <div>
          <h3 style="font-size:1rem; margin-bottom:4px; color:var(--primary);">4. User Logs Tab</h3>
          <p style="color:var(--muted);">Choose a log source from the dropdown (<strong>Admin &amp; User Actions</strong>, <strong>Apache Access Log</strong>, <strong>Apache Error Log</strong>, or <strong>Supervisor Log</strong>), type in the <strong>Filter logs...</strong> box to search by keyword or tenant name, and click <strong>Refresh</strong> to reload.</p>
        </div>
      </div>
    </div>
  </section>
</main>

<div class="toast" id="toast"></div>

<script>
const titles = {
  dashboard: 'Real-Time Dashboard',
  tenants: 'Tenants & Users',
  create: 'Create New Tenant',
  logs: 'User & System Logs',
  help: 'Help & Tips'
};

function switchTab(tab, el) {
  document.querySelectorAll('.section').forEach(s => s.classList.remove('active'));
  document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
  document.getElementById('sec-' + tab).classList.add('active');
  if (el) el.classList.add('active');
  document.getElementById('pageTitle').textContent = titles[tab] || 'Dashboard';
  if (tab === 'logs') loadLogs();
  if (tab === 'tenants' || tab === 'dashboard') loadTenants();
}

function showToast(msg) {
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.style.display = 'block';
  setTimeout(() => { t.style.display = 'none'; }, 3000);
}

function formatUptime(sec) {
  const h = Math.floor(sec / 3600);
  const m = Math.floor((sec % 3600) / 60);
  return `${h}h ${m}m`;
}

async function loadMetrics() {
  try {
    const res = await fetch('?api=metrics');
    const json = await res.json();
    if (!json.ok) return;
    const d = json.data;
    document.getElementById('lastSync').textContent = d.timestamp;
    document.getElementById('m-total').textContent = d.total_tenants;
    document.getElementById('m-active').textContent = d.active_tenants;
    document.getElementById('m-blocked').textContent = d.blocked_tenants;
    document.getElementById('m-cpu').textContent = d.cpu_load;
    const cpuPct = Math.min(100, Math.round(d.cpu_load * 25));
    document.getElementById('cpu-bar').style.width = cpuPct + '%';
    document.getElementById('cpu-bar').style.background = cpuPct > 80 ? 'var(--danger)' : cpuPct > 50 ? 'var(--warning)' : 'var(--primary)';

    document.getElementById('m-mem').textContent = d.mem_used_pct + '%';
    document.getElementById('mem-bar').style.width = d.mem_used_pct + '%';
    document.getElementById('mem-bar').style.background = d.mem_used_pct > 85 ? 'var(--danger)' : d.mem_used_pct > 65 ? 'var(--warning)' : 'var(--success)';
    document.getElementById('m-mem-sub').textContent = d.mem_total_mb + ' MB total';
    document.getElementById('m-disk').textContent = d.disk_used_pct + '%';
    document.getElementById('m-uptime').textContent = formatUptime(d.uptime_sec);
  } catch (e) {
    console.error('Metrics error:', e);
  }
}

async function loadTenants() {
  try {
    const res = await fetch('?api=tenants');
    const json = await res.json();
    if (!json.ok) return;
    const rows = json.data;

    const fullBody = document.getElementById('tenants-body');
    const dashBody = document.getElementById('dash-tenants-body');

    if (rows.length === 0) {
      fullBody.innerHTML = '<tr><td colspan="6" style="color:var(--muted)">No tenants found.</td></tr>';
      dashBody.innerHTML = '<tr><td colspan="4" style="color:var(--muted)">No tenants found.</td></tr>';
      return;
    }

    fullBody.innerHTML = rows.map(t => {
      const blocked = Number(t.is_blocked) === 1;
      const statusHtml = blocked
        ? '<span class="status-pill status-blocked">Blocked</span>'
        : '<span class="status-pill status-active">Active</span>';
      const blockBtn = blocked
        ? `<button class="btn btn-sm btn-success" onclick="toggleBlock(${t.id})">Unblock</button>`
        : `<button class="btn btn-sm btn-warning" onclick="toggleBlock(${t.id})">Block</button>`;
      return `<tr>
        <td><strong>${escapeHtml(t.name)}</strong></td>
        <td>${escapeHtml(t.hostname)}</td>
        <td>${escapeHtml(t.environment)}</td>
        <td>${statusHtml}</td>
        <td style="color:var(--muted)">${escapeHtml(t.created_at)}</td>
        <td class="actions-cell">
          <a class="btn btn-sm btn-ghost" href="http://localhost:7681/?tenant=${encodeURIComponent(t.name)}" target="_blank">Terminal</a>
          <button class="btn btn-sm btn-ghost" onclick="viewTenantLog('${escapeHtml(t.name)}')">Logs</button>
          <button class="btn btn-sm btn-ghost" onclick="resetPassword(${t.id}, '${escapeHtml(t.name)}')">Reset PW</button>
          ${blockBtn}
          <button class="btn btn-sm btn-danger" onclick="deleteTenant(${t.id}, '${escapeHtml(t.name)}')">Delete</button>
        </td>
      </tr>`;
    }).join('');

    dashBody.innerHTML = rows.slice(0, 5).map(t => {
      const blocked = Number(t.is_blocked) === 1;
      const statusHtml = blocked
        ? '<span class="status-pill status-blocked">Blocked</span>'
        : '<span class="status-pill status-active">Active</span>';
      const blockBtn = blocked
        ? `<button class="btn btn-sm btn-success" onclick="toggleBlock(${t.id})">Unblock</button>`
        : `<button class="btn btn-sm btn-warning" onclick="toggleBlock(${t.id})">Block</button>`;
      return `<tr>
        <td><strong>${escapeHtml(t.name)}</strong></td>
        <td>${escapeHtml(t.environment)}</td>
        <td>${statusHtml}</td>
        <td class="actions-cell">
          ${blockBtn}
          <button class="btn btn-sm btn-danger" onclick="deleteTenant(${t.id}, '${escapeHtml(t.name)}')">Delete</button>
        </td>
      </tr>`;
    }).join('');
  } catch (e) {
    console.error('Tenants error:', e);
  }
}

async function createTenant(e) {
  e.preventDefault();
  const payload = {
    name: document.getElementById('t-name').value.trim(),
    password: document.getElementById('t-password').value,
    hostname: document.getElementById('t-host').value.trim(),
    environment: document.getElementById('t-env').value,
    env_vars: document.getElementById('t-vars').value
  };
  const res = await fetch('?api=create_tenant', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify(payload)
  });
  const json = await res.json();
  if (json.ok) {
    showToast(json.message);
    document.getElementById('createForm').reset();
    loadTenants();
    loadMetrics();
    switchTab('tenants', document.querySelectorAll('.nav-item')[1]);
  } else {
    showToast('Error: ' + json.error);
  }
}

async function toggleBlock(id) {
  const res = await fetch('?api=toggle_block', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({ id })
  });
  const json = await res.json();
  if (json.ok) {
    showToast(json.is_blocked ? 'User access blocked.' : 'User access unblocked.');
    loadTenants();
    loadMetrics();
  }
}

async function resetPassword(id, name) {
  const pw = prompt(`Enter new password for "${name}" (leave blank to remove password):`);
  if (pw === null) return;
  const res = await fetch('?api=reset_password', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({ id, password: pw })
  });
  const json = await res.json();
  if (json.ok) {
    showToast(json.message);
  } else {
    showToast('Error: ' + json.error);
  }
}

async function deleteTenant(id, name) {
  if (!confirm(`Delete tenant "${name}" and its entire environment?`)) return;
  const res = await fetch('?api=delete_tenant', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({ id })
  });
  const json = await res.json();
  if (json.ok) {
    showToast(json.message);
    loadTenants();
    loadMetrics();
  }
}

async function onLogSourceChange() {
  const source = document.getElementById('log-source').value;
  const tenantSel = document.getElementById('log-tenant');
  if (source === 'tenant') {
    tenantSel.style.display = 'inline-block';
    const res = await fetch('?api=tenants');
    const json = await res.json();
    if (json.ok) {
      const current = tenantSel.value;
      tenantSel.innerHTML = json.data.map(t => `<option value="${escapeHtml(t.name)}">${escapeHtml(t.name)}</option>`).join('');
      if (current) tenantSel.value = current;
    }
  } else {
    tenantSel.style.display = 'none';
  }
  loadLogs();
}

function viewTenantLog(name) {
  switchTab('logs', document.querySelectorAll('.nav-item')[3]);
  document.getElementById('log-source').value = 'tenant';
  onLogSourceChange().then(() => {
    document.getElementById('log-tenant').value = name;
    loadLogs();
  });
}

async function loadLogs() {
  const source = document.getElementById('log-source').value;
  const tenant = document.getElementById('log-tenant').value;
  const q = document.getElementById('log-filter').value;
  const box = document.getElementById('log-box');
  try {
    const res = await fetch(`?api=logs&source=${encodeURIComponent(source)}&tenant=${encodeURIComponent(tenant)}&q=${encodeURIComponent(q)}`);
    const json = await res.json();
    if (!json.ok) {
      box.textContent = 'Error loading logs: ' + json.error;
      return;
    }
    if (json.data.length === 0) {
      box.innerHTML = '<div style="color:var(--muted)">No log entries found.</div>';
      return;
    }
    if (source === 'admin') {
      box.innerHTML = json.data.map(l =>
        `<div class="log-line">[${escapeHtml(l.created_at)}] <strong>${escapeHtml(l.action)}</strong> (${escapeHtml(l.tenant_name)}): ${escapeHtml(l.details)}</div>`
      ).join('');
    } else {
      box.innerHTML = json.data.map(line =>
        `<div class="log-line">${escapeHtml(line)}</div>`
      ).join('');
    }
  } catch (e) {
    box.textContent = 'Failed to load logs.';
  }
}

function escapeHtml(str) {
  return String(str ?? '').replace(/[&<>"']/g, c => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
  }[c]));
}

async function changeAdminPassword() {
  const current_password = prompt('Enter current admin password:');
  if (current_password === null) return;
  const new_password = prompt('Enter new admin password (min 4 chars):');
  if (new_password === null) return;
  const res = await fetch('?api=change_admin_password', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({ current_password, new_password })
  });
  const json = await res.json();
  showToast(json.ok ? json.message : ('Error: ' + json.error));
}

async function doLogout() {
  await fetch('?api=logout');
  location.reload();
}

// Initial load & real-time polling every 2 seconds
loadMetrics();
loadTenants();
setInterval(loadMetrics, 2000);
</script>
</body>
</html>
