"""Interactive local dashboard: `ada dashboard --serve`. The ONLY way to decide from a browser, and built so only YOU can.

Threat model: an agent (its Playwright browser could visit localhost), another local process, or a web page you have open must not be able to
click Accept. Controls: binds 127.0.0.1 on a random port; a one-time secret exists only in the URL `ada` opens for you and in this process's memory
(never written to a file); the secret becomes an HttpOnly SameSite=Strict cookie; every request needs that cookie; the Host header must be
127.0.0.1/localhost:port (DNS-rebinding); POSTs need Origin = this server and a JSON body with a custom header (so no cross-site form can send one);
a reason is mandatory; every decision is logged with 'via dashboard'; the server stops after 30 idle minutes or on Ctrl-C.
It calls the same human_decision()/unblock() the terminal commands call, so all engine rules (legal moves, Sentinel ledger sync) still apply."""
import http.server, json, os, secrets, socketserver, sys, threading, time, urllib.parse

HERE = os.path.dirname(os.path.abspath(__file__))
MAX_BODY = 4096


def make_server(ada, dashboard, idle_minutes=30, port=0):
    token = secrets.token_urlsafe(24)
    state = {"last": time.time(), "port": None}

    class H(http.server.BaseHTTPRequestHandler):
        server_version = "IttaiDept"

        def log_message(self, *a):
            pass

        def _host_ok(self):
            return self.headers.get("Host", "") in ("127.0.0.1:%d" % state["port"], "localhost:%d" % state["port"])

        def _cookie_ok(self):
            for part in (self.headers.get("Cookie") or "").split(";"):
                k, _, v = part.strip().partition("=")
                if k == "dept" and secrets.compare_digest(v, token):
                    return True
            return False

        def _send(self, code, body, ctype="text/html; charset=utf-8", extra=()):
            b = body.encode("utf-8") if isinstance(body, str) else body
            self.send_response(code)
            self.send_header("Content-Type", ctype); self.send_header("Content-Length", str(len(b)))
            self.send_header("Cache-Control", "no-store"); self.send_header("X-Content-Type-Options", "nosniff")
            self.send_header("Content-Security-Policy", "default-src 'none'; style-src 'unsafe-inline'; script-src 'unsafe-inline'; connect-src 'self'; form-action 'none'; frame-ancestors 'none'; base-uri 'none'")
            for k, v in extra:
                self.send_header(k, v)
            self.end_headers(); self.wfile.write(b)

        def do_GET(self):
            state["last"] = time.time()
            if not self._host_ok():
                return self._send(403, "forbidden")
            u = urllib.parse.urlparse(self.path)
            q = urllib.parse.parse_qs(u.query)
            if u.path == "/" and q.get("t") and secrets.compare_digest(q["t"][0], token):    # first visit from the URL we opened for you
                return self._send(302, "", extra=[("Location", "/"), ("Set-Cookie", "dept=%s; HttpOnly; SameSite=Strict; Path=/" % token)])
            if not self._cookie_ok():
                return self._send(403, "Open this page with `ada dashboard --serve` (it carries a one-time secret).")
            if u.path != "/":
                return self._send(404, "not found")
            db = ada.connect()
            try:
                return self._send(200, dashboard.render(ada, db, interactive=True))
            finally:
                db.close()

        def do_POST(self):
            state["last"] = time.time()
            if not self._host_ok() or not self._cookie_ok():
                return self._send(403, json.dumps({"error": "forbidden"}), "application/json")
            if self.headers.get("Origin") not in ("http://127.0.0.1:%d" % state["port"], "http://localhost:%d" % state["port"]):
                return self._send(403, json.dumps({"error": "bad origin"}), "application/json")
            if self.path != "/api/decide" or self.headers.get("X-Dept") != "1" or "application/json" not in (self.headers.get("Content-Type") or ""):
                return self._send(400, json.dumps({"error": "bad request"}), "application/json")
            n = int(self.headers.get("Content-Length") or 0)
            if n <= 0 or n > MAX_BODY:
                return self._send(400, json.dumps({"error": "bad size"}), "application/json")
            try:
                body = json.loads(self.rfile.read(n))
                tid, decision, reason = int(body["id"]), str(body["decision"]), str(body["reason"]).strip()
            except (ValueError, KeyError, TypeError):
                return self._send(400, json.dumps({"error": "bad request"}), "application/json")
            if decision not in ("approve", "reject", "revise", "unblock", "release"):
                return self._send(400, json.dumps({"error": "unknown decision"}), "application/json")
            if not reason:
                return self._send(400, json.dumps({"error": "a reason is required"}), "application/json")
            db = ada.connect()
            try:
                if decision == "release":
                    t = ada.get_task(db, tid)
                    if t["state"] != "awaiting_release_approval":
                        raise ada.Refused("task %d is not awaiting release" % tid)
                    import forge

                    def go():
                        d2 = ada.connect()
                        try:
                            forge.release(ada, d2, tid, tty=True)
                        except Exception as e:  # noqa: BLE001  (the page shows the task as blocked/failed on the next refresh)
                            print("release failed: %s" % e, file=sys.stderr)
                        finally:
                            ada.refresh_dashboard(d2); d2.close()
                    threading.Thread(target=go, daemon=True).start()
                    return self._send(200, json.dumps({"ok": True, "started": True}), "application/json")
                if decision == "unblock":
                    ada.unblock(db, tid, "tim", reason + " (via dashboard)")
                else:
                    ada.human_decision(db, tid, decision, reason + " (via dashboard)", tty=True)    # tty=True: the authenticated browser is the human channel
                ada.refresh_dashboard(db)
                return self._send(200, json.dumps({"ok": True}), "application/json")
            except (ada.Refused, ada.C.Refused) as e:
                return self._send(409, json.dumps({"error": str(e)}), "application/json")
            finally:
                db.close()

    class S(socketserver.ThreadingMixIn, http.server.HTTPServer):
        daemon_threads = True

    srv = S(("127.0.0.1", port), H)
    state["port"] = srv.server_address[1]
    srv.token, srv.state, srv.idle = token, state, idle_minutes * 60
    srv.url = "http://127.0.0.1:%d/?t=%s" % (state["port"], token)
    return srv


def run(ada, dashboard, open_browser=True, idle_minutes=30):
    import subprocess
    srv = make_server(ada, dashboard, idle_minutes)
    t = threading.Thread(target=srv.serve_forever, daemon=True); t.start()
    print("Dashboard running at http://127.0.0.1:%d (only this Mac). Stops after %d idle minutes or Ctrl-C." % (srv.state["port"], idle_minutes))
    if open_browser:
        subprocess.run(["open", srv.url])
    else:
        print(srv.url)
    try:
        while time.time() - srv.state["last"] < srv.idle:
            time.sleep(5)
        print("Idle for %d minutes: dashboard stopped." % idle_minutes)
    except KeyboardInterrupt:
        print("Dashboard stopped.")
    srv.shutdown()


if __name__ == "__main__":
    sys.exit("run through: ada dashboard --serve")
