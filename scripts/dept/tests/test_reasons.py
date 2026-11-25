import importlib.util, json, os, sys, tempfile, unittest
os.environ["FORGE_AUTOSTART"] = "0"
HERE = os.path.dirname(os.path.abspath(__file__))


def load(path, name):
    sys.path.insert(0, os.path.dirname(path))
    spec = importlib.util.spec_from_file_location(name, path)
    m = importlib.util.module_from_spec(spec); sys.modules[name] = m; spec.loader.exec_module(m); return m


R = load(os.path.join(HERE, "..", "reasons.py"), "reasons")
REC = {"topic": "web.header.cta", "title": "Add a quote button to the header", "area": "web", "problem": "p", "evidence": [{"text": "e"}], "proposal": "x", "benefit": "b",
       "effort": {"size": "M"}, "risk": {"level": "low", "notes": "n"}, "acceptance": ["a 44px target"], "impact": 4, "confidence": 4}


class Reasons(unittest.TestCase):
    def test_every_reason_teaches_something_and_codes_are_unique_per_decision(self):
        for d, items in R.REASONS.items():
            self.assertEqual(len({c for c, _ in items}), len(items))
            self.assertTrue(all(c and l for c, l in items))
            for c, _ in items:
                if d in R.TEACHES:
                    self.assertIn(c, R.TEACHES[d], "%s/%s has no teaching" % (d, c))

    def test_compose_requires_a_note_only_for_other_and_rejects_foreign_codes(self):
        self.assertEqual(R.compose("reject", "deliberate", ""), "It's deliberate: we want it this way")
        self.assertIn("because", R.compose("reject", "deliberate", "because"))
        with self.assertRaises(ValueError):
            R.compose("reject", "other", " ")
        with self.assertRaises(ValueError):
            R.compose("approve", "not_now", "")


class SentinelLearns(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory(); os.environ["SENTINEL_HOME"] = self.tmp.name
        self.s = load(os.path.join(HERE, "..", "..", "sentinel", "sentinel.py"), "sentinel_t")

    def tearDown(self):
        self.tmp.cleanup()

    def reject(self, code, rec=REC):
        rid = self.s.ingest(json.dumps({"recommendations": [rec]}), "daily", "2026-11-24")["recommendations"][0]["id"]
        self.s.decide(rid, "reject", R.compose("reject", code, ""), code=code)
        return rid

    def again(self, rec=REC, date="2026-11-25"):
        return self.s.ingest(json.dumps({"recommendations": [rec]}), "daily", date)["recommendations"]

    def age(self, rid, days):
        import datetime
        rows = [json.loads(l) for l in open(self.s.LEDGER)]
        for r in rows:
            if r.get("event") == "decision":
                r["at"] = (datetime.datetime.now().astimezone() - datetime.timedelta(days=days)).isoformat(timespec="seconds")
        open(self.s.LEDGER, "w").write("".join(json.dumps(r) + "\n" for r in rows))

    def test_deliberate_and_low_value_and_wrong_facts_stay_rejected(self):
        for code in ("deliberate", "low_value", "wrong_facts"):
            rid = self.reject(code, dict(REC, topic="t." + code, title="Distinct idea about " + code * 3)); self.age(rid, 400)
            self.assertEqual(self.again(dict(REC, topic="t." + code, title="Distinct idea about " + code * 3)), [], code)

    def test_not_now_is_quiet_for_90_days_then_may_return(self):
        rid = self.reject("not_now")
        self.assertEqual(self.again(), [])
        self.age(rid, 91)
        self.assertEqual(len(self.again(date="2026-12-01")), 1)

    def test_too_big_returns_only_as_a_smaller_version(self):
        self.reject("too_big")
        self.assertEqual(self.again(dict(REC, effort={"size": "L"})), [])
        self.assertEqual(len(self.again(dict(REC, effort={"size": "S"}), date="2026-11-26")), 1)

    def test_the_reason_code_reaches_the_agents_context(self):
        self.reject("wrong_facts")
        self.assertIn("wrong_facts", self.s.context()); self.assertIn("re-verify", self.s.context())


if __name__ == "__main__":
    unittest.main()
