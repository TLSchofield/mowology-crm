import importlib.util, json, os, re, sys, tempfile, unittest

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
