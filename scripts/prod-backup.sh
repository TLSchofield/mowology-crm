#!/usr/bin/env bash
# prod-backup.sh — download a full backup of the PRODUCTION database to this Mac (2026-10-12).
#
# Why: the host has no shell (shell_exec/mysqldump unavailable) and nothing backed the database up.
# This uploads a one-time, token-guarded, self-deleting PHP file that dumps every table (structure +
# data, views after tables) and STREAMS it, gzipped, as the HTTP response. Nothing is written to
# the server's disk, so no server copy can survive (security audit 2026-10-12 #6a — the first
# version wrote the dump under app/Storage/backups, inside the FTP/web tree, then deleted it).
#
# Usage:   scripts/prod-backup.sh            # → ~/Backups/mowology/mowology-db-YYYY-MM-DD-HHMM.sql.gz
#          BACKUP_KEEP=14 (default) newest dumps are kept; older ones are deleted after a good run.
# Restore: scripts/prod-restore.sh FILE [local_db]   (local MySQL only)
# The file holds every client's personal data: it is chmod 600 and kept out of iCloud. Read-only on
# the database (SELECTs only, inside a REPEATABLE READ snapshot so the dump is consistent).
# Transport: FTP upload of the one-time file via scripts/lib/ftp.sh (verified TLS); the dump comes
# back over HTTPS (curl verifies the certificate); the token travels in a header, not the URL, so it
# never lands in the access log.
set -euo pipefail
cd "$(git rev-parse --show-toplevel)"
. scripts/lib/ftp.sh
mw_ftp_check

DEST="${BACKUP_DIR:-$HOME/Backups/mowology}"
KEEP="${BACKUP_KEEP:-14}"
mkdir -p "$DEST"; chmod 700 "$DEST"
STAMP="$(date +%Y-%m-%d-%H%M)"
TOK="$(openssl rand -hex 24)"
NAME="once-$(openssl rand -hex 8).php"
TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT

cat > "$TMP/$NAME" <<PHP
<?php
if (!hash_equals('$TOK', (string)(\$_SERVER['HTTP_X_ONCE_TOKEN'] ?? ''))) { http_response_code(404); exit; }
@unlink(__FILE__);
set_time_limit(0); ignore_user_abort(false);
require_once dirname(__DIR__, 2) . '/app/Core/paths.php';
require_once APP_ROOT . '/Core/config.php';
@ini_set('zlib.output_compression', '0');
while (ob_get_level() > 0) { ob_end_clean(); }
header('Content-Type: application/gzip');
header('Content-Disposition: attachment; filename="dump.sql.gz"');
header('Cache-Control: no-store');
header('X-Accel-Buffering: no');
\$z = deflate_init(ZLIB_ENCODING_GZIP, ['level' => 6]);
\$put = function (string \$s) use (\$z) { \$c = deflate_add(\$z, \$s, ZLIB_NO_FLUSH); if (\$c !== '') { echo \$c; flush(); } };
\$tables = []; \$views = []; \$rows = 0;
try {
    \$db = new PDO(sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_NAME), DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false]);
    \$db->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    \$db->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
    \$put("-- Mowology production backup " . date('c') . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET UNIQUE_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n");
    foreach (\$db->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM) as \$r) { if (\$r[1] === 'VIEW') \$views[] = \$r[0]; else \$tables[] = \$r[0]; }
    foreach (\$tables as \$t) {
        \$create = \$db->query('SHOW CREATE TABLE \`' . \$t . '\`')->fetch(PDO::FETCH_NUM)[1];
        \$put("DROP TABLE IF EXISTS \`\$t\`;\n\$create;\n");
        \$st = \$db->query('SELECT * FROM \`' . \$t . '\`');
        \$batch = []; \$tn = 0;
        while ((\$row = \$st->fetch(PDO::FETCH_NUM)) !== false) {
            \$vals = [];
            foreach (\$row as \$v) \$vals[] = \$v === null ? 'NULL' : \$db->quote((string)\$v);
            \$batch[] = '(' . implode(',', \$vals) . ')';
            \$rows++; \$tn++;
            if (count(\$batch) >= 200) { \$put("INSERT INTO \`\$t\` VALUES " . implode(",\n", \$batch) . ";\n"); \$batch = []; }
        }
        if (\$batch) \$put("INSERT INTO \`\$t\` VALUES " . implode(",\n", \$batch) . ";\n");
        \$st->closeCursor();
        \$put("-- rows \`\$t\` \$tn\n\n");
    }
    foreach (\$views as \$v) {
        \$create = \$db->query('SHOW CREATE VIEW \`' . \$v . '\`')->fetch(PDO::FETCH_NUM)[1];
        \$create = preg_replace('/DEFINER=\S+ /', '', \$create);
        \$put("DROP VIEW IF EXISTS \`\$v\`;\n\$create;\n");
    }
    \$db->exec('COMMIT');
    // The "-- end" line is written ONLY on success; the Mac side refuses a dump without it.
    \$put("\nSET FOREIGN_KEY_CHECKS=1;\nSET UNIQUE_CHECKS=1;\n-- stats tables=" . count(\$tables) . " views=" . count(\$views) . " rows=\$rows\n-- end\n");
} catch (Throwable \$e) {
    \$put("\n-- BACKUP FAILED: " . str_replace(["\n", "\r"], ' ', \$e->getMessage()) . "\n");
}
echo deflate_add(\$z, '', ZLIB_FINISH);
PHP
php -l "$TMP/$NAME" >/dev/null

mw_lftp "put $TMP/$NAME -o /crm/api/$NAME; bye" >/dev/null 2>&1
FILE="$DEST/mowology-db-$STAMP.sql.gz"
PART="$FILE.part"
( umask 077; : > "$PART" )
http="$(curl -sS -m 1800 -H "X-Once-Token: $TOK" -o "$PART" -w '%{http_code}' "https://mowology.ca/crm/api/$NAME" || echo "curl-failed")"
code="$(curl -s -o /dev/null -w '%{http_code}' "https://mowology.ca/crm/api/$NAME")"
[ "$code" = "404" ] || echo "WARNING: /crm/api/$NAME still answers ($code) — delete it" >&2
[ "$http" = "200" ] || { rm -f "$PART"; echo "backup failed: HTTP $http" >&2; exit 1; }

if ! gzip -t "$PART" 2>/dev/null || ! gunzip -c "$PART" | tail -c 300 | grep -q -- '^-- end$'; then
  gunzip -c "$PART" 2>/dev/null | grep -- '^-- BACKUP FAILED' >&2 || true
  rm -f "$PART"; echo "downloaded dump is incomplete — discarded" >&2; exit 1
fi
mv "$PART" "$FILE"; chmod 600 "$FILE"
STATS="$(gunzip -c "$FILE" | tail -c 300 | grep -- '^-- stats' | sed 's/^-- stats //')"
echo "OK  $FILE  ($(du -h "$FILE" | cut -f1); $STATS)"

# Retention: keep the newest $KEEP dumps (only after a good run, so a failing night never prunes).
ls -1t "$DEST"/mowology-db-*.sql.gz 2>/dev/null | tail -n +"$((KEEP + 1))" | while read -r old; do
  rm -f -- "$old" && echo "pruned $(basename "$old")"
done
