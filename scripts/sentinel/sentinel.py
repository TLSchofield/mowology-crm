#!/usr/bin/env python3
"""Sentinel — Ittai's product auditor (Ada's Continuous Improvement Department).

State lives outside git in $SENTINEL_HOME (default ~/.mowology-sentinel):
  memory/            product.md, taste.md, audit-history.md  (what Sentinel knows; Tim and Sentinel both edit)
  ledger/decisions.jsonl   append-only: every proposal and every decision of Tim's
  backlog.md         generated from the ledger, never hand-edited
  reports/YYYY-MM-DD/      daily.md, daily.json, shots/

Subcommands:
  init                         create the state folders and seed memory
  context                      print what the agent is told before it audits (memory + decisions + backlog)
  ingest <raw.json> [--kind daily|weekly] [--date YYYY-MM-DD]
                               validate the agent's output, drop suppressed ideas, write the report, log proposals
  decide <id> accept|reject|modify "reason" [--note "what was changed"]
  backlog                      regenerate backlog.md
  list [--status proposed|accepted|rejected|modified]
Exit codes: 0 ok, 2 nothing valid to report, 3 bad input.
"""
import argparse, datetime, difflib, json, os, re, sys

HOME = os.path.expanduser("~")
STATE = os.environ.get("SENTINEL_HOME", os.path.join(HOME, ".mowology-sentinel"))
LEDGER = os.path.join(STATE, "ledger", "decisions.jsonl")
MAX_DAILY = 3
EFFORTS = {"S", "M", "L", "XL"}
RISKS = {"low", "medium", "high"}
AREAS = {"web", "webapp", "ios", "android", "cross-platform", "code", "seo"}
REQUIRED = ["topic", "title", "area", "problem", "evidence", "proposal", "benefit", "effort", "risk", "acceptance"]
SIMILAR = 0.82


def now():
    return datetime.datetime.now().astimezone().isoformat(timespec="seconds")


def today():
    return datetime.date.today().isoformat()


def ensure_dirs():
    for d in ("memory", "ledger", "reports"):
        os.makedirs(os.path.join(STATE, d), exist_ok=True)


# ---------------------------------------------------------------- ledger
def read_ledger():
    if not os.path.exists(LEDGER):
        return []
    rows = []
    with open(LEDGER, encoding="utf-8") as f:
        for line in f:
            line = line.strip()
            if line:
                try:
                    rows.append(json.loads(line))
                except ValueError:
                    pass  # a torn line must never stop the audit
    return rows


def append_ledger(row):
    ensure_dirs()
    with open(LEDGER, "a", encoding="utf-8") as f:
        f.write(json.dumps(row, ensure_ascii=False) + "\n")


def current_state(rows):
    """Fold the append-only ledger into one record per recommendation id (latest status wins)."""
    recs = {}
    for r in rows:
        rid = r.get("id")
        if not rid:
            continue
        if r.get("event") == "proposed":
            recs[rid] = dict(r.get("rec", {}), id=rid, status="proposed", proposed_at=r.get("at"), history=[])
        elif rid in recs and r.get("event") == "decision":
            recs[rid]["status"] = {"accept": "accepted", "reject": "rejected", "modify": "modified"}[r["decision"]]
            recs[rid]["decision_reason"] = r.get("reason", "")
            recs[rid]["decision_note"] = r.get("note", "")
            recs[rid]["decided_at"] = r.get("at")
        elif rid in recs and r.get("event") == "done":
            recs[rid]["status"] = "done"
    return recs


def norm(s):
    return re.sub(r"[^a-z0-9]+", " ", (s or "").lower()).strip()


def suppression(rec, recs):
    """Why this idea must not be shown again, or None.
    Rejected: suppressed unless new_evidence is given. Accepted/modified/proposed/done: already known —
    suppressed as a duplicate unless it cites a regression (new_evidence)."""
    new_ev = (rec.get("new_evidence") or "").strip()
    for old in recs.values():
        same_topic = norm(old.get("topic")) == norm(rec.get("topic")) and norm(rec.get("topic"))
        close = difflib.SequenceMatcher(None, norm(old.get("title")), norm(rec.get("title"))).ratio() >= SIMILAR
        if not (same_topic or close):
            continue
        st = old.get("status")
        if st == "rejected" and len(new_ev) < 40:
            return "previously rejected (%s: %s) and no new evidence given" % (old["id"], old.get("decision_reason", ""))
        if st in ("proposed", "accepted", "modified", "done") and len(new_ev) < 40:
            return "already %s as %s" % (st, old["id"])
    return None


# ------------------------------------------------------------ validation
def validate_rec(r):
    """Return a list of problems; empty means the seven required parts are all there."""
    p = []
    for k in REQUIRED:
        v = r.get(k)
        if v in (None, "", [], {}):
            p.append("missing " + k)
    if p:
        return p
    if r["area"] not in AREAS:
        p.append("area must be one of %s" % sorted(AREAS))
    if str(r["effort"].get("size") if isinstance(r["effort"], dict) else r["effort"]).upper() not in EFFORTS:
        p.append("effort.size must be S, M, L or XL")
    risk = r["risk"]
    if not isinstance(risk, dict) or str(risk.get("level", "")).lower() not in RISKS or not str(risk.get("notes", "")).strip():
        p.append("risk needs level (low|medium|high) and notes")
    if not isinstance(r["evidence"], list) or not all(isinstance(e, dict) and str(e.get("text", "")).strip() for e in r["evidence"]):
        p.append("evidence must be a list of {text, screenshot?}")
    if not isinstance(r["acceptance"], list) or not any(re.search(r"\d", str(a)) for a in r["acceptance"]):
        p.append("acceptance criteria must be measurable (include a number, threshold or count)")
    for k in ("impact", "confidence"):
        if not isinstance(r.get(k), int) or not 1 <= r[k] <= 5:
            p.append("%s must be an integer 1-5" % k)
    return p


def effort_points(r):
    return {"S": 1, "M": 2, "L": 4, "XL": 8}[str(r["effort"]["size"] if isinstance(r["effort"], dict) else r["effort"]).upper()]


def score(r):
    """Priority = impact x confidence / effort. Pure; cosmetic ideas score low because impact is honest."""
    return round(r["impact"] * r["confidence"] / effort_points(r), 2)


def next_ids(date, n, recs):
    stem = "SEN-" + date.replace("-", "")
    used = [int(m.group(1)) for i in recs for m in [re.match(re.escape(stem) + r"-(\d+)$", i)] if m]
    start = max(used) + 1 if used else 1
    return ["%s-%d" % (stem, start + i) for i in range(n)]


# ---------------------------------------------------------------- ingest
def extract_json(text):
    """The agent's answer may arrive wrapped in prose or a ```json fence. Find the object."""
    text = text.strip()
    try:
        return json.loads(text)
    except ValueError:
        pass
    m = re.search(r"```(?:json)?\s*(\{.*\})\s*```", text, re.S)
    if m:
        return json.loads(m.group(1))
    a, b = text.find("{"), text.rfind("}")
    if a >= 0 and b > a:
        return json.loads(text[a:b + 1])
    raise ValueError("no JSON object found")


def ingest(raw_text, kind="daily", date=None):
    ensure_dirs()
    date = date or today()
    data = extract_json(raw_text)
    cands = data.get("recommendations", [])
    recs = current_state(read_ledger())
    accepted, dropped = [], []
    for r in cands:
        probs = validate_rec(r)
        if probs:
            dropped.append({"title": r.get("title", "?"), "why": "; ".join(probs)})
            continue
        why = suppression(r, recs) or suppression(r, {"run%d" % n: dict(i, id="earlier in this run", status="proposed") for n, i in enumerate(accepted)})
        if why:
            dropped.append({"title": r["title"], "why": why})
            continue
        r["priority_score"] = score(r)
        accepted.append(r)
    accepted.sort(key=lambda r: -r["priority_score"])
    cap = MAX_DAILY if kind == "daily" else 10
    for r in accepted[cap:]:
        dropped.append({"title": r["title"], "why": "over the %d-per-%s cap (kept the higher scores)" % (cap, kind)})
    accepted = accepted[:cap]
    for rid, r in zip(next_ids(date, len(accepted), recs), accepted):
        r["id"] = rid
        append_ledger({"event": "proposed", "id": rid, "at": now(), "kind": kind, "rec": {k: v for k, v in r.items() if k != "id"}})
    out_dir = os.path.join(STATE, "reports", date)
    os.makedirs(os.path.join(out_dir, "shots"), exist_ok=True)
    report = {"date": date, "kind": kind, "summary": data.get("summary", ""), "checked": data.get("checked", []),
              "not_checked": data.get("not_checked", []), "recommendations": accepted, "dropped": dropped}
    with open(os.path.join(out_dir, kind + ".json"), "w", encoding="utf-8") as f:
        json.dump(report, f, indent=2, ensure_ascii=False)
    with open(os.path.join(out_dir, kind + ".md"), "w", encoding="utf-8") as f:
        f.write(render_md(report))
    with open(os.path.join(STATE, "memory", "audit-history.md"), "a", encoding="utf-8") as f:
        f.write("- %s %s: %d recommendation(s) %s; %d dropped\n" % (date, kind, len(accepted), [r["id"] for r in accepted], len(dropped)))
    write_backlog()
    return report


def render_md(rep):
    L = ["# Sentinel %s report — %s" % (rep["kind"], rep["date"]), "", rep.get("summary", ""), ""]
    if rep["checked"]:
        L += ["**Checked:** " + "; ".join(rep["checked"]), ""]
    if rep["not_checked"]:
        L += ["**Not checked (and why):** " + "; ".join(rep["not_checked"]), ""]
    if not rep["recommendations"]:
        L += ["_No recommendation met the bar today. That is a result, not a failure._", ""]
    for r in rep["recommendations"]:
        eff = r["effort"] if isinstance(r["effort"], dict) else {"size": r["effort"]}
        L += ["## %s — %s" % (r["id"], r["title"]),
              "Area: %s · Priority score %s (impact %d × confidence %d ÷ effort %d)" % (r["area"], r["priority_score"], r["impact"], r["confidence"], effort_points(r)), "",
              "**1. Problem.** " + r["problem"], "", "**2. Evidence.**"]
        for e in r["evidence"]:
            L.append("- " + e["text"] + (("  ![shot](shots/%s)" % e["screenshot"]) if e.get("screenshot") else ""))
        L += ["", "**3. Proposed improvement.** " + r["proposal"], "", "**4. Benefit.** " + r["benefit"], "",
              "**5. Effort.** %s%s" % (eff["size"], (" — " + eff["estimate"]) if eff.get("estimate") else ""), "",
              "**6. Risk (%s).** %s" % (r["risk"]["level"], r["risk"]["notes"]), "", "**7. Acceptance criteria.**"]
        L += ["- " + a for a in r["acceptance"]]
        L += ["", "Decide: `sentinel accept|reject|modify %s \"why\"`" % r["id"], ""]
    if rep["dropped"]:
        L += ["---", "_Held back (%d):_" % len(rep["dropped"])] + ["- %s — %s" % (d["title"], d["why"]) for d in rep["dropped"]]
    return "\n".join(L) + "\n"


# --------------------------------------------------------------- decisions
def decide(rid, decision, reason, note=""):
    recs = current_state(read_ledger())
    if rid not in recs:
        raise KeyError("unknown recommendation " + rid)
    if not reason.strip():
        raise ValueError("a reason is required — Sentinel learns from it")
    append_ledger({"event": "decision", "id": rid, "decision": decision, "reason": reason, "note": note, "at": now()})
    write_backlog()


def write_backlog():
    ensure_dirs()
    recs = current_state(read_ledger())
    by = lambda st: sorted([r for r in recs.values() if r["status"] in st], key=lambda r: -r.get("priority_score", 0))
    L = ["# Sentinel backlog", "", "_Generated from ledger/decisions.jsonl — do not edit. Updated %s._" % now(), ""]
    for title, st in (("Accepted / modified — to build", ("accepted", "modified")), ("Proposed — waiting for Tim", ("proposed",))):
        L += ["## " + title, ""]
        rows = by(st)
        L += ["- **%s** [%s, score %s, effort %s] %s%s" % (r["id"], r["area"], r.get("priority_score"), r["effort"]["size"] if isinstance(r["effort"], dict) else r["effort"], r["title"],
              (" — _modified: %s_" % r["decision_note"]) if r.get("decision_note") else "") for r in rows] or ["_none_"]
        L.append("")
    L += ["## Rejected (never re-proposed without new evidence)", ""]
    L += ["- %s %s — %s" % (r["id"], r["title"], r.get("decision_reason", "")) for r in by(("rejected",))] or ["_none_"]
    with open(os.path.join(STATE, "backlog.md"), "w", encoding="utf-8") as f:
        f.write("\n".join(L) + "\n")


# ----------------------------------------------------------------- context
SEED = {
    "product.md": """# Ittai product knowledge (Sentinel's memory — edit freely)
- Ittai: owner-operator software for trades businesses (Mowology, landscaping, is tenant #1; never trade-specific words in new schema or copy).
- Surfaces: ittai.ca (marketing), mowology.ca (public site), web app (CRM, signed-in, NOT audited live), native iOS (SwiftUI), native Android (Kotlin, source lives in a separate repo), crew apps.
- Design system v1.2 (KANSO): docs/design/ittai-v1.2/ — tokens.json is the source; WCAG 2.2 AAA (7:1 text, 44pt targets, nothing floats over content, Dynamic Type); symbol is frozen; indigo brand colours for all until design is done everywhere.
- Heads: Kaname (chief of staff), Sam, Otto, Penny, Mia, Yui, Ada. Ada owns technology; Sentinel reports into her Continuous Improvement Department.
- Rules Sentinel must respect: SMS plain text no URLs; no npm/Webpack in the CRM; MySQL 5.7; multi-discipline tenants.
""",
    "taste.md": """# What Tim values (Sentinel learns this from the ledger; edit freely)
- Meaningful over cosmetic. A change must help a customer finish a job faster, avoid a mistake, or buy.
- Fewer steps, plainer words, one clear action per screen.
- Parity: the same job behaves the same on web, iPhone and Android.
- Proof, not opinion: evidence with a screenshot or a measurement.
""",
    "audit-history.md": "# Audit history\n",
}


def init():
    ensure_dirs()
    for name, body in SEED.items():
        p = os.path.join(STATE, "memory", name)
        if not os.path.exists(p):
            with open(p, "w", encoding="utf-8") as f:
                f.write(body)
    write_backlog()


def context():
    recs = current_state(read_ledger())
    parts = []
    for name in ("product.md", "taste.md"):
        p = os.path.join(STATE, "memory", name)
        if os.path.exists(p):
            with open(p, encoding="utf-8") as f:
                parts.append(f.read())
    hist = os.path.join(STATE, "memory", "audit-history.md")
    if os.path.exists(hist):
        with open(hist, encoding="utf-8") as f:
            parts.append("# Recent audits\n" + "".join(f.readlines()[-15:]))
    rej = [r for r in recs.values() if r["status"] == "rejected"]
    parts.append("# Rejected — do NOT propose again unless you cite significant NEW evidence in new_evidence\n" +
                 ("\n".join("- topic=%s | %s | Tim said: %s" % (r.get("topic"), r["title"], r.get("decision_reason", "")) for r in rej) or "_none yet_"))
    known = [r for r in recs.values() if r["status"] in ("accepted", "modified", "proposed", "done")]
    parts.append("# Already known (accepted, modified or awaiting Tim) — do not repeat\n" +
                 ("\n".join("- %s topic=%s | %s [%s]%s" % (r["id"], r.get("topic"), r["title"], r["status"], (" — Tim: " + r["decision_reason"]) if r.get("decision_reason") else "") for r in known) or "_none yet_"))
    return "\n\n".join(parts)


def main(argv=None):
    ap = argparse.ArgumentParser(prog="sentinel")
    sub = ap.add_subparsers(dest="cmd", required=True)
    sub.add_parser("init"); sub.add_parser("context"); sub.add_parser("backlog")
    p = sub.add_parser("ingest"); p.add_argument("file"); p.add_argument("--kind", default="daily", choices=["daily", "weekly"]); p.add_argument("--date")
    p = sub.add_parser("decide"); p.add_argument("id"); p.add_argument("decision", choices=["accept", "reject", "modify"]); p.add_argument("reason"); p.add_argument("--note", default="")
    p = sub.add_parser("list"); p.add_argument("--status")
    a = ap.parse_args(argv)
    try:
        if a.cmd == "init":
            init(); print("ready:", STATE)
        elif a.cmd == "context":
            print(context())
        elif a.cmd == "backlog":
            write_backlog(); print(os.path.join(STATE, "backlog.md"))
        elif a.cmd == "ingest":
            rep = ingest(open(a.file, encoding="utf-8").read(), a.kind, a.date)
            print("%d recommendation(s), %d held back -> %s" % (len(rep["recommendations"]), len(rep["dropped"]), os.path.join(STATE, "reports", rep["date"], a.kind + ".md")))
            return 0 if rep["recommendations"] else 2
        elif a.cmd == "decide":
            decide(a.id, a.decision, a.reason, a.note); print("recorded", a.id, a.decision)
        elif a.cmd == "list":
            for r in sorted(current_state(read_ledger()).values(), key=lambda r: r["id"]):
                if not a.status or r["status"] == a.status:
                    print(r["id"], r["status"], r["title"])
    except (ValueError, KeyError) as e:
        print("sentinel:", e, file=sys.stderr)
        return 3
    return 0


if __name__ == "__main__":
    sys.exit(main())
