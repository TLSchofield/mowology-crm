#!/usr/bin/env bash
# =============================================================================
# scripts/tests/codex-web-build-selftest.sh — pure tests for the safe web-build plumbing.
# =============================================================================
# Tim, 2026-10-24: "utilize codex as much as possible due to usage". These checks need neither
# production nor Codex; Codex writes/reviews in the real flow, tests decide, then Claude reviews.
# =============================================================================
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
CODEX_WEB_BUILD_SOURCE_ONLY=1 . "$ROOT/scripts/codex-web-build.sh"
TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT
SRC="$TMP/src"; DEST="$TMP/dest"
mkdir -p "$SRC"/{app/vendor,public/crm,tests/Unit,scripts,docs,database,uploads,app_config}
for f in app/keep.php public/keep.js public/.htaccess tests/Unit/X.php scripts/tool.sh phpunit.xml.dist; do mkdir -p "$SRC/$(dirname "$f")"; echo x > "$SRC/$f"; done
for f in app/secrets.php public/bad.min.js public/bad.min.css docs/no.php database/no.php uploads/no.php app_config/no.php app/vendor/no.php tests/no.md; do mkdir -p "$SRC/$(dirname "$f")"; echo x > "$SRC/$f"; done
[ "$(copy_code_tree "$SRC" "$DEST")" -eq 6 ]
actual="$(cd "$DEST" && find . -type f | sort)"
expected="./app/keep.php
./phpunit.xml.dist
./public/.htaccess
./public/keep.js
./scripts/tool.sh
./tests/Unit/X.php"
[ "$actual" = "$expected" ] || { echo "filter failed" >&2; diff -u <(echo "$expected") <(echo "$actual") || true; exit 1; }

cat > "$TMP/sample.diff" <<'EOF'
--- a/a.php
+++ b/a.php
 context [client]
-old [client]
+new [client]
+ordinary
EOF
extract_redacted_lines "$TMP/sample.diff" "$TMP/redacted"
[ "$(wc -l < "$TMP/redacted" | tr -d ' ')" -eq 2 ]
grep -q '^ context \[client\]$' "$TMP/redacted"
grep -q '^+new \[client\]$' "$TMP/redacted"

echo 'OK (42 tests, 100 assertions)' > "$TMP/ok"
[ "$(test_summary "$TMP/ok")" = 'OK (42 tests, 100 assertions)' ]
echo 'Tests: 12, Assertions: 20, Failures: 2.' > "$TMP/fail"
[ "$(test_summary "$TMP/fail")" = 'Tests: 12, Assertions: 20, Failures: 2.' ]
echo "codex-web-build self-test: PASS"
