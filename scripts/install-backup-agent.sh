#!/usr/bin/env bash
# install-backup-agent.sh — install (or re-point) the nightly backup launchd agent (2026-10-12).
#
# Writes ~/Library/LaunchAgents/ca.mowology.backup.plist to run THIS checkout's
# scripts/backup-nightly.sh every night at 02:30, then (re)loads it. Re-run it from the main
# checkout once these scripts are merged, so the agent does not point at a deleted worktree.
#
#   scripts/install-backup-agent.sh            # install + load
#   scripts/install-backup-agent.sh --remove   # unload + delete the plist
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
LABEL="ca.mowology.backup"
PLIST="$HOME/Library/LaunchAgents/$LABEL.plist"
DOMAIN="gui/$(id -u)"

launchctl bootout "$DOMAIN/$LABEL" 2>/dev/null || true
if [ "${1:-}" = "--remove" ]; then rm -f "$PLIST"; echo "removed $LABEL"; exit 0; fi

mkdir -p "$HOME/Library/LaunchAgents" "$HOME/Backups/mowology"
cat > "$PLIST" <<PLIST
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
  <key>Label</key><string>$LABEL</string>
  <key>ProgramArguments</key>
  <array>
    <string>/bin/bash</string>
    <string>$ROOT/scripts/backup-nightly.sh</string>
  </array>
  <key>WorkingDirectory</key><string>$ROOT</string>
  <key>EnvironmentVariables</key>
  <dict>
    <key>BACKUP_KEEP</key><string>14</string>
    <key>PATH</key><string>/usr/local/bin:/opt/homebrew/bin:/usr/bin:/bin:/usr/sbin:/sbin</string>
  </dict>
  <key>StartCalendarInterval</key>
  <dict>
    <key>Hour</key><integer>2</integer>
    <key>Minute</key><integer>30</integer>
  </dict>
  <key>StandardOutPath</key><string>$HOME/Backups/mowology/launchd.out.log</string>
  <key>StandardErrorPath</key><string>$HOME/Backups/mowology/launchd.err.log</string>
  <key>ProcessType</key><string>Background</string>
</dict>
</plist>
PLIST
plutil -lint "$PLIST" >/dev/null
launchctl bootstrap "$DOMAIN" "$PLIST"
echo "installed $LABEL → $ROOT/scripts/backup-nightly.sh (02:30 nightly)"
launchctl print "$DOMAIN/$LABEL" | grep -E "state|path" | head -3
