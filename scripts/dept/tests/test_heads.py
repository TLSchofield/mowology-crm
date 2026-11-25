import json, os, subprocess, sys, tempfile, types, unittest, shutil

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE); sys.path.insert(0, os.path.join(HERE, ".."))
import test_dashboard_server as TS  # noqa: E402


def snap(**over):
    s = {"generated": "2026-11-24T00:00:00", "window_days": 84, "heads": {
        "kaname": {"metrics": {"shown": {"total": 100, "weekly": {"2026-W46": 40, "2026-W47": 60}, "by": {"quote_followup": 40, "invoice": 60}},
                               "dealt_with": {"total": 70, "weekly": {"2026-W47": 70}}, "dismissed": {"total": 20, "weekly": {"2026-W47": 20}, "by": {"quote_followup": 16, "invoice": 4}}}, "gaps": ["suggested-versus-final is not recorded"]},
        "penny": {"metrics": {"receipt_suggestions": {"total": 60, "by": {"accepted": 35, "edited": 15, "pending": 10}},
                              "bank_line_reviews": {"total": 40, "weekly": {"2026-W47": 40}, "by": {"accepted": 30, "edited": 10}}, "bank_account_changed": {"total": 4, "weekly": {"2026-W47": 4}}}},
        "sam": {"metrics": {"followup_edits": {"total": 10, "by": {"unchanged": 4, "light": 3, "heavy": 3}}}},
        "ada": {"metrics": {"cron_runs": {"total": 200, "weekly": {"2026-W47": 200}, "by": {"success": 190, "failed": 10}}, "cron_failures_by_job": {"total": 10, "weekly": {"2026-W47": 10}, "by": {"invoice_overdue": 7, "gst_check": 3}}}},
    }}
    s.update(over); return s


class Heads(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory(); os.environ["DEPT_HOME"] = self.tmp.name
        import importlib; sys.modules.pop("heads", None); import heads; self.H = heads

    def tearDown(self):
        os.environ.pop("DEPT_HOME", None); self.tmp.cleanup()

    def test_valid_snapshot_is_stored_and_read_back(self):
        p = self.H.save(snap(), "2026-11-24"); self.assertTrue(os.path.exists(p))
        self.assertEqual(self.H.latest()[0], "2026-11-24")

    def test_personal_text_is_refused_wherever_it_hides(self):
        bad = [
            snap(heads={"penny": {"metrics": {"x": {"total": 1, "by": {"Acme Landscaping Ltd. invoice 4431 for $1,204.19": 1}}}}}),   # a vendor name as a label
            snap(heads={"sam": {"metrics": {"x": {"total": "Dear Mrs Lee, your quote"}}}}),                                          # text as a value
            snap(heads={"yui": {"metrics": {"x": {"samples": ["hello"]}}}}),                                                        # a list
            snap(heads={"yui": {"gaps": ["see jane.doe@example.com about her receipts and so on and so forth " * 4]}}),              # a long gap line
            snap(heads={"nobody": {"metrics": {}}}),                                                                                # an unknown head
            {"heads": "text"},
        ]
        for b in bad:
            with self.assertRaises(self.H.Refused, msg=json.dumps(b)[:80]):
                self.H.save(b)
        self.assertEqual(self.H.latest()[0], None)                       # nothing was stored

    def test_an_empty_list_from_php_is_fine_a_full_one_is_not(self):
        s = snap(); s["heads"]["kaname"]["metrics"]["dismissed"]["weekly"] = []
        self.H.save(s, "2026-11-24")
        s["heads"]["kaname"]["metrics"]["dismissed"]["weekly"] = ["x"]
        with self.assertRaises(self.H.Refused):
            self.H.save(s, "2026-11-25")

    def test_the_snapshot_script_has_no_way_to_emit_free_text(self):
        src = open(os.path.join(HERE, "..", "heads", "snapshot.php")).read()
        self.assertNotRegex(src, r"(?i)\b(INSERT|UPDATE|DELETE|REPLACE|ALTER|DROP|TRUNCATE|CREATE)\b\s+(INTO|TABLE|FROM|\w+\s+SET)")
        for banned in ("suggested_body'", "draft_body'", "sent_body'", "vendor_name", "email", "subject", "description"):
            self.assertNotIn("$out['" + banned, src)
        self.assertIn("$label(", src)

    def test_rates_use_only_real_denominators(self):
        r = self.H.rates(snap())
        self.assertIn(("receipt suggestions you edited (of those decided)", "30.0% of 50"), r["penny"])
        self.assertIn(("bank lines you edited (of those decided)", "25.0% of 40"), r["penny"])
        self.assertTrue(any("quote_followup" in a and b == "40.0%" for a, b in r["kaname"]))          # 16 of 40
        self.assertIn(("follow-up drafts edited before sending", "60.0% of 10"), r["sam"])
        self.assertIn(("failing job: invoice_overdue", "7"), r["ada"])
        self.assertEqual(r["otto"], []); self.assertEqual(r["mia"], [])                                   # no signal: nothing invented

    def test_oracle_gets_counts_and_the_gaps_but_nothing_for_an_unknown_service(self):
        self.H.save(snap(), "2026-11-24")
        c = self.H.context("charlie"); self.assertIn("dismissed", c); self.assertIn("not recorded", c)
        self.assertEqual(self.H.context("platform"), ""); self.assertEqual(self.H.context("crew-apps"), "")

    def test_pull_runs_the_script_read_only_and_stores_the_result(self):
        calls = []
        def run(cmd):
            calls.append(cmd); return types.SimpleNamespace(returncode=0, stdout="note\n" + json.dumps({"out": snap()}), stderr="")
        path, s = self.H.pull(run)
        self.assertTrue(os.path.exists(path)); self.assertEqual(calls[0][:2], ["bash", "scripts/prod-run.sh"]); self.assertNotIn("--commit", calls[0])

    def test_pull_failures_say_why_and_store_nothing(self):
        bad = lambda cmd: types.SimpleNamespace(returncode=1, stdout="", stderr="ftp refused")  # noqa: E731
        with self.assertRaises(self.H.Refused) as c:
            self.H.pull(bad)
        self.assertIn("ftp refused", str(c.exception))
        leak = lambda cmd: types.SimpleNamespace(returncode=0, stdout=json.dumps({"out": snap(heads={"sam": {"metrics": {"x": {"by": {"Dear Mrs Lee, thanks for the quote": 2}}}}})}), stderr="")  # noqa: E731
        with self.assertRaises(self.H.Refused):
            self.H.pull(leak)
        self.assertIsNone(self.H.latest()[0])

    def test_trend_reads_weekly_totals_oldest_first(self):
        self.H.save(snap(), "2026-11-24"); self.assertEqual(self.H.trend("kaname", "shown"), [40, 60])


class DashHeads(TS.Server):
    def test_the_dashboard_shows_heads_and_survives_a_bad_snapshot(self):
        import heads
        html = self.dash.render(types.SimpleNamespace(**vars(self.ada)), self.db)
        self.assertIn("No snapshot yet", html)
        heads.save(snap(), "2026-11-24")
        html = self.dash.render(types.SimpleNamespace(**vars(self.ada)), self.db)
        for needle in ("Heads on production", "30.0% of 50", "failing job: invoice_overdue", "suggested-versus-final is not recorded"):
            self.assertIn(needle, html)
        open(os.path.join(heads.home(), "2026-11-25.json"), "w").write("{broken")
        self.assertIn("Heads on production", self.dash.render(types.SimpleNamespace(**vars(self.ada)), self.db))


if __name__ == "__main__":
    unittest.main()
