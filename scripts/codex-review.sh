#!/usr/bin/env bash
# =============================================================================
# scripts/codex-review.sh — an independent Codex (OpenAI) code review on a clean copy of the code.
# =============================================================================
# Tim, 2026-10-14: Codex reviews, Claude edits. A trial on the Android camera found 5 real defects
# Claude had missed. Codex only ever sees CODE:
#   1. the given paths are copied into a temp folder: code files only — no docs, no tests,
#      no .md/.sql/.csv/.json data, no secrets.php / app_config;
#   2. every client / company / property name in the live CRM (fetched fresh with prod-run.sh,
#      never written to the repo) is replaced by [client], comments included;
#   3. it stops if anything looking like a password, key or token literal remains;
#   4. `codex exec` runs read-only (no edits, no shell writes) and writes its findings.
# Claude then checks every finding against the real code before anything is fixed.
#
# Usage:
#   scripts/codex-review.sh <name> <prompt-file> <path> [<path> …]
#   paths: files or directories, relative to the repo root, or absolute (e.g. the Android repo).
#   Findings: $CODEX_OUT (default /tmp/codex-review/<name>.md). Exit 3 = secret found, nothing sent.
# =============================================================================
set -euo pipefail
cd "$(git rev-parse --show-toplevel)"

NAME="${1:?usage: codex-review.sh <name> <prompt-file> <path>…}"; shift
PROMPT_FILE="${1:?prompt file}"; shift
[ $# -gt 0 ] || { echo "give at least one path" >&2; exit 2; }
[ -f "$PROMPT_FILE" ] || { echo "no prompt file: $PROMPT_FILE" >&2; exit 2; }
command -v codex >/dev/null || { echo "codex CLI not installed (npm i -g @openai/codex)" >&2; exit 2; }

BASE="${TMPDIR:-/tmp}/codex-review"
WORK="$BASE/$NAME"
OUT="${CODEX_OUT:-$BASE/$NAME.md}"
rm -rf "$WORK"; mkdir -p "$WORK"

# ── 1. Code files only ────────────────────────────────────────────────────────
CODE_EXT='php|js|ts|kt|java|swift|css|html|sh|py|gradle|xml|htaccess'   # .htaccess: access rules are code
copied=0
for p in "$@"; do
  if [ -d "$p" ]; then
    root="$p"
    while IFS= read -r f; do
      rel="${f#"$root"/}"
      dest="$WORK/$(basename "$root")/$rel"
      mkdir -p "$(dirname "$dest")"; cp "$f" "$dest"; copied=$((copied + 1))
    done < <(find "$p" -type f | grep -E "\.($CODE_EXT)$" \
             | grep -vE '/(tests?|Tests?|docs?|vendor|node_modules|build|uploads|downloads|app_config)/|\.min\.(js|css)$|secrets\.php$|\.test\.')
  elif [ -f "$p" ]; then
    echo "$p" | grep -qE "\.($CODE_EXT)$" || { echo "skipping non-code file $p" >&2; continue; }
    dest="$WORK/files/$(echo "$p" | sed 's#^/##')"
    mkdir -p "$(dirname "$dest")"; cp "$p" "$dest"; copied=$((copied + 1))
  else
    echo "no such path: $p" >&2; exit 2
  fi
done
[ "$copied" -gt 0 ] || { echo "nothing to review" >&2; exit 2; }

# ── 2. Client names out (fresh from production, kept only in a temp file) ─────
NAMES_PHP="$(mktemp)"; NAMES_JSON="$(mktemp)"
trap 'rm -f "$NAMES_PHP" "$NAMES_JSON"' EXIT
cat > "$NAMES_PHP" <<'PHP'
$n = [];
$add = function ($v, $min) use (&$n) { $v = trim((string)$v); if (mb_strlen($v) >= $min && strpos($v, ' ') !== false) $n[] = $v; };
foreach ($db->query("SELECT CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,'')) FROM contacts")->fetchAll(PDO::FETCH_COLUMN) as $v) $add($v, 5);
foreach ($db->query("SELECT company_name FROM companies")->fetchAll(PDO::FETCH_COLUMN) as $v) { $v = trim((string)$v); if (mb_strlen($v) >= 6) $n[] = $v; }
foreach ($db->query("SELECT display_name FROM clients WHERE display_name IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN) as $v) $add($v, 6);
foreach ($db->query("SELECT property_name FROM properties WHERE property_name IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN) as $v) $add($v, 6);
foreach ($db->query("SELECT CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,'')) FROM users")->fetchAll(PDO::FETCH_COLUMN) as $v) $add($v, 5);
$out['names'] = array_values(array_unique($n));
PHP
if ! scripts/prod-run.sh "$NAMES_PHP" > "$NAMES_JSON" 2>/dev/null; then
  echo "could not fetch the name list — nothing sent" >&2; exit 3
fi
python3 - "$WORK" "$NAMES_JSON" <<'PY'
import json, os, re, sys
work, names_file = sys.argv[1], sys.argv[2]
names = json.load(open(names_file)).get('names', [])
if len(names) < 50:
    sys.exit("name list looks empty — refusing to send unredacted code")
names.sort(key=len, reverse=True)
pats = [re.compile(r'(?<![A-Za-z0-9])' + r'[\s\-_]*'.join(map(re.escape, n.split())) + r'(?![A-Za-z0-9])', re.I) for n in names]
hits = 0
for root, _, files in os.walk(work):
    for f in files:
        p = os.path.join(root, f)
        s = open(p, encoding='utf-8', errors='replace').read()
        t = s
        for rx in pats:
            t, k = rx.subn('[client]', t)
            hits += k
        if t != s:
            open(p, 'w', encoding='utf-8').write(t)
print(f"redacted {hits} name mention(s)")
PY

# ── 3. Secrets: stop rather than send ─────────────────────────────────────────
if grep -rInE "(sk-[A-Za-z0-9]{20,}|sk_live_[A-Za-z0-9]{10,}|AKIA[0-9A-Z]{16}|-----BEGIN [A-Z ]*PRIVATE KEY|(password|passwd|secret|api_?key)['\"]?\s*[:=]>?\s*['\"][^'\"\$]{8,}['\"])" "$WORK" >/dev/null; then
  echo "possible secret in the copy — nothing sent. Matches:" >&2
  grep -rInE "(sk-[A-Za-z0-9]{20,}|sk_live_[A-Za-z0-9]{10,}|AKIA[0-9A-Z]{16}|-----BEGIN [A-Z ]*PRIVATE KEY|(password|passwd|secret|api_?key)['\"]?\s*[:=]>?\s*['\"][^'\"\$]{8,}['\"])" "$WORK" | cut -c1-160 >&2
  exit 3
fi

echo "reviewing $copied file(s) in $WORK"
# ── 4. Codex, read-only ───────────────────────────────────────────────────────
codex exec --sandbox read-only --skip-git-repo-check --ephemeral -C "$WORK" -o "$OUT" "$(cat "$PROMPT_FILE")" < /dev/null > "$BASE/$NAME.log" 2>&1
echo "findings: $OUT"
