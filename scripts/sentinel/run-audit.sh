#!/usr/bin/env bash
# run-audit.sh — Sentinel's daily / weekly product audit (read-only). Usage: run-audit.sh daily|weekly
# Steps: lock -> code snapshot (code only) -> native shots (optional) -> agent (claude -p, read-only tools, budget cap)
#        -> validate + dedupe against the ledger (sentinel.py ingest) -> report + backlog + notification.
# Never edits the repo, deploys, signs in, or reads customer data. Failures name their cause in $STATE/runs.log.
# Test hooks: SENTINEL_CLAUDE_BIN (stand-in for the CLI), SENTINEL_HOME, SENTINEL_REPO, SENTINEL_SKIP_NATIVE=1.
set -uo pipefail
KIND="${1:-daily}"; case "$KIND" in daily|weekly) ;; *) echo "usage: run-audit.sh daily|weekly" >&2; exit 64;; esac
export PATH="$HOME/.npm-global/bin:/opt/homebrew/bin:/usr/local/bin:/usr/bin:/bin:$PATH"
HERE="$(cd "$(dirname "$0")" && pwd)"
STATE="${SENTINEL_HOME:-$HOME/.mowology-sentinel}"; export SENTINEL_HOME="$STATE"
REPO="${SENTINEL_REPO:-$HOME/Projects/mowology-crm}"
DATE="$(date +%F)"; RUN="$STATE/reports/$DATE"; SRC="$STATE/src"; LOG="$STATE/runs.log"
mkdir -p "$STATE" "$RUN/shots" "$RUN/native"
log() { echo "[$(date '+%F %T')] $KIND: $*" | tee -a "$LOG"; }
fail() { log "FAILED — $1"; notify "Sentinel $KIND audit failed: $1"; exit "${2:-1}"; }
notify() { /usr/bin/osascript -e "display notification \"$1\" with title \"Sentinel\"" >/dev/null 2>&1 || true; }
sentinel() { python3 "$HERE/sentinel.py" "$@"; }

# 1. lock (a stale lock older than 3 h is ignored)
LOCK="$STATE/run.lock"
if [ -d "$LOCK" ] && [ -z "$(find "$LOCK" -maxdepth 0 -mmin +180 2>/dev/null)" ]; then log "another audit is running — skipped"; exit 0; fi
rm -rf "$LOCK"; mkdir "$LOCK" || fail "cannot take lock"; trap 'rm -rf "$LOCK"' EXIT
sentinel init >/dev/null || fail "cannot create state folders"

# 2. the Claude CLI (bundled in the desktop app; same lookup as ~/.claude/bin/claude-rc.sh)
CLAUDE="${SENTINEL_CLAUDE_BIN:-$(find "$HOME/Library/Application Support/Claude/claude-code" -path '*claude.app/Contents/MacOS/claude' 2>/dev/null | sort -V | tail -1)}"
[ -x "${CLAUDE:-}" ] || CLAUDE="$(command -v claude || true)"
[ -x "${CLAUDE:-}" ] || fail "no Claude CLI found"
if [ -z "${SENTINEL_CLAUDE_BIN:-}" ] && ! "$CLAUDE" auth status 2>/dev/null | grep -q '"loggedIn": true'; then
  fail "Claude CLI is not signed in — run once in a terminal: \"$CLAUDE\" auth login" 75
fi

# 3. code snapshot: tracked code + design tokens only (no docs, tests, data, secrets) from the tip of feature/language
TARGETS="$HERE/targets.json"
PATHS="$(python3 -c "import json;print(' '.join(json.load(open('$TARGETS'))['code_snapshot_paths']))")"
if git -C "$REPO" rev-parse --verify -q origin/feature/language >/dev/null; then
  git -C "$REPO" fetch -q origin feature/language 2>/dev/null || true
  rm -rf "$SRC"; mkdir -p "$SRC"
  # shellcheck disable=SC2086
  git -C "$REPO" archive origin/feature/language -- $PATHS 2>/dev/null | tar -x -C "$SRC" || log "snapshot partial"
  # binaries and anything big are not code: the audit sees the live pages for visuals
  find "$SRC" -type f \( -size +400k -o -iname '*.png' -o -iname '*.jpg' -o -iname '*.jpeg' -o -iname '*.webp' -o -iname '*.gif' -o -iname '*.mp4' -o -iname '*.mov' -o -iname '*.pdf' -o -iname '*.ttf' -o -iname '*.otf' -o -iname '*.woff*' -o -iname '*.zip' -o -iname '*.a' -o -iname '*.xcframework' \) -delete 2>/dev/null
  find "$SRC" \( -name '*.env' -o -name 'secrets*.php' -o -name '*.pem' -o -name '*.p8' -o -name '*.sql' -o -name '*.sqlite' \) -delete 2>/dev/null
else
  log "no origin/feature/language in $REPO — code audit skipped"; mkdir -p "$SRC"
fi
# agent + skill: the installed copy (bin/agent) wins, else the repo checkout this script sits in
AG="$HERE/agent"; [ -f "$AG/agents/sentinel.md" ] || AG="$HERE/../../.claude"
[ -f "$AG/agents/sentinel.md" ] || [ -f "$AG/sentinel.md" ] || fail "sentinel agent file not found"
mkdir -p "$SRC/.claude/agents" "$SRC/.claude/skills"
cp "$AG/agents/sentinel.md" "$SRC/.claude/agents/"
cp -R "$AG/skills/sentinel-product-audit" "$SRC/.claude/skills/" 2>/dev/null
cp "$TARGETS" "$SRC/targets.json"

# 4. native screenshots when a simulator / emulator is running (never required)
[ "${SENTINEL_SKIP_NATIVE:-0}" = "1" ] || bash "$HERE/native-shots.sh" "$RUN/native" >>"$LOG" 2>&1 || log "native shots skipped"

# 5. browser tools: Playwright (screenshots land in the report folder) + Chrome DevTools (Lighthouse), nothing else
MCP="$RUN/mcp.json"
cat >"$MCP" <<JSON
{"mcpServers":{
 "playwright":{"command":"npx","args":["-y","@playwright/mcp@latest","--headless","--isolated","--output-dir","$RUN/shots"]},
 "chrome-devtools":{"command":"npx","args":["-y","chrome-devtools-mcp@latest","--headless","--isolated"]}}}
JSON

# 6. permissions come from registry.json, so the file — not this script — is the one place to change them
python3 - "$HERE/registry.json" "$RUN" <<'PY' || fail "cannot read registry.json"
import json,sys
a=json.load(open(sys.argv[1]))["agents"]["sentinel"]
d=sys.argv[2]
open(d+"/allow.txt","w").write("\n".join(a["tools_allowed"])+"\n")
open(d+"/deny.txt","w").write("\n".join(a["tools_denied"])+"\n")
open(d+"/model.txt","w").write(a["model"]+" "+str(a["budget_usd_per_run"])+"\n")
PY
read -r MODEL BUDGET <"$RUN/model.txt"
ALLOW=(); while IFS= read -r l; do [ -n "$l" ] && ALLOW+=("$l"); done <"$RUN/allow.txt"
DENY=();  while IFS= read -r l; do [ -n "$l" ] && DENY+=("$l");  done <"$RUN/deny.txt"
CTX="$(sentinel context)"
if [ "$KIND" = "daily" ]; then ASK="Run today's DAILY audit ($DATE): at most 3 recommendations, zero is fine."
else ASK="Run the WEEKLY audit ($DATE): a deeper pass, up to 10 ranked recommendations as a roadmap, and review the ledger for stalled accepted items and rejected themes that keep coming back."; fi
PROMPT="$ASK
Targets: targets.json in the current folder (public URLs only; viewports 375x812, 768x1024, 1440x900). Code snapshot: this folder (code only).
Native screenshots, if any: $RUN/native (a missing folder or empty means not checked).
Screenshots you take are saved to $RUN/shots — cite only the file name.

$CTX

Reply with only the JSON object from the sentinel-product-audit skill."

OUT="$RUN/$KIND.raw.json"; ERR="$RUN/$KIND.stderr.txt"
log "agent starting (model $MODEL, cap \$$BUDGET)"
( cd "$SRC" && "$CLAUDE" -p "$PROMPT" --agent sentinel --model "$MODEL" --max-budget-usd "$BUDGET" \
    --mcp-config "$MCP" --strict-mcp-config --no-session-persistence --output-format json \
    --allowedTools "${ALLOW[@]}" --disallowedTools "${DENY[@]}" ) >"$OUT" 2>"$ERR"
RC=$?
if [ $RC -ne 0 ]; then
  if grep -qiE 'usage limit|rate limit|limit reached|quota' "$OUT" "$ERR" 2>/dev/null; then echo "$(date +%s)" >"$STATE/LIMIT"; fail "Claude usage limit reached — will retry next run" 75; fi
  fail "agent exited $RC: $(head -c 300 "$ERR")"
fi

# 7. pull the answer out of the CLI envelope, validate, dedupe, write the report
python3 - "$OUT" "$RUN/$KIND.answer.txt" <<'PY' || fail "could not read the agent's answer"
import json,sys
d=json.load(open(sys.argv[1]))
if d.get("is_error"): sys.exit(1)
open(sys.argv[2],"w").write(d.get("result",""))
PY
sentinel ingest "$RUN/$KIND.answer.txt" --kind "$KIND" --date "$DATE" >>"$LOG" 2>&1; IRC=$?
case $IRC in
  0) N="$(python3 -c "import json;print(len(json.load(open('$RUN/$KIND.json'))['recommendations']))")"; log "done: $N recommendation(s) -> $RUN/$KIND.md"; notify "$N new recommendation(s) — see $KIND report";;
  2) log "done: nothing met the bar -> $RUN/$KIND.md"; notify "No new recommendations today";;
  *) fail "agent answer failed validation (see $RUN/$KIND.answer.txt)" 3;;
esac
exit 0
