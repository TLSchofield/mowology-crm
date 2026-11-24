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
sys.path.insert(0, HERE)
import commercial as C  # noqa: E402
import agents as AG  # noqa: E402
import oracle as ORC  # noqa: E402

STATES = ["discovered", "investigating", "proposed", "awaiting_approval", "approved", "implementing", "awaiting_verification",
          "verification_failed", "verified", "awaiting_release_approval", "released", "measuring_outcomes", "completed",
          "blocked", "rejected"]
TERMINAL = {"completed", "rejected"}
AGENTS = {"sentinel", "artisan", "forge", "guardian", "mercury", "empath", "atlas", "oracle", "apollo", "hunter", "scribe", "beacon", "closer", "ambassador", "compass"}
COMMERCIAL = {"apollo", "mercury", "hunter", "scribe", "beacon", "closer", "ambassador", "compass"}


def dept_of(agent):
    return "apollo" if agent in COMMERCIAL else ("shared" if agent in ("atlas", "oracle") else "ada")

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
    ("awaiting_approval", "proposed"): {"tim", "ada"},     # revision request, or Ada re-ranking a lower-scored item out of the three slots
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
    CREATE TABLE IF NOT EXISTS findings(
      key TEXT PRIMARY KEY, severity TEXT NOT NULL, title TEXT NOT NULL, evidence TEXT, area TEXT, fix_hint TEXT,
      first_seen TEXT, last_seen TEXT, status TEXT NOT NULL DEFAULT 'open', task_id INTEGER, resolved_at TEXT);
    CREATE TABLE IF NOT EXISTS disagreements(
      id INTEGER PRIMARY KEY AUTOINCREMENT, task_id INTEGER, parties TEXT, issue TEXT, resolution TEXT, at TEXT);
    """)
    for col, ddl in (("department", "TEXT NOT NULL DEFAULT 'ada'"), ("result", "TEXT")):
        if col not in [r["name"] for r in db.execute("PRAGMA table_info(tasks)").fetchall()]:
            db.execute("ALTER TABLE tasks ADD COLUMN %s %s" % (col, ddl))
    C.ensure_schema(db)
    ORC.ensure_schema(db)
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
PERMS = [  # agent, category, level, tools-note, environments, limits-note
    ("sentinel", "audit-public-web", 1, "see scripts/sentinel/registry.json", "public websites in targets.json; local simulators", "reports only"),
    ("mercury", "audit-public-web", 1, "read-only web + Playwright", "public websites", "findings recorded as observations"),
    ("compass", "research-public-web", 1, "WebSearch/WebFetch read-only", "public web", "no contact with anyone"),
    ("hunter", "research-public-web", 1, "WebSearch/WebFetch read-only", "public business pages only; no restricted platforms", "never contacts prospects"),
    ("scribe", "draft-commercial", 3, "no tools", "drafts inside the pipeline", "verified claims only; drafts are never sent"),
    ("apollo", "evaluate-opportunities", 2, "no tools", "pipeline records", "recommend; the owner approves"),
    ("closer", "prepare-sales-plan", 3, "no tools", "pipeline records", "verified capabilities only"),
    ("oracle", "research-public-web", 2, "WebSearch/WebFetch read-only", "public web, primary sources; synthetic data and isolated worktrees for any experiment design", "recommend only; experiments are designed, never executed; never deploys, buys or contacts anyone"),
    ("atlas", "capture-lessons", 2, "deterministic script", "pipeline history", "lessons stay draft until verified"),
    ("forge", "build-in-worktree", 3, "scripts/codex-web-build.sh (Codex, redacted copy) + isolated git worktree; no merge, no deploy without the owner", "build worktrees under ~/.mowology-dept/builds; public site and app/ code only", "max 8 files / 400 lines; money, auth, tenant, migrations, secrets refused"),
    ("guardian", "verify-readonly", 1, "deterministic scripts + headless Chrome, read-only", "public websites; code snapshot of origin/feature/language", "no model, no writes, no customer data"),
]


def seed_permissions(db):
    for agent, cat, lvl, tools, env, lim in PERMS:
        if not db.execute("SELECT 1 FROM permissions WHERE agent=? AND category=?", (agent, cat)).fetchone():
            db.execute("INSERT INTO permissions(agent,category,level,tools,repos,environments,max_risk,approval,limits,authorised_at,authorised_by) VALUES(?,?,?,?,?,?,?,?,?,?,?)",
                       (agent, cat, lvl, json.dumps(tools), json.dumps(["none: no repository access"] if agent in COMMERCIAL else ["code snapshot, read-only"]), json.dumps(env), "low", "owner approves anything external", json.dumps(lim), now(), "tim"))
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
    entry = roster().get(agent)
    if entry and entry.get("status") == "planned" and actor != "tim":
        raise Refused("%s is planned, not built. Build trigger: %s. Build it with: python3 scripts/dept/scaffold.py %s" % (agent, entry.get("build_trigger", "not defined"), agent))
    t = now()
    cur = db.execute("INSERT INTO tasks(dedupe_key,title,kind,agent,department,state,risk,category,priority_score,rationale,depends_on,payload,source_ref,max_attempts,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
                     (dedupe_key, title, kind, agent, dept_of(agent), state, risk, category, score, rationale, json.dumps(list(depends_on)), json.dumps(payload or {}), source_ref, MAX_ATTEMPTS, t, t))
    db.execute("INSERT INTO transitions(task_id,from_state,to_state,actor,reason,at) VALUES(?,?,?,?,?,?)", (cur.lastrowid, None, state, actor, "created", t))
    db.commit()
    return cur.lastrowid, True


def roster():
    try:
        return json.load(open(os.path.join(HERE, "roster.json")))["agents"]
    except (OSError, ValueError, KeyError):
        return {}


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
    if (frm, to_state) == ("investigating", "completed") and t["kind"] not in ("audit", "commercial", "research"):
        raise Refused("only audit and commercial work tasks may complete straight from investigating")
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
    back = {"investigating": "discovered", "implementing": "approved"}.get(t["blocked_from"], t["blocked_from"])   # working states restart cleanly
    back = back if back in RESUME_STATES else "discovered"
    ts = now()
    db.execute("UPDATE tasks SET state=?, blocked_reason=NULL, attempts=0, updated_at=? WHERE id=?", (back, ts, tid))
    db.execute("INSERT INTO transitions(task_id,from_state,to_state,actor,reason,at) VALUES(?,?,?,?,?,?)", (tid, "blocked", back, actor, reason, ts))
    db.commit()


def human_decision(db, tid, decision, reason, tty, code=None):
    """approve | reject | revise — the only way work becomes Approved. Needs a real terminal (agents have none)."""
    if not tty:
        raise Refused("approvals need an interactive terminal; an agent or script cannot approve")
    if not reason.strip():
        raise Refused("a reason is required — the department learns from it")
    to = {"approve": "approved", "reject": "rejected", "revise": "proposed"}[decision]
    transition(db, tid, to, "tim", ("[%s] " % code if code else "") + reason)
    if decision == "revise":      # park it: prioritise() skips tasks waiting for a revision so it does not bounce straight back
        p = json.loads(get_task(db, tid)["payload"] or "{}"); p["revision_requested"] = reason
        db.execute("UPDATE tasks SET payload=? WHERE id=?", (json.dumps(p), tid)); db.commit()
    t = get_task(db, tid)
    sen = os.path.join(HERE, "..", "sentinel", "sentinel.py")
    if t["source_ref"] and t["agent"] == "sentinel" and os.path.exists(sen):  # one shared decision history
        verb = {"approve": "accept", "reject": "reject", "revise": "modify"}[decision]
        subprocess.run([sys.executable, sen, "decide", t["source_ref"], verb, reason] + (["--code", code] if code else []), env=dict(os.environ), capture_output=True)
    if decision == "approve" and t["kind"] == "improvement":      # accepting IS the instruction to build, test, review, deploy and confirm (owner, 2026-11-24)
        pl = json.loads(get_task(db, tid)["payload"] or "{}"); pl["ship_on_accept"] = True
        db.execute("UPDATE tasks SET payload=? WHERE id=?", (json.dumps(pl), tid)); db.commit()
        try:
            import forge
            forge.start_in_background(tid)
        except Exception as e:  # noqa: BLE001
            print("could not start the build: %s" % e, file=sys.stderr)


# ------------------------------------------------------------- prioritising
def prioritise(db):
    """Critical work (security, data, financial, reliability) goes to the owner at once, unlimited. Ordinary work competes for THREE slots by score,
    whenever it arrived: a better proposal displaces a weaker one back to Proposed (the owner is never shown the weaker item just because it came first)."""
    moved = []
    crit = "category IN ('security','data-integrity','financial','reliability')"
    for r in db.execute("SELECT id, rationale FROM tasks WHERE state='proposed' AND payload NOT LIKE '%%\"revision_requested\"%%' AND %s ORDER BY priority_score DESC, id" % crit).fetchall():
        transition(db, r["id"], "awaiting_approval", "ada", r["rationale"] or "prioritised"); moved.append(r["id"])
    pool = db.execute("SELECT * FROM tasks WHERE state IN ('proposed','awaiting_approval') AND payload NOT LIKE '%%\"revision_requested\"%%' AND NOT %s ORDER BY priority_score DESC, id" % crit).fetchall()
    for i, r in enumerate(pool):
        if i < 3 and r["state"] == "proposed":
            transition(db, r["id"], "awaiting_approval", "ada", r["rationale"] or "prioritised"); moved.append(r["id"])
        elif i >= 3 and r["state"] == "awaiting_approval":
            transition(db, r["id"], "proposed", "ada", "outranked by higher-scored work; kept in the backlog")
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
    done_today = os.path.join(os.environ.get("SENTINEL_HOME", os.path.join(HOME, ".mowology-sentinel")), "reports", datetime.date.today().isoformat(), kind + ".json")
    if os.path.exists(done_today) and not os.environ.get("ADA_SENTINEL_RUNNER"):
        return True, "already ran today (%s report exists); not repeated" % kind
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


SEV_SCORE = {"P0": 100, "P1": 50, "P2": 10, "P3": 2}
SEV_CATEGORY = {"P0": "security", "P1": "reliability", "P2": "ordinary", "P3": "ordinary"}


def guardian_worker(task, db):
    """Guardian's nightly deterministic verification: rules ratchet + rendered public pages. Persists findings, queues new ones, closes resolved ones."""
    gdir = os.environ.get("GUARDIAN_DIR") or next(p for p in (os.path.join(HERE, "..", "guardian"), os.path.join(HERE, "..", "guardian-tools")) if os.path.exists(p))
    sys.path.insert(0, gdir)
    import guardian as G
    day = datetime.date.today().isoformat()
    run_dir = os.path.join(G.HOME, "runs", day)
    root = None
    repo = os.environ.get("SENTINEL_REPO", os.path.join(HOME, "Projects", "mowology-crm"))
    tmp = None
    if os.path.isdir(os.path.join(repo, ".git")) and not os.environ.get("GUARDIAN_STANDIN_METRICS"):
        import tempfile
        tmp = tempfile.mkdtemp(prefix="guardian-src-")
        subprocess.run("git -C '%s' archive origin/feature/language -- public app database ios/MowologyCRM 2>/dev/null | tar -x -C '%s'" % (repo, tmp), shell=True)
        root = tmp
    try:
        metrics, rules_out, notes = G.collect(run_dir, root)
    finally:
        if tmp:
            shutil.rmtree(tmp, ignore_errors=True)
    if metrics is None and not rules_out and notes:
        return False, "; ".join(notes)
    found = G.findings_from(metrics or {}, rules_out, G.latest_previous())
    t = now()
    seen = set()
    new = 0
    for f in found:
        seen.add(f["key"])
        row = db.execute("SELECT * FROM findings WHERE key=?", (f["key"],)).fetchone()
        ev = json.dumps(f["evidence"])
        if row:
            db.execute("UPDATE findings SET severity=?, title=?, evidence=?, last_seen=?, status='open', resolved_at=NULL WHERE key=?", (f["severity"], f["title"], ev, t, f["key"]))
            continue
        tid, _ = add_task(db, "guardian:" + f["key"], f["title"], "improvement", "guardian", state="proposed", risk="low", category=SEV_CATEGORY[f["severity"]],
                          payload={"problem": f["title"], "evidence": [{"text": e} for e in f["evidence"]], "proposal": f.get("fix_hint") or "", "benefit": "Measured defect on the rendered product", "effort": {"size": "?"}, "severity": f["severity"], "area": f["area"]},
                          source_ref=f["key"], score=SEV_SCORE[f["severity"]], rationale="Guardian measurement, severity %s (rule-based, not an estimate): %s" % (f["severity"], "; ".join(f["evidence"])[:200]), actor="guardian")
        db.execute("INSERT INTO findings(key,severity,title,evidence,area,fix_hint,first_seen,last_seen,status,task_id) VALUES(?,?,?,?,?,?,?,?,?,?)", (f["key"], f["severity"], f["title"], ev, f["area"], f.get("fix_hint"), t, t, "open", tid))
        new += 1
    resolved = 0
    for row in db.execute("SELECT * FROM findings WHERE status='open'").fetchall():
        if row["key"] not in seen and metrics is not None:                 # only close when the page audit actually ran
            db.execute("UPDATE findings SET status='resolved', resolved_at=? WHERE key=?", (t, row["key"]))
            tk = db.execute("SELECT id, state FROM tasks WHERE id=?", (row["task_id"],)).fetchone()
            if tk and tk["state"] == "proposed":
                transition(db, tk["id"], "rejected", "ada", "resolved: no longer observed by Guardian")
            resolved += 1
    db.commit()
    if metrics is not None:
        G.record_history(metrics)
        os.makedirs(G.HOME, exist_ok=True)
        json.dump({"at": t, "findings": found, "run_dir": run_dir, "pages": G.page_summary(metrics)}, open(os.path.join(G.HOME, "latest.json"), "w"), indent=1)
    sev = {s: sum(1 for f in found if f["severity"] == s) for s in ("P0", "P1", "P2", "P3")}
    return True, "%d open finding(s) %s, %d new, %d resolved%s" % (len(found), sev, new, resolved, ("; " + "; ".join(notes)) if notes else "")


WORKERS = {"sentinel": ("audit-public-web", sentinel_worker), "guardian": ("verify-readonly", guardian_worker)}
for _a, (_cat) in (("mercury", "audit-public-web"), ("compass", "research-public-web"), ("hunter", "research-public-web"), ("scribe", "draft-commercial"),
                   ("apollo", "evaluate-opportunities"), ("closer", "prepare-sales-plan"), ("atlas", "capture-lessons"), ("oracle", "research-public-web")):
    WORKERS[_a] = (_cat, AG.worker)
for _a in ("beacon", "ambassador"):
    WORKERS[_a] = ("dormant", AG.worker)


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


def tick(db, max_runs=1, department=None, only=None, exclude=()):
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
            if department and t["department"] not in (department, "shared"):
                continue
            if (only and t["agent"] not in only) or t["agent"] in exclude:
                continue
            if t["agent"] in ("beacon", "ambassador"):
                continue                                   # dormant until the owner activates them
            opp = AG.dependency_results(db, t).get("opp")
            if opp and C.is_suppressed_opp(db, C.get_opp(db, opp)):
                transition(db, t["id"], "rejected", "ada", "opted out: this prospect is suppressed department-wide")
                done.append((t["id"], "rejected: opted out"))
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


RUNS_PER_DEPARTMENT = 2   # 50/50 split of the cycle's run budget between product (ada) and commercial (apollo)


def signin_blocked(db):
    r = db.execute("SELECT 1 FROM tasks WHERE agent='sentinel' AND state='blocked' AND blocked_reason LIKE '%not signed in%' ORDER BY id DESC LIMIT 1").fetchone()
    return bool(r) and not AG.cli_ready()


def refresh_dashboard(db):
    """Regenerate the local dashboard; it must never break a cycle or a decision."""
    try:
        import dashboard
        import types
        return dashboard.write(types.SimpleNamespace(**globals()), db)
    except Exception as e:  # noqa: BLE001
        print("dashboard not refreshed: %s" % e, file=sys.stderr)


def cycle(db, today=None):
    """The once-a-day overnight cycle: queue the day's work, run what is runnable (equal run budget per department),
    import proposals, prioritise, write ONE briefing."""
    today = today or datetime.date.today().isoformat()
    d = datetime.date.fromisoformat(today)
    set_ctl(db, "cycle_started", now())
    seed_permissions(db)
    add_task(db, "guardian:verify:" + today, "Guardian nightly verification " + today, "audit", "guardian", payload={"audit": "nightly"})   # deterministic: needs no sign-in
    if AG.cli_ready():
        for t in db.execute("SELECT id FROM tasks WHERE state='blocked' AND blocked_reason LIKE '%not signed in%'").fetchall():
            unblock(db, t["id"], "ada", "the Claude CLI is signed in now")
    if signin_blocked(db):
        set_ctl(db, "cli_note", "Claude CLI is not signed in: no model-driven work was queued (sign in once; the cycle resumes by itself)")
    else:
        set_ctl(db, "cli_note", None)
        add_task(db, "sentinel:audit:daily:" + today, "Sentinel daily audit " + today, "audit", "sentinel", payload={"audit": "daily"})
        if d.weekday() == 6:
            add_task(db, "sentinel:audit:weekly:" + today, "Sentinel weekly audit " + today, "audit", "sentinel", payload={"audit": "weekly"})
        if get_ctl(db, "oracle_on") and AG.cli_ready() and d.weekday() == 2:
            add_task(db, "oracle:weekly:" + today, "Oracle weekly research " + today, "research", "oracle", payload={"input": {"focus": "weekly radar review: entries due for review, then at most 3 new findings; then the current-service review below", "service_focus": ORC.next_service(db)}})
        if get_ctl(db, "commercial_on") and AG.cli_ready():
            if d.weekday() == 0:
                add_task(db, "compass:market:" + today, "Compass weekly market review " + today, "commercial", "compass", payload={"input": {"focus": "see memory/compass/brief.md; evidence with sources only"}})
            if d.weekday() in (1, 3):
                add_task(db, "mercury:review:" + today, "Mercury acquisition review " + today, "commercial", "mercury", payload={"input": {"targets": "public pages in scripts/sentinel/targets.json"}})
    # Guardian and Atlas are deterministic (no model): they do not use the model-run budget that is split between the departments
    try:
        import forge
        sweep_note = forge.sweep(__import__("types").SimpleNamespace(**globals()), db)
    except Exception as e:  # noqa: BLE001
        sweep_note = "forge sweep failed: %s" % e
    results = tick(db, max_runs=2, only=("guardian",)) + tick(db, max_runs=RUNS_PER_DEPARTMENT, department="ada", exclude=("guardian",)) + tick(db, max_runs=RUNS_PER_DEPARTMENT, department="apollo", exclude=("guardian",))
    imported = import_sentinel(db)
    moved = prioritise(db)
    path = write_briefing(db, today, results, imported, moved)
    set_ctl(db, "cycle_finished", now())
    refresh_dashboard(db)
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
    L += guardian_section(db)
    L += commercial_section(db)
    note = get_ctl(db, "cli_note")
    if note:
        L += ["", "> **Not running models:** " + note]
    path = os.path.join(out, today + ".md")
    open(path, "w").write("\n".join(L))
    return path


def guardian_section(db):
    """What Guardian MEASURED (no model): open findings by severity, what is new, what was fixed."""
    L = ["", "## Guardian (measured, rule-based)", ""]
    rows = db.execute("SELECT severity, COUNT(*) c FROM findings WHERE status='open' GROUP BY severity ORDER BY severity").fetchall()
    if not db.execute("SELECT 1 FROM findings LIMIT 1").fetchone() and not db.execute("SELECT 1 FROM tasks WHERE agent='guardian' AND state='completed'").fetchone():
        return L + ["_Guardian has not completed a run yet._"]
    L.append("- Open findings: " + (", ".join("%s %d" % (r["severity"], r["c"]) for r in rows) or "none"))
    today = datetime.date.today().isoformat()
    for r in db.execute("SELECT severity, title FROM findings WHERE status='open' AND first_seen >= ? ORDER BY severity LIMIT 5", (today,)).fetchall():
        L.append("- NEW %s: %s" % (r["severity"], r["title"]))
    fixed = db.execute("SELECT COUNT(*) c FROM findings WHERE status='resolved' AND resolved_at >= ?", (today,)).fetchone()["c"]
    if fixed:
        L.append("- Resolved since last run: %d" % fixed)
    L.append("- Rules ratchet (project rules from CLAUDE.md) also runs on every merge; page measurements: ~/.mowology-guardian/latest.json")
    return L


def commercial_section(db):
    """Apollo's half of the one briefing. Counts and facts from the pipeline; nothing estimated."""
    L = ["", "# Commercial position (Apollo)", ""]
    real = db.execute("SELECT stage, COUNT(*) c FROM opportunities WHERE synthetic=0 GROUP BY stage").fetchall()
    synth = db.execute("SELECT COUNT(*) c FROM opportunities WHERE synthetic=1").fetchone()["c"]
    L.append("- Pipeline (real prospects): " + (", ".join("%s %d" % (r["stage"], r["c"]) for r in real) or "**empty — no real prospect has been researched yet**") + ("; %d synthetic demo row(s) excluded" % synth if synth else ""))
    L.append("- Paying customers: **none** (no billing data exists yet); recurring revenue: **not available**.")
    cap = {r["status"]: r["c"] for r in db.execute("SELECT status, COUNT(*) c FROM capabilities GROUP BY status").fetchall()}
    L.append("- Product claims: %d verified, %d with evidence found (awaiting your or Guardian's verification), %d unverified. **Scribe can use only verified ones.**" % (cap.get("verified", 0), cap.get("evidence_found", 0), cap.get("unverified", 0)))
    sup = db.execute("SELECT COUNT(*) c FROM suppressions").fetchone()["c"]
    L.append("- Suppression list: %d entr%s (applies to every agent and campaign)." % (sup, "y" if sup == 1 else "ies"))
    fc = C.forecast(db)
    L.append("- Forecast buckets (counts / known value): " + "; ".join("%s %d / $%s" % (b, fc[b]["count"], fc[b]["known_value"]) for b in C.BUCKETS) + ". Confirmed revenue: unavailable. No probability-weighted figure (no conversion history).")
    L += ["", "## Commercial decisions for you (max 3)"]
    dec = []
    for r in db.execute("SELECT o.uid, o.business_name, e.basis, e.evidence FROM eligibility e JOIN opportunities o ON o.id=e.opp_id WHERE e.status='proposed' AND o.stage NOT IN ('opted_out','disqualified')").fetchall():
        dec.append("Confirm contact eligibility for %s (%s): proposed basis **%s** — %s. Recommend: review the evidence; this is a legal call and is yours. `ada confirm-eligibility %s --note \"…\"`" % (r["uid"], r["business_name"], r["basis"], r["evidence"], r["uid"]))
    for r in db.execute("SELECT o.uid, o.business_name, c.id cid FROM communications c JOIN opportunities o ON o.id=c.opp_id JOIN eligibility e ON e.opp_id=o.id WHERE c.status='draft' AND e.status='confirmed' AND e.outreach_approved_by IS NULL").fetchall():
        dec.append("Approve outreach draft #%d to %s. `ada approve-outreach %s` — nothing is sent by the system; you send it and record it." % (r["cid"], r["business_name"], r["uid"]))
    L += ["- " + d for d in dec[:3]] or ["_none_"]
    if len(dec) > 3:
        L.append("_%d more held back._" % (len(dec) - 3))
    L += ["", "## Product intelligence for Ada"]
    reqs = db.execute("SELECT problem, observations FROM product_requests ORDER BY observations DESC LIMIT 3").fetchall()
    L += ["- %s (seen %d time(s))" % (r["problem"], r["observations"]) for r in reqs] or ["_no commercially requested features recorded yet_"]
    cf = C.conflicts(db)
    if cf:
        L += ["", "## Knowledge conflicts needing a decision"] + ["- draft #%d disagrees with authoritative #%d on %s" % (d, a, t) for a, d, t in cf]
    L += ["", "_Dormant: Beacon (until you approve a content programme) and Ambassador (until the first paying customer). Compass/Mercury run only after `ada commercial on`._"]
    return L


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
    for c in ("confirm-eligibility",):
        p = sub.add_parser(c); p.add_argument("opp"); p.add_argument("--note", required=True)
    p = sub.add_parser("approve-outreach"); p.add_argument("opp")
    p = sub.add_parser("mark-sent"); p.add_argument("comm", type=int)
    p = sub.add_parser("suppress"); p.add_argument("--email"); p.add_argument("--domain"); p.add_argument("--phone"); p.add_argument("--name"); p.add_argument("--reason", required=True)
    p = sub.add_parser("verify-capability"); p.add_argument("name"); p.add_argument("--note", default="")
    p = sub.add_parser("activate"); p.add_argument("agent", choices=["beacon", "ambassador"])
    p = sub.add_parser("commercial"); p.add_argument("onoff", choices=["on", "off"])
    p = sub.add_parser("forge-build"); p.add_argument("id", type=int)
    p = sub.add_parser("release"); p.add_argument("id", type=int)
    p = sub.add_parser("oracle"); p.add_argument("onoff", choices=["on", "off"])
    p = sub.add_parser("approve-experiment"); p.add_argument("id", type=int)
    sub.add_parser("radar"); sub.add_parser("research"); sub.add_parser("oracle-briefing")
    sub.add_parser("pipeline"); sub.add_parser("capabilities"); sub.add_parser("demo-commercial"); sub.add_parser("roster")
    p = sub.add_parser("dashboard"); p.add_argument("--open", action="store_true"); p.add_argument("--serve", action="store_true")
    p = sub.add_parser("revoke"); p.add_argument("agent"); p.add_argument("category"); p.add_argument("--reason", required=True)
    a = ap.parse_args(argv)
    db = connect(); seed_permissions(db)
    if not db.execute("SELECT 1 FROM capabilities LIMIT 1").fetchone():
        C.seed_capabilities(db, os.environ.get("ADA_REPO_ROOT", os.path.join(HOME, "Projects", "mowology-crm")))
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
            print(write_briefing(db, datetime.date.today().isoformat())); refresh_dashboard(db)
        elif a.cmd == "dashboard":
            if a.serve:
                import types, dashboard, dashboard_server
                dashboard_server.run(types.SimpleNamespace(**globals()), dashboard)
                return 0
            path = refresh_dashboard(db); print(path)
            if a.open and path:
                subprocess.run(["open", path])
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
            human_decision(db, a.id, a.cmd, a.reason, sys.stdin.isatty()); print("recorded", a.cmd, a.id); refresh_dashboard(db)
        elif a.cmd == "unblock":
            if not sys.stdin.isatty():
                raise Refused("needs an interactive terminal")
            unblock(db, a.id, "tim", a.reason); print("unblocked", a.id)
        elif a.cmd == "confirm-eligibility":
            C.confirm_eligibility(db, a.opp, "tim", a.note, sys.stdin.isatty()); print("confirmed; the opportunity can now move to contact_eligibility_verified")
        elif a.cmd == "approve-outreach":
            C.approve_outreach(db, a.opp, "tim", sys.stdin.isatty()); print("outreach approved for", a.opp, "- nothing was sent")
        elif a.cmd == "mark-sent":
            C.record_sent(db, a.comm, "tim", sys.stdin.isatty()); print("recorded as sent by you")
        elif a.cmd == "suppress":
            hit = C.suppress(db, a.reason, a.email, a.domain, a.phone, a.name); print("suppressed department-wide; opportunities opted out:", hit or "none")
        elif a.cmd == "verify-capability":
            C.verify_capability(db, a.name, "tim", a.note, sys.stdin.isatty()); print("verified:", a.name)
        elif a.cmd == "activate":
            set_ctl(db, a.agent + "_active", now()); print(a.agent, "activated")
        elif a.cmd == "commercial":
            set_ctl(db, "commercial_on", now() if a.onoff == "on" else None); print("commercial research", a.onoff)
        elif a.cmd == "demo-commercial":
            import tempfile, importlib
            os.execve(sys.executable, [sys.executable, os.path.join(HERE, "demo.py"), "--cli"], dict(os.environ, DEPT_HOME=os.path.join(HOME, ".mowology-dept-demo")))
        elif a.cmd == "forge-build":
            import types, forge
            print(forge.build(types.SimpleNamespace(**globals()), db, a.id)); refresh_dashboard(db)
        elif a.cmd == "release":
            import types, forge
            for line in forge.release(types.SimpleNamespace(**globals()), db, a.id, sys.stdin.isatty()):
                print(line)
            refresh_dashboard(db)
        elif a.cmd == "oracle":
            set_ctl(db, "oracle_on", now() if a.onoff == "on" else None); print("Oracle weekly research", a.onoff)
        elif a.cmd == "approve-experiment":
            ORC.approve_experiment(db, a.id, "tim", sys.stdin.isatty()); print("experiment approved (it is designed, not run: execution is not enabled)")
        elif a.cmd == "radar":
            for r in db.execute("SELECT ring, tech, evidence_rank, next_review FROM radar ORDER BY CASE ring WHEN 'adopt' THEN 1 WHEN 'trial' THEN 2 WHEN 'assess' THEN 3 WHEN 'watch' THEN 4 ELSE 5 END, tech").fetchall():
                print("%-7s %-40s evidence %d/3  review %s" % (r["ring"], r["tech"], r["evidence_rank"], r["next_review"]))
        elif a.cmd == "research":
            for r in db.execute("SELECT id, horizon, state, target, title FROM research_opps ORDER BY id").fetchall():
                print("#%-3d H%d %-26s -> %-6s %s" % (r["id"], r["horizon"], r["state"], r["target"], r["title"]))
        elif a.cmd == "oracle-briefing":
            text = ORC.weekly_briefing(db); out = os.path.join(DEPT, "briefings"); os.makedirs(out, exist_ok=True)
            path = os.path.join(out, "oracle-%s.md" % datetime.date.today().isoformat()); open(path, "w").write(text); print(path)
        elif a.cmd == "roster":
            for n, r in roster().items():
                print("%-14s %-10s %-18s %s%s" % (n, r["department"], r["status"], r["role"], ("\n%14s build when: %s" % ("", r["build_trigger"])) if r["status"] == "planned" and r.get("build_trigger") else ""))
        elif a.cmd == "pipeline":
            for o in db.execute("SELECT uid,stage,business_name,synthetic,next_action FROM opportunities ORDER BY id").fetchall():
                print("%s %-30s %s%s  next: %s" % (o["uid"], o["stage"], o["business_name"], " [synthetic]" if o["synthetic"] else "", o["next_action"] or "-"))
        elif a.cmd == "capabilities":
            for r in db.execute("SELECT name,status,limitations FROM capabilities ORDER BY id").fetchall():
                print("%-14s %s%s" % (r["status"], r["name"], (" — " + r["limitations"]) if r["limitations"] else ""))
        elif a.cmd == "revoke":
            print("revoked", revoke(db, a.agent, a.category, a.reason))
    except (Refused, C.Refused, ORC.Refused) as e:
        print("ada:", e, file=sys.stderr)
        return 3
    return 0


if __name__ == "__main__":
    sys.exit(main())
