#!/usr/bin/python3
"""
Mowology messages bridge — texts from customers -> CRM (read-only).

Runs on Tim's Mac (launchd, every 5 minutes). Reads the Messages database READ-ONLY,
keeps only ONE-TO-ONE conversations, and sends to the CRM only the messages exchanged
with a known customer. Matching never exposes a phone number: the CRM hands out salted
SHA-256 hashes of its customers' numbers, the bridge hashes each conversation's number
the same way on the Mac, and only matches leave the machine. Everything else (family,
friends, group chats, unknown numbers) is never sent, logged or printed.

Python 3.9 standard library only (/usr/bin/python3 on macOS 13).

Usage:
    /usr/bin/python3 bridge.py --dry-run     # counts only, sends nothing
    /usr/bin/python3 bridge.py               # the real run (what launchd does)

Files (outside the repo):
    ~/Library/Application Support/mowology-bridge/token       bearer token, chmod 600
    ~/Library/Application Support/mowology-bridge/state.json high-water mark (message ROWID)
"""

import argparse
import hashlib
import json
import os
import re
import sqlite3
import stat
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

VERSION = "1.0"
DEFAULT_DB = os.path.expanduser("~/Library/Messages/chat.db")
DEFAULT_STATE_DIR = os.path.expanduser("~/Library/Application Support/mowology-bridge")
DEFAULT_URL = "https://mowology.ca/crm/api/text-bridge.php"
APPLE_EPOCH = 978307200          # 2001-01-01 00:00:00 UTC as a unix timestamp
TEXT_MAX = 800
BATCH = 200                      # server accepts at most 500 per call
FIRST_RUN_DAYS = 90              # no state yet: look back this far, not the whole history
OBJECT_REPLACEMENT = "￼"    # attachment placeholder inside message text


# ─────────────────────────────────────────────────────────────────────────────
# Pure helpers (unit tested)
# ─────────────────────────────────────────────────────────────────────────────

def normalize_number(handle):
    """Last 10 digits of a phone handle, or None (emails, short codes)."""
    if not handle or "@" in handle:
        return None
    digits = re.sub(r"\D", "", handle)
    if len(digits) < 10:
        return None
    return digits[-10:]


def hash_number(ten_digits, salt):
    """sha256(salt + 10 digits) — must match TextBridgeService::hash() in the CRM."""
    return hashlib.sha256((salt + ten_digits).encode("utf-8")).hexdigest()


def apple_to_unix(value):
    """Messages stores dates as seconds (old) or nanoseconds (new) since 2001-01-01."""
    if value is None:
        return None
    v = int(value)
    if v > 10 ** 11:
        v = v / 1e9
    return int(v + APPLE_EPOCH)


def unix_to_apple_ns(ts):
    return int((ts - APPLE_EPOCH) * 1e9)


def decode_attributed_body(blob):
    """Text from an archived NSAttributedString (typedstream).

    Layout: ... b'NSString' ... 0x2B ('+') <length> <utf-8 bytes>
    length is one byte, or 0x81 + 2 bytes little-endian, or 0x82 + 4 bytes little-endian.
    """
    if not blob:
        return None
    data = bytes(blob)
    i = data.find(b"NSString")
    if i < 0:
        return None
    j = data.find(b"+", i + len(b"NSString"))
    if j < 0 or j + 1 >= len(data):
        return None
    k = j + 1
    first = data[k]
    if first == 0x81:
        length = int.from_bytes(data[k + 1:k + 3], "little")
        start = k + 3
    elif first == 0x82:
        length = int.from_bytes(data[k + 1:k + 5], "little")
        start = k + 5
    else:
        length = first
        start = k + 1
    raw = data[start:start + length]
    if not raw:
        return None
    return raw.decode("utf-8", errors="replace")


def clean_text(text):
    if text is None:
        return ""
    t = text.replace(OBJECT_REPLACEMENT, "").strip()
    return t[:TEXT_MAX]


# ─────────────────────────────────────────────────────────────────────────────
# Reading chat.db (read-only)
# ─────────────────────────────────────────────────────────────────────────────

def open_readonly(path):
    uri = "file:" + urllib.parse.quote(os.path.abspath(path)) + "?mode=ro"
    return sqlite3.connect(uri, uri=True)


ONE_TO_ONE_SQL = """
    SELECT m.ROWID, m.guid, m.text, m.attributedBody, m.is_from_me, m.date,
           COALESCE(m.associated_message_type, 0), h.id
    FROM message m
    JOIN chat_message_join cmj ON cmj.message_id = m.ROWID
    JOIN (SELECT chat_id, MIN(handle_id) AS handle_id
          FROM chat_handle_join GROUP BY chat_id HAVING COUNT(*) = 1) one
         ON one.chat_id = cmj.chat_id
    JOIN handle h ON h.ROWID = one.handle_id
    WHERE m.ROWID > ? AND m.date >= ?
    ORDER BY m.ROWID
"""


def read_messages(conn, high_water, min_apple_date):
    """Returns (scanned, max_rowid, rows) — rows are one-to-one messages with usable text.

    Each row: dict(rowid, guid, handle, from_me, sent_at, text). Tapbacks/reactions and
    messages without text (attachments only) are dropped here.
    """
    cur = conn.cursor()
    scanned, max_rowid = cur.execute(
        "SELECT COUNT(*), MAX(ROWID) FROM message WHERE ROWID > ? AND date >= ?",
        (high_water, min_apple_date)).fetchone()
    rows, seen = [], set()
    for rowid, guid, text, body, from_me, date, assoc, handle in cur.execute(
            ONE_TO_ONE_SQL, (high_water, min_apple_date)):
        if rowid in seen:
            continue
        seen.add(rowid)
        if assoc:                       # tapback / reaction / sticker on another message
            continue
        t = clean_text(text if text else decode_attributed_body(body))
        if not t or not guid:
            continue
        rows.append({"rowid": rowid, "guid": guid, "handle": handle, "from_me": bool(from_me),
                     "sent_at": apple_to_unix(date), "text": t})
    return int(scanned or 0), int(max_rowid or high_water), rows


def match(rows, salt, hashes):
    """Only messages whose number hashes to a known customer. Nothing else is kept."""
    out, customers = [], set()
    for r in rows:
        ten = normalize_number(r["handle"])
        if ten is None:
            continue
        h = hash_number(ten, salt)
        if h not in hashes:
            continue
        customers.add(h)
        out.append({"hash": h, "direction": "out" if r["from_me"] else "in",
                    "sent_at": r["sent_at"], "guid": r["guid"], "text": r["text"]})
    return out, len(customers)


# ─────────────────────────────────────────────────────────────────────────────
# State, token, CRM client
# ─────────────────────────────────────────────────────────────────────────────

def load_state(state_dir):
    try:
        with open(os.path.join(state_dir, "state.json")) as f:
            return json.load(f)
    except (OSError, ValueError):
        return None


def save_state(state_dir, state):
    os.makedirs(state_dir, exist_ok=True)
    path = os.path.join(state_dir, "state.json")
    tmp = path + ".tmp"
    with open(tmp, "w") as f:
        json.dump(state, f)
    os.replace(tmp, path)


def read_token(state_dir):
    path = os.path.join(state_dir, "token")
    try:
        mode = os.stat(path).st_mode
    except OSError:
        raise SystemExit("No token file at %s — see README.md." % path)
    if mode & (stat.S_IRWXG | stat.S_IRWXO):
        raise SystemExit("Token file is readable by others — run: chmod 600 \"%s\"" % path)
    with open(path) as f:
        token = f.read().strip()
    if not token:
        raise SystemExit("Token file is empty — see README.md.")
    return token


class CrmClient:
    def __init__(self, url, token, timeout=30):
        self.url = url
        self.token = token
        self.timeout = timeout

    def _call(self, req):
        req.add_header("Authorization", "Bearer " + self.token)
        req.add_header("X-Bridge-Token", self.token)     # some hosts strip Authorization
        req.add_header("Accept", "application/json")
        req.add_header("User-Agent", "mowology-messages-bridge/" + VERSION)
        try:
            with urllib.request.urlopen(req, timeout=self.timeout) as r:
                return json.loads(r.read().decode("utf-8"))
        except urllib.error.HTTPError as e:
            raise RuntimeError("CRM answered HTTP %d" % e.code)
        except urllib.error.URLError as e:
            raise RuntimeError("CRM unreachable (%s)" % type(e.reason).__name__)

    def numbers(self):
        res = self._call(urllib.request.Request(self.url + "?mode=numbers", method="GET"))
        if not res.get("ok"):
            raise RuntimeError("CRM refused the numbers request")
        return str(res["salt"]), set(res["hashes"])

    def post(self, mode, payload):
        body = dict(payload)
        body["mode"] = mode
        req = urllib.request.Request(self.url + "?mode=" + mode, data=json.dumps(body).encode("utf-8"),
                                     method="POST", headers={"Content-Type": "application/json"})
        res = self._call(req)
        if not res.get("ok"):
            raise RuntimeError("CRM refused the %s request" % mode)
        return res


# ─────────────────────────────────────────────────────────────────────────────
# One run
# ─────────────────────────────────────────────────────────────────────────────

def run(db_path, state_dir, client, dry_run=False, now=None, out=print):
    """One pass. Prints counts only — never text, numbers or names."""
    now = time.time() if now is None else now
    state = load_state(state_dir)
    high_water = int(state["rowid"]) if state and "rowid" in state else 0
    min_date = 0 if state else unix_to_apple_ns(now - FIRST_RUN_DAYS * 86400)

    conn = open_readonly(db_path)
    try:
        scanned, max_rowid, rows = read_messages(conn, high_water, min_date)
    finally:
        conn.close()

    salt, hashes = client.numbers()
    matched, customers = match(rows, salt, hashes)
    counts = {"scanned": scanned, "one_to_one": len(rows), "matched_customers": customers,
              "texts": len(matched)}

    if dry_run:
        out("dry run: scanned %d, one-to-one %d, matched %d customers, would send %d texts"
            % (scanned, len(rows), customers, len(matched)))
        return counts

    stored = 0
    for i in range(0, len(matched), BATCH):
        res = client.post("ingest", {"messages": matched[i:i + BATCH]})
        stored += int(res.get("stored", 0))
    # Only after every batch was accepted: a failure above leaves the mark where it was,
    # so the next run re-sends (the CRM ignores duplicates by guid).
    save_state(state_dir, {"rowid": max_rowid, "updated": int(now)})
    counts["stored"] = stored
    client.post("heartbeat", {"scanned": scanned, "one_to_one": len(rows), "matched_customers": customers,
                              "sent": len(matched), "stored": stored, "version": VERSION})
    out("%s scanned %d, one-to-one %d, matched %d customers, sent %d texts, %d new"
        % (time.strftime("%Y-%m-%d %H:%M:%S"), scanned, len(rows), customers, len(matched), stored))
    return counts


def main(argv=None):
    p = argparse.ArgumentParser(description="Customer texts -> Mowology CRM (read-only).")
    p.add_argument("--dry-run", action="store_true", help="count only; send nothing, keep the mark")
    p.add_argument("--db", default=DEFAULT_DB, help="chat.db path (default: %(default)s)")
    p.add_argument("--state-dir", default=DEFAULT_STATE_DIR)
    p.add_argument("--url", default=os.environ.get("MOWOLOGY_BRIDGE_URL", DEFAULT_URL))
    a = p.parse_args(argv)
    try:
        client = CrmClient(a.url, read_token(a.state_dir))
        run(a.db, a.state_dir, client, dry_run=a.dry_run)
    except sqlite3.OperationalError as e:
        msg = str(e)
        hint = " — grant Full Disk Access to /usr/bin/python3 (see README.md)" if "unable to open" in msg or "authoriz" in msg else ""
        print("%s error: cannot read the Messages database%s" % (time.strftime("%Y-%m-%d %H:%M:%S"), hint))
        return 1
    except RuntimeError as e:
        print("%s error: %s" % (time.strftime("%Y-%m-%d %H:%M:%S"), e))
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
