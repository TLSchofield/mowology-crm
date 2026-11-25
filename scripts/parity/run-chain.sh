#!/usr/bin/env bash
# scripts/parity/run-chain.sh — run a list of screen parity jobs through scripts/codex-build.sh, one after another (Tim, 2026-11-20:
# "continue through all of them"). Each job: Codex writes Android on codex/native, tests gate it, a second Codex reviews, it commits.
# Nothing here merges, releases or pushes. Claude reviews each commit afterwards.
#
#   scripts/parity/run-chain.sh [order-file]      default ~/.mowology-parity/chain/order.txt (one job key per line)
# Job key K reads ~/.mowology-parity/chain/K.md (the task) and ~/.mowology-parity/chain/ref-K/ (the iPhone code to read).
# The Codex worktree allows ONE writer: the chain waits while any other codex-build.sh runs, and retries a busy worktree (exit 3).
# Stops on exit 75 (Codex out of usage) or 2 (tests still failing; the worktree is left for Claude). Log: ~/.mowology-parity/chain/chain.log
set -uo pipefail
export PATH="$HOME/.npm-global/bin:/opt/homebrew/bin:/usr/local/bin:/usr/bin:/bin:$PATH"
CH="$HOME/.mowology-parity/chain"; ORDER="${1:-$CH/order.txt}"; LOG="$CH/chain.log"
REPO="$(cd "$(dirname "$0")/../.." && pwd)"
log() { echo "[$(date '+%a %H:%M:%S')] $*" | tee -a "$LOG"; }
busy() { pgrep -f "^bash .*codex-build.sh" >/dev/null; }

log "chain start: $(tr '\n' ' ' < "$ORDER")"
while read -r key; do
  [ -n "$key" ] || continue
  done_marker="$CH/done-$key"; [ -f "$done_marker" ] && { log "$key: already done"; continue; }
  tries=0
  while :; do
    while busy; do sleep 30; done
    sleep 5
    log "$key: start"
    # < /dev/null: Codex otherwise reads the job list from this loop's stdin and swallows the remaining jobs (2026-11-20)
    (cd "$REPO" && bash scripts/codex-build.sh "parity-$key" "$CH/$key.md" "$CH/ref-$key" < /dev/null > "$CH/$key.log" 2>&1); rc=$?
    log "$key: exit $rc ($(tail -1 "$CH/$key.log" | cut -c1-100))"
    case $rc in
      0)  touch "$done_marker"; break ;;
      3)  tries=$((tries+1)); [ $tries -ge 4 ] && { log "$key: worktree busy/dirty 4 times — stopping"; exit 3; }; sleep 120 ;;
      75) log "Codex out of usage — stopping; rerun the chain later"; exit 75 ;;
      *)  log "$key: stopping (exit $rc) — Claude must look"; exit "$rc" ;;
    esac
  done
done < "$ORDER"
log "chain done"
