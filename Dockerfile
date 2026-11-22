FROM alpine:3.20

LABEL org.opencontainers.image.title="WebVM" \
      org.opencontainers.image.description="Browser terminal into an Alpine Linux container with Apache, PHP, Python, Go, C++ and SQLite" \
      org.opencontainers.image.version="2.1.0"

ENV NODE_ENV=production \
    WEBVM_TERMINAL_PORT=7681 \
    WEBVM_SHELL=/bin/bash \
    WEBVM_CWD=/var/www/localhost/htdocs \
    LANG=C.UTF-8

RUN apk add --no-cache \
      apache2 \
      bash \
      build-base \
      ca-certificates \
      curl \
      git \
      go \
      nodejs \
      npm \
      php83 \
      php83-apache2 \
      php83-curl \
      php83-json \
      php83-mbstring \
      php83-openssl \
      php83-pdo_sqlite \
      php83-session \
      php83-sqlite3 \
      py3-pip \
      python3 \
      R \
      ripgrep \
      sqlite \
      supervisor \
      tini \
      tzdata \
    && mkdir -p /etc/supervisor.d /run/apache2 /var/log/supervisor \
                /var/www/localhost/htdocs /etc/webvm /root/go /etc/webvm/tenants \
    && python3 --version | awk '{print $2}' > /etc/webvm/python \
    && sqlite3 --version | awk '{print $1}' > /etc/webvm/sqlite \
    && go version | awk '{print $3}' | sed 's/^go//' > /etc/webvm/go \
    && g++ -dumpversion > /etc/webvm/cpp \
    && R --version | head -n 1 | awk '{print $3}' > /etc/webvm/r \
    && sqlite3 /etc/webvm/tenants.db "CREATE TABLE IF NOT EXISTS tenants (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT UNIQUE, hostname TEXT UNIQUE, environment TEXT DEFAULT 'default', is_blocked INTEGER DEFAULT 0, created_at DATETIME DEFAULT CURRENT_TIMESTAMP);" \
    && sqlite3 /etc/webvm/tenants.db "CREATE TABLE IF NOT EXISTS tenant_env (tenant_id INTEGER, key TEXT, value TEXT, PRIMARY KEY (tenant_id, key), FOREIGN KEY (tenant_id) REFERENCES tenants(id));" \
    && sqlite3 /etc/webvm/tenants.db "CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT UNIQUE, password_hash TEXT, role TEXT DEFAULT 'admin', created_at DATETIME DEFAULT CURRENT_TIMESTAMP);"

WORKDIR /app

COPY server/package*.json ./
RUN apk add --no-cache --virtual .build-deps build-base linux-headers nodejs-dev python3 \
    && npm_config_nodedir=/usr npm ci --omit=dev --no-audit --no-fund \
    && apk del .build-deps \
    && npm cache clean --force

COPY server/ ./

# Bundle the xterm.js browser assets so the terminal works without CDNs.
RUN mkdir -p public/vendor/xterm public/vendor/addon-fit public/vendor/addon-web-links \
    && cp node_modules/@xterm/xterm/lib/xterm.js public/vendor/xterm/xterm.js \
    && cp node_modules/@xterm/xterm/css/xterm.css public/vendor/xterm/xterm.css \
    && cp node_modules/@xterm/addon-fit/lib/addon-fit.js public/vendor/addon-fit/addon-fit.js \
    && cp node_modules/@xterm/addon-web-links/lib/addon-web-links.js public/vendor/addon-web-links/addon-web-links.js

COPY apache/webvm.conf /etc/apache2/conf.d/webvm.conf
COPY php/webvm.ini /etc/php83/conf.d/99-webvm.ini
COPY supervisord.conf /etc/supervisor.d/webvm.ini
COPY scripts/entrypoint.sh /usr/local/bin/entrypoint.sh
COPY scripts/webvm /usr/local/bin/webvm
COPY scripts/profile.sh /etc/profile.d/webvm.sh
COPY etc/webvm/tenants/ /etc/webvm/tenants/
COPY site/ /var/www/localhost/htdocs/

RUN chmod +x /usr/local/bin/entrypoint.sh /usr/local/bin/webvm \
    && mkdir -p /etc/apache2/conf.d/tenants /var/www/tenants \
    && chown -R apache:apache /etc/webvm /etc/apache2/conf.d/tenants /var/www/tenants \
    && rm -f /var/www/localhost/htdocs/index.html

EXPOSE 80 8082 7681

HEALTHCHECK --interval=30s --timeout=5s --start-period=15s --retries=3 \
  CMD curl -fsS http://127.0.0.1:7681/healthz >/dev/null || exit 1

ENTRYPOINT ["/sbin/tini", "--", "/usr/local/bin/entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisord.conf", "-n"]
