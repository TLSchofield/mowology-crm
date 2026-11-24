#!/usr/bin/env bash
# qbo-onboarding-snapshot.sh — LOCAL TEST ONLY: copy the connected QuickBooks TEST company into the local
# site's onboarding snapshot, so the QuickBooks onboarding import can be rehearsed locally (2026-10-20).
#
# Why a copy: PHP on this Mac can't call Intuit (no CA bundle), and the live site must not import (Ittai has
# one business, so a test company's customers would land in Mowology's CRM).
#
#   1. production reads the QuickBooks company (QboOnboardingService::pull) inside a ROLLED-BACK preview and
#      prints the snapshot — nothing is kept on production;
#   2. this Mac loads it into mowology_local (QboOnboardingService::import), which refuses anything but the
#      LOCAL TEST ONLY database.
# Refuses a production QuickBooks company: the snapshot is the sandbox's made-up data only.
#
# Usage: scripts/local/qbo-onboarding-snapshot.sh
set -euo pipefail
cd "$(git rev-parse --show-toplevel)"

tmp="$(mktemp -d -t qboob.XXXXXX)"
trap 'rm -rf "$tmp"' EXIT

cat > "$tmp/pull.php" <<'PHP'
require_once APP_ROOT . '/Modules/QuickBooks/Services/QboOnboardingService.php';
$conn = new QboConnectionService($db);
$co = $conn->company();
if (!$co) { $out['error'] = 'QuickBooks is not connected on production.'; return; }
if ($co['environment'] !== 'sandbox') { $out['error'] = 'Refusing: the connected QuickBooks company is not the sandbox.'; return; }
$ob = new QboOnboardingService($db, $conn);
$r = $ob->pull();
$out['pull'] = $r['message'];
$out['snapshot'] = $r['ok'] ? $ob->export() : null;
PHP

scripts/prod-run.sh "$tmp/pull.php" > "$tmp/out.json"
php -r '
$d = json_decode(file_get_contents($argv[1]), true);
if (!is_array($d) || !empty($d["error"]) || empty($d["snapshot"])) { fwrite(STDERR, "production: " . ($d["error"] ?? $d["_error"] ?? "no snapshot") . "\n"); exit(1); }
file_put_contents($argv[2], $d["snapshot"]);
echo "production: " . $d["pull"] . "\n";
' "$tmp/out.json" "$tmp/snapshot.json"

PHP_BIN=""
for p in /usr/local/opt/php@8.3/bin/php /opt/homebrew/opt/php@8.3/bin/php /Applications/MAMP/bin/php/php8.3.30/bin/php; do
  [ -x "$p" ] && { PHP_BIN="$p"; break; }
done
[ -n "$PHP_BIN" ] || PHP_BIN=php

# The local site's own PHP settings (its php.ini and the MySQL socket scripts/local-site.sh uses).
SOCK="$HOME/.mowology-local/mysql.sock"
"$PHP_BIN" -c scripts/local/php.ini -d "pdo_mysql.default_socket=$SOCK" -d "mysqli.default_socket=$SOCK" -r '
$root = $argv[1];
require_once $root . "/app/Core/paths.php";
require_once APP_ROOT . "/Core/config.php";
if (!defined("LOCAL_TEST_SITE") || DB_NAME !== "mowology_local") { fwrite(STDERR, "REFUSING: only the LOCAL TEST ONLY database.\n"); exit(1); }
require_once APP_ROOT . "/Modules/QuickBooks/Services/QboOnboardingService.php";
$r = (new QboOnboardingService(getDB(), null, 1, null, true))->import(file_get_contents($argv[2]));
echo "local: " . $r["message"] . "\n";
exit($r["ok"] ? 0 : 1);
' "$PWD" "$tmp/snapshot.json"
