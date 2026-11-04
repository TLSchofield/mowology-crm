#!/usr/bin/env bash
# deploy-guard-selftest.sh — test deploy fingerprints and decisions without FTP.
# Why: stale-live refusal must stay testable without production access. Tim 2026-10-24.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
TEST_TMP="$(mktemp -d)"
trap 'rm -rf "$TEST_TMP"' EXIT
export DEPLOY_STATE_DIR="$TEST_TMP/state"
. "$ROOT/scripts/lib/deploy-fingerprints.sh"

printf 'one\n' > "$TEST_TMP/one"
printf 'two\n' > "$TEST_TMP/two"
: > "$TEST_TMP/empty"
one_sha="$(shasum -a 256 "$TEST_TMP/one" | awk '{print $1}')"
two_sha="$(shasum -a 256 "$TEST_TMP/two" | awk '{print $1}')"
empty_sha="$(shasum -a 256 "$TEST_TMP/empty" | awk '{print $1}')"

[ "$(fp_last public/new.php)" = "" ]
[ "$(fp_decide public/new.php "$one_sha")" = "REFUSE-NEVER" ]
fp_record public/example.php "$TEST_TMP/one" pulled
[ "$(fp_last public/example.php)" = "$one_sha" ]
[ "$(fp_decide public/example.php "$one_sha")" = "ALLOW" ]
[ "$(fp_decide public/example.php "$two_sha")" = "REFUSE-CHANGED" ]
fp_record public/example.php "$TEST_TMP/two" deployed
[ "$(fp_last public/example.php)" = "$two_sha" ]
fp_record app/missing.php MISSING pulled
[ "$(fp_last app/missing.php)" = "MISSING" ]
[ "$(fp_decide app/missing.php MISSING)" = "ALLOW" ]
[ "$(fp_decide app/missing.php "$one_sha")" = "REFUSE-CHANGED" ]
fp_record public/empty.php "$TEST_TMP/empty" pulled
[ "$(fp_last public/empty.php)" = "$empty_sha" ]
[ "$(fp_decide public/empty.php "$empty_sha")" = "ALLOW" ]

printf 'not a directory\n' > "$TEST_TMP/state-parent"
if (
  export DEPLOY_STATE_DIR="$TEST_TMP/state-parent/child"
  . "$ROOT/scripts/lib/deploy-fingerprints.sh"
  fp_record public/failure.php "$TEST_TMP/one" deployed 2>/dev/null
); then
  echo "fp_record unexpectedly succeeded with an unusable state directory" >&2
  exit 1
fi

export DEPLOY_LOCK_POLL_SECONDS=0.05
lock_order="$TEST_TMP/lock-order"
(
  fp_lock_acquire
  printf 'first-acquired\n' >> "$lock_order"
  sleep 0.2
  printf 'first-releasing\n' >> "$lock_order"
  fp_lock_release
) &
first_pid=$!
while [ ! -d "$DEPLOY_LOCK_DIR" ]; do sleep 0.01; done
(
  fp_lock_acquire
  printf 'second-acquired\n' >> "$lock_order"
  fp_lock_release
) &
second_pid=$!
wait "$first_pid" "$second_pid"
[ "$(sed -n '1p' "$lock_order")" = "first-acquired" ]
[ "$(sed -n '2p' "$lock_order")" = "first-releasing" ]
[ "$(sed -n '3p' "$lock_order")" = "second-acquired" ]

DEPLOY_FORCE=1
export DEPLOY_FORCE
[ "$(fp_decide public/new.php "$one_sha")" = "ALLOW" ]
fp_record public/forced.php "$TEST_TMP/one" deployed
grep -q $'\tpublic/forced.php\t.*\tdeployed\t.* FORCED$' "$DEPLOY_FINGERPRINT_LOG"

echo "deploy guard self-test: PASS"
