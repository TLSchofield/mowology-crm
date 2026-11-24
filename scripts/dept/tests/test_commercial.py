import importlib.util, json, os, tempfile, unittest

os.environ["FORGE_AUTOSTART"] = "0"   # tests must never start a real build
HERE = os.path.dirname(os.path.abspath(__file__))
DEPT = os.path.join(HERE, "..")


def load(name):
    spec = importlib.util.spec_from_file_location(name, os.path.join(DEPT, name + ".py"))
    m = importlib.util.module_from_spec(spec); spec.loader.exec_module(m); return m


EV = [{"fact": "lists lawn care", "source": "https://x.example", "observed_at": "2026-11-24"}]


class Base(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        os.environ["DEPT_HOME"] = os.path.join(self.tmp.name, "dept"); os.environ["SENTINEL_HOME"] = os.path.join(self.tmp.name, "sen")
        os.environ.pop("ADA_CLAUDE_BIN", None)
        fx = os.path.join(self.tmp.name, "empty-metrics.json"); json.dump({"results": [], "site": {}}, open(fx, "w"))
        os.environ["GUARDIAN_STANDIN_METRICS"] = fx; os.environ["GUARDIAN_HOME"] = os.path.join(self.tmp.name, "g")
        self.ada = load("ada"); self.C = self.ada.C; self.db = self.ada.connect(); self.ada.seed_permissions(self.db)

    def tearDown(self):
        self.tmp.cleanup()
        os.environ.pop("ADA_CLAUDE_BIN", None)
        os.environ.pop("GUARDIAN_STANDIN_METRICS", None); os.environ.pop("GUARDIAN_HOME", None)

    def opp(self, name="Green Co", site="https://green.example", loc="Surrey", email="a@green.example"):
        return self.C.add_opportunity(self.db, name, site, "landscaping", loc, "test", {"email": email}, EV)

    def qualified(self, **kw):
        u = self.opp(**kw)
        self.C.move_stage(self.db, u, "researching", "hunter"); self.C.move_stage(self.db, u, "qualified", "hunter")
        return u


class Pipeline(Base):
    def test_duplicates_refused_by_domain_and_by_name_plus_location(self):
        self.opp()
        for kw in (dict(name="Other Name", loc="Delta"), dict(name="green co", site="https://other.example")):
            with self.assertRaises(self.C.Refused):
                self.opp(**kw)
        self.opp(name="Green Co", site="https://other2.example", loc="Delta")      # same name, other city: allowed

    def test_observed_facts_need_source_and_date(self):
        with self.assertRaises(self.C.Refused):
            self.C.add_opportunity(self.db, "X", "https://x.example", evidence=[{"fact": "claims they are busy"}])

    def test_qualification_needs_evidence_and_stage_order_is_enforced(self):
        u = self.C.add_opportunity(self.db, "NoEv", "https://noev.example")
        self.C.move_stage(self.db, u, "researching", "hunter")
        with self.assertRaises(self.C.Refused):
            self.C.move_stage(self.db, u, "qualified", "hunter")
        with self.assertRaises(self.C.Refused):
            self.C.move_stage(self.db, u, "won", "tim")

    def test_agents_can_never_mark_contacted(self):
        u = self.qualified()
        for who in ("apollo", "scribe", "closer", "hunter", "ambassador"):
            with self.assertRaises(self.C.Refused):
                self.C.move_stage(self.db, u, "contacted", who)

    def test_full_gate_eligibility_then_approval_then_human_contacts(self):
        u = self.qualified()
        with self.assertRaises(self.C.Refused):                                    # no eligibility yet
            self.C.move_stage(self.db, u, "contact_eligibility_verified", "apollo")
        self.C.propose_eligibility(self.db, u, "conspicuous_publication", "email published on own site", "apollo")
        with self.assertRaises(self.C.Refused):                                    # proposed is not confirmed
            self.C.move_stage(self.db, u, "contact_eligibility_verified", "apollo")
        for by, tty in (("apollo", True), ("tim", False)):                         # agent, or a script without a terminal
            with self.assertRaises(self.C.Refused):
                self.C.confirm_eligibility(self.db, u, by, "ok", tty)
        self.C.confirm_eligibility(self.db, u, "tim", "reviewed the basis", True)
        self.C.move_stage(self.db, u, "contact_eligibility_verified", "apollo")
        with self.assertRaises(self.C.Refused):                                    # no draft yet
            self.C.move_stage(self.db, u, "awaiting_outreach_approval", "apollo")
        self.C.add_capability(self.db, "Quotes", "q", ["x"]); self.C.verify_capability(self.db, "Quotes", "guardian", "", False)
        cid = self.C.add_draft(self.db, u, "email", "s", "Tim, Mowology, 1 Main St. Reply unsubscribe to opt out.", ["Quotes"], "scribe")
        self.C.move_stage(self.db, u, "awaiting_outreach_approval", "apollo")
        with self.assertRaises(self.C.Refused):                                    # outreach not approved yet, even for the owner
            self.C.move_stage(self.db, u, "contacted", "tim")
        with self.assertRaises(self.C.Refused):
            self.C.record_sent(self.db, cid, "tim", True)                          # draft is not approved
        with self.assertRaises(self.C.Refused):
            self.C.approve_outreach(self.db, u, "apollo", True)
        self.C.approve_outreach(self.db, u, "tim", True)
        self.C.record_sent(self.db, cid, "tim", True)
        self.C.move_stage(self.db, u, "contacted", "tim")
        self.assertEqual(self.C.get_opp(self.db, u)["stage"], "contacted")

    def test_every_stage_move_is_logged(self):
        u = self.qualified()
        n = self.db.execute("SELECT COUNT(*) c FROM opp_history").fetchone()["c"]
        self.assertEqual(n, 3)


class OptOut(Base):
    def test_opt_out_applies_department_wide_and_cancels_drafts(self):
        a, b = self.qualified(), self.qualified(name="Second Co", site="https://second.example", email="x@second.example")
        self.C.add_capability(self.db, "Quotes", "q", []); self.C.verify_capability(self.db, "Quotes", "guardian", "", False)
        self.C.add_draft(self.db, a, "email", "s", "Mowology, 1 Main St, unsubscribe here", ["Quotes"], "scribe")
        hit = self.C.suppress(self.db, "asked to stop", email="A@Green.example")
        self.assertEqual(hit, [self.C.get_opp(self.db, a)["uid"]])
        self.assertEqual(self.C.get_opp(self.db, a)["stage"], "opted_out")
        self.assertEqual(self.C.get_opp(self.db, b)["stage"], "qualified")          # other businesses untouched
        self.assertEqual(self.db.execute("SELECT status FROM communications").fetchone()["status"], "cancelled")
        with self.assertRaises(self.C.Refused):                                    # cannot be re-added by any agent
            self.opp()
        with self.assertRaises(self.C.Refused):
            self.C.move_stage(self.db, a, "researching", "hunter")
        with self.assertRaises(self.C.Refused):
            self.C.add_draft(self.db, a, "email", "s", "Mowology unsubscribe", [], "scribe")

    def test_free_email_domains_are_not_suppressed_as_a_whole(self):
        a = self.C.add_opportunity(self.db, "Gmail One", "", contact={"email": "one@gmail.com"}, evidence=EV)
        self.C.add_opportunity(self.db, "Gmail Two", "", contact={"email": "two@gmail.com"}, evidence=EV)
        self.C.suppress(self.db, "stop", email="one@gmail.com")
        self.assertEqual(self.db.execute("SELECT COUNT(*) c FROM opportunities WHERE stage='opted_out'").fetchone()["c"], 1)

    def test_suppression_by_phone_domain_and_name(self):
        self.C.add_opportunity(self.db, "Phone Co", "", contact={"phone": "(604) 555-0100"}, evidence=EV)
        self.C.suppress(self.db, "stop", phone="604-555-0100")
        with self.assertRaises(self.C.Refused):
            self.C.add_opportunity(self.db, "Phone Co Again", "", contact={"phone": "6045550100"}, evidence=EV)
        self.C.suppress(self.db, "stop", name="Name Only Ltd")
        with self.assertRaises(self.C.Refused):
            self.C.add_opportunity(self.db, "name only ltd", "", evidence=EV)


class Drafts(Base):
    def setUp(self):
        super().setUp()
        self.u = self.qualified()
        self.C.add_capability(self.db, "Quotes", "q", []); self.C.verify_capability(self.db, "Quotes", "guardian", "", False)
        self.C.add_capability(self.db, "Payments", "p", [])      # exists, NOT verified

    def test_only_verified_capabilities_may_be_claimed(self):
        body = "Mowology, 1 Main St. Reply unsubscribe to opt out."
        with self.assertRaises(self.C.Refused):
            self.C.add_draft(self.db, self.u, "email", "s", body, ["Payments"], "scribe")
        with self.assertRaises(self.C.Refused):
            self.C.add_draft(self.db, self.u, "email", "s", body, ["Invented feature"], "scribe")
        self.C.add_draft(self.db, self.u, "email", "s", body, ["Quotes"], "scribe")

    def test_email_needs_sender_id_and_unsubscribe_and_sms_rules(self):
        for body in ("Hi there, buy now", "Mowology here, buy now"):
            with self.assertRaises(self.C.Refused):
                self.C.add_draft(self.db, self.u, "email", "s", body, [], "scribe")
        with self.assertRaises(self.C.Refused):
            self.C.add_draft(self.db, self.u, "sms", "", "See mowology.ca now", [], "scribe")
        with self.assertRaises(self.C.Refused):
            self.C.add_draft(self.db, self.u, "sms", "", "x" * 161, [], "scribe")
        self.C.add_draft(self.db, self.u, "sms", "", "Tim at Mowology: check your email for details. (778) 846-9273", [], "scribe")

    def test_only_the_human_verifies_a_capability(self):
        with self.assertRaises(self.C.Refused):
            self.C.verify_capability(self.db, "Payments", "scribe", "", True)
        with self.assertRaises(self.C.Refused):
            self.C.verify_capability(self.db, "Payments", "tim", "", False)
        self.C.verify_capability(self.db, "Payments", "tim", "checked live", True)

    def test_repo_candidates_are_never_seeded_as_verified(self):
        self.C.seed_capabilities(self.db, os.path.abspath(os.path.join(DEPT, "..", "..")))
        self.assertEqual(self.db.execute("SELECT COUNT(*) c FROM capabilities WHERE status='verified' AND source LIKE 'repo candidate%'").fetchone()["c"], 0)


class Knowledge(Base):
    def test_agents_cannot_make_knowledge_authoritative(self):
        k = self.C.add_knowledge(self.db, "pricing", "observation", "Jobber Core $49", "jobber pricing page 2026-11-24", "compass")
        for who in ("compass", "atlas", "apollo"):
            with self.assertRaises(self.C.Refused):
                self.C.promote_knowledge(self.db, k, who, "evidence")
        self.C.promote_knowledge(self.db, k, "guardian", "page re-fetched")
        self.assertEqual(self.db.execute("SELECT status FROM knowledge WHERE id=?", (k,)).fetchone()["status"], "authoritative")

    def test_hypotheses_need_an_owner_decision_and_supersede_not_overwrite(self):
        h = self.C.add_knowledge(self.db, "icp", "hypothesis", "owner-operators 1-10 staff", "inference", "compass")
        with self.assertRaises(self.C.Refused):
            self.C.promote_knowledge(self.db, h, "guardian", "x")
        self.C.promote_knowledge(self.db, h, "tim", "I decided")
        h2 = self.C.add_knowledge(self.db, "icp", "hypothesis", "owner-operators 3-10 staff", "inference", "compass")
        self.C.promote_knowledge(self.db, h2, "tim", "refined")
        rows = {r["id"]: r["status"] for r in self.db.execute("SELECT id,status FROM knowledge")}
        self.assertEqual((rows[h], rows[h2]), ("superseded", "authoritative"))

    def test_source_required_and_conflicts_are_surfaced(self):
        with self.assertRaises(self.C.Refused):
            self.C.add_knowledge(self.db, "t", "observation", "claim without source", "", "compass")
        a = self.C.add_knowledge(self.db, "price", "observation", "Plan costs 49 dollars monthly", "page", "compass"); self.C.promote_knowledge(self.db, a, "guardian", "checked")
        self.C.add_knowledge(self.db, "price", "observation", "Totally different: free forever tier exists", "forum", "hunter")
        self.assertEqual(len(self.C.conflicts(self.db)), 1)

    def test_lessons_are_distributed_selectively(self):
        self.C.add_knowledge(self.db, "x", "lesson", "scribe's own draft", "s", "scribe")
        self.C.add_knowledge(self.db, "y", "lesson", "hunter's own draft", "s", "hunter")
        self.assertEqual({r["statement"] for r in self.C.lessons_for(self.db, "scribe")}, {"scribe's own draft"})


class Records(Base):
    def test_campaign_and_experiment_need_every_field_and_owner_approval_executes_nothing(self):
        with self.assertRaises(self.C.Refused):
            self.C.create_campaign(self.db, {"objective": "x"}, "apollo")
        f = {k: "x" for k in self.C.CAMPAIGN_FIELDS}
        cid = self.C.create_campaign(self.db, f, "apollo")
        with self.assertRaises(self.C.Refused):
            self.C.approve_record(self.db, "campaigns", cid, "apollo", True)
        self.C.approve_record(self.db, "campaigns", cid, "tim", True)
        e = {k: "x" for k in self.C.EXPERIMENT_FIELDS}
        self.C.create_experiment(self.db, e, "mercury")
        with self.assertRaises(self.C.Refused):
            self.C.create_experiment(self.db, dict(e, hypothesis=""), "mercury")

    def test_forecast_excludes_synthetic_and_never_invents_confirmed_revenue(self):
        u = self.qualified()
        self.db.execute("UPDATE opportunities SET synthetic=1"); self.db.commit()
        f = self.C.forecast(self.db)
        self.assertEqual(f["speculative"]["count"], 0)
        self.assertIn("unavailable", f["confirmed"]["note"])

    def test_product_request_register_counts_repeat_observations(self):
        for ev in ("prospect A", "prospect B", "prospect C"):
            self.C.register_request(self.db, "Import customers from Jobber", ev, segment="landscaping")
        r = self.db.execute("SELECT observations FROM product_requests").fetchone()
        self.assertEqual(r["observations"], 3)


class Departments(Base):
    standin = os.path.abspath(os.path.join(DEPT, "demo", "standin-claude.py"))

    def run_chain(self, tag="1", fail=None):
        os.environ["ADA_CLAUDE_BIN"] = self.standin
        if fail:
            os.environ["STANDIN_FAIL"] = fail
        demo = load("demo")
        out = []
        try:
            os.environ["DEMO_RUN"] = tag
            return demo.run(self.ada, self.db, say=lambda *a: out.append(" ".join(map(str, a)))), out
        finally:
            os.environ.pop("STANDIN_FAIL", None)

    def test_acceptance_workflow_stops_before_any_external_message(self):
        r, out = self.run_chain()
        txt = "\n".join(out)
        self.assertEqual(self.C.get_opp(self.db, r["opp"])["stage"], "qualified")
        self.assertIn("gate refused contacted", txt)
        self.assertEqual(self.db.execute("SELECT COUNT(*) c FROM communications WHERE status IN ('approved','sent')").fetchone()["c"], 0)
        self.assertEqual(self.db.execute("SELECT status FROM communications").fetchone()["status"], "draft")
        self.assertEqual(self.db.execute("SELECT proposed_by||status FROM eligibility").fetchone()[0], "apolloproposed")
        self.assertTrue(all(self.ada.get_task(self.db, t)["state"] == "completed" for t in r["ids"].values()))
        self.assertGreaterEqual(self.db.execute("SELECT COUNT(*) c FROM knowledge WHERE created_by='atlas' AND status='draft'").fetchone()["c"], 1)

    def test_failed_task_blocks_its_dependants_then_recovers(self):
        os.environ["ADA_CLAUDE_BIN"] = self.standin
        os.environ["STANDIN_FAIL"] = "hunter"
        self.C.add_capability(self.db, "Quotes: build, send and track quotes", "fixture", []); self.C.verify_capability(self.db, "Quotes: build, send and track quotes", "guardian", "", False)
        try:
            ids = {}
            for ag, deps in (("compass", []), ("hunter", ["compass"]), ("scribe", ["hunter"])):
                ids[ag] = self.ada.add_task(self.db, "t:" + ag, ag, "commercial", ag, payload={"synthetic": True}, depends_on=[ids[d] for d in deps])[0]
            self.ada.tick(self.db, max_runs=10); self.ada.tick(self.db, max_runs=10)      # first failure -> retry once -> blocked
            st = {a: self.ada.get_task(self.db, i)["state"] for a, i in ids.items()}
            self.assertEqual(st, {"compass": "completed", "hunter": "blocked", "scribe": "discovered"})   # dependant waits, nothing wrong ran
            self.assertEqual(self.db.execute("SELECT COUNT(*) c FROM opportunities").fetchone()["c"], 0)
        finally:
            os.environ.pop("STANDIN_FAIL", None)
        self.ada.unblock(self.db, ids["hunter"], "tim", "agent fixed")                     # recovery: owner unblocks, work resumes
        self.ada.tick(self.db, max_runs=10)
        self.assertEqual({a: self.ada.get_task(self.db, i)["state"] for a, i in ids.items()}, {"compass": "completed", "hunter": "completed", "scribe": "completed"})

    def test_scribe_is_refused_when_no_capability_is_verified(self):
        os.environ["ADA_CLAUDE_BIN"] = self.standin
        ids = {}
        for ag, deps in (("hunter", []), ("scribe", ["hunter"])):
            ids[ag] = self.ada.add_task(self.db, "t:" + ag, ag, "commercial", ag, payload={"synthetic": True}, depends_on=[ids[d] for d in deps])[0]
        self.ada.tick(self.db, max_runs=5); self.ada.tick(self.db, max_runs=5)
        self.assertEqual(self.ada.get_task(self.db, ids["scribe"])["state"], "blocked")
        self.assertIn("not a verified capability", self.ada.get_task(self.db, ids["scribe"])["blocked_reason"])
        self.assertEqual(self.db.execute("SELECT COUNT(*) c FROM communications").fetchone()["c"], 0)

    def test_one_off_failure_retries_automatically(self):
        os.environ["ADA_CLAUDE_BIN"] = self.standin
        os.environ["STANDIN_FAIL_ONCE_FILE"] = os.path.join(self.tmp.name, "once"); os.environ["STANDIN_FAIL_ONCE_AGENT"] = "compass"
        try:
            tid = self.ada.add_task(self.db, "t:c", "c", "commercial", "compass")[0]
            self.ada.tick(self.db); self.assertEqual(self.ada.get_task(self.db, tid)["state"], "discovered")
            self.ada.tick(self.db); self.assertEqual(self.ada.get_task(self.db, tid)["state"], "completed")
        finally:
            for k in ("STANDIN_FAIL_ONCE_FILE", "STANDIN_FAIL_ONCE_AGENT"):
                os.environ.pop(k, None)

    def test_opt_out_mid_workflow_rejects_pending_work_for_that_prospect(self):
        os.environ["ADA_CLAUDE_BIN"] = self.standin
        r, _ = self.run_chain("2")
        later = self.ada.add_task(self.db, "later:scribe", "follow-up draft", "commercial", "scribe", depends_on=[r["ids"]["hunter"]])[0]
        self.C.suppress(self.db, "replied: please remove me", email="hello@evergreenrow.example")
        res = self.ada.tick(self.db)
        self.assertEqual(self.ada.get_task(self.db, later)["state"], "rejected")
        self.assertEqual(self.C.get_opp(self.db, r["opp"])["stage"], "opted_out")
        with self.assertRaises(self.C.Refused):
            self.C.add_opportunity(self.db, "Evergreen Row Landscaping (SYNTHETIC)", "https://evergreenrow.example", evidence=EV)

    def test_dormant_agents_do_not_run_until_the_owner_activates_them(self):
        os.environ["ADA_CLAUDE_BIN"] = self.standin
        tid = self.ada.add_task(self.db, "b:1", "b", "commercial", "beacon")[0]
        self.assertEqual(self.ada.tick(self.db), [])
        self.assertEqual(self.ada.get_task(self.db, tid)["state"], "discovered")

    def test_pause_applies_to_the_commercial_department_and_permissions_can_be_revoked(self):
        os.environ["ADA_CLAUDE_BIN"] = self.standin
        tid = self.ada.add_task(self.db, "h:1", "h", "commercial", "hunter")[0]
        self.ada.set_ctl(self.db, "paused:hunter", "now"); self.assertEqual(self.ada.tick(self.db), []); self.ada.set_ctl(self.db, "paused:hunter", None)
        self.ada.revoke(self.db, "hunter", "research-public-web", "test")
        self.ada.tick(self.db); self.assertEqual(self.ada.get_task(self.db, tid)["state"], "blocked")

    def test_run_budget_is_split_between_departments(self):
        os.environ["ADA_CLAUDE_BIN"] = self.standin
        for i in range(5):
            self.ada.add_task(self.db, "p%d" % i, "p", "audit", "sentinel")
            self.ada.add_task(self.db, "c%d" % i, "c", "commercial", "compass")
        os.environ["ADA_SENTINEL_RUNNER"] = "/bin/true"
        try:
            r = self.ada.tick(self.db, max_runs=2, department="apollo")
        finally:
            os.environ.pop("ADA_SENTINEL_RUNNER", None)
        self.assertEqual(len(r), 2)
        self.assertTrue(all(self.ada.get_task(self.db, t[0])["department"] == "apollo" for t in r))

    def test_briefing_has_a_commercial_section_that_makes_no_revenue_claims(self):
        self.run_chain("3")
        text = open(self.ada.write_briefing(self.db, "2026-11-25")).read()
        self.assertIn("Commercial position (Apollo)", text); self.assertIn("Confirm contact eligibility", text)
        self.assertIn("recurring revenue: **not available**", text); self.assertIn("Dormant: Beacon", text)

    def test_agent_definitions_and_registry_agree_and_none_can_write(self):
        reg = json.load(open(os.path.join(DEPT, "registry.json")))["agents"]
        self.assertEqual(set(reg), {"apollo", "mercury", "hunter", "scribe", "beacon", "closer", "ambassador", "compass"})
        agents_dir = os.path.abspath(os.path.join(DEPT, "..", "..", ".claude", "agents"))
        for name, r in reg.items():
            self.assertTrue(os.path.exists(os.path.join(agents_dir, name + ".md")), name)
            for t in ("Write", "Edit", "Bash"):
                self.assertIn(t, r["tools_denied"]); self.assertNotIn(t, r["tools_allowed"])
            self.assertLessEqual(r["autonomy_level"], 3)


if __name__ == "__main__":
    unittest.main()
