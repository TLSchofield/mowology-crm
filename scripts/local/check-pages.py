#!/usr/bin/env python3
"""
LOCAL TEST ONLY — render chosen pages on the local site as a role and check each one.

    python3 scripts/local/check-pages.py admin /crm/tax-report_appstack.php?year=2026 /crm/timeclock/payroll-summary.php
    python3 scripts/local/check-pages.py crew  /crm/jobs/schedule.php --grep "mw-proximity-job"

Roles and passwords come from scripts/local/.credentials.local (seed.php). Each page must answer
200, render to </html>, and show no PHP fatal/warning text (smoke.py's rules). --grep PATTERN
prints how many times PATTERN appears on each page (e.g. to prove a crew page no longer carries
other crews' jobs). Exit 1 if any page fails. Built 2026-10-14 for the Codex-fix batches.
"""
import os, re, sys
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
bad = 0
for path in rest:
    good, st, note = smoke.check_page(s, path, 200)
    extra = ""
    if grep and good:
        _, _, body = s.req(path)
        extra = f"  [{grep}: {len(re.findall(grep, body))}]"
    print(("OK  " if good else "FAIL") + f" {st} {path} — {note}{extra}")
    bad += 0 if good else 1
sys.exit(1 if bad else 0)
