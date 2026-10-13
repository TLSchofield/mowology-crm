# scripts/lib/heartbeat.sh — tell the server a Mac-side job ran (2026-10-13).
# Sourced by scripts/backup-nightly.sh and scripts/composer-audit-report.sh. Ada's security checks
# (cron security_posture) read the rows: no successful db heartbeat in 36 h is red, no files
# heartbeat amber, no composer report in 14 days amber.
#
# Token: macOS Keychain item "mowology-backup-heartbeat" first, else the gitignored file
# scripts/.backup-heartbeat-token. It goes to curl through a header file, never on the command line.
# The server side is public/crm/api/backup-heartbeat.php.

MW_HEARTBEAT_URL="${MW_HEARTBEAT_URL:-https://mowology.ca/crm/api/backup-heartbeat.php}"

mw_heartbeat_token() {
  local root; root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
  security find-generic-password -s mowology-backup-heartbeat -w 2>/dev/null \
    || { [ -r "$root/scripts/.backup-heartbeat-token" ] && tr -d '[:space:]' < "$root/scripts/.backup-heartbeat-token"; }
}

# mw_heartbeat <kind db|files|composer> <ok 1|0> [bytes] [advisories] [detail]
# Never fails the caller: prints one line saying whether the server took it.
mw_heartbeat() {
  local kind="$1" ok="$2" bytes="${3:-}" adv="${4:-}" detail="${5:-}" tok hdr body code
  tok="$(mw_heartbeat_token)"
  if [ -z "$tok" ]; then echo "heartbeat $kind: no token (Keychain mowology-backup-heartbeat or scripts/.backup-heartbeat-token)"; return 0; fi
  body="$(python3 -c 'import json,sys,datetime
k,ok,b,a,d=sys.argv[1:6]
o={"kind":k,"ok":ok=="1","finished_at":datetime.datetime.now().astimezone().isoformat(timespec="seconds")}
if b: o["bytes"]=int(b)
if a: o["advisories"]=int(a)
if d: o["detail"]=d[:500]
print(json.dumps(o))' "$kind" "$ok" "$bytes" "$adv" "$detail")"
  hdr="$(mktemp)"; chmod 600 "$hdr"
  printf 'X-Heartbeat-Token: %s\nContent-Type: application/json\n' "$tok" > "$hdr"
  code="$(curl -s -o /dev/null -w '%{http_code}' -m 20 -X POST -H @"$hdr" --data-binary "$body" "$MW_HEARTBEAT_URL" || echo 000)"
  rm -f "$hdr"
  echo "heartbeat $kind ok=$ok → HTTP $code"
  return 0
}
