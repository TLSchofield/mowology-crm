#!/usr/bin/env bash
# =============================================================================
# scripts/codex-ios-build.sh — Codex AUDITS or REFACTORS the iPhone app; a second Codex reviews; the Swift
# checks decide; Claude reviews the real-file diff before anything ships.
# =============================================================================
# Tim, 2026-11-11: "have codex begin reviewing and refactoring the ios build ... you are the senior engineer".
# Same shape as codex-web-build.sh: Codex only ever sees redacted code copies, never commits, pushes, deploys or
# touches Xcode Cloud / TestFlight, and never runs prod-run.
#
# Usage: scripts/codex-ios-build.sh audit <name> <task-file> --scope <path> ... [--inputs <file> ...]
#        scripts/codex-ios-build.sh build <name> <task-file> --scope <path> ... [--inputs <file> ...]
#        scripts/codex-ios-build.sh --clean <name>
#   audit: ONE read-only Codex call; findings in $OUT/audit.md (nothing is changed).
#   build: Codex edits the redacted copy; the diff is applied to the scratch worktree; checks run there:
#          1. python3 ios/LogicTests/test_swift_bridge.py + bash ios/LogicTests/run.sh   (seconds)
#          2. whole-app typecheck with the iOS 17 simulator SDK                          (about 5 minutes)
#          then a fresh read-only Codex reviews the diff; real findings are fixed (2 rounds).
#   --scope  files/folders under the repo root Codex may read and change (Swift, .sh, .py only are copied).
#   --inputs extra read-only reference files (a checklist, a spec) copied to .codex-inputs/ — never part of the patch.
# Run it OUTSIDE the Claude sandbox (xcrun / swiftc need the Xcode toolchain).
# Not done here, by design: xcodebuild, the simulator, new-file registration in the Xcode project
# (python3 ios/add_swift_file.py, by Claude), TestFlight. Codex cannot see the screen: Claude runs the
# simulator and ios/tools/bar-smoothness.py.
# Exit: 0 checks + review pass · 2 checks/rejects remain · 3 safety/setup stop · 75 Codex limit.
# =============================================================================
set -uo pipefail

usage() { echo "usage: codex-ios-build.sh audit|build <name> <task-file> --scope <path> ... [--inputs <file> ...] | --clean <name>" >&2; exit 3; }
[ $# -ge 1 ] || usage
REPO="$(git rev-parse --show-toplevel 2>/dev/null)" || { echo "not inside a git worktree" >&2; exit 3; }

if [ "$1" = "--clean" ]; then
  [ $# -eq 2 ] || usage
  case "$2" in ""|*/*|.|..) echo "unsafe build name: $2" >&2; exit 3;; esac
  rm -rf "$HOME/.mowology-codex/ios-builds/$2"; echo "removed $HOME/.mowology-codex/ios-builds/$2"; exit 0
fi

[ $# -ge 3 ] || usage
MODE="$1"; NAME="$2"; TASK="$3"; shift 3
case "$MODE" in audit|build) ;; *) usage ;; esac
case "$NAME" in ""|*/*|.|..) echo "unsafe build name: $NAME" >&2; exit 3;; esac
SCOPE=(); INPUTS=(); mode=""
while [ $# -gt 0 ]; do
  case "$1" in
    --scope) mode=scope ;;
    --inputs) mode=inputs ;;
    /*|../*|*/../*|*/..) [ "$mode" = inputs ] && INPUTS+=("$1") || { echo "unsafe path: $1" >&2; exit 3; } ;;
    *) case "$mode" in scope) SCOPE+=("$1") ;; inputs) INPUTS+=("$1") ;; *) usage ;; esac ;;
  esac
  shift
done
[ ${#SCOPE[@]} -gt 0 ] || { echo "give --scope" >&2; exit 3; }
[ -f "$TASK" ] || { echo "no task file: $TASK" >&2; exit 3; }
TASK="$(cd "$(dirname "$TASK")" && pwd)/$(basename "$TASK")"
command -v codex >/dev/null || { echo "codex CLI not installed" >&2; exit 3; }
command -v swiftc >/dev/null || { echo "swiftc (Xcode toolchain) not found" >&2; exit 3; }
SDK="$(xcrun --sdk iphonesimulator --show-sdk-path 2>/dev/null)" || true
[ -n "${SDK:-}" ] || { echo "no iphonesimulator SDK: run this outside the Claude sandbox" >&2; exit 3; }
[ -x "$REPO/scripts/prod-run.sh" ] || { echo "missing scripts/prod-run.sh (the redaction fetches client names)" >&2; exit 3; }

OUT="$HOME/.mowology-codex/ios-builds/$NAME"
REAL="$HOME/.mowology-codex/ios-builds/_real"; COPY="$OUT/codex"
LOCK="$HOME/.mowology-codex/ios-builds/.lock"
[ ! -e "$OUT" ] || { echo "$OUT already exists; use --clean $NAME first" >&2; exit 3; }
mkdir -p "$OUT" "$HOME/.mowology-codex/ios-builds"
log() { echo "[$(date '+%H:%M:%S')] $*" | tee -a "$OUT/run.log"; }

. "$REPO/scripts/lib/codex-run.sh"
. "$REPO/scripts/lib/codex-sanitize.sh"

mkdir "$LOCK" 2>/dev/null || { log "another codex-ios-build run is active ($LOCK)"; exit 3; }
trap 'rmdir "$LOCK" 2>/dev/null' EXIT

# one reusable scratch worktree, reset per run
if [ -e "$REAL/.git" ]; then
  git -C "$REAL" reset -q --hard && git -C "$REAL" clean -qfd \
    && git -C "$REAL" checkout -q --detach "$(git -C "$REPO" rev-parse HEAD)" >> "$OUT/run.log" 2>&1 \
    && git -C "$REAL" reset -q --hard && git -C "$REAL" clean -qfd || { log "could not reset $REAL"; exit 3; }
else
  git -C "$REPO" worktree add --detach "$REAL" HEAD >> "$OUT/run.log" 2>&1 || { log "could not create worktree"; exit 3; }
fi
BASE="$(git -C "$REAL" rev-parse HEAD)"

copy_swift_tree() { # <source> <destination> <scope path> ...
  local src="$1" dest="$2" top f rel base n=0; shift 2
  for top in "$@"; do
    [ -e "$src/$top" ] || continue
    while IFS= read -r f; do
      rel="${f#"$src"/}"; base="${f##*/}"
      case "/$rel/" in */build/*|*/DerivedData/*|*/Pods/*|*.xcodeproj/*|*/Assets.xcassets/*) continue ;; esac
      case "$base" in *.swift|*.sh|*.py) ;; CLAUDE.md) ;; *) continue ;; esac
      mkdir -p "$dest/$(dirname "$rel")"; cp "$f" "$dest/$rel"; n=$((n + 1))
    done < <(find "$src/$top" -type f -print)
  done
  echo "$n"
}

mkdir -p "$COPY"
copied="$(copy_swift_tree "$REAL" "$COPY" "${SCOPE[@]}" ios/CLAUDE.md)"
[ "$copied" -gt 0 ] || { log "nothing copied — nothing sent"; exit 3; }
export SANITIZE_REPO="$REPO"
sanitize_names "$COPY" || exit 3
sanitize_secret_scan "$COPY" || exit 3
mkdir -p "$COPY/.codex-inputs"
for f in "${INPUTS[@]:-}"; do [ -n "$f" ] && [ -f "$f" ] && cp "$f" "$COPY/.codex-inputs/$(basename "$f")"; done
git -C "$COPY" init -q
printf '.codex-inputs/\n' >> "$COPY/.git/info/exclude"
git -C "$COPY" add -A
git -C "$COPY" -c user.name="Codex ios build" -c user.email="codex@mowology.local" commit -q -m baseline
CODEX_BASE="$(git -C "$COPY" rev-parse HEAD)"

RULES="NON-INTERACTIVE. Mowology/Ittai iPhone app: SwiftUI, minimum iOS 17.0, Swift 5.9 (Xcode 15.2: no iOS 18 or Swift 6 APIs, no macros beyond what 5.9 has). Read ios/CLAUDE.md first. Follow the Swift API Design Guidelines and idiomatic SwiftUI (value types, @State/@Binding/@Observable only where needed, no force unwraps or try! outside tests, no retain cycles, main-actor correctness, structured concurrency). Respect Dynamic Type, VoiceOver, Reduce Motion, Large Content Viewer, right-to-left and every size class. Design tokens come from ItTokens (generated from docs/design/ittai-v1.2/tokens.json): never hard-code a colour or a metric that has a token. No client names, no secrets, no new dependencies, no new files unless the task says so (new files must be registered in the Xcode project by Claude), never change project.yml, Info.plist, entitlements or signing. You cannot run the app: reason about behaviour from the code, and keep tests (ios/LogicTests) for every pure rule. Behaviour you cannot prove unchanged must not change."

codex_call() { # <json-log> <codex args...>
  local logfile="$1" rc=0; shift
  CODEX_CALLER="codex-ios-build.sh $NAME" codex_run "$OUT/$logfile" "$@" || rc=$?
  cat "$OUT/$logfile" >> "$OUT/codex.log"
  if [ "$rc" -eq "$CODEX_LIMIT_RC" ]; then log "CODEX LIMIT — hand to the code-reviewer / web-builder agent"; exit 75; fi
  [ "$rc" -eq 0 ] || { log "codex failed (rc=$rc)"; exit 2; }
}

if [ "$MODE" = audit ]; then
  log "audit ($copied files) — read-only"
  codex_call last-audit.jsonl --sandbox read-only --skip-git-repo-check --ephemeral -C "$COPY" -o "$OUT/audit.md" "$RULES

You are the AUDITOR. Change nothing. Read the code in scope and the reference files in .codex-inputs/. Report REAL findings only, each with file:line, what is wrong, a concrete failure or future-proofing scenario, and the smallest fix; rank High / Medium / Low; say what you checked and found fine. End with 'VERDICT: CLEAN' or 'VERDICT: FIX'.

TASK:
$(cat "$TASK")"
  log "audit: $OUT/audit.md"
  exit 0
fi

make_diff_apply() {
  git -C "$COPY" add -A -N
  git -C "$COPY" diff "$CODEX_BASE" -- . ':!.codex-inputs' > "$OUT/codex.diff"
  git -C "$REAL" reset --hard "$BASE" >/dev/null
  git -C "$REAL" clean -fd >/dev/null
  find "$REAL" -name '*.rej' -type f -delete
  (cd "$REAL" && git apply --reject --whitespace=nowarn "$OUT/codex.diff") >> "$OUT/apply.log" 2>&1 || true
  find "$REAL" -name '*.rej' -type f -print | sed "s#^$REAL/##" > "$OUT/REJECTED.txt"
}

TEST_ROUNDS=0; LAST_SUMMARY="not run"
run_checks() {
  TEST_ROUNDS=$((TEST_ROUNDS + 1)); local output="$OUT/check-$TEST_ROUNDS.txt" rc=0
  : > "$output"
  (cd "$REAL" && python3 -B ios/LogicTests/test_swift_bridge.py && bash ios/LogicTests/run.sh) >> "$output" 2>&1 || rc=$?
  if [ "$rc" -ne 0 ]; then LAST_SUMMARY="logic tests FAILED"; log "checks round $TEST_ROUNDS: $LAST_SUMMARY"; return 1; fi
  log "checks round $TEST_ROUNDS: logic tests pass; typechecking the whole app (about 5 minutes)"
  (cd "$REAL" && swiftc -typecheck -suppress-warnings -sdk "$SDK" -target arm64-apple-ios17.0-simulator $(find ios/MowologyCRM/MowologyCRM -name '*.swift')) >> "$output" 2>&1 || rc=$?
  if [ "$rc" -ne 0 ]; then LAST_SUMMARY="typecheck FAILED"; log "checks round $TEST_ROUNDS: $LAST_SUMMARY"; return 1; fi
  LAST_SUMMARY="logic tests + whole-app typecheck pass"; log "checks round $TEST_ROUNDS: $LAST_SUMMARY"; return 0
}

log "build ($copied files)"
codex_call last-run.jsonl --sandbox workspace-write --skip-git-repo-check --ephemeral -C "$COPY" -o "$OUT/build-summary.md" "$RULES

TASK:
$(cat "$TASK")"

CHECK_OK=1
for round in 1 2 3; do
  make_diff_apply
  if run_checks; then CHECK_OK=0; break; fi
  [ "$round" -eq 3 ] && break
  codex_call last-fix.jsonl --sandbox workspace-write --skip-git-repo-check --ephemeral -C "$COPY" -o "$OUT/fix-check-$round.md" "$RULES

The checks FAILED after your change. Fix the cause, never by deleting or weakening tests. Last 120 lines:
$(tail -120 "$OUT/check-$TEST_ROUNDS.txt")"
done

REVIEW_VERDICT="NOT RUN"
[ -s "$OUT/codex.diff" ] || { REVIEW_VERDICT="NOT RUN (Codex made no change — see build-summary.md)"; log "Codex made NO change"; }
if [ "$CHECK_OK" -eq 0 ] && [ -s "$OUT/codex.diff" ]; then
  cp "$OUT/codex.diff" "$COPY/.codex-inputs/change.diff"
  codex_call last-review.jsonl --sandbox read-only --skip-git-repo-check --ephemeral -C "$COPY" -o "$OUT/review.md" "$RULES

You are a strict, independent REVIEWER of .codex-inputs/change.diff against this task:
$(cat "$TASK")

Find REAL defects only: a behaviour change the task did not ask for, a regression in animation/gesture/accessibility, a crash or force-unwrap, a retain cycle, main-actor misuse, a hard-coded token value, a missing fallback, a weakened test. File:line and a concrete scenario for each; High / Medium / Low. End with 'VERDICT: CLEAN' or 'VERDICT: FIX'."
  REVIEW_VERDICT="$(grep -E 'VERDICT: (CLEAN|FIX)' "$OUT/review.md" | tail -1 || true)"
  [ -n "$REVIEW_VERDICT" ] || REVIEW_VERDICT="VERDICT: FIX (missing verdict)"
  if grep -qiE '\b(High|Medium)\b' "$OUT/review.md" && ! grep -q 'VERDICT: CLEAN' "$OUT/review.md"; then
    codex_call last-fix-review.jsonl --sandbox workspace-write --skip-git-repo-check --ephemeral -C "$COPY" -o "$OUT/fix-review.md" "$RULES

An independent reviewer found the following. Check each against the code, fix the real findings, briefly reject false positives. Keep tests strong.
$(cat "$OUT/review.md")"
    CHECK_OK=1
    make_diff_apply
    if run_checks; then CHECK_OK=0; REVIEW_VERDICT="VERDICT: FIXED"; fi
  fi
fi

make_diff_apply
git -C "$REAL" add -A -N
git -C "$REAL" diff "$BASE" > "$OUT/change.diff"
git -C "$REAL" diff "$BASE" --stat > "$OUT/diffstat.txt"
rejected_files="$(grep -c . "$OUT/REJECTED.txt" 2>/dev/null || true)"
cat > "$OUT/REPORT.md" <<EOF
# Codex iOS build: $NAME

- Check rounds: $TEST_ROUNDS
- Checks: $LAST_SUMMARY
- Review: $REVIEW_VERDICT
- Rejected hunks files: ${rejected_files:-0}
- Real worktree: $REAL
- Real-file diff: $OUT/change.diff
- Diffstat: $OUT/diffstat.txt
- Review: $OUT/review.md
EOF
log "result: $OUT/REPORT.md"
echo "The patched files are in $REAL until the next run resets it. Claude reviews the diff, registers any new file, and runs the simulator."
if [ "$CHECK_OK" -ne 0 ] || [ "${rejected_files:-0}" -ne 0 ] || { [ "$REVIEW_VERDICT" != "VERDICT: CLEAN" ] && [ "$REVIEW_VERDICT" != "VERDICT: FIXED" ]; }; then exit 2; fi
exit 0
