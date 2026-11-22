'use strict';

/**
 * WebVM terminal server.
 * 
 * Serves the xterm.js frontend, a small JSON API and a WebSocket endpoint
 * that bridges the browser to a real PTY inside the container.
 * Supports multi-tenant isolation via ?tenant= query parameter or tenant.localhost subdomain.
 */

const http = require('node:http');
const fs = require('node:fs');
const fsp = require('node:fs/promises');
const path = require('node:path');
const os = require('node:os');
const crypto = require('node:crypto');
const { execFileSync } = require('node:child_process');
const { WebSocketServer, WebSocket } = require('ws');
const pty = require('node-pty');

const VERSION = '2.1.0';
const tenantSessions = new Map();
const PUBLIC_DIR = path.join(__dirname, 'public');
const MAX_INPUT_BYTES = 1024 * 1024;
const MAX_BUFFERED_BYTES = 2 * 1024 * 1024;

function log(...args) {
  console.log(new Date().toISOString(), '[webvm]', ...args);
}

function envInt(name, fallback) {
  const parsed = Number.parseInt(process.env[name] ?? '', 10);
  return Number.isFinite(parsed) && parsed > 0 ? parsed : fallback;
}

function clamp(value, min, max, fallback) {
  const parsed = Number.parseInt(value, 10);
  if (!Number.isFinite(parsed)) return fallback;
  return Math.min(max, Math.max(min, parsed));
}

// ============================================================
// Multi-tenant system
// ============================================================
// Tenant identification via ?tenant= query parameter OR tenant.localhost subdomain
// Per-tenant config stored in /etc/webvm/tenants/<name>.conf
// Each .conf file contains KEY=VALUE pairs for environment variables

// Initial tenant state
const config = {
  bind: process.env.WEBVM_BIND || '0.0.0.0',
  port: envInt('WEBVM_LISTEN_PORT', envInt('WEBVM_TERMINAL_PORT', 7681)),
  publicPort: envInt('WEBVM_TERMINAL_PORT', 7681),
  httpPort: envInt('WEBVM_HTTP_PORT', 8080),
  shell: process.env.WEBVM_SHELL || '/bin/bash',
  cwd: process.env.WEBVM_CWD || '/var/www/localhost/htdocs',
  user: process.env.WEBVM_USER || 'admin',
  password: process.env.WEBVM_PASSWORD || '',
  maxSessions: envInt('WEBVM_MAX_SESSIONS', 8),
  startedAt: Date.now(),
  tenant: null,
  tenantEnv: {},
  tenants: {},      // loaded {name: {KEY: VALUE}}
  blockedTenants: new Set(), // set of blocked tenant names
};

// Load all tenant configurations from /etc/webvm/tenants/ on startup
function loadTenants() {
  const tenantDir = '/etc/webvm/tenants';
  try {
    const entries = fs.readdirSync(tenantDir);
    for (const entry of entries) {
      if (!entry.endsWith('.conf')) continue;
      const tenantName = entry.replace('.conf', '');
      const configPath = path.join(tenantDir, entry);
      const content = fs.readFileSync(configPath, 'utf8');
      const tenantEnv = {};
      for (const line of content.split('\n')) {
        const eq = line.indexOf('=');
        if (eq > 0) {
          const key = line.substring(0, eq).trim();
          const value = line.substring(eq + 1).trim();
          tenantEnv[key] = value;
        }
      }
      config.tenants[tenantName] = tenantEnv;
      // Check if tenant is blocked
      if (content.includes('BLOCKED=1')) {
        config.blockedTenants.add(tenantName);
      }
    }
  } catch (e) {
    log('Error loading tenants:', e.message);
  }
}

// Initialize tenant context from request
function initTenantContext(req) {
  loadTenants();
  // Get tenant from ?tenant= query parameter
  const url = new URL(req.url || '/', `http://${req.headers.host || 'localhost'}`);
  const tenantParam = url.searchParams.get('tenant');
  
  // Also check Host header for subdomain-style: tenant.localhost
  let tenantName = tenantParam;
  if (!tenantName) {
    const host = req ? req.headers.host : '';
    const hostname = host.split(':')[0].toLowerCase();
    // Simple subdomain check: tenant.localhost
    if (hostname.includes('.') && hostname.endsWith('.localhost') && hostname.split('.').length === 2) {
      tenantName = hostname.replace('.localhost', '');
    }
  }
  
  // Check if tenant is blocked
  if (config.blockedTenants.has(tenantName)) {
    config.tenant = { name: tenantName, blocked: true };
    config.tenantEnv = {};
    return;
  }
  
  config.tenant = { name: tenantName, blocked: false };
  config.tenantEnv = config.tenants[tenantName] || {};
}

// Load tenants on server start
loadTenants();

// ------------------------
// HTTP basic auth (optional)

// Check if a tenant is blocked
function isTenantBlocked(tenantName) {
  return config.blockedTenants.has(tenantName || '');
}

// Get tenant info for admin panel
function getTenantInfo() {
  return {
    tenant: config.tenant,
    tenantEnvKeys: config.tenantEnv ? Object.keys(config.tenantEnv) : [],
    availableTenants: Object.keys(config.tenants),
    blockedTenants: Array.from(config.blockedTenants),
  };
}

// Make getTenantInfo available globally (will be used by PHP admin panel)
global.getTenantInfo = getTenantInfo;

// ------------------------
// HTTP basic auth (optional)

// ==========================
// HTTP server setup
// ==========================

function sendJson(res, status, payload) {
  const body = JSON.stringify(payload);
  res.writeHead(status, {
    'Content-Type': 'application/json; charset=utf-8',
    'Content-Length': Buffer.byteLength(body),
    'Cache-Control': 'no-store',
  });
  res.end(body);
}

function readTextFile(file) {
  try {
    return fs.readFileSync(file, 'utf8').trim();
  } catch {
    return null;
  }
}

function runCommand(command, args) {
  try {
    return execFileSync(command, args, {
      encoding: 'utf8',
      timeout: 2500,
      stdio: ['ignore', 'pipe', 'ignore'],
    }).trim();
  } catch {
    return null;
  }
}

// ---------------------------------------------------------------------------
// HTTP server
// ---------------------------------------------------------------------------

const server = http.createServer(async (req, res) => {
  // Initialize tenant context for this request
  initTenantContext(req);
  
  const method = req.method || 'GET';

  try {
    const url = new URL(req.url || '/', `http://${req.headers.host || 'localhost'}`);

    if (method !== 'GET' && method !== 'HEAD' && !(method === 'POST' && url.pathname === '/api/tenant-login')) {
      res.writeHead(405, {
        'Allow': 'GET, HEAD',
        'Content-Type': 'text/plain; charset=utf-8',
      });
      res.end('Method not allowed.\n');
      return;
    }

    // Tenant-aware health check
    if (url.pathname === '/healthz') {
      sendJson(res, 200, {
        status: 'ok',
        version: VERSION,
        uptime: Math.round((Date.now() - config.startedAt) / 1000),
        sessions: 0, // will be set by wss logic
        maxSessions: config.maxSessions,
        tenant: config.tenant ? config.tenant.name : null,
        tenantBlocked: config.tenant ? config.tenant.blocked : false,
      });
      return;
    }

    // Tenant-aware config.js
    if (url.pathname === '/config.js') {
      const body =
        'window.WEBVM_CONFIG = Object.freeze(' +
        JSON.stringify({
          httpPort: config.httpPort,
          terminalPort: config.publicPort,
          hostname: 'webvm',
          alpine: '3.20.10',
          auth: Boolean(config.password),
          version: VERSION,
          tenant: config.tenant ? config.tenant.name : null,
          tenantEnv: config.tenantEnv,
        }) +
        ');\n';
      res.writeHead(200, {
        'Content-Type': 'text/javascript; charset=utf-8',
        'Content-Length': Buffer.byteLength(body),
        'Cache-Control': 'no-store',
      });
      res.end(body);
      return;
    }

    // Real-time CPU & memory metrics
    if (url.pathname === '/api/metrics') {
      const load1 = os.loadavg()[0] || 0;
      const cpus = os.cpus().length || 1;
      const cpuPct = Math.min(100, Math.round((load1 / cpus) * 100));
      const totalMem = os.totalmem() || 1;
      let availMem = os.freemem();
      const meminfo = readTextFile('/proc/meminfo') || '';
      const m = /MemAvailable:\s+(\d+)\s+kB/.exec(meminfo);
      if (m) availMem = Number.parseInt(m[1], 10) * 1024;
      const memPct = Math.min(100, Math.round(((totalMem - availMem) / totalMem) * 100));
      sendJson(res, 200, { cpuPct, memPct });
      return;
    }

    // Tenant-aware API info
    if (url.pathname === '/api/info') {
      const apacheOut = runCommand('httpd', ['-v']) || '';
      const apacheMatch = /Apache\/([^\s]+)/.exec(apacheOut);
      sendJson(res, 200, {
        version: VERSION,
        hostname: os.hostname(),
        host: os.hostname(),
        alpine: readTextFile('/etc/alpine-release') || 'unknown',
        php: runCommand('php', ['-r', 'echo PHP_VERSION;']) || 'unknown',
        apache: apacheMatch ? apacheMatch[1] : 'unknown',
        python: readTextFile('/etc/webvm/python') || 'unknown',
        go: readTextFile('/etc/webvm/go') || 'unknown',
        cpp: readTextFile('/etc/webvm/cpp') || 'unknown',
        r: readTextFile('/etc/webvm/r') || 'unknown',
        node: process.version.replace(/^v/, ''),
        sqlite: readTextFile('/etc/webvm/sqlite') || 'unknown',
        shell: config.shell,
        tenant: config.tenant ? config.tenant.name : null,
        tenantBlocked: config.tenant ? config.tenant.blocked : false,
      });
      return;
    }

    // If tenant is blocked and not the admin, show blocked page
    if (config.tenant && config.tenant.blocked && url.pathname !== '/admin') {
      res.writeHead(403, {
        'Content-Type': 'text/plain; charset=utf-8',
      });
      res.end('Tenant environment is blocked. Contact administrator.\n');
      return;
    }

    if (url.pathname === '/api/tenant-login' && method === 'POST') {
      let body = '';
      req.on('data', chunk => { body += chunk; });
      req.on('end', () => {
        try {
          const { username, password } = JSON.parse(body || '{}');
          const tName = (config.tenant && config.tenant.name) ? config.tenant.name : (username || '');
          const tPass = tName && config.tenants[tName] ? (config.tenants[tName].TENANT_PASSWORD || '') : '';
          if (tName && tPass && username === tName && password === tPass) {
            const token = crypto.randomBytes(24).toString('hex');
            tenantSessions.set(token, tName);
            res.writeHead(200, {
              'Content-Type': 'application/json',
              'Set-Cookie': `zunox_tenant_session=${token}; Path=/; HttpOnly; SameSite=Lax`,
            });
            res.end(JSON.stringify({ ok: true, redirect: `/?tenant=${encodeURIComponent(tName)}` }));
          } else {
            sendJson(res, 401, { ok: false, error: 'Invalid tenant username or password.' });
          }
        } catch {
          sendJson(res, 400, { ok: false, error: 'Invalid request.' });
        }
      });
      return;
    }

    if (!isAuthorized(req)) {
      const accept = req.headers.accept || '';
      if (accept.includes('text/html')) {
        const tName = (config.tenant && config.tenant.name) ? config.tenant.name.replace(/[<>&"']/g, '') : '';
        res.writeHead(401, { 'Content-Type': 'text/html; charset=utf-8' });
        res.end(`<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>ZunoX Tenant Login</title><style>body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#0f172a;color:#f8fafc;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}.card{background:#1e293b;border:1px solid #334155;border-radius:12px;padding:28px;width:100%;max-width:360px}h1{font-size:1.3rem;margin:0 0 6px}p{font-size:.85rem;color:#94a3b8;margin:0 0 20px}label{display:block;font-size:.8rem;color:#94a3b8;margin-bottom:5px}input{width:100%;box-sizing:border-box;padding:9px 12px;border-radius:8px;border:1px solid #334155;background:#0f172a;color:#f8fafc;margin-bottom:14px;font-size:.9rem}button{width:100%;padding:10px;border:none;border-radius:8px;background:#3b82f6;color:#fff;font-weight:600;cursor:pointer;font-size:.92rem}button:hover{background:#2563eb}.err{color:#ef4444;font-size:.84rem;margin-bottom:12px;display:none}</style></head><body><div class="card"><h1>ZunoX Terminal</h1><p>${tName ? `Sign in to tenant <strong>${tName}</strong>` : 'Sign in to your tenant environment'}</p><div class="err" id="err"></div><form onsubmit="doLogin(event)"><label>Tenant Username</label><input type="text" id="u" value="${tName}" required ${tName ? '' : 'autofocus'}><label>Password</label><input type="password" id="p" required ${tName ? 'autofocus' : ''}><button type="submit">Sign In</button></form></div><script>async function doLogin(e){e.preventDefault();const u=document.getElementById('u').value.trim();const r=await fetch('/api/tenant-login',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({username:u,password:document.getElementById('p').value})});const j=await r.json();if(j.ok){location.href=j.redirect||('/?tenant='+encodeURIComponent(u))}else{const el=document.getElementById('err');el.textContent=j.error||'Login failed';el.style.display='block'}}</script></body></html>`);
        return;
      }
      res.writeHead(401, {
        'WWW-Authenticate': 'Basic realm="ZunoX Tenant Login", charset="UTF-8"',
        'Content-Type': 'text/plain; charset=utf-8',
      });
      res.end('Authentication required.\n');
      return;
    }

    // Serve static files (PHP site)
    await serveStatic(res, url.pathname, method);
  } catch (err) {
    log('request error:', err.message);
    if (!res.headersSent) {
      res.writeHead(500, { 'Content-Type': 'text/plain; charset=utf-8' });
    }
    res.end('Internal server error.\n');
  }
});

// ==========================
// WebSocket terminal sessions
// ==========================

const sessions = new Set();
const wss = new WebSocketServer({
  noServer: true,
  clientTracking: true,
  perMessageDeflate: false,
  maxPayload: MAX_INPUT_BYTES,
});

function send(socket, payload) {
  if (socket.readyState === WebSocket.OPEN) {
    socket.send(JSON.stringify(payload));
  }
}

server.on('upgrade', (req, socket, head) => {
  // Initialize tenant context for WebSocket
  initTenantContext(req);
  
  let pathname;
  try {
    pathname = new URL(req.url || '/', 'http://localhost').pathname;
  } catch {
    socket.destroy();
    return;
  }

  if (pathname !== '/ws') {
    socket.write('HTTP/1.1 404 Not Found\r\nConnection: close\r\n\r\n');
    socket.destroy();
    return;
  }

  if (!isAuthorized(req)) {
    socket.write(
      'HTTP/1.1 401 Unauthorized\r\n' +
        'WWW-Authenticate: Basic realm="WebVM", charset="UTF-8"\r\n' +
        'Connection: close\r\n\r\n',
    );
    socket.destroy();
    return;
  }

  wss.handleUpgrade(req, socket, head, (ws) => {
    wss.emit('connection', ws, req);
  });
});

wss.on('connection', (ws, req) => {
  const remote = req.socket.remoteAddress || 'unknown';
  
  // Use tenant-aware environment
  const tenantEnv = { ...process.env, ...config.tenantEnv };
  
  const url = new URL(req.url || '/ws', 'http://localhost');
  const requestedCols = clamp(url.searchParams.get('cols'), 20, 400, 100);
  const requestedRows = clamp(url.searchParams.get('rows'), 5, 200, 30);

  if (sessions.size >= config.maxSessions) {
    log(`rejected ${remote}: session limit reached (${config.maxSessions})`);
    send(ws, {
      type: 'error',
      message: `The terminal session limit (${config.maxSessions}) has been reached. Try again shortly.`,
    });
    ws.close(1013, 'Session limit reached');
    return;
  }

  const shell = tenantEnv.WEBVM_SHELL || '/bin/bash';
  const cwd = tenantEnv.WEBVM_CWD || (config.tenant && config.tenant.name ? `/var/www/localhost/htdocs/tenants/${config.tenant.name}` : '/var/www/localhost/htdocs');
  try {
    fs.mkdirSync(cwd, { recursive: true });
  } catch {}
  const shellArgs = /(?:^|\/)(?:ba|z|k)sh$/.test(shell) ? ['--login'] : [];
  if (config.tenant && config.tenant.name && !tenantEnv.WEBVM_CWD) {
    tenantEnv.TENANT_NAME = config.tenant.name;
    const tenantDocRoot = `/var/www/tenants/${config.tenant.name}`;
    if (fs.existsSync(tenantDocRoot)) {
      cwd = tenantDocRoot;
    }
  }

  let terminal;
  try {
    terminal = pty.spawn(shell, shellArgs, {
      name: 'xterm-256color',
      cols: requestedCols,
      rows: requestedRows,
      cwd,
      env: tenantEnv,
    });
  } catch (err) {
    log(`failed to spawn shell for ${remote}:`, err.message);
    send(ws, { type: 'error', message: `Unable to start a shell: ${err.message}` });
    ws.close(1011, 'Shell failed');
    return;
  }

  const sessionId = crypto.randomUUID().slice(0, 8);
  sessions.add(sessionId);
  ws.isAlive = true;
  let finished = false;
  let paused = false;

  log(`session ${sessionId} opened from ${remote} (${shell}, ${requestedCols}x${requestedRows})`);

  send(ws, { type: 'ready', sessionId });

  function resumeWhenDrained() {
    if (!paused) return;
    if (ws.readyState !== WebSocket.OPEN) return;
    if (ws.bufferedAmount > MAX_BUFFERED_BYTES / 2) {
      setTimeout(resumeWhenDrained, 50).unref();
      return;
    }
    paused = false;
    terminal.resume();
  }

  terminal.onData((data) => {
    if (ws.readyState !== WebSocket.OPEN) return;

    if (!paused && ws.bufferedAmount > MAX_BUFFERED_BYTES) {
      paused = true;
      terminal.pause();
      setTimeout(resumeWhenDrained, 50).unref();
    }

    ws.send(JSON.stringify({ type: 'data', data }));
  });

  terminal.onExit(({ exitCode, signal }) => {
    if (finished) return;
    finished = true;
    log(`session ${sessionId} exited (code ${exitCode}, signal ${signal})`);
    send(ws, { type: 'exit', exitCode, signal });
    setTimeout(() => {
      try {
        ws.close(1000, 'Shell exited');
      } catch {
        /* already closed */
      }
    }, 40);
  });

  ws.on('pong', () => {
    ws.isAlive = true;
  });

  ws.on('message', (raw, isBinary) => {
    if (isBinary) return;

    let message;
    try {
      message = JSON.parse(raw.toString('utf8'));
    } catch {
      return;
    }

    if (!message || typeof message !== 'object') return;

    if (message.type === 'input' && typeof message.data === 'string') {
      if (message.data.length > MAX_INPUT_BYTES) return;
      terminal.write(message.data);
      return;
    }

    if (message.type === 'resize') {
      const cols = clamp(message.cols, 20, 400, 100);
      const rows = clamp(message.rows, 5, 200, 30);
      try {
        terminal.resize(cols, rows);
      } catch {
        /* shell already gone */
      }
      return;
    }

    if (message.type === 'ping') {
      send(ws, { type: 'pong', at: Date.now() });
    }
  });

  ws.on('close', () => {
    sessions.delete(sessionId);
    log(`session ${sessionId} closed`);
    if (!finished) {
      finished = true;
      try {
        terminal.kill();
      } catch {
        /* already gone */
      }
    }
  });

  ws.on('error', (err) => {
    log(`session ${sessionId} websocket error:`, err.message);
  });
});

// ==========================
// Startup / shutdown
// ==========================

const heartbeat = setInterval(() => {
  for (const ws of wss.clients) {
    if (ws.isAlive === false) {
      ws.terminate();
      continue;
    }
    ws.isAlive = false;
    try {
      ws.ping();
    } catch {
      /* ignore */
    }
  }
}, 30_000);
heartbeat.unref();

server.listen(config.port, config.bind, () => {
  log(`terminal listening on http://${config.bind}:${config.port}`);
});

// ==========================
// Serve static files
// ==========================

function serveStatic(res, pathname, method) {
  const relPath = pathname === '/' ? 'index.html' : pathname.replace(/^\//, '');
  const filePath = path.join(PUBLIC_DIR, relPath);
  
  if (method === 'HEAD') {
    try {
      fs.accessSync(filePath);
      res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
      res.end();
    } catch {
      res.writeHead(404, { 'Content-Type': 'text/plain; charset=utf-8' });
      res.end('Not found.\n');
    }
    return;
  }

  try {
    const stat = fs.statSync(filePath);
    if (!stat.isFile()) {
      res.writeHead(404, { 'Content-Type': 'text/plain; charset=utf-8' });
      res.end('Not found.\n');
      return;
    }
    const mimeType = relPath.endsWith('.html') ? 'text/html; charset=utf-8' :
                     relPath.endsWith('.js') ? 'text/javascript; charset=utf-8' :
                     relPath.endsWith('.css') ? 'text/css; charset=utf-8' :
                     relPath.endsWith('.svg') ? 'image/svg+xml' :
                     'text/plain; charset=utf-8';
    const body = fs.readFileSync(filePath, 'utf8');
    res.writeHead(200, { 'Content-Type': mimeType, 'Content-Length': Buffer.byteLength(body) });
    res.end(body);
  } catch {
    res.writeHead(404, { 'Content-Type': 'text/plain; charset=utf-8' });
    res.end('Not found.\n');
  }
}

// ==========================
// Helper functions
// ==========================

function isAuthorized(req) {
  const tenantName = config.tenant ? config.tenant.name : null;
  const tenantPassword = tenantName && config.tenants[tenantName]
    ? (config.tenants[tenantName].TENANT_PASSWORD || '')
    : '';

  const requiredPassword = tenantPassword || config.password;
  if (!requiredPassword) {
    // Require tenant login on root HTML page when no tenant is specified
    const accept = req.headers.accept || '';
    if (!tenantName && accept.includes('text/html')) return false;
    return true;
  }

  if (tenantPassword) {
    const cookies = req.headers.cookie || '';
    const cm = /zunox_tenant_session=([A-Za-z0-9]+)/.exec(cookies);
    if (cm && tenantSessions.get(cm[1]) === tenantName) {
      return true;
    }
  }

  const header = req.headers.authorization || '';
  const match = /^Basic\s+([A-Za-z0-9+/=]+)\s*$/i.exec(header);
  if (!match) return false;

  let decoded;
  try {
    decoded = Buffer.from(match[1], 'base64').toString('utf8');
  } catch {
    return false;
  }

  const separator = decoded.indexOf(':');
  if (separator < 0) return false;

  const user = decoded.slice(0, separator);
  const pass = decoded.slice(separator + 1);

  if (tenantPassword) {
    return user === tenantName && pass === tenantPassword;
  }
  return user === config.user && pass === config.password;
}

// ============================================================
// Admin PHP panel will be placed at /admin/ in the site directory
// ============================================================

// Ensure tenant directories exist
const tenantDir = '/etc/webvm/tenants';
try {
  if (!fs.existsSync(tenantDir)) {
    fs.mkdirSync(tenantDir, { recursive: true });
  }
} catch (e) {
  log('Error creating tenant directory:', e.message);
}