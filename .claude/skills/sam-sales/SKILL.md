---
name: sam-sales
description: Use BEFORE touching anything sales-related in the Mowology CRM / Ittai — Sam (the sales head), quotes (building, totals, GST, sending, recipients, CC to property managers, the customer quote page, line untick, approval, verbal approval), quote follow-ups and nudges, leads and "maybe a lead" emails, replies from clients (office@, iCloud, texts), pipeline stages, the Closer's price and rate card, trip/delivery line suggestions, quote-opened pushes, the "Just approved" list and the Unscheduled tray job. Also when Tim asks "who hasn't answered…", "did they say yes…", "why did this quote go to…", or reports a missing line, a wrong total or a wrong recipient. Holds how Sam is allowed to act, where each sales job lives in code, iPhone vs web parity, and the traps already hit.
covers:
  - app/Modules/Sales/*
  - app/Modules/Quotes/*
  - public/crm/quotes/*
  - public/customer/quote.php
  - public/customer/api/quote-upsell.php
  - public/crm/customer/quote.php
  - public/crm/includes/sam-card.php
  - public/crm/js/sam-card.js
  - public/crm/includes/closer-panel.php
  - public/crm/js/closer-panel.js
  - ios/MowologyCRM/MowologyCRM/Features/Team/SamCard*
  - ios/MowologyCRM/MowologyCRM/Core/Models/SalesModels.swift
metadata:
  version: 1.0.0
  created: 2026-10-11
  owner: Tim Schofield (Mowology)
---

# Sam — sales for Mowology (and Ittai)

Sam is the CRM's sales head ("the Closer"): the pipeline, quotes, follow-ups and every client
"yes". This skill is what a careful sales coordinator would know before touching Tim's quotes:
**how Sam is allowed to act**, **how a quote's money and recipients work**, **where each job lives
in code**, and **the traps already hit**. Read the reference that matches the task:

| Task | Read |
|---|---|
| Totals, GST, line untick, approval, tray job, recipients, CC, sending | `references/quotes.md` |
| What Sam may do, draft, learn, push; who sends | `references/operating-rules.md` |
| Traps already hit (read before ANY change to quotes or follow-ups) | `references/traps.md` |
| iPhone vs Android vs web — keeping Sam the same everywhere | `references/surfaces.md` |

**Location rules** (taxes, payroll, CASL/SMS, bylaws, licences — by country/province/city) live in the shared
`regions` skill (`.claude/skills/regions/`), not here. Read the file for the place the job is in.

## Before and after every change (Obsidian + Graphify)

**Before** writing or changing anything sales-related:
1. Read the Obsidian notes for the area —
   `~/Library/Mobile Documents/iCloud~md~obsidian/Documents/30-PROJECTS/Active/Mowology-CRM/Architecture/`
   Integration-Map.md "Quotes" and "Quote approval chain" first, then
   `grep -n -i "<area>" Known-Failure-Patterns.md Decision-Log.md` (quote, sam, closer, sms,
   recipient, approval, tray, inbox…).
2. Graph what you are about to touch: `python3 ~/.claude/tools/graphify.py touches <Class>`
   (every screen/app a change reaches) and `… file <path>` (what it uses). Watch for reach
   outside Sales: `SamFollowupService` is also used by Yui (`YuiDraftService`, `YuiRules`);
   `SalesDeskService` feeds Kaname's brief and Yui's desk.
3. Sales files on production are often AHEAD of the repo (other sessions). `cmp` against the
   live copy before deploying; `scripts/deploy-merged.py` stops on conflicts.
4. On production data: `scripts/prod-run.sh` preview first, then `--commit`.

**After** the change:
1. A mistake found or a rule learned → `references/traps.md` (this skill) and
   Known-Failure-Patterns.md (Obsidian) the same day; a "why it's built this way" → Decision-Log.
2. A Sam feature changed on one surface → update `references/surfaces.md` (and the other
   surfaces, or record the gap there).
3. Names, paths or rules in this skill changed → update the skill in the same commit.
4. `/obsidian` (bare) at the end of the session writes the rest back.

## The rules that are never broken

1. **Nothing goes to a client without Tim's yes.** Sam drafts; Tim sends, edits, skips or
   snoozes. `SamFollowupService::send()` runs only from Tim's click. No automatic nudges: the
   nine automatic quote follow-up rules were disabled (2026-10-05) and must stay off.
2. **GST is always added, never included.** 5% on top of the subtotal, shown as its own line.
   No price, term or template may say "includes GST" (migration
   `database/migrations/1187_gst_added_terms.sql` fixed the old wording).
3. **Nothing silently disappears.** Every approval states each line ✓ approved or ✗ declined with
   its amount, approved total beside quoted total (`QuoteApprovalService::summary()`). Declined
   lines stay on the quote, marked, and are left out of totals, jobs, PDF and the edit form.
4. **The right person gets the quote.** Quotes go to the chosen recipient ("Send to" /
   "Quotes go to"), never by default to a firm's accountant. On a managed (strata) building the
   property manager is CC'd when known (`QuoteRecipientService::ccForQuote()`) — on the quote
   send and on Sam's follow-ups.
5. **Approved non-contract quotes become a job in the Unscheduled tray** — never on today's
   calendar or crew phones. Contracts are set up from the contract (snow: the daily route).
6. **Texts obey the carrier rules:** ≤160 characters, plain text, no links or anything that
   looks like a domain, direct them to email, include (778) 846-9273. Email and SMS go only
   through `sendEmail()` / `sendSms()` — SMS never through PHPMailer.
7. **Never claim a fact about a quote or client you did not check this session.** Cite the
   query, the row or the file. "Unverified" is an acceptable answer.

## One place for each job (improve it there, and every screen and app gets it)

| Job | The one place | Used by |
|---|---|---|
| Quote fetch, create/update, mark sent, verbal approval | `QuoteService` (`app/Modules/Quotes/Services/QuoteService.php`) | quotes pages, Sam desk + follow-ups, PDF, field asks, invoice-from-visit |
| Quote totals (subtotal + 5% GST, declined/optional out) | `calculateQuoteTotals()` — two copies: `app/Services/CrmFunctions.php`, `public/includes/functions.php` | quote builder, view, PDF |
| Who a quote goes to + PM CC | `QuoteRecipientService::forProperty()`, `QuoteRecipientService::ccForQuote()` | `public/crm/quotes/view.php`, `public/crm/quotes/create.php`, companies/properties pages, `SamFollowupService` |
| Client unticks lines before signing | `QuoteLineChoiceService::setDeclined()` | `public/customer/api/quote-upsell.php`, `public/customer/quote.php`, office lock on `public/crm/quotes/view.php`, plan conversion (`app/Modules/Jobs/Services/Plan/PlanLineItems.php`) |
| Everything after an approval (summary, tray job, push) | `QuoteApprovalService::afterApproval()` | `public/customer/quote.php`, `public/crm/customer/quote.php`, `public/crm/quotes/view.php` (approve_verbal), nightly `QuoteApprovalService::sweep()` in `app/Modules/Jobs/Cron/generate_visits.php` |
| Follow-up queue (who is waiting, one card per customer) | `SalesDeskService::queue()` | `app/Modules/Sales/Api/sales-head.php`, `app/Modules/Sales/Api/sales-head-mobile.php`, Kaname, Yui |
| Drafting, sending, parking a follow-up | `SamFollowupService::draft()`, `SamFollowupService::send()`, `SamFollowupService::park()` | Sam web + iPhone, Yui |
| Claude reply draft (Tim's tap, 20/day) | `SamFollowupService::draftReply()` | Sam web + iPhone |
| Text rule check | `SamFollowupService::smsProblems()` | send; copies in `public/crm/js/sam-card.js` and iOS `SalesSMS` |
| Client mail history (office@, iCloud) | `SalesInboxService::ingest()`; joining strangers' mail to a quote `SalesInboxService::classifyAny()` | `app/Modules/Sales/Cron/sales_inbox_poll.php`, `IcloudInboxRouter` |
| Replies no other net caught; clear "yes" | `UnclaimedReplyService::items()`, `UnclaimedReplyService::lane()` | Sam, Kaname |
| Email enquiries from strangers | `EmailLeadService::score()`, `EmailLeadService::maybes()` | iCloud router, Sam web |
| Expired quotes: lost / keep / won | `SamQuestionService::answer()` | Sam web + iPhone |
| Pipeline stage of a contact/company | `PipelineStageService::onEvent()`, `PipelineStageService::sweep()` | quote/plan/invoice events, pipeline sweep |
| "Quote opened" push | `QuoteViewNotifier::notifyFirstView()` | `public/customer/quote.php` |
| The Closer's price beside the quote | `CloserService::forQuote()` (read-only) | `app/Modules/Sales/Api/closer.php`, `public/crm/includes/closer-panel.php` |
| Pickup / disposal / delivery line suggestion | `TripLineSuggester::forQuote()` | `app/Modules/Sales/Api/closer.php` |
| Rate card edits (Tim only) | `CloserSettingsService::save()` | closer.php `save_card` |

**Not yet central (known):** the 5% GST rate is written in at least five quote places
(`calculateQuoteTotals()` ×2, `QuoteLineChoiceService` and `QuoteApprovalService` `TAX_RATE`,
`recalculateQuoteTotals` in `public/customer/api/quote-upsell.php`). The text rule is in three
(server, `public/crm/js/sam-card.js`, iOS). A change means finding every copy
(`grep -rn "0\.05\|TAX_RATE" app/Modules/Quotes app/Modules/Sales public/customer public/includes app/Services/CrmFunctions.php`).

## Keeping this skill true

- `tests/Unit/Docs/HeadSkillsTest.php` fails the test suite when a file, class method or
  function this skill names no longer exists, when the Obsidian + Graphify section is missing,
  or when the frontmatter has no `covers:` list. Fix the skill in the same commit as the code.
- Unit tests for the rules above live in `tests/Unit/Sales/` and `tests/Unit/Quotes/`
  (e.g. `tests/Unit/Sales/QuoteApprovalServiceTest.php`,
  `tests/Unit/Quotes/QuoteLineChoiceServiceTest.php`). Run `vendor/bin/phpunit` before a commit.
- When a mistake is found in a quote, a send or Sam's behaviour, add it to
  `references/traps.md` the same day (what happened, the signature, the rule that prevents it).
