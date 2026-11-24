#!/usr/bin/env bash
# composer-audit-report.sh — run `composer audit` on the Mac and report the result to the server
# (2026-10-13). The host cannot run composer, so Ada's "PHP library advisories" check reads this
# report (backup_heartbeats kind 'composer'): advisories > 0 or a report older than 14 days is amber.
#
# Audits both composer projects: the repo root (composer.json → vendor/) and public/ (public/composer.json).
# Run nightly by scripts/backup-nightly.sh; safe to run by hand. Prints the summary, never the token.
set -uo pipefail
cd "$(dirname "$0")/.."
export PATH="/usr/local/bin:/opt/homebrew/bin:/usr/bin:/bin:/usr/sbin:/sbin"
. scripts/lib/heartbeat.sh

total=0; ok=1; parts=()
for dir in . public; do
  [ -f "$dir/composer.lock" ] || continue
  json="$(cd "$dir" && composer audit --locked --format=json --no-interaction 2>/dev/null)"
  summary="$(printf '%s' "$json" | python3 -c '
import json,sys
try: d=json.load(sys.stdin)
except Exception: print("ERR"); sys.exit()
adv=d.get("advisories") or {}
items=[]
for pkg,lst in (adv.items() if isinstance(adv,dict) else []):
    for a in (lst.values() if isinstance(lst,dict) else lst):
        items.append(pkg+" "+(a.get("cve") or a.get("advisoryId") or ""))
print(len(items)); print("; ".join(sorted(set(items)))[:300])')"
  n="$(printf '%s\n' "$summary" | sed -n 1p)"
  if [ "$n" = "ERR" ] || [ -z "$n" ]; then ok=0; parts+=("$dir: audit failed"); continue; fi
  names="$(printf '%s\n' "$summary" | sed -n 2p)"
  total=$((total + n))
  label="$([ "$dir" = . ] && echo root || echo public)"
  parts+=("$label: $n${names:+ ($names)}")
done
detail="$(IFS='|'; echo "${parts[*]+${parts[*]}}")"
echo "composer audit: $total advisory(ies) — $detail"
mw_heartbeat composer "$ok" "" "$total" "$detail"
