"""
Tests for the messages bridge. Run from tools/messages-bridge/:

    /usr/bin/python3 -m unittest discover -s tests

Every test uses a SYNTHETIC chat.db built here with the same table shape as the Messages
database — never a real one. All numbers are 555-01xx fictional numbers.
"""

import hashlib
import os
import sqlite3
import stat
import sys
import tempfile
import time
import unittest

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), ".."))
import bridge  # noqa: E402

SALT = "mowology-test-salt"
# Shared test vector — the same one is asserted in tests/Unit/Sales/TextBridgeServiceTest.php
VECTOR_HASH = "e622d119b1c0a6d90294c6ff640ee4f688b234b4b443e9066c565ff8256b3762"

CUSTOMER = "+16045550101"        # a known customer
STRANGER = "+16045550199"        # not a customer
FRIEND_A = "+16045550150"        # group-chat members
FRIEND_B = "+16045550151"


def typedstream(text, form="short"):
    """A minimal archived NSAttributedString: just enough for the decoder's layout."""
    raw = text.encode("utf-8")
    if form == "short":
        length = bytes([len(raw)])
    elif form == "0x81":
        length = b"\x81" + len(raw).to_bytes(2, "little")
    else:
        length = b"\x82" + len(raw).to_bytes(4, "little")
    return (b"\x04\x0bstreamtyped\x81\xe8\x03\x84\x01@\x84\x84\x84\x12NSAttributedString\x00"
            b"\x84\x84\x08NSObject\x00\x85\x92\x84\x84\x84\x08NSString\x01\x94\x84\x01+"
            + length + raw + b"\x86\x84\x02iI\x01\x05\x92\x84\x84\x84\x0cNSDictionary\x00")


def apple_ns(ts):
    return int((ts - bridge.APPLE_EPOCH) * 1e9)


class Fixture:
    """Builds a chat.db with message / handle / chat / chat_handle_join / chat_message_join."""

    def __init__(self, path):
        self.path = path
        self.db = sqlite3.connect(path)
        self.db.executescript("""
            CREATE TABLE handle (ROWID INTEGER PRIMARY KEY AUTOINCREMENT, id TEXT NOT NULL, service TEXT);
            CREATE TABLE chat (ROWID INTEGER PRIMARY KEY AUTOINCREMENT, guid TEXT, chat_identifier TEXT, style INTEGER);
            CREATE TABLE chat_handle_join (chat_id INTEGER, handle_id INTEGER);
            CREATE TABLE message (ROWID INTEGER PRIMARY KEY AUTOINCREMENT, guid TEXT UNIQUE NOT NULL,
                text TEXT, attributedBody BLOB, handle_id INTEGER DEFAULT 0, is_from_me INTEGER DEFAULT 0,
                date INTEGER, associated_message_type INTEGER DEFAULT 0, associated_message_guid TEXT);
            CREATE TABLE chat_message_join (chat_id INTEGER, message_id INTEGER, message_date INTEGER);
        """)
        self.n = 0
        self.now = time.time()

    def handle(self, number):
        return self.db.execute("INSERT INTO handle (id, service) VALUES (?, 'iMessage')", (number,)).lastrowid

    def chat(self, *handles):
        cid = self.db.execute("INSERT INTO chat (guid, style) VALUES (?, ?)",
                              ("chat%d" % len(handles), 45 if len(handles) == 1 else 43)).lastrowid
        for h in handles:
            self.db.execute("INSERT INTO chat_handle_join VALUES (?, ?)", (cid, h))
        return cid

    def msg(self, chat, handle, text=None, body=None, from_me=0, assoc=0, ago=3600):
        self.n += 1
        date = apple_ns(self.now - ago)
        mid = self.db.execute(
            "INSERT INTO message (guid, text, attributedBody, handle_id, is_from_me, date, associated_message_type)"
            " VALUES (?, ?, ?, ?, ?, ?, ?)",
            ("GUID-%04d" % self.n, text, body, handle, from_me, date, assoc)).lastrowid
        self.db.execute("INSERT INTO chat_message_join VALUES (?, ?, ?)", (chat, mid, date))
        self.db.commit()
        return mid


class FakeClient:
    def __init__(self, known_numbers):
        self.hashes = {bridge.hash_number(bridge.normalize_number(n), SALT) for n in known_numbers}
        self.posts = []

    def numbers(self):
        return SALT, set(self.hashes)

    def post(self, mode, payload):
        self.posts.append((mode, payload))
        return {"ok": True, "stored": len(payload.get("messages", []))}

    def sent(self):
        return [m for mode, p in self.posts if mode == "ingest" for m in p["messages"]]


class PureTest(unittest.TestCase):
    def test_number_normalization(self):
        self.assertEqual(bridge.normalize_number("+1 (604) 555-0101"), "6045550101")
        self.assertEqual(bridge.normalize_number("6045550101"), "6045550101")
        self.assertEqual(bridge.normalize_number("604.555.0101"), "6045550101")
        self.assertIsNone(bridge.normalize_number("someone@icloud.com"))
        self.assertIsNone(bridge.normalize_number("72727"))          # short code
        self.assertIsNone(bridge.normalize_number(""))

    def test_hash_matches_the_shared_vector(self):
        self.assertEqual(bridge.hash_number("6045550101", SALT), VECTOR_HASH)
        self.assertEqual(VECTOR_HASH, hashlib.sha256(b"mowology-test-salt6045550101").hexdigest())
        self.assertEqual(bridge.hash_number(bridge.normalize_number("+1 (604) 555-0101"), SALT), VECTOR_HASH)

    def test_attributed_body_short_length(self):
        self.assertEqual(bridge.decode_attributed_body(typedstream("Yes please, Monday works")), "Yes please, Monday works")

    def test_attributed_body_0x81_length(self):
        long = "Can you also do the side yard? " * 12      # > 255 bytes
        self.assertGreater(len(long), 255)
        self.assertEqual(bridge.decode_attributed_body(typedstream(long, "0x81")), long)

    def test_attributed_body_0x82_length_and_utf8(self):
        self.assertEqual(bridge.decode_attributed_body(typedstream("Merci — à lundi", "0x82")), "Merci — à lundi")

    def test_attributed_body_garbage(self):
        self.assertIsNone(bridge.decode_attributed_body(b""))
        self.assertIsNone(bridge.decode_attributed_body(b"no string class here"))

    def test_apple_dates(self):
        ts = 1790000000
        self.assertEqual(bridge.apple_to_unix(apple_ns(ts)), ts)                 # nanoseconds
        self.assertEqual(bridge.apple_to_unix(ts - bridge.APPLE_EPOCH), ts)     # old: seconds


class RunTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.state = os.path.join(self.tmp.name, "state")
        self.fx = Fixture(os.path.join(self.tmp.name, "chat.db"))
        self.cust = self.fx.handle(CUSTOMER)
        self.cust_chat = self.fx.chat(self.cust)
        self.lines = []

    def tearDown(self):
        self.fx.db.close()
        self.tmp.cleanup()

    def run_bridge(self, client, dry_run=False):
        return bridge.run(self.fx.path, self.state, client, dry_run=dry_run, out=self.lines.append)

    def test_plain_text_both_directions(self):
        self.fx.msg(self.cust_chat, self.cust, text="Hi, is the quote still good?", ago=7200)
        self.fx.msg(self.cust_chat, self.cust, text="Yes until the 3rd", from_me=1, ago=3600)
        c = FakeClient([CUSTOMER])
        self.run_bridge(c)
        sent = c.sent()
        self.assertEqual([m["direction"] for m in sent], ["in", "out"])
        self.assertEqual(sent[0]["text"], "Hi, is the quote still good?")
        self.assertEqual(sent[0]["hash"], VECTOR_HASH)
        self.assertEqual(sent[0]["guid"], "GUID-0001")
        self.assertAlmostEqual(sent[0]["sent_at"], int(self.fx.now - 7200), delta=2)
        self.assertEqual(set(sent[0]), {"hash", "direction", "sent_at", "guid", "text"})

    def test_attributed_body_only(self):
        self.fx.msg(self.cust_chat, self.cust, body=typedstream("Go ahead with the cleanup"))
        c = FakeClient([CUSTOMER])
        self.run_bridge(c)
        self.assertEqual([m["text"] for m in c.sent()], ["Go ahead with the cleanup"])

    def test_group_chat_excluded(self):
        a, b = self.fx.handle(FRIEND_A), self.fx.handle(FRIEND_B)
        group = self.fx.chat(self.cust, a)          # even with the customer in it
        self.fx.chat(b)
        self.fx.msg(group, self.cust, text="group message")
        c = FakeClient([CUSTOMER])
        counts = self.run_bridge(c)
        self.assertEqual(c.sent(), [])
        self.assertEqual(counts["one_to_one"], 0)
        self.assertEqual(counts["scanned"], 1)

    def test_tapback_and_empty_skipped(self):
        self.fx.msg(self.cust_chat, self.cust, text='Loved "Yes until the 3rd"', assoc=2000)
        self.fx.msg(self.cust_chat, self.cust, text="￼")          # attachment only
        self.fx.msg(self.cust_chat, self.cust, text=None, body=None)
        self.fx.msg(self.cust_chat, self.cust, text="Thanks")
        c = FakeClient([CUSTOMER])
        self.run_bridge(c)
        self.assertEqual([m["text"] for m in c.sent()], ["Thanks"])

    def test_high_water_mark(self):
        self.fx.msg(self.cust_chat, self.cust, text="first")
        c = FakeClient([CUSTOMER])
        self.run_bridge(c)
        self.assertEqual(len(c.sent()), 1)
        c2 = FakeClient([CUSTOMER])
        self.run_bridge(c2)                         # nothing new
        self.assertEqual(c2.sent(), [])
        self.fx.msg(self.cust_chat, self.cust, text="second")
        c3 = FakeClient([CUSTOMER])
        self.run_bridge(c3)
        self.assertEqual([m["text"] for m in c3.sent()], ["second"])

    def test_first_run_looks_back_90_days_only(self):
        self.fx.msg(self.cust_chat, self.cust, text="ancient", ago=200 * 86400)
        self.fx.msg(self.cust_chat, self.cust, text="recent", ago=86400)
        c = FakeClient([CUSTOMER])
        self.run_bridge(c)
        self.assertEqual([m["text"] for m in c.sent()], ["recent"])

    def test_unmatched_never_sent_logged_or_printed(self):
        s = self.fx.handle(STRANGER)
        self.fx.msg(self.fx.chat(s), s, text="secret family stuff")
        self.fx.msg(self.cust_chat, self.cust, text="customer text")
        c = FakeClient([CUSTOMER])
        self.run_bridge(c)
        everything = repr(c.posts) + "\n".join(self.lines)
        self.assertNotIn("secret family stuff", everything)
        self.assertNotIn("0199", everything)
        self.assertEqual([m["text"] for m in c.sent()], ["customer text"])
        self.assertEqual(c.posts[-1][0], "heartbeat")
        self.assertEqual(c.posts[-1][1]["sent"], 1)

    def test_dry_run_prints_counts_only_and_sends_nothing(self):
        s = self.fx.handle(STRANGER)
        self.fx.msg(self.fx.chat(s), s, text="private")
        self.fx.msg(self.cust_chat, self.cust, text="customer text")
        c = FakeClient([CUSTOMER])
        self.run_bridge(c, dry_run=True)
        self.assertEqual(c.posts, [])
        self.assertEqual(self.lines, ["dry run: scanned 2, one-to-one 2, matched 1 customers, would send 1 texts"])
        self.assertIsNone(bridge.load_state(self.state))       # the mark is not moved

    def test_failed_post_keeps_the_mark(self):
        self.fx.msg(self.cust_chat, self.cust, text="hello")

        class Failing(FakeClient):
            def post(self, mode, payload):
                raise RuntimeError("CRM answered HTTP 500")
        with self.assertRaises(RuntimeError):
            self.run_bridge(Failing([CUSTOMER]))
        self.assertIsNone(bridge.load_state(self.state))

    def test_database_is_opened_read_only(self):
        conn = bridge.open_readonly(self.fx.path)
        with self.assertRaises(sqlite3.OperationalError):
            conn.execute("DELETE FROM message")
        conn.close()


class TokenTest(unittest.TestCase):
    def test_token_must_be_private(self):
        with tempfile.TemporaryDirectory() as d:
            p = os.path.join(d, "token")
            with open(p, "w") as f:
                f.write("test-token\n")
            os.chmod(p, 0o644)
            with self.assertRaises(SystemExit):
                bridge.read_token(d)
            os.chmod(p, stat.S_IRUSR | stat.S_IWUSR)
            self.assertEqual(bridge.read_token(d), "test-token")


if __name__ == "__main__":
    unittest.main()
