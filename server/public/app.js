'use strict';

(() => {
  const config = window.WEBVM_CONFIG || {};
  const terminalElement = document.getElementById('terminal');
  const frame = document.getElementById('terminal-frame');
  const overlay = document.getElementById('overlay');
  const overlayText = document.getElementById('overlay-text');
  const overlayHint = document.getElementById('overlay-hint');
  const overlayAction = document.getElementById('overlay-action');
  const statusPill = document.getElementById('status-pill');
  const statusText = document.getElementById('status-text');
  const statConnection = document.getElementById('stat-connection');
  const statHost = document.getElementById('stat-host');
  const statShell = document.getElementById('stat-shell');
  const statSize = document.getElementById('stat-size');
  const statClock = document.getElementById('stat-clock');
  const chromeTitle = document.getElementById('chrome-title');
  const siteLink = document.getElementById('site-link');
  const btnClear = document.getElementById('btn-clear');
  const btnReconnect = document.getElementById('btn-reconnect');
  const btnFullscreen = document.getElementById('btn-fullscreen');

  if (!window.Terminal || !window.FitAddon) {
    overlayText.textContent = 'Terminal assets failed to load.';
    overlayHint.textContent = 'Rebuild the container image';
    return;
  }

  const isSmallScreen = window.matchMedia('(max-width: 560px)').matches;

  const terminal = new Terminal({
    cursorBlink: true,
    cursorStyle: 'bar',
    fontFamily:
      '"Cascadia Code", "JetBrains Mono", ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace',
    fontSize: isSmallScreen ? 13 : 14,
    fontWeight: '400',
    fontWeightBold: '600',
    lineHeight: 1.22,
    letterSpacing: 0,
    scrollback: 10000,
    allowProposedApi: true,
    macOptionIsMeta: true,
    rightClickSelectsWord: true,
    theme: {
      background: '#05080f',
      foreground: '#d7e3f4',
      cursor: '#38e0c8',
      cursorAccent: '#05080f',
      selectionBackground: 'rgba(94, 168, 255, 0.28)',
      selectionForeground: '#ffffff',
      black: '#0b1220',
      red: '#ff6b81',
      green: '#38d39f',
      yellow: '#ffc857',
      blue: '#5ea8ff',
      magenta: '#c792ea',
      cyan: '#38e0c8',
      white: '#d7e3f4',
      brightBlack: '#5d6c83',
      brightRed: '#ff8a9b',
      brightGreen: '#5cf0c9',
      brightYellow: '#ffd98a',
      brightBlue: '#8bc2ff',
      brightMagenta: '#ddb8ff',
      brightCyan: '#7df0e1',
      brightWhite: '#f4f8ff',
    },
  });

  const fitAddon = new window.FitAddon.FitAddon();
  terminal.loadAddon(fitAddon);

  if (window.WebLinksAddon) {
    terminal.loadAddon(
      new window.WebLinksAddon.WebLinksAddon((event, uri) => {
        event.preventDefault();
        window.open(uri, '_blank', 'noopener,noreferrer');
      }),
    );
  }

  terminal.open(terminalElement);

  // ---------------------------------------------------------------------
  // State
  // ---------------------------------------------------------------------

  let socket = null;
  let reconnectTimer = null;
  let reconnectAttempts = 0;
  let ready = false;
  let sessionEnded = false;
  let hasConnected = false;

  // ---------------------------------------------------------------------
  // UI helpers
  // ---------------------------------------------------------------------

  const stateLabels = {
    connecting: 'Connecting',
    online: 'Connected',
    offline: 'Offline',
  };

  function setState(state, label) {
    statusPill.dataset.state = state;
    statusText.textContent = label || stateLabels[state] || state;
    statConnection.textContent =
      state === 'online' ? 'connected' : state === 'connecting' ? 'connecting' : 'offline';
    statConnection.classList.toggle('is-online', state === 'online');
    statConnection.classList.toggle('is-offline', state === 'offline');
  }

  function showOverlay(text, hint, showAction) {
    overlay.hidden = false;
    overlayText.textContent = text;
    if (hint) overlayHint.textContent = hint;
    overlay.classList.toggle('is-idle', Boolean(showAction));
    overlayAction.hidden = !showAction;
  }

  function hideOverlay() {
    overlay.hidden = true;
  }

  overlayAction.addEventListener('click', () => {
    hideOverlay();
    reconnectNow();
  });

  function updateSizeLabel() {
    statSize.textContent = `${terminal.cols} × ${terminal.rows}`;
  }

  function setIsFullscreenState() {
    const active = document.fullscreenElement === frame;
    btnFullscreen.setAttribute('aria-pressed', String(active));
  }

  function fitTerminal() {
    try {
      const dimensions = fitAddon.proposeDimensions();
      if (!dimensions || !Number.isFinite(dimensions.cols) || dimensions.cols < 2) return;
      fitAddon.fit();
      updateSizeLabel();
    } catch {
      /* terminal not visible yet */
    }
  }

  function send(message) {
    if (socket && socket.readyState === WebSocket.OPEN) {
      socket.send(JSON.stringify(message));
    }
  }

  function syncSize() {
    if (ready) {
      send({ type: 'resize', cols: terminal.cols, rows: terminal.rows });
    }
  }

  // ---------------------------------------------------------------------
  // Banner
  // ---------------------------------------------------------------------

  async function pollMetrics() {
    try {
      const res = await fetch('/api/metrics', { cache: 'no-store' });
      if (!res.ok) return;
      const { cpuPct, memPct } = await res.json();
      const cpuEl = document.getElementById('stat-cpu');
      const cpuBar = document.getElementById('cpu-bar');
      const memEl = document.getElementById('stat-mem');
      const memBar = document.getElementById('mem-bar');
      if (cpuEl) cpuEl.textContent = `cpu ${cpuPct}%`;
      if (cpuBar) {
        cpuBar.style.width = `${cpuPct}%`;
        cpuBar.style.background = cpuPct > 80 ? '#ef4444' : cpuPct > 50 ? '#f59e0b' : '#38bdf8';
      }
      if (memEl) memEl.textContent = `mem ${memPct}%`;
      if (memBar) {
        memBar.style.width = `${memPct}%`;
        memBar.style.background = memPct > 85 ? '#ef4444' : memPct > 65 ? '#f59e0b' : '#10b981';
      }
    } catch {}
  }

  function printBanner() {
    const lines = [
      '',
      ` \x1b[38;5;39m╭──────────────────────────────────────────────────────╮\x1b[0m`,
      ` \x1b[38;5;39m│\x1b[0m  \x1b[1;37mWelcome to ZunoX v2.1.0\x1b[0m  \x1b[38;5;244m(Alpine Linux + Toolchains)\x1b[0m \x1b[38;5;39m│\x1b[0m`,
      ` \x1b[38;5;39m╰──────────────────────────────────────────────────────╯\x1b[0m`,
      ` \x1b[38;5;245mType \x1b[38;5;222mwebvm\x1b[38;5;245m for helpers · Ctrl+L clears\x1b[0m`,
      ` \x1b[38;5;240m© 2026 ZunoX. All rights reserved.\x1b[0m`,
      '',
    ];

    terminal.write(lines.join('\r\n') + '\r\n');
  }

  // ---------------------------------------------------------------------
  // WebSocket lifecycle
  // ---------------------------------------------------------------------

  function socketUrl() {
    const protocol = window.location.protocol === 'https:' ? 'wss:' : 'ws:';
    const cols = terminal.cols || 100;
    const rows = terminal.rows || 30;
    const tenant = new URLSearchParams(window.location.search).get('tenant');
    const tenantQuery = tenant ? `&tenant=${encodeURIComponent(tenant)}` : '';
    return `${protocol}//${window.location.host}/ws?cols=${cols}&rows=${rows}${tenantQuery}`;
  }

  function scheduleReconnect() {
    if (reconnectTimer) return;

    const delay = Math.min(8000, 750 * 2 ** reconnectAttempts);
    reconnectAttempts += 1;
    const seconds = Math.max(1, Math.round(delay / 1000));

    setState('offline', `Retry in ${seconds}s`);
    if (!hasConnected) {
      showOverlay('Waiting for the terminal service…', `Retrying in ${seconds}s`, false);
    }

    reconnectTimer = window.setTimeout(() => {
      reconnectTimer = null;
      connect();
    }, delay);
  }

  function handleMessage(event) {
    let message;
    try {
      message = JSON.parse(event.data);
    } catch {
      return;
    }

    if (!message || typeof message !== 'object') return;

    switch (message.type) {
      case 'ready': {
        ready = true;
        sessionEnded = false;
        hasConnected = true;
        reconnectAttempts = 0;
        setState('online');
        hideOverlay();

        terminal.clear();

        const info = message.info || {};
        if (info.hostname) {
          statHost.textContent = `host ${info.hostname}`;
          chromeTitle.textContent = `root@${info.hostname}`;
        }
        if (info.shell) statShell.textContent = `shell ${String(info.shell).split('/').pop()}`;

        printBanner(info);

        // The PTY was created with the dimensions from the URL, but the
        // browser may have been resized while the socket was opening.
        requestAnimationFrame(() => {
          fitTerminal();
          syncSize();
          terminal.focus();
        });
        break;
      }

      case 'data': {
        if (typeof message.data === 'string') terminal.write(message.data);
        break;
      }

      case 'exit': {
        ready = false;
        sessionEnded = true;
        const code = typeof message.exitCode === 'number' ? message.exitCode : '?';
        terminal.write(`\r\n\x1b[38;5;245m[session ended with exit code ${code}]\x1b[0m\r\n`);
        showOverlay('Session ended', 'Start a new shell to continue.', true);
        setState('offline', 'Session ended');
        break;
      }

      case 'error': {
        const text = typeof message.message === 'string' ? message.message : 'Terminal error.';
        terminal.write(`\r\n\x1b[38;5;203m${text}\x1b[0m\r\n`);
        if (!hasConnected) showOverlay(text, 'Retrying automatically', false);
        break;
      }

      default:
        break;
    }
  }

  function connect() {
    if (socket && (socket.readyState === WebSocket.CONNECTING || socket.readyState === WebSocket.OPEN)) {
      return;
    }

    if (reconnectTimer) {
      window.clearTimeout(reconnectTimer);
      reconnectTimer = null;
    }

    setState('connecting', hasConnected ? 'Attaching' : 'Connecting');

    let nextSocket;
    try {
      nextSocket = new WebSocket(socketUrl());
    } catch {
      scheduleReconnect();
      return;
    }

    socket = nextSocket;

    nextSocket.addEventListener('open', () => {
      if (socket !== nextSocket) return;
      setState('connecting', 'Attaching');
    });

    nextSocket.addEventListener('message', (event) => {
      if (socket !== nextSocket) return;
      handleMessage(event);
    });

    nextSocket.addEventListener('close', (event) => {
      if (socket !== nextSocket) return;
      socket = null;

      ready = false;

      if (sessionEnded) {
        setState('offline', 'Session ended');
        showOverlay('Session ended', 'Start a new shell to continue.', true);
        return;
      }

      setState('offline', 'Disconnected');
      if (hasConnected) {
        terminal.write(
          `\r\n\x1b[38;5;245m[connection lost${event.code ? ` (${event.code})` : ''} — reconnecting…]\x1b[0m\r\n`,
        );
      }

      scheduleReconnect();
    });

    nextSocket.addEventListener('error', () => {
      // The close event always follows; reconnection is handled there.
    });
  }

  function reconnectNow() {
    if (reconnectTimer) {
      window.clearTimeout(reconnectTimer);
      reconnectTimer = null;
    }

    reconnectAttempts = 0;
    sessionEnded = false;
    ready = false;

    if (socket) {
      const oldSocket = socket;
      socket = null;
      try {
        oldSocket.close();
      } catch {
        /* ignore */
      }
      // Give the old socket a moment to release its server-side session.
      window.setTimeout(connect, 120);
    } else {
      connect();
    }

    setState('connecting', 'Restarting');
    if (!hasConnected) showOverlay('Attaching to the container…', 'Alpine Linux · Apache · PHP', false);
  }

  // ---------------------------------------------------------------------
  // Input, layout and controls
  // ---------------------------------------------------------------------

  terminal.onData((data) => {
    if (ready) send({ type: 'input', data });
  });

  terminal.onResize(({ cols, rows }) => {
    updateSizeLabel();
    if (ready) send({ type: 'resize', cols, rows });
  });

  let resizeTimer = null;
  function scheduleFit() {
    if (resizeTimer) window.clearTimeout(resizeTimer);
    resizeTimer = window.setTimeout(() => {
      resizeTimer = null;
      fitTerminal();
    }, 80);
  }

  window.addEventListener('resize', scheduleFit);
  window.addEventListener('orientationchange', scheduleFit);

  if (window.visualViewport) {
    window.visualViewport.addEventListener('resize', scheduleFit);
  }

  if (window.ResizeObserver) {
    const observer = new ResizeObserver(scheduleFit);
    observer.observe(terminalElement);
  }

  btnClear.addEventListener('click', () => {
    terminal.clear();
    terminal.focus();
    if (ready) send({ type: 'input', data: '\x0c' });
  });

  btnReconnect.addEventListener('click', () => {
    hideOverlay();
    reconnectNow();
  });

  btnFullscreen.addEventListener('click', async () => {
    try {
      if (document.fullscreenElement) {
        await document.exitFullscreen();
      } else if (frame.requestFullscreen) {
        await frame.requestFullscreen();
      }
    } catch {
      /* fullscreen may be blocked by the browser */
    }
    window.setTimeout(fitTerminal, 180);
  });

  document.addEventListener('fullscreenchange', () => {
    setIsFullscreenState();
    window.setTimeout(fitTerminal, 120);
  });

  if (!frame.requestFullscreen) btnFullscreen.hidden = true;

  const sitePort = Number(config.httpPort);
  const portSuffix = Number.isFinite(sitePort) && sitePort !== 80 && sitePort !== 443 ? `:${sitePort}` : '';
  siteLink.href = `${window.location.protocol}//${window.location.hostname}${portSuffix}/`;

  statHost.textContent = `host ${config.hostname || '—'}`;
  chromeTitle.textContent = `root@${config.hostname || 'webvm'}`;

  if (config.auth) {
    statusPill.title = 'Terminal is protected with HTTP basic authentication';
  } else {
    statusPill.title = 'Terminal authentication is disabled';
  }

  function updateClock() {
    statClock.textContent = new Date().toLocaleTimeString([], {
      hour: '2-digit',
      minute: '2-digit',
      second: '2-digit',
      hour12: false,
    });
  }

  updateClock();
  window.setInterval(updateClock, 1000);

  // Initial layout happens before connecting so the PTY starts at the right size.
  requestAnimationFrame(() => {
    fitTerminal();
    updateSizeLabel();
    connect();
  });
  pollMetrics();
  setInterval(pollMetrics, 2000);
})();
