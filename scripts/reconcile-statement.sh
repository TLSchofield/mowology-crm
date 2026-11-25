#!/usr/bin/env bash
set -euo pipefail

cd "$(git rev-parse --show-toplevel)"
[ "$#" -ge 4 ] || { echo "usage: scripts/reconcile-statement.sh <account-code> <from> <to> <csv> [<csv> ...]" >&2; exit 1; }

account_code="$1"; from_date="$2"; to_date="$3"; shift 3
[ "$#" -gt 0 ] || { echo "at least one CSV is required" >&2; exit 1; }
for csv_file in "$@"; do [ -f "$csv_file" ] || { echo "no such CSV: $csv_file" >&2; exit 1; }; done

tmp_dir="$(mktemp -d)"
trap 'rm -rf "$tmp_dir"' EXIT

php -r '
require $argv[1];
$all = [];
for ($i = 2; $i < $argc; $i++) $all = array_merge($all, StatementReconcileService::parseVancityCsv(file_get_contents($argv[$i]), $i . ":" . basename($argv[$i])));
$j = json_encode($all, JSON_INVALID_UTF8_SUBSTITUTE);
if ($j === false) { fwrite(STDERR, "cannot encode the statement lines: " . json_last_error_msg() . "\n"); exit(1); }
echo base64_encode($j);
' app/Modules/Accounting/Services/StatementReconcileService.php "$@" > "$tmp_dir/lines.b64"

code_b64="$(printf '%s' "$account_code" | base64 | tr -d '\n')"
from_b64="$(printf '%s' "$from_date" | base64 | tr -d '\n')"
to_b64="$(printf '%s' "$to_date" | base64 | tr -d '\n')"
lines_b64="$(cat "$tmp_dir/lines.b64")"

{
    # Embed the service source so the preview works before the file is deployed (read-only either way).
    echo "if (!class_exists('StatementReconcileService', false)) {"
    tail -n +2 app/Modules/Accounting/Services/StatementReconcileService.php
    echo "}"
    echo "\$code = base64_decode('$code_b64');"
    echo "\$from = base64_decode('$from_b64');"
    echo "\$to = base64_decode('$to_b64');"
    echo "\$lines = json_decode(base64_decode('$lines_b64'), true);"
    echo "if (!is_array(\$lines)) throw new RuntimeException('statement lines did not decode');"
    echo "\$find = \$db->prepare('SELECT id FROM chart_of_accounts WHERE code = ? LIMIT 1');"
    echo "\$find->execute([\$code]);"
    echo "\$accountId = \$find->fetchColumn();"
    echo "if (!\$accountId) throw new RuntimeException('Unknown account code: ' . \$code);"
    echo "\$out = (new StatementReconcileService(\$db))->report((int)\$accountId, \$from, \$to, \$lines);"
} > "$tmp_dir/snippet.php"

scripts/prod-run.sh "$tmp_dir/snippet.php" > "$tmp_dir/report.json"
php -r '
$r = json_decode(file_get_contents($argv[1]), true);
if (!is_array($r)) { fwrite(STDERR, "Invalid report output\n"); exit(1); }
if (isset($r["_error"])) { fwrite(STDERR, $r["_error"] . "\n"); exit(1); }
printf("Statement reconciliation: %s to %s — %s\n\n", $r["from"], $r["to"], $r["status"]);
printf("%-8s %14s %14s %14s %8s %8s\n", "Month", "Statement", "Books", "Difference", "Stmt", "Books");
foreach ($r["months"] as $ym => $m) printf("%-8s %14.2f %14.2f %14.2f %8d %8d\n", $ym, $m["statement_net"], $m["books_net"], $m["difference"], $m["statement_count"], $m["books_count"]);
$m = $r["matching"];
echo "\nUnmatched statement lines\n";
foreach ($m["statement_unmatched"] as $x) printf("  %s %12.2f  %s\n", $x["date"], $x["amount"], $x["description"] ?? "");
echo "\nUnmatched book entries\n";
foreach ($m["books_unmatched"] as $x) printf("  %s %12.2f  #%d %s\n", $x["date"], $x["net"], $x["id"], $x["memo"] ?? "");
echo "\nMatched pairs by tier\n";
foreach ($m["pairs"] as $p) printf("  %-12s %12.2f  gap %d day(s), %d statement / %d book\n", $p["tier"], $p["amount"], $p["gap_days"], count($p["statement"]), count($p["books"]));
echo "\nApproved receipts with no bank line (the nightly check will send these back to draft)\n";
foreach ($r["receipts_without_line"] ?? [] as $x) printf("  %s %12.2f  #%d %s\n", $x["date"], $x["total"], $x["id"], $x["vendor"]);
if ($m["capped"]) echo "\nGroup search cap reached; affected lines were left unmatched.\n";
' "$tmp_dir/report.json"
