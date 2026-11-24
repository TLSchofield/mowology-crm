#!/usr/bin/env bash
# install.sh — install the department engine to ~/.mowology-dept/bin and load two launchd jobs:
#   ca.mowology.ada-cycle     02:30 every day  (the one overnight cycle: audits -> import -> prioritise -> briefing)
#   ca.mowology.ada-briefing  07:00 every day  (rebuilds the briefing from the queue and posts the notification)
# Replaces Sentinel's own two jobs (Ada runs Sentinel now). Wake the Mac for it: sudo pmset repeat wakeorpoweron MTWRFSU 02:25:00
# Remove: install.sh --uninstall   (state in ~/.mowology-dept is kept)
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"; ROOT="$(cd "$HERE/../.." && pwd)"
D="$HOME/.mowology-dept"; BIN="$D/bin"; LA="$HOME/Library/LaunchAgents"; U="$(id -u)"
if [ "${1:-}" = "--uninstall" ]; then
  for j in ada-cycle ada-briefing; do launchctl bootout "gui/$U/ca.mowology.$j" 2>/dev/null || true; rm -f "$LA/ca.mowology.$j.plist"; done
  echo "unloaded"; exit 0
fi
mkdir -p "$BIN/dept" "$BIN/sentinel/agent/agents" "$BIN/sentinel/agent/skills" "$D/briefings" "$LA"
cp "$HERE/ada.py" "$HERE/ada" "$BIN/dept/"
cp "$ROOT"/scripts/sentinel/{sentinel.py,run-audit.sh,native-shots.sh,sentinel,registry.json,targets.json} "$BIN/sentinel/"
cp "$ROOT/.claude/agents/sentinel.md" "$BIN/sentinel/agent/agents/"
rm -rf "$BIN/sentinel/agent/skills/sentinel-product-audit"; cp -R "$ROOT/.claude/skills/sentinel-product-audit" "$BIN/sentinel/agent/skills/"
chmod +x "$BIN/dept/ada" "$BIN/dept/ada.py" "$BIN/sentinel/"*.sh "$BIN/sentinel/sentinel"
mkplist() { # label hour minute command...
  local label="$1" h="$2" m="$3"; shift 3
  { echo '<?xml version="1.0" encoding="UTF-8"?><!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd"><plist version="1.0"><dict>'
    echo "<key>Label</key><string>ca.mowology.$label</string><key>ProgramArguments</key><array>"
    for a in "$@"; do echo "<string>$a</string>"; done
    echo "</array><key>StartCalendarInterval</key><dict><key>Hour</key><integer>$h</integer><key>Minute</key><integer>$m</integer></dict>"
    echo "<key>StandardOutPath</key><string>$D/$label.out</string><key>StandardErrorPath</key><string>$D/$label.err</string>"
    echo "<key>EnvironmentVariables</key><dict><key>HOME</key><string>$HOME</string><key>SENTINEL_REPO</key><string>$HOME/Projects/mowology-crm</string><key>PATH</key><string>$HOME/.npm-global/bin:/opt/homebrew/bin:/usr/local/bin:/usr/bin:/bin</string></dict>"
    echo '</dict></plist>'; } >"$LA/ca.mowology.$label.plist"
  plutil -lint "$LA/ca.mowology.$label.plist" >/dev/null
  launchctl bootout "gui/$U/ca.mowology.$label" 2>/dev/null || true
  launchctl bootstrap "gui/$U" "$LA/ca.mowology.$label.plist"
}
for j in daily weekly; do launchctl bootout "gui/$U/ca.mowology.sentinel-$j" 2>/dev/null || true; rm -f "$LA/ca.mowology.sentinel-$j.plist"; done
mkplist ada-cycle 2 30 /usr/bin/python3 "$BIN/dept/ada.py" cycle
mkplist ada-briefing 7 0 /usr/bin/python3 "$BIN/dept/ada.py" briefing
mkdir -p "$HOME/.local/bin"; ln -sf "$BIN/dept/ada" "$HOME/.local/bin/ada"; ln -sf "$BIN/sentinel/sentinel" "$HOME/.local/bin/sentinel"
python3 "$BIN/dept/ada.py" status
echo "installed: cycle 02:30, briefing 07:00. Decide with: ada approve|reject|revise <id> --reason \"…\"   Pause: ada pause   Emergency: ada stop"
