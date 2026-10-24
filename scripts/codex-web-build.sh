#!/usr/bin/env bash
# =============================================================================
# scripts/codex-web-build.sh — Codex WRITES web changes, a second Codex reviews them,
# tests decide, and Claude reviews the resulting real-file diff before anything ships.
# =============================================================================
# Tim, 2026-10-24: "utilize codex as much as possible due to usage". This creates isolated,
# client-redacted worktrees only; it never commits to the real repo, pushes, deploys, or runs prod-run.
#
# Usage: scripts/codex-web-build.sh <name> <task-file> --scope <path> ... [--live <repo-path> ...]
#        scripts/codex-web-build.sh --clean <name>
#   --scope: the files/folders Codex may read and change (only these are copied, redacted and scanned — copying the
#            whole repo was slow, filled the disk and tripped the secret scan on test fixtures, 2026-10-24).
#            tests/bootstrap.php and phpunit.xml* are always added. The full suite still runs on the real tree.
# Exit: 0 tests/review pass · 2 tests/rejects remain · 3 safety/setup stop · 75 Codex limit.
# =============================================================================
set -uo pipefail

copy_code_tree() { # <source> <destination> [<scope path> ...] (default scope: app public tests scripts)
  local src="$1" dest="$2" top f rel base copied=0
  shift 2
  [ $# -gt 0 ] || set -- app public tests scripts
  for top in "$@"; do
    [ -e "$src/$top" ] || continue
    while IFS= read -r f; do
      rel="${f#"$src"/}"; base="${f##*/}"
      case "/$rel/" in
        */vendor/*|*/node_modules/*|*/uploads/*|*/downloads/*|*/app_config/*|*/docs/*|*/database/*) continue ;;
      esac
      case "$base" in secrets.php|*.min.js|*.min.css) continue ;; esac
      case "$base" in
        phpunit.xml*) ;;
        *.php|*.js|*.css|*.html|*.sh|*.py|*.xml|*.htaccess) ;;
        *) continue ;;
      esac
      mkdir -p "$dest/$(dirname "$rel")"
      cp "$f" "$dest/$rel"
      copied=$((copied + 1))
    done < <(find "$src/$top" -type f -print)
  done
  for f in "$src"/phpunit.xml*; do
    [ -f "$f" ] || continue
    cp "$f" "$dest/${f##*/}"
    copied=$((copied + 1))
  done
  echo "$copied"
}

extract_redacted_lines() { # <diff> <output>; added/context only, not removed lines
  awk '(/^\+\+\+/ || /^---/) { next } /^[ +]/ && index($0, "[client]") { print }' "$1" > "$2"
}

test_summary() { # <phpunit-output>
  local summary
  summary="$(grep -E '^(OK \([0-9]+ tests?|Tests: [0-9]+,)' "$1" 2>/dev/null | tail -1)"
  [ -n "$summary" ] && printf '%s\n' "$summary" || printf '%s\n' "no PHPUnit summary"
}

[ "${CODEX_WEB_BUILD_SOURCE_ONLY:-0}" = 1 ] && return 0

usage() { echo "usage: codex-web-build.sh <name> <task-file> --scope <path> ... [--live <repo-path> ...] | --clean <name>" >&2; exit 3; }
[ $# -ge 1 ] || usage
REPO="$(git rev-parse --show-toplevel 2>/dev/null)" || { echo "not inside a git worktree" >&2; exit 3; }

if [ "$1" = "--clean" ]; then
  [ $# -eq 2 ] || usage
  NAME="$2"; case "$NAME" in ""|*/*|.|..) echo "unsafe build name: $NAME" >&2; exit 3;; esac
  OUT="$HOME/.mowology-codex/web-builds/$NAME"
  rm -rf "$OUT"   # the shared scratch worktree (_real) is kept and reset by the next run
  echo "removed $OUT"
  exit 0
fi

[ $# -ge 2 ] || usage
NAME="$1"; TASK="$2"; shift 2
case "$NAME" in ""|*/*|.|..) echo "unsafe build name: $NAME" >&2; exit 3;; esac
LIVE=(); SCOPE=(); mode=""
while [ $# -gt 0 ]; do
  case "$1" in
    --live) mode=live ;;
    --scope) mode=scope ;;
    /*|../*|*/../*|*/..) echo "unsafe path: $1" >&2; exit 3 ;;
    *) case "$mode" in live) LIVE+=("$1") ;; scope) SCOPE+=("$1") ;; *) usage ;; esac ;;
  esac
  shift
done
[ ${#SCOPE[@]} -gt 0 ] || { echo "give --scope: the files/folders Codex needs (live files are added automatically)" >&2; exit 3; }
SCOPE+=(tests/bootstrap.php)
[ ${#LIVE[@]} -eq 0 ] || SCOPE+=("${LIVE[@]}")
[ -f "$TASK" ] || { echo "no task file: $TASK" >&2; exit 3; }
TASK="$(cd "$(dirname "$TASK")" && pwd)/$(basename "$TASK")"
command -v codex >/dev/null || { echo "codex CLI not installed (npm i -g @openai/codex)" >&2; exit 3; }
[ -x "$REPO/scripts/prod-run.sh" ] || { echo "missing scripts/prod-run.sh" >&2; exit 3; }
[ ${#LIVE[@]} -eq 0 ] || [ -x "$REPO/scripts/prod-pull.sh" ] || { echo "missing scripts/prod-pull.sh" >&2; exit 3; }

OUT="$HOME/.mowology-codex/web-builds/$NAME"
# One reusable scratch worktree, reset per run (a fresh 5,000-file checkout per run filled the disk, 2026-10-24); one run at a time.
REAL="$HOME/.mowology-codex/web-builds/_real"; COPY="$OUT/codex"
LOCK="$HOME/.mowology-codex/web-builds/.lock"
[ ! -e "$OUT" ] || { echo "$OUT already exists; use --clean $NAME first" >&2; exit 3; }
mkdir -p "$OUT"
log() { echo "[$(date '+%H:%M:%S')] $*" | tee -a "$OUT/run.log"; }

. "$REPO/scripts/lib/codex-run.sh"
. "$REPO/scripts/lib/codex-sanitize.sh"

mkdir "$LOCK" 2>/dev/null || { log "another codex-web-build run is active ($LOCK) — wait for it"; exit 3; }
trap 'rmdir "$LOCK" 2>/dev/null' EXIT
if [ -e "$REAL/.git" ]; then
  log "resetting the scratch worktree to HEAD"
  git -C "$REAL" checkout -q --detach "$(git -C "$REPO" rev-parse HEAD)" >> "$OUT/run.log" 2>&1 \
    && git -C "$REAL" reset -q --hard && git -C "$REAL" clean -qfd -e vendor || { log "could not reset $REAL"; exit 3; }
else
  log "creating the scratch worktree (once)"
  git -C "$REPO" worktree add --detach "$REAL" HEAD >> "$OUT/run.log" 2>&1 || { log "could not create worktree"; exit 3; }
fi
[ -d "$REPO/vendor" ] || { log "repo vendor/ is missing"; exit 3; }
[ -L "$REAL/vendor" ] || ln -s "$REPO/vendor" "$REAL/vendor"

if [ ${#LIVE[@]} -gt 0 ]; then
  log "pulling ${#LIVE[@]} live file(s)"
  pull_output="$OUT/prod-pull.txt"
  (cd "$REPO" && scripts/prod-pull.sh "${LIVE[@]}") > "$pull_output" 2>&1 || { log "prod-pull failed — nothing sent"; exit 3; }
  pull_root="${TMPDIR:-/tmp/}prod-pull"
  for p in "${LIVE[@]}"; do
    if [ -f "$pull_root/$p" ]; then mkdir -p "$REAL/$(dirname "$p")"; cp "$pull_root/$p" "$REAL/$p"; elif grep -F "PROD-MISSING" "$pull_output" | grep -Fq "$p"; then :; else log "prod-pull returned no file/status for $p"; exit 3; fi
  done
  git -C "$REAL" add -A
  git -C "$REAL" -c user.name="Codex web build" -c user.email="codex@mowology.local" commit -q --allow-empty -m "live copies"
fi
BASE="$(git -C "$REAL" rev-parse HEAD)"

mkdir -p "$COPY"
copied="$(copy_code_tree "$REAL" "$COPY" "${SCOPE[@]}")"
[ "$copied" -gt 0 ] || { log "nothing copied — nothing sent"; exit 3; }
export SANITIZE_REPO="$REPO"
sanitize_names "$COPY" || exit 3
sanitize_secret_scan "$COPY" || exit 3
git -C "$COPY" init -q
echo ".codex-inputs/" >> "$COPY/.git/info/exclude"   # the reviewer's inputs must never end up in the patch
git -C "$COPY" add -A
git -C "$COPY" -c user.name="Codex web build" -c user.email="codex@mowology.local" commit -q -m baseline
CODEX_BASE="$(git -C "$COPY" rev-parse HEAD)"

RULES="Mowology/Ittai web app; PHP 8.3 but PHP 7.4-compatible syntax; MySQL 5.7 (no window/JSON functions, no IF NOT EXISTS on indexes); prepared statements for user data; new business logic in app/Modules/<Module>/Services/ with a PHPUnit test in tests/Unit/, page/API files thin; escape output (h()), CSRF on forms; CRM CSS only in public/crm/css/mowology-brand.css or the ittai-*.css files, CSS variables, no hex, no inline <style> on AppStack pages; email/SMS only via sendCrmEmail/sendSms; multi-business: no trade words in new schema/copy, names via BusinessIdentity, files via uploadsDir(); [client] in the code is a redacted name — never edit those lines unless the task needs it, and never \"fix\" them; edit only inside the copy; no network; this script runs the tests after you; match the surrounding style; finish with a summary file by file, what you could not do, and what Claude must check by hand. The task file IS the approved plan: build it, don't ask."

codex_call() { # <json-log> <codex args...>
  local logfile="$1" rc=0; shift
  CODEX_CALLER="codex-web-build.sh $NAME" codex_run "$OUT/$logfile" "$@" || rc=$?
  cat "$OUT/$logfile" >> "$OUT/codex.log"
  if [ "$rc" -eq "$CODEX_LIMIT_RC" ]; then log "CODEX LIMIT — hand to the web-builder agent"; exit 75; fi
  [ "$rc" -eq 0 ] || { log "codex failed (rc=$rc)"; exit 2; }
}
codex_write() { local prompt="$1" summary="$2"; codex_call last-run.jsonl --sandbox workspace-write --skip-git-repo-check --ephemeral -C "$COPY" -o "$summary" "$prompt"; }

make_diff_apply() {
  git -C "$COPY" add -A -N
  git -C "$COPY" diff "$CODEX_BASE" > "$OUT/codex.diff"
  extract_redacted_lines "$OUT/codex.diff" "$OUT/REDACTED-LINES.txt"
  git -C "$REAL" reset --hard "$BASE" >/dev/null
  git -C "$REAL" clean -fd -e vendor >/dev/null
  [ -L "$REAL/vendor" ] || ln -s "$REPO/vendor" "$REAL/vendor"
  find "$REAL" -name '*.rej' -type f -delete
  (cd "$REAL" && git apply --reject --whitespace=nowarn "$OUT/codex.diff") >> "$OUT/apply.log" 2>&1 || true
  find "$REAL" -name '*.rej' -type f -print | sed "s#^$REAL/##" > "$OUT/REJECTED.txt"
}

TEST_ROUNDS=0; LAST_SUMMARY="not run"; TEST_OK=1
run_tests() {
  TEST_ROUNDS=$((TEST_ROUNDS + 1)); local output="$OUT/test-$TEST_ROUNDS.txt" rc=0 f
  while IFS= read -r f; do
    [ -f "$REAL/$f" ] || continue
    "${PHP_BIN:-/Applications/MAMP/bin/php/php8.3.30/bin/php}" -l "$REAL/$f" >> "$output" 2>&1 || rc=$?   # the same PHP the suite runs on
  done < <(cd "$REAL" && git ls-files -m -o --exclude-standard '*.php')
  if [ "$rc" -eq 0 ]; then
    (cd "$REAL" && "${PHP_BIN:-/Applications/MAMP/bin/php/php8.3.30/bin/php}" -c "${PHP_INI:-$REPO/scripts/local/php.ini}" -d "pdo_mysql.default_socket=$HOME/.mowology-local/mysql.sock" vendor/bin/phpunit) >> "$output" 2>&1 || rc=$?
  fi
  LAST_SUMMARY="$(test_summary "$output")"
  log "tests round $TEST_ROUNDS: rc=$rc $LAST_SUMMARY"
  [ "$rc" -eq 0 ] && ! grep -qE 'FAILURES!|ERRORS!' "$output"
}

log "build ($copied code files)"
codex_write "$RULES

TASK:
$(cat "$TASK")" "$OUT/build-summary.md"

for round in 1 2 3; do
  make_diff_apply
  if run_tests; then TEST_OK=0; break; fi
  [ "$round" -eq 3 ] && break
  codex_write "$RULES

The tests FAILED after your change. Fix the cause, never by deleting or weakening tests.
Last 120 lines:
$(tail -120 "$OUT/test-$TEST_ROUNDS.txt")" "$OUT/fix-test-$round.md"
done

REVIEW_VERDICT="NOT RUN"
[ -s "$OUT/codex.diff" ] || { REVIEW_VERDICT="NOT RUN (Codex made no change — see build-summary.md)"; log "Codex made NO change"; }
if [ "$TEST_OK" -eq 0 ] && [ -s "$OUT/codex.diff" ]; then
  mkdir -p "$COPY/.codex-inputs"; cp "$OUT/codex.diff" "$COPY/.codex-inputs/change.diff"
  codex_call last-review.jsonl --sandbox read-only --skip-git-repo-check --ephemeral -C "$COPY" -o "$OUT/review.md" "You are a strict, independent reviewer. Review .codex-inputs/change.diff against the code and this task:
$(cat "$TASK")

Find REAL defects only: data or money written wrong; one business's data reaching another (Tenant / BusinessIdentity / uploadsDir); XSS; SQL built from input; CSRF; Host-header trust; MySQL 5.7 incompatibility; PHP fatals that truncate pages; parts of the spec not done; and weak tests. For each give file:line, a concrete scenario, and High/Medium/Low. End with exactly VERDICT: CLEAN or VERDICT: FIX."
  REVIEW_VERDICT="$(grep -E 'VERDICT: (CLEAN|FIX)' "$OUT/review.md" | tail -1 || true)"
  [ -n "$REVIEW_VERDICT" ] || REVIEW_VERDICT="VERDICT: FIX (missing verdict)"
  if grep -qiE '\b(High|Medium)\b' "$OUT/review.md" && ! grep -q 'VERDICT: CLEAN' "$OUT/review.md"; then
    codex_write "$RULES

An independent reviewer found the following. Check each against the code, fix the real findings, and briefly reject false positives. Keep tests strong.
$(cat "$OUT/review.md")" "$OUT/fix-review.md"
    TEST_OK=1
    for round in 1 2; do
      make_diff_apply
      if run_tests; then TEST_OK=0; REVIEW_VERDICT="VERDICT: FIXED"; break; fi
      [ "$round" -eq 2 ] && break
      codex_write "$RULES

Tests FAILED after review fixes. Fix the cause without deleting or weakening tests.
$(tail -120 "$OUT/test-$TEST_ROUNDS.txt")" "$OUT/fix-review-test-$round.md"
    done
  fi
fi

make_diff_apply
git -C "$REAL" add -A -N   # new files too: a plain diff skips untracked files (the deploy-guard run lost its two new files, 2026-10-24)
git -C "$REAL" diff "$BASE" > "$OUT/change.diff"
git -C "$REAL" diff "$BASE" --stat > "$OUT/diffstat.txt"
redacted_count="$(wc -l < "$OUT/REDACTED-LINES.txt" | tr -d ' ')"
rejected_count=0
rejected_files=0
while IFS= read -r rejected; do
  [ -n "$rejected" ] || continue
  rejected_files=$((rejected_files + 1))
  count="$(grep -c '^@@' "$REAL/$rejected" 2>/dev/null || true)"
  rejected_count=$((rejected_count + count))
done < "$OUT/REJECTED.txt"
cat > "$OUT/REPORT.md" <<EOF
# Codex web build: $NAME

- Test rounds: $TEST_ROUNDS
- Test summary: $LAST_SUMMARY
- Review: $REVIEW_VERDICT
- Redacted diff lines: $redacted_count
- Rejected hunks: $rejected_count
- Real worktree: $REAL
- Codex diff: $OUT/codex.diff
- Real-file diff: $OUT/change.diff
- Diffstat: $OUT/diffstat.txt
- Review: $OUT/review.md
- Rejected list: $OUT/REJECTED.txt
EOF

log "result: $OUT/REPORT.md"
echo "The patched files are in $REAL until the next run resets it."
if [ "$TEST_OK" -ne 0 ] || [ "$rejected_files" -ne 0 ] || { [ "$REVIEW_VERDICT" != "VERDICT: CLEAN" ] && [ "$REVIEW_VERDICT" != "VERDICT: FIXED" ]; }; then exit 2; fi
exit 0
