# Ittai backlog — features to carry from Mowology to every tenant

The running list of what Ittai tenants need. Add to it as Mowology proves things. Each item names
what is **shared** across all tenants (knowledge, formats, rules about the world) and what stays
**private** to a tenant (their people, prices, accounts and decisions). That split is the standing
rule.

Direction (council, 2026-10-12): keep the data discipline-neutral, and build and sell to field-service
trades first. The AI back office is the edge.

## Build queue (Tim, 2026-11-05: "Queue all other items") — in order, one at a time after the payment thank-you
1. **e-Transfer memos naming invoices without the year** — `docs/plans/2026-11-05-etransfer-memo-invoice-numbers.md` (plan done; money matching, smallest).
2. **Duplicate receipts on the phone** — `docs/plans/2026-11-05-duplicate-receipts-on-the-phone.md` (plan done; keep the bank-linked one, else the older, until Tim says otherwise).
3. **Button and icon sizes, styles and positions** — inventory, then rules into v1.2 for Tim's approval, then fix screens (entry below).
4. **Android Kaname tab** — `docs/plans/kaname-tab-and-screen-heads-spec.md` Android steps (Codex writes, Claude reviews).
5. **Web: Kaname as a fixed name** — remove the `chief_name` override in `app/Services/HeadIdentity.php` after checking no business set it.
6. **Streamline services and how the system reads them** (entry below), then widen the thank-you ask beyond fall cleanup.

## Queued from Tim, 2026-11-05 (after Sam step 2)
- **Ask Kaname buttons:** every answer gets up to 3 rule-chosen actions for the record it is about (quote sent, not
  accepted → "Nudge <name>" opening Sam's follow-up + "Open quote"; overdue invoice → "Send reminder" via Penny + "Open
  invoice"; client only → "Open client"). Nothing sent from Ask. Server `CharlieAskService` + iOS `KanameAskBox`; TestFlight.
- **Ittai loading animation:** replace Apple's spinner app-wide with one `.progressViewStyle` at the root (64 uses,
  32 files; pull-to-refresh stays system). Design round first (ittai-design-studio): quiet, ink not gold, not the
  frozen symbol, not Chaos-to-Unity or the knowledge glyph; Reduce Motion = still. Then web + Android to match.
- **GST 0 can't be set:** `TaxEngine::gstRate()` treats 0 as unset and falls back to 5% — a business not registered
  for GST (Claudia?) gets GST on Sam's drafts and anything else that uses it.

## Word pools + recognise → suggest → confirm → learn, for every head (all trades) — Tim 2026-11-05
Goal (Tim): a system that improves with every tenant, and slowly for Mowology alone if nobody buys it.
**All heads, one engine on `HeadLessons` (migration 1348)** — each head plugs in its vocabulary: Sam services,
Yui topics (today `InboundTopicClassifier` + `InboundRouteService` "Move to…"), Penny categories/accounts (today
`PennyLessons`, `BankRuleLearning`; receipts "read without AI" 0%), Mia photo tags (AI every time, ~25% failing),
Otto issue kinds. Order: Sam first (the case below), then Yui routing, Penny receipts, Mia tags; each measured on
Agent Studio › Progress (right first time, AI requests down). **Revised after ChatGPT's review
(`docs/plans/2026-11-05-head-learning-engine-review-verdict.md`):** the loop is Recognise → Remember → Prepare →
Approve → Learn; first deliverable is Sam's whole fall-cleanup draft quote (business memory: the property's last
same-service job, price as a dated precedent), plus only the shared pieces it needs: proposal + per-field outcome
log (HeadLessons records corrections only), evidence + source on each proposal, negation check, coverage metric.
Autonomy stays in `AgentPolicy`. Cross-business promotion: design now, build at 3–5 businesses. Mia's failures are a
bug to fix first. Penny's part builds on `claude/penny-line-items` (three no-AI line-item paths, `LineItemFit`).
Sam's part (original):
- **Word pool per service, as data:** each service on a business's books carries its phrases ("fall cleanup",
  "leaf removal"); phrases weigh more than single words; negative words (fell, injury). Replaces the hard-coded,
  landscaping-only `EmailLeadService::SERVICES` list.
- **Sam's three steps, same pattern as Penny's bank lines:** (1) rules — request-for-work topic
  (`InboundTopicClassifier`) + service words + known client → "Fall Cleanup at <property>, last year's price",
  levels sure / likely / not sure from which rules matched (no invented %); (2) "Ask Sam" AI only on click when
  rules can't decide, cached and capped; (3) owner confirms or corrects, two confirmations add the phrase.
- **Shared:** a starter pool per trade + phrases promoted when several businesses confirm the same mapping
  (phrase → service only; region-tagged); vendor knowledge commons pattern (`app/Modules/Knowledge/`).
- **Private:** the email, client, price, and the business's own words (which override shared ones).
- Log every request + decision; Progress tab measures right-first-time.
- Trigger case: Meghan's "can we book you guys in for a fall cleanup" (routed to Penny until 2026-11-04).

## Inbox tidy for every tenant (Yui) — added 2026-10-13
- **Mowology today:** Yui sorts the iCloud inbox into folders using the CRM's own clients, suppliers
  and payment senders. It is preview-first, moves only, never deletes, and every batch can be undone.
  "Keep it tidy" files new mail automatically once switched on.
- **Tenant model (Tim, 2026-10-13): the tenant teaches, Yui applies.**
  - Each message or sender gets simple buttons: **"Is work"**, **"Not work"** and "Always put in…".
    A click creates a rule for that tenant only, and Yui applies it from then on.
  - Yui may *suggest* a folder, but she only moves on rules the tenant confirmed (plus the
    tenant's CRM contacts).
  - Safer than guessing: nothing moves on a hunch, and every rule traces back to a click.
- **Shared:** sender rules for well-known services (Amazon/Home Depot → receipts, Interac → money,
  Twitter/X → promos, etc.). Fixes found by any tenant go into the shared list, e.g. the Twitter/"Bill"
  misfile on 2026-10-13.
- **Private:**
  - "Is work" rules, contacts and suppliers;
  - the folder plan, fitted to the trade;
  - mailbox credentials, encrypted per tenant.
- **Needs:**
  - per-tenant mailbox connection, with iCloud/IMAP app passwords plus Google and Microsoft OAuth;
  - site_id on the mail_tidy tables;
  - a settings page with the "Is work" buttons on Yui's inbox card and in the tidy preview.

## Receipts as the way in (Penny) — council 2026-10-12
- A free or cheap receipts-only entry tier *inside* Ittai, not a separate app. The upgrade moment is
  "put this receipt on a job" or "bill this cost".
- Penny's edge: knows the jobs, the province's taxes and the trade's rules; checks against supplier
  price lists.
- Canada first: Jobber's bookkeeping is US-only.
- Shared: receipt layouts, vendor names, bank-wording patterns. Private: amounts, payers, rules.

## Penny proves her own books + head learning (all heads) — plan 2026-10-13
- `docs/plans/2026-10-13-penny-upgrade-and-head-learning.md`: lessons record, rules in shadow mode,
  mistakes as tests, scorecards, and Charlie's weekly report card on every head.
- Shared: the learning machinery and scorecard format. Private: each tenant's lessons and rules.

## Supplier price lists and the shared catalogue (Otto/Penny) — built 2026-10-12
- Import formats are data (Inline Nurseries first).
- A shared category tree for every trade family (plants, plumbing, electrical, salon supplies…).
- Botanical and part-number matching; a review queue for fuzzy matches.
- Shared: formats, catalogue, aliases, size equivalents. Private: price lists, prices, which suppliers
  a tenant uses.
- Later: an anonymised materials price index, once there are enough tenants and with consent.

## Staff permissions page — Tim 2026-10-12
- A role × permission tick grid, per tenant. Admins and managers edit; only anything touching an
  admin needs users.manage.

## Switch from Jobber tool — `docs/plans/2026-10-12-ittai-switch-from-jobber.md`
- Lessons from Mowology's own migration: accountant's year-end first, bank first, payer interview,
  remittance reading, and Jobber's late "marked paid" dates.

## Security and health watch (Ada) — built 2026-10-13
- Nightly security-posture checks: blocked paths, stray data files, DB privileges, Stripe webhook
  events, payment-mail signatures, backups heartbeat, audit export, certificates, dependencies, drift,
  page render health, migrations, error spikes, mail DNS.
- Each tenant site gets the same checks.
- Before any outside tenant: real tenant isolation, a data-only DB user, tested backups, nonce CSP,
  and an independent penetration test (audit report 2026-10-12).

## "Personal" on receipts and bank lines (Penny) — plan 2026-10-13
- `docs/plans/2026-10-13-move-to-personal.md`: one button covers three cases (personal on own card →
  out of the books; business on own card → owed to the owner; personal on the business card →
  shareholder account). A personal list with T1 tags exports for the accountant. Not a second ledger.
- Shared: the seed list of usually-personal vendor types. Private: each owner's own personal vendors.

## Receipt catch-up, vendor by vendor, teaching Penny as you go (STANDARD) — built 2026-10-14
- Tim: "This should be a standard. Other tenants may join with 100's of receipts." Every tenant's
  receipt review works this way, including the onboarding backlog.
- Penny's card groups waiting receipts by vendor, biggest group first. Approving unchanged counts toward
  her track record. A fix is a lesson; a reject keeps its reason.
- When the owner corrects a vendor the same way twice, she redoes that vendor's other waiting reads
  with the lesson: 10 at once, the rest by the background preparer first in line, within the daily cap.
- Private per tenant: the lessons (head_lessons.site_id) and track record. Shared: nothing yet.
  Candidate for the shared layer: vendor category/tax hints once 2+ tenants agree (the vendor
  knowledge commons already does this for confirmed receipts).
- Before 100s-of-receipts tenants: the daily cap (bookkeeper_daily_cap, default 40) needs an
  onboarding allowance, and the cost per receipt should be shown up front.
- BLOCKER before a second tenant: there is no "current tenant" lookup yet. Lessons are written and
  read as site_id 1 (HeadLessons default; PennyLessons::forVendor's $siteId). Once tenancy exists,
  every caller passes the tenant's site_id (Codex review 2026-10-14).

## Design studio (2026-10-25)
- Built: skill `.claude/skills/ittai-design-studio`, `/design`, `scripts/codex-design.sh`, `scripts/ittai-design/` (token build + `review-shots.mjs`). Proven on a smoke brief, two rounds.
- Web tokens AAA: done 2026-10-25 by porting snow-contracts `032151de` (tokens.json, tenant.config.json, tenants.generated.json, contrast test) onto this branch; regenerating `ItTokens.swift` is a no-op and `ittai-swift-tokens.mjs --check` + `IttaiSwiftTokensInSyncTest` guard against drift (iOS 1.3.58 had hand-edited the generated Swift while the source stayed behind).
- Codex raster image generation: Codex says it has an image tool; unverified. Ask Tim before using it for assets.

## Penny harvest from the small-business plugin (queued by Tim 2026-11-03)
- Source map: `docs/plans/2026-11-03-small-business-skills-harvest.md` (pieces 3, 7 and the cash-forecast half of 10). Sam's piece 1 shipped the same day as the pattern to copy (`LeadScore` + plan doc + Codex review + prod-run dry run).
- **Reminder tone under ReminderGate:** last 12 months, ≥3 invoices: ≥75% on time → gentle; 25–50% late (or <3 invoices) → neutral; >50% late → firm; one email per client, one call to action; skip anyone paid in the last 14 days. The grace / AP / deposit / statement gates stay on top — the plugin has no day ladder.
- **Bill and receipt controls:** duplicate = same vendor + invoice no., or same total within 5 days, or same total staged in 90 days (Penny twin check has part of this); recurring bill moved >10% → flag; expense >$25 with no receipt → flag; auto-code only after N agreeing bills (decide 2 vs 3 against the lessons threshold).
- **Cash-forecast bands:** band = stddev/mean of payment lag capped 50%; <3 payments → 30%; mean lag ≤1 day → 5%; fixed cost = same vendor 3+ consecutive months within 10% (`CashFlowForecastService`).
- Canada-only reminder: nothing in the plugin covers GST/HST/PST or CRA; Penny's BC rules are ours.
## Consumables and price history from receipts (Penny/Otto) — Tim 2026-10-29
- Goal: tools, equipment and consumables bought on receipts are stored so prices can be compared across vendors (chainsaw chain, DEF, bar oil, trimmer line, diesel).
- Checked live 2026-10-29: line items keep name, qty, unit price and total, but the line stays `open` (not linked to the shared catalogue) until a person taps "Add to catalogue"; price memory has 24 rows against ~700 lines and matches exact uppercase names only; `comparePricesAcrossVendors()` has no caller; no screen shows a comparison; no "consumables" family.
- Real DEF lines: Chevron "AIR 1 DEF 9.46L" $29.99 (size in the name); Shell "B SHELL ROTELLA DEF" $36.99 with no unit price, misread once as "ROELLA"; a repeated $4.72 "EHC AUTO DEF 9.46L" line on both Shell receipts that looks like a second line of one purchase, not a second jug (check before trusting). Pump diesel is a $200.00 lump, or litres and $/L inside the name, never in qty/unit price.
- Build, in this order: (1) a "Consumables" catalogue family next to "tools" (accounting category on the receipt stays as it is); (2) a unit size on the catalogue product so every price shows per litre or per piece; (3) match on supplier code / mfr part number plus a cleaned-up name, a person confirms the first match and the learned match carries over; (4) save a price for every receipt line, linked to the catalogue product; (5) one price-history-by-vendor view and a "last paid / cheapest" hint on the receipt; (6) read pump litres and $/L into qty and unit price (also fixes fuel comparison).
- Product scan (Tim 2026-10-29): when a line has no catalogue match or no unit size, offer "Scan the product" once per new product (barcode first for UPC / part number, label photo as fallback, read like a receipt). Optional; skipping keeps the line unlinked. A scan is the confirmation: it creates the catalogue product directly (no separate "Add to catalogue" tap) and links the line, after first looking up the barcode / part number so it never duplicates. New products start tenant-private and move to the shared layer when a second tenant scans the same barcode or an admin approves. Receipt text alone (no scan) still stays `open` until confirmed. Later purchases link without asking. Unchecked: whether the catalogue stores product photos, whether the phone apps can scan barcodes in the receipt flow.
- Open: whether catalogue families are a code list or a table; whether the iPhone Review Receipt screen has a catalogue step (web only per surfaces.md, Swift not read); why only 24 of ~700 lines wrote a price row.
- Tenant-neutral: no trade words in the schema or copy; the unit-size and family fields are shared, the prices stay private per tenant.

## Mia harvest from the plugins (queued by Tim 2026-11-03, after Sam)
- From the small-business map (`docs/plans/2026-11-03-small-business-skills-harvest.md`, piece 9 and the marketing rows):
  social content mix 40% useful / 30% proof / 20% offer / 10% people; posts per week by team size (solo 2, with office help
  3–4, with marketing help 5); caption = hook, benefit, CTA, 3–5 hashtags; email subject under 50 characters, 100–250 words;
  staged times must be in the future; voice profile learned from 15–30 sent emails (greeting, sign-off, sentence length,
  never-uses) and the banned-opener slop test; ad diagnostics (pause only after 30 days or 50 results, judge a new campaign
  after 7 days, raise budget 20–30% at a time, dollar impact never percentages alone); review replies under 60 words;
  content-strategy brief of 200–400 words (push / hold / reposition / seasonal / offers).
- Not yet surveyed: the sibling `marketing` plugin (campaign-plan, content-creation, performance-report, brand-review,
  competitive-brief) — survey like today's, then diff against `MiaCalendar`, `MiaSequenceService`, `MiaSocialService`,
  `MiaWording`, `ReviewReplyDrafter`.

## Otto follow-ups (2026-11-03, after the harvest shipped)
- **Plan lengths that cannot be right:** job plan #99 WEEKLY LAWN CUT = 8,009 min, #59 WILLOW FRONT BEDS = 1,319 min for one
  person. `DwellTimeService::updatePlanDwellAverage()` writes `estimated_duration_minutes` from GPS dwell; cap it at a
  crew-day there, and let Otto's duration suggestion (VisitDurationRules) be the only writer. Owner: Otto / schedule.
- **Vendor "Mowology INVOICE"** ($1,235 in 90 days) is one of our own invoices filed as an expense — Penny re-files.
- **No hourly rates on users** (`users.hourly_rate` empty for the crew) — the clock review says "cost unknown"; Tim can
  fill them in Settings → Users and Penny's payroll checks gain the same.
- **Restock velocity** stays n/a until stock is counted somewhere (no table today).

## Ada follow-ups (2026-11-03, after the register shipped)
- **Drift: 88 files on production differ from `feature/language`** (`app/Core/Auth/JwtAuth.php`, `session_config.php`, Accounting services, schedule pages, `appstack_*.php`, `public/.htaccess`…) — other sessions' deploys from other branches. Ada's drift row is now truthful and Critical. A session should walk the list: `scripts/prod-pull.sh --into-repo` each, decide branch vs live, commit what is wanted, redeploy through a manifest-recording script. Until then the red stands, correctly.
- **`ReviewRequestService`: Unknown column `c.review_request_sent_at`** (live error log 13:38, visit #2378) — a migration shipped and never run, or a column renamed; one of the "116 never run". Owner: Mia / visits.
- **"116 migrations never run"**: mark the hand-applied and renamed ones (done-/todo-) so the amber means something; 1390 failed on "column already exists" (already applied by hand).
- **Pulse `head_cards` fail 14× / `queues` warn 288× in 24 h** — the AI Office cards take 12.7 s to build; Pulse's own code (another session).

## Head harvest queue (Tim, 2026-11-03): complete each head entirely, in this order
1. **Sam** — in progress 2026-11-03 (client rhythm, comparables pricing, Penny→Sam payer terms, Sam→Penny pipeline line,
   signals digest, renewal radar, tiering, lead response times, quote hygiene, objection replies).
2. **Mia** — DONE 2026-11-03 (`docs/plans/2026-11-03-mia-harvest.md`); left: ads wiring when an account exists, content-strategy brief after margin-by-service, page-level SEO checks. Was: small-business pieces above + survey the sibling `marketing` plugin; diff against MiaCalendar / MiaSequenceService /
   MiaSocialService / MiaWording / ReviewReplyDrafter / MiaCampaignService.
3. **Ada** — DONE 2026-11-03 (`docs/plans/2026-11-03-ada-harvest.md`) — `operations` plugin (runbook, risk-assessment, compliance-tracking, status-report, process-doc) and finance
   `audit-support`/`sox-testing` evidence standards; diff against AdaSecurityRules / AdaSecurityWatch / BooksWatchService.
4. **Otto** — DONE 2026-11-03 (`docs/plans/2026-11-03-otto-harvest.md`) — `operations` plugin (capacity-plan, vendor-review, change-request) + small-business inventory/restock
   (velocity, stockout, reorder maths, buffer = min(30, max(7, lead/2))), payroll-prep punch rules weekly on time_entries;
   diff against OttoRules / OttoActionService / RecurringBillService / supplier lists.
5. **Yui** — inbox-manager (needs-you / drafted / handled; >5 "needs you" means the threshold is wrong; archive never delete),
   voice profile + slop test, outreach cadences (0/+4/+11/+21; never two sends in 48 h; trades 6–8 am or after 5 pm),
   customer-support `ticket-triage` / `draft-response`; diff against YuiRules / YuiDraftService / IcloudInboxRouter.
6. **Kaname (Charlie)** — monday-brief ("merge, don't staple"; "the one thing" closes it; skip missing reports silently; ask
   before posting bad news), business-pulse rollup (worst section; forced red on named client + amount + deadline ≤ 7 d),
   report-builder sanity checks (partial period, totals tie, "n/a — source" never an estimate), report-pack cadence;
   diff against CharlieBriefService / CharlieRankService / CharlieUrgentService / HouseBriefAdapter.
Pattern for each: survey → plan doc → pure classes + tests → wire → prod-run dry run → Codex → deploy → verify → vault + skill.

## Sam: record why quotes are lost (2026-11-03)
- Add `lost_reason` (competitor / budget / timing / no decision / other) when a quote is declined or expires, so the plugin's win-loss review (win rate by source, size band and service; where losses die; median cycle) can run in a year. Today 34 accepted and 0 declined are recorded in 12 months — losses are not being marked.

## Earned autonomy — the Ittai Trust Ladder (Tim, 2026-11-04) — DECIDED: not for Sam
- **Tim, 2026-11-04: "Sam stays on a click."** No autonomy level for client-facing sends; Sam drafts, Tim sends. The notes below are kept for the other heads if the question ever comes up for them; nothing is to be built for Sam.
- Tim's direction: Sam (and every head) starts by recommending; the more Tim approves unchanged, the more Sam may do, until Sam himself asks "you've approved my last 20 quote follow-ups without changes — do you trust me to send these automatically?" Tim grants; nothing ever self-promotes.
- Five levels per head **per task type** (observe → assist → approve → trusted → autonomous). Trust belongs to the task, not the head: routine reminders may be trusted while discounts, price changes and scheduling promises always need Tim.
- Charlie runs a Trust Engine across the seven heads: approvals, edits, reversals, outcomes, complaints, exceptions. Approval earns confidence; outcomes validate it (20 unedited sends ≠ 20 good decisions). Reversible actions can be delegated; irreversible ones (changing an accepted quote, committing a price) stay with Tim.
- Granting needs: named action categories, spending limits, escalation rules, a complete log, one-tap revoke, and a weekly "your team handled N tasks, I needed you for M" line from Charlie.
- **Blocker to decide first:** this reverses Sam's rule 1 ("nothing goes to a client without Tim's yes"; nine automatic nudge rules disabled 2026-10-05 and must stay off). The ladder is a deliberate, logged exception granted by Tim per task type — a council decision before any build. Not part of the 2026-11-04 Sam dashboard hierarchy work.
## Ideas from the Aspire 2026 Commercial Landscape Industry Report — added 2026-10-27
Source: Aspire (ServiceTitan) survey of 1,015 US commercial landscape owners, May 2025, published
July 2025. It is a vendor's survey of commercial firms, so treat the numbers as direction, not as
Mowology's market (Mowology is mostly residential and strata). Each idea names what is shared and
what stays private.
- **Get paid on time (biggest signal).** 76% invoice within 4 days but only 39% are paid on terms;
  60% are paid a week or more late, 7% two to three months late. Ittai already sends the invoice with
  its PDF; the gap is the chase and the payment route.
  - Automatic, polite reminder ladder per invoice (email first; SMS only the short no-link form).
  - "Pay by e-transfer / card / ACH" on every invoice and reminder; late-payment cost shown to the
    owner, not only the client.
  - Shared: reminder wording and timing defaults. Private: each tenant's terms, clients, amounts.
- **Job-cost forecasting.** 37% expect equipment and material costs up 10%+; 58% expect 2-3 week lead
  times; "forecasting job costs and margins" is a named worry. Penny/Otto can warn when a quoted
  price no longer clears the margin given recent receipts and supplier price lists.
  - Shared: price-index hints from the supplier lists. Private: the tenant's margins and prices.
- **Wage-aware pricing.** 70% plan raises (44% by 4%+); maintenance crews mostly $17-$30/hr in the
  survey. Prompt "your labour cost moved, re-check recurring prices" at renewal time.
- **Recurring maintenance is the money** (59% earn most revenue there; 35% of revenue is repeat
  business, 26% referrals). Make renewal, upsell and referral nudges first-class (Sam's pipeline).
- **One system, not seven.** 90% use 4+ tools, 62% use 7+, 29% use 10+. Top reasons to switch:
  automate workflows (58%), efficiency (51%), feature gaps (44%), scale (36%), margins (30%). Top
  switch worries: features, price, all-in-one, integrations, support/training. The pitch and
  onboarding should say "replaces your N tools" and show the list (accounting, payroll, GPS,
  marketing, estimating, invoicing).
- **Features tenants say they want in 2025:** process automation, customer notifications, smart
  routing, employee training and certification. Ittai has or is building all four (heads, notices,
  route planner, quiz); name them in the product copy in the survey's own words.
- **Client-retention reporting.** Retention is the top risk to goals; give the owner a retention and
  repeat-revenue view (churned, at-risk, renewal dates).
- **Quality validation** ("validating quality of work" is a listed risk): before/after photos and
  visit proof attached to the invoice, already in the selling points.

## Platform Terms of Service — built 2026-10-25 (gate OFF)
- Draft at /terms.php, acceptance tables (migration 1381), sign-in gate for admin/manager.
- **Owed before the gate goes on:** a lawyer settles the [bracketed] items (entity name, liability cap,
  governing law and venue, export window); then insert a terms_versions row (is_current=1) whose
  version matches TERMS_VERSION in public/terms.php.
- **Later:** clean /terms URL (needs the live .htaccess merged), add-on schedules for Payments and
  Accounting (Wave-style), acceptance in the native apps, and a send-to-owner email when the terms change.

- (2026-11-04) Receipts roadmap: faster capture, learning from every read, self-filling vendor directory, Otto lot borders for stores — docs/plans/receipt-roadmap-2026-11-04.md. Firecrawl-style page fetch for supplier availability: PageFetchService (curl + cleaner, Firecrawl API per supplier behind a flag), with supplier availability. Bank statements Claude fallback: StatementLlmExtractor gated by opening + lines = closing, learned per-bank preset.
## Android crew app as a founding business sees it — first local run 2026-10-26
Seen on the emulator against `claudia.ittai.ca` on the local test site (`scripts/local-android.sh up`;
docs/LOCAL_SITE.md "Android emulator"). The server side is right: Host → tenant database, Ittai sign-in page,
native sign-in, the tenant's own schedule. The APP still says Mowology. In the order Tim called them:
1. **Splash shows the Mowology logo** (launcher icon + splash in `android/app/src/main/res`, shared by both
   flavors). A founding business must never see it.
2. **Sign-in screen reads "Mowology / Field Manager" and "Access is restricted to Mowology staff."** — hardcoded
   in the app, not from the server (the server's own sign-in page already says the business's name).
3. **Sign-in (and every native screen) uses Mowology green** (button, "Show", tabs, pills). Tim: "the sign in
   page should only be ittai design brand colors" — Ittai Design System v1.2 tokens, nothing of Mowology's.
4. **Initiation should start from a sign-up screen** (Tim). Today the app only signs in to a business someone
   already provisioned (TenantProvisioner, owner-run). The first screen for a new business must be sign-up:
   create the business, then the owner's login — i.e. self-serve provisioning from the phone.
5. Bottom tab bar on the day screen has 8 tabs with labels wrapped letter by letter ("To da y", "Se arc h") —
   unreadable on a Pixel-6-width screen.
6. Crew gets 403 on `team-mobile?mode=brief/brains` at every open (expected for crew, but the app asks anyway).
7. **Android day screen must match the iOS Schedule screen** (Tim, with the iPhone screenshot 2026-10-26: "they are
   meant to be similar/same"). iOS: five icon tabs — Schedule · Time Clock · Search · Receipts · AI Office; week
   strip with MON/TUE day names; each job card shows est. arrival time + distance, the service tag pill, "First
   visit" / "Last done N days ago", the client and the crew names, "n visit(s)", a ··· menu. Android today: eight
   text tabs, no arrival estimate, plainer cards, a "Display" link and a "Web app" button in the header.
   Map mode too (iOS screenshot 2026-10-26): the list/map toggle in the header, numbered pins with the address
   label, the current-position dot, a collapsible stop card (time, distance, "1/3", name, address, last-visit
   line) with **Directions** (filled) and **Move** (tonal) buttons, locate-me button — Android's map icon must
   open the same thing.
   **Sign-up must flow into the EXISTING onboarding path** (Tim, 2026-10-26): `app/Modules/Onboarding/Services/
   OnboardingService.php` — Name → Company → QuickBooks → Your books → Accountant → Done (`/crm/onboarding/`,
   each step owned by a head; order/skips in ops_settings), plus the planned Penny-first unlock
   (`docs/plans/2026-10-24-claudia-onboarding-penny-first.md`). Sign-up = create the business + owner login, then
   the same steps, on the phone — not a second onboarding.
   **Built 2026-10-26 (both halves, local only).** App: `codex/native` 9776382…efcf6a9 (Codex builds 24/24b, behind
   `android.native_signup`). Server: `public/api/auth/signup.php` (claims a pre-provisioned, unclaimed business — owner
   email must match, claimed = `ops_settings tenant_claimed_at` or the owner has signed in; 2–60-char name),
   `public/api/onboarding/map.php` + `step.php` (JWT; `SignUpService`, `OnboardingStepService`,
   `OnboardingApiPresenter`; spec `docs/plans/2026-10-26-signup-onboarding-api-codex-spec.md`). Walked end to end
   against an unclaimed local tenant (`fern`). **Deployed 2026-10-27 (Tim's go):** the three endpoints, the three
   services, `JwtService::generateMobileJwt`, the transaction-joining `BusinessIdentityService::upsert`, and the feed
   keys `native_signup` / `native_crew_schedule` both OFF in `public/crm/downloads/app-version.json`. Checked live:
   mowology.ca → 404 not_available; testcompany.ittai.ca → 400 field messages, 409 taken, 405, 401 without a token.
   OPcache reset still owed (admin click) before the first real sign-up — the two edited helpers are only reached on a
   successful claim. **Owed:** `user.business_name` in `token.php`; run the real app through sign-up on the emulator
   (rebuild with `-PtenantTestHost=<fresh unclaimed key>.ittai.ca`); Tim's open calls — crew fifth tab, search scope,
   map tiles.
   **Superseded 2026-10-27 — sign-up v2 (Tim: "you would just need your full name, cell phone number, email address, and
   create a password, verification should be done then go to screen one").** Sign-up asks only for the person; a 6-digit
   code by email (SMS best effort); then every existing onboarding step on the phone (About you → Company lookup/confirm →
   QuickBooks connect from the app via a Custom Tab, or Your books → Invite accountant → You're set up). Server: spec
   `docs/plans/2026-10-27-phone-signup-v2-codex-spec.md`, Codex build signup-v2, commits 5d3af841b + 2222998af
   (`signup.php` request code, `signup-confirm.php`, map/step `data`, `quickbooks-start.php`, phone path in
   `quickbooks-callback.php`, QuickBooks redirect URI follows the business host). App: `codex/native` fde10c6 + 6154d9d
   (Android session spec `docs/plans/2026-10-27-android-phone-signup-v2-codex-spec.md`), walked end to end on the emulator
   against `sage.ittai.ca`. Tests 3613 web / 516 per Android flavor. **Deployed 2026-11-02 (Tim's go, switch still OFF):**
   the 12 server files via `scripts/deploy-safe.sh`; checked live on testcompany.ittai.ca (v2 four-field 400s, 409 taken,
   405, 401 on map/step/quickbooks-start, callback with a bad phone state falls through to the login redirect) and
   mowology.ca (404 not_available); OPcache revalidated on its own. **2026-11-03:** Ittai-flavour test APK with the two-people
   icon, "Ittai" label and Ittai splash at `/crm/downloads/ittai-testcompany-signup-v2-test.apk` (codex/native d28a824 + a3f1083,
   not pushed). Per-business feed override live: `/crm/api/app-version.php` merges `app-version.<key>.json` over the shared file on
   a business's own address (`AppVersionFeed`); `app-version.testcompany.json` turns on native_auth, native_signup,
   native_crew_schedule; mowology.ca feed unchanged (checked). Intuit redirect URI for testcompany added by Tim; the phone
   connect round trip still needs Tim's Intuit sign-in. **Owed:** add each further business's callback URL to the Intuit app
   (testcompany first) and prove the Intuit tab round trip on production; SMS leg unproven; Tim's call: after "Yes, I use
   QuickBooks" + Skip, the map marks "Your books" Not needed (existing web rule) — keep, or ask again?
   **Rehearsal walk 2026-11-03 (Tim's phone, rehearsal.ittai.ca, final APK):** step 1 proven — fresh install opens native
   "Create your login". Two first-impression findings for the Android session, Tim to decide after the walk: (a) no visible
   splash or welcome — the Ittai splash exists (`tenanttest/res/values/styles.xml`, hexagon icon) but MainActivity is a UI-less
   dispatcher that finishes at once and nothing calls `installSplashScreen()`, so the Android 12 splash is a sub-100 ms flash;
   no welcome composable exists; (b) the brown "TEST TENANT: <host>" banner is gated by the flavour (`BuildConfig.IS_TEST_TENANT`,
   `app/build.gradle:70-76`), so a build for Claudia would show "TEST TENANT: claudia.ittai.ca" — dropping it needs a non-test
   Ittai flavour (and the default-on native switches keyed off a separate `IS_ITTAI` field, not `isTestTenant`). Also: "Back"
   on the first screen. **Walk completed to the day screen 19:23** (two server fixes deployed mid-walk: sign-up
   duplicate-key 500, registry false warning). Blockers + full findings list in
   `docs/plans/2026-11-03-claudia-rehearsal-runbook.md` → "The walk": QuickBooks import off on production (Accept
   refuses, Continue loops), TEST TENANT banner, Claudia's Intuit URI; plus ~12 first-impression app fixes
   (incl. "Charlie" → Kaname on Android).
   **Migrations per business (found 2026-11-03):** a migration run on Mowology after a business is provisioned never
   reaches that business (walk-1 rehearsal lacked `snow_runs`). Needs a per-business migration runner before more
   founding businesses; until then provision Claudia's after the latest migrations.

- **Every other cron is still Mowology-only (found 2026-11-03).** Business databases had never run a cron (0 `cron_runs` rows). Otto's pins + lot borders now fan out per business (`BusinessCronFanout` from `generate_visits`, `--tenant=<key>`). Reminders, pollers, Charlie's brief, weather moves and the rest still run for Mowology only. Decide which run per business (some must not: sending and charging crons refuse test businesses) and route them through the same fan-out, one cron at a time, checking `cron_runs` in each business after.
- **QuickBooks import drops the property's province (found 2026-11-03).** `QboOnboardingService::applyClients()` passes `property_address/city/postal_code` but no province, so every imported property is stored as the default 'BC'. Fine for Claudia. For a business outside BC, Otto would wrongly treat its properties as BC. Pass `ShipAddr.CountrySubDivisionCode`.
- **Lot maps outside BC:** `OttoAutoPlaceService::LOT_MAPS` has BC only. Other provinces show "no public lot map for <province> yet — draw the border". Add a source per province when a business there signs up (Alberta: municipal open data, e.g. Calgary parcels; research needed).
- **Local harness:** `scripts/local-site.sh php …` does not set the dead proxy that `start` does, so a CLI run reaches real outside services. Export the proxy variables in `php_run` too.
- **Button sizes, styles and positions are inconsistent (Tim, 2026-11-05: "We should review styles sizes and positioning rules").** v1.2 has only touch-target sizes and "one dominant action" (`.claude/skills/ittai-design-studio/references/v1.2-rules.md`), no button ladder or placement rules, so every screen invents its own. Seen 2026-11-05: web e-Transfer card, a small "Record payment" with a tiny "×" beside it and eight unlabelled stacked fields; the iPhone visit screen, "Skip Visit" as big as "Start Job", "Last"/"This" wrapping to "Las t"/"Thi s", the centre bead covering the Photo proof tiles and list rows (content not inset above the bar); the Penny duplicate card, small buttons too close together. Icon sizes vary too (Tim, same day): e.g. 22 pt tab icons beside 24 pt and 1.35× ones, mixed SF Symbol weights and sizes in rows and tiles. Do: inventory every button, icon and action placement on iOS, web and Android (Codex, code only); write the rules into v1.2 (button sizes per role, an icon size scale per context (bar, row, button, tile), square decision tiles, gaps, where actions sit, destructive kept apart, content inset above the bar); then fix screens worst first.
- **Streamline services and how the system reads them (Tim, 2026-11-05).** Service names live in several places (`job_plans.service_type`, quote/invoice line items, Jobber `subject`/`line_items`, products) with no single list, so Mia can't reliably tell a fall cleanup from a regular visit. The payment thank-you ask (`docs/plans/2026-11-05-payment-thank-you-seasonal-ask.md`) is limited to fall cleanup until this is done. Do: one service list per business (tenant-neutral), each history source mapped to it, then widen the thank-you ask and `MiaFinder::seasonal()` to every seasonal service.
- 2026-11-05 iOS top bar to complement the meniscus tab bar: quiet flat bar, avatar · day title (tap = date picker) · one navy +; map becomes a List/Map toggle by Mine/All crews, refresh = pull-down; "Today" chip only off-today. Needs a design-studio round.
- 2026-11-05 Visit length from GPS on site (phones + truck), timer only when it agrees; one writer (Otto). Plan in chat 2026-11-05.
- 2026-11-05 Add a client, job and quote from inside the iPhone app (Tim: "We should be able to add a client/job/quote within the app"). Today the bar's Add dial has Job and Client, both through FieldJobView (`Features/Schedule/FieldJobView.swift`), and Client always creates a job too. Missing: a client on its own, and a new quote (the app can only list quotes, `GET /api/schedule/quotes`). Needs a plan: which quote fields on a phone, the create endpoint, Sam's rules. Then Quote joins `AddDialItem` (`App/AddDial.swift`).

**Claudia keeps QuickBooks (2026-11-04):** sending to QuickBooks is built but runs for Mowology only; per-business
run, imported-customer links, her own items, phone switch, payment bank-match test, Production keys; shadow
ledger (`books_mode`, monthly compare with her QuickBooks reports) when she considers switching.
Plan: `2026-11-04-claudia-quickbooks-sending-plan.md`.

**Walk 2 web view of rehearsal2 (Tim's screenshot, 2026-11-04, /crm/jobs/schedule.php):** a business that is not
Mowology still shows "mowology" as the sidebar logo, "mowology CRM © 2026" in the footer and "Vancouver, BC" (not
from business settings); the sidebar head is "Charlie" (should be Kaname); the visit-complete panel ("Job Complete!",
"Add small job or extras", "Complete & Create Invoice", "Complete — No Invoice") renders unstyled and visible under
the week grid; dashboard targets show Mowology's ($6,000 week target). All first-impression items for Claudia.

**QuickBooks import field check on rehearsal2 (2026-11-04, `scripts/qbo-import-check.snippet.php`):** 60 clients,
23 services, 44 suppliers, 13 account mappings, business GST. Names, emails, phones, mobiles, companies, service
prices and QuickBooks item links all match; a second Accept added nothing (idempotent). Two real gaps:
1. **Billing address of a person client is dropped** (44 of 60): ContactService stores billing only on `companies`;
   a QuickBooks customer with no company keeps its service address (property) but loses a different mailing address.
2. **Two clients at the same street address:** `addPropertyToContact`/createContact reuse the first property with that
   address, so the second client gets no property of its own (36 here, because the sandbox shares 24 public addresses;
   in real data: couples entered as two customers, landlord + tenant, duplicate QuickBooks customers).
Also seen: the QuickBooks page subtitle still says "nothing is sent to QuickBooks yet"; a new business's chart of
accounts is Mowology's (Lawn Care, Hedge & Shrub, Snow Removal, Landscape Design).

**From Otto's session (2026-11-04):** generate_visits, run per business, skips snow statements and the approved-quote
sweep behind `Tenant::isDefault()`. Gate them on `Tenant::isTest()` instead (G9 added it), so real Ittai businesses
get them after phase 2.
