import copy, importlib.util, json, os, sys, tempfile, unittest

HERE = os.path.dirname(os.path.abspath(__file__))


def load():
    spec = importlib.util.spec_from_file_location("sentinel", os.path.join(HERE, "..", "sentinel.py"))
    m = importlib.util.module_from_spec(spec); spec.loader.exec_module(m); return m


GOOD = {
    "topic": "web.home.hero-contrast", "title": "Hero subtitle fails AAA contrast on mobile", "area": "web",
    "problem": "Subtitle is grey on a photo.", "evidence": [{"text": "Measured 3.1:1 at 375px", "screenshot": "home-375.png"}],
    "proposal": "Use token --text on the sunk surface.", "benefit": "Readable outdoors; fewer bounces.",
    "effort": {"size": "S", "estimate": "1 hour"}, "risk": {"level": "low", "notes": "CSS only"},
    "acceptance": ["Contrast >= 7:1 at 375, 768 and 1440 px"], "impact": 3, "confidence": 4,
}


class T(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        os.environ["SENTINEL_HOME"] = self.tmp.name
        self.s = load(); self.s.init()

    def tearDown(self):
        self.tmp.cleanup()

    def run_ingest(self, recs, kind="daily", date="2026-11-24"):
        return self.s.ingest(json.dumps({"summary": "x", "recommendations": recs}), kind, date)

    def test_valid_recommendation_is_logged_and_scored(self):
        rep = self.run_ingest([GOOD])
        self.assertEqual(rep["recommendations"][0]["id"], "SEN-20261124-1")
        self.assertEqual(rep["recommendations"][0]["priority_score"], 12.0)
        self.assertEqual(len(self.s.read_ledger()), 1)

    def test_every_required_part_is_enforced(self):
        for k in self.s.REQUIRED:
            bad = copy.deepcopy(GOOD); del bad[k]
            rep = self.run_ingest([bad])
            self.assertEqual(rep["recommendations"], [], k)
            self.assertIn("missing " + k, rep["dropped"][0]["why"])

    def test_acceptance_must_be_measurable(self):
        bad = copy.deepcopy(GOOD); bad["acceptance"] = ["It looks better"]
        self.assertIn("measurable", self.run_ingest([bad])["dropped"][0]["why"])

    def test_daily_cap_is_three_and_keeps_highest_scores(self):
        recs = []
        for i, imp in enumerate([1, 5, 2, 4, 3]):
            r = copy.deepcopy(GOOD); r["topic"] = "t%d" % i; r["title"] = "Distinct idea number %d about %s" % (i, "abcdefg"[i] * 6); r["impact"] = imp
            recs.append(r)
        rep = self.run_ingest(recs)
        self.assertEqual([r["impact"] for r in rep["recommendations"]], [5, 4, 3])
        self.assertEqual(len(rep["dropped"]), 2)

    def test_rejected_is_not_proposed_again_without_new_evidence(self):
        rid = self.run_ingest([GOOD])["recommendations"][0]["id"]
        self.s.decide(rid, "reject", "we like the grey")
        again = self.run_ingest([GOOD], date="2026-11-25")
        self.assertEqual(again["recommendations"], [])
        self.assertIn("previously rejected", again["dropped"][0]["why"])

    def test_rejected_returns_with_significant_new_evidence(self):
        rid = self.run_ingest([GOOD])["recommendations"][0]["id"]
        self.s.decide(rid, "reject", "we like the grey")
        r = copy.deepcopy(GOOD); r["new_evidence"] = "Search Console shows mobile bounce rate rose 18% since the hero change."
        self.assertEqual(len(self.run_ingest([r], date="2026-11-25")["recommendations"]), 1)

    def test_reworded_title_same_idea_is_still_suppressed(self):
        rid = self.run_ingest([GOOD])["recommendations"][0]["id"]
        self.s.decide(rid, "reject", "no")
        r = copy.deepcopy(GOOD); r["topic"] = "something.else"; r["title"] = "Hero subtitle fails AAA contrast on mobile!"
        self.assertEqual(self.run_ingest([r], date="2026-11-25")["recommendations"], [])

    def test_accepted_idea_is_not_repeated(self):
        rid = self.run_ingest([GOOD])["recommendations"][0]["id"]
        self.s.decide(rid, "accept", "yes")
        self.assertIn("already accepted", self.run_ingest([GOOD], date="2026-11-25")["dropped"][0]["why"])

    def test_decision_needs_a_reason(self):
        rid = self.run_ingest([GOOD])["recommendations"][0]["id"]
        with self.assertRaises(ValueError):
            self.s.decide(rid, "reject", "  ")

    def test_modified_note_reaches_backlog_and_context(self):
        rid = self.run_ingest([GOOD])["recommendations"][0]["id"]
        self.s.decide(rid, "modify", "do it but only on the home page", note="home page only")
        self.assertIn("home page only", open(os.path.join(self.tmp.name, "backlog.md")).read())
        self.assertIn(rid, self.s.context())

    def test_context_lists_rejections_with_tims_reason(self):
        rid = self.run_ingest([GOOD])["recommendations"][0]["id"]
        self.s.decide(rid, "reject", "brand grey is deliberate")
        self.assertIn("brand grey is deliberate", self.s.context())

    def test_ids_continue_within_a_day(self):
        self.run_ingest([GOOD])
        r = copy.deepcopy(GOOD); r["topic"] = "other"; r["title"] = "Completely different quote form idea"
        self.assertEqual(self.run_ingest([r])["recommendations"][0]["id"], "SEN-20261124-2")

    def test_json_in_prose_and_fences_is_found(self):
        wrapped = "Here you go:\n```json\n" + json.dumps({"recommendations": [GOOD]}) + "\n```\nDone."
        self.assertEqual(len(self.s.ingest(wrapped, "daily", "2026-11-24")["recommendations"]), 1)

    def test_garbage_output_raises(self):
        with self.assertRaises(ValueError):
            self.s.ingest("I could not run the audit.", "daily", "2026-11-24")

    def test_torn_ledger_line_does_not_stop_the_audit(self):
        self.run_ingest([GOOD])
        open(self.s.LEDGER, "a").write('{"event": "decis')
        self.assertEqual(len(self.s.current_state(self.s.read_ledger())), 1)

    def test_empty_day_is_a_valid_report(self):
        rep = self.run_ingest([])
        self.assertIn("No recommendation met the bar", open(os.path.join(self.tmp.name, "reports", "2026-11-24", "daily.md")).read())

    def test_registry_keeps_sentinel_read_only(self):
        reg = json.load(open(os.path.join(HERE, "..", "registry.json")))["agents"]["sentinel"]
        self.assertEqual(reg["autonomy_level"], 0)
        for t in ("Write", "Edit"):
            self.assertIn(t, reg["tools_denied"]); self.assertNotIn(t, reg["tools_allowed"])


if __name__ == "__main__":
    unittest.main()


class RunnerTest(unittest.TestCase):
    """End-to-end: run-audit.sh with a stand-in CLI (no model, no network, no repo writes)."""

    def test_runner_flow_and_read_only_flags(self):
        import subprocess, stat
        with tempfile.TemporaryDirectory() as t:
            fake = os.path.join(t, "fakeclaude")
            rec = dict(GOOD)
            with open(fake, "w") as f:
                f.write("#!/bin/bash\necho \"$*\" >> \"$FAKE_LOG\"\n/usr/bin/env python3 -c 'import json,sys;print(json.dumps({\"is_error\":False,\"result\":json.dumps({\"summary\":\"s\",\"recommendations\":[%s]})}))'\n" % json.dumps(rec).replace("'", "'\\''"))
            os.chmod(fake, os.stat(fake).st_mode | stat.S_IEXEC)
            env = dict(os.environ, SENTINEL_HOME=os.path.join(t, "s"), SENTINEL_CLAUDE_BIN=fake, SENTINEL_SKIP_NATIVE="1",
                       FAKE_LOG=os.path.join(t, "args.log"), SENTINEL_REPO=os.path.join(t, "norepo"))
            r = subprocess.run(["bash", os.path.join(HERE, "..", "run-audit.sh"), "daily"], env=env, capture_output=True, text=True)
            self.assertEqual(r.returncode, 0, r.stdout + r.stderr)
            args = open(env["FAKE_LOG"]).read()
            for needle in ("--agent sentinel", "--max-budget-usd 3", "--strict-mcp-config", "--disallowedTools Write Edit"):
                self.assertIn(needle, args)
            self.assertNotIn("Write", args.split("--allowedTools")[1].split("--disallowedTools")[0])
            day = os.listdir(os.path.join(t, "s", "reports"))[0]
            self.assertTrue(os.path.exists(os.path.join(t, "s", "reports", day, "daily.md")))
            self.assertIn("SEN-", open(os.path.join(t, "s", "backlog.md")).read())

    def test_unsigned_cli_fails_with_the_cause(self):
        import subprocess
        with tempfile.TemporaryDirectory() as t:
            env = dict(os.environ, SENTINEL_HOME=os.path.join(t, "s"), SENTINEL_SKIP_NATIVE="1", SENTINEL_REPO=os.path.join(t, "norepo"))
            env.pop("SENTINEL_CLAUDE_BIN", None)
            fake_dir = os.path.join(t, "Library", "Application Support", "Claude", "claude-code", "9", "h", "claude.app", "Contents", "MacOS")
            os.makedirs(fake_dir)
            with open(os.path.join(fake_dir, "claude"), "w") as f:
                f.write("#!/bin/bash\necho '{\"loggedIn\": false}'\n")
            os.chmod(os.path.join(fake_dir, "claude"), 0o755)
            env["HOME"] = t
            r = subprocess.run(["bash", os.path.join(HERE, "..", "run-audit.sh"), "daily"], env=env, capture_output=True, text=True)
            self.assertEqual(r.returncode, 75)
            self.assertIn("not signed in", r.stdout)
