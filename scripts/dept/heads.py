"""Head health — a one-way, counts-only picture of how the resident heads (Kaname, Sam, Penny, Otto, Mia, Yui, Ada) are doing on production.

Owner decision 2026-11-24: the department should know the production heads' real state without a copy of production data.
  pull()     runs heads/snapshot.php through scripts/prod-run.sh (read-only: a rolled-back transaction, SELECTs only);
  validate() refuses anything that is not a number or a short fixed label, so personal text cannot be stored even if the script were wrong;
  save()     keeps one JSON per day in ~/.mowology-dept/heads/ (outside the vault and iCloud) and a history for trends;
  rates()    the few honest ratios agents and the dashboard use (corrected share, edited share, dismissed share, failure count);
  context()  a fenced, plain-words summary handed to Oracle as DATA about a service.
Nothing here writes to production, and nothing here names a person, a client or an amount."""
import datetime, glob, json, os, re, subprocess

HERE = os.path.dirname(os.path.abspath(__file__))
HEADS = ["kaname", "sam", "penny", "otto", "mia", "yui", "ada"]
SERVICE_TO_HEAD = {"charlie": "kaname", "sam": "sam", "penny": "penny", "otto": "otto", "mia": "mia", "yui": "yui", "ada": "ada"}
LABEL = re.compile(r"^[A-Za-z0-9_.:\-]{1,48}$")
GAP = re.compile(r"^[A-Za-z0-9_.:/\- ,()']{1,160}$")


class Refused(Exception):
    pass


def home():
    return os.path.join(os.environ.get("DEPT_HOME", os.path.expanduser("~/.mowology-dept")), "heads")


def repo_root():
    return os.environ.get("ADA_REPO_ROOT") or os.path.abspath(os.path.join(HERE, "..", ".."))


def _num(v):
    return isinstance(v, (int, float)) and not isinstance(v, bool)


def validate(snap):
    """Only numbers and short fixed labels are allowed anywhere. Raises Refused naming the path of the first offender."""
    if not isinstance(snap, dict) or not isinstance(snap.get("heads"), dict):
        raise Refused("not a head snapshot")
    for h, body in snap["heads"].items():
        if h not in HEADS:
            raise Refused("unknown head %r" % h)
        for g in body.get("gaps", []):
            if not isinstance(g, str) or not GAP.match(g):
                raise Refused("%s: a gap line is not a plain label" % h)
        for name, m in body.get("metrics", {}).items():
            if not LABEL.match(name):
                raise Refused("%s: metric name %r" % (h, name))
            _walk(m, "%s.%s" % (h, name))
    return snap


def _walk(node, path):
    if isinstance(node, dict):
        for k, v in node.items():
            if not isinstance(k, str) or not LABEL.match(k):
                raise Refused("%s: key %r is not a short fixed label" % (path, str(k)[:30]))
            _walk(v, path + "." + k)
    elif isinstance(node, list):
        if node:                                       # PHP writes an empty array as []; an empty list carries nothing, a full one could carry text
            raise Refused("%s: lists are not allowed" % path)
    elif not _num(node):
        raise Refused("%s: only numbers are allowed here" % path)


# ------------------------------------------------------------------ storage
def save(snap, day=None):
    validate(snap)
    os.makedirs(home(), exist_ok=True)
    day = day or datetime.date.today().isoformat()
    p = os.path.join(home(), day + ".json")
    tmp = p + ".%d.tmp" % os.getpid()
    with open(tmp, "w") as f:
        json.dump(snap, f)
    os.replace(tmp, p)
    return p


def history(limit=60):
    files = sorted(glob.glob(os.path.join(home(), "[0-9]*.json")))[-limit:]
    out = []
    for f in files:
        try:
            out.append((os.path.basename(f)[:-5], json.load(open(f))))
        except ValueError:
            continue
    return out


def latest():
    h = history(1)
    return h[-1] if h else (None, None)


def age_days():
    day, snap = latest()
    if not day:
        return None
    return (datetime.date.today() - datetime.date.fromisoformat(day)).days


# ------------------------------------------------------------------ pulling
def pull(runner=None):
    """Run the snapshot on production (read-only) and store it. Returns (path, snapshot). Raises Refused with the cause."""
    run = runner or (lambda cmd: subprocess.run(cmd, cwd=repo_root(), capture_output=True, text=True, timeout=600))
    p = run(["bash", "scripts/prod-run.sh", os.path.join(HERE, "heads", "snapshot.php")])
    out = (p.stdout or "") + (p.stderr or "")
    i = out.find("{")
    if p.returncode != 0 or i < 0:
        raise Refused("the snapshot did not run: " + out.strip()[-200:])
    try:
        body = json.loads(out[i:out.rfind("}") + 1])
    except ValueError:
        raise Refused("the snapshot output was not JSON: " + out.strip()[-160:])
    snap = body.get("out", body) if isinstance(body, dict) else body
    if isinstance(snap, dict) and "heads" not in snap and isinstance(snap.get("result"), dict):
        snap = snap["result"]
    return save(snap), snap


# ------------------------------------------------------------------ the few ratios
def _tot(h, name, snap):
    return ((snap["heads"].get(h) or {}).get("metrics", {}).get(name) or {}).get("total")


def _by(h, name, snap, key=None):
    m = ((snap["heads"].get(h) or {}).get("metrics", {}).get(name) or {}).get("by") or {}
    return m if key is None else m.get(key, 0)


def _share(n, d):
    return None if not d else round(100.0 * n / d, 1)


def rates(snap):
    """{head: [(label, value-text)]} — only ratios that have a denominator; a missing signal is left out, never zero-filled."""
    r = {h: [] for h in HEADS}
    shown, dealt, dism = _tot("kaname", "shown", snap), _tot("kaname", "dealt_with", snap), _tot("kaname", "dismissed", snap)
    if shown:
        r["kaname"] += [("items shown (12 weeks)", str(shown)), ("dealt with", "%s%%" % _share(dealt or 0, shown)), ("dismissed", "%s%%" % _share(dism or 0, shown))]
        sh, di = _by("kaname", "shown", snap), _by("kaname", "dismissed", snap)
        worst = sorted(((_share(di.get(k, 0), n), k) for k, n in sh.items() if n >= 5 and di.get(k, 0)), reverse=True)[:2]
        r["kaname"] += [("most dismissed kind: %s" % k, "%s%%" % s) for s, k in worst]
    e = (snap["heads"].get("sam", {}).get("metrics", {}).get("followup_edits") or {}).get("by")
    if e and sum(e.values()):
        r["sam"].append(("follow-up drafts edited before sending", "%s%% of %d" % (_share(e.get("light", 0) + e.get("heavy", 0), sum(e.values())), sum(e.values()))))
    q = _tot("sam", "quotes_accepted", snap)
    if q is not None:
        r["sam"].append(("quotes accepted (12 weeks)", str(q)))
    rs = _by("penny", "receipt_suggestions", snap)
    dec = rs.get("accepted", 0) + rs.get("edited", 0)
    if dec:
        r["penny"].append(("receipt suggestions you edited (of those decided)", "%s%% of %d" % (_share(rs.get("edited", 0), dec), dec)))
        if rs.get("pending"):
            r["penny"].append(("receipt suggestions waiting for you", str(rs["pending"])))
    bl = _by("penny", "bank_line_reviews", snap)
    bd = bl.get("accepted", 0) + bl.get("edited", 0)
    if bd:
        r["penny"].append(("bank lines you edited (of those decided)", "%s%% of %d" % (_share(bl.get("edited", 0), bd), bd)))
    sg = _by("otto", "suggestions", snap)
    if sg and sum(sg.values()):
        r["otto"].append(("suggestions accepted", "%s%% of %d" % (_share(sg.get("accepted", 0), sum(sg.values())), sum(sg.values()))))
        r["otto"].append(("dismissed or expired", "%s%%" % _share(sg.get("dismissed", 0) + sg.get("expired", 0), sum(sg.values()))))
        kinds, exp, acc = _by("otto", "suggestions_by_kind", snap), _by("otto", "expired_by_kind", snap), _by("otto", "accepted_by_kind", snap)
        worst = sorted(((_share(exp.get(k, 0), n), k, n) for k, n in kinds.items() if n >= 20 and exp.get(k, 0)), reverse=True)[:3]
        r["otto"] += [("expires unseen: %s (%d, accepted %d)" % (k, n, acc.get(k, 0)), "%s%%" % s) for s, k, n in worst]
    ms = _by("mia", "suggestions", snap)
    if ms and sum(ms.values()):
        r["mia"].append(("suggestions sent", "%s%% of %d" % (_share(ms.get("sent", 0), sum(ms.values())), sum(ms.values()))))
    oc = _by("mia", "suggestion_outcomes", snap)
    if oc and sum(oc.values()):
        r["mia"].append(("sent suggestions that led to booked, quote or reply", "%s%% of %d" % (_share(oc.get("booked", 0) + oc.get("quote", 0) + oc.get("reply", 0), sum(oc.values())), sum(oc.values()))))
    ye = (snap["heads"].get("yui", {}).get("metrics", {}).get("draft_edits") or {}).get("by")
    if ye and sum(ye.values()):
        r["yui"].append(("drafts edited before sending", "%s%% of %d" % (_share(ye.get("light", 0) + ye.get("heavy", 0), sum(ye.values())), sum(ye.values()))))
    cr = _by("ada", "cron_runs", snap)
    if cr and sum(cr.values()):
        bad = sum(v for k, v in cr.items() if k.lower() not in ("success", "ok", "completed"))
        r["ada"].append(("cron runs that did not succeed", "%s%% of %d" % (_share(bad, sum(cr.values())), sum(cr.values()))))
        for job, n in sorted(_by("ada", "cron_failures_by_job", snap).items(), key=lambda kv: -kv[1])[:3]:
            r["ada"].append(("failing job: %s" % job, str(n)))
    return r


def gaps(snap):
    return {h: list((snap["heads"].get(h) or {}).get("gaps", [])) for h in HEADS}


def trend(head, metric, weeks=8):
    """Weekly totals for one metric from the latest snapshot, oldest first (for a sparkline)."""
    day, snap = latest()
    if not snap:
        return []
    w = (((snap["heads"].get(head) or {}).get("metrics") or {}).get(metric) or {}).get("weekly") or {}
    return [w[k] for k in sorted(w)][-weeks:]


def context(service):
    """Plain words for Oracle about one service's production head, or '' when there is no snapshot. Counts only."""
    head = SERVICE_TO_HEAD.get(service)
    day, snap = latest()
    if not head or not snap:
        return ""
    lines = ["%s: %s" % (a, b) for a, b in rates(snap)[head]]
    g = gaps(snap)[head]
    if not lines and not g:
        return ""
    return "Measured on production %s (counts only, last 12 weeks):\n%s%s" % (day, "\n".join("- " + l for l in lines) or "- no usable signal yet",
                                                                                ("\nNot recorded today: " + "; ".join(g)) if g else "")
