"""Commercial core for Apollo's department: ONE pipeline, department-wide suppression, contact-eligibility gate, registers.

Lives in the same SQLite file as Ada's queue (dept.db) so approvals, locks, pause and audit are shared. Rules are enforced here in code:
  * a prospect is deduplicated on normalised domain and on business-name+location, and a suppressed one can never be re-added;
  * an opt-out (suppress) applies to every opportunity, draft and campaign that matches any identifier, across all agents;
  * nothing reaches `contacted` unless eligibility was CONFIRMED BY THE HUMAN with a documented basis, outreach was approved by the human,
    a draft exists, and the prospect is not suppressed. No code here sends anything: the human sends and records it;
  * agents' knowledge is draft until a human (or Guardian) verifies it; Scribe may only use VERIFIED capabilities.
"""
import datetime, difflib, json, os, re, sqlite3, uuid
from urllib.parse import urlparse

STAGES = ["identified", "researching", "qualified", "contact_eligibility_verified", "awaiting_outreach_approval", "contacted", "engaged",
          "discovery_scheduled", "discovery_completed", "demonstration_scheduled", "demonstration_completed", "trial_started", "activated",
          "proposal_presented", "decision_pending", "won", "lost", "nurture", "disqualified", "opted_out"]
FINAL = {"won", "disqualified", "opted_out"}
RESEARCHERS = {"hunter", "apollo", "compass", "tim"}
SALES = {"closer", "apollo", "ambassador", "tim"}
_fwd = [("identified", "researching", RESEARCHERS), ("researching", "qualified", RESEARCHERS),
        ("qualified", "contact_eligibility_verified", {"apollo", "tim"}),
        ("contact_eligibility_verified", "awaiting_outreach_approval", {"apollo", "scribe", "tim"}),
        ("awaiting_outreach_approval", "contacted", {"tim"}),
        ("contacted", "engaged", SALES), ("engaged", "discovery_scheduled", SALES), ("discovery_scheduled", "discovery_completed", SALES),
        ("discovery_completed", "demonstration_scheduled", SALES), ("demonstration_scheduled", "demonstration_completed", SALES),
        ("demonstration_completed", "trial_started", SALES), ("trial_started", "activated", SALES | {"ambassador"}),
        ("activated", "proposal_presented", SALES), ("proposal_presented", "decision_pending", SALES), ("decision_pending", "won", {"tim", "apollo"}),
        ("decision_pending", "lost", SALES)]
MOVES = {(a, b): who for a, b, who in _fwd}
for s in STAGES:
    if s not in FINAL | {"lost"}:
        MOVES[(s, "disqualified")] = {"hunter", "apollo", "closer", "tim"}
    if s not in FINAL | {"identified", "researching", "nurture"}:
        MOVES[(s, "nurture")] = {"apollo", "closer", "tim"}
    if s in ("engaged", "discovery_scheduled", "discovery_completed", "demonstration_scheduled", "demonstration_completed", "trial_started", "activated", "proposal_presented", "decision_pending"):
        MOVES[(s, "lost")] = SALES
MOVES[("lost", "nurture")] = {"apollo", "closer", "tim"}
MOVES[("nurture", "contacted")] = {"tim"}
BASES = {"express_consent", "implied_existing_relationship", "implied_inquiry", "conspicuous_publication", "exempt_other"}
KNOWLEDGE_KINDS = {"observation", "hypothesis", "recommendation", "decision", "verified_outcome", "lesson"}
VERIFIERS = {"tim", "guardian"}


class Refused(Exception):
    pass


def now():
    return datetime.datetime.now().astimezone().isoformat(timespec="seconds")


def ensure_schema(db):
    db.executescript("""
    CREATE TABLE IF NOT EXISTS opportunities(
      id INTEGER PRIMARY KEY AUTOINCREMENT, uid TEXT UNIQUE NOT NULL, business_name TEXT NOT NULL, website TEXT, industry TEXT, location TEXT,
      domain_norm TEXT, name_norm TEXT, dedupe_key TEXT, stage TEXT NOT NULL, owner TEXT NOT NULL, source TEXT, qualification_evidence TEXT NOT NULL DEFAULT '[]',
      inferred_needs TEXT NOT NULL DEFAULT '[]', fit TEXT, contact TEXT NOT NULL DEFAULT '{}', next_action TEXT, next_action_date TEXT,
      permission_status TEXT NOT NULL DEFAULT 'none', value REAL, product_requirements TEXT, objections TEXT NOT NULL DEFAULT '[]',
      outcome TEXT, synthetic INTEGER NOT NULL DEFAULT 0, created_at TEXT, updated_at TEXT);
    CREATE UNIQUE INDEX IF NOT EXISTS ux_opp_domain ON opportunities(domain_norm) WHERE domain_norm IS NOT NULL AND domain_norm != '';
    CREATE UNIQUE INDEX IF NOT EXISTS ux_opp_name ON opportunities(dedupe_key);
    CREATE TABLE IF NOT EXISTS opp_history(id INTEGER PRIMARY KEY AUTOINCREMENT, opp_id INTEGER NOT NULL, from_stage TEXT, to_stage TEXT NOT NULL, actor TEXT NOT NULL, reason TEXT, at TEXT NOT NULL);
    CREATE TABLE IF NOT EXISTS eligibility(
      opp_id INTEGER PRIMARY KEY, basis TEXT NOT NULL, evidence TEXT NOT NULL, status TEXT NOT NULL, proposed_by TEXT NOT NULL, proposed_at TEXT,
      confirmed_by TEXT, confirmed_at TEXT, outreach_approved_by TEXT, outreach_approved_at TEXT, note TEXT);
    CREATE TABLE IF NOT EXISTS suppressions(id INTEGER PRIMARY KEY AUTOINCREMENT, kind TEXT NOT NULL, value_norm TEXT NOT NULL, reason TEXT, source TEXT, at TEXT NOT NULL, UNIQUE(kind, value_norm));
    CREATE TABLE IF NOT EXISTS communications(
      id INTEGER PRIMARY KEY AUTOINCREMENT, opp_id INTEGER, channel TEXT NOT NULL, direction TEXT NOT NULL DEFAULT 'out', status TEXT NOT NULL DEFAULT 'draft',
      subject TEXT, body TEXT, claims TEXT NOT NULL DEFAULT '[]', created_by TEXT NOT NULL, created_at TEXT, sent_recorded_by TEXT, sent_at TEXT);
    CREATE TABLE IF NOT EXISTS capabilities(
      id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT UNIQUE NOT NULL, description TEXT, evidence_paths TEXT NOT NULL DEFAULT '[]', status TEXT NOT NULL DEFAULT 'unverified',
      limitations TEXT, source TEXT, verified_by TEXT, verified_at TEXT);
    CREATE TABLE IF NOT EXISTS knowledge(
      id INTEGER PRIMARY KEY AUTOINCREMENT, topic TEXT NOT NULL, kind TEXT NOT NULL, statement TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'draft', source TEXT NOT NULL,
      created_by TEXT NOT NULL, created_at TEXT, verified_by TEXT, verified_at TEXT, evidence TEXT, supersedes INTEGER, department TEXT);
    CREATE TABLE IF NOT EXISTS campaigns(
      id INTEGER PRIMARY KEY AUTOINCREMENT, cid TEXT UNIQUE NOT NULL, fields TEXT NOT NULL, state TEXT NOT NULL DEFAULT 'draft', created_by TEXT, created_at TEXT, approved_by TEXT, approved_at TEXT);
    CREATE TABLE IF NOT EXISTS experiments(
      id INTEGER PRIMARY KEY AUTOINCREMENT, eid TEXT UNIQUE NOT NULL, fields TEXT NOT NULL, state TEXT NOT NULL DEFAULT 'proposed', created_by TEXT, created_at TEXT, approved_by TEXT, approved_at TEXT, results TEXT);
    CREATE TABLE IF NOT EXISTS product_requests(
      id INTEGER PRIMARY KEY AUTOINCREMENT, problem_norm TEXT UNIQUE NOT NULL, problem TEXT NOT NULL, evidence TEXT NOT NULL DEFAULT '[]', observations INTEGER NOT NULL DEFAULT 1,
      segment TEXT, potential_value TEXT, alternatives TEXT, requirements TEXT, risk TEXT, alignment TEXT, created_at TEXT, updated_at TEXT);
    CREATE TABLE IF NOT EXISTS objections(
      id INTEGER PRIMARY KEY AUTOINCREMENT, wording TEXT NOT NULL, concern TEXT, response TEXT, evidence TEXT, capability TEXT, limitation TEXT, outcome TEXT, created_at TEXT);
    """)
    db.commit()


# ------------------------------------------------------------ normalisation
def norm_name(s):
    return re.sub(r"[^a-z0-9]+", " ", (s or "").lower()).strip()


def norm_domain(s):
    s = (s or "").strip().lower()
    if not s:
        return ""
    if "@" in s and "//" not in s:
        s = s.split("@", 1)[1]
    host = urlparse(s if "//" in s else "//" + s).netloc or s
    return re.sub(r"^www\.", "", host.split(":")[0])


def norm_phone(s):
    d = re.sub(r"\D", "", s or "")
    return d[-10:] if len(d) >= 10 else d


def identifiers(opp):
    """Every suppressible identifier an opportunity carries."""
    c = json.loads(opp["contact"] or "{}")
    ids = []
    for e in ([c.get("email")] if c.get("email") else []) + list(c.get("emails", [])):
        ids += [("email", (e or "").strip().lower()), ("domain", norm_domain(e))]
    if c.get("phone"):
        ids.append(("phone", norm_phone(c["phone"])))
    if opp["domain_norm"]:
        ids.append(("domain", opp["domain_norm"]))
    ids.append(("name", opp["name_norm"]))
    return [(k, v) for k, v in ids if v]


# -------------------------------------------------------------- suppression
GENERIC_DOMAINS = {"gmail.com", "hotmail.com", "outlook.com", "yahoo.com", "icloud.com", "shaw.ca", "telus.net", "live.com", "me.com"}


def suppress(db, reason, email=None, domain=None, phone=None, name=None, source="opt-out"):
    """Department-wide opt-out. Every matching opportunity becomes opted_out; future adds are refused."""
    items = []
    if email:
        items.append(("email", email.strip().lower()))
        d = norm_domain(email)
        if d and d not in GENERIC_DOMAINS:
            items.append(("domain", d))
    if domain and norm_domain(domain) not in GENERIC_DOMAINS:
        items.append(("domain", norm_domain(domain)))
    if phone:
        items.append(("phone", norm_phone(phone)))
    if name:
        items.append(("name", norm_name(name)))
    if not items:
        raise Refused("nothing to suppress")
    for k, v in items:
        db.execute("INSERT OR IGNORE INTO suppressions(kind,value_norm,reason,source,at) VALUES(?,?,?,?,?)", (k, v, reason, source, now()))
    hit = []
    for o in db.execute("SELECT * FROM opportunities WHERE stage != 'opted_out'").fetchall():
        if is_suppressed_opp(db, o):
            _move(db, o["id"], "opted_out", "system", "opt-out: " + reason)
            db.execute("UPDATE communications SET status='cancelled' WHERE opp_id=? AND status IN ('draft','approved')", (o["id"],))
            db.execute("UPDATE opportunities SET permission_status='opted_out', next_action=NULL, next_action_date=NULL WHERE id=?", (o["id"],))
            hit.append(o["uid"])
    db.commit()
    return hit


def is_suppressed(db, kind, value):
    return bool(value) and bool(db.execute("SELECT 1 FROM suppressions WHERE kind=? AND value_norm=?", (kind, value)).fetchone())


def is_suppressed_opp(db, opp):
    return any(is_suppressed(db, k, v) for k, v in identifiers(opp))


def suppressed_contact(db, email=None, phone=None, domain=None, name=None):
    d = norm_domain(email or domain)
    return (is_suppressed(db, "email", (email or "").strip().lower())
            or (bool(d) and d not in GENERIC_DOMAINS and is_suppressed(db, "domain", d))
            or is_suppressed(db, "phone", norm_phone(phone))
            or is_suppressed(db, "name", norm_name(name)))


# ----------------------------------------------------------- opportunities
def add_opportunity(db, business_name, website="", industry="", location="", source="", contact=None, evidence=None, inferred=None, owner="hunter", synthetic=False, actor="hunter"):
    """Create an opportunity at `identified`. Refuses duplicates and suppressed prospects. Evidence items need a source and a date."""
    if not business_name.strip():
        raise Refused("business name required")
    for e in evidence or []:
        if not (e.get("fact") and e.get("source") and e.get("observed_at")):
            raise Refused("every qualification fact needs fact, source and observed_at — observed facts only; inferences go in inferred_needs")
    contact = contact or {}
    dom, nm = norm_domain(website), norm_name(business_name)
    dk = nm + "|" + norm_name(location)
    if suppressed_contact(db, email=contact.get("email"), phone=contact.get("phone"), domain=website, name=business_name):
        raise Refused("this business has opted out or is suppressed")
    row = db.execute("SELECT uid FROM opportunities WHERE (domain_norm=? AND domain_norm!='') OR dedupe_key=?", (dom, dk)).fetchone()
    if row:
        raise Refused("duplicate of %s" % row["uid"])
    uid = "OPP-" + uuid.uuid4().hex[:8].upper()
    t = now()
    cur = db.execute("INSERT INTO opportunities(uid,business_name,website,industry,location,domain_norm,name_norm,dedupe_key,stage,owner,source,qualification_evidence,inferred_needs,contact,synthetic,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
                     (uid, business_name, website, industry, location, dom, nm, dk, "identified", owner, source, json.dumps(evidence or []), json.dumps(inferred or []), json.dumps(contact), 1 if synthetic else 0, t, t))
    db.execute("INSERT INTO opp_history(opp_id,from_stage,to_stage,actor,reason,at) VALUES(?,?,?,?,?,?)", (cur.lastrowid, None, "identified", actor, "created", t))
    db.commit()
    return uid


def get_opp(db, uid):
    r = db.execute("SELECT * FROM opportunities WHERE uid=? OR id=?", (uid, uid if str(uid).isdigit() else -1)).fetchone()
    if not r:
        raise Refused("no opportunity " + str(uid))
    return r


def _move(db, oid, to, actor, reason):
    frm = db.execute("SELECT stage FROM opportunities WHERE id=?", (oid,)).fetchone()["stage"]
    t = now()
    db.execute("UPDATE opportunities SET stage=?, updated_at=? WHERE id=?", (to, t, oid))
    db.execute("INSERT INTO opp_history(opp_id,from_stage,to_stage,actor,reason,at) VALUES(?,?,?,?,?,?)", (oid, frm, to, actor, reason, t))


def move_stage(db, uid, to, actor, reason=""):
    o = get_opp(db, uid)
    frm = o["stage"]
    if to == "opted_out":
        raise Refused("opt-outs are recorded with suppress(), which applies department-wide")
    if frm in FINAL:
        raise Refused("%s is %s and cannot change" % (o["uid"], frm))
    allowed = MOVES.get((frm, to))
    if allowed is None or actor not in allowed:
        raise Refused("%s may not move %s from %s to %s" % (actor, o["uid"], frm, to))
    if is_suppressed_opp(db, o):
        raise Refused("%s is suppressed" % o["uid"])
    el = db.execute("SELECT * FROM eligibility WHERE opp_id=?", (o["id"],)).fetchone()
    if to == "qualified" and not json.loads(o["qualification_evidence"]):
        raise Refused("qualification needs at least one sourced, dated observed fact")
    if to == "contact_eligibility_verified" and not (el and el["status"] == "confirmed"):
        raise Refused("contact eligibility has not been confirmed by the product owner")
    if to == "awaiting_outreach_approval" and not db.execute("SELECT 1 FROM communications WHERE opp_id=? AND status='draft'", (o["id"],)).fetchone():
        raise Refused("there is no draft to approve")
    if to == "contacted" and frm != "nurture":
        if not (el and el["status"] == "confirmed" and el["outreach_approved_by"] == "tim"):
            raise Refused("outreach has not been approved by the product owner")
    _move(db, o["id"], to, actor, reason)
    db.commit()


# ------------------------------------------------------------- eligibility
def propose_eligibility(db, uid, basis, evidence, actor):
    if basis not in BASES:
        raise Refused("basis must be one of %s" % sorted(BASES))
    if not evidence.strip():
        raise Refused("a documented basis needs supporting evidence")
    o = get_opp(db, uid)
    db.execute("INSERT INTO eligibility(opp_id,basis,evidence,status,proposed_by,proposed_at) VALUES(?,?,?,'proposed',?,?) ON CONFLICT(opp_id) DO UPDATE SET basis=excluded.basis, evidence=excluded.evidence, status='proposed', proposed_by=excluded.proposed_by, proposed_at=excluded.proposed_at, confirmed_by=NULL, confirmed_at=NULL, outreach_approved_by=NULL, outreach_approved_at=NULL",
               (o["id"], basis, evidence, actor, now()))
    db.commit()


def confirm_eligibility(db, uid, by, note, tty):
    """The legal call is the human's. An agent or script cannot confirm."""
    if by != "tim" or not tty:
        raise Refused("only the product owner, in an interactive terminal, can confirm contact eligibility")
    o = get_opp(db, uid)
    if is_suppressed_opp(db, o):
        raise Refused("suppressed")
    if not db.execute("SELECT 1 FROM eligibility WHERE opp_id=?", (o["id"],)).fetchone():
        raise Refused("no proposed basis to confirm")
    db.execute("UPDATE eligibility SET status='confirmed', confirmed_by=?, confirmed_at=?, note=? WHERE opp_id=?", (by, now(), note, o["id"]))
    db.execute("UPDATE opportunities SET permission_status='eligibility_confirmed' WHERE id=?", (o["id"],))
    db.commit()


def approve_outreach(db, uid, by, tty):
    if by != "tim" or not tty:
        raise Refused("only the product owner, in an interactive terminal, can approve outreach")
    o = get_opp(db, uid)
    el = db.execute("SELECT * FROM eligibility WHERE opp_id=?", (o["id"],)).fetchone()
    if not (el and el["status"] == "confirmed") or is_suppressed_opp(db, o):
        raise Refused("eligibility not confirmed, or suppressed")
    db.execute("UPDATE eligibility SET outreach_approved_by=?, outreach_approved_at=? WHERE opp_id=?", (by, now(), o["id"]))
    db.execute("UPDATE communications SET status='approved' WHERE opp_id=? AND status='draft'", (o["id"],))
    db.execute("UPDATE opportunities SET permission_status='outreach_approved' WHERE id=?", (o["id"],))
    db.commit()


# ----------------------------------------------------------- communications
def add_draft(db, uid, channel, subject, body, claims, actor):
    """A draft for human review. Every product claim must be a VERIFIED capability; email needs sender identification and an unsubscribe line."""
    o = get_opp(db, uid)
    if is_suppressed_opp(db, o):
        raise Refused("suppressed")
    ok = {r["name"].lower() for r in db.execute("SELECT name FROM capabilities WHERE status='verified'").fetchall()}
    for c in claims:
        if str(c).lower() not in ok:
            raise Refused("claim %r is not a verified capability (status must be 'verified')" % c)
    if channel == "email":
        low = body.lower()
        if "unsubscribe" not in low and "opt out" not in low:
            raise Refused("an email draft must include a working unsubscribe/opt-out instruction")
        if "[sender" not in low and "mowology" not in low:
            raise Refused("an email draft must identify the sender and give contact details")
    if channel == "sms" and (len(body) > 160 or re.search(r"https?://|www\.|\b[a-z0-9-]+\.[a-z]{2,}\b", body.lower())):
        raise Refused("SMS: 160 characters, no URLs")
    cur = db.execute("INSERT INTO communications(opp_id,channel,status,subject,body,claims,created_by,created_at) VALUES(?,?,?,?,?,?,?,?)", (o["id"], channel, "draft", subject, body, json.dumps(claims), actor, now()))
    db.commit()
    return cur.lastrowid


def record_sent(db, comm_id, by, tty):
    """Only the human records that a message was actually sent (nothing here sends)."""
    if by != "tim" or not tty:
        raise Refused("only the product owner can record a message as sent")
    c = db.execute("SELECT * FROM communications WHERE id=?", (comm_id,)).fetchone()
    if not c or c["status"] != "approved":
        raise Refused("only an approved draft can be recorded as sent")
    o = db.execute("SELECT * FROM opportunities WHERE id=?", (c["opp_id"],)).fetchone()
    if is_suppressed_opp(db, o):
        raise Refused("suppressed")
    db.execute("UPDATE communications SET status='sent', sent_recorded_by=?, sent_at=? WHERE id=?", (by, now(), comm_id))
    db.commit()


# ------------------------------------------------------ capabilities / claims
def add_capability(db, name, description, evidence_paths, limitations="", source="repo", repo_root=None):
    exists = all(os.path.exists(os.path.join(repo_root, p)) for p in evidence_paths) if repo_root else False
    db.execute("INSERT OR IGNORE INTO capabilities(name,description,evidence_paths,status,limitations,source) VALUES(?,?,?,?,?,?)",
               (name, description, json.dumps(evidence_paths), "evidence_found" if exists else "unverified", limitations, source))
    db.commit()


def verify_capability(db, name, by, note, tty):
    if by not in VERIFIERS or (by == "tim" and not tty):
        raise Refused("only the product owner (terminal) or Guardian can verify a capability")
    n = db.execute("UPDATE capabilities SET status='verified', verified_by=?, verified_at=?, limitations=COALESCE(NULLIF(?,''), limitations) WHERE name=?", (by, now(), note, name)).rowcount
    if not n:
        raise Refused("no such capability")
    db.commit()


# ------------------------------------------------------------- knowledge
def add_knowledge(db, topic, kind, statement, source, actor, department="commercial", evidence=""):
    """Any agent may record; nothing becomes authoritative here. Needs a source."""
    if kind not in KNOWLEDGE_KINDS:
        raise Refused("kind must be one of %s" % sorted(KNOWLEDGE_KINDS))
    if not (statement.strip() and source.strip()):
        raise Refused("a statement and its source are required")
    cur = db.execute("INSERT INTO knowledge(topic,kind,statement,status,source,created_by,created_at,department,evidence) VALUES(?,?,?,?,?,?,?,?,?)", (topic, kind, statement, "draft", source, actor, now(), department, evidence))
    db.commit()
    return cur.lastrowid


def promote_knowledge(db, kid, by, evidence, tty=True):
    """draft -> authoritative: only verified evidence or an owner decision, by Guardian or the human."""
    if by not in VERIFIERS or (by == "tim" and not tty):
        raise Refused("only the product owner or Guardian can make knowledge authoritative")
    if not evidence.strip():
        raise Refused("promotion needs verifying evidence")
    k = db.execute("SELECT * FROM knowledge WHERE id=?", (kid,)).fetchone()
    if not k:
        raise Refused("no such record")
    if k["kind"] in ("hypothesis", "recommendation") and by != "tim":
        raise Refused("hypotheses and recommendations become authoritative only by an owner decision")
    # a newer authoritative statement on the same topic supersedes, never silently overwrites
    old = db.execute("SELECT id FROM knowledge WHERE topic=? AND status='authoritative' AND id!=?", (k["topic"], kid)).fetchall()
    for o in old:
        db.execute("UPDATE knowledge SET status='superseded' WHERE id=?", (o["id"],))
    db.execute("UPDATE knowledge SET status='authoritative', verified_by=?, verified_at=?, evidence=?, supersedes=? WHERE id=?", (by, now(), evidence, old[0]["id"] if old else None, kid))
    db.commit()


def conflicts(db):
    """Draft statements that disagree with an authoritative one on the same topic (surfaced, never auto-resolved)."""
    out = []
    auth = {r["topic"]: r for r in db.execute("SELECT * FROM knowledge WHERE status='authoritative'").fetchall()}
    for d in db.execute("SELECT * FROM knowledge WHERE status='draft'").fetchall():
        a = auth.get(d["topic"])
        if a and difflib.SequenceMatcher(None, a["statement"].lower(), d["statement"].lower()).ratio() < 0.6:
            out.append((a["id"], d["id"], d["topic"]))
    return out


def lessons_for(db, agent, limit=10):
    """Selective distribution: authoritative lessons/outcomes plus this agent's own drafts only."""
    return db.execute("SELECT id,topic,kind,statement,status FROM knowledge WHERE (status='authoritative') OR (created_by=? AND status='draft') ORDER BY (status='authoritative') DESC, id DESC LIMIT ?", (agent, limit)).fetchall()


# --------------------------------------------- campaigns, experiments, requests
CAMPAIGN_FIELDS = ["objective", "segment", "customer_problem", "offer", "positioning", "channel", "approved_messaging", "assets", "contact_eligibility_requirements",
                   "landing_page", "conversion_tracking", "budget", "success_metrics", "guardrail_metrics", "review_date", "stop_conditions", "responsible_agents"]
EXPERIMENT_FIELDS = ["problem", "evidence", "hypothesis", "change", "audience", "success_metric", "guardrail_metrics", "sample_size", "duration", "cost", "risks"]


def _record(db, table, idcol, prefix, fields, required, actor):
    missing = [f for f in required if fields.get(f) in (None, "", [], {})]
    if missing:
        raise Refused("missing: " + ", ".join(missing))
    if table == "campaigns" and fields.get("budget") not in (0, "0", "none", "no spend"):
        pass  # a budget may be proposed; spending needs approval and is not built
    rid = prefix + "-" + uuid.uuid4().hex[:6].upper()
    db.execute("INSERT INTO %s(%s,fields,created_by,created_at) VALUES(?,?,?,?)" % (table, idcol), (rid, json.dumps(fields), actor, now()))
    db.commit()
    return rid


def create_campaign(db, fields, actor):
    return _record(db, "campaigns", "cid", "CMP", fields, CAMPAIGN_FIELDS, actor)


def create_experiment(db, fields, actor):
    return _record(db, "experiments", "eid", "EXP", fields, EXPERIMENT_FIELDS, actor)


def approve_record(db, table, rid, by, tty):
    if by != "tim" or not tty:
        raise Refused("only the product owner can approve; approval does not execute anything (no execution is built)")
    col = "cid" if table == "campaigns" else "eid"
    n = db.execute("UPDATE %s SET state='approved', approved_by=?, approved_at=? WHERE %s=?" % (table, col), (by, now(), rid)).rowcount
    if not n:
        raise Refused("no such record")
    db.commit()


def register_request(db, problem, evidence, segment="", potential_value="", alternatives="", requirements="", risk="", alignment=""):
    """The shared product-commercial register: the same problem seen again raises its observation count."""
    pn = norm_name(problem)
    t = now()
    row = db.execute("SELECT id, evidence FROM product_requests WHERE problem_norm=?", (pn,)).fetchone()
    if row:
        ev = json.loads(row["evidence"]) + [evidence]
        db.execute("UPDATE product_requests SET observations=observations+1, evidence=?, updated_at=? WHERE id=?", (json.dumps(ev), t, row["id"]))
    else:
        db.execute("INSERT INTO product_requests(problem_norm,problem,evidence,segment,potential_value,alternatives,requirements,risk,alignment,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)",
                   (pn, problem, json.dumps([evidence]), segment, potential_value, alternatives, requirements, risk, alignment, t, t))
    db.commit()


# ---------------------------------------------------------------- forecast
BUCKETS = {"contracted": ["won"], "probable": ["decision_pending", "proposal_presented", "activated", "trial_started"],
           "potential": ["demonstration_completed", "demonstration_scheduled", "discovery_completed", "discovery_scheduled", "engaged"],
           "speculative": ["contacted", "awaiting_outreach_approval", "contact_eligibility_verified", "qualified"]}


def forecast(db):
    """Counts and KNOWN values by confidence bucket. 'confirmed' revenue needs billing data that does not exist yet, so it is reported as unavailable.
    No stage conversion history exists, so no probability-weighted number is produced."""
    out = {"confirmed": {"note": "unavailable: no billing data yet"}, "_note": "values are only those entered on opportunities; unvalued opportunities are counted, not guessed. Synthetic rows excluded."}
    for b, stages in BUCKETS.items():
        rows = db.execute("SELECT value FROM opportunities WHERE synthetic=0 AND stage IN (%s)" % ",".join("?" * len(stages)), stages).fetchall()
        out[b] = {"count": len(rows), "known_value": sum(r["value"] for r in rows if r["value"] is not None), "unvalued": sum(1 for r in rows if r["value"] is None)}
    return out


CANDIDATE_CAPABILITIES = [
    ("Quotes: build, send and track quotes", "Quote builder, recipients, follow-ups", ["app/Modules/Quotes"], "Verify with a live quote demo"),
    ("Invoices: create, send and track invoices", "Invoice creation, PDF, send", ["app/Modules/Invoices"], "Online card payments not offered for other businesses (RPAA)"),
    ("Jobs and scheduling", "Jobs, schedule, crew assignment", ["app/Modules/Jobs", "app/Modules/Schedule"], ""),
    ("Crew time clock and GPS", "Timesheets, geofence, location", ["app/Modules/Geofence", "app/Modules/Operations"], "Native Android parity still in progress"),
    ("Photo proof of work", "Visit photos", ["app/Modules/Photos"], ""),
    ("Receipts and expenses (Penny)", "Receipt capture, expense records", ["app/Modules/Expenses", "app/Modules/Accounting"], "Accountant sign-off is the user's, not Ittai's"),
    ("Payroll support", "Payroll engine", ["app/Modules/Payroll"], "BC rules only; not verified for other provinces"),
    ("Marketing helper (Mia)", "Campaigns, reviews, social drafts", ["app/Modules/Marketing", "app/Modules/Reviews"], "Facebook/Instagram publishing known broken"),
    ("Client consent and unsubscribe", "Consent records", ["app/Modules/Consent"], ""),
    ("Referrals", "Referral tracking", ["app/Modules/Referrals"], ""),
    ("Public enquiry forms", "Website forms to leads", ["app/Modules/PublicForms", "app/Modules/Sales"], ""),
    ("Customer portal", "Quote/invoice pages for clients", ["public/customer"], ""),
    ("Onboarding", "Business setup", ["app/Modules/Onboarding"], "Setup paths still being built"),
    ("AI office heads (Kaname, Sam, Otto, Penny, Mia, Yui, Ada)", "AI employees' suggestions", ["app/Modules/ChiefOfStaff", "app/Modules/Agents"], "Autonomy is earned per task; they propose, the owner approves"),
]


def seed_capabilities(db, repo_root):
    for n, d, p, lim in CANDIDATE_CAPABILITIES:
        add_capability(db, n, d, p, lim, "repo candidate list (unverified by a human or Guardian)", repo_root)
