#!/usr/bin/env bash
# =============================================================================
# scripts/lib/codex-sanitize.sh — remove live client names and stop secrets reaching Codex.
# =============================================================================
# Tim, 2026-10-24: "utilize codex as much as possible due to usage". Shared by the Codex review
# and web-build tools so Codex can work on code safely; tests decide, and Claude reviews before ship.
# =============================================================================

sanitize_names() { # <copy-dir>; production helper resolved from SANITIZE_REPO or the current repo
  local work="$1" repo names_php names_json rc
  repo="${SANITIZE_REPO:-$(git rev-parse --show-toplevel)}"
  names_php="$(mktemp)" || return 3
  names_json="$(mktemp)" || { rm -f "$names_php"; return 3; }
  cat > "$names_php" <<'PHP'
$n = [];
$add = function ($v, $min) use (&$n) { $v = trim((string)$v); if (mb_strlen($v) >= $min && strpos($v, ' ') !== false) $n[] = $v; };
foreach ($db->query("SELECT CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,'')) FROM contacts")->fetchAll(PDO::FETCH_COLUMN) as $v) $add($v, 5);
foreach ($db->query("SELECT company_name FROM companies")->fetchAll(PDO::FETCH_COLUMN) as $v) { $v = trim((string)$v); if (mb_strlen($v) >= 6) $n[] = $v; }
foreach ($db->query("SELECT display_name FROM clients WHERE display_name IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN) as $v) $add($v, 6);
foreach ($db->query("SELECT property_name FROM properties WHERE property_name IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN) as $v) $add($v, 6);
foreach ($db->query("SELECT CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,'')) FROM users")->fetchAll(PDO::FETCH_COLUMN) as $v) $add($v, 5);
$out['names'] = array_values(array_unique($n));
PHP
  if ! "$repo/scripts/prod-run.sh" "$names_php" > "$names_json" 2>/dev/null; then
    echo "could not fetch the name list — nothing sent" >&2
    rm -f "$names_php" "$names_json"
    return 3
  fi
  python3 - "$work" "$names_json" <<'PY'
import json, os, re, sys
work, names_file = sys.argv[1], sys.argv[2]
try:
    names = json.load(open(names_file)).get('names', [])
except Exception:
    sys.exit("name list looks empty — refusing to send unredacted code")
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
  rc=$?
  rm -f "$names_php" "$names_json"
  [ "$rc" -eq 0 ] || return 3
}

sanitize_secret_scan() { # <copy-dir>
  local work="$1" pattern
  pattern="(sk-[A-Za-z0-9]{20,}|sk_live_[A-Za-z0-9]{10,}|AKIA[0-9A-Z]{16}|-----BEGIN [A-Z ]*PRIVATE KEY-----(\\n|\s)*[A-Za-z0-9+/]{40,}|(password|passwd|secret|api_?key)['\"]?\s*[:=]>?\s*['\"][^'\"\$]{8,}['\"])"
  if grep -rInE "$pattern" "$work" >/dev/null; then
    echo "possible secret in the copy — nothing sent. Matches:" >&2
    grep -rInE "$pattern" "$work" | cut -c1-160 >&2
    return 3
  fi
}
