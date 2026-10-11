#!/usr/bin/env bash
# prod-run.sh — run a PHP snippet against PRODUCTION, rolled back unless --commit (2026-10-11).
#
# Why: one-off checks and pilots on the live books were hand-written self-deleting scripts, and a
# pilot's "undo" once changed a real invoice (INV-2026-0010). Here every run is ONE database
# transaction that is ROLLED BACK by default — a preview shows exactly what would change and then
# nothing stays changed. Only `--commit` keeps the changes.
#
# Usage:
#   scripts/prod-run.sh snippet.php            # preview: runs, prints $out as JSON, rolls back
#   scripts/prod-run.sh snippet.php --commit   # same, but commits
#
# The snippet is plain PHP WITHOUT "<?php". It gets:
#   $db   — a PDO whose nested beginTransaction()/commit()/rollBack() become SAVEPOINTs inside the
#           outer transaction (services that open their own transactions still roll back)
#   $out  — an array; whatever you put in it is printed as JSON
#   $commit — bool, true only with --commit
# APP_ROOT / PUBLIC_ROOT / config.php are loaded. Services must be given $db (not call getDB()) for
# their writes to be inside the transaction.
#
# Refused in preview: DDL (CREATE/ALTER/DROP/TRUNCATE/RENAME) — MySQL commits those implicitly.
# Transport: the same one-time, token-guarded, self-deleting file as before (FTP creds from git config).
set -euo pipefail
cd "$(git rev-parse --show-toplevel)"

SNIP="${1:?usage: scripts/prod-run.sh snippet.php [--commit]}"
COMMIT=0; [ "${2:-}" = "--commit" ] && COMMIT=1
[ -f "$SNIP" ] || { echo "no such snippet: $SNIP" >&2; exit 1; }
if [ $COMMIT -eq 0 ] && grep -Eiq '\b(CREATE|ALTER|DROP|TRUNCATE|RENAME)[[:space:]]+(TABLE|INDEX|DATABASE|VIEW)' "$SNIP"; then
  echo "refused: DDL commits implicitly in MySQL — run it with --commit on purpose, or move it to a migration" >&2; exit 2
fi
if grep -q '^<?php' "$SNIP"; then echo "remove the opening <?php from the snippet" >&2; exit 1; fi

TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT
TOK="$(openssl rand -hex 16)"; NAME="once-$(openssl rand -hex 8).php"
{
cat <<PHP
<?php
if (!hash_equals('$TOK', (string)(\$_GET['t'] ?? ''))) { http_response_code(404); exit; }
@unlink(__FILE__);
set_time_limit(280);
header('Content-Type: application/json');
if (function_exists('opcache_reset')) opcache_reset();
require_once dirname(__DIR__, 2) . '/app/Core/paths.php';
require_once APP_ROOT . '/Core/config.php';
/** Nested transactions become savepoints inside the one outer transaction. */
final class ProdRunPDO extends PDO {
    private int \$depth = 0;
    public function beginTransaction(): bool { if (\$this->depth++ === 0) return parent::beginTransaction(); \$this->exec('SAVEPOINT sp' . \$this->depth); return true; }
    public function commit(): bool { if (--\$this->depth === 0) return parent::commit(); \$this->exec('RELEASE SAVEPOINT sp' . (\$this->depth + 1)); return true; }
    public function rollBack(): bool { if (--\$this->depth === 0) return parent::rollBack(); \$this->exec('ROLLBACK TO SAVEPOINT sp' . (\$this->depth + 1)); return true; }
    public function inTransaction(): bool { return \$this->depth > 1; }   // services see "not in one" at top level
    public function closeAll(bool \$keep): void { while (\$this->depth > 1) \$this->commit(); if (\$this->depth === 1) { \$keep ? \$this->commit() : \$this->rollBack(); } }
}
\$db = new ProdRunPDO(sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_NAME), DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
\$db->exec("SET time_zone = '" . date('P') . "'");
\$commit = $( [ $COMMIT -eq 1 ] && echo true || echo false );
\$out = [];
\$db->beginTransaction();
try {
PHP
cat "$SNIP"
cat <<'PHP'

    $db->closeAll($commit);
    $out['_mode'] = $commit ? 'COMMITTED' : 'PREVIEW — rolled back, nothing changed';
} catch (Throwable $e) {
    try { $db->closeAll(false); } catch (Throwable $e2) {}
    $out['_error'] = $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine();
    $out['_mode'] = 'ERROR — rolled back, nothing changed';
}
echo json_encode($out);
PHP
} > "$TMP/$NAME"
php -l "$TMP/$NAME" >/dev/null || { echo "snippet does not parse" >&2; php -l "$TMP/$NAME"; exit 1; }

U="$(git config git-ftp.user)"; P="$(git config git-ftp.password)"
lftp -u "$U,$P" -e "set ssl:verify-certificate no; set ftp:ssl-force true; put $TMP/$NAME -o /crm/api/$NAME; bye" ftp.mowology.ca >/dev/null 2>&1
curl -s -m 290 "https://mowology.ca/crm/api/$NAME?t=$TOK"
echo
# The file deletes itself on first request; make sure it is gone.
code="$(curl -s -o /dev/null -w '%{http_code}' "https://mowology.ca/crm/api/$NAME")"
[ "$code" = "404" ] || echo "WARNING: /crm/api/$NAME still answers ($code) — delete it" >&2
