#!/usr/bin/env python3
"""
LOCAL TEST ONLY — render chosen pages on the local site as a role and check each one.

    python3 scripts/local/check-pages.py admin /crm/tax-report_appstack.php?year=2026 /crm/timeclock/payroll-summary.php
    python3 scripts/local/check-pages.py crew  /crm/jobs/schedule.php --grep "mw-proximity-job"

Roles and passwords come from scripts/local/.credentials.local (seed.php). Each page must answer
200, render to </html>, and show no PHP fatal/warning text (smoke.py's rules). --grep PATTERN
prints how many times PATTERN appears on each page (e.g. to prove a crew page no longer carries
other crews' jobs). Exit 1 if any page fails. Built 2026-10-14 for the Codex-fix batches.
Every inline <script> on the rendered page is also syntax-checked with node (2026-10-15: one stray ')'
in expenses_appstack.php passed every PHP check and left the expenses list on "Loading…" for 2 hours).
"""
import os, re, shutil, subprocess, sys, tempfile
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import smoke  # noqa: E402  (refuses anything but localhost)

args = sys.argv[1:]
if len(args) < 2:
    sys.exit(__doc__)
role, rest = args[0], args[1:]
grep = None
if "--grep" in rest:
    i = rest.index("--grep"); grep = rest[i + 1]; rest = rest[:i] + rest[i + 2:]
creds, _ = smoke.load_creds()
if role not in creds:
    sys.exit(f"no '{role}' in .credentials.local (have: {', '.join(creds)})")
s = smoke.Session()
ok, why = s.login(*creds[role])
if not ok:
    sys.exit(f"login as {role} failed: {why}")
def script_errors(body):
    """node --check each inline script of the rendered page; [] when node is missing."""
    if not shutil.which("node"):
        return []
    out = []
    for i, m in enumerate(re.finditer(r"<script(?![^>]*\bsrc=)(?![^>]*type=[\"'](?:application/(?:ld\+)?json|text/template)[\"'])[^>]*>(.*?)</script>", body, re.S)):
        with tempfile.NamedTemporaryFile("w", suffix=".js", delete=False) as f:
            f.write(m.group(1))
        r = subprocess.run(["node", "--check", f.name], capture_output=True, text=True)
        os.unlink(f.name)
        if r.returncode:
            line = body[:m.start()].count("\n") + 1
            msg = next((l for l in r.stderr.splitlines() if "Error" in l), r.stderr.strip()[:120])
            out.append(f"script {i + 1} (page line {line}): {msg}")
    return out

bad = 0
for path in rest:
    good, st, note = smoke.check_page(s, path, 200)
    if good:
        _, _, body = s.req(path)
        errs = script_errors(body)
        if errs:
            good, note = False, "JS syntax: " + "; ".join(errs[:3])
    extra = ""
    if grep and good:
        _, _, body = s.req(path)
        extra = f"  [{grep}: {len(re.findall(grep, body))}]"
    print(("OK  " if good else "FAIL") + f" {st} {path} — {note}{extra}")
    bad += 0 if good else 1
sys.exit(1 if bad else 0)
