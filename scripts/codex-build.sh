#!/usr/bin/env bash
# =============================================================================
# scripts/codex-build.sh — Codex WRITES a change to the crew Android app, a second Codex reviews it,
# the first fixes what's real, and the tests decide. Claude reviews the result before anything ships.
# =============================================================================
# Tim, 2026-10-16: "have codex code the entire app for native then you review it, have it review its own
# code". Claude's allowance was nearly used up for the week; Codex runs on the ChatGPT plan.
#
# Where it works: ~/Projects/mowology-android-codex — a git worktree of mowology-android on branch
# codex/native, with a DUMMY google-services.json (skip-worktree; the real Firebase keys never go to
# Codex). Nothing here reaches a phone: Claude reviews the branch, then a test APK goes to Nigel.
#
#   scripts/codex-build.sh <name> <task-file> [<reference-dir>]
#
#   1. build   codex exec --sandbox workspace-write in the worktree (no network: it cannot run gradle)
#   2. test    this script runs the unit tests + a debug compile, offline; failures go back (2 rounds max)
#   3. review  a fresh, read-only Codex reviews `git diff` against the start → findings
#   4. fix     the builder checks each finding against the code and fixes the real ones; tests again
#   5. commit  on codex/native, with Codex's own summary; report → ~/.mowology-codex/builds/<name>/
#
# <reference-dir>: a client-names-removed copy of CRM server code (made by codex-review.sh) that Codex
# may READ to learn what the server expects. Codex is told to read nothing else outside the worktree.
# Exit codes: 0 committed · 2 tests still failing (left uncommitted for Claude) · 3 bad setup.
# =============================================================================
set -uo pipefail

NAME="${1:?usage: codex-build.sh <name> <task-file> [<reference-dir>]}"
TASK="${2:?task file}"
REF="${3:-}"
WT="$HOME/Projects/mowology-android-codex"
OUT="$HOME/.mowology-codex/builds/$NAME"
export JAVA_HOME="${JAVA_HOME:-/Users/timschofield/.jdks/jdk-17.0.18+8/Contents/Home}"
export ANDROID_HOME="${ANDROID_HOME:-/Users/timschofield/Library/Android/sdk}"
mkdir -p "$OUT"
log() { echo "[$(date '+%H:%M:%S')] $*" | tee -a "$OUT/run.log"; }

[ -d "$WT/.git" ] || [ -f "$WT/.git" ] || { log "no worktree at $WT"; exit 3; }
[ -f "$TASK" ] || { log "no task file $TASK"; exit 3; }
cd "$WT"
[ "$(git branch --show-current)" = "codex/native" ] || { log "worktree is not on codex/native"; exit 3; }
if [ -n "$REF" ]; then rm -rf .codex-inputs/server-code && mkdir -p .codex-inputs && cp -R "$REF" .codex-inputs/server-code; fi
if [ -n "$(git status --porcelain)" ]; then log "worktree has uncommitted changes — Claude must look first"; exit 3; fi
grep -q "AIzaDUMMY" android/app/google-services.json || { log "google-services.json is not the dummy — refusing"; exit 3; }
BASE="$(git rev-parse HEAD)"
log "start $NAME at $BASE"

RULES="You are working on the Mowology crew Android app (Kotlin + a Capacitor shell). Repo root: $WT (Android project in android/).
Rules:
- Edit ONLY files inside $WT. Never touch android/app/google-services.json, signing, or anything under node_modules.
- You may READ anything in $WT. Your inputs (review files, server reference, client-names-removed server code) are in
  $WT/.codex-inputs/ — read them, never edit them. Read nothing outside $WT.
- There is no network and you cannot run gradle; this script runs the tests after you. Write code that compiles: check imports,
  types and Android API levels (minSdk in android/variables.gradle) by reading the code, not by guessing.
- Match the existing code style (see the camera/, receipts/, plants/ and day/ packages). New behaviour goes behind a switch
  read from app-version.json the way NativeReceiptsSwitch / NativePlantsSwitch do, default OFF, unless the task says otherwise.
- Pure logic goes in a *Logic.kt object with JUnit tests in android/app/src/test (the existing *LogicTest.kt are the pattern).
- Offline first: anything a crew member creates is saved on the phone first and sent with retries (see ReceiptQueue/ReceiptUploadWorker).
- Never log passwords, tokens or cookies. Never put secrets in code.
- Bump nothing (versionCode/versionName), publish nothing. Do not run git commit; this script commits.
- Finish with a short summary: what you changed, file by file, what you could not do, and anything Claude must check by hand."

gradle_check() {   # → $OUT/test-<n>.txt; returns 0 when tests + compile pass
  local n="$1"
  ( ./android/gradlew -p android --offline testDebugUnitTest compileDebugKotlin -q > "$OUT/test-$n.txt" 2>&1 )
  local rc=$?
  local res; res="$(grep -ho 'tests="[0-9]*" skipped="[0-9]*" failures="[0-9]*" errors="[0-9]*"' android/app/build/test-results/testDebugUnitTest/*.xml 2>/dev/null \
                   | awk -F'"' '{t+=$2; f+=$6+$8} END {print t" tests, "f" failing"}')"
  log "tests/compile round $n: rc=$rc ${res:-no results}"
  return $rc
}

# Codex usage used up mid-build (scripts/lib/codex-run.sh exit 75): stop, leave the worktree for Claude, and say so —
# the chat hands the rest to the web-builder / code-reviewer Claude agents.
. "$(dirname "$0")/lib/codex-run.sh"
codex_call() {     # <logname> <codex exec args...>
  local rc=0
  CODEX_CALLER="codex-build.sh $NAME" codex_run "$OUT/$1" "${@:2}" || rc=$?
  cat "$OUT/$1" >> "$OUT/codex.log"
  if [ "$rc" = "$CODEX_LIMIT_RC" ]; then log "CODEX LIMIT — stopped; worktree left for Claude (hand the rest to a Claude agent)"; exit "$rc"; fi
  return 0
}
codex_write() {    # <prompt> <outfile>
  codex_call last-run.jsonl --sandbox workspace-write --skip-git-repo-check --ephemeral -C "$WT" -o "$2" "$1"
}

# 1. build
log "build"
codex_write "$RULES

TASK:
$(cat "$TASK")" "$OUT/build-summary.md"

# 2. test, feeding failures back
ok=1
for round in 1 2 3; do
  if gradle_check "$round"; then ok=0; break; fi
  [ "$round" = 3 ] && break
  log "fixing build/test failures (round $round)"
  codex_write "$RULES

The unit tests or the Kotlin compile FAILED after your change. Fix the cause (not by deleting or weakening tests).
Output (last 120 lines):
$(tail -120 "$OUT/test-$round.txt")" "$OUT/fix-build-$round.md"
done

# 3. independent review of the diff
git add -A -N .
git diff "$BASE" > "$OUT/change.diff"
if [ ! -s "$OUT/change.diff" ]; then log "Codex made NO change — see build-summary.md"; exit 2; fi
cp "$OUT/change.diff" .codex-inputs/change.diff
log "review ($(wc -l < "$OUT/change.diff") diff lines)"
codex_call last-review.jsonl --sandbox read-only --skip-git-repo-check --ephemeral -C "$WT" -o "$OUT/review.md" "You are a strict, independent reviewer.
Another engineer made the change in .codex-inputs/change.diff to the Mowology crew Android app at $WT (read any file you need there; inputs in .codex-inputs/).
The task they were given:
$(cat "$TASK")

Find REAL defects only: data lost or sent twice (offline, retries, app killed, phone restarted), crashes, wrong user on a shared tablet,
sign-in/token handling, permissions, privacy, battery, threading/lifecycle, Android API 26-35 differences, wrong data sent to the server,
behaviour not behind its switch, tests that don't test the claim, and parts of the task not done.
For each: file:line, a concrete scenario, High/Medium/Low. End with the line VERDICT: CLEAN or VERDICT: FIX."

# 4. fix what's real
if grep -qiE "\b(High|Medium)\b" "$OUT/review.md" 2>/dev/null && ! grep -q "VERDICT: CLEAN" "$OUT/review.md"; then
  log "fixing review findings"
  codex_write "$RULES

An independent reviewer found the following in your change. For EACH finding: read the code and decide if it is real.
Fix the real ones; for the others, say in one line why not. Keep the tests passing.

$(cat "$OUT/review.md")" "$OUT/fix-review.md"
  ok=1
  for round in 4 5; do
    if gradle_check "$round"; then ok=0; break; fi
    [ "$round" = 5 ] && break
    codex_write "$RULES

Tests or compile FAILED after the review fixes. Fix the cause.
$(tail -120 "$OUT/test-$round.txt")" "$OUT/fix-build-$round.md"
  done
fi

git diff "$BASE" --stat > "$OUT/diffstat.txt"
git diff "$BASE" > "$OUT/change.diff"
if [ "$ok" != 0 ]; then
  log "TESTS STILL FAILING — left uncommitted for Claude"
  exit 2
fi
git add -A
git -c user.name="Codex (via codex-build.sh)" -c user.email="codex@mowology.local" commit -q -F - <<MSG
codex($NAME): $(head -c 180 "$OUT/build-summary.md" | tr '\n' ' ')

Written by Codex, reviewed by a separate Codex run, tests passing. NOT yet reviewed by Claude.
Report: ~/.mowology-codex/builds/$NAME/
MSG
log "committed $(git rev-parse --short HEAD) — $(tail -1 "$OUT/diffstat.txt")"
exit 0
