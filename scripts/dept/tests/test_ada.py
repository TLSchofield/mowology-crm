import datetime, importlib.util, json, os, stat, subprocess, sys, tempfile, unittest

HERE = os.path.dirname(os.path.abspath(__file__))
SENTINEL = os.path.join(HERE, "..", "..", "sentinel")


def load():
    spec = importlib.util.spec_from_file_location("ada", os.path.join(HERE, "..", "ada.py"))
    m = importlib.util.module_from_spec(spec); spec.loader.exec_module(m); return m


def fake_runner(path, body):
    with open(path, "w") as f:
        f.write("#!/bin/bash\n" + body + "\n")
    os.chmod(path, os.stat(path).st_mode | stat.S_IEXEC)
    return path


REC = {"topic": "web.home.cta", "title": "Quote button below the fold at 375px", "area": "web", "problem": "CTA hidden.",
       "evidence": [{"text": "top at y=912"}], "proposal": "Move into hero.", "benefit": "More requests.", "effort": {"size": "S"},
       "risk": {"level": "low", "notes": "css"}, "acceptance": ["top <= 700px"], "impact": 4, "confidence": 4, "priority_score": 16.0}


class Base(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        t = self.tmp.name
        os.environ["DEPT_HOME"] = os.path.join(t, "dept"); os.environ["SENTINEL_HOME"] = os.path.join(t, "sen")
        self.ada = load(); self.db = self.ada.connect(); self.ada.seed_permissions(self.db)
        self.runner = os.path.join(t, "runner.sh")
        os.environ["ADA_SENTINEL_RUNNER"] = self.runner
        self.calls = os.path.join(t, "calls.log")

    def tearDown(self):
        self.tmp.cleanup()

    def runner_ok(self):
        fake_runner(self.runner, 'echo "$1" >> %s; echo "done: 0 recommendation(s)"; exit 0' % self.calls)

    def state(self, tid):
        return self.db.execute("SELECT state FROM tasks WHERE id=?", (tid,)).fetchone()["state"]


class StateMachine(Base):
    def new(self, state="discovered", agent="forge", kind="improvement", key="k1"):
        return self.ada.add_task(self.db, key, "t", kind, agent, state=state)[0]

    def test_illegal_and_unauthorised_moves_refused(self):
        tid = self.new("proposed", "sentinel")
        for bad, actor in (("approved", "ada"), ("implementing", "forge"), ("completed", "ada")):
            with self.assertRaises(self.ada.Refused):
                self.ada.transition(self.db, tid, bad, actor)
        self.assertEqual(self.state(tid), "proposed")

    def test_only_human_approves_and_needs_a_terminal(self):
        tid = self.new("awaiting_approval", "sentinel")
        for actor in ("ada", "sentinel", "forge", "guardian", "system"):
            with self.assertRaises(self.ada.Refused):
                self.ada.transition(self.db, tid, "approved", actor)
        with self.assertRaises(self.ada.Refused):
            self.ada.human_decision(self.db, tid, "approve", "ok", tty=False)
        with self.assertRaises(self.ada.Refused):
            self.ada.human_decision(self.db, tid, "approve", "  ", tty=True)
        self.ada.human_decision(self.db, tid, "approve", "yes build it", tty=True)
        self.assertEqual(self.state(tid), "approved")

    def test_forge_cannot_verify_itself_and_guardian_stays_independent(self):
        tid = self.new("approved")
        self.ada.transition(self.db, tid, "implementing", "forge")
        self.ada.transition(self.db, tid, "awaiting_verification", "forge")
        for actor in ("forge", "ada", "tim"):
            with self.assertRaises(self.ada.Refused):
                self.ada.transition(self.db, tid, "verified", actor)
        self.ada.transition(self.db, tid, "verification_failed", "guardian", "regression")
        with self.assertRaises(self.ada.Refused):       # Ada cannot wave a failed gate through
            self.ada.transition(self.db, tid, "verified", "ada")
        self.ada.transition(self.db, tid, "implementing", "forge")
        self.ada.transition(self.db, tid, "awaiting_verification", "forge")
        self.ada.transition(self.db, tid, "verified", "guardian")
        self.ada.transition(self.db, tid, "awaiting_release_approval", "ada")
        with self.assertRaises(self.ada.Refused):       # releasing is the owner's alone
            self.ada.transition(self.db, tid, "released", "ada")
        self.ada.transition(self.db, tid, "released", "tim")

    def test_every_transition_is_logged(self):
        tid = self.new("proposed", "sentinel")
        self.ada.transition(self.db, tid, "awaiting_approval", "ada", "why")
        rows = self.db.execute("SELECT from_state,to_state,actor,reason FROM transitions WHERE task_id=? ORDER BY id", (tid,)).fetchall()
        self.assertEqual([(r["from_state"], r["to_state"]) for r in rows], [(None, "proposed"), ("proposed", "awaiting_approval")])

    def test_terminal_states_are_final(self):
        tid = self.new("proposed", "sentinel")
        self.ada.transition(self.db, tid, "rejected", "tim", "no")
        with self.assertRaises(self.ada.Refused):
            self.ada.transition(self.db, tid, "proposed", "tim")

    def test_dependencies_are_respected(self):
        a = self.new(key="a", state="approved")
        b = self.ada.add_task(self.db, "b", "t", "improvement", "forge", state="approved", depends_on=[a])[0]
        with self.assertRaises(self.ada.Refused):
            self.ada.transition(self.db, b, "implementing", "forge")
        self.db.execute("UPDATE tasks SET state='completed' WHERE id=?", (a,)); self.db.commit()
        self.ada.transition(self.db, b, "implementing", "forge")

    def test_blocked_returns_to_where_it_was(self):
        tid = self.new("approved")
        self.ada.transition(self.db, tid, "blocked", "system", "waiting on dependency")
        self.ada.unblock(self.db, tid, "tim", "fixed")
        self.assertEqual(self.state(tid), "approved")
        with self.assertRaises(self.ada.Refused):
            self.ada.unblock(self.db, tid, "tim", "again")           # no longer blocked

    def test_duplicate_work_is_not_queued_twice(self):
        a, c1 = self.ada.add_task(self.db, "same", "t", "audit", "sentinel")
        b, c2 = self.ada.add_task(self.db, "same", "t", "audit", "sentinel")
        self.assertEqual((a, c1, c2), (b, True, False))


class Orchestration(Base):
    def test_cycle_runs_once_per_day_and_never_twice(self):
        self.runner_ok()
        self.ada.cycle(self.db, "2026-11-25"); self.ada.cycle(self.db, "2026-11-25")
        self.assertEqual(open(self.calls).read().split(), ["daily"])      # the second cycle found the work already done
        self.assertEqual(self.db.execute("SELECT COUNT(*) c FROM tasks WHERE kind='audit'").fetchone()["c"], 1)

    def test_sunday_adds_the_weekly_audit(self):
        self.runner_ok()
        self.ada.cycle(self.db, "2026-11-29")   # a Sunday
        self.assertEqual(sorted(open(self.calls).read().split()), ["daily", "weekly"])

    def test_failure_retries_once_then_blocks_with_the_cause_and_stops(self):
        fake_runner(self.runner, 'echo "$1" >> %s; echo "boom"; exit 1' % self.calls)
        tid = self.ada.add_task(self.db, "x", "t", "audit", "sentinel", payload={"audit": "daily"})[0]
        r1 = self.ada.tick(self.db); self.assertEqual(self.state(tid), "discovered")      # retry later
        r2 = self.ada.tick(self.db); self.assertEqual(self.state(tid), "blocked")
        self.assertIn("boom", self.db.execute("SELECT blocked_reason FROM tasks WHERE id=?", (tid,)).fetchone()["blocked_reason"])
        self.ada.tick(self.db); self.ada.tick(self.db)
        self.assertEqual(len(open(self.calls).read().split()), 2)                         # never a third try

    def test_needs_human_blocks_at_once_no_retry(self):
        fake_runner(self.runner, 'echo "Claude CLI is not signed in"; exit 75')
        tid = self.ada.add_task(self.db, "x", "t", "audit", "sentinel")[0]
        self.ada.tick(self.db)
        self.assertEqual(self.state(tid), "blocked")

    def test_usage_limit_sets_a_hold_that_stops_everything(self):
        fake_runner(self.runner, 'echo "Claude usage limit reached"; exit 75')
        self.ada.add_task(self.db, "x", "t", "audit", "sentinel")
        self.ada.tick(self.db)
        self.assertIn("limit hold", self.ada.halted(self.db))
        self.ada.add_task(self.db, "y", "t", "audit", "sentinel")
        self.assertIn("halted", self.ada.tick(self.db)[0][1])

    def test_interrupted_run_is_recovered_not_repeated_blindly(self):
        tid = self.ada.add_task(self.db, "x", "t", "audit", "sentinel")[0]
        self.ada.transition(self.db, tid, "investigating", "sentinel")
        old = (datetime.datetime.now().astimezone() - datetime.timedelta(hours=5)).isoformat(timespec="seconds")
        self.db.execute("UPDATE tasks SET heartbeat_at=?, updated_at=?, attempts=1 WHERE id=?", (old, old, tid))
        self.db.execute("INSERT INTO runs(task_id,agent,started_at,status) VALUES(?,?,?,'running')", (tid, "sentinel", old)); self.db.commit()
        self.assertEqual(self.ada.recover(self.db), 1)
        self.assertEqual(self.state(tid), "discovered")
        self.assertEqual(self.db.execute("SELECT status FROM runs").fetchone()["status"], "interrupted")
        self.assertEqual(self.db.execute("SELECT attempts FROM tasks WHERE id=?", (tid,)).fetchone()["attempts"], 2)

    def test_pause_resume_and_emergency_stop(self):
        self.runner_ok()
        tid = self.ada.add_task(self.db, "x", "t", "audit", "sentinel")[0]
        self.ada.set_ctl(self.db, "paused", "now"); self.assertIn("halted", self.ada.tick(self.db)[0][1]); self.assertEqual(self.state(tid), "discovered")
        self.ada.set_ctl(self.db, "paused", None); self.ada.set_ctl(self.db, "paused:sentinel", "now")
        self.assertEqual(self.ada.tick(self.db), []); self.assertEqual(self.state(tid), "discovered")
        self.ada.set_ctl(self.db, "paused:sentinel", None); self.ada.set_ctl(self.db, "stopped", "now")
        self.assertIn("emergency stop", self.ada.tick(self.db)[0][1])
        self.ada.set_ctl(self.db, "stopped", None)
        self.ada.tick(self.db); self.assertEqual(self.state(tid), "completed")

    def test_revoked_permission_blocks_the_agent(self):
        self.runner_ok()
        self.ada.revoke(self.db, "sentinel", "audit-public-web", "testing")
        tid = self.ada.add_task(self.db, "x", "t", "audit", "sentinel")[0]
        self.ada.tick(self.db)
        self.assertEqual(self.state(tid), "blocked")
        self.assertFalse(os.path.exists(self.calls))

    def test_resource_controls_cap_runs_and_check_disk(self):
        self.runner_ok()
        for i in range(6):
            self.ada.add_task(self.db, "k%d" % i, "t", "audit", "sentinel")
        self.assertEqual(len(self.ada.tick(self.db, max_runs=self.ada.MAX_RUNS_PER_CYCLE)), 3)
        orig = self.ada.free_gb; self.ada.free_gb = lambda: 1.0
        self.assertIn("disk", self.ada.tick(self.db)[0][1]); self.ada.free_gb = orig


class SharedKnowledge(Base):
    def write_proposal(self, rid="SEN-1", decision=None):
        os.makedirs(os.path.join(os.environ["SENTINEL_HOME"], "ledger"), exist_ok=True)
        with open(os.path.join(os.environ["SENTINEL_HOME"], "ledger", "decisions.jsonl"), "a") as f:
            f.write(json.dumps({"event": "proposed", "id": rid, "at": "x", "rec": REC}) + "\n")
            if decision:
                f.write(json.dumps({"event": "decision", "id": rid, "decision": decision, "reason": "r", "at": "x"}) + "\n")

    def test_proposals_import_once_and_queue_for_the_owner_capped_at_three(self):
        for i in range(5):
            self.write_proposal("SEN-%d" % i)
        self.assertEqual(self.ada.import_sentinel(self.db), 5); self.assertEqual(self.ada.import_sentinel(self.db), 0)
        self.assertEqual(len(self.ada.prioritise(self.db)), 3)
        self.assertEqual(self.db.execute("SELECT COUNT(*) c FROM tasks WHERE state='proposed'").fetchone()["c"], 2)

    def test_critical_work_jumps_the_queue_and_is_not_capped(self):
        for i in range(5):
            self.ada.add_task(self.db, "c%d" % i, "sec", "improvement", "guardian", state="proposed", category="security")
        for i in range(4):
            self.ada.add_task(self.db, "o%d" % i, "ord", "improvement", "sentinel", state="proposed", score=9)
        moved = self.ada.prioritise(self.db)
        self.assertEqual(len(moved), 8)

    def test_decision_flows_into_sentinels_ledger_and_rejects_are_not_reimported(self):
        sys.path.insert(0, SENTINEL)
        spec = importlib.util.spec_from_file_location("sentinel", os.path.join(SENTINEL, "sentinel.py")); sen = importlib.util.module_from_spec(spec); spec.loader.exec_module(sen)
        rep = sen.ingest(json.dumps({"recommendations": [dict(REC, topic="t1")]}), "daily", "2026-11-25")
        rid = rep["recommendations"][0]["id"]
        self.ada.import_sentinel(self.db); self.ada.prioritise(self.db)
        tid = self.db.execute("SELECT id FROM tasks WHERE source_ref=?", (rid,)).fetchone()["id"]
        self.ada.human_decision(self.db, tid, "reject", "we like it as is", tty=True)
        self.assertEqual(sen.current_state(sen.read_ledger())[rid]["status"], "rejected")   # one shared decision history
        self.assertIn("we like it as is", sen.context())

    def test_decision_made_in_sentinel_cli_is_respected(self):
        self.write_proposal("SEN-A", "reject"); self.write_proposal("SEN-B", "accept")
        self.ada.import_sentinel(self.db)
        self.assertIsNone(self.db.execute("SELECT 1 FROM tasks WHERE source_ref='SEN-A'").fetchone())
        self.assertEqual(self.db.execute("SELECT state FROM tasks WHERE source_ref='SEN-B'").fetchone()["state"], "approved")

    def test_briefing_has_the_required_sections_and_is_honest_about_usage(self):
        self.write_proposal("SEN-1"); self.ada.import_sentinel(self.db); self.ada.prioritise(self.db)
        text = open(self.ada.write_briefing(self.db, "2026-11-25")).read()
        for h in ("Executive summary", "Critical issues", "Decisions requiring your approval", "Completed improvements", "Department activity", "Resource consumption", "Next actions"):
            self.assertIn(h, text)
        self.assertIn("not measurable", text); self.assertIn("ada approve", text)


class EndToEnd(Base):
    def test_real_sentinel_runner_with_standin_cli_through_a_full_cycle(self):
        t = self.tmp.name
        fake = fake_runner(os.path.join(t, "fakeclaude"), 'python3 -c \'import json;rec=%s;print(json.dumps({"is_error":False,"total_cost_usd":0.0,"result":json.dumps({"summary":"s","recommendations":[rec]})}))\'' % json.dumps(dict(REC, impact=4)).replace("'", "'\\''"))
        os.environ.pop("ADA_SENTINEL_RUNNER")
        env_extra = dict(SENTINEL_CLAUDE_BIN=fake, SENTINEL_SKIP_NATIVE="1", SENTINEL_REPO=os.path.join(t, "norepo"))
        os.environ.update(env_extra)
        try:
            res, path = self.ada.cycle(self.db, "2026-11-25")
        finally:
            for k in env_extra:
                os.environ.pop(k, None)
        self.assertTrue(res[0][1].startswith("completed"), res)
        text = open(path).read()
        self.assertIn("Quote button", text)
        self.assertEqual(self.db.execute("SELECT COUNT(*) c FROM tasks WHERE state='awaiting_approval'").fetchone()["c"], 1)


if __name__ == "__main__":
    unittest.main()
