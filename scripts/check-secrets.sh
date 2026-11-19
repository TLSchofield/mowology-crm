#!/usr/bin/env bash
# check-secrets.sh — after anyone edits the LIVE secrets.php, check it parses (2026-10-20).
#
# Why: a missing `;` in secrets.php is a PHP parse error in a file every page loads, so the WHOLE
# site (public pages, login, crew app) answers HTTP 500. It happened 2026-10-20 adding the QBO_* lines.
#
# What it does: downloads secrets.php to a private temp file, runs `php -l`, prints only the error
# and line number (values on nearby lines masked as '…'), deletes the copy, then checks the home page
# and login answer 200. It never prints a secret value.
#
# Usage: scripts/check-secrets.sh
set -uo pipefail
cd "$(dirname "$0")/.."
source scripts/lib/ftp.sh

tmpd="$(mktemp -d -t secrets.XXXXXX)"   # a folder: lftp will not overwrite an existing file
tmp="$tmpd/secrets.php"
trap 'rm -rf "$tmpd"' EXIT
mw_lftp "get /app_config/secrets.php -o $tmp" >/dev/null 2>&1 || { echo "could not download secrets.php"; exit 2; }

lint="$(php -l "$tmp" 2>&1)"
if echo "$lint" | grep -q "No syntax errors"; then
    echo "secrets.php: OK (parses)"
else
    echo "secrets.php: SYNTAX ERROR — every page is down until this is fixed"
    echo "$lint" | grep -m1 "error" | sed "s#$tmp#secrets.php#"
    line="$(echo "$lint" | grep -o 'on line [0-9]*' | head -1 | grep -o '[0-9]*')"
    if [ -n "$line" ]; then
        start=$(( line > 3 ? line - 3 : 1 ))
        echo "lines $start–$line (values masked):"
        sed -n "${start},${line}p" "$tmp" | sed -E "s/'[^']*'/'…'/g; s/\"[^\"]*\"/\"…\"/g" | cut -c1-100 | nl -v "$start"
        echo "Usually the line ABOVE the error is missing its ; (often after removing a closing ?>)."
    fi
fi

for u in https://mowology.ca/ https://mowology.ca/loginAuth/login.php; do
    printf '%s  %s\n' "$(curl -s -o /dev/null -w '%{http_code}' "$u")" "$u"
done
