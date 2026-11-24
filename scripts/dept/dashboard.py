"""Local dashboard for the department: ONE self-contained HTML file generated from the queue, Guardian's findings and Sentinel's reports.

Read-only on purpose. Approving, rejecting and releasing stay in a terminal (`ada approve …`), so the rule "only the owner, at a real
terminal, can approve" cannot be bypassed by a web page; the dashboard shows each decision with its exact command and a copy button.
No server, no network requests, no external fonts or scripts. Everything that came from an agent or a web page is HTML-escaped.
Written to ~/.mowology-dept/dashboard/index.html after every cycle, briefing and decision; open with `ada dashboard --open`."""
import datetime, html, importlib.util, json, os, urllib.parse

HERE = os.path.dirname(os.path.abspath(__file__))
HOME = os.path.expanduser("~")
E = html.escape


def _tokens_css():
    for p in (os.path.join(HERE, "ittai-tokens.css"), os.path.join(HERE, "..", "..", "docs", "design", "ittai-v1.2", "tokens.css")):
        if os.path.exists(p):
            return open(p).read()
    return ":root{--page:#F4F5F3;--surface:#fff;--sunk:#EEF1EE;--line:#D9DFDA;--text:#131D17;--meta:#48534C;--brand:#233A5E;--status-complete:#1A5A39;--status-attention:#6E4500;--status-blocked:#8A2418;--status-progress:#24507A;--focus:#24507A}"


def _file_url(path):
    return "file://" + urllib.parse.quote(path)


def _sentinel():
    p = next((p for p in (os.path.join(HERE, "..", "sentinel", "sentinel.py"),) if os.path.exists(p)), None)
    if not p:
        return None
    spec = importlib.util.spec_from_file_location("sentinel_dash", p)
    m = importlib.util.module_from_spec(spec); spec.loader.exec_module(m)
    return m


ICON = {"P0": "◆", "P1": "▲", "P2": "●", "P3": "○", "ok": "✓", "halt": "■", "wait": "…"}


def _cmd(text):
    return '<code>%s</code> <button type="button" class="copy" data-cmd="%s">Copy</button>' % (E(text), E(text, quote=True))


def _sentinel_controls(db, rid, interactive):
    t = db.execute("SELECT id, state FROM tasks WHERE source_ref=?", (rid,)).fetchone()
    if t and t["state"] == "awaiting_approval":
        return _controls(t["id"], ["approve", "revise", "reject"], interactive, ['ada approve %d --reason "…"' % t["id"], 'ada reject %d --reason "…"' % t["id"]])
    if t:
        return "<span class='meta'>Task #%d is %s.</span>" % (t["id"], E(t["state"].replace("_", " ")))
    return "<span class='meta'>Not in the queue yet: it is imported at the next cycle (`ada cycle`).</span>"


STATUS_WORDS = {"live": ("complete", "✓", "Built · running nightly"), "live-needs-signin": ("attention", "…", "Built · needs the CLI sign-in"), "live-partial": ("attention", "◐", "Partly built"),
                "live-assisted": ("complete", "✓", "Built · builds when you Accept"), "built-off": ("attention", "○", "Built · switched off"), "dormant": ("", "○", "Built · asleep"),
                "planned": ("", "–", "Not built yet"), "external": ("", "↗", "Separate tool"), "engine": ("complete", "✓", "Built · the engine")}


def _cards(ada, db, signed):
    ros = ada.roster()
    perms = {}
    for r in db.execute("SELECT agent, MAX(level) lv FROM permissions WHERE revoked_at IS NULL GROUP BY agent").fetchall():
        perms[r["agent"]] = r["lv"]
    LV = {1: "Level 1: observes and reports", 2: "Level 2: recommends", 3: "Level 3: prepares and builds in isolation", 4: "Level 4", 5: "Level 5"}
    last = {r["agent"]: r for r in db.execute("SELECT agent, started_at, status, note FROM runs WHERE id IN (SELECT MAX(id) FROM runs GROUP BY agent)").fetchall()}
    out = []
    for n, a in ros.items():
        st = a["status"]
        if st == "live-needs-signin" and signed:
            st = "live"
        cls, icon, word = STATUS_WORDS.get(st, ("", "?", st))
        lr = last.get(n)
        activity = ("Last run %s: %s" % ((lr["started_at"] or "")[5:16], lr["status"])) if lr else ("Builds happen after an Accept" if n == "forge" else "No runs recorded yet")
        how = ""
        if st == "planned" and a.get("build_trigger"):
            how = "<p class='meta'><b>Build it when:</b> %s</p>" % E(a["build_trigger"])
        elif a.get("wake"):
            how = "<p class='meta'><b>How:</b> %s</p>" % E(a["wake"])
        out.append("<article class='card'><h3>%s <span class='pill %s'>%s %s</span></h3><p>%s</p><p class='meta'><b>Limits:</b> %s</p>%s<p class='meta'>%s · %s</p></article>" % (
            E(n), cls, icon, E(word), E(a.get("does", a["role"])), E(a.get("limits", "")), how, E(LV.get(perms.get(n), "No permission level set yet")) if n in perms else E("Department: " + a["department"]), E(activity)))
    return "<div class='cards'>%s</div>" % "".join(out)


def _table(headers, rows, empty="Nothing."):
    if not rows:
        return '<p class="meta">%s</p>' % E(empty)
    return "<table><thead><tr>%s</tr></thead><tbody>%s</tbody></table>" % ("".join("<th>%s</th>" % E(h) for h in headers), "".join("<tr>%s</tr>" % "".join("<td>%s</td>" % c for c in r) for r in rows))


def _controls(tid, actions, interactive, cmds):
    """Served mode: a reason box + buttons (reason is required: the department learns from it). Static mode: the terminal commands."""
    if not interactive:
        return " ".join(_cmd(c) for c in cmds)
    label = {"release": "Release to production", "approve": "Accept", "reject": "Reject", "revise": "Revise", "unblock": "Unblock"}
    return ("<form class='decide' data-id='%d' onsubmit='return false'><label class='meta'>Why? (required)<br><input type='text' name='reason' maxlength='300' required placeholder='one line the department can learn from'></label> %s <span class='result meta' role='status'></span></form>"
            % (tid, " ".join("<button type='button' class='act %s' data-decision='%s'>%s</button>" % (a, a, label[a]) for a in actions)))


def render(ada, db, interactive=False):
    C, AG = ada.C, ada.AG
    now = datetime.datetime.now().astimezone()
    why = ada.halted(db)
    sen_home = os.environ.get("SENTINEL_HOME", os.path.join(HOME, ".mowology-sentinel"))
    ghome = os.environ.get("GUARDIAN_HOME", os.path.join(HOME, ".mowology-guardian"))
    q = lambda sql, *a: db.execute(sql, a).fetchall()
    S = []

    # ---- header
    signed = AG.cli_ready()
    last = ada.get_ctl(db, "cycle_finished") or "never"
    S.append('<header><h1>Ittai department</h1><p class="meta">Generated %s · last overnight cycle: %s</p><p class="pills">%s %s %s</p></header>' % (
        E(now.strftime("%Y-%m-%d %H:%M")), E(last),
        '<span class="pill %s">%s %s</span>' % ("blocked" if why else "complete", ICON["halt"] if why else ICON["ok"], E("Halted: " + why) if why else "Running"),
        '<span class="pill %s">%s Claude CLI %s</span>' % ("complete" if signed else "attention", ICON["ok"] if signed else ICON["wait"], "signed in" if signed else "not signed in: model agents idle"),
        '<span class="pill">Commercial agents: %s</span>' % ("on" if ada.get_ctl(db, "commercial_on") else "off")))

    # ---- needs you
    need = []
    for t in q("SELECT * FROM tasks WHERE state IN ('awaiting_approval','awaiting_release_approval') ORDER BY (category IN ('security','data-integrity','financial','reliability')) DESC, priority_score DESC"):
        crit = t["category"] in ada.CRITICAL_CATEGORIES
        p = json.loads(t["payload"] or "{}")
        if t["state"] == "awaiting_release_approval":
            res = json.loads(t["result"] or "{}")
            need.append("<li><b>%s Built and checked: #%d %s</b><br><span class='meta'>Branch %s · %s file(s), %s changed line(s) · not deployed</span><br><span class='meta'>%s</span><br><span class='meta'>Reviewer: %s</span><br>%s</li>" % (
                ICON["ok"], t["id"], E(t["title"]), E(res.get("branch", "?")), len(res.get("files", [])), E(str(res.get("lines", "?"))), E(" · ".join(res.get("verification", []))[:400]),
                E((res.get("review_verdict") or "")[:200].replace("\n", " ")),
                _controls(t["id"], ["release"], interactive, ['ada release %d' % t["id"]]) + "<span class='meta'> Release checks production for drift, deploys file by file through the gate, then re-measures the live page.</span>"))
            continue
        need.append("<li class='%s'><b>%s #%d %s</b> <span class='meta'>%s · %s</span><br><span class='meta'>%s</span><br>%s</li>" % (
            "crit" if crit else "", ICON["P1"] if crit else ICON["P2"], t["id"], E(t["title"]), E(t["agent"]), E(t["risk"] + " risk"),
            E((p.get("problem") or "")[:260] + ((" — " + (t["rationale"] or "")[:160]) if t["rationale"] else "")),
            _controls(t["id"], ["approve", "revise", "reject"], interactive, ['ada approve %d --reason "…"' % t["id"], 'ada reject %d --reason "…"' % t["id"]]) if t["state"] == "awaiting_approval" else "<span class='meta'>Release approval is not available here yet.</span>"))
    for t in q("SELECT * FROM tasks WHERE state IN ('approved','implementing','awaiting_verification') AND kind='improvement' ORDER BY id"):
        need.append("<li class='meta'>%s <b>#%d %s</b>: %s</li>" % (ICON["wait"], t["id"], E(t["title"]), {"approved": "accepted, build starting", "implementing": "being built now", "awaiting_verification": "being verified by Guardian"}[t["state"]]))
    for r in q("SELECT o.uid, o.business_name, e.basis FROM eligibility e JOIN opportunities o ON o.id=e.opp_id WHERE e.status='proposed' AND o.stage NOT IN ('opted_out','disqualified')"):
        need.append("<li><b>%s Confirm contact eligibility</b> %s (%s, proposed basis %s)<br>%s</li>" % (ICON["P2"], E(r["business_name"]), E(r["uid"]), E(r["basis"]), _cmd('ada confirm-eligibility %s --note "…"' % r["uid"]) + (" <span class='meta'>(a legal call: terminal only)</span>" if interactive else "")))
    for t in q("SELECT * FROM tasks WHERE state='blocked' ORDER BY id"):
        need.append("<li><b>%s Blocked #%d %s</b><br><span class='meta'>%s</span> %s</li>" % (ICON["P1"], t["id"], E(t["title"]), E((t["blocked_reason"] or "")[:300]), _controls(t["id"], ["unblock"], interactive, ['ada unblock %d --reason "…"' % t["id"]])))
    S.append("<section><h2>Needs you</h2>%s</section>" % ("<ul class='need'>%s</ul>" % "".join(need) if need else "<p class='meta'>%s Nothing is waiting for you.</p>" % ICON["ok"]))

    # ---- Guardian
    rows = q("SELECT * FROM findings WHERE status='open' ORDER BY severity, first_seen")
    cnt = {s: sum(1 for r in rows if r["severity"] == s) for s in ("P0", "P1", "P2", "P3")}
    g = ['<p>%s</p>' % " ".join('<span class="pill %s">%s %s %d</span>' % ({"P0": "blocked", "P1": "blocked", "P2": "attention", "P3": ""}[s], ICON[s], s, n) for s, n in cnt.items())]
    g.append(_table(["Severity", "Finding", "Evidence", "First seen"], [["%s %s" % (ICON[r["severity"]], E(r["severity"])), E(r["title"]), E("; ".join(json.loads(r["evidence"] or "[]"))[:200]), E((r["first_seen"] or "")[:10])] for r in rows[:25]], "No open findings (Guardian has not completed a run yet, or everything is clean)."))
    hist = os.path.join(ghome, "history.jsonl")
    if os.path.exists(hist):
        pts = [json.loads(l) for l in open(hist).read().splitlines() if l.strip()][-14:]
        pages = sorted({p for h in pts for p in h["pages"]})
        trs = []
        for p in pages:
            kb = [h["pages"][p]["transferKB"] for h in pts if p in h["pages"]]
            trs.append([E(p), "%s KB" % kb[-1], _spark(kb), E("%d run(s)" % len(kb))])
        g.append("<h3>Page weight, phone view</h3>" + _table(["Page", "Latest", "Trend", "History"], trs))
    S.append("<section><h2>Guardian <span class='meta'>measured, no model</span></h2>%s</section>" % "".join(g))

    # ---- Sentinel
    sen = _sentinel()
    s_html = []
    if sen:
        recs = sen.current_state(sen.read_ledger()) if os.path.exists(sen.LEDGER) else {}
        by = {}
        for r in recs.values():
            by[r["status"]] = by.get(r["status"], 0) + 1
        s_html.append("<p class='meta'>Decisions so far: %s</p>" % (E(", ".join("%s %d" % (k, v) for k, v in sorted(by.items()))) or "none yet"))
        days = sorted(d for d in os.listdir(os.path.join(sen_home, "reports"))) if os.path.isdir(os.path.join(sen_home, "reports")) else []
        if days:
            d = days[-1]
            rp = os.path.join(sen_home, "reports", d)
            try:
                rep = json.load(open(os.path.join(rp, "daily.json")))
            except (OSError, ValueError):
                rep = None
            if rep:
                s_html.append("<p>%s</p><p class='meta'>Report %s · <a href='%s'>full report</a> · <a href='%s'>screenshots</a></p>" % (E(rep.get("summary", "")[:420]), E(d), E(_file_url(os.path.join(rp, "daily.md"))), E(_file_url(os.path.join(rp, "shots")))))
                for r in rep.get("recommendations", []):
                    st = recs.get(r["id"], {}).get("status", "proposed")
                    s_html.append("<details><summary><b>%s</b> %s <span class='pill'>%s</span> <span class='meta'>score %s · effort %s · risk %s</span></summary><p><b>Problem.</b> %s</p><p><b>Proposal.</b> %s</p><p><b>Benefit.</b> %s</p><p><b>Acceptance.</b> %s</p>%s</details>" % (
                        E(r["id"]), E(r["title"]), E(st), E(str(r.get("priority_score"))), E(str((r.get("effort") or {}).get("size", "?"))), E((r.get("risk") or {}).get("level", "?")),
                        E(r.get("problem", "")), E(r.get("proposal", "")), E(r.get("benefit", "")), E("; ".join(r.get("acceptance", []))),
                        _sentinel_controls(db, r["id"], interactive)))
                if not rep.get("recommendations"):
                    s_html.append("<p class='meta'>No recommendation met the bar that day.</p>")
        else:
            s_html.append("<p class='meta'>No Sentinel report yet.</p>")
    S.append("<section><h2>Sentinel <span class='meta'>product opportunities</span></h2>%s</section>" % "".join(s_html))

    # ---- Commercial
    real = q("SELECT stage, COUNT(*) c FROM opportunities WHERE synthetic=0 GROUP BY stage")
    cap = {r["status"]: r["c"] for r in q("SELECT status, COUNT(*) c FROM capabilities GROUP BY status")}
    S.append("<section><h2>Commercial <span class='meta'>Apollo</span></h2><p>Pipeline: %s.</p><p>Product claims: %d verified · %d evidence found · %d unverified. Suppression list: %d. Paying customers: none recorded; revenue: unavailable.</p>%s</section>" % (
        E(", ".join("%s %d" % (r["stage"], r["c"]) for r in real) or "empty — no real prospect researched"), cap.get("verified", 0), cap.get("evidence_found", 0), cap.get("unverified", 0),
        q("SELECT COUNT(*) c FROM suppressions")[0]["c"], "<p>" + _cmd('ada verify-capability "<name>" --note "checked how"') + "</p>"))

    # ---- Team cards
    S.append("<section><h2>The team <span class='meta'>what each agent does, and whether it is built</span></h2>%s</section>" % _cards(ada, db, signed))
    S.append("<section><h2>Permissions <span class='meta'>autonomy level per task category</span></h2>%s</section>" % _table(["Agent", "Category", "Level", "Max risk", "Since", "Status"], [
        [E(r["agent"]), E(r["category"]), str(r["level"]), E(r["max_risk"]), E((r["authorised_at"] or "")[:10]), "revoked %s" % E((r["revoked_at"] or "")[:10]) if r["revoked_at"] else "active"] for r in q("SELECT * FROM permissions ORDER BY agent")]))
    S.append("<section><h2>Recent runs</h2>%s</section>" % _table(["Agent", "Started", "Status", "Seconds", "Note"], [
        [E(r["agent"]), E((r["started_at"] or "")[5:16]), E(r["status"]), E(str(r["seconds"] or "")), E((r["note"] or "")[:140])] for r in q("SELECT * FROM runs ORDER BY id DESC LIMIT 12")], "No runs yet."))
    S.append("<section><h2>Recent changes</h2>%s</section>" % _table(["When", "Task", "Move", "By", "Why"], [
        [E((r["at"] or "")[5:16]), "#%d" % r["task_id"], E("%s → %s" % (r["from_state"] or "new", r["to_state"])), E(r["actor"]), E((r["reason"] or "")[:120])] for r in q("SELECT * FROM transitions ORDER BY id DESC LIMIT 14")], "Nothing yet."))
    bdir = os.path.join(ada.DEPT, "briefings")
    files = sorted(os.listdir(bdir), reverse=True)[:10] if os.path.isdir(bdir) else []
    S.append("<section><h2>Briefings</h2><ul>%s</ul><p class='meta'>Subscription usage is not measurable from here; the run table shows runs and seconds only.</p></section>" % "".join("<li><a href='%s'>%s</a></li>" % (E(_file_url(os.path.join(bdir, f))), E(f)) for f in files))

    css = _tokens_css() + CSS
    return "<!doctype html><html lang='en'><head><meta charset='utf-8'><meta name='viewport' content='width=device-width, initial-scale=1'>%s<title>Ittai department</title><style>%s</style></head><body><main>%s</main><script>%s</script></body></html>" % ("" if interactive else "<meta http-equiv='refresh' content='300'>", css, "".join(S), JS + (ACT_JS if interactive else ""))


def _spark(vals):
    if len(vals) < 2:
        return "—"
    lo, hi = min(vals), max(vals)
    span = (hi - lo) or 1
    pts = " ".join("%d,%d" % (i * 80 // (len(vals) - 1), 18 - int((v - lo) * 16 / span)) for i, v in enumerate(vals))
    return "<svg width='84' height='20' viewBox='0 0 84 20' role='img' aria-label='trend %d to %d KB'><polyline points='%s' fill='none' stroke='currentColor' stroke-width='1.75'/></svg>" % (vals[0], vals[-1], pts)


CSS = """
*{box-sizing:border-box}body{margin:0;background:var(--page);color:var(--text);font:16px/1.55 Inter,system-ui,-apple-system,sans-serif}
main{max-width:1080px;margin:0 auto;padding:32px 20px 80px}
h1{font-size:28px;font-weight:600;margin:0 0 4px}h2{font-size:20px;font-weight:600;margin:0 0 12px}h3{font-size:16px;font-weight:600;margin:16px 0 8px}
section{background:var(--surface);border:1px solid var(--line);border-radius:12px;padding:20px 24px;margin:20px 0}
.meta{color:var(--meta);font-size:14px;font-weight:400}.pills{display:flex;flex-wrap:wrap;gap:8px;margin:12px 0 0}
.pill{display:inline-block;border:1px solid var(--line);border-radius:999px;padding:2px 12px;font-size:14px;background:var(--sunk)}
.pill.complete{color:var(--status-complete)}.pill.attention{color:var(--status-attention)}.pill.blocked{color:var(--status-blocked)}
table{width:100%;border-collapse:collapse;font-size:14px}th{text-align:left;font-weight:600;color:var(--meta);border-bottom:1px solid var(--line);padding:6px 8px}td{border-bottom:1px solid var(--line);padding:8px;vertical-align:top}
ul.need{list-style:none;padding:0;margin:0}ul.need li{padding:12px 0;border-bottom:1px solid var(--line)}ul.need li.crit{border-left:3px solid var(--status-blocked);padding-left:12px}
code{background:var(--sunk);border:1px solid var(--line);border-radius:6px;padding:2px 6px;font:13px ui-monospace,Menlo,monospace;word-break:break-all}
button.copy{min-height:44px;min-width:64px;border:1px solid var(--line);background:var(--surface);color:var(--text);border-radius:8px;font:inherit;font-size:14px;cursor:pointer}
button.act{min-height:44px;min-width:88px;border:1px solid var(--line);border-radius:8px;font:inherit;font-size:15px;cursor:pointer;background:var(--surface);color:var(--text)}
button.act.approve{background:var(--tenant);color:var(--on-tenant);border-color:var(--tenant);font-weight:600}
form.decide{display:flex;flex-wrap:wrap;gap:8px;align-items:flex-end;margin-top:8px}form.decide input{min-height:44px;min-width:280px;border:1px solid var(--line);border-radius:8px;padding:0 10px;font:inherit;background:var(--surface);color:var(--text)}
button.copy:focus-visible,button.act:focus-visible,input:focus-visible,a:focus-visible,summary:focus-visible{outline:3px solid var(--focus);outline-offset:2px}
details{border-top:1px solid var(--line);padding:10px 0}summary{cursor:pointer;min-height:44px}a{color:var(--brand)}
.cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(290px,1fr));gap:16px}
.card{border:1px solid var(--line);border-radius:12px;padding:16px;background:var(--page)}.card h3{margin:0 0 8px;font-size:17px;display:flex;flex-wrap:wrap;gap:8px;align-items:center;text-transform:capitalize}
.card p{margin:6px 0}
@media (max-width:600px){main{padding:16px 12px}section{padding:16px}}
@media (prefers-reduced-motion:no-preference){section{transition:none}}
"""
ACT_JS = """document.addEventListener('click',function(e){var b=e.target.closest('button.act');if(!b)return;var f=b.closest('form.decide');var r=f.querySelector('input[name=reason]');var out=f.querySelector('.result');
if(!r.value.trim()){out.textContent='Please add a reason.';r.focus();return;}
var d=b.getAttribute('data-decision');if(!confirm((d==='release'?'Release to production':d==='approve'?'Accept (this also starts the build)':d==='reject'?'Reject':d==='revise'?'Send back for revision':'Unblock')+' item #'+f.getAttribute('data-id')+'?'))return;
f.querySelectorAll('button').forEach(function(x){x.disabled=true});out.textContent='Saving…';
fetch('/api/decide',{method:'POST',headers:{'Content-Type':'application/json','X-Dept':'1'},credentials:'same-origin',body:JSON.stringify({id:+f.getAttribute('data-id'),decision:d,reason:r.value})})
.then(function(x){return x.json().then(function(j){return [x.ok,j]})}).then(function(a){if(a[0]){out.textContent='Done — refreshing';setTimeout(function(){location.reload()},600)}else{out.textContent=a[1].error||'Failed';f.querySelectorAll('button').forEach(function(x){x.disabled=false})}})
.catch(function(){out.textContent='Server stopped. Run: ada dashboard --serve';});});"""
JS = "document.addEventListener('click',function(e){var b=e.target.closest('button.copy');if(!b)return;var t=b.getAttribute('data-cmd');(navigator.clipboard?navigator.clipboard.writeText(t):Promise.reject()).then(function(){b.textContent='Copied'},function(){b.textContent='Select + copy';});setTimeout(function(){b.textContent='Copy'},1500)});"


def write(ada, db):  # static copy: commands only, no buttons
    out = os.path.join(ada.DEPT, "dashboard")
    os.makedirs(out, exist_ok=True)
    p = os.path.join(out, "index.html")
    tmp = p + ".tmp"
    open(tmp, "w", encoding="utf-8").write(render(ada, db))
    os.replace(tmp, p)
    return p
