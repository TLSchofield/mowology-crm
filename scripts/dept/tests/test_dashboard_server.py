import http.client, importlib.util, json, os, sys, tempfile, threading, types, unittest, urllib.parse

os.environ["FORGE_AUTOSTART"] = "0"   # tests must never start a real build
HERE = os.path.dirname(os.path.abspath(__file__))
DEPT = os.path.join(HERE, "..")


def load(name):
    sys.path.insert(0, DEPT)
    spec = importlib.util.spec_from_file_location(name, os.path.join(DEPT, name + ".py"))
    m = importlib.util.module_from_spec(spec); sys.modules[name] = m; spec.loader.exec_module(m); return m


class Server(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory(); t = self.tmp.name
        os.environ.update(DEPT_HOME=os.path.join(t, "d"), SENTINEL_HOME=os.path.join(t, "s"), GUARDIAN_HOME=os.path.join(t, "g"))
        fx = os.path.join(t, "m.json"); json.dump({"results": [], "site": {}}, open(fx, "w")); os.environ["GUARDIAN_STANDIN_METRICS"] = fx
        os.environ.pop("ADA_CLAUDE_BIN", None)
        self.ada = load("ada"); self.dash = load("dashboard"); self.srv_mod = load("dashboard_server")
        self.db = self.ada.connect(); self.ada.seed_permissions(self.db)
        self.tid = self.ada.add_task(self.db, "k", "Fix the header", "improvement", "guardian", state="proposed", category="reliability", payload={"problem": "p"})[0]
        self.ada.prioritise(self.db)
        ns = types.SimpleNamespace(**vars(self.ada))
        self.srv = self.srv_mod.make_server(ns, self.dash)
        threading.Thread(target=self.srv.serve_forever, daemon=True).start()
        self.port = self.srv.state["port"]

    def tearDown(self):
        self.srv.shutdown(); self.srv.server_close()
        for k in ("GUARDIAN_STANDIN_METRICS", "GUARDIAN_HOME"):
            os.environ.pop(k, None)
        self.tmp.cleanup()

    def req(self, method, path, body=None, headers=None, host=None):
        c = http.client.HTTPConnection("127.0.0.1", self.port, timeout=10)
        h = {"Host": host or "127.0.0.1:%d" % self.port}; h.update(headers or {})
        c.request(method, path, body=body, headers=h)
        r = c.getresponse(); data = r.read().decode(); hdrs = dict(r.getheaders()); c.close()
        return r.status, data, hdrs

    def login(self):
        st, _, h = self.req("GET", "/?t=" + self.srv.token)
        self.assertEqual(st, 302)
        return h["Set-Cookie"].split(";")[0]

    def post(self, cookie, payload, **over):
        hd = {"Cookie": cookie, "Content-Type": "application/json", "X-Dept": "1", "Origin": "http://127.0.0.1:%d" % self.port}
        hd.update(over)
        return self.req("POST", "/api/decide", json.dumps(payload), hd)

    def state(self):
        return self.db.execute("SELECT state FROM tasks WHERE id=?", (self.tid,)).fetchone()["state"]

    def test_no_secret_no_page_and_wrong_secret_is_refused(self):
        self.assertEqual(self.req("GET", "/")[0], 403)
        self.assertEqual(self.req("GET", "/?t=nope")[0], 403)
        self.assertEqual(self.req("GET", "/", headers={"Cookie": "dept=wrong"})[0], 403)

    def test_the_secret_sets_an_httponly_strict_cookie_and_the_page_has_buttons(self):
        st, _, h = self.req("GET", "/?t=" + self.srv.token)
        self.assertIn("HttpOnly", h["Set-Cookie"]); self.assertIn("SameSite=Strict", h["Set-Cookie"])
        st, body, h = self.req("GET", "/", headers={"Cookie": h["Set-Cookie"].split(";")[0]})
        self.assertEqual(st, 200); self.assertIn("data-decision='approve'", body); self.assertIn("Content-Security-Policy", h)

    def test_dns_rebinding_host_is_refused(self):
        c = self.login()
        self.assertEqual(self.req("GET", "/", headers={"Cookie": c}, host="evil.example")[0], 403)

    def test_accept_works_records_the_reason_and_syncs_state(self):
        c = self.login()
        st, body, _ = self.post(c, {"id": self.tid, "decision": "approve", "reason": "yes, build it"})
        self.assertEqual((st, json.loads(body)["ok"]), (200, True))
        self.assertEqual(self.state(), "approved")
        r = self.db.execute("SELECT actor, reason FROM transitions WHERE task_id=? ORDER BY id DESC", (self.tid,)).fetchone()
        self.assertEqual(r["actor"], "tim"); self.assertIn("via dashboard", r["reason"])

    def test_a_reason_is_mandatory_and_bad_requests_change_nothing(self):
        c = self.login()
        for payload in ({"id": self.tid, "decision": "approve", "reason": "  "}, {"id": self.tid, "decision": "delete", "reason": "x"}, {"decision": "approve", "reason": "x"}):
            self.assertEqual(self.post(c, payload)[0], 400)
        self.assertEqual(self.state(), "awaiting_approval")

    def test_cross_site_and_headerless_posts_are_refused(self):
        c = self.login(); good = {"id": self.tid, "decision": "approve", "reason": "x"}
        self.assertEqual(self.post(c, good, Origin="https://evil.example")[0], 403)
        self.assertEqual(self.post(c, good, Origin="null")[0], 403)
        self.assertEqual(self.post(c, good, **{"X-Dept": "0"})[0], 400)
        self.assertEqual(self.post(c, good, **{"Content-Type": "text/plain"})[0], 400)
        self.assertEqual(self.req("POST", "/api/decide", json.dumps(good), {"Content-Type": "application/json", "X-Dept": "1", "Origin": "http://127.0.0.1:%d" % self.port})[0], 403)   # no cookie
        self.assertEqual(self.state(), "awaiting_approval")

    def test_illegal_moves_are_still_refused_by_the_engine(self):
        c = self.login()
        self.post(c, {"id": self.tid, "decision": "reject", "reason": "no"})
        st, body, _ = self.post(c, {"id": self.tid, "decision": "approve", "reason": "changed my mind"})
        self.assertEqual(st, 409); self.assertEqual(self.state(), "rejected")

    def test_revise_parks_the_item_so_it_does_not_bounce_back(self):
        c = self.login()
        self.post(c, {"id": self.tid, "decision": "revise", "reason": "smaller scope"})
        self.assertEqual(self.state(), "proposed"); self.ada.prioritise(self.db)
        self.assertEqual(self.state(), "proposed")

    def test_unblock_from_the_page(self):
        c = self.login()
        t2 = self.ada.add_task(self.db, "b", "Stuck", "audit", "guardian")[0]
        self.ada.transition(self.db, t2, "blocked", "system", "needs a human")
        self.assertEqual(self.post(c, {"id": t2, "decision": "unblock", "reason": "fixed"})[0], 200)
        self.assertEqual(self.db.execute("SELECT state FROM tasks WHERE id=?", (t2,)).fetchone()["state"], "discovered")

    def test_the_static_file_still_has_no_buttons_and_binds_to_localhost_only(self):
        self.assertNotIn("data-decision", self.dash.render(self.ada, self.db))
        self.assertEqual(self.srv.server_address[0], "127.0.0.1")


if __name__ == "__main__":
    unittest.main()


class ReleaseFromThePage(Server):
    def test_release_is_started_in_the_background_only_for_built_work(self):
        c = self.login()
        self.assertEqual(self.post(c, {"id": self.tid, "decision": "release", "reason": "go"})[0], 409)       # not awaiting release
        called = []
        import forge
        orig = forge.release
        forge.release = lambda ada, db, tid, tty, runner=None: called.append((tid, tty)) or []
        try:
            t2 = self.ada.add_task(self.db, "r", "Built", "improvement", "sentinel", state="awaiting_release_approval", actor="sentinel")[0]
            st, body, _ = self.post(c, {"id": t2, "decision": "release", "reason": "ship it"})
            import time; time.sleep(0.5)
        finally:
            forge.release = orig
        self.assertEqual((st, json.loads(body)["started"]), (200, True)); self.assertEqual(called, [(t2, True)])
