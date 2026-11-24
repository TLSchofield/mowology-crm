import importlib.util, json, os, re, sys, tempfile, unittest

os.environ["FORGE_AUTOSTART"] = "0"   # tests must never start a real build
HERE = os.path.dirname(os.path.abspath(__file__))
DEPT = os.path.join(HERE, "..")


def load(name):
    sys.path.insert(0, DEPT)
    spec = importlib.util.spec_from_file_location(name, os.path.join(DEPT, name + ".py"))
    m = importlib.util.module_from_spec(spec); sys.modules[name] = m; spec.loader.exec_module(m); return m


class Dashboard(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory(); t = self.tmp.name
        os.environ.update(DEPT_HOME=os.path.join(t, "d"), SENTINEL_HOME=os.path.join(t, "s"), GUARDIAN_HOME=os.path.join(t, "g"))
        fx = os.path.join(t, "m.json"); json.dump({"results": [], "site": {}}, open(fx, "w")); os.environ["GUARDIAN_STANDIN_METRICS"] = fx
        self.ada = load("ada"); self.dash = load("dashboard"); self.db = self.ada.connect(); self.ada.seed_permissions(self.db)

    def tearDown(self):
        for k in ("GUARDIAN_STANDIN_METRICS", "GUARDIAN_HOME"):
            os.environ.pop(k, None)
        self.tmp.cleanup()

    def test_empty_department_renders_every_section_without_error(self):
        h = self.dash.render(self.ada, self.db)
        for needle in ("Needs you", "Guardian", "Sentinel", "Commercial", "Department", "Permissions", "Recent runs", "Briefings", "Nothing is waiting for you"):
            self.assertIn(needle, h)

    def test_decisions_show_their_exact_commands_and_stay_in_the_terminal(self):
        tid = self.ada.add_task(self.db, "k", "Fix the thing", "improvement", "guardian", state="proposed", category="reliability", payload={"problem": "p"})[0]
        self.ada.prioritise(self.db)
        h = self.dash.render(self.ada, self.db)
        self.assertIn('ada approve %d --reason' % tid, h); self.assertIn("data-cmd", h)
        self.assertNotIn("<form", h); self.assertNotIn("fetch(", h); self.assertNotIn("XMLHttpRequest", h)     # no way to approve from the page

    def test_agent_and_web_text_is_escaped(self):
        evil = "<script>alert(1)</script><img src=x onerror=alert(2)>"
        self.ada.add_task(self.db, "x", evil, "improvement", "guardian", state="proposed", payload={"problem": evil})
        self.ada.prioritise(self.db)
        self.db.execute("INSERT INTO findings(key,severity,title,evidence,first_seen,status) VALUES('k','P2',?,?,?,'open')", (evil, json.dumps([evil]), "2026-11-25")); self.db.commit()
        h = self.dash.render(self.ada, self.db)
        self.assertNotIn("<script>alert(1)", h); self.assertNotIn("<img src=x", h); self.assertIn("&lt;script&gt;", h)

    def test_page_makes_no_external_requests(self):
        h = self.dash.render(self.ada, self.db)
        self.assertEqual(re.findall(r'(?:src|href)=["\']https?://', h), [])
        self.assertNotIn("@import", h); self.assertNotIn("<link", h)

    def test_write_is_atomic_and_cycle_refreshes_it(self):
        os.environ["ADA_SENTINEL_RUNNER"] = "/usr/bin/true"
        try:
            self.ada.cycle(self.db, "2026-11-25")
        finally:
            os.environ.pop("ADA_SENTINEL_RUNNER")
        p = os.path.join(self.ada.DEPT, "dashboard", "index.html")
        self.assertTrue(os.path.exists(p)); self.assertFalse(os.path.exists(p + ".tmp"))
        self.assertIn("Ittai department", open(p).read())

    def test_blocked_work_and_halts_are_visible(self):
        tid = self.ada.add_task(self.db, "b", "Stuck job", "audit", "guardian")[0]
        self.ada.transition(self.db, tid, "blocked", "system", "needs a human")
        self.ada.set_ctl(self.db, "paused", "now")
        h = self.dash.render(self.ada, self.db)
        self.assertIn("Blocked #%d" % tid, h); self.assertIn("Halted", h); self.assertIn("ada unblock %d" % tid, h)


if __name__ == "__main__":
    unittest.main()


class Cards(Dashboard):
    def test_every_agent_has_a_card_that_says_what_it_does_and_whether_it_is_built(self):
        h = self.dash.render(self.ada, self.db)
        for name, a in self.ada.roster().items():
            self.assertTrue(a.get("does") and a.get("limits"), name + " needs plain-language does/limits in roster.json")
            self.assertIn("<span class='name'>%s</span>" % name, h)
        for words in ("Not built yet", "Built · running nightly", "Built · switched off", "Built · asleep", "Build it when:"):
            self.assertIn(words, h)

    def test_a_built_task_offers_release_only_in_the_authenticated_page(self):
        tid = self.ada.add_task(self.db, "k", "Header button", "improvement", "sentinel", state="awaiting_release_approval", actor="sentinel")[0]
        self.db.execute("UPDATE tasks SET result=? WHERE id=?", (json.dumps({"branch": "forge/1-header", "files": ["public/includes/header.php"], "lines": 6, "verification": ["full test suite: ok"], "review_verdict": "PASS"}), tid)); self.db.commit()
        static, live = self.dash.render(self.ada, self.db), self.dash.render(self.ada, self.db, interactive=True)
        self.assertIn("Built and checked", static); self.assertIn("ada release %d" % tid, static); self.assertNotIn("data-decision='release'", static)
        self.assertIn("data-decision='release'", live); self.assertIn("not deployed", live)

    def test_the_inline_script_is_valid_javascript(self):
        import subprocess, shutil
        node = shutil.which("node") or "/usr/local/bin/node"
        if not os.path.exists(node):
            self.skipTest("node not available")
        js = self.dash.JS + self.dash.ACT_JS
        p = subprocess.run([node, "--check", "-"], input=js, capture_output=True, text=True)
        self.assertEqual(p.returncode, 0, p.stderr)
