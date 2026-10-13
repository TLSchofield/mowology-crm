#!/usr/bin/env bash
# prod-backup.sh — download a full backup of the PRODUCTION database to this Mac (2026-10-12).
#
# Why: the host has no shell (shell_exec/mysqldump unavailable) and nothing backed the database up.
# This uploads a one-time, token-guarded, self-deleting PHP file that dumps every table (structure +
# data, views after tables) to a gzipped .sql file under /app/Storage/backups/ — app/ is denied to the
# web by app/.htaccess — then downloads that file over FTP and deletes it from the server.
#
# Usage:   scripts/prod-backup.sh            # → ~/Backups/mowology/mowology-db-YYYY-MM-DD-HHMM.sql.gz
# Restore: gunzip -c FILE | mysql -u root mowology_local
# The file holds every client's personal data: it is chmod 600 and kept out of iCloud. Read-only on
# the database (SELECTs only, inside a REPEATABLE READ snapshot so the dump is consistent).
set -euo pipefail
cd "$(git rev-parse --show-toplevel)"

DEST="${BACKUP_DIR:-$HOME/Backups/mowology}"
mkdir -p "$DEST"; chmod 700 "$DEST"
STAMP="$(date +%Y-%m-%d-%H%M)"
TOK="$(openssl rand -hex 24)"
NAME="once-$(openssl rand -hex 8).php"
OUT="dump-$(openssl rand -hex 12).sql.gz"
TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT

cat > "$TMP/$NAME" <<PHP
<?php
if (!hash_equals('$TOK', (string)(\$_GET['t'] ?? ''))) { http_response_code(404); exit; }
@unlink(__FILE__);
set_time_limit(0); ignore_user_abort(true);
header('Content-Type: application/json');
require_once dirname(__DIR__, 2) . '/app/Core/paths.php';
require_once APP_ROOT . '/Core/config.php';
\$dir = APP_ROOT . '/Storage/backups';
if (!is_dir(\$dir)) mkdir(\$dir, 0700, true);
\$path = \$dir . '/$OUT';
try {
    \$db = new PDO(sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_NAME), DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false]);
    \$db->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    \$db->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
    \$gz = gzopen(\$path, 'wb6');
    gzwrite(\$gz, "-- Mowology production backup " . date('c') . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET UNIQUE_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n");
    \$tables = []; \$views = [];
    foreach (\$db->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM) as \$r) { if (\$r[1] === 'VIEW') \$views[] = \$r[0]; else \$tables[] = \$r[0]; }
    \$rows = 0;
    foreach (\$tables as \$t) {
        \$create = \$db->query('SHOW CREATE TABLE \`' . \$t . '\`')->fetch(PDO::FETCH_NUM)[1];
        gzwrite(\$gz, "DROP TABLE IF EXISTS \`\$t\`;\n\$create;\n");
        \$st = \$db->query('SELECT * FROM \`' . \$t . '\`');
        \$batch = [];
        while ((\$row = \$st->fetch(PDO::FETCH_NUM)) !== false) {
            \$vals = [];
            foreach (\$row as \$v) \$vals[] = \$v === null ? 'NULL' : \$db->quote((string)\$v);
            \$batch[] = '(' . implode(',', \$vals) . ')';
            \$rows++;
            if (count(\$batch) >= 200) { gzwrite(\$gz, "INSERT INTO \`\$t\` VALUES " . implode(",\n", \$batch) . ";\n"); \$batch = []; }
        }
        if (\$batch) gzwrite(\$gz, "INSERT INTO \`\$t\` VALUES " . implode(",\n", \$batch) . ";\n");
        \$st->closeCursor();
        gzwrite(\$gz, "\n");
    }
    foreach (\$views as \$v) {
        \$create = \$db->query('SHOW CREATE VIEW \`' . \$v . '\`')->fetch(PDO::FETCH_NUM)[1];
        \$create = preg_replace('/DEFINER=\S+ /', '', \$create);
        gzwrite(\$gz, "DROP VIEW IF EXISTS \`\$v\`;\n\$create;\n");
    }
    gzwrite(\$gz, "\nSET FOREIGN_KEY_CHECKS=1;\nSET UNIQUE_CHECKS=1;\n-- end\n");
    gzclose(\$gz);
    \$db->exec('COMMIT');
    echo json_encode(['ok' => true, 'file' => '$OUT', 'bytes' => filesize(\$path), 'tables' => count(\$tables), 'views' => count(\$views), 'rows' => \$rows]);
} catch (Throwable \$e) {
    @unlink(\$path);
    echo json_encode(['ok' => false, 'error' => \$e->getMessage()]);
}
PHP
php -l "$TMP/$NAME" >/dev/null

U="$(git config git-ftp.user)"; P="$(git config git-ftp.password)"
lftp -u "$U,$P" -e "set ssl:verify-certificate no; set ftp:ssl-force true; put $TMP/$NAME -o /crm/api/$NAME; bye" ftp.mowology.ca >/dev/null 2>&1
RES="$(curl -s -m 1500 "https://mowology.ca/crm/api/$NAME?t=$TOK")"
echo "$RES"
code="$(curl -s -o /dev/null -w '%{http_code}' "https://mowology.ca/crm/api/$NAME")"
[ "$code" = "404" ] || echo "WARNING: /crm/api/$NAME still answers ($code) — delete it" >&2
echo "$RES" | grep -q '"ok":true' || { echo "backup failed" >&2; exit 1; }

FILE="$DEST/mowology-db-$STAMP.sql.gz"
lftp -u "$U,$P" -e "set ssl:verify-certificate no; set ftp:ssl-force true; get /app/Storage/backups/$OUT -o $FILE; rm /app/Storage/backups/$OUT; bye" ftp.mowology.ca >/dev/null 2>&1
chmod 600 "$FILE"
gzip -t "$FILE" && tail -c 200 <(gunzip -c "$FILE") | grep -q -- '-- end' && echo "OK  $FILE  ($(du -h "$FILE" | cut -f1))" || { echo "downloaded file is incomplete: $FILE" >&2; exit 1; }
lftp -u "$U,$P" -e "set ssl:verify-certificate no; set ftp:ssl-force true; cls /app/Storage/backups/; bye" ftp.mowology.ca 2>/dev/null | grep -q "$OUT" && echo "WARNING: server copy still present — delete /app/Storage/backups/$OUT" >&2 || true
