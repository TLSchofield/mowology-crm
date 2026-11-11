#!/usr/bin/env bash
# =============================================================================
# scripts/codex-schedule/nightly.sh — the nightly Codex review (Tim, 2026-10-15: 3 a night,
# salt & snow first, until the iPhone app, the Android app and the website are reviewed entirely).
# =============================================================================
# Runs from launchd on the iMac (~/Library/LaunchAgents/ca.mowology.codex-nightly.plist) in its own
# checkout, ~/.mowology-codex/src, moved to origin/feature/language (what production runs) each night.
#   1. picks the next N units of scripts/codex-schedule/queue.json not yet reviewed in this pass;
#   2. reviews each with scripts/codex-review.sh (code only, client names redacted, read-only Codex);
#   3. saves the report to ~/.mowology-codex/reports/<date>-<unit>.md and the outcome to
#      ~/.mowology-codex/state.json — outside the repo: reports quote code and are never committed.
# Nothing is fixed here. Claude checks every finding against the real code first (CLAUDE.md 1c-bis).
# When every unit is reviewed, the next pass starts, oldest review first.
#
#   scripts/codex-schedule/nightly.sh [N]        N units (default 3)
#   scripts/codex-schedule/nightly.sh --status   what's done, what's next
# Runs on Tim's ChatGPT plan (codex CLI), not Claude usage.
# =============================================================================
set -uo pipefail
export PATH="$HOME/.npm-global/bin:/opt/homebrew/bin:/usr/local/bin:/usr/bin:/bin:$PATH"

HOME_DIR="$HOME/.mowology-codex"
BIN="$(cd "$(dirname "$0")" && pwd)"          # this script, build-queue.py, prompt.txt, codex-review.sh (install.sh copies them)
QUEUE="$HOME_DIR/queue.json"
SRC="$HOME_DIR/src"
REPORTS="$HOME_DIR/reports"
STATE="$HOME_DIR/state.json"
LOG="$HOME_DIR/nightly.log"
mkdir -p "$REPORTS"
[ -f "$STATE" ] || echo '{"pass": 1, "units": {}, "files": {}}' > "$STATE"

log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*" | tee -a "$LOG"; }

if [ "${1:-}" = "--status" ]; then
  python3 - "$QUEUE" "$STATE" <<'PY'
import json, sys
q = json.load(open(sys.argv[1]))['units']; st = json.load(open(sys.argv[2]))
files = st.get('files', {})
done = [u for u in q if all(files.get(f) == st['pass'] for f in u['paths'])]
print(f"pass {st['pass']}: {len(done)}/{len(q)} units reviewed")
for u in [u for u in q if u not in done][:6]: print('  next:', u['id'], '—', u['title'])
PY
  exit 0
fi
N="${1:-3}"

# ── The checkout: what production runs ──────────────────────────────────────
cd "$SRC" || { log "no checkout at $SRC"; exit 2; }
if ! git fetch -q origin feature/language; then log "git fetch failed — reviewing the last fetched code"; fi
git checkout -q -f --detach origin/feature/language || { log "checkout failed"; exit 2; }   # -f: last night's rebuilt queue.json
SHA="$(git rev-parse --short HEAD)"
python3 "$BIN/build-queue.py" --out "$QUEUE" >> "$LOG" 2>&1 || { log "queue build failed"; exit 2; }

# ── The next N units ────────────────────────────────────────────────────────
NEXT="$(python3 - "$QUEUE" "$STATE" "$N" <<'PY'
import json, sys
q = json.load(open(sys.argv[1]))['units']; st = json.load(open(sys.argv[2])); n = int(sys.argv[3])
files = st.setdefault('files', {})
todo = [u['id'] for u in q if not all(files.get(f) == st['pass'] for f in u['paths'])]
if not todo:                      # pass complete: start the next one
    st['pass'] += 1
    json.dump(st, open(sys.argv[2], 'w'), indent=1)
    todo = [u['id'] for u in q]
print(' '.join(todo[:n]))
PY
)"
[ -n "$NEXT" ] || { log "nothing to review"; exit 0; }
log "commit $SHA — reviewing: $NEXT"

for ID in $NEXT; do
  PROMPT="$(mktemp)"; PATHS="$(mktemp)"
  python3 - "$QUEUE" "$ID" "$PROMPT" "$PATHS" "$BIN/prompt.txt" <<'PY'
import json, sys
u = next(x for x in json.load(open(sys.argv[1]))['units'] if x['id'] == sys.argv[2])
open(sys.argv[3], 'w').write(open(sys.argv[5]).read()
    .replace('{TITLE}', u['title']).replace('{FOCUS}', u['focus']))
open(sys.argv[4], 'w').write('\n'.join(u['paths']))
PY
  OUT="$REPORTS/$(date +%F)-$ID.md"
  # shellcheck disable=SC2046
  CODEX_OUT="$OUT" "$BIN/codex-review.sh" "nightly-$ID" "$PROMPT" $(cat "$PATHS") >> "$LOG" 2>&1
  RC=$?
  case $RC in
    0) RESULT=reviewed ;;
    3) RESULT=blocked ;;      # a secret-looking literal or no name list: nothing was sent
    *) RESULT=failed ;;
  esac
  python3 - "$STATE" "$ID" "$RESULT" "$SHA" "$OUT" "$QUEUE" <<'PY'
import json, sys, datetime, os
st = json.load(open(sys.argv[1])); uid, res, sha, out = sys.argv[2:6]
prev = st['units'].get(uid, {})
tries = prev.get('tries', 0) + 1 if res == 'failed' else 0
rec = {'result': res, 'commit': sha, 'at': datetime.datetime.now().isoformat(timespec='minutes'),
       'report': out if os.path.exists(out) else None, 'checked_by_claude': False, 'tries': tries}
# reviewed → its files count as done this pass. blocked (a secret-looking literal: nothing sent) needs a person,
# and a unit that failed twice is skipped too — both are listed for Claude instead of retried every night.
if res == 'reviewed' or res == 'blocked' or tries >= 2:
    u = next(x for x in json.load(open(sys.argv[6]))['units'] if x['id'] == uid)
    for f in u['paths']: st.setdefault('files', {})[f] = st['pass']
    rec['paths'] = u['paths']
st['units'][uid] = {**prev, **rec}
json.dump(st, open(sys.argv[1], 'w'), indent=1)
PY
  log "$ID: $RESULT${OUT:+ → $OUT}"
  rm -f "$PROMPT" "$PATHS"
done
log "done"
