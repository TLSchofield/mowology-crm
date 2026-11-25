"""Forge ship — what happens between "built and checked" and "live and confirmed", so an Accept carries a change all the way through.

Owner decision 2026-11-24: Accept = build, test, review, deploy, confirm live. Nothing here weakens the earlier limits (size, forbidden areas, one
build at a time, drift check, the feature-gate deploy scripts); it adds:
  * hold_reason(): changes that stay for the owner's own Release click (routing, bootstrap, session and config files);
  * review(): an independent read-only review of the diff; any real defect holds the change;
  * capture(): before/after screenshots and measured numbers of the affected public pages (Guardian's page audit, no model);
  * live_check(): the live page must load fully and the numbers must not get worse; a failure puts the old files back (rollback()).
The pictures and numbers are stored under ~/.mowology-dept/visuals/<task>/<before|after>/ and shown on the dashboard.
Every outcome is recorded on the task, so the dashboard can say exactly what was checked."""
import json, os, re, shutil, subprocess, sys

HERE = os.path.dirname(os.path.abspath(__file__))
BASE_URL = os.environ.get("FORGE_LIVE_BASE", "https://mowology.ca")
VIEWPORTS = [[375, 812], [1440, 900]]
# Held for the owner's own click even when everything passes: a mistake here breaks many pages at once.
HOLD = ("cms-render", "bootstrap.php", "session_config", "/router", "routes.php", "config.php", "app_config", "robots.txt", "sitemap")
SHOT_NAME = re.compile(r"^[A-Za-z0-9._-]+\.png$")


def visuals_root():
    return os.environ.get("FORGE_VISUALS_DIR") or os.path.join(os.environ.get("DEPT_HOME", os.path.expanduser("~/.mowology-dept")), "visuals")


def hold_reason(files):
    for f in files:
        low = f.lower()
        w = next((h for h in HOLD if h in low), None)
        if w:
            return "%s changes how many pages are served (%s): you press Release yourself for this one" % (f, w.strip("/"))
    return None


def page_urls(files, payload=None):
    """The public pages a change can show on. A page file is its own URL; a shared include (header, footer, CSS, JS) is seen on home and contact."""
    urls = []
    for f in files:
        m = re.match(r"^public/([a-z0-9_-]+)\.php$", f)
        if m and m.group(1) not in ("cms-render", "index"):
            urls.append(("/" + m.group(1)))
        elif f.startswith("public/") and not f.startswith(("public/crm/", "public/api/", "public/jobFlow/")):
            urls += ["/", "/contact"]
    for e in (payload or {}).get("evidence", []):
        urls += re.findall(r"https?://(?:www\.)?mowology\.ca(/[A-Za-z0-9_\-./]*)", e.get("text", ""))
    out, seen = [], set()
    for u in urls:
        if u not in seen:
            seen.add(u); out.append(u)
    return [{"name": (u.strip("/") or "home"), "url": BASE_URL + u} for u in out[:4]]


# ---------------------------------------------------------------- capture
def capture(tid, phase, pages, runner=None):
    """Measure the pages in a browser (Guardian's page audit). Returns {phase, pages:[...]} or {error}. Never raises: a missing browser must not stop a deploy decision, it is reported."""
    if os.environ.get("FORGE_VISUALS", "1") == "0" or not pages:
        return {"phase": phase, "skipped": True, "pages": []}
    out = os.path.join(visuals_root(), str(tid), phase)
    shutil.rmtree(out, ignore_errors=True)
    os.makedirs(out, exist_ok=True)
    targets = os.path.join(out, "targets.json")
    json.dump({"web": pages, "viewports": VIEWPORTS}, open(targets, "w"))
    script = os.path.join(HERE, "..", "guardian", "page-audit.mjs")
    cmd = ["node", script, out, targets]
    try:
        if runner is None:
            subprocess.run(cmd, capture_output=True, text=True, timeout=600)
        else:
            runner(cmd, timeout=600)
    except Exception as e:  # noqa: BLE001
        return {"phase": phase, "error": str(e)[:200], "pages": []}
    mp = os.path.join(out, "metrics.json")
    if not os.path.exists(mp):
        return {"phase": phase, "error": "the page audit produced no measurements", "pages": []}
    return summarise(phase, json.load(open(mp)))


def summarise(phase, metrics):
    rows = []
    for r in metrics.get("results", []):
        m = r.get("measure") or {}
        contrast = []
        for a in r.get("axe", []) or []:
            if a.get("id") == "color-contrast":
                contrast = (a.get("details") or [])[:4]
        rows.append({"page": r.get("page"), "url": r.get("url"), "width": r.get("width"), "ok": bool(r.get("ok")), "status": r.get("status"),
                     "shot": os.path.basename(r.get("screenshot") or ""), "transfer_kb": m.get("transferKB"), "cls": m.get("cls"),
                     "small_targets": m.get("smallTargets"), "tiny_text": m.get("tinyText"), "dom_nodes": m.get("domNodes"),
                     "console_errors": len(r.get("consoleErrors") or []), "failed_requests": len(r.get("failedRequests") or []),
                     "axe_serious": sum(1 for a in (r.get("axe") or []) if a.get("impact") in ("serious", "critical")), "contrast": contrast, "error": r.get("error")})
    return {"phase": phase, "pages": rows}


def compare(before, after):
    """Rows for the dashboard: the same page at the same width, before against after."""
    b = {(r["page"], r["width"]): r for r in (before or {}).get("pages", [])}
    rows = []
    for r in (after or {}).get("pages", []):
        o = b.get((r["page"], r["width"]))
        if not o:
            continue
        rows.append({"page": r["page"], "width": r["width"], "before": {k: o.get(k) for k in ("transfer_kb", "cls", "small_targets", "tiny_text", "axe_serious", "shot")},
                     "after": {k: r.get(k) for k in ("transfer_kb", "cls", "small_targets", "tiny_text", "axe_serious", "shot")}, "contrast_before": o.get("contrast") or [], "contrast_after": r.get("contrast") or []})
    return rows


def live_check(before, after):
    """(ok, [lines]): the live page loads fully, and nothing measurable got worse. A page that was already broken before is not blamed on this change."""
    lines, ok = [], True
    if (after or {}).get("skipped"):
        return True, ["live page check: skipped (visuals off)"]
    if (after or {}).get("error") or not (after or {}).get("pages"):
        return False, ["live page check FAILED: " + ((after or {}).get("error") or "no page could be measured")]
    b = {(r["page"], r["width"]): r for r in (before or {}).get("pages", [])}
    for r in after["pages"]:
        label = "%s at %spx" % (r["page"], r["width"])
        if not r["ok"] or r.get("status") != 200 or (r.get("dom_nodes") or 0) < 40:
            lines.append("%s: FAILED to load fully (status %s, %s elements)%s" % (label, r.get("status"), r.get("dom_nodes"), (": " + r["error"]) if r.get("error") else ""))
            ok = False
            continue
        o = b.get((r["page"], r["width"]))
        if o and o.get("ok"):
            if (r.get("console_errors") or 0) > (o.get("console_errors") or 0) or (r.get("failed_requests") or 0) > (o.get("failed_requests") or 0):
                lines.append("%s: new browser errors or failed requests after the change" % label); ok = False
            if (o.get("transfer_kb") or 0) and (r.get("transfer_kb") or 0) > 1.5 * o["transfer_kb"] + 50:
                lines.append("%s: page got much heavier (%s KB -> %s KB)" % (label, o["transfer_kb"], r["transfer_kb"])); ok = False
    if ok:
        lines.append("live pages load fully and nothing measured got worse (%d page view(s))" % len(after["pages"]))
    return ok, lines


# ---------------------------------------------------------------- review
REVIEW_PROMPT = """You are an independent, read-only code reviewer for a PHP/JS/CSS website change that will go live automatically if you find nothing wrong.
The change below is the diff against production's branch. Read the changed files and the code around them if you need to.
Report ONLY real defects the change introduces: wrong behaviour, broken links, escaped-output or security problems, anything that can white-screen a page,
anything that makes a page worse for a phone user. Do not report style, taste or things that were already wrong before this change.
Reply with ONLY this JSON: {"defects":[{"file":"...","line":0,"what":"...","why_real":"..."}],"summary":"one sentence"}. An empty list means you found none.

TASK THE CHANGE WAS FOR:
%s

DIFF:
%s
"""


def claude_review(wt, title, diff_text):
    """Independent review by a read-only Claude run (Codex's own review of the build, when it exists, is passed in the diff notes). Returns (ok, summary, defects)."""
    import agents as AG
    if not AG.cli_ready():
        return None, "no reviewer is available (the Claude CLI is not signed in)", []
    cmd = [AG.cli_binary(), "-p", REVIEW_PROMPT % (title, diff_text[:40000]), "--model", "sonnet", "--max-budget-usd", "1.5", "--no-session-persistence",
           "--output-format", "json", "--strict-mcp-config", "--permission-mode", "dontAsk", "--allowedTools", "Read", "Grep", "Glob",
           "--disallowedTools", "Bash", "Edit", "Write", "WebFetch", "WebSearch"]
    p = subprocess.run(cmd, cwd=wt, capture_output=True, text=True, timeout=900)
    env = json.loads(p.stdout or "{}")
    if p.returncode != 0 or env.get("is_error"):
        return None, "the reviewer failed: " + str(env.get("result") or p.stderr)[:150], []
    out = AG.parse_json(env.get("result", ""))
    defects = [d for d in out.get("defects", []) if isinstance(d, dict) and d.get("what")]
    return (not defects), str(out.get("summary", ""))[:300], defects


def review(wt, title, files, runner, reviewer=None):
    rc, diff = runner(["git", "-C", wt, "diff", "origin/feature/language...HEAD", "--"] + list(files), timeout=120)
    try:
        ok, summary, defects = (reviewer or claude_review)(wt, title, diff)
    except Exception as e:  # noqa: BLE001
        return {"ok": None, "summary": "the review could not run: %s" % str(e)[:150], "defects": []}
    return {"ok": ok, "summary": summary, "defects": defects}


# ---------------------------------------------------------------- rollback
def rollback(wt, files, olds, runner, log):
    """Put the pre-deploy contents back through the same gated deploy script. A file that did not exist before cannot be removed automatically."""
    manual = []
    for f in files:
        old = olds.get(f)
        if old is None:
            manual.append(f); continue
        open(os.path.join(wt, f), "wb").write(old)
    runner(["git", "-C", wt, "add", "-A"])
    runner(["git", "-C", wt, "-c", "user.name=Ittai Forge", "-c", "user.email=forge@localhost", "commit", "-q", "-m", "revert: the live check failed after release, restoring the previous files"])
    for f in files:
        if f in manual:
            continue
        rc, out = runner(["bash", "scripts/deploy-file.sh", f], cwd=wt, timeout=1800)
        log.append("rollback %s: %s" % (f, "restored" if rc == 0 else "FAILED " + out[-150:]))
    for f in manual:
        log.append("rollback %s: NEW file left live, remove it by hand" % f)
    return not manual
