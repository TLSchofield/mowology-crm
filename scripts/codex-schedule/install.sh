#!/usr/bin/env bash
# =============================================================================
# scripts/codex-schedule/install.sh — set up the nightly Codex review on this Mac (once; re-run to update).
#   ~/.mowology-codex/src   its own git worktree of this repo (detached; nightly.sh moves it to
#                           origin/feature/language each night, so Claude's worktrees are never touched)
#   ~/.mowology-codex/bin   nightly.sh, build-queue.py, prompt.txt, codex-review.sh (copied from here)
#   ~/Library/LaunchAgents/ca.mowology.codex-nightly.plist   every night at 01:30 (runs on wake if asleep)
# Undo: launchctl unload ~/Library/LaunchAgents/ca.mowology.codex-nightly.plist
# =============================================================================
set -euo pipefail
REPO="$(git rev-parse --show-toplevel)"
HOME_DIR="$HOME/.mowology-codex"
BIN="$HOME_DIR/bin"
SRC="$HOME_DIR/src"
PLIST="$HOME/Library/LaunchAgents/ca.mowology.codex-nightly.plist"
N="${CODEX_NIGHTLY_UNITS:-3}"

mkdir -p "$BIN" "$HOME_DIR/reports"
cp "$REPO/scripts/codex-schedule/nightly.sh" "$REPO/scripts/codex-schedule/build-queue.py" \
   "$REPO/scripts/codex-schedule/prompt.txt" "$REPO/scripts/codex-review.sh" "$BIN/"
chmod +x "$BIN/nightly.sh" "$BIN/build-queue.py" "$BIN/codex-review.sh"

if [ ! -e "$SRC/.git" ]; then
  git -C "$REPO" fetch -q origin feature/language
  git -C "$REPO" worktree add -q --detach "$SRC" origin/feature/language
fi

cat > "$PLIST" <<PL
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0"><dict>
  <key>Label</key><string>ca.mowology.codex-nightly</string>
  <key>ProgramArguments</key><array><string>/bin/bash</string><string>$BIN/nightly.sh</string><string>$N</string></array>
  <key>StartCalendarInterval</key><dict><key>Hour</key><integer>1</integer><key>Minute</key><integer>30</integer></dict>
  <key>StandardOutPath</key><string>$HOME_DIR/launchd.out</string>
  <key>StandardErrorPath</key><string>$HOME_DIR/launchd.err</string>
  <key>EnvironmentVariables</key><dict><key>HOME</key><string>$HOME</string></dict>
</dict></plist>
PL
launchctl unload "$PLIST" 2>/dev/null || true
launchctl load "$PLIST"
echo "installed: $N unit(s) nightly at 01:30 — checkout $SRC, reports $HOME_DIR/reports"
"$BIN/nightly.sh" --status 2>/dev/null || true
