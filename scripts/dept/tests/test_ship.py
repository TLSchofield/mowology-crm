import json, os, sys, types, unittest

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE); sys.path.insert(0, os.path.join(HERE, ".."))
import test_forge as TF  # noqa: E402
import test_dashboard_server as TS  # noqa: E402


def page(name, width, status=200, kb=100, cls=0.0, small=0, nodes=300, errs=0, contrast=None, shot="a.png"):
    return {"page": name, "url": "https://x/" + name, "width": width, "ok": status == 200, "status": status, "shot": shot, "transfer_kb": kb, "cls": cls,
            "small_targets": small, "tiny_text": 0, "dom_nodes": nodes, "console_errors": errs, "failed_requests": 0, "axe_serious": 0, "contrast": contrast or [], "error": None}


class Units(unittest.TestCase):
    def setUp(self):
        import ship
        self.ship = ship

    def test_routing_and_config_files_are_held_for_the_owner(self):
        self.assertIn("cms-render", self.ship.hold_reason(["public/cms-render.php"]))
        self.assertIsNone(self.ship.hold_reason(["public/includes/header.php", "public/contact.php"]))

    def test_pages_to_measure_come_from_the_changed_files(self):
        urls = [p["url"] for p in self.ship.page_urls(["public/contact.php"])]
        self.assertEqual(urls, ["https://mowology.ca/contact"])
        urls = [p["url"] for p in self.ship.page_urls(["public/includes/header.php"])]
        self.assertEqual(urls, ["https://mowology.ca/", "https://mowology.ca/contact"])
        self.assertEqual(self.ship.page_urls(["public/crm/x.php"]), [])

    def test_live_check_passes_a_healthy_page_and_fails_a_broken_or_heavier_one(self):
        before = {"pages": [page("home", 375)]}
        self.assertTrue(self.ship.live_check(before, {"pages": [page("home", 375, kb=110)]})[0])
        ok, lines = self.ship.live_check(before, {"pages": [page("home", 375, status=500)]})
        self.assertFalse(ok); self.assertIn("FAILED to load", lines[0])
        self.assertFalse(self.ship.live_check(before, {"pages": [page("home", 375, nodes=5)]})[0])           # a truncated 200
        self.assertFalse(self.ship.live_check(before, {"pages": [page("home", 375, kb=900)]})[0])
        self.assertFalse(self.ship.live_check(before, {"pages": [page("home", 375, errs=2)]})[0])
        self.assertFalse(self.ship.live_check(before, {"error": "no browser", "pages": []})[0])
        self.assertTrue(self.ship.live_check({"pages": [page("home", 375, status=500)]}, {"pages": [page("home", 375)]})[0])   # was already broken: not blamed on this change

    def test_compare_pairs_the_same_page_and_width(self):
        c = [{"fg": "#777", "bg": "#fff", "ratio": 3.1, "target": ".a"}]
        d = [{"fg": "#222", "bg": "#fff", "ratio": 7.4, "target": ".a"}]
        rows = self.ship.compare({"pages": [page("home", 375, contrast=c), page("home", 1440)]}, {"pages": [page("home", 375, contrast=d), page("other", 375)]})
        self.assertEqual(len(rows), 1); self.assertEqual(rows[0]["contrast_before"][0]["ratio"], 3.1); self.assertEqual(rows[0]["contrast_after"][0]["ratio"], 7.4)

    def test_review_reports_defects_and_a_reviewer_that_cannot_run(self):
        run = lambda cmd, cwd=None, timeout=0: (0, "diff")  # noqa: E731
        self.assertTrue(self.ship.review("/x", "t", ["a"], run, reviewer=lambda w, t, d: (True, "fine", []))["ok"])
        r = self.ship.review("/x", "t", ["a"], run, reviewer=lambda w, t, d: (False, "bad", [{"file": "a", "line": 1, "what": "breaks"}]))
        self.assertFalse(r["ok"]); self.assertEqual(len(r["defects"]), 1)
        def boom(*a):
            raise RuntimeError("down")
        self.assertIsNone(self.ship.review("/x", "t", ["a"], run, reviewer=boom)["ok"])


class Ship(unittest.TestCase):
    setUp = TF.Forge.setUp; tearDown = TF.Forge.tearDown; task = TF.Forge.task; st = TF.Forge.st; build = TF.Forge.build; built = TF.Forge.built; runner = TF.Forge.runner

    def patch(self, before_ok=True, after_status=200):
        import ship
        self.ship = ship; self._cap, self._rev = ship.capture, ship.claude_review
        def cap(tid, phase, pages, runner=None):
            return {"phase": phase, "pages": [page("home", 375, status=200 if phase == "before" else after_status), page("home", 1440, status=200 if phase == "before" else after_status)]}
        ship.capture = cap
        os.environ["FORGE_VISUALS"] = "1"
        self.addCleanup(lambda: (setattr(ship, "capture", self._cap), setattr(ship, "claude_review", self._rev)))

    def test_release_records_before_and_after_and_a_green_live_check(self):
        tid = self.built(); self.patch()
        self.forge.release(self.ns, self.db, tid, tty=True, runner=self.runner())
        res = json.loads(self.st(tid)["result"]); v = res["visuals"]
        self.assertTrue(v["ok"]); self.assertEqual(len(v["compare"]), 2); self.assertEqual(self.st(tid)["state"], "measuring_outcomes")

    def test_a_failed_live_check_puts_the_old_file_back_and_blocks(self):
        tid = self.built(); self.patch(after_status=500); run = self.runner()
        self.forge.release(self.ns, self.db, tid, tty=True, runner=run)
        t = self.st(tid); res = json.loads(t["result"])
        self.assertEqual(t["state"], "blocked"); self.assertTrue(res["rolled_back"])
        self.assertEqual(sum(1 for c in run.calls if c[:2] == ["bash", "scripts/deploy-file.sh"]), 2)       # the deploy, then the restore
        self.assertIn("restored", " ".join(res["release_log"]))
        self.assertEqual(open(os.path.join(res["worktree"], "public/includes/header.php")).read(), "<?php\necho 'header';\n")

    def shipping(self, files=None, review=(True, "no defects", [])):
        tid = self.built(); self.patch(); os.environ["FORGE_AUTOSHIP"] = "1"
        import ship; ship.claude_review = lambda wt, title, diff: review
        t = self.st(tid); p = json.loads(t["payload"]); p["ship_on_accept"] = True
        res = json.loads(t["result"])
        run = self.runner()
        out = self.forge.auto_ship(self.ns, self.db, tid, t, p, res["worktree"], files or res["files"], run)
        return tid, out, run

    def test_accept_ships_when_everything_passes(self):
        tid, out, run = self.shipping()
        self.assertEqual(self.st(tid)["state"], "measuring_outcomes"); self.assertIn("live and confirmed", out)
        self.assertTrue(any(c[:2] == ["bash", "scripts/deploy-file.sh"] for c in run.calls))

    def test_a_review_that_finds_a_defect_holds_the_change(self):
        tid, out, run = self.shipping(review=(False, "one problem", [{"file": "public/includes/header.php", "line": 2, "what": "breaks the nav"}]))
        t = self.st(tid); self.assertEqual(t["state"], "awaiting_release_approval"); self.assertIn("held", out)
        self.assertFalse(any(c[:2] == ["bash", "scripts/deploy-file.sh"] for c in run.calls))
        self.assertIn("breaks the nav", json.loads(t["result"])["review_verdict"])

    def test_a_reviewer_that_cannot_run_holds_the_change(self):
        tid, out, run = self.shipping(review=(None, "no reviewer is available", []))
        self.assertEqual(self.st(tid)["state"], "awaiting_release_approval"); self.assertIn("could not run", out)

    def test_routing_files_are_held_even_when_the_review_passes(self):
        tid, out, run = self.shipping(files=["public/cms-render.php"])
        self.assertEqual(self.st(tid)["state"], "awaiting_release_approval"); self.assertIn("Release yourself", out)

    def test_without_the_accept_flag_nothing_ships(self):
        tid = self.built(); t = self.st(tid); res = json.loads(t["result"])
        self.assertEqual(self.forge.auto_ship(self.ns, self.db, tid, t, json.loads(t["payload"]), res["worktree"], res["files"], self.runner()), "")

    def test_accepting_marks_the_task_to_ship(self):
        tid = self.task(state="awaiting_approval", key="acc")
        self.ada.human_decision(self.db, tid, "approve", "ok", tty=True, code="x")
        self.assertTrue(json.loads(self.st(tid)["payload"])["ship_on_accept"])


class Dash(TS.Server):
    def test_cards_show_checks_swatches_bars_and_held_reason(self):
        import dashboard, ship
        res = {"branch": "b", "files": ["a"], "lines": 2, "verification": ["php -l: ok"], "held": "routing change",
               "visuals": {"pages": ["https://x/"], "checks": ["live pages load fully"], "compare": [{"page": "home", "width": 375,
               "before": {"transfer_kb": 900, "cls": 0.2, "small_targets": 6, "axe_serious": 2, "shot": "b.png"}, "after": {"transfer_kb": 100, "cls": 0.1, "small_targets": 0, "axe_serious": 0, "shot": "a.png"},
               "contrast_before": [{"fg": "#777777", "bg": "#ffffff", "ratio": 3.1, "target": ".a"}], "contrast_after": [{"fg": "#222222", "bg": "#ffffff", "ratio": 7.4, "target": ".a"}]}]}}
        html = dashboard._visuals(7, res, True)
        for needle in ("background:#777777", "3.1", "7.4", "900", "routing change", "/visual/7/before/b.png", "live pages load fully"):
            self.assertIn(needle, html)
        self.assertNotIn("<img", dashboard._visuals(7, res, False))
        self.assertNotIn("<script", dashboard._visuals(7, {"held": "<script>x</script>"}, True))

    def test_the_image_route_serves_only_saved_screenshots(self):
        import ship
        d = os.path.join(ship.visuals_root(), "7", "before", "shots"); os.makedirs(d)
        open(os.path.join(d, "a.png"), "w").write("PNGDATA")
        open(os.path.join(ship.visuals_root(), "secret.png"), "w").write("nope")
        self.assertEqual(self.req("GET", "/visual/7/before/a.png")[0], 403)                      # not signed in
        c = self.login(); hd = {"Cookie": c}
        st, body, h = self.req("GET", "/visual/7/before/a.png", headers=hd)
        self.assertEqual((st, body, h["Content-Type"]), (200, "PNGDATA", "image/png"))
        self.assertEqual(self.req("GET", "/visual/7/before/..%2F..%2Fsecret.png", headers=hd)[0], 404)
        self.assertEqual(self.req("GET", "/visual/7/before/../../secret.png", headers=hd)[0], 404)
        self.assertEqual(self.req("GET", "/visual/7/after/a.png", headers=hd)[0], 404)


if __name__ == "__main__":
    unittest.main()
