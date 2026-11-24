#!/usr/bin/env python3
"""Guardian — deterministic verification (no model). Turns measurements into findings with severity, evidence and a stable key.

collect(): runs the rules ratchet and the rendered-page audit (page-audit.mjs). findings_from(metrics, rules_out, previous): pure, tested.
Severity: P0 security/data/financial; P1 a public page broken or unusable on a phone; P2 meaningful degradation; P3 minor/maintenance.
Only what was MEASURED is reported; every finding carries the measurement that triggered it."""
import json, os, subprocess, sys, datetime

HERE = os.path.dirname(os.path.abspath(__file__))
HOME = os.environ.get("GUARDIAN_HOME", os.path.expanduser("~/.mowology-guardian"))
THRESH = {"transfer_p2_kb": 3000, "transfer_p1_kb": 10000, "lcp_p2_ms": 2500, "cls_p2": 0.1, "regress_kb": 200, "regress_pct": 0.25, "regress_lcp_pct": 0.30}


def _worst(rows, key, hi=True):
    vals = [r for r in rows if r.get(key) is not None]
    return (max if hi else min)(vals, key=lambda r: r[key]) if vals else None


def findings_from(metrics, rules_out="", previous=None):
    """-> list of {key, severity, title, evidence[], area, fix_hint}. `previous` is the prior run's per-page summary dict."""
    F = []
    add = lambda key, sev, title, ev, area, fix: F.append({"key": key, "severity": sev, "title": title, "evidence": ev, "area": area, "fix_hint": fix})
    pages = {}
    for r in metrics.get("results", []):
        pages.setdefault(r["page"], []).append(r)
    for name, rows in pages.items():
        url = rows[0]["url"]
        bad = [r for r in rows if not r.get("ok") or (r.get("status") or 0) >= 400]
        if bad:
            st = bad[0].get("status") or bad[0].get("error", "failed to load")
            add("page-down|" + name, "P1", "%s does not load properly (%s)" % (name, st), ["%s @%dpx -> %s" % (url, bad[0]["width"], st)], "web", "Check the page and server logs.")
            continue
        ms = [dict(r["measure"], width=r["width"]) for r in rows]
        m375 = next((m for m in ms if m["width"] <= 400), ms[0])
        if any(m["overflowX"] for m in ms):
            w = next(m for m in ms if m["overflowX"])
            add("overflow-x|" + name, "P1", "%s scrolls sideways at %dpx" % (name, w["width"]), ["content width %d > viewport %d" % (w["scrollWidth"], w["innerWidth"])], "web", "Find the element wider than the viewport.")
        kb = max(m["transferKB"] for m in ms)
        if kb >= THRESH["transfer_p2_kb"]:
            add("page-weight|" + name, "P1" if kb >= THRESH["transfer_p1_kb"] else "P2", "%s transfers %.1f MB" % (name, kb / 1024), ["measured transfer %d KB (threshold %d KB)" % (kb, THRESH["transfer_p2_kb"])], "performance", "Resize/compress images, lazy-load below the fold, serve modern formats.")
        lcp = _worst(ms, "lcpMs")
        if lcp and lcp["lcpMs"] > THRESH["lcp_p2_ms"]:
            add("lcp|" + name, "P2", "%s is slow to show its main content (%.1fs)" % (name, lcp["lcpMs"] / 1000), ["LCP %d ms at %dpx (good < 2500)" % (lcp["lcpMs"], lcp["width"])], "performance", "Optimise the largest above-the-fold element.")
        cls = _worst(ms, "cls")
        if cls and cls["cls"] > THRESH["cls_p2"]:
            add("cls|" + name, "P2", "%s shifts while loading (CLS %.2f)" % (name, cls["cls"]), ["CLS %.3f at %dpx (good < 0.1)" % (cls["cls"], cls["width"])], "web", "Reserve space for images/embeds.")
        for r in rows:
            for a in r.get("axe", []):
                if a["impact"] in ("serious", "critical"):
                    add("a11y-%s|%s" % (a["id"], name), "P1" if a["impact"] == "critical" else "P2", "%s: %s (%s)" % (name, a["help"], a["impact"]), ["axe-core %s: %d element(s), e.g. %s at %dpx" % (a["id"], a["nodes"], a.get("sample", "?"), r["width"])], "accessibility", "Fix the flagged elements; house standard is WCAG 2.2 AAA contrast 7:1.")
            if r.get("consoleErrors"):
                add("console-errors|" + name, "P2", "%s logs browser errors" % name, r["consoleErrors"][:3], "web", "Fix the script errors.")
            if r.get("failedRequests"):
                add("failed-requests|" + name, "P2", "%s has failing requests" % name, r["failedRequests"][:3], "web", "Fix or remove the broken resources.")
            if r.get("brokenLinks"):
                add("broken-links|" + name, "P2", "%s links to broken pages" % name, r["brokenLinks"][:4], "web", "Fix or remove the links.")
        if m375["smallTargets"]:
            add("tap-targets|" + name, "P3", "%s has %d tap targets under 44 px on a phone" % (name, m375["smallTargets"]), ["%(what)s %(w)dx%(h)d" % s for s in m375["smallTargetSamples"][:4]], "ux", "House rule: 44 pt minimum (48 on phones).")
        if m375["tinyText"]:
            add("tiny-text|" + name, "P3", "%s has %d text runs under 12 px" % (name, m375["tinyText"]), ["%(px).0fpx: %(text)s" % s for s in m375["tinyTextSamples"][:3]], "ux", "House rule: nothing under 12 px.")
        for cond, key, sev, msg in ((m375["h1"] != 1, "h1", "P2", "%s has %d <h1> headings (should be 1)" % (name, m375["h1"])),
                                    (not m375["title"] or m375["titleLen"] > 65, "title", "P3", "%s title missing or over 65 characters" % name),
                                    (not m375["description"], "description", "P3", "%s has no meta description" % name),
                                    (not m375["lang"], "lang", "P3", "%s has no <html lang>" % name),
                                    (not m375["viewportMeta"], "viewport", "P1", "%s has no viewport meta tag" % name),
                                    (m375["imagesNoAlt"] > 0, "alt", "P2", "%s has %d images without alt" % (name, m375["imagesNoAlt"])),
                                    (m375["unlabeledInputs"] > 0, "labels", "P2", "%s has %d form fields without labels" % (name, m375["unlabeledInputs"]))):
            if cond:
                add("%s|%s" % (key, name), sev, msg, ["measured on the rendered page at 375 px"], "seo" if key in ("title", "description", "h1") else "accessibility", "")
        prev = (previous or {}).get(name)
        if prev:
            kb375, lcp375 = m375["transferKB"], m375.get("lcpMs")        # compare like with like: the summary kept for history is the phone view
            if kb375 - prev["transferKB"] > THRESH["regress_kb"] and kb375 > prev["transferKB"] * (1 + THRESH["regress_pct"]):
                add("regress-weight|" + name, "P2", "%s got heavier: %d KB -> %d KB" % (name, prev["transferKB"], kb375), ["previous run vs this run, phone view"], "performance", "Find what was added.")
            if lcp375 and prev.get("lcpMs") and lcp375 > prev["lcpMs"] * (1 + THRESH["regress_lcp_pct"]) and lcp375 > 1500:
                add("regress-lcp|" + name, "P2", "%s slower: LCP %d -> %d ms" % (name, prev["lcpMs"], lcp375), ["previous run vs this run, phone view"], "performance", "")
    for origin, s in metrics.get("site", {}).items():
        host = origin.replace("https://", "")
        if s["robotsStatus"] != 200:
            add("robots|" + host, "P2", "%s has no robots.txt" % host, ["status %s" % s["robotsStatus"]], "seo", "")
        if s["robotsBlocksAll"]:
            add("robots-blocks-all|" + host, "P0", "%s robots.txt blocks every crawler" % host, ["Disallow: / found"], "seo", "Remove the blanket Disallow.")
        if s["sitemapStatus"] != 200:
            add("sitemap|" + host, "P2", "%s has no sitemap.xml" % host, ["GET /sitemap.xml -> %s" % s["sitemapStatus"]], "seo", "Publish a sitemap and reference it in robots.txt.")
        elif not s["robotsHasSitemap"]:
            add("sitemap-ref|" + host, "P3", "%s robots.txt does not point to its sitemap" % host, [], "seo", "")
    for line in (rules_out or "").splitlines():
        if line.startswith("RULE "):
            rid = line.split()[1]
            sev = "P0" if rid == "secret" else "P2"
            add("rule|" + line.split()[2].rstrip(":"), sev, "New project-rule violation (%s) in %s" % (rid, line.split()[2].rstrip(":")), [line[:200]], "code", "Fix it; never raise the baseline.")
    order = {"P0": 0, "P1": 1, "P2": 2, "P3": 3}
    merged = {}
    for f in F:                                   # the same issue seen at three widths is ONE finding: worst severity, evidence merged
        m = merged.get(f["key"])
        if not m:
            merged[f["key"]] = f
        else:
            if order[f["severity"]] < order[m["severity"]]:
                m["severity"] = f["severity"]
            m["evidence"] = (m["evidence"] + [e for e in f["evidence"] if e not in m["evidence"]])[:5]
    return sorted(merged.values(), key=lambda f: (order[f["severity"]], f["key"]))


def page_summary(metrics):
    out = {}
    for r in metrics.get("results", []):
        if r.get("ok") and r["width"] <= 400:
            out[r["page"]] = {"transferKB": r["measure"]["transferKB"], "lcpMs": r["measure"].get("lcpMs")}
    return out


def collect(run_dir, root=None):
    """Run both checks. Returns (metrics or None, rules_output, notes)."""
    os.makedirs(run_dir, exist_ok=True)
    notes = []
    py = sys.executable
    rr = subprocess.run([py, os.path.join(HERE, "rules.py"), "--check"] + (["--root", root] if root else []), capture_output=True, text=True)
    rules_out = rr.stdout
    node = next((n for n in ("/usr/local/bin/node", os.path.expanduser("~/.claude/bin/node"), "/opt/homebrew/bin/node") if os.path.exists(n)), None)
    targets = next(p for p in (os.path.join(HERE, "targets.json"), os.path.join(HERE, "..", "sentinel", "targets.json")) if os.path.exists(p))
    metrics = None
    if os.environ.get("GUARDIAN_STANDIN_METRICS"):          # tests: use a fixture instead of launching Chrome
        return json.load(open(os.environ["GUARDIAN_STANDIN_METRICS"])), rules_out, notes
    if not node or not os.path.isdir(os.path.join(HOME, "node_modules", "playwright-core")):
        notes.append("page audit skipped: run scripts/guardian/install.sh (node/playwright-core missing)")
    else:
        p = subprocess.run([node, os.path.join(HERE, "page-audit.mjs"), run_dir, targets], capture_output=True, text=True, timeout=900, env=dict(os.environ, GUARDIAN_HOME=HOME))
        if p.returncode == 0 and os.path.exists(os.path.join(run_dir, "metrics.json")):
            metrics = json.load(open(os.path.join(run_dir, "metrics.json")))
        else:
            notes.append("page audit failed: " + (p.stderr or p.stdout)[-300:])
    return metrics, rules_out, notes


def latest_previous():
    p = os.path.join(HOME, "history.jsonl")
    if not os.path.exists(p):
        return None
    lines = [l for l in open(p).read().splitlines() if l.strip()]
    return json.loads(lines[-1])["pages"] if lines else None


def record_history(metrics):
    os.makedirs(HOME, exist_ok=True)
    with open(os.path.join(HOME, "history.jsonl"), "a") as f:
        f.write(json.dumps({"at": datetime.datetime.now().astimezone().isoformat(timespec="seconds"), "pages": page_summary(metrics)}) + "\n")
