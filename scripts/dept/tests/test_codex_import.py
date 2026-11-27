import importlib.util, json, os, sys, tempfile, unittest

os.environ["FORGE_AUTOSTART"] = "0"
HERE = os.path.dirname(os.path.abspath(__file__))
DEPT = os.path.join(HERE, "..")


def load(name):
    sys.path.insert(0, DEPT)
    spec = importlib.util.spec_from_file_location(name, os.path.join(DEPT, name + ".py"))
    m = importlib.util.module_from_spec(spec); sys.modules[name] = m; spec.loader.exec_module(m); return m


F = [
    {"unit": "crew-08", "severity": "high", "title": "Visit overwrites minutes", "status": "left", "note": "real; fix ready on codex-fixes/2026-11-24, needs Tim's yes to deploy", "date": "2026-11-24"},
    {"unit": "crew-08", "severity": "High", "title": "Needs a transaction", "status": "left", "note": "real; needs transaction design", "date": "2026-11-24"},
    {"unit": "auth-3", "severity": "Low", "title": "Cancel via GET", "status": "decided", "note": "nuisance only", "date": "2026-11-18"},
    {"unit": "crew-08", "severity": "Low", "title": "Already fixed", "status": "fixed", "note": "done", "date": "2026-11-24"},
    {"unit": "crew-08", "severity": "Low", "title": "Not real", "status": "not_real", "note": "no", "date": "2026-11-24"},
]


class CodexImport(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory(); t = self.tmp.name
        os.environ.update(DEPT_HOME=os.path.join(t, "d"), SENTINEL_HOME=os.path.join(t, "s"), GUARDIAN_HOME=os.path.join(t, "g"))
        self.ada = load("ada"); self.ci = load("codex_import"); self.dash = load("dashboard")
        self.db = self.ada.connect()

    def tearDown(self):
        self.tmp.cleanup()

    def test_only_open_findings_are_imported_and_bucketed(self):
        r = self.ci.import_codex(self.db, self.ada.now, F)
        self.assertEqual((r["open"], r["new"], r["checked"]), (3, 3, 5))
        rows = {x["title"]: x for x in self.ci.open_rows(self.db)}
        self.assertEqual(rows["Visit overwrites minutes"]["bucket"], "ready")
        self.assertEqual(rows["Visit overwrites minutes"]["branch"], "codex-fixes/2026-11-24")
        self.assertEqual(rows["Visit overwrites minutes"]["severity"], "High")
        self.assertEqual(rows["Needs a transaction"]["bucket"], "decision")
        self.assertEqual(rows["Cancel via GET"]["bucket"], "decision")
        self.assertNotIn("Already fixed", rows); self.assertNotIn("Not real", rows)

    def test_rerun_does_not_duplicate_and_closes_what_is_no_longer_open(self):
        self.ci.import_codex(self.db, self.ada.now, F)
        r = self.ci.import_codex(self.db, self.ada.now, F)
        self.assertEqual((r["new"], r["closed"]), (0, 0))
        self.assertEqual(self.db.execute("SELECT COUNT(*) c FROM codex_findings").fetchone()["c"], 3)
        later = [dict(f, status="fixed") if f["title"] == "Needs a transaction" else f for f in F]
        r = self.ci.import_codex(self.db, self.ada.now, later)
        self.assertEqual(r["closed"], 1)
        self.assertNotIn("Needs a transaction", [x["title"] for x in self.ci.open_rows(self.db)])

    def test_unreadable_file_imports_nothing_and_closes_nothing(self):
        self.ci.import_codex(self.db, self.ada.now, F)
        os.environ["CODEX_HOME_DIR"] = self.tmp.name; self.ci.CODEX_HOME = self.tmp.name   # no findings.json there
        self.assertIsNone(self.ci.import_codex(self.db, self.ada.now))
        self.assertEqual(len(self.ci.open_rows(self.db)), 3)

    def test_dashboard_shows_both_buckets_and_escapes_text(self):
        evil = dict(F[1], title="<script>alert(1)</script>")
        self.ci.import_codex(self.db, self.ada.now, [F[0], evil])
        h = self.dash.render(self.ada, self.db)
        self.assertIn("Fix ready, needs your yes to deploy", h); self.assertIn("codex-fixes/2026-11-24", h)
        self.assertIn("&lt;script&gt;alert(1)&lt;/script&gt;", h); self.assertNotIn("<script>alert(1)", h)

    def test_dashboard_renders_with_no_codex_data(self):
        h = self.dash.render(self.ada, self.db)
        self.assertIn("Codex", h); self.assertIn("Nothing waiting.", h)


if __name__ == "__main__":
    unittest.main()
