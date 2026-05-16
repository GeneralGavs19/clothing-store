#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")"

echo "==> Installing PHP dependencies"
if [ "${APP_ENV:-production}" = "production" ]; then
  composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev
else
  composer install --no-interaction --prefer-dist --optimize-autoloader
fi

map_railway_mysql_env() {
  if [ -n "${MYSQLHOST:-}" ]; then
    export DB_CONNECTION=mysql
    export DB_HOST="${MYSQLHOST}"
    export DB_PORT="${MYSQLPORT:-3306}"
    export DB_DATABASE="${MYSQLDATABASE:-railway}"
    export DB_USERNAME="${MYSQLUSER:-root}"
    export DB_PASSWORD="${MYSQLPASSWORD:-}"
    echo "==> Using Railway MySQL: ${DB_HOST}:${DB_PORT}/${DB_DATABASE}"
    return 0
  fi

  local db_url="${DATABASE_URL:-${MYSQL_URL:-${MYSQL_PUBLIC_URL:-${MYSQL_PRIVATE_URL:-}}}}"
  if [ -z "$db_url" ]; then
    return 1
  fi

  echo "==> Parsing DATABASE_URL for MySQL connection"
  eval "$(DATABASE_URL="$db_url" php -r '
$url = getenv("DATABASE_URL");
$p = parse_url($url);
if (!$p || empty($p["host"])) {
    fwrite(STDERR, "Invalid DATABASE_URL\n");
    exit(1);
}
$database = ltrim($p["path"] ?? "/railway", "/") ?: "railway";
$port = $p["port"] ?? "3306";
$user = $p["user"] ?? "root";
$pass = $p["pass"] ?? "";
function sh($v) { return escapeshellarg($v); }
echo "export DB_CONNECTION=mysql\n";
echo "export DB_HOST=".sh($p["host"])."\n";
echo "export DB_PORT=".sh((string)$port)."\n";
echo "export DB_DATABASE=".sh($database)."\n";
echo "export DB_USERNAME=".sh($user)."\n";
echo "export DB_PASSWORD=".sh($pass)."\n";
')"
  echo "==> Using DATABASE_URL host ${DB_HOST}:${DB_PORT}/${DB_DATABASE}"
}

require_mysql_connection() {
  if map_railway_mysql_env; then
    return 0
  fi

  cat <<'EOF'

ERROR: MySQL is not connected to the clothing-store service.

Fix in Railway:
  1. Project → "+ New" → "Database" → "MySQL" (if you do not have one yet)
  2. Open service "clothing-store" → tab "Variables"
  3. Click "+ New Variable" → "Add Reference" (or "Connect")
  4. Select your MySQL service → add all MYSQL* variables
  5. Redeploy clothing-store

You should see MYSQLHOST, MYSQLPORT, MYSQLUSER, MYSQLPASSWORD, MYSQLDATABASE in Variables.

EOF
  exit 1
}

require_mysql_connection

if [ "${APP_ENV:-production}" = "production" ]; then
  export APP_DEBUG="${APP_DEBUG:-false}"
  export SESSION_DRIVER="${SESSION_DRIVER:-file}"
  export CACHE_STORE="${CACHE_STORE:-file}"
  export QUEUE_CONNECTION="${QUEUE_CONNECTION:-sync}"
fi

ensure_env_file() {
  if [ -f .env ]; then
    return 0
  fi

  echo "==> Creating .env from Railway environment variables"
  {
    printf 'APP_NAME="%s"\n' "${APP_NAME:-Durability Store}"
    printf 'APP_ENV=%s\n' "${APP_ENV:-production}"
    printf 'APP_DEBUG=%s\n' "${APP_DEBUG:-false}"
    printf 'APP_URL=%s\n' "${APP_URL:-http://localhost}"
    printf 'FRONTEND_URL=%s\n' "${FRONTEND_URL:-*}"
    printf 'JWT_SECRET=%s\n' "${JWT_SECRET:-}"
    printf 'JWT_TTL=%s\n' "${JWT_TTL:-480}"
    printf 'SESSION_DRIVER=%s\n' "${SESSION_DRIVER:-file}"
    printf 'CACHE_STORE=%s\n' "${CACHE_STORE:-file}"
    printf 'QUEUE_CONNECTION=%s\n' "${QUEUE_CONNECTION:-sync}"
    if [ -n "${APP_KEY:-}" ]; then
      printf 'APP_KEY=%s\n' "${APP_KEY}"
    fi
  } > .env
}

ensure_app_key() {
  ensure_env_file

  if [ -n "${APP_KEY:-}" ]; then
    echo "==> APP_KEY provided by Railway"
    return 0
  fi

  if grep -qE '^APP_KEY=base64:.+' .env 2>/dev/null; then
    echo "==> APP_KEY already in .env"
    return 0
  fi

  echo "==> Generating APP_KEY (set APP_KEY in Railway Variables to skip)"
  php artisan key:generate --force
}

ensure_app_key

echo "==> Running migrations"
php artisan migrate --force

echo "==> Seeding database (idempotent)"
php artisan db:seed --force

php artisan storage:link || true

RAW_PORT="${PORT:-8000}"
PORT_NUM=$(echo "$RAW_PORT" | grep -oE '[0-9]+' || true)
if [ -z "$PORT_NUM" ]; then
  PORT_NUM=8000
fi

echo "==> Starting Laravel on port ${PORT_NUM}"
exec php artisan serve --host=0.0.0.0 --port="${PORT_NUM}"
