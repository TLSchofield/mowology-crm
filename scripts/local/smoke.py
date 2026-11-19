#!/usr/bin/env python3
"""
LOCAL TEST ONLY — smoke test for the local Mowology site (http://localhost:8000).

    python3 scripts/local/smoke.py            # or: scripts/local-site.sh smoke
    python3 scripts/local/smoke.py --http     # skip the browser pass even if Playwright is installed

Logs in as admin, manager and crew (scripts/local/.credentials.local, written by seed.php),
opens the key pages and checks each one:
  * HTTP status is what the role should get (200, or 403 where the role is gated out);
  * a 200 page renders to </html> and has no "Fatal error" / "Parse error" / "Warning:" /
    "Uncaught" text (PHP display_errors is ON locally, so a fatal shows up instead of a
    silently truncated page as on production).
Then checks the role gates for crew: clients bulk delete, contract edit, reports, users.

Two passes:
  1. HTTP (stdlib only, always runs) — status, </html>, PHP error text, role gates.
  2. Browser (Playwright, only if `import playwright` works) — renders each 200 page in the
     installed Google Chrome (channel="chrome", no browser download) and reports JS console
     errors. Every request to a host other than localhost is aborted, so a page can never call
     Google, Stripe or anything else from the browser either.

Refuses to run against anything but localhost / 127.0.0.1.
"""
import html
import http.cookiejar
import json
import os
import re
import sys
import urllib.error
import urllib.parse
import urllib.request

BASE = os.environ.get("LOCAL_SITE_URL", "http://localhost:8000").rstrip("/")
HERE = os.path.dirname(os.path.abspath(__file__))
CRED_FILE = os.path.join(HERE, ".credentials.local")
FAIL_PATTERNS = [r"Fatal error", r"Parse error", r"Warning:", r"Uncaught "]
NOTE_PATTERNS = [r"Deprecated:", r"Notice:"]

if urllib.parse.urlparse(BASE).hostname not in ("localhost", "127.0.0.1"):
    sys.exit("REFUSING: smoke.py only runs against localhost.")


def load_creds():
    creds, portal = {}, None
    with open(CRED_FILE) as fh:
        for line in fh:
            m = re.search(r"token=([a-f0-9]+)", line)
            if m:
                portal = "/customer/invoice.php?token=" + m.group(1)
            if line.startswith("#") or not line.strip():
                continue
            role, email, pw = line.split()[:3]
            creds[role] = (email, pw)
    return creds, portal


class Session:
    def __init__(self):
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar))

    def req(self, path, data=None, headers=None, method=None):
        url = path if path.startswith("http") else BASE + path
        body = None
        if data is not None:
            body = data if isinstance(data, bytes) else urllib.parse.urlencode(data).encode()
        r = urllib.request.Request(url, data=body, headers=headers or {}, method=method)
        try:
            resp = self.opener.open(r, timeout=60)
            return resp.status, resp.geturl(), resp.read().decode("utf-8", "replace")
        except urllib.error.HTTPError as e:
            return e.code, url, e.read().decode("utf-8", "replace")

    def login(self, email, pw):
        _, _, page = self.req("/loginAuth/login.php")
        m = re.search(r'name="csrf_token" value="([^"]+)"', page)
        if not m:
            return False, "no login CSRF field"
        st, final, body = self.req("/loginAuth/login.php", {"csrf_token": html.unescape(m.group(1)), "email": email, "password": pw})
        ok = "/loginAuth/login.php" not in final
        return ok, f"landed on {urllib.parse.urlparse(final).path}" if ok else "still on login page"


def csrf_from(page):
    for pat in (r"MW_CSRF_TOKEN\s*=\s*'([^']+)'", r'name="csrf_token"\s+value="([^"]+)"', r"csrf_token:\s*'([^']+)'"):
        m = re.search(pat, page)
        if m:
            return html.unescape(m.group(1))
    return ""


def check_page(sess, path, expect):
    st, final, body = sess.req(path)
    fpath = urllib.parse.urlparse(final).path
    if "/loginAuth/login.php" in fpath and "/loginAuth/" not in path:
        return False, st, "redirected to login"
    if st != expect:
        snippet = re.sub(r"<[^>]+>", " ", body)
        snippet = re.sub(r"\s+", " ", snippet).strip()[:160]
        return False, st, f"expected {expect}: {snippet}"
    if expect != 200:
        return True, st, "refused as expected"
    problems = []
    if "</html>" not in body.lower():
        problems.append("no </html> (truncated)")
    for p in FAIL_PATTERNS:
        m = re.search(p + r".{0,200}", body, re.S)
        if m:
            problems.append(re.sub(r"<[^>]+>|\s+", " ", m.group(0))[:220].strip())
    notes = sum(len(re.findall(p, body)) for p in NOTE_PATTERNS)
    if problems:
        return False, st, "; ".join(problems)
    return True, st, f"{len(body)//1024} KB" + (f", {notes} deprecation/notice lines" if notes else "")


def main():
    use_browser = "--http" not in sys.argv
    creds, portal = load_creds()
    results = []  # (role, check, ok, status, detail)

    # IDs of the seeded records (seed is deterministic on a fresh reset).
    admin = Session()
    ok, why = admin.login(*creds["admin"])
    results.append(("admin", "login", ok, "", why))
    if not ok:
        print_table(results)
        return 1
    ids = discover_ids(admin)

    pages = [
        ("dashboard",          "/crm/dashboard_appstack.php"),
        ("schedule",           "/crm/jobs/schedule.php"),
        ("jobs/view",          f"/crm/jobs/view.php?id={ids['plan']}"),
        ("invoices list",      "/crm/invoices/index.php"),
        ("invoice view",       f"/crm/invoices/view.php?id={ids['invoice']}"),
        ("clients",            "/crm/clients_appstack.php"),
        ("quotes",             "/crm/quotes_appstack.php"),
        ("contracts/view",     f"/crm/contracts/view.php?id={ids['contract']}"),
        ("reports",            "/crm/reports_appstack.php"),
        ("settings",           "/crm/settings.php"),
        ("users",              "/crm/users_appstack.php"),
        ("supplier price lists", "/crm/suppliers/price-lists.php"),
        ("ledger",             "/crm/ledger/"),
        ("ledger journal",     "/crm/ledger/journal.php"),
        ("ledger entry",       "/crm/ledger/entry.php?id=1"),
        ("ledger gifi",        "/crm/ledger/gifi.php"),
        ("ledger exceptions",  "/crm/ledger/exceptions.php"),
        ("trial balance",      "/crm/accounting/trial-balance.php"),
        ("ledger access",      "/crm/ledger/invite.php"),
    ]
    LEDGER = ("ledger", "ledger journal", "ledger entry", "ledger gifi", "ledger exceptions", "trial balance", "ledger access")
    # What each role should get (anything not listed = 200).
    expect = {
        "admin":   {},
        "manager": {"settings": 403, "users": 403, "ledger access": 403},
        "crew":    {"reports": 403, "users": 403, **{n: 403 for n in LEDGER}},
    }
    sessions = {"admin": admin}
    for role in ("manager", "crew"):
        s = Session()
        ok, why = s.login(*creds[role])
        results.append((role, "login", ok, "", why))
        sessions[role] = s

    browser_targets = []
    for role in ("admin", "manager", "crew"):
        s = sessions[role]
        for name, path in pages:
            if role == "crew" and name not in ("schedule", "jobs/view", "clients", "contracts/view", "reports", "users", "supplier price lists") + LEDGER:
                continue  # crew: field pages + the gates below
            exp = expect[role].get(name, 200)
            ok, st, detail = check_page(s, path, exp)
            results.append((role, name, ok, st, detail))
            if ok and exp == 200:
                browser_targets.append((role, name, path))
    if "crew" in sessions:
        for name, path in (("crew app-launch", "/crm/app-launch.php"), ("crew driver-portal", "/crm/driver-portal.php")):
            ok, st, detail = check_page(sessions["crew"], path, 200)
            results.append(("crew", name, ok, st, detail))

    # Customer portal invoice via token (no login).
    anon = Session()
    if portal:
        ok, st, detail = check_page(anon, portal, 200)
        st2, _, body = anon.req(portal)
        if ok and ids["invoice_number_sent"] not in body:
            ok, detail = False, "portal page did not show the invoice number"
        results.append(("public", "portal invoice (token)", ok, st, detail))
        ok, st, detail = check_page(anon, "/customer/invoice.php?token=" + "0" * 64, 200)
        st3, _, body3 = anon.req("/customer/invoice.php?token=" + "0" * 64)
        results.append(("public", "portal bad token refused", "invalid or has expired" in body3, st3, "error shown" if "invalid or has expired" in body3 else "no error text"))

    # Router denies (production .htaccess equivalents).
    for path in ("/app/Core/config.php", "/vendor/autoload.php", "/CLAUDE.md", "/app_config/secrets.php", "/sessions/x"):
        st, _, _ = anon.req(path)
        results.append(("public", f"deny {path}", st == 403, st, ""))

    # ── Crew role gates ──
    crew = sessions["crew"]
    # 1. Clients bulk delete → 403 (target: the seeded "Delete-Me Test Client").
    st, _, page = crew.req("/crm/clients_appstack.php")
    if st != 200:
        st, _, page = crew.req("/crm/jobs/schedule.php")
    token = csrf_from(page)
    st, _, body = crew.req("/crm/clients_appstack.php?action=bulk_delete",
                           json.dumps({"ids": [ids["delete_me_client"]], "csrf_token": token}).encode(),
                           {"Content-Type": "application/json"}, "POST")
    _, _, check = admin.req("/crm/clients_appstack.php")
    still_there = "Delete-Me Test Client" in check
    results.append(("crew", "GATE clients bulk delete", st == 403 and still_there, st,
                    (body.strip()[:120] if st != 403 else "403 refused") + ("" if still_there else " — CLIENT WAS DELETED")))

    # 2. Contract edit → refused, title unchanged.
    cpath = f"/crm/contracts/view.php?id={ids['contract']}"
    st, _, page = crew.req(cpath)
    if st == 200:
        token = csrf_from(page)
        st, _, body = crew.req(cpath, {"csrf_token": token, "action": "update_contract", "title": "CREW EDIT ATTEMPT",
                                       "billing_cycle": "monthly", "billing_amount": "1"})
        _, _, after = admin.req(cpath)
        refused = "You do not have permission to change contracts" in body and "CREW EDIT ATTEMPT" not in after
        results.append(("crew", "GATE contract edit", refused, st, "refused, title unchanged" if refused else "EDIT WENT THROUGH or no refusal text"))
    else:
        results.append(("crew", "GATE contract edit", st == 403, st, "view itself refused"))

    # 3/4. Reports and users → 403 (already in the page loop; repeated as explicit gate rows).
    for name, path in (("GATE reports", "/crm/reports_appstack.php"), ("GATE users", "/crm/users_appstack.php")):
        st, final, _ = crew.req(path)
        results.append(("crew", name, st == 403, st, "403 refused" if st == 403 else "crew can open it"))

    # ── The accountant: the Ledger and nothing else (migration 1355, AccountantFence) ──
    if "accountant" in creds:
        acct = Session()
        ok, why = acct.login(*creds["accountant"])
        results.append(("accountant", "login lands on the Ledger", ok and "/crm/ledger/" in why, "", why))
        sessions["accountant"] = acct
        for name in LEDGER:
            path = dict(pages)[name]
            exp = 403 if name == "ledger access" else 200
            ok, st, detail = check_page(acct, path, exp)
            results.append(("accountant", name, ok, st, detail))
            if ok and exp == 200:
                browser_targets.append(("accountant", name, path))
        _, _, gp = acct.req("/crm/ledger/gifi.php")
        ready = "Ready to export" in gp
        st, _, body = acct.req("/crm/ledger/gifi.php?export=csv")
        ok = (st == 200 and '"GIFI code",Amount' in body) if ready else st == 409
        results.append(("accountant", "GIFI export follows the validator", ok, st,
                        ("validates → CSV" if ready else "blocked (fresh reset: nothing mapped)") if ok else body[:80]))
        st, _, body = acct.req("/crm/ledger/journal.php?export=csv")
        results.append(("accountant", "journal CSV export", st == 200 and "Entity" in body[:40], st, f"{len(body)} bytes"))
        for name, path in (("GATE clients", "/crm/clients_appstack.php"), ("GATE schedule", "/crm/jobs/schedule.php"),
                           ("GATE payroll / SIN profile", "/crm/team/profile.php?id=1"), ("GATE payroll summary", "/crm/timeclock/payroll-summary.php"),
                           ("GATE crew GPS map", "/crm/map.php"), ("GATE crew GPS api", "/crm/api/crew-location.php"),
                           ("GATE settings / bank", "/crm/settings.php"), ("GATE chart of accounts", "/crm/accounting/chart-of-accounts.php"),
                           ("GATE invoice page", f"/crm/invoices/view.php?id={ids['invoice']}"), ("GATE users", "/crm/users_appstack.php"),
                           ("GATE expenses", "/crm/expenses_appstack.php"), ("GATE path trick", "/crm/ledger/../clients_appstack.php")):
            st, final, body = acct.req(path)
            refused = st == 403 or (name == "GATE path trick" and 400 <= st < 500)
            results.append(("accountant", name, refused, st, f"{st} refused" if refused else f"reached {urllib.parse.urlparse(final).path}"))
        st, final, _ = acct.req("/crm/dashboard_appstack.php")
        results.append(("accountant", "dashboard → Ledger", urllib.parse.urlparse(final).path == "/crm/ledger/", st, urllib.parse.urlparse(final).path))
        st, _, body = Session().req("/api/auth/token.php", json.dumps({"email": creds["accountant"][0], "password": creds["accountant"][1]}).encode(),
                               {"Content-Type": "application/json"}, "POST")
        results.append(("accountant", "no app (JWT) token", st in (401, 403) and '"token"' not in body, st, body.strip()[:80]))
        _, _, page = admin.req("/crm/ledger/invite.php")
        logged = "accountant@local.test" in page and re.search(r"accountant@local\.test.*?\d{4}-\d{2}-\d{2} \d{2}:\d{2}", page, re.S) is not None
        results.append(("admin", "accountant's views are logged", logged, "", "last Ledger page shown" if logged else "no ledger.view row seen"))

    # ── Optional browser pass ──
    if use_browser:
        browser_pass(creds, browser_targets, results)

    print_table(results)
    return 0 if all(r[2] for r in results) else 1


def discover_ids(admin):
    """Seeded record ids. The seed is deterministic on a fresh reset: plan 1, contract 1,
    client 11 = "Delete-Me Test Client", the first 'sent' invoice is INV-<year>-0002.
    The invoice id is read from the invoices list so a re-seeded DB still works."""
    import datetime
    ids = {"plan": 1, "invoice": 1, "contract": 1, "delete_me_client": 11,
           "invoice_number_sent": f"INV-{datetime.date.today().year}-0002"}
    _, _, page = admin.req("/crm/invoices/index.php")
    m = re.search(r"/crm/invoices/view\.php\?id=(\d+)", page)
    if m:
        ids["invoice"] = int(m.group(1))
    return ids


def browser_pass(creds, targets, results):
    try:
        from playwright.sync_api import sync_playwright
    except ImportError:
        results.append(("browser", "Playwright pass", True, "", "skipped — `pip3 install playwright` to enable (uses installed Chrome)"))
        return
    with sync_playwright() as pw:
        try:
            browser = pw.chromium.launch(channel="chrome", headless=True)
        except Exception:
            browser = pw.chromium.launch(headless=True)
        for role in ("admin", "manager", "crew", "accountant"):
            if role not in creds:
                continue
            ctx = browser.new_context()
            ctx.route("**/*", lambda route: route.continue_() if urllib.parse.urlparse(route.request.url).hostname in ("localhost", "127.0.0.1") else route.abort())
            page = ctx.new_page()
            page.goto(BASE + "/loginAuth/login.php")
            page.fill('input[name="email"]', creds[role][0])
            page.fill('input[name="password"]', creds[role][1])
            page.click('button[type="submit"]')
            page.wait_for_load_state("networkidle")
            for r, name, path in targets:
                if r != role:
                    continue
                errors = []
                handler = lambda e, errors=errors: errors.append(str(e)[:120])
                page.on("pageerror", handler)
                resp = page.goto(BASE + path, wait_until="load")
                page.wait_for_timeout(500)
                page.remove_listener("pageerror", handler)
                ok = resp is not None and resp.status == 200 and not errors
                results.append((f"{role}/browser", name, ok, resp.status if resp else "", "; ".join(errors[:2]) or "no JS errors"))
            ctx.close()
        browser.close()


def print_table(results):
    w1 = max(len(str(r[0])) for r in results)
    w2 = max(len(str(r[1])) for r in results)
    print(f"\n{'ROLE'.ljust(w1)}  {'CHECK'.ljust(w2)}  RESULT  HTTP  DETAIL")
    print("-" * (w1 + w2 + 40))
    for role, name, ok, st, detail in results:
        print(f"{str(role).ljust(w1)}  {str(name).ljust(w2)}  {'PASS' if ok else 'FAIL'}    {str(st).ljust(4)}  {detail}")
    n_fail = sum(1 for r in results if not r[2])
    print(f"\n{len(results) - n_fail} passed, {n_fail} failed")


if __name__ == "__main__":
    sys.exit(main())
