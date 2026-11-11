# Mailboxes — who reads what, and what each route stores

Source of truth: `app/Services/Mail/MailboxConfig.php` (checked 2026-10-11). Passwords live only
in `secrets.php` (never named values here, never in any other file).

## The four mailboxes

| Key | Address | Host | Secret constants | Purposes (`MailboxConfig::forPurpose()`) |
|---|---|---|---|---|
| office | office@mowology.ca | mail.mowology.ca:993 | SMTP_USER / SMTP_PASS | sales, receipts, etransfer, yardi |
| receipts | receipts@mowology.ca | mail.mowology.ca:993 | RECEIPTS_IMAP_PASS | receipts |
| info | info@mowology.ca | mail.mowology.ca:993 | ETRANSFER_IMAP_PASS | etransfer **only** |
| icloud | mowology@icloud.com | imap.mail.me.com:993 | ICLOUD_IMAP_USER / ICLOUD_IMAP_PASS (Apple app-specific password) | router |

iCloud is read **once** per run by `IcloudInboxRouter`, never by the per-purpose readers; it is
inert (reports "not configured") until both constants exist.

## Which reader reads which box

| Cron (cPanel schedule in its header) | Reads | Search | Window / floor | Stores |
|---|---|---|---|---|
| `app/Modules/Sales/Cron/sales_inbox_poll.php` (every 15 min) | office@ INBOX + Sent folder (`ImapReader::pickSentFolder()`) | mail from/to a known contact; non-contacts joined by quote#, address, domain | 3 days; 90 on first run or `?backfill=1` | `sales_messages` (Sam + Yui history) |
| … same run: `SupplierInboxService::poll()` | office@ + iCloud | one `FROM <supplier>` search per directory supplier | 4 days (`DAYS`) | last heard, supplier PDFs → `SupplierFormService`, licence → `CompanyDocumentService`, price-list links (sender domain has a `supplier_list_formats` row) → `SupplierPriceListService` |
| `app/Modules/Comms/Cron/icloud_inbox_poll.php` (7,22,37,52) | every iCloud folder except junk/trash/drafts/notes | envelopes in UID chunks, triage, then routed | first run 90 days, then new UIDs (`mailbox_poll_state`, migration 1223); money routes never backfill | see routes below |
| `app/Modules/Expenses/Cron/receipt_inbox_poll.php` (every 15 min) | receipts@ (everything) + office@ (receipt-looking only, from 2026-10-06) | `SINCE` | 21 days; floor RECEIPTS_POLL_FLOOR or 2026-06-19 | pending/draft expenses (never approved) |
| `app/Modules/Accounting/Cron/etransfer_inbox_poll.php` (every 10 min) | office@ + info@ | FROM Interac sender / SUBJECT "Interac e-Transfer" | 21 days | `etransfer_notifications` (Penny) |
| `app/Modules/Accounting/Cron/yardi_eft_inbox_poll.php` (every 10 min) | office@ INBOX only | FROM `DoNotReply@yardi.com` exactly | 21 days; floor YARDI_EFT_POLL_FLOOR or 2026-08-05 | auto-records exact invoice# + amount, else pending (Penny) |

Web runners for admins: `public/crm/cron/<name>.php` shims. Every run writes `recordCronRun()`.

## iCloud routes (`IcloudInboxRouter::classify()`, first match wins)

| Route | What | Goes to |
|---|---|---|
| interac | Interac notice | `EtransferInboxService::parseInteracEmail()` + ingest (Penny) |
| yardi | DoNotReply@yardi.com remittance | `YardiEftInboxService::parseRemittanceEmail()` + ingest (Penny) |
| payment platforms | PayPal/Stripe/Square/Wise/Wave: "you sent" → receipt; "you received" → `vendor_messages` kind payment | Penny |
| contact | to/from a CRM contact | `sales_messages`, mailbox `icloud …` (Sam / Yui) |
| receipt | PDF/photo with invoice words, or a known vendor's e-receipt | `ReceiptInboxService` (pending/draft) |
| vendor | other mail with a known vendor | `vendor_messages` |
| lead | enquiry from an unknown sender (`EmailLeadService`) | strong → `quote_requests`; weak → "maybe a lead" (Sam) |
| ignore | everything else (personal) | **not stored**; counts only in the log |

Dedupe is by Message-ID in every store, so mail that reached office@ and iCloud is stored once.

## Read-only, exactly
- Open: `ImapReader::open()` / `ImapReader::reopen()` — flags `ImapReader::readOnlyFlag()` (OP_READONLY).
- Body/part fetch: FT_PEEK (`ImapReader::peekFlag()`); helpers `ImapReader::fetchPart()`,
  `ImapReader::textBody()`, `ImapReader::rawHeaders()`.
- Bulk envelopes: imap_fetch_overview with FT_UID; never FT_PREFETCHTEXT (it sets \Seen).
- Forbidden in any reader: imap_delete, expunge, setflag_full, clearflag_full, mail_move,
  mail_copy, append, mailbox create/rename/delete.
- `tests/Unit/Comms/MailboxReadOnlyTest.php` scans its `READERS` list for all of the above.
  Gap: `app/Modules/Comms/Services/SupplierInboxService.php` is **not** in that list (it reads
  through ImapReader and only searches / fetches structure, but nothing enforces that).
- Tidying iCloud (moving mail) is a separate write-enabled tool on branch feature/icloud-tidy
  (Decision-Log 2026-10-07) — never add writes to these readers.

## Sending identity
- CRM email: From no-reply@mowology.ca, Reply-To office@mowology.ca (`sendEmail()`), PHPMailer
  SMTP with native fallback. Replies therefore land in office@ and are read by sales_inbox_poll.
- SMS: From no-reply@mowology.ca to all ten carrier gateways via native mail() (`sendSms()`).
- Supplier / subscription mail: office@mowology.ca (Tim, 2026-10-10).
