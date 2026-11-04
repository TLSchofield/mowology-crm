---
name: yui-comms
description: Use BEFORE touching anything comms-related in the Mowology CRM / Ittai — Yui (the comms / client-relations head), the mailboxes (office@, info@, receipts@, Tim's iCloud), any IMAP reader or inbox cron (sales_inbox_poll, icloud_inbox_poll, receipt_inbox_poll, etransfer_inbox_poll, yardi_eft_inbox_poll), routing inbound mail to a head ("Move to…"), unclaimed client replies, Yui's drafts and sends, email or SMS sending (sendEmail / sendCrmEmail / sendSms), the iMessage text bridge, supplier mail, or push notifications (APNs / FCM). Also when Tim asks "why didn't I see that email…", "who should have got this…", "did that text go out…", or a poller reports zero. Holds which mailbox each reader reads, the read-only rule, the SMS rules, how Yui is allowed to act, the one place for each job, and the traps already hit.
covers:
  - app/Modules/Comms/*
  - app/Services/Mail/*
  - app/Services/Messaging/*
  - app/Services/Push/*
  - app/Modules/Sales/Services/SalesInboxService.php
  - app/Modules/Sales/Services/UnclaimedReplyService.php
  - app/Modules/Sales/Services/TextBridgeService.php
  - app/Modules/Sales/Cron/sales_inbox_poll.php
  - app/Modules/Expenses/Cron/receipt_inbox_poll.php
  - app/Modules/Accounting/Cron/etransfer_inbox_poll.php
  - app/Modules/Accounting/Cron/yardi_eft_inbox_poll.php
  - public/crm/includes/yui-card.php
  - public/crm/js/yui-card.js
  - tools/messages-bridge/*
  - ios/MowologyCRM/MowologyCRM/Features/Team/*
metadata:
  version: 1.0.0
  created: 2026-10-11
  owner: Tim Schofield (Mowology)
---

# Yui — comms for Mowology (and Ittai)

Yui is the CRM's comms head: one-to-one conversations with existing clients, the inboxes that
feed every head, and the messages that go out. This skill is what a careful office manager
would know before touching the mail: **which mailbox is read by what**, **what may never be
written**, **how Yui is allowed to act**, and **where each job lives in code**.

| Task | Read |
|---|---|
| Which reader reads which mailbox, read-only rules, what each route stores | `references/mailboxes.md` |
| What Yui may draft, send, learn; approvals; SMS rules | `references/operating-rules.md` |
| Traps already hit (read before ANY change to a reader, router or sender) | `references/traps.md` |
| iPhone vs Android vs web — keeping Yui the same everywhere | `references/surfaces.md` |

**Location rules** (taxes, payroll, CASL/SMS, bylaws, licences — by country/province/city) live in the shared
`regions` skill (`.claude/skills/regions/`), not here. Read the file for the place the job is in.

## Before and after every change (Obsidian + Graphify)

**Before** changing a reader, a route or a sender:
1. Read the Obsidian notes —
   `~/Library/Mobile Documents/iCloud~md~obsidian/Documents/30-PROJECTS/Active/Mowology-CRM/Architecture/`
   `Integration-Map.md` "Messaging (Email / SMS)" and "Push Notifications", then
   `grep -n -i "<area>" Known-Failure-Patterns.md` (imap, icloud, inbox, sms, push, apns…) and
   `Decision-Log.md` (the SMS decisions, iCloud read-only, push-drain CLI-only).
2. Graph what you are about to touch: `python3 ~/.claude/tools/graphify.py touches <Class>`.
   Checked 2026-10-11: `MailboxConfig` is read by every inbox cron plus `IcloudInboxRouter`;
   `SalesInboxService` reaches Yui's desk, Penny's brief adapter and the iCloud API;
   `UnclaimedReplyService` reaches Kaname's brief/desk/inbox, Sam's APIs and Yui; `PushDispatcher`
   is called by 8 files (none of them Yui's). Change a shared reader once, there.
3. Diff the live copy first. Sales/Comms files have repeatedly been ahead on production
   (see `references/traps.md`) — never deploy a repo copy of a reader wholesale.

**After** the change:
1. A mistake found or a rule learned → `references/traps.md` and Known-Failure-Patterns.md the
   same day; a "why it's built this way" → Decision-Log.
2. A Yui feature changed on one surface → update `references/surfaces.md` (or record the gap).
3. A new file that opens a mailbox → add it to `READERS` in
   `tests/Unit/Comms/MailboxReadOnlyTest.php`.
4. `/obsidian` (bare) at the end of the session writes the rest back.

## The rules that are never broken

1. **Every mailbox is read-only.** Open only through `ImapReader::open()` / `ImapReader::reopen()`
   (OP_READONLY = IMAP EXAMINE) and fetch bodies with FT_PEEK. Never delete, move, flag, copy,
   append or expunge — office@ and iCloud are read by people. `MailboxReadOnlyTest` enforces it.
2. **Yui never sends on her own.** She drafts; Tim's click sends (`YuiDraftService::send()`).
   Anything to a client or supplier needs Tim's yes.
3. **All mail goes through the messaging functions** — `sendEmail()` / `sendCrmEmail()` for
   email, `sendSms()` or a named wrapper for texts. Never call PHPMailer or native mail()
   from page or service code (repo CLAUDE.md rule 9).
4. **SMS: native mail() inside `sendSms()` only, never PHPMailer.** ≤160 characters, plain
   text, **no URLs or bare domains**, include (778) 846-9273, tell them to check their email.
   Only to contacts with SMS consent (`hasSmConsent()`). Rules 8 and 11.
5. **Supplier and subscription mail uses office@mowology.ca**, never mowology@icloud.com
   (Tim, 2026-10-10). CRM mail goes From no-reply@mowology.ca with Reply-To office@.
6. **A poller that always finds zero is broken until proven otherwise.** Check what it
   searches (sender, window, floor, mailbox) against a message you know is there.
7. **Never claim a fact about the mail you did not check this session.** Cite the query,
   log line or file. "Unverified" is fine.

## One place for each job (improve it there, and every screen and app gets it)

| Job | The one place | Used by |
|---|---|---|
| Which mailboxes exist, their secrets, their purposes | `MailboxConfig::forPurpose()` in `app/Services/Mail/MailboxConfig.php` | every inbox cron, iCloud router, admin status |
| Opening / reading a mailbox read-only, MIME walk | `ImapReader::open()` in `app/Services/Mail/ImapReader.php` | every reader |
| Tim's iCloud: read once, classify, hand to each store | `IcloudInboxRouter::poll()` / `IcloudInboxRouter::classify()` | `app/Modules/Comms/Cron/icloud_inbox_poll.php`, `app/Modules/Comms/Api/icloud.php` |
| One live iCloud read at a time | `MailboxPollLock::acquire()` | iCloud router |
| Customer mail history (office@ + iCloud) | `SalesInboxService::ingest()` (+ `SalesInboxService::classifyAny()` joins non-contacts) | sales_inbox_poll, iCloud router |
| Which head owns an inbound message, "Move to…" | InboundRouteService + InboundTopicClassifier — **not in this tree** (see traps) | ingest, Sam/Yui/Penny cards, team-mobile |
| Client replies nobody answered (Sam's lane vs Yui's) | `UnclaimedReplyService::items()` / `UnclaimedReplyService::lane()` | Yui desk, Sam desk, Kaname, contact timeline |
| Yui's five sections + brief | `YuiDeskService::desk()` / `YuiDeskService::brief()` (decisions in `app/Modules/Comms/Services/YuiRules.php`) | web card, iPhone Team tab, Kaname |
| Drafting and sending a Yui message | `YuiDraftService::draftReply()` (Claude, capped) / `YuiDraftService::send()` | `app/Modules/Comms/Api/yui.php` |
| Text rules check | `YuiRules::smsProblems()` (wraps `SamFollowupService::smsProblems()`) | Yui send, `public/crm/js/yui-card.js` mirror |
| Supplier mail + business licence | `SupplierInboxService::poll()` | inside sales_inbox_poll (office@ + iCloud) |
| Facts in mail/money (strata, roles, details) | `ClueService` (`app/Modules/Comms/Cron/clues_daily.php`) | Yui + Penny clues |
| Customer texts from Tim's iPhone | `TextBridgeService::ingest()` + `tools/messages-bridge/bridge.py` | Sam/Yui history |
| Email out | `sendEmail()` in `app/Services/Messaging/MessagingService.php` | everything |
| Text out | `sendSms()` in `app/Services/Messaging/MessagingService.php` | 14 other files |
| Push out | `PushDispatcher::notifyUser()` → push_queue → `PushDispatcher::drainQueue()` (`app/Services/Push/Cron/push-drain.php`, CLI only) | 8 callers |
| Which head a push is from ("Yui [Comms]") | `PushHeads::brand()` | every push |

**Not yet central (known):** `sendCrmEmail()` is defined in three files
(`app/Services/Messaging/MessagingService.php` guarded, `app/Services/Messaging/SmtpMailer.php`,
`app/Services/Messaging/EmailHelper.php`); `sendQuoteNotificationSms()` lives only in
`MessagingService.php` (the legacy SmsGateway copy, which put a URL in the text, was deleted
2026-10-12 — nothing loaded it). Only
`public/crm/includes/messaging.php` → MessagingService should be loaded.

## Keeping this skill true

- `tests/Unit/Docs/HeadSkillsTest.php` fails the suite when a backticked path, class method or
  function named here no longer exists, when this file loses its Obsidian + Graphify section, or
  when the frontmatter `covers:` list is missing. Fix the skill in the same commit as the code.
- Code that does not exist in this tree (InboundRouteService, the APNs PEM fix) is named
  without backticks on purpose; backtick it once it is merged.
- When a mail, text or push goes missing, add it to `references/traps.md` the same day
  (what happened, the signature, the rule).
- Re-check `references/mailboxes.md` whenever a mailbox, a purpose or a cron window changes.
