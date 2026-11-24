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
mkdir -p "$BIN/dept/demo" "$BIN/dept/agent-defs"
cp "$HERE"/ada.py "$HERE"/commercial.py "$HERE"/agents.py "$HERE"/demo.py "$HERE"/registry.json "$HERE"/roster.json "$HERE"/oracle.py "$HERE"/reasons.py "$HERE"/forge.py "$HERE"/dashboard.py "$HERE"/dashboard_server.py "$HERE"/scaffold.py "$HERE"/ada "$BIN/dept/"
cp "$HERE"/demo/standin-claude.py "$BIN/dept/demo/"
cp "$ROOT/docs/design/ittai-v1.2/tokens.css" "$BIN/dept/ittai-tokens.css"
for a in oracle apollo mercury hunter scribe beacon closer ambassador compass; do cp "$ROOT/.claude/agents/$a.md" "$BIN/dept/agent-defs/"; mkdir -p "$D/memory/$a"; [ -f "$D/memory/$a/README.md" ] || printf "# %s memory\nNotes the department keeps for this agent (shown to it each run). Edit freely; authoritative facts live in the shared knowledge store.\n" "$a" > "$D/memory/$a/README.md"; done
cp "$ROOT"/scripts/sentinel/{sentinel.py,run-audit.sh,native-shots.sh,sentinel,registry.json,targets.json} "$BIN/sentinel/"
cp "$ROOT/.claude/agents/sentinel.md" "$BIN/sentinel/agent/agents/"
rm -rf "$BIN/sentinel/agent/skills/sentinel-product-audit"; cp -R "$ROOT/.claude/skills/sentinel-product-audit" "$BIN/sentinel/agent/skills/"
chmod +x "$BIN/dept/ada" "$BIN/dept/ada.py" "$BIN/dept/demo/standin-claude.py" "$BIN/sentinel/"*.sh "$BIN/sentinel/sentinel"
[ -f "$D/memory/compass/brief.md" ] || printf "# Compass standing brief\nStart from docs/marketing/competitor-checklist.md and docs/commercial/research-2026-11-24.md (BuildOps, Aspire, LeanScaper are on the checklist). Evidence with sources and dates only; competitor claims are not verified facts.\n" > "$D/memory/compass/brief.md"
mkdir -p "$BIN/guardian" "$HOME/.mowology-guardian"
cp "$ROOT"/scripts/guardian/{rules.py,guardian.py,page-audit.mjs,baseline.json} "$BIN/guardian/"
if [ ! -d "$HOME/.mowology-guardian/node_modules/playwright-core" ]; then
  # dev tools for Guardian only, outside the repo (pinned): headless browser control + axe accessibility engine. Uses the installed Google Chrome.
  (cd "$HOME/.mowology-guardian" && { [ -f package.json ] || npm init -y >/dev/null 2>&1; npm install --no-audit --no-fund playwright-core@1.57.0 axe-core@4.10.2 >/dev/null 2>&1; }) || echo "note: npm install failed; Guardian's page audit will be skipped until it works"
fi
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
