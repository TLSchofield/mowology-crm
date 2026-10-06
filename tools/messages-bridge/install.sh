#!/bin/bash
# Mowology messages bridge: install (Tim runs this himself; see README.md).
#
# Copies bridge.py to ~/Library/Application Support/mowology-bridge/ (so it keeps working
# whatever branch the repo is on), writes the launchd agent to ~/Library/LaunchAgents and
# loads it. Re-running it updates the installed copy. Uninstall: see README.md.
set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
APP="$HOME/Library/Application Support/mowology-bridge"
LOGS="$HOME/Library/Logs/mowology"
LABEL="ca.mowology.messages-bridge"
PLIST="$HOME/Library/LaunchAgents/$LABEL.plist"

if [ ! -s "$APP/token" ]; then
    echo "No token yet. Create it first (README.md, step 2)."
    exit 1
fi
chmod 600 "$APP/token"

mkdir -p "$APP" "$LOGS" "$HOME/Library/LaunchAgents"
cp "$HERE/bridge.py" "$APP/bridge.py"
chmod 700 "$APP/bridge.py"
sed "s#__HOME__#$HOME#g" "$HERE/$LABEL.plist" > "$PLIST"
plutil -lint "$PLIST" >/dev/null

launchctl unload "$PLIST" 2>/dev/null || true
launchctl load "$PLIST"

echo "Installed. It runs now and every 5 minutes."
echo "Log:  tail -f \"$LOGS/messages-bridge.log\""
