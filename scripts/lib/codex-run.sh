# scripts/lib/codex-run.sh — run `codex exec` and recognise "Codex usage is used up" (Tim, 2026-10-24).
# Sourced by codex-review.sh, codex-build.sh and codex-web-build.sh.
#
#   codex_run <logfile> <codex exec args...>
#     → 0 when Codex ran; 75 (CODEX_LIMIT_RC) when Codex is out of usage; Codex's own code otherwise.
#
# On a limit it writes ~/.mowology-codex/LIMIT (when, which script, the message) and prints "CODEX LIMIT".
# A chat that sees exit 75 or a LIMIT file under CODEX_LIMIT_FRESH_MIN minutes old sends the job to the matching
# Claude agent instead (.claude/agents/: web-builder, code-reviewer, researcher) — see ~/.claude/CLAUDE.md.
#
# Detection reads only Codex's structured error events (--json) and its non-JSON error lines — never the agent's
# own messages, which can contain "429" inside code diffs (the Android logs do). Limit wording has not been seen on
# this Mac yet (2026-10-24): the patterns cover the common forms, and any failed run with an unrecognised error is
# logged to ~/.mowology-codex/limit-unknown.log so the first real limit can tighten them.

CODEX_LIMIT_RC=75
CODEX_STATE_DIR="${CODEX_STATE_DIR:-$HOME/.mowology-codex}"
CODEX_LIMIT_FILE="$CODEX_STATE_DIR/LIMIT"
CODEX_LIMIT_FRESH_MIN="${CODEX_LIMIT_FRESH_MIN:-60}"

# 0 when a LIMIT note is younger than CODEX_LIMIT_FRESH_MIN minutes (skip Codex; it would only fail again).
codex_limit_fresh() {
  [ -f "$CODEX_LIMIT_FILE" ] || return 1
  [ -n "$(find "$CODEX_LIMIT_FILE" -mmin -"$CODEX_LIMIT_FRESH_MIN" 2>/dev/null)" ]
}

# Pure: reads a codex --json log on stdin; prints the limit message and exits 0 if the run failed on usage/rate limits.
# Exits 1 for no error at all, 2 for an error that is not a limit (its text printed for the unknown log).
codex_limit_scan() {
  python3 -c '
import json, re, sys
LIMIT = re.compile(r"usage limit|rate[ _-]?limit|quota|too many requests|hit your (usage )?limit|insufficient_quota|\b429\b|try again (in|at|later)", re.I)
errors = []
for line in sys.stdin:
    line = line.strip()
    if not line:
        continue
    try:
        ev = json.loads(line)
    except ValueError:
        # stderr from the CLI itself (not the agent): only error-looking lines count
        if re.search(r"error|failed|limit|quota|429", line, re.I):
            errors.append(line)
        continue
    if not isinstance(ev, dict):
        continue
    t = str(ev.get("type", "")) + " " + str((ev.get("msg") or {}).get("type", "") if isinstance(ev.get("msg"), dict) else "")
    if re.search(r"error|fail", t, re.I):
        errors.append(json.dumps(ev)[:600])
if not errors:
    sys.exit(1)
hits = [e for e in errors if LIMIT.search(e)]
print((hits or errors)[0][:600])
sys.exit(0 if hits else 2)
'
}

codex_run() {
  local log="$1"; shift
  mkdir -p "$CODEX_STATE_DIR"
  if codex_limit_fresh && [ "${CODEX_IGNORE_LIMIT:-0}" != "1" ]; then
    echo "CODEX LIMIT (noted $(stat -f '%Sm' "$CODEX_LIMIT_FILE" 2>/dev/null)) — skipped; use the matching Claude agent" | tee -a "$log" >&2
    return $CODEX_LIMIT_RC
  fi
  codex exec --json "$@" < /dev/null > "$log" 2>&1
  local rc=$?
  local msg scan
  msg=$(codex_limit_scan < "$log"); scan=$?
  # Only a run that FAILED counts: a brief rate limit that Codex retried through is not "used up".
  if [ $scan -eq 0 ] && { [ $rc -ne 0 ] || grep -q '"turn.failed"\|"turn_failed"' "$log"; }; then
    printf 'when: %s\nscript: %s\nmessage: %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "${CODEX_CALLER:-$0}" "$msg" > "$CODEX_LIMIT_FILE"
    echo "CODEX LIMIT — $msg" >&2
    return $CODEX_LIMIT_RC
  fi
  if [ $rc -ne 0 ] || [ $scan -eq 2 ]; then
    printf '%s %s rc=%s %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "${CODEX_CALLER:-$0}" "$rc" "$msg" >> "$CODEX_STATE_DIR/limit-unknown.log"
  fi
  # A clean run clears an old note: Codex has usage again.
  [ $rc -eq 0 ] && [ $scan -eq 1 ] && rm -f "$CODEX_LIMIT_FILE"
  return $rc
}
