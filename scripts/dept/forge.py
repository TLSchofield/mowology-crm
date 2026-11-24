"""Forge (assisted build) — turns an ACCEPTED task into a built, checked branch. It never merges, deploys or touches production on its own.

accept -> build(): scope from the proposal -> the task brief -> scripts/codex-web-build.sh (Codex writes in a redacted copy, tests run, a second Codex
reviews; Claude's web-builder is the fallback when Codex is out of usage) -> guards (size, forbidden areas) -> the change is applied to an isolated git
worktree on its own branch and committed -> Guardian re-verifies INDEPENDENTLY (fresh checks, not Forge's word) -> awaiting_release_approval.
release() is a separate, human action: drift check against production, deploy through the existing gated scripts, OPcache reset, Guardian re-measures.
Hard limits: at most MAX_FILES files and MAX_LINES changed lines; forbidden areas (money, auth, tenant, migrations, secrets, vendor code) are refused
and left for a human-led build; one build at a time; every step is an Ada state transition by the right actor (Forge never verifies itself)."""
import datetime, json, os, re, shutil, subprocess, sys, time

HERE = os.path.dirname(os.path.abspath(__file__))
HOME = os.path.expanduser("~")
MAX_FILES, MAX_LINES, MAX_SCOPE = 8, 400, 6
FORBIDDEN = ("invoice", "ledger", "accounting", "payroll", "payment", "stripe", "quickbooks", "auth", "login", "password", "secret", "token", "tenant",
             "migration", "database/", ".sql", "app_config", "crinum", "classic.css", "corporate.css", "/js/app.js", "vendor/", "composer.", ".htaccess", "deploy", "/api/")
FILE_RX = re.compile(r"[A-Za-z0-9_\-./]+\.(?:php|js|css|html)\b")


class Stop(Exception):
    """A reason the build cannot or must not proceed (becomes the task's blocked reason)."""


def repo_root():
    return os.environ.get("ADA_REPO_ROOT", os.path.join(HOME, "Projects", "mowology-crm"))


def builds_dir():
    return os.path.join(os.environ.get("DEPT_HOME", os.path.join(HOME, ".mowology-dept")), "builds")


def run(cmd, cwd=None, timeout=600, env=None):
    p = subprocess.run(cmd, cwd=cwd, capture_output=True, text=True, timeout=timeout, env=env)
    return p.returncode, (p.stdout or "") + (p.stderr or "")


def forbidden(path):
    low = path.lower()
    return next((w for w in FORBIDDEN if w in low), None)


def scope_from(payload, repo):
    """Files the proposal names, resolved against the repo. Unresolvable or forbidden -> the build needs a human-chosen scope."""
    text = " ".join([payload.get("problem", ""), payload.get("proposal", "")] + [e.get("text", "") for e in payload.get("evidence", [])])
    found = []
    for m in FILE_RX.findall(text):
        m = m.strip("./") if m.startswith("./") else m
        cands = [m, "public/" + m]
        hit = next((c for c in cands if os.path.isfile(os.path.join(repo, c))), None)
        if not hit and "/" not in m:
            hits = subprocess.run(["git", "-C", repo, "ls-files", "--", "public/*" + m, "app/*" + m], capture_output=True, text=True).stdout.split()
            hit = hits[0] if len(hits) == 1 else None
        if hit and hit not in found:
            found.append(hit)
    if not found:
        raise Stop("the proposal names no file I can find; pick the scope by hand (`scripts/codex-web-build.sh`)")
    bad = [f for f in found if forbidden(f)]
    if bad:
        raise Stop("touches an area that needs a human-led build (%s: %s)" % (bad[0], forbidden(bad[0])))
    return found[:MAX_SCOPE]


def brief(task, payload, scope):
    ev = "\n".join("- " + e.get("text", "") for e in payload.get("evidence", []))
    return """# Build task: %s

## Problem
%s

## Evidence
%s

## What to do
%s

## Acceptance criteria (the change is done when these hold)
%s

## Rules
- Make the SMALLEST change that satisfies the acceptance criteria; do not refactor or restyle anything else.
- Only these files may change unless a new file is genuinely needed: %s
- House rules: design tokens only (no hard-coded hex), no inline <style> in CRM pages, no new dependency, new logic in a service with a test, PHP 7.4-compatible syntax, MySQL 5.7, prepared statements.
- Public pages: mobile first; tap targets at least 44px (48px on phones) and text contrast 7:1; no floating bars over content; keep the existing look and brand.
- Do not touch payments, auth, tenant isolation, migrations or secrets. If the task cannot be done within these limits, change nothing and say why.
""" % (task["title"], payload.get("problem", ""), ev or "-", payload.get("proposal", ""), "\n".join("- " + a for a in (payload.get("acceptance") or ["(none given: ask for clarification by changing nothing)"])), ", ".join(scope))


def slug(s):
    return re.sub(r"[^a-z0-9]+", "-", s.lower()).strip("-")[:30] or "change"


def diff_guard(diff_text):
    files = re.findall(r"^diff --git a/(\S+) b/", diff_text, re.M)
    lines = sum(1 for l in diff_text.splitlines() if (l.startswith("+") or l.startswith("-")) and not l.startswith(("+++", "---")))
    if not files:
        raise Stop("the builder made no change (see its summary)")
    if len(files) > MAX_FILES or lines > MAX_LINES:
        raise Stop("change too large for an assisted build (%d files, %d lines; limit %d / %d)" % (len(files), lines, MAX_FILES, MAX_LINES))
    bad = [f for f in files if forbidden(f)]
    if bad:
        raise Stop("the builder touched a forbidden area: %s" % bad[0])
    return files, lines


def make_worktree(repo, tid, title, diff_path, runner=run):
    name = "forge-%d-%s" % (tid, slug(title))
    wt = os.path.join(builds_dir(), name)
    branch = "forge/%d-%s" % (tid, slug(title))
    runner(["git", "-C", repo, "fetch", "-q", "origin", "feature/language"])
    if os.path.exists(wt):
        runner(["git", "-C", repo, "worktree", "remove", "--force", wt])
    os.makedirs(builds_dir(), exist_ok=True)
    rc, out = runner(["git", "-C", repo, "worktree", "add", "-q", "-B", branch, wt, "origin/feature/language"])
    if rc:
        raise Stop("could not create the build worktree: " + out[-200:])
    rc, out = runner(["git", "-C", wt, "apply", "--whitespace=nowarn", diff_path])
    if rc:
        raise Stop("the builder's change does not apply cleanly to feature/language (production may have drifted): " + out[-200:])
    if os.path.isdir(os.path.join(repo, "vendor")) and not os.path.exists(os.path.join(wt, "vendor")):
        os.symlink(os.path.join(repo, "vendor"), os.path.join(wt, "vendor"))
    return wt, branch


def independent_checks(wt, files):
    """Guardian's fresh verification of the committed branch: syntax, project rules, the full test suite. Returns (ok, [lines])."""
    out, ok = [], True
    for f in [f for f in files if f.endswith(".php")]:
        rc, o = run(["php", "-l", os.path.join(wt, f)])
        out.append("php -l %s: %s" % (f, "ok" if rc == 0 else "FAILED " + o[-120:])); ok &= rc == 0
    rules = os.path.join(HERE, "..", "guardian", "rules.py")
    if not os.path.exists(rules):
        rules = os.path.join(HERE, "..", "guardian", "rules.py")
    rc, o = run([sys.executable, rules, "--check", "--root", wt])
    out.append("project rules (no new violations): %s" % ("ok" if rc == 0 else "FAILED " + o[-200:])); ok &= rc == 0
    phpunit = os.path.join(wt, "vendor", "bin", "phpunit")
    if os.path.exists(phpunit):
        rc, o = run([phpunit, "--no-progress"], cwd=wt, timeout=1500)
        last = [l for l in o.splitlines() if l.startswith(("OK", "Tests:", "FAILURES"))][-1:] or [o[-120:]]
        out.append("full test suite: %s" % ("ok — " if rc == 0 else "FAILED — ") + last[0]); ok &= rc == 0
    else:
        out.append("full test suite: NOT RUN (no phpunit in the worktree)"); ok = False
    return ok, out


def build(ada, db, tid, runner=run, checker=independent_checks):
    """Accepted -> built and independently verified, or Blocked with the cause. Returns a one-line outcome."""
    C = ada.C
    t = ada.get_task(db, tid)
    if t["state"] != "approved":
        raise ada.Refused("task %d is %s, not approved" % (tid, t["state"]))
    if ada.halted(db, "forge"):
        return "not started: " + ada.halted(db, "forge")
    if not ada.permitted(db, "forge", "build-in-worktree", t["risk"]):
        ada.transition(db, tid, "blocked", "system", "Forge has no active permission for this risk level (revoked, or risk above medium)")
        return "blocked: permission"
    if db.execute("SELECT 1 FROM tasks WHERE state='implementing'").fetchone():
        return "not started: another build is running (one at a time)"
    payload = json.loads(t["payload"] or "{}")
    repo = repo_root()
    ada.transition(db, tid, "implementing", "forge", "assisted build started after your acceptance")
    db.execute("UPDATE tasks SET attempts=attempts+1 WHERE id=?", (tid,)); db.commit()
    result = {"started": ada.now()}
    try:
        runner(["git", "-C", repo, "fetch", "-q", "origin", "feature/language"])
        base = os.path.join(builds_dir(), "_base")
        os.makedirs(builds_dir(), exist_ok=True)
        if not os.path.isdir(base):
            rc, o = runner(["git", "-C", repo, "worktree", "add", "-q", "--detach", base, "origin/feature/language"])
            if rc:
                raise Stop("could not prepare the build base: " + o[-200:])
        else:
            runner(["git", "-C", base, "checkout", "-q", "--detach", "origin/feature/language"])
        if os.path.isdir(os.path.join(repo, "vendor")) and not os.path.exists(os.path.join(base, "vendor")):
            os.symlink(os.path.join(repo, "vendor"), os.path.join(base, "vendor"))      # the builder needs the test dependencies in the checkout it runs from
        scope = scope_from(payload, base)
        name = "forge-%d" % tid
        taskfile = os.path.join(builds_dir(), name + ".task.md")
        open(taskfile, "w").write(brief(t, payload, scope))
        script = os.environ.get("FORGE_BUILD_SCRIPT", os.path.join(base, "scripts", "codex-web-build.sh"))
        outdir = os.environ.get("FORGE_OUT_DIR", os.path.join(os.path.expanduser("~"), ".mowology-codex", "web-builds", name))
        runner(["bash", script, "--clean", name], cwd=base, timeout=120)          # the builder refuses to reuse an old output folder
        cmd = ["bash", script, name, taskfile, "--scope"] + scope
        for f in scope:
            cmd += ["--live", f]
        rc, out = runner(cmd, cwd=base, timeout=int(os.environ.get("FORGE_TIMEOUT", "3600")))
        result["builder_exit"] = rc
        if rc == 75:
            raise Stop("Codex is out of usage; retry later (the Claude fallback is not enabled for unattended builds)")
        if rc == 3:
            raise Stop("the builder's safety stop: " + out.strip().splitlines()[-1][:200] if out.strip() else "the builder's safety stop")
        diff = os.path.join(outdir, "change.diff")
        text = open(diff).read() if os.path.exists(diff) else ""
        files, lines = diff_guard(text)
        wt, branch = make_worktree(repo, tid, t["title"], diff, runner)
        runner(["git", "-C", wt, "add", "-A"])
        rc2, o2 = runner(["git", "-C", wt, "-c", "user.name=Ittai Forge", "-c", "user.email=forge@localhost", "commit", "-q", "-m",
                          "%s\n\nAssisted build for task #%d (accepted by the owner). Not merged, not deployed.\n\nCo-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>" % (t["title"], tid)])
        if rc2:
            raise Stop("nothing to commit after applying the change: " + o2[-120:])
        review = os.path.join(outdir, "review.md")
        result.update(branch=branch, worktree=wt, files=files, lines=lines, builder_exit=rc, summary=os.path.join(outdir, "build-summary.md"),
                      review=review if os.path.exists(review) else None, tests_ok_by_builder=(rc == 0),
                      review_verdict=(open(review).read()[:400] if os.path.exists(review) else "no review file"))
        ada.transition(db, tid, "awaiting_verification", "forge", "built on branch %s: %d file(s), %d changed line(s)" % (branch, len(files), lines))
        ok, lines_out = checker(wt, files)
        result["verification"] = lines_out
        if ok:
            ada.transition(db, tid, "verified", "guardian", "independent code checks passed: " + "; ".join(lines_out))
            ada.transition(db, tid, "awaiting_release_approval", "ada", "built, verified by code checks; live acceptance criteria are measured after release")
            msg = "built and verified: %s" % branch
        else:
            ada.transition(db, tid, "verification_failed", "guardian", "; ".join(lines_out))
            ada.transition(db, tid, "blocked", "ada", "Guardian's checks failed: " + "; ".join(l for l in lines_out if "FAILED" in l or "NOT RUN" in l)[:300])
            msg = "verification failed: " + "; ".join(lines_out)[:200]
    except Stop as e:
        ada.transition(db, tid, "blocked", "forge", str(e))
        msg = "blocked: " + str(e)
    except subprocess.TimeoutExpired:
        ada.transition(db, tid, "blocked", "forge", "the build timed out")
        msg = "blocked: timeout"
    db.execute("UPDATE tasks SET result=? WHERE id=?", (json.dumps(result), tid)); db.commit()
    nxt = db.execute("SELECT id FROM tasks WHERE state='approved' AND kind='improvement' AND attempts < max_attempts AND id != ? ORDER BY priority_score DESC, id LIMIT 1", (tid,)).fetchone()
    if nxt:                                    # builds run one at a time; the next accepted task starts when this one ends
        start_in_background(nxt["id"])
    return msg


def release(ada, db, tid, tty, runner=run):
    """The human's go-live: drift check, deploy through the gated scripts file by file, OPcache reset, then Guardian re-measures the live pages."""
    if not tty:
        raise ada.Refused("release needs you at a terminal or the authenticated dashboard")
    t = ada.get_task(db, tid)
    if t["state"] != "awaiting_release_approval":
        raise ada.Refused("task %d is %s, not awaiting release" % (tid, t["state"]))
    res = json.loads(t["result"] or "{}")
    wt, files = res.get("worktree"), res.get("files", [])
    if not wt or not os.path.isdir(wt):
        raise ada.Refused("the build worktree is gone; rebuild it")
    log = []
    for f in files:                                                    # 1. production must still match feature/language for every file we touch
        if not f.startswith(("public/", "app/")):
            raise ada.Refused("%s is not a deployable path" % f)
        rc, out = runner(["bash", "scripts/prod-pull.sh", f], cwd=wt, timeout=120)
        m = re.search(r"→ (\S+)", out)
        base = subprocess.run(["git", "-C", wt, "show", "origin/feature/language:" + f], capture_output=True).stdout if rc == 0 else b"__"
        live = open(m.group(1), "rb").read() if (rc == 0 and m and os.path.exists(m.group(1))) else None
        if os.environ.get("FORGE_SKIP_DRIFT") != "1" and live is not None and live != base:
            raise ada.Refused("production differs from feature/language for %s; sync it first (prod-pull --into-repo) and rebuild" % f)
    for f in files:                                                    # 2. deploy through the gate (merges, tests, pushes, then uploads one file)
        rc, out = runner(["bash", "scripts/deploy-file.sh", f], cwd=wt, timeout=1800)
        log.append("%s: %s" % (f, "deployed" if rc == 0 else "FAILED " + out[-200:]))
        if rc:
            ada.transition(db, tid, "blocked", "ada", "release stopped at %s: %s" % (f, out[-200:]))
            raise ada.Refused("deploy of %s failed; nothing further was uploaded: %s" % (f, out[-200:]))
    snippet = os.path.join(builds_dir(), "opcache-%d.php" % tid)       # 3. clear OPcache for the uploaded files
    open(snippet, "w").write("".join('opcache_invalidate((defined("%s") ? %s : "") . "%s", true);\n' % (("PUBLIC_ROOT", "PUBLIC_ROOT", "/" + f[7:]) if f.startswith("public/") else ("APP_ROOT", "APP_ROOT", "/" + f[4:])) for f in files) + '$out["opcache"] = "invalidated";')
    runner(["bash", "scripts/prod-run.sh", snippet, "--commit"], cwd=wt, timeout=300)
    ada.transition(db, tid, "released", "tim", "released by the owner: " + "; ".join(log))
    ada.transition(db, tid, "measuring_outcomes", "ada", "Guardian re-measures the live pages against the acceptance criteria")
    res["released"] = ada.now(); res["release_log"] = log
    db.execute("UPDATE tasks SET result=? WHERE id=?", (json.dumps(res), tid)); db.commit()
    try:
        ada.guardian_worker({"id": 0}, db)                             # 4. fresh live measurement; fixed findings close themselves
    except Exception as e:  # noqa: BLE001
        log.append("post-release measurement failed: %s" % e)
    return log


def start_in_background(tid):
    """After an accept: build in a detached process so the click returns at once. Disabled in tests via FORGE_AUTOSTART=0."""
    if os.environ.get("FORGE_AUTOSTART", "1") == "0":
        return None
    os.makedirs(builds_dir(), exist_ok=True)
    log = open(os.path.join(builds_dir(), "forge-%d.log" % tid), "a")
    return subprocess.Popen([sys.executable, os.path.join(HERE, "ada.py"), "forge-build", str(tid)], stdout=log, stderr=subprocess.STDOUT, start_new_session=True)


def sweep(ada, db, runner=run):
    """Nightly safety net: build one accepted task that never got built (a crash, or Codex was out of usage). One per cycle, at most two attempts each."""
    t = db.execute("SELECT id FROM tasks WHERE state='approved' AND kind='improvement' AND attempts < max_attempts ORDER BY priority_score DESC, id LIMIT 1").fetchone()
    return build(ada, db, t["id"], runner) if t else None
