# Traps already hit — read before any change to a reader, router or sender

Add a new entry the same day a mistake is found: date, what happened, the signature, the rule.

## 2026-11-05 — Personal texts filed as client history (FIXED)
Tim's personal iMessages with someone saved as a contact (no quote, invoice, contract or property) were stored in
`sales_messages` (mailbox 'imessage', 292 rows since July) and read as client mail by Yui, Sam and the voice learner.
Signature: outbound 'imessage' rows for a contact with no work on file; `TextBridgeService::numbers()` hashed EVERY
active contact with a phone, and Personal only existed for numbers on no contact. Found while reading Tim's sent mail
for his voice. Fix: a number in `text_personal` never matches (`TextBridgeService::numbers()` drops it,
`TextBridgeService::ingest()` refuses it even from an older bridge); `TextBridgeService::markContactPersonal()`
blocks the contact's numbers and deletes that contact's texts only (emails untouched), asking again when the contact
has work on file; Yui's text panel lists `TextBridgeService::textedWithoutWork()` with Keep / Personal.
**Rule:** being a contact is not being a client. Anything that reads `sales_messages` as "client history" or learns
from it must expect personal rows until the owner has reviewed the texted-without-work list; never quote raw
snippets from it in docs.

## 2026-10-11 — Yardi / SparcPay remittances are not being read (OPEN)
`yardi_eft_inbox_poll` ran 2,663 times reporting "Scanned 0 email(s)" while office@ held 5 Yardi
remittances (May–Jul) and info@ had forwarded Dorset ones. Remittances name invoice numbers, so
Penny is missing certain payment matches. Likely causes from the code (unverified against the
mailbox): the search is `FROM "DoNotReply@yardi.com"` over 21 days with a 2026-08-05 floor, so
May–Jul mail is outside both; forwarded copies are From the forwarder, not Yardi; info@ has only
the `etransfer` purpose, so the Yardi reader never opens it; the iCloud router's yardi route never
backfills money mail; nothing anywhere reads SparcPay (`grep -ri sparc app` is empty).
**Rule:** a poller that always finds zero is broken; test its search against a known message.

## 2026-10-11 — Routing code is referenced here but missing from this tree
`app/Modules/Sales/Services/SalesInboxService.php`, `app/Modules/Sales/Services/SalesDeskService.php`,
`app/Modules/Sales/Api/sales-head-mobile.php` and `app/Modules/Team/Api/team-mobile.php` require
app/Modules/Comms/Services/InboundRouteService.php and InboundTopicClassifier.php, which exist only
on branch feature/route-billing-to-penny (commit b0e0252a, migrations 1280–1284) and, per memory
2026-10-10, on production. A require_once of a missing file is a fatal, not a caught exception.
**Rule:** never deploy those four files from this branch until the routing files are merged in;
diff against live first.

## 2026-10-10 — Prod ahead of repo for Sales/Comms
SalesDeskService, SalesInboxService, EmailLeadService, team-mobile, sales_inbox_poll and others were
ahead on production; deploys were hand-merges onto the live copies (Known-Failure-Patterns).
**Rule:** `cmp` against prod before deploying any reader or desk file.

## 2026-10-10 — Supplier mail sent from iCloud
20 supplier emails went from mowology@icloud.com. **Rule:** supplier/subscription mail from office@.

## 2026-10-10 — Non-contact approvals were skipped
Monica (Macdonald PM) approved QUO-2026-0073 by email; she wasn't a contact, so office@ skipped
it. `SalesInboxService::classifyAny()` / `SalesInboxService::joinUnknown()` now join by quote#, address,
then a domain held by exactly one contact who has a quote (migration 1314). The office@ poll
re-reads only 3 days — run `?backfill=1` after a fix like this.

## 2026-10-07 — Readers could mark office@ mail read
The e-Transfer poller opened read-write without FT_PEEK. Every reader now goes through
`ImapReader::open()`; `tests/Unit/Comms/MailboxReadOnlyTest.php` enforces it.
**Rule:** a new reader goes in that test's `READERS` list (SupplierInboxService is not yet).

## 2026-10-07 — Big IMAP scans from a web request time out
Per-message headerinfo over a 71,827-message iCloud INBOX hit 504/503. **Rule:** bulk
imap_fetch_overview by UID chunks, a time budget, resume (`IcloudInboxRouter::poll()`), and
`MailboxPollLock` so runs never overlap.

## 2026-10-07 — APNs key would not parse
The first pasted .p8 failed openssl. Production has a normalizePem() fix that is **not** in this
tree's `app/Services/Push/ApnsService.php`. Test pushes with Sam's "Send me a test push" first.
`push-drain.php` is CLI-only on purpose (APNs JWT mint limit) — no "Run now" button.

## 2026-10-06 — A client's "Yes" surfaced nowhere
Gaby (Cambridge Apartments) replied "Yes" to an email Tim sent by hand; no net caught it.
`UnclaimedReplyService` is the catch-all now. **Rule:** every inbound reply from a known contact
must land in some lane (Sam's or Yui's) until answered or handled.

## 2026-10-05 — Quote SMS carried a bare domain
`sendQuoteNotificationSms()` adds `host/quote` to the text — carriers drop it silently. The fix
(`SmsTemplates.php`) is on branch fix/quote-sms-no-url; **this tree still has the URL**. It has no
callers here. **Rule:** no URL or domain in any text, ever.

## Standing SMS traps
- Gateways reject MIME headers: `sendSms()` uses native mail() with From + X-Mailer only.
- It sends to all ten carriers on purpose (carrier unknown, mail() always "succeeds") — its doc
  comment says it stops at the first; the code (correctly) does not. Don't "fix" it.
- Over 160 characters is truncated to 157 + "...", possibly cutting the phone number.

## Standing email trap
`YuiDraftService` says email goes "from office@"; it actually goes From no-reply@ with Reply-To
office@ (`sendEmail()`). Replies still reach office@.

## 2026-10-12 — Fable review
Rules now enforced in code:
- Push tokens follow the phone (`DeviceTokenService`): registering takes the token from every other
  user; sign-out retires it (web/Android via the session's token in `logoutUser()`, iPhone via
  `AuthSession.logout()` → POST /api/device/token {remove:true}). `public/api/auth/device-token.php`
  is not on production — the iPhone uses /api/device/token.
- Mail identity: `SalesInboxService::addresses()` trusts only the address in <brackets>; a display
  name can't pose as a contact. (The From header itself is still unauthenticated — no DKIM check.)
- `YuiDraftService::send()` writes a 'sending' row first (hides the card, blocks a second send for
  5 min), sends, logs history, then settles the row.
- `hasSmConsent()` asks the ledger first; only "no consent on record" falls back to the contact flags
  (17 contacts had an SMS flag with no ledger proof on 2026-10-12 — backfill, then drop the fallback).
- `PushDispatcher::drainQueue()` holds a named lock and claims rows (attempts-based, 10-min hold).
- Password reset, privacy requests and salt reports send via MessagingService's `sendCrmEmail()`.
- Readers refill rows stored with an empty snippet when they see them again as a dupe.
Dropped: QuoteViewNotifier through PushDispatcher — it already claims once per quote under a named
lock and retires 410 tokens; routing it through the queue would only add the drain's delay.
Not done: `joinUnknown()` still joins by quote number from any sender — requiring a domain/thread
match would have refused Monica's QUO-2026-0073 approval (the case it was built for). Tim to decide.

## 2026-10-12 — Security audit group C: mail senders must be PROVEN
Production (mail.mowology.ca) adds **no Authentication-Results header** — checked on the last 3 Yardi
and Interac messages in office@ and info@: they carry DKIM-Signature (d=yardi.com,
d=payments.interac.ca), X-Spam-Status, no AR / ARC / Received-SPF. So any Authentication-Results on
office@/info@ mail was written by the sender. `MailAuth` (app/Services/Mail) verifies DKIM itself
(rsa-sha256, simple/relaxed, DNS key; l= refused; From must be signed; d= aligned) and believes AR only
from `MAIL_TRUSTED_AUTHSERV_IDS` (none). Used by the Yardi and Interac pollers, the iCloud router's
money routes and the supplier price-list import (fails closed). `ImapReader::senderVerified()`.
receipts@ reads only active users, the owner's addresses and known vendor domains; others are held
('held_sender') without OCR/Claude. **Rule:** never trust a From header for anything that moves
books or data; ask MailAuth.

## 2026-10-13 — Yui's renewals section died every run (collation)
"Yui renewals: Illegal mix of collations (utf8mb4_bin,NONE) and (utf8mb4_general_ci,COERCIBLE)
for operation 'like'" — 98 a day. Production mixes utf8mb4_0900_ai_ci (properties, contacts,
quotes, ops_settings) with utf8mb4_general_ci (contracts); CONCAT_WS over both has no collation,
so the ZZTEST `NOT LIKE` fails. **Rule:** multi-column ZZTEST filters use
`YuiDeskService::notTest()`, which collates each column (YuiDeskCollationTest enforces it).

## 2026-11-04 — A booking request on Penny ("wrote about billing")
Meghan replied to her invoice email, "Re: Invoice INV-2026-0447 from Mowology…", with "can we book you
guys in for a fall cleanup". The subject counted double for billing, and her words matched no sales
pattern, so the message landed on Penny. **Rule:** on a reply, the thread subject is ours, not theirs.
When the new words match any topic, only they decide (`InboundTopicClassifier::isReply()`). The subject
decides only when the body says nothing topical. "Fall cleanup" is NOT a sales word, because it names
jobs in thread titles. "Book a crew/visit" is Otto's. Moving a machine-stamped message now learns from
a fresh reading, not the stale stamp (`InboundRouteService::move()`). Rows stamped before the fix keep
their head until Tim moves them.
## 2026-11-03 — a reply list that quoted the greeting, kept a crew text for six days and could draft a bank-change reply
Live desk: "Joe replied \"Hi Tim,\"" (the quote was the greeting), Nigel's text (a crew manager who is also contact #24) sat six days in
the client inbox, a bare "All right" and a "👍" waited a week, and nothing stopped Yui drafting a reply to a message that asked
to change payment details. Rules now in code: the quote skips greetings and quoted history (`UnclaimedReplyService::quote()`);
staff are matched by email, or by phone WITH the same first name (a family sharing a number is still a client); an acknowledgement
leaves the list unless our last message asked something (a `?` or "please confirm"/"let me know"); a held message is removed from the
ordinary list for good, even after its hold is marked Handled (otherwise the draftable copy comes back); the repeat-chase rule needs a
weekday gap (two texts in a row are one conversation); a mention of a credential ("the PDF password is the invoice number") is dropped
sentence by sentence, so a real ask beside it still holds.

## 2026-11-09 — office@ mail from people not on file was dropped silently (switch OFF until the replay is read)
A paralegal asked office@ for the balance on a numbered company; she was not a contact, `classifyAny()` found nothing and
the poll counted it as "not customer mail". `InboundIdentityService` now reads the person's own words (quoted text and
signature cut, so a law firm's footer never counts) and matches company (numbered, or two non-generic words), address and
non-free domain. Strong = company + address, or a numbered company alone, on ONE account; everything else waits in
`inbound_unplaced` (migration 1446). Only when `ops_settings.inbox_place_unknown` is on. **Rule:** a name or domain in an
email is evidence of the account, not proof of who wrote it; evidence at two accounts is "weak", never a guess. Run
`scripts/inbox-replay.php` through `prod-run.sh` and read every placement before switching on.

## 2026-11-10 — One reply quoting invoice numbers broke Yui's whole card
`UnclaimedReplyService` gives a reply's `invoices` as bare numbers; overdue accounts give rows. `YuiRules::vars()` read
`$i['number']`, so the desk threw from the moment a placed reply quoted two invoice numbers (06:58 to about 11:30). **Rule:** a
field shared by several sections takes every shape it can arrive in, and a new kind of stored mail gets a desk load test
before the switch goes on.


## 2026-11-12 — ittai.ca mail must not reach for a business database
ittai.ca runs in directory mode (`Tenant::db()` throws), and `sendCrmEmail()`'s neighbours log through `getDB()`. Mail sent
from the marketing address (the calculators' result email, saved-numbers reminders) uses `sendPlatformEmail()`, which uses
the same SMTP account but no database and Ittai's own sender. **Check before deploy:** sending as an ittai.ca address
through the Mowology SMTP account can fail DMARC alignment unless that SMTP host is allowed in ittai.ca's SPF/DKIM.
