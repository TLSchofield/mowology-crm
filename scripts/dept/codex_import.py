"""Codex nightly review findings -> Ada's dashboard (read-only, no model, never touches production).

The nightly Codex review writes ~/.mowology-codex/findings.json (checked by the daily triage task). This copies the ones that
still need Tim into the table `codex_findings` in dept.db so the dashboard can show them next to Guardian and Sentinel.
  ready     = a fix is written on a codex-fixes/<date> branch and waits for Tim's yes to deploy
  decision  = real or plausible, not fixed: needs a design or a call from Tim
Findings marked fixed / not_real are only counted. Safe to rerun: the key is unit + a hash of the title.
Usage: python3 codex_import.py   (Ada's cycle calls import_codex(db) every night)
"""
import hashlib, json, os, re

CODEX_HOME = os.environ.get("CODEX_HOME_DIR", os.path.expanduser("~/.mowology-codex"))
OPEN = {"left", "decided"}
BRANCH = re.compile(r"codex-fixes/\d{4}-\d{2}-\d{2}")
READY = re.compile(r"fix ready|fixed on codex-fixes|on codex-fixes/", re.I)


def ensure(db):
    db.execute("""CREATE TABLE IF NOT EXISTS codex_findings(
      key TEXT PRIMARY KEY, unit TEXT, severity TEXT, title TEXT, note TEXT, bucket TEXT, branch TEXT, found_on TEXT,
      status TEXT NOT NULL DEFAULT 'open', first_seen TEXT, last_seen TEXT, resolved_at TEXT)""")


def key_of(f):
    return "codex:%s:%s" % (f.get("unit", "?"), hashlib.sha1((f.get("title") or "").strip().lower().encode()).hexdigest()[:12])


def classify(f):
    """('ready'|'decision', branch or None) for one open finding."""
    note = f.get("note") or ""
    m = BRANCH.search(note)
    if f.get("status") == "left" and m and READY.search(note):
        return "ready", m.group(0)
    return "decision", m.group(0) if m else None


def load(path=None):
    try:
        return json.load(open(path or os.path.join(CODEX_HOME, "findings.json")))
    except (OSError, ValueError):
        return None


def import_codex(db, now, findings=None):
    """Upsert open findings, close the ones no longer open. Returns a counts dict (None if the file is unreadable: nothing is closed then)."""
    ensure(db)
    data = findings if findings is not None else load()
    if data is None:
        return None
    t = now()
    seen, new = set(), 0
    for f in data:
        if f.get("status") not in OPEN:
            continue
        k = key_of(f)
        seen.add(k)
        bucket, branch = classify(f)
        sev = (f.get("severity") or "Low").capitalize()
        row = db.execute("SELECT 1 FROM codex_findings WHERE key=?", (k,)).fetchone()
        if row:
            db.execute("UPDATE codex_findings SET severity=?, title=?, note=?, bucket=?, branch=?, status='open', last_seen=?, resolved_at=NULL WHERE key=?",
                       (sev, f.get("title"), f.get("note"), bucket, branch, t, k))
        else:
            db.execute("INSERT INTO codex_findings(key,unit,severity,title,note,bucket,branch,found_on,status,first_seen,last_seen) VALUES(?,?,?,?,?,?,?,?,?,?,?)",
                       (k, f.get("unit"), sev, f.get("title"), f.get("note"), bucket, branch, f.get("date"), "open", t, t))
            new += 1
    closed = 0
    for r in db.execute("SELECT key FROM codex_findings WHERE status='open'").fetchall():
        if r["key"] not in seen:
            db.execute("UPDATE codex_findings SET status='resolved', resolved_at=? WHERE key=?", (t, r["key"]))
            closed += 1
    db.commit()
    return {"open": len(seen), "new": new, "closed": closed, "checked": len(data)}


def open_rows(db):
    ensure(db)
    order = "CASE severity WHEN 'High' THEN 0 WHEN 'Medium' THEN 1 ELSE 2 END"
    return db.execute("SELECT * FROM codex_findings WHERE status='open' ORDER BY bucket DESC, %s, found_on DESC" % order).fetchall()
