# Traps already hit — read before any change to a reader, router or sender

Add a new entry the same day a mistake is found: date, what happened, the signature, the rule.

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
