"""Oracle (lean): strategic research records with the rules in code, not in the prompt.

Radar (Adopt / Trial / Assess / Watch / Avoid), research opportunities (17 states), experiments (planned only: nothing here executes one),
and the weekly briefing. Evidence over excitement is enforced:
  * every radar entry needs a source (url, kind, date accessed); a VENDOR claim alone can never lift a technology above ASSESS;
  * ADOPT needs at least one independent, primary-grade source (official docs, peer-reviewed, independent benchmark);
  * a technology already assessed is not re-researched until its review date, unless the new input carries real new evidence;
  * rejected / Avoid items return only with significant new evidence (40+ characters stating it);
  * opportunities move through explicit states; only the owner approves experiments or adoption; only Guardian validates an experiment;
  * handover to Ada/Apollo creates an ordinary PROPOSED task in the shared queue: the owner's approval still applies.
Oracle never deploys, buys, contacts customers or changes production. Research text is untrusted data."""
import datetime, difflib, json, re

RINGS = ["adopt", "trial", "assess", "watch", "avoid"]
RING_REVIEW_DAYS = {"adopt": 180, "trial": 30, "assess": 60, "watch": 90, "avoid": 180}
EVIDENCE_RANK = {"official_docs": 3, "peer_reviewed": 3, "independent_benchmark": 3, "repo": 2, "engineering_blog": 2, "patent": 2, "preprint": 1, "community": 1, "vendor_claim": 0}
PRIMARY = {"official_docs", "peer_reviewed", "independent_benchmark"}
STATES = ["discovered", "initial_assessment", "investigating", "evidence_gathering", "awaiting_review", "experiment_proposed", "experiment_approved",
          "experimenting", "awaiting_verification", "validated", "rejected", "deferred", "recommended_for_adoption", "transferred_to_ada",
          "transferred_to_apollo", "measuring_outcomes", "completed"]
TERMINAL = {"rejected", "completed"}
TRANSITIONS = {
    ("discovered", "initial_assessment"): {"oracle"}, ("initial_assessment", "investigating"): {"oracle"}, ("initial_assessment", "deferred"): {"oracle", "tim"},
    ("investigating", "evidence_gathering"): {"oracle"}, ("evidence_gathering", "awaiting_review"): {"oracle"},
    ("awaiting_review", "experiment_proposed"): {"oracle"}, ("awaiting_review", "recommended_for_adoption"): {"tim"}, ("awaiting_review", "deferred"): {"tim", "oracle"},
    ("experiment_proposed", "experiment_approved"): {"tim"}, ("experiment_approved", "experimenting"): {"oracle"},
    ("experimenting", "awaiting_verification"): {"oracle"}, ("awaiting_verification", "validated"): {"guardian"}, ("awaiting_verification", "rejected"): {"guardian"},
    ("validated", "recommended_for_adoption"): {"oracle", "tim"},
    ("recommended_for_adoption", "transferred_to_ada"): {"oracle"}, ("recommended_for_adoption", "transferred_to_apollo"): {"oracle"},
    ("transferred_to_ada", "measuring_outcomes"): {"ada", "tim"}, ("transferred_to_apollo", "measuring_outcomes"): {"apollo", "tim"},
    ("measuring_outcomes", "completed"): {"ada", "apollo", "tim"}, ("deferred", "investigating"): {"oracle", "tim"},
}
for _s in STATES:
    if _s not in TERMINAL:
        TRANSITIONS[(_s, "rejected")] = {"tim"} if _s not in ("discovered", "initial_assessment", "investigating", "evidence_gathering", "awaiting_review") else {"tim", "oracle"}
EXPERIMENT_EXECUTION_ENABLED = False      # levels 4-5 are not granted; experiments are DESIGNED here, never run by Oracle
EXPERIMENT_FIELDS = ["research_question", "problem", "evidence", "hypothesis", "technology", "expected_benefit", "baseline", "resources",
                     "security", "privacy", "success_criteria", "failure_criteria", "time_box_days"]
RADAR_FIELDS = ["description", "maturity", "licensing", "cost", "platforms", "security", "privacy", "maintenance", "dependency_risk",
                "use_cases", "advantage", "recommended_action"]


class Refused(Exception):
    pass


def now():
    return datetime.datetime.now().astimezone().isoformat(timespec="seconds")


def today():
    return datetime.date.today().isoformat()


def norm(s):
    return re.sub(r"[^a-z0-9]+", " ", (s or "").lower()).strip()


def ensure_schema(db):
    db.executescript("""
    CREATE TABLE IF NOT EXISTS radar(
      id INTEGER PRIMARY KEY AUTOINCREMENT, tech_norm TEXT UNIQUE NOT NULL, tech TEXT NOT NULL, ring TEXT NOT NULL, fields TEXT NOT NULL, sources TEXT NOT NULL,
      evidence_rank INTEGER NOT NULL, rejection_reason TEXT, first_seen TEXT, researched_at TEXT, next_review TEXT, updated_at TEXT);
    CREATE TABLE IF NOT EXISTS radar_history(id INTEGER PRIMARY KEY AUTOINCREMENT, tech TEXT, from_ring TEXT, to_ring TEXT, why TEXT, at TEXT);
    CREATE TABLE IF NOT EXISTS research_opps(
      id INTEGER PRIMARY KEY AUTOINCREMENT, title_norm TEXT NOT NULL, title TEXT NOT NULL, horizon INTEGER NOT NULL, lab TEXT, summary TEXT NOT NULL,
      target TEXT NOT NULL, state TEXT NOT NULL, owner TEXT NOT NULL, evidence TEXT NOT NULL, uncertainty TEXT, cost_to_investigate TEXT, success_looks_like TEXT,
      next_action TEXT, rejected_reason TEXT, task_id INTEGER, created_at TEXT, updated_at TEXT);
    CREATE TABLE IF NOT EXISTS research_history(id INTEGER PRIMARY KEY AUTOINCREMENT, opp_id INTEGER, from_state TEXT, to_state TEXT, actor TEXT, note TEXT, at TEXT);
    CREATE TABLE IF NOT EXISTS research_experiments(
      id INTEGER PRIMARY KEY AUTOINCREMENT, opp_id INTEGER NOT NULL, fields TEXT NOT NULL, state TEXT NOT NULL DEFAULT 'proposed', approved_by TEXT, approved_at TEXT,
      results TEXT, limitations TEXT, verified_by TEXT, created_at TEXT);
    """)
    db.commit()


# ------------------------------------------------------------------ radar
def _sources(sources):
    out = []
    for s in sources or []:
        if not (s.get("url") and s.get("kind") in EVIDENCE_RANK and s.get("accessed")):
            raise Refused("every source needs url, kind (%s) and the date accessed" % ", ".join(sorted(EVIDENCE_RANK)))
        out.append({"url": s["url"], "kind": s["kind"], "published": s.get("published", ""), "accessed": s["accessed"]})
    if not out:
        raise Refused("a radar entry needs at least one source")
    return out


def upsert_radar(db, tech, ring, sources, fields, new_evidence="", rejection_reason="", actor="oracle"):
    """Add or update a radar entry under the evidence rules. Returns 'added' or 'updated'."""
    if ring not in RINGS:
        raise Refused("ring must be one of %s" % RINGS)
    missing = [f for f in RADAR_FIELDS if not str(fields.get(f, "")).strip()]
    if missing:
        raise Refused("missing: " + ", ".join(missing))
    src = _sources(sources)
    rank = max(EVIDENCE_RANK[s["kind"]] for s in src)
    independent = max([EVIDENCE_RANK[s["kind"]] for s in src if s["kind"] != "vendor_claim"] or [0])
    if ring in ("adopt", "trial") and independent < 1:
        raise Refused("a vendor claim alone cannot lift %s above Assess; add an independent source" % tech)
    if ring == "adopt" and not any(s["kind"] in PRIMARY for s in src):
        raise Refused("Adopt needs a primary, independent source (official docs, peer-reviewed, independent benchmark)")
    if ring == "avoid" and not rejection_reason.strip():
        raise Refused("Avoid needs the reason it was rejected")
    key = norm(tech)
    row = db.execute("SELECT * FROM radar WHERE tech_norm=?", (key,)).fetchone()
    nxt = (datetime.date.today() + datetime.timedelta(days=RING_REVIEW_DAYS[ring])).isoformat()
    if row:
        if today() < (row["next_review"] or "") and len(new_evidence.strip()) < 40:
            raise Refused("%s was assessed on %s (next review %s): not re-researched without new evidence" % (tech, (row["researched_at"] or "")[:10], row["next_review"]))
        if row["ring"] == "avoid" and ring != "avoid" and len(new_evidence.strip()) < 40:
            raise Refused("%s was rejected (%s); it returns only with significant new evidence" % (tech, row["rejection_reason"]))
        if row["ring"] != ring:
            db.execute("INSERT INTO radar_history(tech,from_ring,to_ring,why,at) VALUES(?,?,?,?,?)", (tech, row["ring"], ring, new_evidence[:300] or "review", now()))
        db.execute("UPDATE radar SET ring=?, fields=?, sources=?, evidence_rank=?, rejection_reason=?, researched_at=?, next_review=?, updated_at=? WHERE id=?",
                   (ring, json.dumps(fields), json.dumps(src), rank, rejection_reason or row["rejection_reason"], now(), nxt, now(), row["id"]))
        db.commit()
        return "updated"
    db.execute("INSERT INTO radar(tech_norm,tech,ring,fields,sources,evidence_rank,rejection_reason,first_seen,researched_at,next_review,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)",
               (key, tech, ring, json.dumps(fields), json.dumps(src), rank, rejection_reason, now(), now(), nxt, now()))
    db.execute("INSERT INTO radar_history(tech,from_ring,to_ring,why,at) VALUES(?,?,?,?,?)", (tech, None, ring, "added", now()))
    db.commit()
    return "added"


# ---------------------------------------------------------- opportunities
def add_opportunity(db, title, horizon, summary, target, evidence, lab="", uncertainty="", cost="", success="", next_action="", new_evidence="", actor="oracle"):
    if horizon not in (1, 2, 3):
        raise Refused("horizon must be 1, 2 or 3")
    if target not in ("ada", "apollo"):
        raise Refused("target must be ada or apollo")
    if not (title.strip() and summary.strip()):
        raise Refused("title and summary required")
    if not evidence or not all(e.get("fact") and e.get("source") and e.get("accessed") for e in evidence):
        raise Refused("every evidence item needs fact, source and accessed date; opportunities without evidence are not recorded")
    key = norm(title)
    for o in db.execute("SELECT * FROM research_opps").fetchall():
        close = o["title_norm"] == key or difflib.SequenceMatcher(None, o["title_norm"], key).ratio() >= 0.85
        if close:
            if o["state"] != "rejected":
                raise Refused("already tracked as #%d (%s)" % (o["id"], o["state"]))
            if len(new_evidence.strip()) < 40:
                raise Refused("already rejected (#%d: %s); it returns only with significant new evidence" % (o["id"], o["rejected_reason"] or "no reason recorded"))
    cur = db.execute("INSERT INTO research_opps(title_norm,title,horizon,lab,summary,target,state,owner,evidence,uncertainty,cost_to_investigate,success_looks_like,next_action,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
                     (key, title, horizon, lab, summary, target, "discovered", "oracle", json.dumps(evidence), uncertainty, cost, success, next_action, now(), now()))
    db.execute("INSERT INTO research_history(opp_id,from_state,to_state,actor,note,at) VALUES(?,?,?,?,?,?)", (cur.lastrowid, None, "discovered", actor, "created", now()))
    db.commit()
    return cur.lastrowid


def move(db, oid, to, actor, note="", tty=False):
    o = db.execute("SELECT * FROM research_opps WHERE id=?", (oid,)).fetchone()
    if not o:
        raise Refused("no opportunity %s" % oid)
    frm = o["state"]
    if frm in TERMINAL:
        raise Refused("#%s is %s and cannot change" % (oid, frm))
    allowed = TRANSITIONS.get((frm, to))
    if allowed is None or actor not in allowed:
        raise Refused("%s may not move #%s from %s to %s" % (actor, oid, frm, to))
    if actor == "tim" and not tty:
        raise Refused("the owner's decisions need an interactive terminal or the authenticated dashboard")
    if to == "experimenting" and not EXPERIMENT_EXECUTION_ENABLED:
        raise Refused("running experiments is not enabled (Oracle is at level 2: it designs, it does not execute)")
    if to == "experiment_proposed" and not db.execute("SELECT 1 FROM research_experiments WHERE opp_id=?", (oid,)).fetchone():
        raise Refused("propose the experiment (all required fields) first")
    if to == "evidence_gathering" and not json.loads(o["evidence"] or "[]"):
        raise Refused("no evidence recorded")
    if to == "rejected":
        db.execute("UPDATE research_opps SET rejected_reason=? WHERE id=?", (note, oid))
    db.execute("UPDATE research_opps SET state=?, updated_at=? WHERE id=?", (to, now(), oid))
    db.execute("INSERT INTO research_history(opp_id,from_state,to_state,actor,note,at) VALUES(?,?,?,?,?,?)", (oid, frm, to, actor, note, now()))
    db.commit()


def propose_experiment(db, oid, fields):
    missing = [f for f in EXPERIMENT_FIELDS if fields.get(f) in (None, "", [], {})]
    if missing:
        raise Refused("experiment missing: " + ", ".join(missing))
    try:
        days = int(fields["time_box_days"])
    except (TypeError, ValueError):
        raise Refused("time_box_days must be a number")
    if not 1 <= days <= 30:
        raise Refused("experiments are time-boxed: 1-30 days")
    cur = db.execute("INSERT INTO research_experiments(opp_id,fields,created_at) VALUES(?,?,?)", (oid, json.dumps(fields), now()))
    db.commit()
    return cur.lastrowid


def approve_experiment(db, eid, by, tty):
    if by != "tim" or not tty:
        raise Refused("only the owner (terminal or authenticated dashboard) can approve an experiment")
    n = db.execute("UPDATE research_experiments SET state='approved', approved_by=?, approved_at=? WHERE id=? AND state='proposed'", (by, now(), eid)).rowcount
    if not n:
        raise Refused("no proposed experiment %s" % eid)
    opp = db.execute("SELECT opp_id FROM research_experiments WHERE id=?", (eid,)).fetchone()["opp_id"]
    move(db, opp, "experiment_approved", "tim", "experiment %s approved" % eid, tty=True)
    db.commit()


def transfer(db, ada, oid):
    """Hand a recommended opportunity to Ada or Apollo as an ordinary PROPOSED task: the owner's queue and approval still apply."""
    o = db.execute("SELECT * FROM research_opps WHERE id=?", (oid,)).fetchone()
    if not o or o["state"] != "recommended_for_adoption":
        raise Refused("only a recommended opportunity can be transferred")
    ev = json.loads(o["evidence"])
    tid, _ = ada.add_task(db, "oracle:%d" % oid, o["title"], "improvement", "oracle", state="proposed", category="ordinary",
                          payload={"problem": o["summary"], "evidence": [{"text": "%s (%s, %s)" % (e["fact"], e["source"], e["accessed"])} for e in ev],
                                   "proposal": o["next_action"] or "", "benefit": o["success_looks_like"] or "", "effort": {"size": "?"}, "acceptance": [o["success_looks_like"] or "to be defined"],
                                   "area": "research", "uncertainty": o["uncertainty"]},
                          source_ref="oracle:%d" % oid, score=5.0 if o["horizon"] == 1 else 2.0, rationale="Oracle research, horizon %d; uncertainty: %s" % (o["horizon"], o["uncertainty"] or "not stated"), actor="oracle")
    dest = "transferred_to_ada" if o["target"] == "ada" else "transferred_to_apollo"
    db.execute("UPDATE research_opps SET task_id=? WHERE id=?", (tid, oid))
    move(db, oid, dest, "oracle", "queued as task #%d for the owner's approval" % tid)
    return tid


# ------------------------------------------------------------- context
def context(db):
    """What Oracle is told before researching: the radar with review dates (do not redo), the rejected list, open opportunities."""
    radar = db.execute("SELECT tech, ring, next_review, rejection_reason FROM radar ORDER BY ring, tech").fetchall()
    opps = db.execute("SELECT id, title, state FROM research_opps ORDER BY id").fetchall()
    lines = ["RADAR (do NOT re-research an entry before its review date unless you have significant new evidence):"]
    lines += ["- %s [%s] next review %s%s" % (r["tech"], r["ring"], r["next_review"], (" REJECTED: " + r["rejection_reason"]) if r["ring"] == "avoid" else "") for r in radar] or ["- empty"]
    lines.append("OPPORTUNITIES already tracked (do not repeat):")
    lines += ["- #%d %s [%s]" % (o["id"], o["title"], o["state"]) for o in opps] or ["- none"]
    return "\n".join(lines)


# ------------------------------------------------------------ briefing
def weekly_briefing(db, date=None):
    date = date or today()
    since = (datetime.date.fromisoformat(date) - datetime.timedelta(days=7)).isoformat()
    L = ["# Oracle weekly briefing — %s" % date, "", "_Evidence over excitement: sections with nothing worth saying say so._", ""]

    def sec(title, body):
        L.extend(["## " + title, body or "_Nothing met the bar this week._", ""])
    best = db.execute("SELECT * FROM radar WHERE researched_at >= ? ORDER BY evidence_rank DESC, id DESC LIMIT 1", (since,)).fetchone()
    sec("Most significant discovery", ("**%s** [%s, evidence rank %d/3]: %s" % (best["tech"], best["ring"], best["evidence_rank"], json.loads(best["fields"]).get("advantage", ""))) if best else "")

    def opp(h=None, target=None, lab=None):
        q, a = "SELECT * FROM research_opps WHERE state NOT IN ('rejected','completed')", []
        for col, v in (("horizon", h), ("target", target), ("lab", lab)):
            if v is not None:
                q += " AND %s=?" % col; a.append(v)
        r = db.execute(q + " ORDER BY id DESC LIMIT 1", a).fetchone()
        return ("**#%d %s** (%s). %s Uncertainty: %s. Next: %s" % (r["id"], r["title"], r["state"].replace("_", " "), r["summary"], r["uncertainty"] or "not stated", r["next_action"] or "—")) if r else ""
    sec("Immediate opportunity (about 3 months)", opp(h=1)); sec("Emerging opportunity (3-12 months)", opp(h=2)); sec("Transformational opportunity", opp(h=3))
    sec("Commercial opportunity", opp(target="apollo") or opp(lab="E"))
    ch = db.execute("SELECT * FROM radar_history WHERE at >= ? ORDER BY id", (since,)).fetchall()
    sec("Technology radar changes", "\n".join("- %s: %s → %s (%s)" % (c["tech"], c["from_ring"] or "new", c["to_ring"], (c["why"] or "")[:100]) for c in ch))
    ex = db.execute("SELECT e.*, o.title FROM research_experiments e JOIN research_opps o ON o.id=e.opp_id WHERE e.state='proposed' ORDER BY e.id DESC LIMIT 1").fetchone()
    if ex:
        f = json.loads(ex["fields"])
        sec("Recommended experiment", "**%s**: %s Baseline: %s. Success: %s. Failure: %s. Time box: %s days. Approve: `ada approve-experiment %d`" % (ex["title"], f["research_question"], f["baseline"], f["success_criteria"], f["failure_criteria"], f["time_box_days"], ex["id"]))
    else:
        sec("Recommended experiment", "")
    risks = ["- %s: only vendor claims so far (evidence rank 0)" % r["tech"] for r in db.execute("SELECT tech FROM radar WHERE evidence_rank=0").fetchall()]
    risks += ["- Overdue review: %s (was due %s)" % (r["tech"], r["next_review"]) for r in db.execute("SELECT tech, next_review FROM radar WHERE next_review < ?", (date,)).fetchall()]
    sec("Risks and limitations", "\n".join(risks))
    return "\n".join(L)
