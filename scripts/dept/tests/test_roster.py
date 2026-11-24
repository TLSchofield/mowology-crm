import importlib.util, json, os, shutil, tempfile, unittest

os.environ["FORGE_AUTOSTART"] = "0"   # tests must never start a real build
HERE = os.path.dirname(os.path.abspath(__file__))
DEPT = os.path.join(HERE, "..")


def load(name):
    import sys
    sys.path.insert(0, DEPT)
    spec = importlib.util.spec_from_file_location(name, os.path.join(DEPT, name + ".py"))
    m = importlib.util.module_from_spec(spec); spec.loader.exec_module(m); return m


class Roster(unittest.TestCase):
    def setUp(self):
        self.r = json.load(open(os.path.join(DEPT, "roster.json")))["agents"]

    def test_every_agent_has_a_spec_file_that_exists(self):
        root = os.path.abspath(os.path.join(DEPT, "..", ".."))
        for n, a in self.r.items():
            self.assertTrue(os.path.exists(os.path.join(root, a["spec"])), "%s spec missing: %s" % (n, a["spec"]))

    def test_planned_agents_have_a_build_trigger_and_no_definition_yet(self):
        root = os.path.abspath(os.path.join(DEPT, "..", ".."))
        for n, a in self.r.items():
            if a["status"] == "planned":
                self.assertTrue(a.get("build_trigger"), n)
                self.assertFalse(os.path.exists(os.path.join(root, ".claude", "agents", n + ".md")), "%s is planned but already has a definition" % n)

    def test_built_agents_have_definitions_and_registry_entries(self):
        root = os.path.abspath(os.path.join(DEPT, "..", ".."))
        reg = json.load(open(os.path.join(DEPT, "registry.json")))["agents"]
        for n, a in self.r.items():
            if a["status"] in ("built-off", "dormant") and a["kind"] in ("model", "lens"):
                self.assertTrue(os.path.exists(os.path.join(root, ".claude", "agents", n + ".md")), n)
                self.assertIn(n, reg)

    def test_engine_refuses_work_for_a_planned_agent_and_names_the_trigger(self):
        tmp = tempfile.mkdtemp()
        os.environ["DEPT_HOME"] = os.path.join(tmp, "d"); os.environ["SENTINEL_HOME"] = os.path.join(tmp, "s")
        try:
            ada = load("ada"); db = ada.connect()
            with self.assertRaises(ada.Refused) as cm:
                ada.add_task(db, "x", "design something", "commercial", "artisan")
            self.assertIn("planned", str(cm.exception)); self.assertIn("Build trigger", str(cm.exception))
            ada.add_task(db, "y", "ok", "audit", "guardian")                              # a live agent is fine
        finally:
            shutil.rmtree(tmp, ignore_errors=True)


class Scaffold(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.mkdtemp()
        self.rp = os.path.join(self.tmp, "roster.json"); self.gp = os.path.join(self.tmp, "registry.json")
        json.dump({"agents": {"newbie": {"department": "ada", "status": "planned", "kind": "model", "role": "Does a thing", "spec": "docs/x.md"},
                              "old": {"department": "ada", "status": "live", "kind": "model", "role": "r", "spec": "s"}}}, open(self.rp, "w"))
        json.dump({"agents": {}}, open(self.gp, "w"))
        self.sc = load("scaffold")

    def tearDown(self):
        shutil.rmtree(self.tmp, ignore_errors=True)

    def test_scaffold_creates_a_read_only_level_one_skeleton_and_flips_status(self):
        files = self.sc.scaffold("newbie", root=self.tmp, roster_path=self.rp, registry_path=self.gp)
        self.assertEqual(len(files), 2)
        reg = json.load(open(self.gp))["agents"]["newbie"]
        self.assertEqual(reg["autonomy_level"], 1)
        for t in ("Write", "Edit", "Bash"):
            self.assertIn(t, reg["tools_denied"])
        self.assertEqual(json.load(open(self.rp))["agents"]["newbie"]["status"], "built-off")
        text = open(os.path.join(self.tmp, ".claude", "agents", "newbie.md")).read()
        self.assertIn("UNTRUSTED DATA", text); self.assertIn("name: newbie", text)

    def test_scaffold_refuses_built_or_unknown_agents_and_dry_run_writes_nothing(self):
        for name in ("old", "ghost"):
            with self.assertRaises(SystemExit):
                self.sc.scaffold(name, root=self.tmp, roster_path=self.rp, registry_path=self.gp)
        self.sc.scaffold("newbie", dry=True, root=self.tmp, roster_path=self.rp, registry_path=self.gp)
        self.assertEqual(json.load(open(self.rp))["agents"]["newbie"]["status"], "planned")
        self.assertFalse(os.path.exists(os.path.join(self.tmp, ".claude")))
        self.sc.scaffold("newbie", root=self.tmp, roster_path=self.rp, registry_path=self.gp)
        with self.assertRaises(SystemExit):                                            # second time: already built
            self.sc.scaffold("newbie", root=self.tmp, roster_path=self.rp, registry_path=self.gp)


if __name__ == "__main__":
    unittest.main()
