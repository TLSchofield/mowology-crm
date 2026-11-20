#!/usr/bin/env bash
# scripts/parity/run-daily.sh — the daily Android/iOS parity run (launchd, 06:30). See parity.py for the stages.
# detect → queue (Codex writes Android on codex/native; skipped when Codex is out of usage) → shots → report.
# Never merges, releases or pushes anything: Claude reviews codex/native, Tim approves the release.
set -uo pipefail
export PATH="$HOME/.npm-global/bin:/opt/homebrew/bin:/usr/local/bin:/usr/bin:/bin:$PATH"
BIN="$(cd "$(dirname "$0")" && pwd)"
STATE="${PARITY_HOME:-$HOME/.mowology-parity}"; mkdir -p "$STATE"
LOG="$STATE/daily.log"
log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*" | tee -a "$LOG"; }

log "parity run start"
python3 "$BIN/parity.py" detect  2>&1 | tee -a "$LOG"
python3 "$BIN/parity.py" queue   2>&1 | tee -a "$LOG"
if [ "${PARITY_START_ANDROID:-0}" = "1" ] && [ -x "$HOME/.mowology-codex/src/scripts/local-android.sh" ]; then
  "$HOME/.mowology-codex/src/scripts/local-android.sh" up >>"$LOG" 2>&1 || log "emulator did not start — Android screens skipped"
fi
python3 "$BIN/parity.py" shots   2>&1 | tee -a "$LOG"
python3 "$BIN/parity.py" report  2>&1 | tee -a "$LOG"
log "parity run done"
