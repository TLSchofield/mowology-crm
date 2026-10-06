# Messages bridge: customer texts → CRM

Customers text Tim's phone; the CRM (Sam's card) only saw email. This little program runs
on Tim's Mac every 5 minutes, reads the Messages database **read-only**, and sends the CRM
only the texts exchanged with **known customers** in **one-to-one** conversations.

What it never does:

- Never writes to Messages (the database is opened `?mode=ro`).
- Never sends, logs or prints texts with anyone who isn't a customer (family, friends,
  unknown numbers), and never touches group chats.
- Never sends a phone number. The CRM gives the Mac salted SHA-256 hashes of its customers'
  numbers; the Mac hashes each conversation's number the same way, and only matches go up.
- Logs counts only, never text, numbers or names.

Stored in the CRM: `sales_messages` rows with `mailbox = 'imessage'`, `channel = 'sms'`,
the text (≤800 characters), direction and time. Sam's card shows them as "text".

## Setup (Tim, once)

### 1. Add the token to the CRM

Generate a long random token in Terminal:

```bash
openssl rand -hex 32 | pbcopy
```

Add this line to `public/app_config/secrets.php` **on the server** (paste the token):

```php
define('SALES_TEXT_BRIDGE_TOKEN', 'PASTE-THE-TOKEN-HERE');
```

Until this constant exists, the CRM endpoint answers "not configured" and accepts nothing.

### 2. Put the same token on the Mac

```bash
mkdir -p ~/Library/Application\ Support/mowology-bridge
pbpaste > ~/Library/Application\ Support/mowology-bridge/token
chmod 600 ~/Library/Application\ Support/mowology-bridge/token
```

(The bridge refuses to run if the token file is readable by anyone else.)

### 3. Full Disk Access

macOS protects the Messages database. Two grants, in **System Settings → Privacy &
Security → Full Disk Access** (click **+**, press **⌘⇧G** to type a path):

- **Terminal** (for the dry run you start by hand), and
- **`/usr/bin/python3`** (for the background job launchd starts).

If the log later says `cannot read the Messages database`, also add the real Python that
`/usr/bin/python3` hands off to: run `xcrun -f python3` and add that path.

### 4. Dry run (sends nothing)

From the repo:

```bash
/usr/bin/python3 tools/messages-bridge/bridge.py --dry-run
```

It prints one line, counts only:

```
dry run: scanned 812, one-to-one 640, matched 23 customers, would send 97 texts
```

The first run looks back 90 days. A dry run does not move the "already sent" mark.

### 5. Install

```bash
bash tools/messages-bridge/install.sh
```

This copies `bridge.py` to `~/Library/Application Support/mowology-bridge/`, installs
`~/Library/LaunchAgents/ca.mowology.messages-bridge.plist` and loads it. It runs at once
and then every 5 minutes. Re-run it after updating `bridge.py`.

## Checking on it

```bash
tail -n 20 ~/Library/Logs/mowology/messages-bridge.log
launchctl list | grep mowology          # second column 0 = last run OK
```

Each run writes one line of counts. Every run also sends the CRM a heartbeat (counts only),
stored in `ops_settings` `text_bridge_heartbeat`; Sam's card says when the bridge has been
silent for over an hour (Mac asleep, Full Disk Access lost, token wrong).

## Uninstall

```bash
launchctl unload ~/Library/LaunchAgents/ca.mowology.messages-bridge.plist
rm ~/Library/LaunchAgents/ca.mowology.messages-bridge.plist
rm -r ~/Library/Application\ Support/mowology-bridge
```

Then remove `SALES_TEXT_BRIDGE_TOKEN` from `secrets.php`.

## Files

| File | What |
|------|------|
| `bridge.py` | The program (Python 3.9 standard library only) |
| `ca.mowology.messages-bridge.plist` | launchd template (`__HOME__` filled in by `install.sh`) |
| `install.sh` | Installs + loads the agent (Tim runs it) |
| `tests/test_bridge.py` | Tests against a synthetic chat.db (never a real one) |

State: `~/Library/Application Support/mowology-bridge/state.json` holds the last message
ROWID sent. Delete it to re-send the last 90 days (the CRM ignores duplicates).

CRM side: `app/Modules/Sales/Services/TextBridgeService.php`,
`app/Modules/Sales/Api/text-bridge.php` (`/crm/api/text-bridge.php`, `?mode=numbers`,
POST `ingest` / `heartbeat`, bearer token). See `docs/crm/sales-head.md`.

## Tests

```bash
cd tools/messages-bridge && /usr/bin/python3 -m unittest discover -s tests
```
