#!/usr/bin/env python3
"""Guardian rules ratchet — the project's own written rules (CLAUDE.md), checked mechanically, with NO model.

Existing violations are recorded once in scripts/guardian/baseline.json; the check fails only when a file gets WORSE
(count above its baseline) or a new file breaks a rule, or on any secret. Improvements are never punished; run with --tighten to lock them in.
Usage: rules.py --check [--root DIR]   |   rules.py --baseline   |   rules.py --tighten   |   rules.py --report
Exit 0 ok, 1 new violations (listed), 2 usage error."""
import json, os, re, subprocess, sys

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.abspath(os.path.join(HERE, "..", ".."))
VENDOR = ("public/crinum/", "public/crm/css/classic.css", "public/crm/css/corporate.css", "public/crm/js/app.js", "vendor/", "node_modules/")
SKIP_DIRS = {".git", "vendor", "node_modules", ".claude", "docs", "tests", "ios", "android"}

# id -> (description, file-extension tuple, path-prefix tuple, regex, path-exclude regex or None, baselined?)
RULES = {
    "inline-style": ("CLAUDE.md rule 4: no inline <style> blocks in CRM pages (styles go in mowology-brand.css)", (".php",), ("public/crm/",), r"<style\b", r"public/crm/(templates/pdf|templates/email|js/|css/)", True),
    "hardcoded-hex": ("CLAUDE.md rule 5: no hard-coded 6-digit colour hex; use --mw-* tokens", (".php", ".js", ".css"), ("public/crm/",), r"#[0-9a-fA-F]{6}\b", r"mowology-brand\.css|ittai-tokens\.css|public/crm/templates/(pdf|email)|public/crm/js/(app|vendor)", True),
    "raw-mail": ("CLAUDE.md rule 9: never call mail()/PHPMailer from page code; use sendCrmEmail()/sendSms()", (".php",), ("public/", "app/"), r"(?<![A-Za-z0-9_>:$])mail\s*\(|new\s+\\?PHPMailer", r"Messaging|messaging\.php|Mailer|vendor|public/crinum", True),
    "native-date-input": ("CLAUDE.md rule 12: use MwDatePicker, not a visible <input type=date>", (".php", ".js"), ("public/crm/",), r"""type\s*=\s*["']date["']""", r"mw-datepicker", True),
    "mysql8-only": ("CLAUDE.md: production is MySQL 5.7 — no IF NOT EXISTS on ADD COLUMN/INDEX, no window or JSON functions, no utf8mb4_0900", (".sql", ".php"), ("database/", "app/Database/", "public/crm/database", "public/crm/migrations", "migrations/"), r"ADD\s+COLUMN\s+IF\s+NOT\s+EXISTS|CREATE\s+INDEX\s+IF\s+NOT\s+EXISTS|utf8mb4_0900|ROW_NUMBER\s*\(|\bRANK\s*\(\s*\)\s+OVER|JSON_EXTRACT\s*\(|JSON_ARRAY\s*\(", None, True),
    "secret": ("No credentials outside secrets.php (private keys, live Stripe keys, AWS keys)", (".php", ".js", ".json", ".sql", ".sh", ".py", ".swift", ".kt", ".plist", ".env"), ("",), r"-----BEGIN [A-Z ]*PRIVATE KEY-----\s*\n[A-Za-z0-9+/=]{40,}|sk_live_[0-9A-Za-z]{16,}|rk_live_[0-9A-Za-z]{16,}|AKIA[0-9A-Z]{16}", r"scripts/guardian/|SecurityPostureRulesTest", False),
}


def tracked_files(root):
    try:
        out = subprocess.run(["git", "-C", root, "ls-files"], capture_output=True, text=True, check=True).stdout.splitlines()
        return [f for f in out if os.path.exists(os.path.join(root, f))]
    except Exception:
        found = []
        for d, dirs, files in os.walk(root):
            dirs[:] = [x for x in dirs if x not in SKIP_DIRS]
            found += [os.path.relpath(os.path.join(d, f), root) for f in files]
        return found


def scan(root=ROOT):
    """{rule: {file: count}}"""
    res = {r: {} for r in RULES}
    files = tracked_files(root)
    for rid, (_, exts, prefixes, rx, excl, _b) in RULES.items():
        cre, ex = re.compile(rx), re.compile(excl) if excl else None
        for f in files:
            if not f.endswith(exts) or not f.startswith(prefixes) or f.startswith(VENDOR) or (ex and ex.search(f)):
                continue
            if rid != "secret" and any(f.startswith(p) for p in ("docs/", "tests/")):
                continue
            try:
                with open(os.path.join(root, f), encoding="utf-8", errors="ignore") as fh:
                    n = len(cre.findall(fh.read()))
            except OSError:
                continue
            if n:
                res[rid][f] = n
    return res


def load_baseline():
    p = os.path.join(HERE, "baseline.json")
    return json.load(open(p))["counts"] if os.path.exists(p) else {r: {} for r in RULES}


def compare(current, baseline):
    """Violations = files whose count exceeds the baseline (new files baseline 0); secrets always fail. Also reports improvements."""
    bad, better = [], []
    for rid, files in current.items():
        base = baseline.get(rid, {}) if RULES[rid][5] else {}
        for f, n in files.items():
            if n > base.get(f, 0):
                bad.append((rid, f, base.get(f, 0), n))
        for f, n in base.items():
            if files.get(f, 0) < n:
                better.append((rid, f, n, files.get(f, 0)))
    return bad, better


def write_baseline(current):
    json.dump({"_about": "Guardian ratchet baseline: existing rule violations per file. Counts may only go DOWN (python3 scripts/guardian/rules.py --tighten). Never raise a number to make a check pass.",
               "counts": {r: dict(sorted(f.items())) for r, f in current.items() if RULES[r][5]}}, open(os.path.join(HERE, "baseline.json"), "w"), indent=1)


def main(argv):
    global ROOT
    if "--root" in argv:
        ROOT = os.path.abspath(argv[argv.index("--root") + 1])
    cur = scan(ROOT)
    if "--baseline" in argv:
        write_baseline(cur); print("baseline written:", {r: sum(f.values()) for r, f in cur.items() if RULES[r][5]}); return 0
    if "--tighten" in argv:
        base = load_baseline(); bad, better = compare(cur, base)
        if bad:
            print("refusing to tighten: there are new violations"); return 1
        write_baseline(cur); print("tightened:", len(better), "improvement(s) locked in"); return 0
    base = load_baseline(); bad, better = compare(cur, base)
    if "--report" in argv:
        for rid, (d, *_r) in RULES.items():
            print("%-18s %5d violation(s) in %d file(s) — %s" % (rid, sum(cur[rid].values()), len(cur[rid]), d))
        return 0
    for rid, f, b, n in bad:
        print("RULE %-16s %s: %d (baseline %d) — %s" % (rid, f, n, b, RULES[rid][0]))
    if better:
        print("note: %d file(s) improved; run --tighten to lock it in" % len(better))
    if bad:
        print("guardian rules: %d new violation(s). Fix them; do not raise the baseline." % len(bad)); return 1
    print("guardian rules: ok (%d improved)" % len(better)); return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
