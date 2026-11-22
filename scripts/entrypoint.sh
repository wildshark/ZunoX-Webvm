#!/bin/sh
set -eu

mkdir -p /run/apache2 /run/supervisor /var/log/supervisor /var/log/apache2

# Fail fast on a broken Apache configuration instead of looping in supervisor.
if ! httpd -t >/tmp/webvm-httpd-check.log 2>&1; then
  echo "[webvm] Apache configuration test failed:" >&2
  cat /tmp/webvm-httpd-check.log >&2 || true
  exit 1
fi

echo "[webvm] starting Alpine $(cat /etc/alpine-release 2>/dev/null || echo unknown) container"
echo "[webvm] PHP site     http://localhost:${WEBVM_HTTP_PORT:-8080}/"
echo "[webvm] terminal     http://localhost:${WEBVM_TERMINAL_PORT:-7681}/"

if [ -z "${WEBVM_PASSWORD:-}" ]; then
  echo "[webvm] WARNING: WEBVM_PASSWORD is empty. The root shell is unauthenticated." >&2
  echo "[webvm]          Keep WEBVM_BIND_ADDR=127.0.0.1 or set a password." >&2
fi

exec "$@"
