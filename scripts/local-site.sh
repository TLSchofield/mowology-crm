#!/usr/bin/env bash
# LOCAL TEST ONLY — run a private copy of the Mowology CRM on this Mac for testing before production.
#
#   scripts/local-site.sh start    start MySQL + the PHP server (first run also builds everything)
#   scripts/local-site.sh stop     stop both
#   scripts/local-site.sh reset    drop the local database, reload the schema, run local-only
#                                  migrations, seed fake data (keeps servers running)
#   scripts/local-site.sh status   show what is running
#   scripts/local-site.sh smoke    run the Playwright smoke test (scripts/local/smoke.py)
#   scripts/local-site.sh mail     tail the captured-mail log
#
# Site: http://localhost:8000   Docs: docs/LOCAL_SITE.md
#
# NO real data: the schema comes from the newest production backup, structure only
# (scripts/local/extract-schema.php drops every INSERT). Mail is appended to a log, never sent.
# External services are blank/fake in the generated secrets.php AND every outbound HTTP(S)
# request from PHP goes to a dead proxy (127.0.0.1:9), so nothing can reach the internet.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
STATE="${MOWOLOGY_LOCAL_STATE:-$HOME/.mowology-local}"
PORT="${LOCAL_SITE_PORT:-8000}"
DB_PORT="${LOCAL_DB_PORT:-3307}"
DB_NAME="mowology_local"
DB_USER="mowology_local"
BACKUP_DIR="${MOWOLOGY_BACKUP_DIR:-$HOME/Backups/mowology}"
LOCAL_MIGRATIONS=(1331 1350 1355)   # repo migrations newer than the production schema snapshot

DATADIR="$STATE/mysql-data"
SOCK="$STATE/mysql.sock"
MYSQL_PID="$STATE/mysqld.pid"
PHP_PID="$STATE/php-server.pid"
SCHEMA="$STATE/schema.sql"
DBPASS_FILE="$STATE/db-password"
mkdir -p "$STATE" "$STATE/sessions" "$STATE/tmp"
chmod 700 "$STATE"

# ── Binaries: Homebrew first, MAMP as fallback ─────────────────────────────────────
find_dir() { for d in "$@"; do [ -x "$d/mysqld" ] && { echo "$d"; return; }; done; return 1; }
MYSQL_BIN="$(find_dir /usr/local/opt/mysql@8.0/bin /opt/homebrew/opt/mysql@8.0/bin /Applications/MAMP/Library/bin/mysql80/bin)" \
  || { echo "MySQL 8.0 not found (brew install mysql@8.0)"; exit 1; }
PHP=""
for p in /usr/local/opt/php@8.3/bin/php /opt/homebrew/opt/php@8.3/bin/php /Applications/MAMP/bin/php/php8.3.30/bin/php; do
  [ -x "$p" ] && { PHP="$p"; break; }
done
[ -n "$PHP" ] || { echo "PHP 8.3 not found (brew install shivammathur/php/php@8.3)"; exit 1; }
MYSQL=("$MYSQL_BIN/mysql" --no-defaults --socket="$SOCK" -uroot)

php_flags() {
  local f=(-c "$ROOT/scripts/local/php.ini"
    -d "session.save_path=$STATE/sessions"
    -d "upload_tmp_dir=$STATE/tmp"
    -d "sys_temp_dir=$STATE/tmp"
    -d "sendmail_path=$ROOT/scripts/local/sendmail-log.sh $STATE/mail.log"
    -d "pdo_mysql.default_socket=$SOCK"
    -d "mysqli.default_socket=$SOCK"
    -d "error_log=$STATE/php-error.log")
  local extdir; extdir="$("$PHP" -n -r 'echo ini_get("extension_dir");' 2>/dev/null || true)"
  case "$PHP" in /Applications/MAMP/*)
    extdir="$(dirname "$(dirname "$PHP")")/lib/php/extensions/no-debug-non-zts-20230831" ;;
  esac
  if [ -n "$extdir" ]; then
    f+=(-d "extension_dir=$extdir")
    [ -f "$extdir/opcache.so" ] && f+=(-d "zend_extension=$extdir/opcache.so")
    [ -f "$extdir/imap.so" ] && f+=(-d "extension=imap.so")
  fi
  printf '%s\n' "${f[@]}"
}

php_run() { local flags=(); while IFS= read -r l; do flags+=("$l"); done < <(php_flags); "$PHP" "${flags[@]}" "$@"; }

mysql_running() { [ -S "$SOCK" ] && "$MYSQL_BIN/mysqladmin" --no-defaults --socket="$SOCK" -uroot ping >/dev/null 2>&1; }
php_running() { [ -f "$PHP_PID" ] && kill -0 "$(cat "$PHP_PID")" 2>/dev/null; }

db_password() {
  [ -f "$DBPASS_FILE" ] || { "$PHP" -r 'echo bin2hex(random_bytes(16));' > "$DBPASS_FILE"; chmod 600 "$DBPASS_FILE"; }
  cat "$DBPASS_FILE"
}

start_mysql() {
  if mysql_running; then echo "MySQL already running ($SOCK)"; return; fi
  if [ ! -d "$DATADIR/mysql" ]; then
    echo "Initialising MySQL data dir at $DATADIR …"
    "$MYSQL_BIN/mysqld" --no-defaults --initialize-insecure --datadir="$DATADIR" --log-error="$STATE/mysqld-init.err"
  fi
  "$MYSQL_BIN/mysqld" --no-defaults --datadir="$DATADIR" --socket="$SOCK" --port="$DB_PORT" \
    --bind-address=127.0.0.1 --mysqlx=OFF --skip-log-bin --pid-file="$MYSQL_PID" \
    --log-error="$STATE/mysqld.err" --character-set-server=utf8mb4 --collation-server=utf8mb4_general_ci \
    ${LOCAL_SQL_MODE:+--sql-mode="$LOCAL_SQL_MODE"} < /dev/null >/dev/null 2>&1 &
  for _ in $(seq 1 60); do mysql_running && break; sleep 1; done
  mysql_running || { echo "MySQL failed to start — see $STATE/mysqld.err"; exit 1; }
  echo "MySQL $("$MYSQL_BIN/mysqld" --version | awk '{print $3}') running on socket $SOCK and 127.0.0.1:$DB_PORT"
}

stop_mysql() {
  if mysql_running; then "$MYSQL_BIN/mysqladmin" --no-defaults --socket="$SOCK" -uroot shutdown && echo "MySQL stopped"; else echo "MySQL not running"; fi
}

latest_backup() { ls -t "$BACKUP_DIR"/mowology-db-*.sql.gz 2>/dev/null | head -1; }

build_schema() {
  local b; b="$(latest_backup)"
  if [ -z "$b" ]; then
    [ -f "$SCHEMA" ] && { echo "No backup found; reusing $SCHEMA"; return; }
    echo "No backup in $BACKUP_DIR and no cached schema. Run scripts/prod-backup.sh first."; exit 1
  fi
  if [ ! -f "$SCHEMA" ] || [ "$b" -nt "$SCHEMA" ]; then
    echo "Extracting STRUCTURE ONLY from $(basename "$b") (all rows discarded) …"
    "$PHP" -n -d memory_limit=512M "$ROOT/scripts/local/extract-schema.php" "$b" "$SCHEMA"
    chmod 600 "$SCHEMA"
  fi
}

reset_db() {
  start_mysql
  build_schema
  local pw; pw="$(db_password)"
  echo "Recreating database $DB_NAME …"
  "${MYSQL[@]}" -e "DROP DATABASE IF EXISTS \`$DB_NAME\`;
    CREATE DATABASE \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
    CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$pw';
    CREATE USER IF NOT EXISTS '$DB_USER'@'127.0.0.1' IDENTIFIED BY '$pw';
    ALTER USER '$DB_USER'@'localhost' IDENTIFIED BY '$pw';
    ALTER USER '$DB_USER'@'127.0.0.1' IDENTIFIED BY '$pw';
    GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
    GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'127.0.0.1';
    FLUSH PRIVILEGES;"
  "${MYSQL[@]}" "$DB_NAME" < "$SCHEMA"
  for m in "${LOCAL_MIGRATIONS[@]}"; do
    for f in "$ROOT"/database/migrations/${m}_*.sql; do
      [ -f "$f" ] || continue
      echo "Local-only migration: $(basename "$f")"
      "${MYSQL[@]}" "$DB_NAME" < "$f"
    done
  done
  "${MYSQL[@]}" -N -e "SELECT CONCAT(SUM(table_type='BASE TABLE'),' tables, ',SUM(table_type='VIEW'),' views')
    FROM information_schema.tables WHERE table_schema='$DB_NAME';"
  ensure_secrets
  echo "Seeding fake data …"
  php_run "$ROOT/scripts/local/seed.php"
}

ensure_secrets() {
  php_run "$ROOT/scripts/local/make-secrets.php" "$DB_NAME" "$DB_USER" "$(db_password)" "$DB_PORT" >/dev/null
}

start_php() {
  if php_running; then echo "PHP server already running on http://localhost:$PORT"; return; fi
  ensure_secrets
  local flags=(); while IFS= read -r l; do flags+=("$l"); done < <(php_flags)
  # Dead proxy for every outbound HTTP(S) call PHP/cURL makes; localhost stays direct.
  ( cd "$ROOT/public" || exit 1; \
    http_proxy=http://127.0.0.1:9 https_proxy=http://127.0.0.1:9 HTTP_PROXY=http://127.0.0.1:9 \
    HTTPS_PROXY=http://127.0.0.1:9 ALL_PROXY=http://127.0.0.1:9 all_proxy=http://127.0.0.1:9 \
    no_proxy=localhost,127.0.0.1 NO_PROXY=localhost,127.0.0.1 \
    APP_ENV=dev PHP_CLI_SERVER_WORKERS="${PHP_WORKERS:-6}" \
    nohup "$PHP" "${flags[@]}" -S "127.0.0.1:$PORT" -t "$ROOT/public" "$ROOT/scripts/local/router.php" \
      < /dev/null >> "$STATE/php-server.log" 2>&1 & echo $! > "$PHP_PID" )
  for _ in $(seq 1 20); do curl -s -m 5 -o /dev/null "http://127.0.0.1:$PORT/loginAuth/login.php" && break; sleep 0.5; done
  echo "PHP $("$PHP" -n -r 'echo PHP_VERSION;') serving http://localhost:$PORT  (log: $STATE/php-server.log)"
}

stop_php() {
  local pat="-S 127.0.0.1:$PORT -t $ROOT/public"
  if php_running || pgrep -f -- "$pat" >/dev/null; then
    [ -f "$PHP_PID" ] && kill "$(cat "$PHP_PID")" 2>/dev/null || true
    pkill -f -- "$pat" 2>/dev/null || true   # the server's worker processes too
    rm -f "$PHP_PID"; echo "PHP server stopped"
  else echo "PHP server not running"; rm -f "$PHP_PID"; fi
}

status() {
  echo "State dir : $STATE"
  echo "MySQL     : $MYSQL_BIN"; mysql_running && echo "            running (socket $SOCK, 127.0.0.1:$DB_PORT)" || echo "            stopped"
  echo "PHP       : $PHP"; php_running && echo "            running http://localhost:$PORT (pid $(cat "$PHP_PID"))" || echo "            stopped"
  if mysql_running; then
    "${MYSQL[@]}" -N -e "SELECT CONCAT('Database  : $DB_NAME — ', COUNT(*), ' tables/views') FROM information_schema.tables WHERE table_schema='$DB_NAME';" 2>/dev/null || true
  fi
  [ -f "$STATE/mail.log" ] && echo "Mail log  : $STATE/mail.log ($(grep -c '^=== ' "$STATE/mail.log") captured)" || echo "Mail log  : $STATE/mail.log (empty)"
  echo "Logins    : scripts/local/.credentials.local"
}

case "${1:-}" in
  start)
    start_mysql
    if ! "${MYSQL[@]}" -e "USE \`$DB_NAME\`" 2>/dev/null; then reset_db; fi
    start_php; status ;;
  stop)   stop_php; stop_mysql ;;
  reset)  reset_db; echo "Reset done." ;;
  status) status ;;
  smoke)  shift; python3 "$ROOT/scripts/local/smoke.py" "$@" ;;
  mail)   touch "$STATE/mail.log"; tail -n 80 -f "$STATE/mail.log" ;;
  *) sed -n '2,13p' "$0"; exit 1 ;;
esac
