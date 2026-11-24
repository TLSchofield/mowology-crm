#!/usr/bin/env bash
# scripts/parity/install.sh — schedule the daily Android/iOS parity run on this Mac (once; re-run to update).
#   ~/.mowology-parity/bin     parity.py, screens.json, run-daily.sh (copied from here)
#   ~/Library/LaunchAgents/ca.mowology.parity-daily.plist   every day 06:30 (runs on wake if asleep)
# Needs ~/.mowology-codex/src (made by scripts/codex-schedule/install.sh) — its nightly checkout of feature/language.
# Undo: launchctl unload ~/Library/LaunchAgents/ca.mowology.parity-daily.plist
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
STATE="$HOME/.mowology-parity"; BIN="$STATE/bin"
PLIST="$HOME/Library/LaunchAgents/ca.mowology.parity-daily.plist"
[ -d "$HOME/.mowology-codex/src" ] || { echo "run scripts/codex-schedule/install.sh first (it creates ~/.mowology-codex/src)"; exit 3; }
mkdir -p "$BIN"
cp "$HERE/parity.py" "$HERE/screens.json" "$HERE/run-daily.sh" "$BIN/"; chmod +x "$BIN/run-daily.sh" "$BIN/parity.py"
cat > "$PLIST" <<PL
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0"><dict>
  <key>Label</key><string>ca.mowology.parity-daily</string>
  <key>ProgramArguments</key><array><string>/bin/bash</string><string>$BIN/run-daily.sh</string></array>
  <key>StartCalendarInterval</key><dict><key>Hour</key><integer>6</integer><key>Minute</key><integer>30</integer></dict>
  <key>StandardOutPath</key><string>$STATE/launchd.out</string>
  <key>StandardErrorPath</key><string>$STATE/launchd.err</string>
  <key>EnvironmentVariables</key><dict><key>HOME</key><string>$HOME</string></dict>
</dict></plist>
PL
launchctl unload "$PLIST" 2>/dev/null || true
launchctl load "$PLIST"
echo "installed: parity run daily at 06:30 — ledger/reports in $STATE"
python3 "$BIN/parity.py" status || true
