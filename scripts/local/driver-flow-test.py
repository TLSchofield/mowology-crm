#!/usr/bin/env python3
"""LOCAL ONLY — runs the phone driver workflow against the local test site, end to end, over the same JWT API the
Android app uses: sign in, clock in, say driving / not driving, pre-trip, the server's post-trip refusal on clock-out,
manager-PIN waiver, trip history and PDF. Never point it at production (it refuses any host but localhost).

  scripts/local/driver-flow-test.py [base-url]      default http://localhost:8000

Needs the local site (scripts/local-site.sh start) and the test user from scripts/local/seed-driver-test.php.
"""
import json, sys, urllib.request, urllib.error

BASE = (sys.argv[1] if len(sys.argv) > 1 else "http://localhost:8000").rstrip("/")
if not BASE.startswith(("http://localhost", "http://127.0.0.1")):
    sys.exit("refusing: local hosts only")
EMAIL, PASSWORD, PIN = "crew@local.test", "LocalTest-12345", "4821"
fails, token = [], ""

def call(method, path, body=None, headers=None, raw=False):
    h = {"Authorization": "Bearer " + token, "Content-Type": "application/json"} if token else {"Content-Type": "application/json"}
    h.update(headers or {})
    req = urllib.request.Request(BASE + path, method=method, headers=h, data=json.dumps(body).encode() if body is not None else None)
    try:
        r = urllib.request.urlopen(req, timeout=60); code, data, ctype = r.status, r.read(), r.headers.get("Content-Type", "")
    except urllib.error.HTTPError as e:
        code, data, ctype = e.code, e.read(), e.headers.get("Content-Type", "")
    if raw: return code, data, ctype
    try: return code, json.loads(data)
    except Exception: return code, {"_raw": data[:200].decode("utf8", "replace")}

def check(name, cond, extra=""):
    print(("  ok    " if cond else "  FAIL  ") + name + (f"   {extra}" if not cond and extra else ""))
    if not cond: fails.append(name)

CHECKS = {k: True for k in ["leaks","hitch","rear_gate","loads_secure","trailer_lights","truck_brakes","truck_lights","mirrors","tire_pressure","washer_wipers"]}
PRE = {"action": "pre_trip", "vehicle_id": "TEST-TRUCK", "odometer_start": 1000, "safe_to_drive": 1,
       **{"chk_" + k: 1 for k in CHECKS}, "defects_critical": "", "defects_non_urgent": ""}

def clock_out(**kw):
    return call("POST", "/api/schedule/clock", {"action": "clock_out", **kw})

def reset():
    c, s = call("GET", "/api/schedule/clock?mode=status")
    if s.get("clocked_in") or (s.get("data") or {}).get("clocked_in"):
        clock_out()

code, tok = call("POST", "/api/auth/token.php", {"email": EMAIL, "password": PASSWORD})
token = tok.get("token", "")
check("sign in", bool(token), str(tok)[:120])
if not token: sys.exit(1)
reset()

print("\nFlow 1 — driver: pre-trip, then the server holds the clock-out until the post-trip")
c, r = call("POST", "/api/schedule/clock", {"action": "clock_in"}); check("clock in", r.get("success") is True, str(r)[:160])
c, r = call("GET", "/api/schedule/trip-report"); check("status before answering", c == 200, str(r)[:160])
c, r = call("POST", "/api/schedule/trip-report", {"action": "declare", "driving": True, "vehicle_id": "TEST-TRUCK"}); check("declare driving", r.get("success") is True, str(r)[:160])
c, r = call("POST", "/api/schedule/trip-report", PRE); check("pre-trip saved", r.get("success") is True, str(r)[:200])
c, r = clock_out(); check("legacy clock-out (no trip_gate) is NOT refused", c == 200 and r.get("success") is True, f"{c} {str(r)[:160]}")
# the legacy call above clocked him out; clock in again with the open trip still there
c, r = call("POST", "/api/schedule/clock", {"action": "clock_in"}); check("clock in again", r.get("success") is True, str(r)[:160])
c, r = clock_out(trip_gate=True); check("live clock-out with trip_gate: 409 post_trip_required", c == 409 and r.get("error_code") == "post_trip_required", f"{c} {str(r)[:200]}")
check("refusal says still clocked in", r.get("clocked_in") is True)
c, r = call("GET", "/api/schedule/clock?mode=status"); st = r.get("data", r); check("still clocked in after refusal", bool(st.get("clocked_in")), str(r)[:160])
c, r = clock_out(trip_gate=True, queued=True); check("replayed offline clock-out is never refused", c == 200 and r.get("success") is True, f"{c} {str(r)[:160]}")
call("POST", "/api/schedule/clock", {"action": "clock_in"})
c, r = call("POST", "/api/schedule/trip-report", {"action": "post_trip", "odometer_end": 1012, "end_of_day_remarks": "test"}); check("post-trip saved", r.get("success") is True, str(r)[:200])
c, r = clock_out(trip_gate=True); check("clock-out after post-trip works", c == 200 and r.get("success") is True, f"{c} {str(r)[:160]}")

print("\nFlow 2 — manager PIN waives the pre-trip")
call("POST", "/api/schedule/clock", {"action": "clock_in"})
call("POST", "/api/schedule/trip-report", {"action": "declare", "driving": True, "vehicle_id": "TEST-TRUCK"})
c, r = call("POST", "/api/schedule/trip-report", {"action": "pre_trip_bypass", "pin": "0000", "vehicle_id": "TEST-TRUCK"}); check("wrong PIN refused (403)", c == 403, f"{c} {str(r)[:160]}")
c, r = call("POST", "/api/schedule/trip-report", {"action": "pre_trip_bypass", "pin": PIN, "reason": "test", "vehicle_id": "TEST-TRUCK"}); check("right PIN accepted", c == 200 and r.get("success") is True, f"{c} {str(r)[:200]}")
c, r = clock_out(trip_gate=True); check("after a waived pre-trip the post-trip is still required", c == 409 and r.get("error_code") == "post_trip_required", f"{c} {str(r)[:200]}")
call("POST", "/api/schedule/trip-report", {"action": "post_trip", "odometer_end": 1020})
c, r = clock_out(trip_gate=True); check("clock-out after post-trip", c == 200, f"{c} {str(r)[:160]}")

print("\nFlow 3 — trip log and PDF")
c, r = call("GET", "/api/schedule/trip-report?mode=history&limit=30"); trips = r.get("trips") or []
check("history lists trips", c == 200 and len(trips) >= 2, f"{c} {str(r)[:200]}")
check("history shows a skipped pre-trip", any(t.get("pre_trip_skipped") for t in trips))
withpdf = [t for t in trips if t.get("has_pdf")]
check("a trip has a PDF", bool(withpdf))
if withpdf:
    c, data, ctype = call("GET", f"/api/schedule/trip-report?mode=pdf&report_id={withpdf[0]['report_id']}", raw=True)
    check("PDF downloads as application/pdf", c == 200 and "pdf" in ctype and data[:4] == b"%PDF", f"{c} {ctype} {data[:40]!r}")
c, data, ctype = call("GET", "/api/schedule/trip-report?mode=pdf&report_id=999999", raw=True); check("someone else's / missing report is 404", c == 404, str(c))

print("\nFlow 4 — not driving: never asked for anything")
call("POST", "/api/schedule/clock", {"action": "clock_in"})
c, r = call("POST", "/api/schedule/trip-report", {"action": "declare", "driving": False}); check("declare not driving", r.get("success") is True, str(r)[:160])
c, r = clock_out(trip_gate=True); check("clock-out with trip_gate is not refused", c == 200 and r.get("success") is True, f"{c} {str(r)[:160]}")

print("\n" + ("ALL PASSED" if not fails else f"{len(fails)} FAILED: " + "; ".join(fails)))
sys.exit(1 if fails else 0)
