#!/usr/bin/env bash
# install.sh — copy Sentinel out of the (disposable) worktree into ~/.mowology-sentinel/bin and load two launchd jobs:
#   ca.mowology.sentinel-daily  07:00 every day     ca.mowology.sentinel-weekly  08:00 Sundays
# Same pattern as ca.mowology.parity-daily. Re-run to update. Remove with: install.sh --uninstall
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"; ROOT="$(cd "$HERE/../.." && pwd)"
STATE="$HOME/.mowology-sentinel"; BIN="$STATE/bin"; LA="$HOME/Library/LaunchAgents"; UID_="$(id -u)"
if [ "${1:-}" = "--uninstall" ]; then
  for j in daily weekly; do launchctl bootout "gui/$UID_/ca.mowology.sentinel-$j" 2>/dev/null || true; rm -f "$LA/ca.mowology.sentinel-$j.plist"; done
  echo "unloaded; state kept in $STATE"; exit 0
fi
mkdir -p "$BIN/agent/agents" "$BIN/agent/skills" "$STATE" "$LA"
cp "$HERE"/sentinel.py "$HERE"/run-audit.sh "$HERE"/native-shots.sh "$HERE"/sentinel "$HERE"/registry.json "$HERE"/targets.json "$BIN/"
chmod +x "$BIN"/sentinel "$BIN"/run-audit.sh "$BIN"/native-shots.sh "$BIN"/sentinel.py
cp "$ROOT/.claude/agents/sentinel.md" "$BIN/agent/agents/"; rm -rf "$BIN/agent/skills/sentinel-product-audit"
cp -R "$ROOT/.claude/skills/sentinel-product-audit" "$BIN/agent/skills/"
python3 "$BIN/sentinel.py" init
mkplist() { # name hour weekday|""
  { echo '<?xml version="1.0" encoding="UTF-8"?><!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd"><plist version="1.0"><dict>'
    echo "<key>Label</key><string>ca.mowology.sentinel-$1</string>"
    echo "<key>ProgramArguments</key><array><string>/bin/bash</string><string>$BIN/run-audit.sh</string><string>$1</string></array>"
    echo "<key>StartCalendarInterval</key><dict><key>Hour</key><integer>$2</integer><key>Minute</key><integer>0</integer>${3:+<key>Weekday</key><integer>$3</integer>}</dict>"
    echo "<key>StandardOutPath</key><string>$STATE/launchd.out</string><key>StandardErrorPath</key><string>$STATE/launchd.err</string>"
    echo "<key>EnvironmentVariables</key><dict><key>HOME</key><string>$HOME</string><key>SENTINEL_REPO</key><string>$HOME/Projects/mowology-crm</string></dict>"
    echo '</dict></plist>'; } >"$LA/ca.mowology.sentinel-$1.plist"
  plutil -lint "$LA/ca.mowology.sentinel-$1.plist" >/dev/null
  launchctl bootout "gui/$UID_/ca.mowology.sentinel-$1" 2>/dev/null || true
  launchctl bootstrap "gui/$UID_" "$LA/ca.mowology.sentinel-$1.plist"
}
mkplist daily 7 ""; mkplist weekly 8 0
mkdir -p "$HOME/.local/bin" && ln -sf "$BIN/sentinel" "$HOME/.local/bin/sentinel"
echo "installed. Daily 07:00, weekly Sunday 08:00. Decide with: sentinel accept|reject|modify <id> \"why\""
echo "One-time (you, in a terminal): sign the bundled CLI in — $(find "$HOME/Library/Application Support/Claude/claude-code" -path '*claude.app/Contents/MacOS/claude' | sort -V | tail -1) auth login"
