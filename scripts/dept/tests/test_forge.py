import importlib.util, json, os, shutil, subprocess, sys, tempfile, types, unittest

HERE = os.path.dirname(os.path.abspath(__file__))
DEPT = os.path.join(HERE, "..")


def load(name):
    sys.path.insert(0, DEPT)
    spec = importlib.util.spec_from_file_location(name, os.path.join(DEPT, name + ".py"))
    m = importlib.util.module_from_spec(spec); sys.modules[name] = m; spec.loader.exec_module(m); return m


def git(cwd, *a):
    return subprocess.run(["git", "-C", cwd, "-c", "user.name=t", "-c", "user.email=t@t"] + list(a), capture_output=True, text=True, check=True).stdout


class Forge(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory(); t = self.tmp.name
        os.environ.update(DEPT_HOME=os.path.join(t, "d"), SENTINEL_HOME=os.path.join(t, "s"), GUARDIAN_HOME=os.path.join(t, "g"), FORGE_AUTOSTART="0", FORGE_VISUALS="0", FORGE_LIVE_WAIT="0", FORGE_AUTOSHIP="0",
                          FORGE_OUT_DIR=os.path.join(t, "out"), ADA_REPO_ROOT=os.path.join(t, "repo"))
        fx = os.path.join(t, "m.json"); json.dump({"results": [], "site": {}}, open(fx, "w")); os.environ["GUARDIAN_STANDIN_METRICS"] = fx
        origin, repo = os.path.join(t, "origin.git"), os.path.join(t, "repo")
        subprocess.run(["git", "init", "-q", "--bare", origin], check=True)
        os.makedirs(repo); subprocess.run(["git", "init", "-q", "-b", "feature/language", repo], check=True)
        os.makedirs(os.path.join(repo, "public", "includes")); os.makedirs(os.path.join(repo, "app", "Modules", "Invoices"))
        open(os.path.join(repo, "public", "includes", "header.php"), "w").write("<?php\necho 'header';\n")
        open(os.path.join(repo, "app", "Modules", "Invoices", "X.php"), "w").write("<?php\n")
        git(repo, "add", "-A"); git(repo, "commit", "-q", "-m", "init"); git(repo, "remote", "add", "origin", origin); git(repo, "push", "-q", "origin", "feature/language")
        self.repo = repo
        # a diff that adds a quote button to the header
        side = os.path.join(t, "side"); subprocess.run(["git", "clone", "-q", "-b", "feature/language", origin, side], check=True)
        open(os.path.join(side, "public", "includes", "header.php"), "a").write("echo '<a class=\"btn\" href=\"/quote\">Get a Quote</a>';\n")
        self.diff = os.path.join(t, "good.diff"); open(self.diff, "w").write(git(side, "diff"))
        self.script = os.path.join(t, "fake-build.sh")
        open(self.script, "w").write('#!/bin/bash\nif [ "$1" = "--clean" ]; then rm -rf "$FORGE_OUT_DIR"; exit 0; fi\nmkdir -p "$FORGE_OUT_DIR"; [ -n "$FAKE_DIFF" ] && cp "$FAKE_DIFF" "$FORGE_OUT_DIR/change.diff"; echo "built" > "$FORGE_OUT_DIR/build-summary.md"; echo "verdict: PASS" > "$FORGE_OUT_DIR/review.md"; echo "$@" >> "%s/calls.log"; exit ${FAKE_RC:-0}\n' % t)
        os.environ["FORGE_BUILD_SCRIPT"] = self.script; os.environ["FAKE_DIFF"] = self.diff; os.environ.pop("FAKE_RC", None)
        self.ada = load("ada"); self.forge = load("forge"); self.db = self.ada.connect(); self.ada.seed_permissions(self.db)
        self.ns = types.SimpleNamespace(**vars(self.ada))
        self.ok_checker = lambda wt, files: (True, ["php -l: ok", "project rules: ok", "full test suite: ok — stub"])

    def tearDown(self):
        subprocess.run(["git", "-C", self.repo, "worktree", "prune"], capture_output=True)
        for k in ("FORGE_VISUALS", "FORGE_LIVE_WAIT", "FORGE_AUTOSHIP", "FORGE_BUILD_SCRIPT", "FAKE_DIFF", "FAKE_RC", "FORGE_AUTOSTART", "FORGE_OUT_DIR", "ADA_REPO_ROOT", "GUARDIAN_STANDIN_METRICS", "GUARDIAN_HOME"):
            os.environ.pop(k, None)
        self.tmp.cleanup()

    def task(self, proposal="Edit public/includes/header.php to add a Get a Quote button.", state="approved", key="t1", acceptance=None):
        return self.ada.add_task(self.db, key, "Add a Get a Quote button to the header", "improvement", "sentinel", state=state, risk="low", actor="sentinel",
                                 payload={"problem": "No quote action in the header", "proposal": proposal, "evidence": [{"text": "the page lacks a quote link"}], "acceptance": acceptance or ["quote link visible at 375px"]})[0]

    def st(self, tid):
        return self.ada.get_task(self.db, tid)

    def build(self, tid, checker=None):
        return self.forge.build(self.ns, self.db, tid, checker=checker or self.ok_checker)

    def test_accepted_task_is_built_verified_and_stops_before_release(self):
        tid = self.task()
        msg = self.build(tid)
        self.assertIn("built and verified", msg)
        t = self.st(tid); self.assertEqual(t["state"], "awaiting_release_approval")
        res = json.loads(t["result"]); wt = res["worktree"]
        self.assertIn("Get a Quote", open(os.path.join(wt, "public/includes/header.php")).read())
        self.assertEqual(git(wt, "log", "-1", "--format=%an"), "Ittai Forge\n")
        self.assertNotIn(res["branch"], git(self.repo, "branch", "-r"))                      # never pushed
        self.assertNotIn("Get a Quote", git(self.repo, "show", "origin/feature/language:public/includes/header.php"))   # feature/language untouched
        who = {(r["to_state"], r["actor"]) for r in self.db.execute("SELECT to_state, actor FROM transitions WHERE task_id=?", (tid,))}
        self.assertIn(("awaiting_verification", "forge"), who); self.assertIn(("verified", "guardian"), who)   # Forge never verifies itself
        self.assertNotIn(("verified", "forge"), who)
        self.assertIn("--live public/includes/header.php", open(os.path.join(self.tmp.name, "calls.log")).read())   # built on production's live copy

    def test_task_brief_carries_the_acceptance_criteria_and_rules(self):
        tid = self.task(acceptance=["button 48px tall"]); self.build(tid)
        txt = open(os.path.join(self.tmp.name, "d", "builds", "forge-%d.task.md" % tid)).read()
        self.assertIn("button 48px tall", txt); self.assertIn("SMALLEST change", txt); self.assertIn("design tokens only", txt)

    def test_forbidden_areas_are_refused_without_running_the_builder(self):
        tid = self.task(proposal="Change app/Modules/Invoices/X.php so totals round up.", key="m")
        self.assertIn("blocked", self.build(tid)); self.assertIn("human-led", self.st(tid)["blocked_reason"])
        self.assertFalse(os.path.exists(os.path.join(self.tmp.name, "calls.log")))

    def test_a_proposal_naming_no_file_needs_a_human_chosen_scope(self):
        tid = self.task(proposal="Make the site feel faster.", key="n")
        self.build(tid); self.assertIn("pick the scope", self.st(tid)["blocked_reason"])

    def test_oversized_and_forbidden_diffs_are_stopped(self):
        big = os.path.join(self.tmp.name, "big.diff")
        open(big, "w").write("diff --git a/public/includes/header.php b/public/includes/header.php\n" + "".join("+line %d\n" % i for i in range(500)))
        os.environ["FAKE_DIFF"] = big; tid = self.task(key="b"); self.build(tid)
        self.assertIn("too large", self.st(tid)["blocked_reason"])
        bad = os.path.join(self.tmp.name, "bad.diff")
        open(bad, "w").write("diff --git a/app/Modules/Invoices/X.php b/app/Modules/Invoices/X.php\n+x\n")
        os.environ["FAKE_DIFF"] = bad; t2 = self.task(key="b2"); self.build(t2)
        self.assertIn("forbidden", self.st(t2)["blocked_reason"])

    def test_codex_limit_and_empty_changes_block_with_the_cause(self):
        os.environ["FAKE_RC"] = "75"; tid = self.task(key="l"); self.build(tid)
        self.assertIn("out of usage", self.st(tid)["blocked_reason"])
        os.environ.pop("FAKE_RC"); os.environ["FAKE_DIFF"] = ""; t2 = self.task(key="e"); self.build(t2)
        self.assertIn("no change", self.st(t2)["blocked_reason"])

    def test_failed_independent_checks_block_the_release(self):
        tid = self.task(key="f")
        self.build(tid, checker=lambda wt, files: (False, ["full test suite: FAILED — 2 failures"]))
        t = self.st(tid); self.assertEqual(t["state"], "blocked"); self.assertIn("FAILED", t["blocked_reason"])
        self.assertIn("verification_failed", {r["to_state"] for r in self.db.execute("SELECT to_state FROM transitions WHERE task_id=?", (tid,))})

    def test_only_one_build_at_a_time_and_permission_is_respected(self):
        t1, t2 = self.task(key="a"), self.task(key="b")
        self.ada.transition(self.db, t1, "implementing", "forge")
        self.assertIn("another build", self.build(t2)); self.assertEqual(self.st(t2)["state"], "approved")
        self.ada.transition(self.db, t1, "blocked", "system", "x")
        self.ada.revoke(self.db, "forge", "build-in-worktree", "test")
        self.build(t2); self.assertEqual(self.st(t2)["state"], "blocked")

    def test_the_next_accepted_task_starts_when_a_build_ends(self):
        started = []
        self.forge.start_in_background = lambda tid: started.append(tid)
        t1, t2 = self.task(key="c1"), self.task(key="c2")
        self.build(t1)
        self.assertEqual(started, [t2])

    def test_the_build_base_gets_the_vendor_folder_the_builder_requires(self):
        os.makedirs(os.path.join(self.repo, "vendor"))
        tid = self.task(key="v"); self.build(tid)
        self.assertTrue(os.path.islink(os.path.join(self.tmp.name, "d", "builds", "_base", "vendor")))

    def test_real_independent_checks_run_syntax_rules_and_tests(self):
        tid = self.task(key="r"); self.forge.build(self.ns, self.db, tid, checker=lambda wt, f: (True, ["stub"]))
        wt = json.loads(self.st(tid)["result"])["worktree"]
        os.makedirs(os.path.join(wt, "vendor", "bin"), exist_ok=True)
        open(os.path.join(wt, "vendor", "bin", "phpunit"), "w").write("#!/bin/bash\necho 'OK (3 tests, 5 assertions)'\n"); os.chmod(os.path.join(wt, "vendor", "bin", "phpunit"), 0o755)
        ok, lines = self.forge.independent_checks(wt, ["public/includes/header.php"])
        self.assertTrue(ok, lines); self.assertTrue(any("full test suite: ok" in l for l in lines))
        open(os.path.join(wt, "public/includes/header.php"), "a").write("<?php if (\n")
        ok, lines = self.forge.independent_checks(wt, ["public/includes/header.php"]); self.assertFalse(ok)

    def test_accepting_starts_a_build_and_rejecting_does_not(self):
        started = []
        self.forge.start_in_background = lambda tid: started.append(tid)
        t1 = self.task(state="awaiting_approval", key="x1"); t2 = self.task(state="awaiting_approval", key="x2")
        self.ada.human_decision(self.db, t1, "approve", "yes", tty=True); self.ada.human_decision(self.db, t2, "reject", "no", tty=True)
        self.assertEqual(started, [t1])

    # ---- release
    def built(self):
        tid = self.task(); self.build(tid); return tid

    def runner(self, drift=False, fail_deploy=False):
        calls = []

        def r(cmd, cwd=None, timeout=0, env=None):
            calls.append(list(cmd))
            if cmd[:2] == ["bash", "scripts/prod-pull.sh"]:
                p = os.path.join(self.tmp.name, "live.php")
                open(p, "w").write("<?php\necho 'header';\n" if not drift else "<?php\necho 'DIFFERENT LIVE';\n")
                return 0, "pulled %s → %s" % (cmd[2], p)
            if cmd[:2] == ["bash", "scripts/deploy-file.sh"]:
                return (1, "deploy refused") if fail_deploy else (0, "Uploaded")
            return 0, ""
        r.calls = calls
        return r

    def test_release_needs_a_human_and_deploys_through_the_gated_scripts(self):
        tid = self.built(); run = self.runner()
        with self.assertRaises(self.ada.Refused):
            self.forge.release(self.ns, self.db, tid, tty=False, runner=run)
        log = self.forge.release(self.ns, self.db, tid, tty=True, runner=run)
        self.assertEqual(self.st(tid)["state"], "measuring_outcomes")
        kinds = [c[1] for c in run.calls if c[0] == "bash"]
        for k in ("scripts/prod-pull.sh", "scripts/deploy-file.sh", "scripts/prod-run.sh"):
            self.assertIn(k, kinds)
        self.assertLess(kinds.index("scripts/prod-pull.sh"), kinds.index("scripts/deploy-file.sh"))   # drift check first
        self.assertTrue(any("deployed" in l for l in log))

    def test_release_stops_when_production_has_drifted(self):
        tid = self.built(); run = self.runner(drift=True)
        with self.assertRaises(self.ada.Refused) as cm:
            self.forge.release(self.ns, self.db, tid, tty=True, runner=run)
        self.assertIn("differs", str(cm.exception))
        self.assertNotIn("scripts/deploy-file.sh", [c[1] for c in run.calls if c[0] == "bash"]); self.assertEqual(self.st(tid)["state"], "awaiting_release_approval")

    def test_a_failed_deploy_blocks_and_uploads_nothing_further(self):
        tid = self.built(); run = self.runner(fail_deploy=True)
        with self.assertRaises(self.ada.Refused):
            self.forge.release(self.ns, self.db, tid, tty=True, runner=run)
        self.assertEqual(self.st(tid)["state"], "blocked")

    def test_release_only_applies_to_built_work(self):
        tid = self.task()
        with self.assertRaises(self.ada.Refused):
            self.forge.release(self.ns, self.db, tid, tty=True, runner=self.runner())


if __name__ == "__main__":
    unittest.main()
