#!/usr/bin/env python3
"""
scripts/parity/parity.py — keep the native Android crew app level with the iPhone app (Tim, 2026-11-20).

iOS is the spec. Every user-visible iOS change gets an Android half. This tool does the mechanical part daily:

  detect   list user-visible iOS commits on origin/feature/language not yet in the ledger
  queue    hand the oldest new gaps to scripts/codex-build.sh (Codex WRITES Android on codex/native; never ships)
  shots    capture the same screen on the iPhone simulator and an Android device, write a side-by-side PNG
  report   write ~/.mowology-parity/reports/<date>.md and (if anything needs Tim) a macOS notification
  status   print the ledger summary
  mark     mark <sha> <status> [note]     (done | skipped | new | blocked ...) — Claude/Tim use this after review

Ledger: ~/.mowology-parity/ledger.json (outside the repo on purpose: the nightly checkout is reset each night).
Statuses: new → ported-unreviewed (Codex built it on codex/native) → done (Claude reviewed + released + Tim agreed)
          deferred (Codex out of usage, retried next day) · blocked (needs a human) · skipped (not user-visible / reverted)
NOTHING here merges, releases, or runs android-field-release.sh. Claude reviews the codex/native diff first.
"""
import argparse, json, os, re, struct, subprocess, sys, time, zlib, datetime, shutil

HOME = os.path.expanduser("~")
STATE = os.environ.get("PARITY_HOME", os.path.join(HOME, ".mowology-parity"))
LEDGER = os.path.join(STATE, "ledger.json")
SRC = os.environ.get("PARITY_SRC", os.path.join(HOME, ".mowology-codex", "src"))  # nightly checkout of feature/language
BRANCH = "origin/feature/language"
SINCE = os.environ.get("PARITY_SINCE", "2026-11-14")  # the day the rule "design reaches Android in the same job" began
IOS_SWIFT = "ios/MowologyCRM/MowologyCRM"
MAX_PORTS = int(os.environ.get("PARITY_MAX", "2"))
ADB = os.environ.get("ADB", os.path.join(HOME, "Library/Android/sdk/platform-tools/adb"))
IOS_BUNDLE = "ca.mowology.crm-field"
ANDROID_PKG = os.environ.get("PARITY_ANDROID_PKG", "ca.mowology.crew.test")  # the local-tenant debug build the emulator runs
VISIBLE = re.compile(r"^(feat|fix|perf)(\(|:|!)", re.I)


def sh(args, cwd=None, check=False, timeout=None):
    r = subprocess.run(args, cwd=cwd, capture_output=True, text=True, timeout=timeout)
    if check and r.returncode:
        raise RuntimeError(f"{args[0]} failed: {r.stderr.strip()[:300]}")
    return r


def load():
    os.makedirs(STATE, exist_ok=True)
    if os.path.exists(LEDGER):
        return json.load(open(LEDGER))
    return {"rows": {}}


def save(led):
    tmp = LEDGER + ".tmp"
    json.dump(led, open(tmp, "w"), indent=1, sort_keys=True)
    os.replace(tmp, LEDGER)


def today():
    return datetime.date.today().isoformat()


# ── detect ──────────────────────────────────────────────────────────────────
def cmd_detect(a):
    led = load()
    if not os.path.isdir(SRC):
        print(f"no checkout at {SRC} — run scripts/codex-schedule/install.sh first", file=sys.stderr); return 2
    if sh(["git", "fetch", "-q", "origin", "feature/language"], cwd=SRC).returncode:
        print("git fetch failed — using the last fetched code")
    out = sh(["git", "log", BRANCH, "--no-merges", f"--since={SINCE}", "--reverse",
              "--format=%H%x09%cs%x09%s", "--", IOS_SWIFT], cwd=SRC, check=True).stdout
    added, reverted = [], []
    for line in out.splitlines():
        sha, date, subj = line.split("\t", 2)
        if sha in led["rows"]:
            continue
        files = sh(["git", "show", "--name-only", "--format=", sha, "--", IOS_SWIFT], cwd=SRC).stdout.split()
        swift = [f for f in files if f.endswith(".swift")]
        row = {"date": date, "subject": subj, "files": len(swift), "status": "new", "note": ""}
        m = re.match(r'^Revert "(.*)"$', subj)
        if m:
            row.update(status="skipped", note="revert")
            for r in led["rows"].values():
                if r["subject"] == m.group(1) and r["status"] in ("new", "deferred"):
                    r.update(status="skipped", note="reverted later")
            reverted.append(sha)
        elif not VISIBLE.match(subj):
            row.update(status="skipped", note="not feat/fix/perf")
        elif not swift:
            row.update(status="skipped", note="no Swift change")
        elif any(w in subj.lower() for w in ("diag(", "debug", "testflight", "xcode cloud", "build number")):
            row.update(status="skipped", note="not user-visible")
        led["rows"][sha] = row
        if row["status"] == "new":
            added.append(sha)
    save(led)
    print(f"detect: {len(added)} new user-visible iOS change(s); {len(led['rows'])} in the ledger")
    for s in added:
        print(f"  {s[:8]} {led['rows'][s]['date']} {led['rows'][s]['subject']}")
    return 0


# ── queue ───────────────────────────────────────────────────────────────────
TASK = """# Parity port: bring the Android crew app level with the iPhone app

The iPhone app is the spec. This iPhone change is live (commit {sha8}, {date}):

    {subject}

Port it to the native Android app so that it is functionally AND visually as close as Android allows:
- Same behaviour, same wording, same order of elements, same empty/error states, same offline behaviour.
- Same colours, spacing, type and icon weights: use the existing Ittai tokens (ui/ittai/*, ItTokens) — never hard-code a colour.
- Where the iPhone change needs a server field or endpoint, read it from .codex-inputs; if the server does not provide it, say so in
  your final summary instead of inventing one.
- If this iOS change is already present on Android, change nothing and say so.
- Put "parity: {sha8}" at the end of your commit summary.

The iOS diff for this change follows (Swift views are the spec; translate to Compose/Kotlin idioms, do not transliterate).

```diff
{diff}
```
"""


def cmd_queue(a):
    led = load()
    codex_build = os.path.join(SRC, "scripts", "codex-build.sh")
    if not os.path.exists(codex_build):
        print(f"no {codex_build}", file=sys.stderr); return 2
    limit = os.path.join(HOME, ".mowology-codex", "LIMIT")
    if os.path.exists(limit) and time.time() - os.path.getmtime(limit) < 3600 and not os.environ.get("CODEX_IGNORE_LIMIT"):
        print("queue: Codex limit note is under an hour old — nothing queued today")
        for r in led["rows"].values():
            if r["status"] == "new":
                r.update(status="deferred", note="Codex out of usage " + today())
        save(led); return 0
    todo = sorted([s for s, r in led["rows"].items() if r["status"] in ("new", "deferred")], key=lambda s: led["rows"][s]["date"])
    os.makedirs(os.path.join(STATE, "tasks"), exist_ok=True)
    ran = 0
    for sha in todo:
        if ran >= MAX_PORTS:
            break
        row = led["rows"][sha]
        diff = sh(["git", "show", "--format=", sha, "--", IOS_SWIFT], cwd=SRC).stdout.splitlines()
        diff = "\n".join(diff[:1500] + (["... (diff cut at 1500 lines — read the rest from the iPhone code in the task's reference)"] if len(diff) > 1500 else []))
        tpath = os.path.join(STATE, "tasks", sha[:8] + ".md")
        open(tpath, "w").write(TASK.format(sha8=sha[:8], date=row["date"], subject=row["subject"], diff=diff))
        print(f"queue: porting {sha[:8]} {row['subject']}")
        r = sh(["bash", codex_build, "parity-" + sha[:8], tpath], cwd=SRC)
        row["run"] = today()
        tail = (r.stdout + r.stderr).strip().splitlines()[-3:]
        if r.returncode == 0:
            head = sh(["git", "-C", os.path.join(HOME, "Projects/mowology-android-codex"), "rev-parse", "--short", "HEAD"]).stdout.strip()
            row.update(status="ported-unreviewed", note=f"codex/native {head}")
            ran += 1
        elif r.returncode == 75:
            row.update(status="deferred", note="Codex out of usage " + today())
            print("queue: Codex out of usage — stopping"); save(led); return 0
        elif r.returncode == 2:
            row.update(status="blocked", note="tests failing; left uncommitted in the codex worktree — Claude must look")
            print("queue: stopping — the codex worktree is dirty"); save(led); return 1
        else:
            row.update(status="deferred", note=f"codex-build exit {r.returncode}: {' | '.join(tail)[:200]}")
            print(f"queue: codex-build exit {r.returncode} — stopping"); save(led); return 1
        save(led)
    print(f"queue: {ran} port(s) built, waiting for Claude's review on codex/native")
    return 0


# ── screenshots ─────────────────────────────────────────────────────────────
def read_png(path):
    b = open(path, "rb").read()
    assert b[:8] == b"\x89PNG\r\n\x1a\n", "not a PNG"
    pos, idat, w = 8, b"", 0
    while pos < len(b):
        n, t = struct.unpack(">I4s", b[pos:pos + 8]); d = b[pos + 8:pos + 8 + n]; pos += 12 + n
        if t == b"IHDR":
            w, h, depth, ctype, _, _, interlace = struct.unpack(">IIBBBBB", d)
        elif t == b"IDAT":
            idat += d
    assert depth == 8 and ctype in (2, 6) and interlace == 0, "unsupported PNG"
    bpp = 3 if ctype == 2 else 4
    raw = zlib.decompress(idat); stride = w * bpp; rows = []; prev = bytearray(stride); i = 0
    for _ in range(h):
        f = raw[i]; line = bytearray(raw[i + 1:i + 1 + stride]); i += 1 + stride
        for x in range(stride):
            a = line[x - bpp] if x >= bpp else 0; up = prev[x]; c = prev[x - bpp] if x >= bpp else 0
            if f == 1: line[x] = (line[x] + a) & 255
            elif f == 2: line[x] = (line[x] + up) & 255
            elif f == 3: line[x] = (line[x] + ((a + up) >> 1)) & 255
            elif f == 4:
                p = a + up - c; pa, pb, pc = abs(p - a), abs(p - up), abs(p - c)
                line[x] = (line[x] + (a if pa <= pb and pa <= pc else up if pb <= pc else c)) & 255
        rows.append(bytes(line)); prev = line
    return w, h, bpp, rows


def px(img, x, y):
    w, h, bpp, rows = img; o = x * bpp; r = rows[y]; return r[o], r[o + 1], r[o + 2]


def write_png(path, w, h, rows):
    def chunk(t, d): return struct.pack(">I", len(d)) + t + d + struct.pack(">I", zlib.crc32(t + d) & 0xffffffff)
    raw = b"".join(b"\x00" + r for r in rows)
    open(path, "wb").write(b"\x89PNG\r\n\x1a\n" + chunk(b"IHDR", struct.pack(">IIBBBBB", w, h, 8, 2, 0, 0, 0)) + chunk(b"IDAT", zlib.compress(raw, 6)) + chunk(b"IEND", b""))


def scaled_rows(img, th):
    w, h = img[0], img[1]; tw = max(1, round(w * th / h)); out = []
    for y in range(th):
        sy = min(h - 1, y * h // th); row = bytearray()
        for x in range(tw):
            row += bytes(px(img, min(w - 1, x * w // tw), sy))
        out.append(bytes(row))
    return tw, out


def side_by_side(ios_png, and_png, out_png):
    a, b = read_png(ios_png), read_png(and_png); th = 1000
    aw, ar = scaled_rows(a, th); bw, br = scaled_rows(b, th); gap = 24
    rows = [ar[y] + b"\xff\x00\xff" * gap + br[y] for y in range(th)]
    write_png(out_png, aw + gap + bw, th, rows)
    # rough layout score: 20x40 luminance grid; 0 = identical layout of light/dark areas, 1 = opposite
    def grid(img):
        g = []
        for gy in range(40):
            for gx in range(20):
                x, y = min(img[0] - 1, int((gx + .5) * img[0] / 20)), min(img[1] - 1, int((gy + .5) * img[1] / 40)); r, gg, bb = px(img, x, y)
                g.append((0.3 * r + 0.59 * gg + 0.11 * bb) / 255)
        return g
    ga, gb = grid(a), grid(b)
    return sum(abs(x - y) for x, y in zip(ga, gb)) / len(ga)


def dismiss_android_overlays():
    """Kaname's once-a-day opening card covers every screen on a fresh launch; tap it away when (and only when) it is showing."""
    for text in ("Start the day",):
        sh([ADB, "shell", "uiautomator", "dump", "/sdcard/u.xml"])
        xml = sh([ADB, "shell", "cat", "/sdcard/u.xml"]).stdout
        m = re.search(r'text="' + re.escape(text) + r'"[^>]*?bounds="\[(\d+),(\d+)\]\[(\d+),(\d+)\]"', xml)
        if m:
            a, b, c, d = map(int, m.groups())
            sh([ADB, "shell", "input", "tap", str((a + c) // 2), str((b + d) // 2)])
            time.sleep(4)


def cmd_shots(a):
    manifest = json.load(open(os.path.join(os.path.dirname(os.path.abspath(__file__)), "screens.json")))
    outdir = os.path.join(STATE, "shots", today()); os.makedirs(outdir, exist_ok=True)
    have_and = os.path.exists(ADB) and "device" in sh([ADB, "devices"]).stdout.replace("List of devices attached", "")
    booted = "(Booted)" in sh(["xcrun", "simctl", "list", "devices"]).stdout
    if not have_and:
        print("shots: no Android device/emulator running — start one with scripts/local-android.sh up (PARITY_START_ANDROID=1 does this in the daily run)")
    if not booted:
        print("shots: no iPhone simulator booted — xcrun simctl boot 'iPhone 15'")
    res = []
    for s in manifest["screens"]:
        n = s["name"]; ip, ap = f"{outdir}/{n}-ios.png", f"{outdir}/{n}-android.png"
        try:
            if booted:
                sh(["xcrun", "simctl", "terminate", "booted", IOS_BUNDLE]); sh(["xcrun", "simctl", "launch", "booted", IOS_BUNDLE] + s.get("ios_args", []))
                time.sleep(s.get("wait", 6)); sh(["xcrun", "simctl", "io", "booted", "screenshot", ip], check=True)
            if have_and:
                sh([ADB, "shell", "am", "force-stop", ANDROID_PKG]); sh([ADB, "shell"] + s.get("android_start", f"monkey -p {{pkg}} -c android.intent.category.LAUNCHER 1").replace("{pkg}", ANDROID_PKG).split())
                time.sleep(s.get("wait", 6)); dismiss_android_overlays()
                with open(ap, "wb") as f:
                    f.write(subprocess.run([ADB, "exec-out", "screencap", "-p"], capture_output=True).stdout)
            if booted and have_and:
                score = side_by_side(ip, ap, f"{outdir}/{n}-pair.png"); res.append((n, f"{outdir}/{n}-pair.png", round(score, 3)))
        except Exception as e:
            res.append((n, None, f"failed: {e}"))
    json.dump(res, open(os.path.join(outdir, "result.json"), "w"))
    for r in res:
        print("shots:", *r)
    return 0


# ── report / status / mark ──────────────────────────────────────────────────
def summary(led):
    c = {}
    for r in led["rows"].values():
        c[r["status"]] = c.get(r["status"], 0) + 1
    return c


def cmd_status(a):
    led = load(); print(summary(led))
    for s, r in sorted(led["rows"].items(), key=lambda kv: kv[1]["date"]):
        if r["status"] != "skipped":
            print(f"  {r['status']:<17} {s[:8]} {r['date']} {r['subject'][:80]} {('— ' + r['note']) if r['note'] else ''}")
    return 0


def cmd_report(a):
    led = load(); os.makedirs(os.path.join(STATE, "reports"), exist_ok=True)
    p = os.path.join(STATE, "reports", today() + ".md"); c = summary(led)
    lines = [f"# Android/iOS parity — {today()}", "", "Counts: " + ", ".join(f"{k} {v}" for k, v in sorted(c.items())), ""]
    for st, head in (("ported-unreviewed", "Built by Codex — Claude must review codex/native before release"),
                     ("blocked", "Blocked — needs a human"), ("deferred", "Waiting (Codex out of usage or retry)"), ("new", "New gaps not yet queued")):
        rows = [(s, r) for s, r in led["rows"].items() if r["status"] == st]
        if rows:
            lines += [f"## {head}", ""] + [f"- `{s[:8]}` {r['date']} {r['subject']}" + (f" — {r['note']}" if r["note"] else "") for s, r in sorted(rows, key=lambda x: x[1]["date"])] + [""]
    sd = os.path.join(STATE, "shots", today(), "result.json")
    if os.path.exists(sd):
        lines += ["## Side-by-side screens (rough layout difference, 0 = same, higher = further apart)", ""]
        for n, path, score in json.load(open(sd)):
            lines.append(f"- {n}: {path or 'no image'} — {score}")
        lines.append("")
    open(p, "w").write("\n".join(lines))
    print("report:", p)
    need = c.get("ported-unreviewed", 0) + c.get("blocked", 0)
    if need and sys.platform == "darwin":
        sh(["osascript", "-e", f'display notification "{need} parity item(s) need review — see {p}" with title "Android/iOS parity"'])
    return 0


def cmd_mark(a):
    led = load(); hits = [s for s in led["rows"] if s.startswith(a.sha)]
    if len(hits) != 1:
        print("no single match for", a.sha); return 2
    led["rows"][hits[0]].update(status=a.status, note=a.note or led["rows"][hits[0]]["note"]); save(led); print("ok", hits[0][:8], a.status); return 0


def main():
    p = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    sp = p.add_subparsers(dest="cmd", required=True)
    for n, f in (("detect", cmd_detect), ("queue", cmd_queue), ("shots", cmd_shots), ("report", cmd_report), ("status", cmd_status)):
        sp.add_parser(n).set_defaults(f=f)
    m = sp.add_parser("mark"); m.add_argument("sha"); m.add_argument("status"); m.add_argument("note", nargs="?"); m.set_defaults(f=cmd_mark)
    a = p.parse_args(); sys.exit(a.f(a))


if __name__ == "__main__":
    main()
