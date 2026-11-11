#!/bin/sh
# LOCAL TEST ONLY — stand-in for sendmail. PHP's mail() pipes the message here; it is appended
# to the log file named in $1 and NEVER sent anywhere. Always exits 0 so mail() reports success.
LOG="${1:-${MOWOLOGY_LOCAL_STATE:-$HOME/.mowology-local}/mail.log}"
{
  printf '=== %s  (captured by sendmail-log.sh, not sent) ===\n' "$(date '+%Y-%m-%d %H:%M:%S')"
  cat
  printf '\n'
} >> "$LOG"
exit 0
