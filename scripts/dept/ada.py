#!/usr/bin/env python3
"""Ada — orchestration core of Ittai's Continuous Improvement Department (development Ada, NOT the customer-facing Ada).

One SQLite file ($DEPT_HOME/dept.db, default ~/.mowology-dept) holds the task queue, every state transition, the
permission register, agent runs and controls. Rules live in code, not in prompts:
  * a task moves only along TRANSITIONS, and only by an actor allowed to make that move (validated + logged);
  * only the human ('tim', via an interactive terminal) can approve, release or reject;
  * Guardian alone can mark work verified; Forge alone can hand work to verification (Forge never judges itself);
  * the same piece of work cannot be queued twice (dedupe_key); retries are capped; a failed task becomes Blocked with its cause;
  * pause / resume / stop-all are honoured by every tick; interrupted runs are recovered, never repeated blindly.
Usage: ada status | tasks | cycle | tick | briefing | approve|reject|revise <id> --reason ... | pause|resume|stop|unstop | revoke ...
"""
import argparse, datetime, json, os, shutil, sqlite3, subprocess, sys, time

HOME = os.path.expanduser("~")
DEPT = os.environ.get("DEPT_HOME", os.path.join(HOME, ".mowology-dept"))
DB = os.path.join(DEPT, "dept.db")
HERE = os.path.dirname(os.path.abspath(__file__))

STATES = ["discovered", "investigating", "proposed", "awaiting_approval", "approved", "implementing", "awaiting_verification",
          "verification_failed", "verified", "awaiting_release_approval", "released", "measuring_outcomes", "completed",
          "blocked", "rejected"]
TERMINAL = {"completed", "rejected"}
AGENTS = {"sentinel", "artisan", "forge", "guardian", "mercury", "empath", "atlas", "oracle"}
PROPOSERS = AGENTS | {"ada"}
# (from_state, to_state) -> actors allowed. 'system' = recovery inside tick. Anything not listed is refused.
TRANSITIONS = {
    ("discovered", "investigating"): PROPOSERS | {"system"},
    ("investigating", "proposed"): PROPOSERS,
    ("investigating", "completed"): PROPOSERS,            # audit-kind tasks only (checked below)
    ("investigating", "discovered"): {"system"},          # recovery of an interrupted run
    ("proposed", "awaiting_approval"): {"ada"},
    ("proposed", "investigating"): {"ada"},
    ("awaiting_approval", "approved"): {"tim"},
    ("awaiting_approval", "proposed"): {"tim"},           # request revision
    ("approved", "implementing"): {"forge"},
    ("implementing", "awaiting_verification"): {"forge"},
    ("implementing", "approved"): {"system"},             # recovery
    ("awaiting_verification", "verified"): {"guardian"},
    ("awaiting_verification", "verification_failed"): {"guardian"},
    ("verification_failed", "implementing"): {"forge"},
    ("verified", "awaiting_release_approval"): {"ada"},
    ("awaiting_release_approval", "released"): {"tim"},
    ("awaiting_release_approval", "implementing"): {"tim"},
    ("released", "measuring_outcomes"): {"ada"},
    ("measuring_outcomes", "completed"): {"sentinel", "ada"},
}
for s in STATES:
    if s not in TERMINAL and s != "blocked":
        TRANSITIONS[(s, "blocked")] = PROPOSERS | {"system", "tim"}
        TRANSITIONS[(s, "rejected")] = {"tim", "ada"} if s in ("discovered", "investigating", "proposed") else {"tim"}
RESUME_STATES = {"discovered", "investigating", "proposed", "approved", "implementing", "awaiting_verification"}
TIM_ONLY = {"approved", "released"}
RISK_ORDER = {"low": 1, "medium": 2, "high": 3, "critical": 4}
CRITICAL_CATEGORIES = {"security", "data-integrity", "financial", "reliability"}
MAX_ATTEMPTS = 2
MAX_RUNS_PER_CYCLE = 3
STALE_MINUTES = 90
MIN_FREE_GB = 5


def now():
    return datetime.datetime.now().astimezone().isoformat(timespec="seconds")


def connect():
    os.makedirs(DEPT, exist_ok=True)
    db = sqlite3.connect(DB, timeout=30)
    db.row_factory = sqlite3.Row
    db.executescript("""
    CREATE TABLE IF NOT EXISTS tasks(
      id INTEGER PRIMARY KEY AUTOINCREMENT, dedupe_key TEXT UNIQUE NOT NULL, title TEXT NOT NULL, kind TEXT NOT NULL,
      agent TEXT NOT NULL, state TEXT NOT NULL, blocked_from TEXT, blocked_reason TEXT, risk TEXT NOT NULL DEFAULT 'low',
      category TEXT NOT NULL DEFAULT 'ordinary', priority_score REAL NOT NULL DEFAULT 0, rationale TEXT,
      depends_on TEXT NOT NULL DEFAULT '[]', attempts INTEGER NOT NULL DEFAULT 0, max_attempts INTEGER NOT NULL DEFAULT 2,
      payload TEXT NOT NULL DEFAULT '{}', source_ref TEXT, implementer TEXT, created_at TEXT, updated_at TEXT, heartbeat_at TEXT);
    CREATE TABLE IF NOT EXISTS transitions(
      id INTEGER PRIMARY KEY AUTOINCREMENT, task_id INTEGER NOT NULL, from_state TEXT, to_state TEXT NOT NULL,
      actor TEXT NOT NULL, reason TEXT, at TEXT NOT NULL);
    CREATE TABLE IF NOT EXISTS permissions(
      id INTEGER PRIMARY KEY AUTOINCREMENT, agent TEXT NOT NULL, category TEXT NOT NULL, level INTEGER NOT NULL,
      tools TEXT, repos TEXT, environments TEXT, max_risk TEXT NOT NULL, approval TEXT, limits TEXT,
      authorised_at TEXT NOT NULL, authorised_by TEXT NOT NULL, revoked_at TEXT, revoke_reason TEXT);
    CREATE TABLE IF NOT EXISTS runs(
      id INTEGER PRIMARY KEY AUTOINCREMENT, task_id INTEGER, agent TEXT NOT NULL, started_at TEXT NOT NULL, ended_at TEXT,
      status TEXT NOT NULL, exit_code INTEGER, seconds REAL, cost_usd REAL, note TEXT);
    CREATE TABLE IF NOT EXISTS controls(key TEXT PRIMARY KEY, value TEXT, at TEXT);
    CREATE TABLE IF NOT EXISTS disagreements(
      id INTEGER PRIMARY KEY AUTOINCREMENT, task_id INTEGER, parties TEXT, issue TEXT, resolution TEXT, at TEXT);
    """)
    return db


# ------------------------------------------------------------------ controls
def get_ctl(db, key, default=None):
    r = db.execute("SELECT value FROM controls WHERE key=?", (key,)).fetchone()
    return r["value"] if r else default


def set_ctl(db, key, value):
    if value is None:
        db.execute("DELETE FROM controls WHERE key=?", (key,))
    else:
        db.execute("INSERT INTO controls(key,value,at) VALUES(?,?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value, at=excluded.at", (key, value, now()))
    db.commit()


def halted(db, agent=None):
    """Why nothing may run right now, or None."""
    if get_ctl(db, "stopped"):
        return "emergency stop is on (ada unstop)"
    if get_ctl(db, "paused"):
        return "department is paused (ada resume)"
    if agent and get_ctl(db, "paused:" + agent):
        return "agent %s is paused" % agent
    until = get_ctl(db, "limit_until")
    if until and until > now():
        return "subscription limit hold until " + until
    return None


# --------------------------------------------------------------- permissions
def seed_permissions(db):
    if db.execute("SELECT COUNT(*) c FROM permissions").fetchone()["c"]:
        return
    reg = json.load(open(os.path.join(HERE, "..", "sentinel", "registry.json")))["agents"]["sentinel"]
    db.execute("INSERT INTO permissions(agent,category,level,tools,repos,environments,max_risk,approval,limits,authorised_at,authorised_by) VALUES(?,?,?,?,?,?,?,?,?,?,?)",
               ("sentinel", "audit-public-web", 1, json.dumps(reg["tools_allowed"]), json.dumps(["code snapshot, read-only"]),
                json.dumps(["public websites in targets.json", "local simulators"]), "low", "none (reports only)",
                json.dumps({"budget_usd_per_run": reg["budget_usd_per_run"], "max_recommendations_daily": 3}), now(), "tim"))
    db.commit()


def permitted(db, agent, category, risk="low"):
    r = db.execute("SELECT * FROM permissions WHERE agent=? AND category=? AND revoked_at IS NULL", (agent, category)).fetchone()
    return bool(r) and RISK_ORDER[risk] <= RISK_ORDER[r["max_risk"]]


def revoke(db, agent, category, reason):
    n = db.execute("UPDATE permissions SET revoked_at=?, revoke_reason=? WHERE agent=? AND category=? AND revoked_at IS NULL", (now(), reason, agent, category)).rowcount
    db.commit()
    return n


# --------------------------------------------------------------- task queue
class Refused(Exception):
    pass


def add_task(db, dedupe_key, title, kind, agent, state="discovered", risk="low", category="ordinary", depends_on=(), payload=None,
             source_ref=None, score=0.0, rationale=None, actor="ada"):
    """Create a task unless the same work is already queued (returns the existing id then, created=False)."""
    row = db.execute("SELECT id FROM tasks WHERE dedupe_key=?", (dedupe_key,)).fetchone()
    if row:
        return row["id"], False
    t = now()
    cur = db.execute("INSERT INTO tasks(dedupe_key,title,kind,agent,state,risk,category,priority_score,rationale,depends_on,payload,source_ref,max_attempts,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
                     (dedupe_key, title, kind, agent, state, risk, category, score, rationale, json.dumps(list(depends_on)), json.dumps(payload or {}), source_ref, MAX_ATTEMPTS, t, t))
    db.execute("INSERT INTO transitions(task_id,from_state,to_state,actor,reason,at) VALUES(?,?,?,?,?,?)", (cur.lastrowid, None, state, actor, "created", t))
    db.commit()
    return cur.lastrowid, True


def get_task(db, tid):
    r = db.execute("SELECT * FROM tasks WHERE id=?", (tid,)).fetchone()
    if not r:
        raise Refused("no task %s" % tid)
    return r


def transition(db, tid, to_state, actor, reason=""):
    t = get_task(db, tid)
    frm = t["state"]
    if to_state not in STATES:
        raise Refused("unknown state " + to_state)
    if frm in TERMINAL:
        raise Refused("task %s is %s and cannot change" % (tid, frm))
    allowed = TRANSITIONS.get((frm, to_state))
    if allowed is None or actor not in allowed:
        raise Refused("%s may not move task %s from %s to %s" % (actor, tid, frm, to_state))
    if to_state in TIM_ONLY and actor != "tim":
        raise Refused("only the product owner can move work to " + to_state)
    if (frm, to_state) == ("investigating", "completed") and t["kind"] != "audit":
        raise Refused("only audit tasks may complete straight from investigating")
    if to_state == "verified" and t["implementer"] == actor:
        raise Refused("an agent cannot verify its own implementation")
    deps = json.loads(t["depends_on"])
    if to_state in ("investigating", "implementing") and deps:
        open_ = [d for d in deps if (db.execute("SELECT state FROM tasks WHERE id=?", (d,)).fetchone() or {"state": None})["state"] != "completed"]
        if open_:
            raise Refused("task %s waits for %s" % (tid, open_))
    ts = now()
    if to_state == "awaiting_verification":
        db.execute("UPDATE tasks SET implementer=? WHERE id=?", (actor, tid))
    if to_state == "blocked":
        db.execute("UPDATE tasks SET blocked_from=?, blocked_reason=? WHERE id=?", (frm, reason, tid))
    db.execute("UPDATE tasks SET state=?, updated_at=?, heartbeat_at=? WHERE id=?", (to_state, ts, ts, tid))
    db.execute("INSERT INTO transitions(task_id,from_state,to_state,actor,reason,at) VALUES(?,?,?,?,?,?)", (tid, frm, to_state, actor, reason, ts))
    db.commit()


def unblock(db, tid, actor, reason):
    t = get_task(db, tid)
    if t["state"] != "blocked":
        raise Refused("task %s is not blocked" % tid)
    if actor not in ("ada", "tim"):
        raise Refused("only ada or the product owner can unblock")
    back = t["blocked_from"] if t["blocked_from"] in RESUME_STATES else "discovered"
    ts = now()
    db.execute("UPDATE tasks SET state=?, blocked_reason=NULL, attempts=0, updated_at=? WHERE id=?", (back, ts, tid))
    db.execute("INSERT INTO transitions(task_id,from_state,to_state,actor,reason,at) VALUES(?,?,?,?,?,?)", (tid, "blocked", back, actor, reason, ts))
    db.commit()


def human_decision(db, tid, decision, reason, tty):
    """approve | reject | revise — the only way work becomes Approved. Needs a real terminal (agents have none)."""
    if not tty:
        raise Refused("approvals need an interactive terminal; an agent or script cannot approve")
    if not reason.strip():
        raise Refused("a reason is required — the department learns from it")
    to = {"approve": "approved", "reject": "rejected", "revise": "proposed"}[decision]
    transition(db, tid, to, "tim", reason)
    t = get_task(db, tid)
    sen = os.path.join(HERE, "..", "sentinel", "sentinel.py")
    if t["source_ref"] and t["agent"] == "sentinel" and os.path.exists(sen):  # one shared decision history
        verb = {"approve": "accept", "reject": "reject", "revise": "modify"}[decision]
        subprocess.run([sys.executable, sen, "decide", t["source_ref"], verb, reason], env=dict(os.environ), capture_output=True)


# ------------------------------------------------------------- prioritising
def prioritise(db):
    """Proposed -> Awaiting approval. Critical categories first and unlimited; ordinary work capped at three waiting for Tim."""
    moved = []
    waiting = db.execute("SELECT COUNT(*) c FROM tasks WHERE state='awaiting_approval' AND category NOT IN ('security','data-integrity','financial','reliability')").fetchone()["c"]
    rows = db.execute("SELECT * FROM tasks WHERE state='proposed' ORDER BY (category IN ('security','data-integrity','financial','reliability')) DESC, priority_score DESC, id").fetchall()
    for r in rows:
        crit = r["category"] in CRITICAL_CATEGORIES
        if not crit and waiting >= 3:
            continue
        transition(db, r["id"], "awaiting_approval", "ada", r["rationale"] or "prioritised")
        moved.append(r["id"])
        if not crit:
            waiting += 1
    return moved


def import_sentinel(db):
    """Sentinel's proposals become Proposed tasks (once: dedupe on its recommendation id)."""
    sen_home = os.environ.get("SENTINEL_HOME", os.path.join(HOME, ".mowology-sentinel"))
    led = os.path.join(sen_home, "ledger", "decisions.jsonl")
    n = 0
    if not os.path.exists(led):
        return 0
    decided = {}
    for line in open(led, encoding="utf-8"):
        try:
            e = json.loads(line)
        except ValueError:
            continue
        if e.get("event") == "decision":
            decided[e["id"]] = e["decision"]
    for line in open(led, encoding="utf-8"):
        try:
            e = json.loads(line)
        except ValueError:
            continue
        if e.get("event") != "proposed":
            continue
        rec = e["rec"]
        rid = e["id"]
        if decided.get(rid) == "reject":
            continue
        cat = "ordinary"
        if rec.get("area") == "code" and any(w in (rec.get("title", "") + rec.get("problem", "")).lower() for w in ("security", "credential", "injection", "tenant")):
            cat = "security"
        risk = (rec.get("risk") or {}).get("level", "low")
        why = "impact %s × confidence %s ÷ effort %s = %s (Sentinel's estimates, not measurements)" % (rec.get("impact"), rec.get("confidence"), (rec.get("effort") or {}).get("size"), rec.get("priority_score"))
        st = "approved" if decided.get(rid) in ("accept", "modify") else "proposed"
        _, created = add_task(db, "sentinel:" + rid, rec.get("title", rid), "improvement", "sentinel", state=st, risk=risk if risk in RISK_ORDER else "low",
                              category=cat, payload=rec, source_ref=rid, score=float(rec.get("priority_score") or 0), rationale=why, actor="tim" if st == "approved" else "sentinel")
        n += created
    return n


# ----------------------------------------------------------------- workers
def sentinel_worker(task, db):
    """Run Sentinel's audit through its own runner. Returns (ok, note). Exit 75 = not signed in / limit: named, not retried."""
    kind = json.loads(task["payload"]).get("audit", "daily")
    script = os.environ.get("ADA_SENTINEL_RUNNER", os.path.join(HERE, "..", "sentinel", "run-audit.sh"))
    p = subprocess.run(["bash", script, kind], capture_output=True, text=True, timeout=int(os.environ.get("ADA_TASK_TIMEOUT", "2400")))
    out = (p.stdout or "") + (p.stderr or "")
    last = [l for l in out.strip().splitlines() if l.strip()][-1:] or [""]
    if p.returncode == 0:
        return True, last[0]
    if p.returncode == 75:
        if "limit" in out.lower():
            set_ctl(db, "limit_until", (datetime.datetime.now().astimezone() + datetime.timedelta(hours=5)).isoformat(timespec="seconds"))
        return False, "needs you: " + last[0]
    return False, "exit %s: %s" % (p.returncode, last[0])


WORKERS = {"sentinel": ("audit-public-web", sentinel_worker)}


def free_gb():
    return shutil.disk_usage(DEPT).free / 1e9


def recover(db):
    """Interrupted runs / tasks stuck in a working state go back to a safe state, counted as an attempt."""
    cutoff = (datetime.datetime.now().astimezone() - datetime.timedelta(minutes=STALE_MINUTES)).isoformat(timespec="seconds")
    n = 0
    for r in db.execute("SELECT * FROM runs WHERE status='running' AND started_at < ?", (cutoff,)).fetchall():
        db.execute("UPDATE runs SET status='interrupted', ended_at=? WHERE id=?", (now(), r["id"]))
    for t in db.execute("SELECT * FROM tasks WHERE state IN ('investigating','implementing') AND COALESCE(heartbeat_at, updated_at) < ?", (cutoff,)).fetchall():
        back = "discovered" if t["state"] == "investigating" else "approved"
        transition(db, t["id"], back, "system", "recovered after an interrupted session")
        db.execute("UPDATE tasks SET attempts=attempts+1 WHERE id=?", (t["id"],))
        n += 1
    db.commit()
    return n


def tick(db, max_runs=1):
    """Run up to max_runs runnable tasks. Returns a list of (task_id, outcome)."""
    why = halted(db)
    if why:
        return [(None, "halted: " + why)]
    if free_gb() < MIN_FREE_GB:
        return [(None, "halted: only %.1f GB free disk" % free_gb())]
    recover(db)
    done = []
    for _ in range(max_runs):
        picked = None
        for t in db.execute("SELECT * FROM tasks WHERE state='discovered' ORDER BY priority_score DESC, id").fetchall():
            if t["agent"] not in WORKERS or t["attempts"] >= t["max_attempts"]:
                continue
            if halted(db, t["agent"]):
                continue
            deps = json.loads(t["depends_on"])
            if any((db.execute("SELECT state FROM tasks WHERE id=?", (d,)).fetchone() or {"state": None})["state"] != "completed" for d in deps):
                continue
            picked = t
            break
        if not picked:
            break
        cat, fn = WORKERS[picked["agent"]]
        if not permitted(db, picked["agent"], cat, picked["risk"]):
            transition(db, picked["id"], "blocked", "ada", "no active permission for %s / %s (revoked or never granted)" % (picked["agent"], cat))
            done.append((picked["id"], "blocked: permission"))
            continue
        transition(db, picked["id"], "investigating", picked["agent"], "run started")
        db.execute("UPDATE tasks SET attempts=attempts+1 WHERE id=?", (picked["id"],))
        run = db.execute("INSERT INTO runs(task_id,agent,started_at,status) VALUES(?,?,?,'running')", (picked["id"], picked["agent"], now())).lastrowid
        db.commit()
        t0 = time.time()
        try:
            ok, note = fn(db.execute("SELECT * FROM tasks WHERE id=?", (picked["id"],)).fetchone(), db)
            code = 0 if ok else 1
        except subprocess.TimeoutExpired:
            ok, note, code = False, "timed out after the wall-clock limit", 124
        except Exception as e:  # a worker bug must never wedge the queue
            ok, note, code = False, "worker error: %s" % e, 1
        db.execute("UPDATE runs SET status=?, ended_at=?, exit_code=?, seconds=?, note=? WHERE id=?", ("ok" if ok else "failed", now(), code, round(time.time() - t0, 1), note, run))
        db.commit()
        if ok:
            transition(db, picked["id"], "completed", picked["agent"], note)
            done.append((picked["id"], "completed: " + note))
        else:
            attempts = db.execute("SELECT attempts, max_attempts FROM tasks WHERE id=?", (picked["id"],)).fetchone()
            needs_human = note.startswith("needs you")
            if needs_human or attempts["attempts"] >= attempts["max_attempts"]:
                transition(db, picked["id"], "blocked", "system", note)
                done.append((picked["id"], "blocked: " + note))
            else:
                transition(db, picked["id"], "discovered", "system", "failed, will retry once: " + note)
                done.append((picked["id"], "retry later: " + note))
                break  # never retry inside the same tick
        if halted(db):
            break
    return done


def cycle(db, today=None):
    """The once-a-day overnight cycle: queue the day's audits, run what is runnable, import, prioritise, brief."""
    today = today or datetime.date.today().isoformat()
    set_ctl(db, "cycle_started", now())
    seed_permissions(db)
    add_task(db, "sentinel:audit:daily:" + today, "Sentinel daily audit " + today, "audit", "sentinel", payload={"audit": "daily"})
    if datetime.date.fromisoformat(today).weekday() == 6:
        add_task(db, "sentinel:audit:weekly:" + today, "Sentinel weekly audit " + today, "audit", "sentinel", payload={"audit": "weekly"})
    results = tick(db, max_runs=MAX_RUNS_PER_CYCLE)
    imported = import_sentinel(db)
    moved = prioritise(db)
    path = write_briefing(db, today, results, imported, moved)
    set_ctl(db, "cycle_finished", now())
    return results, path


# --------------------------------------------------------------- briefing
def write_briefing(db, today, results=(), imported=0, moved=()):
    out = os.path.join(DEPT, "briefings")
    os.makedirs(out, exist_ok=True)
    q = lambda sql, *a: db.execute(sql, a).fetchall()
    crit = q("SELECT * FROM tasks WHERE category IN ('security','data-integrity','financial','reliability') AND state NOT IN ('completed','rejected') ORDER BY id")
    blocked = q("SELECT * FROM tasks WHERE state='blocked' ORDER BY id")
    waiting = [t for t in q("SELECT * FROM tasks WHERE state IN ('awaiting_approval','awaiting_release_approval') ORDER BY priority_score DESC") if t["category"] not in CRITICAL_CATEGORIES]
    done = q("SELECT * FROM tasks WHERE state IN ('completed','released','verified') AND updated_at >= ? AND kind!='audit' ORDER BY id", today)
    runs = q("SELECT * FROM runs WHERE started_at >= ?", today)
    L = ["# Morning briefing — %s" % today, ""]
    why = halted(db)
    L += ["## Executive summary",
          ("**The department is not running: %s.**" % why) if why else
          "%d run(s) last cycle, %d new proposal(s) imported, %d moved to your approval queue, %d blocked." % (len(runs), imported, len(moved), len(blocked)), ""]
    L += ["## Critical issues"] + (["- #%d %s (%s)" % (t["id"], t["title"], t["state"]) for t in crit] or ["_none_"]) + [""]
    L += ["## Decisions requiring your approval (max 3 ordinary)"]
    if not waiting:
        L.append("_nothing waiting_")
    for t in waiting[:3]:
        p = json.loads(t["payload"])
        eff = (p.get("effort") or {}).get("size", "?")
        L += ["### #%d %s" % (t["id"], t["title"]),
              "- Problem: %s" % p.get("problem", "—"), "- Evidence: %s" % "; ".join(e.get("text", "") for e in p.get("evidence", [])) ,
              "- Recommended: %s" % p.get("proposal", "—"), "- Benefit (expected, not measured): %s" % p.get("benefit", "—"),
              "- Effort %s · Risk %s" % (eff, t["risk"]), "- Ada's rationale: %s" % (t["rationale"] or "—"),
              "- Decide: `ada approve|reject|revise %d --reason \"…\"`" % t["id"], ""]
    if len(waiting) > 3:
        L.append("_%d more ordinary decision(s) held back to keep this to three._" % (len(waiting) - 3))
    L += ["", "## Completed improvements"] + (["- #%d %s [%s]" % (t["id"], t["title"], t["state"]) for t in done] or ["_none yet — implementation agents are not built_"]) + [""]
    L += ["## Blocked (needs a human or a fix)"] + (["- #%d %s — %s" % (t["id"], t["title"], t["blocked_reason"]) for t in blocked] or ["_none_"]) + [""]
    L += ["## Department activity"] + (["- %s run #%s %s in %ss — %s" % (r["agent"], r["id"], r["status"], r["seconds"], r["note"]) for r in runs] or ["_no runs_"]) + [""]
    cost = 0.0
    sen_home = os.environ.get("SENTINEL_HOME", os.path.join(HOME, ".mowology-sentinel"))
    rd = os.path.join(sen_home, "reports", today)
    if os.path.isdir(rd):
        for f in os.listdir(rd):
            if f.endswith(".raw.json"):
                try:
                    cost += float(json.load(open(os.path.join(rd, f))).get("total_cost_usd") or 0)
                except Exception:
                    pass
    L += ["## Resource consumption",
          "- Runs: %d, wall-clock %.0f s. Reported API-equivalent cost today: $%.2f (this is NOT your plan allowance)." % (len(runs), sum(r["seconds"] or 0 for r in runs), cost),
          "- Subscription usage: **not measurable** from here; only a \"hit your limit\" message is detectable (%s)." % (("hold until " + get_ctl(db, "limit_until")) if get_ctl(db, "limit_until") else "none seen"),
          "- Disk free: %.0f GB (runs stop below %d GB)." % (free_gb(), MIN_FREE_GB), ""]
    nxt = q("SELECT * FROM tasks WHERE state IN ('discovered','approved') ORDER BY id")
    L += ["## Next actions"] + (["- #%d %s (%s, waiting on: %s)" % (t["id"], t["title"], t["state"], "an implementation agent that is not built yet" if t["state"] == "approved" else "next cycle") for t in nxt] or ["_nothing queued_"]) + [""]
    path = os.path.join(out, today + ".md")
    open(path, "w").write("\n".join(L))
    return path


# --------------------------------------------------------------------- cli
def main(argv=None):
    ap = argparse.ArgumentParser(prog="ada")
    sub = ap.add_subparsers(dest="cmd", required=True)
    for c in ("status", "tasks", "cycle", "tick", "briefing", "pause", "resume", "stop", "unstop", "permissions"):
        p = sub.add_parser(c)
        if c in ("pause", "resume"):
            p.add_argument("agent", nargs="?")
        if c == "tasks":
            p.add_argument("--state")
    for c in ("approve", "reject", "revise", "unblock"):
        p = sub.add_parser(c); p.add_argument("id", type=int); p.add_argument("--reason", required=True)
    p = sub.add_parser("revoke"); p.add_argument("agent"); p.add_argument("category"); p.add_argument("--reason", required=True)
    a = ap.parse_args(argv)
    db = connect(); seed_permissions(db)
    try:
        if a.cmd == "status":
            why = halted(db)
            print("RUNNING" if not why else "HALTED: " + why)
            for s, c in db.execute("SELECT state, COUNT(*) FROM tasks GROUP BY state").fetchall():
                print("  %-26s %d" % (s, c))
        elif a.cmd == "tasks":
            for t in db.execute("SELECT * FROM tasks" + (" WHERE state=?" if a.state else "") + " ORDER BY id", (a.state,) if a.state else ()).fetchall():
                print("#%-4d %-24s %-9s %s%s" % (t["id"], t["state"], t["agent"], t["title"], (" — " + t["blocked_reason"]) if t["blocked_reason"] else ""))
        elif a.cmd == "cycle":
            res, path = cycle(db); [print(r) for r in res]; print("briefing:", path)
        elif a.cmd == "tick":
            [print(r) for r in tick(db)]
        elif a.cmd == "briefing":
            print(write_briefing(db, datetime.date.today().isoformat()))
        elif a.cmd == "pause":
            set_ctl(db, "paused:" + a.agent if a.agent else "paused", now()); print("paused", a.agent or "department")
        elif a.cmd == "resume":
            set_ctl(db, "paused:" + a.agent if a.agent else "paused", None); print("resumed", a.agent or "department")
        elif a.cmd == "stop":
            set_ctl(db, "stopped", now()); subprocess.run(["pkill", "-f", "run-audit.sh"], capture_output=True)
            for j in ("ada-cycle", "ada-briefing"):
                subprocess.run(["launchctl", "bootout", "gui/%d/ca.mowology.%s" % (os.getuid(), j)], capture_output=True)
            print("EMERGENCY STOP: runs killed, schedule unloaded. Resume with: ada unstop && scripts/dept/install.sh")
        elif a.cmd == "unstop":
            set_ctl(db, "stopped", None); print("stop cleared")
        elif a.cmd == "permissions":
            for r in db.execute("SELECT * FROM permissions").fetchall():
                print("%s / %s level %d max_risk %s since %s%s" % (r["agent"], r["category"], r["level"], r["max_risk"], r["authorised_at"], (" REVOKED " + r["revoked_at"]) if r["revoked_at"] else ""))
        elif a.cmd in ("approve", "reject", "revise"):
            human_decision(db, a.id, a.cmd, a.reason, sys.stdin.isatty()); print("recorded", a.cmd, a.id)
        elif a.cmd == "unblock":
            if not sys.stdin.isatty():
                raise Refused("needs an interactive terminal")
            unblock(db, a.id, "tim", a.reason); print("unblocked", a.id)
        elif a.cmd == "revoke":
            print("revoked", revoke(db, a.agent, a.category, a.reason))
    except Refused as e:
        print("ada:", e, file=sys.stderr)
        return 3
    return 0


if __name__ == "__main__":
    sys.exit(main())
