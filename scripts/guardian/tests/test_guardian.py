import copy, importlib.util, json, os, subprocess, sys, tempfile, unittest

os.environ["FORGE_AUTOSTART"] = "0"   # tests must never start a real build
HERE = os.path.dirname(os.path.abspath(__file__))
G_DIR = os.path.join(HERE, "..")
DEPT = os.path.join(HERE, "..", "..", "dept")


def load(path, name):
    sys.path.insert(0, os.path.dirname(path))
    spec = importlib.util.spec_from_file_location(name, path)
    m = importlib.util.module_from_spec(spec); spec.loader.exec_module(m); return m


def page(name="P", width=375, **kw):
    m = {"title": "T", "titleLen": 1, "description": "d", "descriptionLen": 1, "canonical": "", "lang": "en", "viewportMeta": True, "h1": 1, "hOrderSkips": 0,
         "overflowX": False, "scrollWidth": width, "innerWidth": width, "smallTargets": 0, "smallTargetSamples": [], "tinyText": 0, "tinyTextSamples": [],
         "unlabeledInputs": 0, "imagesNoAlt": 0, "emptyLinks": 0, "transferKB": 300, "domNodes": 500, "lcpMs": 900, "cls": 0.01}
    m.update(kw.pop("measure", {}))
    r = {"page": name, "url": "https://x.example/" + name, "width": width, "ok": True, "status": 200, "measure": m, "axe": [], "consoleErrors": [], "failedRequests": []}
    r.update(kw); return r


SITE = {"https://x.example": {"robotsStatus": 200, "robotsHasSitemap": True, "robotsBlocksAll": False, "sitemapStatus": 200, "sitemapUrls": 5}}


class Findings(unittest.TestCase):
    def setUp(self):
        self.G = load(os.path.join(G_DIR, "guardian.py"), "guardian")

    def f(self, results, site=SITE, rules="", prev=None):
        return self.G.findings_from({"results": results, "site": site}, rules, prev)

    def test_healthy_pages_produce_no_findings(self):
        self.assertEqual(self.f([page(width=w) for w in (375, 768, 1440)]), [])

    def test_a_page_that_fails_to_load_is_p1(self):
        r = page(); r.update(ok=False, status=None, error="timeout")
        self.assertEqual([x["severity"] for x in self.f([r])], ["P1"])
        r2 = page(); r2["status"] = 500
        self.assertEqual(self.f([r2])[0]["key"], "page-down|P")

    def test_sideways_scroll_weight_and_speed_thresholds(self):
        out = {x["key"]: x["severity"] for x in self.f([page(measure={"overflowX": True, "scrollWidth": 500, "transferKB": 17000, "lcpMs": 4000, "cls": 0.5})])}
        self.assertEqual(out, {"overflow-x|P": "P1", "page-weight|P": "P1", "lcp|P": "P2", "cls|P": "P2"})
        self.assertEqual(self.f([page(measure={"transferKB": 3500})])[0]["severity"], "P2")
        self.assertEqual(self.f([page(measure={"transferKB": 2900})]), [])

    def test_same_issue_at_three_widths_is_one_finding_with_worst_severity(self):
        ax = [{"id": "color-contrast", "impact": "serious", "help": "contrast", "nodes": 2, "sample": ".a"}]
        out = self.f([page(width=w, axe=ax) for w in (375, 768, 1440)])
        self.assertEqual(len(out), 1); self.assertEqual(out[0]["severity"], "P2")
        crit = [dict(ax[0], impact="critical")]
        self.assertEqual(self.f([page(width=375, axe=ax), page(width=768, axe=crit)])[0]["severity"], "P1")

    def test_minor_axe_impact_is_ignored_and_tap_targets_are_p3(self):
        self.assertEqual(self.f([page(axe=[{"id": "region", "impact": "moderate", "help": "h", "nodes": 9}])]), [])
        o = self.f([page(measure={"smallTargets": 3, "smallTargetSamples": [{"what": "x", "w": 20, "h": 20}]})])
        self.assertEqual((o[0]["severity"], o[0]["area"]), ("P3", "ux"))

    def test_site_level_seo_checks(self):
        bad = {"https://x.example": {"robotsStatus": 200, "robotsHasSitemap": False, "robotsBlocksAll": False, "sitemapStatus": 404, "sitemapUrls": 0}}
        self.assertEqual([x["key"] for x in self.f([], bad)], ["sitemap|x.example"])
        block = {"https://x.example": dict(SITE["https://x.example"], robotsBlocksAll=True)}
        self.assertEqual(self.f([], block)[0]["severity"], "P0")

    def test_regression_against_previous_run(self):
        prev = {"P": {"transferKB": 300, "lcpMs": 800}}
        keys = [x["key"] for x in self.f([page(measure={"transferKB": 900, "lcpMs": 2000})], prev=prev)]
        self.assertIn("regress-weight|P", keys); self.assertIn("regress-lcp|P", keys)
        self.assertEqual(self.f([page(measure={"transferKB": 350})], prev=prev), [])

    def test_regression_compares_phone_to_phone_not_phone_to_desktop(self):
        prev = {"P": {"transferKB": 178, "lcpMs": 800}}
        rows = [page(width=375, measure={"transferKB": 180}), page(width=1440, measure={"transferKB": 410, "lcpMs": 1200})]
        self.assertEqual(self.f(rows, prev=prev), [])

    def test_new_rule_violations_become_findings_and_secrets_are_p0(self):
        out = self.f([], rules="RULE secret           app/x.php: 1 (baseline 0) — msg\nRULE inline-style     public/crm/a.php: 2 (baseline 1) — msg\n")
        self.assertEqual({x["key"]: x["severity"] for x in out}, {"rule|app/x.php": "P0", "rule|public/crm/a.php": "P2"})

    def test_a_failing_iphone_compile_is_p1_and_a_clean_one_is_silent(self):
        bad = {"results": [], "site": {}, "ios": {"ok": False, "files": 214, "errors": 2, "warnings": 9, "first_error": "Foo.swift:3:1: error: cannot find 'x' in scope"}}
        out = self.G.findings_from(bad, "")
        self.assertEqual([(f["key"], f["severity"], f["area"]) for f in out], [("ios-compile|MowologyCRM", "P1", "ios")])
        self.assertIn("cannot find", out[0]["evidence"][0])
        self.assertEqual(self.G.findings_from({"results": [], "site": {}, "ios": {"ok": True, "files": 214, "errors": 0, "warnings": 9, "first_error": ""}}, ""), [])

    def test_ios_typecheck_is_skipped_without_ios_code(self):
        with tempfile.TemporaryDirectory() as t:
            self.assertIsNone(self.G.ios_typecheck(t)); self.assertIsNone(self.G.ios_typecheck(None))

    def test_missing_basics_are_reported(self):
        keys = {x["key"] for x in self.f([page(measure={"h1": 0, "description": "", "lang": "", "imagesNoAlt": 2, "viewportMeta": False})])}
        self.assertEqual(keys, {"h1|P", "description|P", "lang|P", "alt|P", "viewport|P"})


class Rules(unittest.TestCase):
    def setUp(self):
        self.R = load(os.path.join(G_DIR, "rules.py"), "rules")

    def test_repo_is_clean_against_its_baseline(self):
        root = os.path.abspath(os.path.join(HERE, "..", "..", ".."))
        bad, _ = self.R.compare(self.R.scan(root), self.R.load_baseline())
        self.assertEqual(bad, [])

    def test_ratchet_logic(self):
        cur = {"inline-style": {"a.php": 2, "new.php": 1}, "secret": {"k.php": 1}, "hardcoded-hex": {}, "raw-mail": {}, "native-date-input": {}, "mysql8-only": {}}
        base = {"inline-style": {"a.php": 2, "gone.php": 3}}
        bad, better = self.R.compare(cur, base)
        self.assertEqual(sorted((r, f) for r, f, *_ in bad), [("inline-style", "new.php"), ("secret", "k.php")])
        self.assertEqual([(r, f) for r, f, *_ in better], [("inline-style", "gone.php")])
        cur["inline-style"]["a.php"] = 3
        self.assertIn(("inline-style", "a.php"), [(r, f) for r, f, *_ in self.R.compare(cur, base)[0]])   # getting worse fails

    def test_pem_wrapper_strings_are_not_secrets_but_real_keys_are(self):
        import re
        rx = re.compile(self.R.RULES["secret"][3])
        self.assertIsNone(rx.search('"-----BEGIN PRIVATE KEY-----\\n" . chunk_split($b)'))
        self.assertTrue(rx.search("-----BEGIN PRIVATE KEY-----\n" + "A" * 64))
        self.assertTrue(rx.search("sk_live_" + "a" * 24))


class Engine(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory(); t = self.tmp.name
        os.environ.update(DEPT_HOME=os.path.join(t, "dept"), SENTINEL_HOME=os.path.join(t, "sen"), GUARDIAN_HOME=os.path.join(t, "g"), GUARDIAN_DIR=G_DIR)
        os.environ.pop("ADA_CLAUDE_BIN", None)
        self.ada = load(os.path.join(DEPT, "ada.py"), "ada"); self.db = self.ada.connect(); self.ada.seed_permissions(self.db)
        self.fix = os.path.join(t, "metrics.json"); os.environ["GUARDIAN_STANDIN_METRICS"] = self.fix

    def tearDown(self):
        for k in ("GUARDIAN_STANDIN_METRICS", "GUARDIAN_DIR", "GUARDIAN_HOME"):
            os.environ.pop(k, None)
        self.tmp.cleanup()

    def run_with(self, results):
        json.dump({"results": results, "site": SITE}, open(self.fix, "w"))
        tid = self.ada.add_task(self.db, "g:%d" % self.db.execute("SELECT COUNT(*) c FROM tasks").fetchone()["c"], "g", "audit", "guardian")[0]
        return self.ada.tick(self.db, max_runs=1), tid

    def test_findings_queue_once_and_close_when_fixed(self):
        bad = page(measure={"transferKB": 17000})
        res, _ = self.run_with([bad]); self.assertIn("1 open", res[0][1])
        self.assertEqual(self.db.execute("SELECT state, category FROM tasks WHERE agent='guardian' AND kind='improvement'").fetchone()["category"], "reliability")
        self.run_with([bad])                                                       # same finding again: no second task
        self.assertEqual(self.db.execute("SELECT COUNT(*) c FROM tasks WHERE kind='improvement'").fetchone()["c"], 1)
        res, _ = self.run_with([page()])                                           # fixed
        self.assertIn("1 resolved", res[0][1])
        self.assertEqual(self.db.execute("SELECT state FROM tasks WHERE kind='improvement'").fetchone()["state"], "rejected")
        self.assertEqual(self.db.execute("SELECT status FROM findings").fetchone()["status"], "resolved")

    def test_critical_findings_reach_the_briefing_critical_section(self):
        self.run_with([page(measure={"transferKB": 17000})])
        self.ada.prioritise(self.db)
        text = open(self.ada.write_briefing(self.db, "2026-11-25")).read()
        self.assertIn("transfers", text.split("## Critical issues")[1].split("##")[0]); self.assertIn("Guardian (measured", text)

    def test_ordinary_findings_respect_the_three_decision_cap(self):
        res = [page(name="Pg%d" % i, measure={"smallTargets": 2, "smallTargetSamples": [{"what": "a", "w": 1, "h": 1}]}) for i in range(6)]
        self.run_with(res); self.ada.prioritise(self.db)
        self.assertEqual(self.db.execute("SELECT COUNT(*) c FROM tasks WHERE state='awaiting_approval'").fetchone()["c"], 3)

    def test_guardian_runs_without_the_cli_signed_in(self):
        json.dump({"results": [page()], "site": SITE}, open(self.fix, "w"))
        os.environ["ADA_SENTINEL_RUNNER"] = "/usr/bin/true"
        try:
            self.ada.cycle(self.db, "2026-11-25")
        finally:
            os.environ.pop("ADA_SENTINEL_RUNNER")
        self.assertEqual(self.db.execute("SELECT state FROM tasks WHERE agent='guardian' AND kind='audit'").fetchone()["state"], "completed")
        self.assertTrue(self.ada.permitted(self.db, "guardian", "verify-readonly"))


if __name__ == "__main__":
    unittest.main()
