# Ittai backlog — features to carry from Mowology to every tenant

The running list of what Ittai tenants need. Add to it as Mowology proves things. Each item names
what is **shared** across all tenants (knowledge, formats, rules about the world) and what stays
**private** to a tenant (their people, prices, accounts and decisions). That split is the standing
rule.

Direction (council, 2026-10-12): keep the data discipline-neutral, and build and sell to field-service
trades first. The AI back office is the edge.

## Plant report for the client (Tim, 2026-11-11) — queued

Tim: the Work Record's plant lines "could become a client report that either sits on the portal or is emailed". Today the crew's Work Record shows
each plant's own photo and a "now is a good time to divide or move it" note (`PlantLineInfoService`, read-only, from approved care cards). The client
version reuses it: per plant, its photo, what was done on which visit (`plant_work_log`, already read by the visit report and the portal), and what is in season next.
Open: portal-only or emailed too (email goes through `sendCrmEmail`, with a PDF if it is the invoice-style send); which notes a client may see (care-card tips are
internal until Tim approves wording); photos of client properties on the portal need the same token rules as the visit photos. Needs its own plan before code.

## Subcontracting — what is left after the web version (live 2026-11-10)
- Native iPhone card (timeline, Accept), schedule card label, Otto line when a job is close and not yet accepted.
- Their photos onto the visit record on Accept; their invoice attached to the expense in Penny.
- Plan-level profit: stop adding estimated labour on a subcontracted visit.
- Hold WorkSafeBC clearance and insurance per subcontractor.
- A subcontractor who is also on Ittai: the job lands in their schedule and their invoice arrives as the bill (separate databases, needs a handshake between businesses).

## How a business starts and gets paid (Tim, 2026-11-10) — before the first real business
Ittai no longer depends on invoicing as its centre: clients, the schedule and the crew's day are the core, and the invoice is
the handoff at the end. Without QuickBooks a business can still invoice, show How to Pay and keep its books, but has no Pay
button and nothing marks an invoice paid. Each item needs its own plan.
**Order (Tim, 2026-11-10):** Ittai is business operations and organising the owner, measured by making the owner money. Invoicing is a
bonus, not the primary, so setup comes first and the two payment items follow it.
1. **Two setup paths, not one with a fork.** Measured 2026-11-10 on a fresh local business: 14 screens plus connecting QuickBooks,
   never asks what work they do, ends on an empty day. Symphony by Wix started in two tapped answers and about 3 minutes, but 45 minutes
   later still lacked the business name, phone and email, and its quotes and regulars stayed empty until a Wix site and calendar were connected.
   - **Has something:** fetch it, then "Are these your clients?" (keep or skip). Order: QuickBooks, phone contacts, Google Business
     (fills name, address, phone), Outlook, Gmail once Google's yearly assessment is priced, Facebook/Instagram messages, WhatsApp.
   - **Has nothing:** "Who's your next job for, and when?" A client and a schedule entry from one real job; the price can wait.
   - **Both end on the schedule** (Tim started on Jobber with scheduling). Check how many steps one new job takes today.
   - **Ask when it matters:** business name before the first thing goes to a client, GST at the first invoice, payroll at the first
     employee, accountant when there is something to show, quiet hours before the first notification. Terms stay up front.
   - **Empty cards sell the next step:** each says what will appear there and has one button to fill it (seen in Symphony).
   - **Owed first:** a business creating itself (today Tim provisions it and adds its Intuit address by hand), iPhone sign-up.
2. **Pasted payment link** — the business pastes its own Stripe, Square or PayPal page; it becomes the Pay button on the customer
   invoice page when `Tenant::takesCards()` is false and there is no QuickBooks link. The money goes straight to the business,
   and Ittai only sends the client there. Ask the lawyer first (Bank of Canada examples treat a redirect to someone else's
   checkout as not a payment provider). **Guided Stripe (Tim 2026-11-10):** the owner opens their own account with our step-by-step
   help; Ittai never enters their details. **Knowing it's paid:** add the invoice number to the link (`client_reference_id`), the owner
   pastes a read-only restricted key once, and a 15-minute job reads completed payments (same shape as `QboClientPayService::pullPaid`).
   One "customer chooses amount" link serves every invoice: Ittai adds `prefilled_amount` and `prefilled_email`, no write key. The client can
   still edit the amount, so record what was actually paid and flag a difference. **The client's view:** the invoice page comes first, Pay opens
   Stripe with the amount filled and the owner's logo, and the link's redirect (`{CHECKOUT_SESSION_ID}`, UTM codes) returns them to the invoice
   showing it paid. Stripe's page cannot show the invoice number. Not yet tried in Stripe's test mode. **Shared:** the rule and the button. **Private:** each business's link and key.
3. **e-Transfer matching for every business** — exists for Mowology only; each business needs its own mailbox and poller
   (`project_etransfer_inbox_poller`). **Shared:** the matching rules. **Private:** the mailbox and payers.

## Build queue (Tim, 2026-11-05: "Queue all other items") — in order, one at a time after the payment thank-you
1. ~~e-Transfer memos naming invoices without the year~~ — DONE (b13a1aa95, seen 2026-11-06).
2. ~~Duplicate receipts on the phone~~ — BUILT 2026-11-06: Merge / Different square buttons, Penny's verdict + per-vendor record, facts side by side, Undo, Split off (iOS 1.3.89, web card, `expense_merges` migration 1442 — to run). Left: synchronised zoom across the two photos; web "Also on this purchase"; Penny asking per vendor for level 3 once her record is long.
3. **Button and icon sizes, styles and positions** — inventory, then rules into v1.2 for Tim's approval, then fix screens (entry below).
4. **Android Kaname tab** — `docs/plans/kaname-tab-and-screen-heads-spec.md` Android steps (Codex writes, Claude reviews).
5. **Web: Kaname as a fixed name** — remove the `chief_name` override in `app/Services/HeadIdentity.php` after checking no business set it.
6. **Streamline services and how the system reads them** (entry below), then widen the thank-you ask beyond fall cleanup.

## Queued from Tim, 2026-11-05 (after Sam step 2)
- **Competitor checklist, reviewed quarterly** (Tim, 2026-11-08: "BuildOps has some strong features"): `docs/marketing/competitor-checklist.md` — BuildOps, ServiceTitan/Aspire, Jobber, Housecall Pro, LeanScaper, Hootsuite. Ideas from the first pass: Sam finds work noted on a visit but never quoted (BuildOps Revenue Agent), a stalled-jobs list in Kaname's brief, job costing, an AI receptionist for Yui, morning-huddle capture for Otto.
- **Who is the contact now — one roster every head uses, kept true by payments and mail** (fundamental, Tim 2026-11-07) (Tim, 2026-11-07: Mia thanked a strata rep who had
  left the strata; someone else paid). Payer on the payment record ≠ the invoice recipient, or a bounce / "no longer
  with" reply → Mia holds the thank-you and Yui asks "Make <payer> the contact?". Plan, waiting for Tim's OK:
  `docs/plans/2026-11-07-contact-left-the-building.md`.
- **One search box for the owner — phase 1 BUILT 2026-11-06** (iPhone Search tab + web global search on
  `UniversalSearchService`; `ClientFactAnswerer` answers quote sent? / owes? / next visit? / last message? with
  Open/Call/Text/Email; Ask Kaname row; client page; picks learned in `search_picks`, migration 1441). **Phase 2 BUILT
  2026-11-06:** client chip ("Only Linda" swipe → "Linda ×" + "unpaid"), filter words (overdue, unpaid, draft, open,
  accepted + group words), commands (call/text/email/nudge/remind/open), Nudge/Remind open the owner's own Messages/Mail
  with the text written (nothing sent by the server), voice search, "you picked before" for a word that found nothing.
  Still open: a native company page; "invoice linda" (create an invoice by voice); the shared per-trade word pool.
- **Ask Kaname buttons:** (Open / Call / Text / Email shipped with the owner search, 2026-11-06; Nudge / Send reminder
  still to do) every answer gets up to 3 rule-chosen actions for the record it is about (quote sent, not
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

## Receipt reading — what the outside world does better (research 2026-11-06)
Sources: open-source (Donut, LayoutLMv3/LiLT, PaddleOCR, docTR, invoice2data) and commercial (Dext, Hubdoc, AutoEntry,
Veryfi, Taggun, Mindee, Azure Document Intelligence, AWS Textract AnalyzeExpense). Checked against our code the same day.
1. **Bake-off first:** ~100 stored receipts (gas station, supplier, scanned PDF, invoice table) through AWS Textract
   AnalyzeExpense and/or Azure prebuilt-receipt (~$8–10 per 1,000 pages, so ~$1.50/month at our volume; Veryfi has
   100 free/month), scored per field against owner-approved data with the existing parser scorecard. Decides buy vs build
   for line items. Unknown: whether either splits BC GST from PST. Needs Tim to open an AWS or Azure account (keys in
   secrets.php). Crop client names before sending.
2. **AI step sees the photo:** `extractLineItemsWithLlm()` sends OCR text only. Send the cropped image too, keep the
   "items must add up / be printed" checks. Fixes column-split and invoice-table receipts where the text order is the fault.
3. **Column-aware rows for the Vision read:** cluster word x-positions into a name column and a price column, pair by y.
   Also: `reconstructLinesFromVisionResponse()` joins with one space, which `extractLineItems()` does not read as an item
   (the phone-box builder uses two). Coordinate with the receipt-layout work in ReceiptParser.php.
4. Not gaps (already here): per-vendor learned layouts (`ReceiptLearning`), per-field confidence, Penny's per-vendor
   autonomy. Low value: supplier-portal fetch (Dext Fetch, reviewed as weak); self-hosted models (need Python + GPU).
   Shared: which API wins per receipt type. Private: the receipts themselves.

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

## Kaname follow-ups (2026-11-03)
- **Penalty dates first?** Tim's rule (2026-10-11) vs the learned ranking: give forced-red lines (`BriefStatus::forcedRed`) a floor in `CharlieRankService::rank()` if he says so.
- **Group runs of one kind** (Sam 19 campaign replies, Otto 20 job timers, Penny 5 "same purchase?") into one line with a count: each head's own session, it needs a noun per kind.
- **195 of 200 lines are status sentences, not decisions** ("Send 28 invoices? Tap"): head by head.
- **Penny's routed mail:** "Meghan wrote about billing: can we book you for a fall cleanup" is a booking request filed as billing (InboundTopicClassifier).
- **Sam marks 25 of 31 lines urgent** (19 are replies to Mia's fall email): fix the priority at the source; Yui marks all 9 approvals urgent.
- **Show the status chip in the NEW dashboard layout** ("Three things need you today"): the JSON already has `status`, `trend`, `urgency_notes`; only the classic card draws them.
- **Charlie status thresholds** (`charlie_status_money_red`, `charlie_status_deadline_days`) are defaults until Dec 1; review then.

## Yui and Otto-leads follow-ups (2026-11-03)
- **Urgent email for a held message** (payment-detail change, wire, password ask): `CharlieUrgentService` is ahead on production; add a Yui source
  there (key `urgent:yui:hold:<hash>`) once it is pulled into the branch. Today a hold is priority 1 in Yui's card and Charlie's brief only.
- **Cold pitches in Sam's replies:** "A customer replied 'Hello' — Tree Service App" is a vendor pitch with a contact record; inbox-manager files pitches
  silently. A pitch rule (sender describes their product, not a problem) belongs with `UnclaimedReplyService::isAutomated()`.
- **A client who leaves rude-to-Tim** (contact #218, Nov 2: "exceptionally rude", switching company) is in no queue; a lost-client note should reach Tim.
- **Yard for Otto's route days:** `ops_settings` closer_depot_lat / closer_depot_lng are empty.
- **A suite failure that did not reproduce** (once in four full runs on 2026-11-03); if it returns, capture the name.
- **`git push` to `feature/language` is refused by the permission layer** in this session; 20+ local commits wait on the worktree branch.

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
5. **Yui** — DONE 2026-11-03 (`docs/plans/2026-11-03-yui-harvest.md`) — inbox-manager (needs-you / drafted / handled; >5 "needs you" means the threshold is wrong; archive never delete),
   voice profile + slop test, outreach cadences (0/+4/+11/+21; never two sends in 48 h; trades 6–8 am or after 5 pm),
   customer-support `ticket-triage` / `draft-response`; diff against YuiRules / YuiDraftService / IcloudInboxRouter.
6. **Kaname (Charlie)** — DONE 2026-11-03 (`docs/plans/2026-11-03-kaname-harvest.md`) — monday-brief ("merge, don't staple"; "the one thing" closes it; skip missing reports silently; ask
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

- **Crons per business: phase 1 LIVE 2026-11-04.** `app/Core/Cron/business_crons.php` (started from pulse_check every 5 min) runs the 17 crons in `BusinessCrons::REGISTRY` for every business. qbo_sync and contract_billing fan out inside their own crons (another session, G1–G9). Still Mowology-only, from Codex's audit of all 58 crons (2026-11-04):
  - **Phase 2, after the outbound gate (`Tenant::isTest`, G9) is live:** client/crew sending and billing — campaign_sender, automation_runner (subject defaults to "Message from Mowology"), invoice_reminders (hardcodes "Mowology" and (778) 846-9273), contract_billing (done by G9), sync_ledger, auto_clockout, forgot_clockout_sms (hardcoded phone), tracking_silent_alert, quote_request_reminder (texts a global admin number), seasonal_triggers (queues campaign mail), and generate_visits' snow statements and approved-quote sweep. Fix each hardcoded identity first (business_settings).
  - **Phase 3, needs per-business settings:** weather_guard and seasonal_outlook (Vancouver station/forecast fixed), penny_prepare / process_ocr_queue (Mowology's Anthropic / Google Vision keys: who pays?), push_drain (one APNs app), social_publisher + mia_* (per-business Google/Meta accounts), autopay (Stripe Connect), trackimo_poll (per-business tracker).
  - **Never per business:** the mailbox pollers (office@, receipts@, iCloud, e-Transfer, Yardi), CMS/website crons, security_posture, schema_snapshot.
  - Live-only crons not audited (source not in the repo): mia_events_drain, penny_chase, agent_detect, agent_eval, audit_export.
  - The server timezone is America/Vancouver for every business (config.php). Business runs follow the registry times in Pacific; Mowology's real crontab fires in server time and does not always match the registry.
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

### Status 2026-10-26 (evening) — Codex builds 24 + 24b on `codex/native` (9776382, 9bfaa3c), checked on the emulator
Done in the tenanttest flavor: Ittai symbol launcher + splash; sign-in shows the business name, "Crew app", neutral note, brand-indigo button; per-flavor
generated tokens (`ittai` neutral tenant, no Mowology green); five icon tabs (Schedule · Time Clock · Search · Receipts · AI Office/Account); crew never
requests team-mobile; iPhone-style stop cards (est. arrival · distance, service pill, history line, status, client, crew names for admins, n visits, ⋮ menu);
week-strip chips; map with numbered pins, address chips, position dot, My location, collapsible carousel with Directions / Move (tonal); sign-up screen and
onboarding map behind `android.native_signup` (server contract named in docs/plans/2026-10-26-android-ittai-crew-app-codex-spec.md §4 — NOT built).
Left over (small, from the emulator run):
- The job-22 header week strip still shows ONE-letter day names (M T W); Tim asked for MON/TUE. One-line change in the native header strip when `native_crew_schedule` is on.
- Map: address chips overlap neighbouring pins on dense fake data; "My location" is a text button, not an icon; no map tiles (no SDK/key in the offline build — Tim: Google Maps key or osmdroid?).
- "⋮" / "⌄" are text glyphs (render fine in Inter on the emulator); could become drawables like the tab icons.
- Seeded `crew`/`manager`/`admin` passwords in `scripts/local/.credentials.local` no longer matched the DB (401); the crew one was re-hashed locally to the recorded value. Re-check after the next `seed.php` run.
- Server side still owed: `POST /api/auth/signup.php` (claim an unclaimed pre-provisioned tenant), `GET/POST /api/onboarding/map.php`, `POST /api/onboarding/step.php`, `user.business_name` in token.php — second spec.
- 2026-10-26 evening, after Tim's look: (a) the "Schedule / Mon 26 Oct" title bar is gone on the Schedule tab (ac3688f); (b) sign-in now shows the approved v1.3 gold-beam "ittai" wordmark drawn from the SVG masters (efcf6a9; masters copied to the Android repo `docs/design/ittai-v1.2/wordmark/`; rules + boards recorded in the Ittai design skill `.claude/skills/ittai-design-studio/references/wordmark-v1.3.md` on branch claude/ittai-design-studio-skill-889089); (c) fifth tab = AI Office for admin/manager, Account for crew (iPhone rule) — Tim: "account is meant to be AI Office" → decide whether crew get AI Office too (the server refuses office data to crew today, so it would need a crew-shaped office); (d) Search "practically anything" incl. vendors — scope decision pending (crew vs office, money); (e) the owner's AI Office overlay still says "Charlie" — the Kaname rename has not reached Android.
- 2026-10-27 — **the three missing New rows built** (Tim: "New client, New quote and Add a note. Build that"). Server (commit a3134ec4, deployed): `POST /api/schedule/field-job {action: create_client}` (client + one property, no job; shares the contact step with create_client_job) and new `POST /api/schedule/client-note {property_id, content}` (clients.view, idempotent); 5 tests, suite 3599 green. Android (codex/native, Codex 82828c1 + Claude fixes): the sheet is now Snap a receipt · New job · New client · New quote · Add a note · Clock in. New client = a client-only mode of the add-job flow (address prefilled from GPS, "Save client", then "Add a job for them" / Done). New quote = pick today's stop or a searched property → the recommendation flow from its visit; a property with no visit today gets "New job here" first (there is no blank-quote route, by design). Add a note = same picker → a visit note (today's stops, durable queue) or a client note (searched property, new endpoint). Codex review findings on the server: one real (JSON array cast to property 1) fixed; three pre-existing (idempotency keys not scoped per endpoint; check/store race; create_client_job can orphan a contact when the address is already attached elsewhere) — follow-ups, not new. OPcache reset still needs Tim's tap for create_client to be live. iOS still owed for all of navigation + these rows. Seen: production claudia.ittai.ca 404s every /api/* path (token.php, schedule/day) while /crm/api/app-version.php answers — the tenant host does not serve the JWT API.
- 2026-10-27 — **iOS showed last week's photos on today's visit (Tisdall)** → not a photo bug: the stop reschedule carried a photographed, never-completed `job_visits` row onto this week's stop. Built `VisitCarryGuard` (commit 1953cd4b, deployed): a visit with an After photo or completed_at is completed where it is (completed_at = photo time) and a fresh visit goes on the new date; wired into `StopRescheduleService`, `VisitLifecycleService::moveVisit`, `UnscheduledWorkService::moveVisitDate`. Codex: 2 real (single-visit paths now atomic; duplicate sequence retried), 1 narrowed (stop-level settling = scheduled only), 1 not real. Suite 3610. **Today's Tisdall data is NOT repaired** (last week's visit still carries this week's stop; Tim asked only for the guard) — repair snippet is a prod-run away if wanted. Also seen: `/crm/api/reschedule-visit.php` answers HTTP 500 to an anonymous request (siblings 302) — identical to repo, untouched by this change, cause unknown (error_log not at public/error_log).
- 2026-10-27 — **Ask first went to the caretaker, not the property manager** (Tim on site at Tisdall: "trying to send this with ask first to the property manager"). `FieldAskService::recipients()` never looked at `properties.property_manager_id` while invoices already route there. Now (commit 49905f88, deployed): the manager firm's billing/primary contact wins Ask first and is the quote recipient; an unsent draft follows the live rule when opened; the firm's contacts count as B2B for consent; iOS label "property manager" in the next build. Codex: 3 real fixed, 1 documented (email-only routing still needs a contact row), 1 **pre-existing follow-up: `companyManaged()` grants the B2B consent basis to ANY contact once the property has a `company_properties` row** — tighten to contacts related to that company. Unverified: whether 6015 Tisdall actually has a property manager set (production reads blocked in this session).
- 2026-10-27 — **Mowology Android flavor in the Ittai design** (Tim: "review the mowology app and brand it with ittai design") → review in `docs/plans/2026-10-27-android-mowology-ittai-branding.md`: the Ittai screens are already in the flavor behind the live `android.native_*` switches (OFF; 1.6.0 crews see the WebView); needs Tim's go on the switch flip (safe for 1.6.0, which ignores the new keys), the icon/name call, and a phone trial of a Mowology-flavor test APK with his login.
- 2026-10-26 night — **navigation adopted** (Tim: "Yes adopt" on the round-2 mock, canvas KCesZ3bUE654SMPDopFzzV): Ittai symbol 40 px centred in the top bar opens AI Office (admin/manager) or Account (crew) full-screen; the bottom bar is Schedule · Time · **+ New** · Search · Receipts, New opening a full-height sheet (Snap a receipt · New job · Clock in — New client / New quote / Add a note omitted until a native route exists); month "Oct 2026" beside Today. Android: Codex round 3 spec `docs/plans/2026-10-26-android-ittai-crew-app-codex-spec-round3.md`. **BUILT on codex/native** — Codex a4a9f88 + Claude review fixes (compact Today, aligned New slot, MON/TUE strip); emulator-checked on claudia.ittai.ca; tests 503/503 per flavor; nothing released. **Owed: iOS to match** (MainTabView: symbol entry, + New, "Time" label). Seen in passing, not fixed: the opening brief shows "as of M10 26 9:32 pm" (a date-format slip in the overlay, Kaname rename also still pending).

## 2026-10-27 — Android phone sign-up v2 (Task 28, codex/native fde10c6 + 6154d9d, NOT released)
- Built from `docs/plans/2026-10-27-android-phone-signup-v2-codex-spec.md`; walked end to end on the emulator against sage.ittai.ca (now claimed). Behind `android.native_signup` (forced on for the test-tenant flavor only).
- Owed: the Intuit round trip from the phone on production (testcompany); the SMS leg; the owner-facing wording of the server's 409 `not_set_up` (today it names secrets.php); a phone trial by Tim.

## 2026-11-04 — from Tim's tablet walk and two design references (not built)
- **Sign in with Google (Android)** — Tim: "Sign in with google is useful on android"; Google project must be **Ittai** ("Then it has to be ittai"), owned by ittai@mowology.ca for now ("we will change later"). Spec: side-session notes `2026-11-04-sign-in-with-google-spec.md`. Server half: parent Android session; app half: Codex task 33. Blocked on Tim signing ittai@mowology.ca into Chrome (or creating the project himself).
- **Wide-screen sign-in** — two panels (navy brand panel + form) sliding between sign in and sign up, for web at desktop width and tablet landscape only; phones keep today's flow. From a "interactive login page" reel Tim shared.
- **"Meniscus" bottom bar** — the active tab lifts out of the bar as a bead in a notch and slides between tabs. Fits the adopted bar (Schedule · Time · + New · Search · Receipts) if done in v1.2 terms: ONE accent (tenant colour, not a colour per tab), no glow, no drag-the-bead, still under reduce-motion, ≥48 dp targets for gloved hands. Needs a design-studio round before any build.

## 2026-11-04 — Tim's rehearsal2 tablet walk: findings (Android, codex/native)
- **Meniscus bar BUILT** (codex 3d5b627 + Claude a3410cc, + this fix): Ittai builds only; bead replaces the raised + New. **iOS to match** — Tim: "Can we do it on ios as well?" (iPhone bar today: Schedule · Time Clock · Search · Receipts · AI Office).
- **Phone permissions step** — Tim: "the app needs to setup location permissions, camera usage, always in etc". Onboarding should ask for location (Allow all the time, for tracking), camera and notifications, each with a plain why. Not built.
- **Onboarding axis on tablets** — Tim: "On the tablet the number is very small… alot of space not used"; wants better styling and axis animation. Needs a tablet layout (larger step number, wider content, use of width) and motion on the axis.
- **Kaname brief clips on the tablet** — "Start the day" button cut off at the right edge (fits on a phone).
- **Finish screen axis dots** don't line up with the rows.
- **New client form** says the address was "filled in from where you're standing" while the street field is empty (only City filled).
- **QuickBooks "Back to Ittai"** does nothing in the Chrome Custom Tab on the tablet (`ittai://return` resolves when sent directly). Proposed: `intent://return#Intent;scheme=ittai;end` / auto-redirect. Server: parent session.
- **QuickBooks Read/Accept skipped** — Tim reached Done without reading QuickBooks; Done should require or offer the read. Sandbox customers need real Vancouver (public-place) addresses — Tim: "We need vanciuver addresses in there". Imported data must match our tables — Tim: "We need to ensure the data pulled from quickbooks matches our databases".
## Area tool ideas from AreaMap Pro (App Store, 2026-11-02)
Tim asked which AreaMap features fit `crm/products/area-measurement.php`. Already have: polygon/polyline, satellite/hybrid, perimeter, groups, undo/redo, save-to-property, PDF/JSON/screenshot, per-sq-ft pricing, plant focus.
- **Material estimates from the shape** (their main paid feature): area × depth → mulch/soil/gravel yards, sod rolls, seed lbs; polyline length ÷ post spacing → fence posts/rails. Feeds quote line items directly. Logic in a `MaterialEstimateService` with a test.
- **Street View peek** before driving out: Google Street View is already licensed with our Maps key; one panel on the measurement page.
- **Metric toggle** (m², ha, m): cheap; matters once Ittai has tenants who think metric.
- **Feature pin with coordinates** (a tree, a valve box) saved on the property: builds on the 2026-11-02 photo pins; same `media_assets` position columns or a small `property_markers` table.
- Skip: GPS boundary walking (city lots trace faster from satellite; background tracking is heavy in the WebView), KML/GPX/GeoJSON export (no buyer).
- **Otto's icon (Tim, 2026-11-06):** "Otto is a hex icon now I thought". Check which icon Otto (operations head) shows in the iPhone app against the current head icons and match it. Own chat.
- **Add a job: repeat options (Tim, 2026-11-06, iPhone "New client" step):** the Week / 2 weeks / Month segmented control is hard to tap; wants a day picker, Annual and Custom repeat, and "best day to service regularly". Bring the iPhone sheet up to the web's repeat options. Own chat.
## Market research per product: find local suppliers, fetch their prices, ask for delivery quotes (Tim, 2026-11-03)
Tim: "in general our CRM should take a product our client sells and then search for local suppliers and fetch their prices and/or email them requesting delivery prices etc."
What exists (verified 2026-11-03): `MarketPriceService` reads published Shopify feeds weekly (My Garden Bag, live, shared regional layer, migration 1375); Otto's supplier directory and finder; `SupplierPriceListService` (a tenant's own trade lists, private); `SupplierFormService` (Otto fills a supplier's form, sends only on Tim's yes, from office@); the vendor knowledge commons for shared facts. Not yet: nothing starts from a PRODUCT and goes looking, nothing asks a supplier for a delivery price, nothing connects the market table to a product's price outside the mulch fact (being wired 2026-11-03: `MulchPriceBand`).
Shape to plan (own chat, plan first): (1) per product, find candidates (the directory, the web, Tim's names), (2) read published prices where a feed exists (reader per site type; Shopify done), (3) where none exists, draft a "delivery price for <product>, <quantity>, <postcode>" email to the supplier, sent only on Tim's yes from office@mowology.ca, replies read by the existing supplier inbox reader and turned into a price row with the source and date, (4) shared facts (a public price) go to the regional layer, a private quote stays with the tenant, (5) every price carries its source, date and "GST stated or not", never a guess, (6) a read that finds nothing says so. Multi-discipline tenants: no trade words in the schema; the product, not "mulch", is the key. Open for Tim: how many suppliers per product, how often to re-ask, who may be emailed.

## Phone receipt capture: word positions + camera hints (Tim, 2026-11-06, "build all")
- **Word positions:** the iPhone (Vision `VNRecognizeTextRequest`) and Android (ML Kit) send each recognised line with its bounding box beside the plain text; the server builds rows from them the way `reconstructLinesFromVisionResponse()` does for Google Vision. Why: phone plain text puts Chevron item names in one block and prices in another (most of Chevron's 71 unread receipts, replay 2026-11-06). Keep the upload backwards compatible.
- **Camera hints:** edge detection with crop or straighten (iOS `VNDetectDocumentSegmentationRequest` / VisionKit; Android ML Kit Document Scanner) and a "hold flat, fill the frame" hint on blurry or skewed photos. Capture-only stays.
- Needs its own chat started from the desktop app (owns `ios/` + the Android repo + one server row-builder). Could not be started over Remote Control.

## Phone day carousel: the line chooses the day (Tim, 2026-11-06) — BUILT in iPhone 1.3.101
- On phones, swap the working-week strip for a continuous day carousel that snaps the chosen day onto the Schedule
  bead's axis (`ItTabSlot`), so the centre line runs straight from the day → its stops → the Schedule bead (SPEC §13).
  The day on the line is the day shown; swipe or tap snaps a day onto it; "Nov" follows it. No week walls.
- iPad / desktop (≥ 768 pt) keep the week grid (the week at a glance matters for office planning).
- Trade-off: past days sit off the left edge (one swipe; "Today" returns). Mine / All crews already moved above the
  days in 1.3.100 for this. Simulator check before TestFlight (snapping, selection sync).

## Schedule bead cycles its views (Tim, 2026-11-07: "What if the schedule button can switch the view from map to numbered to cards?")
- **BUILT 2026-11-07 (iPhone):** tap the Schedule bead = next view (numbered → cards → time → map), long press = pick, bead shows the current view, top-bar map button gone, remembered per phone; new hour-grid Time view (`DayTimeGridView`). **Left:** first tap scrolls to top when the list is scrolled; Android to match.
- Tapping the raised Schedule bead while on Schedule cycles numbered timeline → map → cards; the first tap scrolls to the top when the list is scrolled down.
- The bead's icon shows the current view; a long press opens a menu of the three; the top-bar map button goes; the last view is remembered per person; VoiceOver says the view and what a tap does.
- Same pattern as Receipts (tap the selected bead = camera, iOS 1.3.81). Android to match later. Needs its own chat (iPhone `ios/` only).

## iPhone smoothness: bottom bar, calendar strip, rolling + (Tim, 2026-11-07: "The main bottom navbar is still not smooth. Also the calendar.")
- **BUILT 2026-11-08 (iPhone), bar moved to Core Animation** (Tim: "ensure smoothness of movement of the bar is AAA, perhaps page load should happen after the bead lands?"). Measured in the simulator with the app blocked for 150 ms just after each slide starts (`-mwBarDemo stall` + `ios/tools/bar-smoothness.py`): the old bar froze for up to 150 ms, the new one never more than two frames. The bead leaves on the tap and the screen changes one turn later (not after the landing: that would add 0.4 s to every tab change). Also: 120 Hz key in Info.plist, long-press-then-hold no longer opens the dial too, a tap on the bead mid-slide no longer falls through to the page, VoiceOver reads "Tab n of 5", Large Content Viewer on the labels, Reduce Motion cross-fades. **To check on Tim's iPhone:** the feel at 120 Hz; a long touch at the large accessibility text sizes (Large Content Viewer beside the long press on Add and Schedule).
- **Android native to match (Tim, 2026-11-08: "once completed do same for android native").** Same review and the same pass on the Compose bar (`ui/ittai/MeniscusGeometry.kt` and its bar): one sampled spring, motion off the main thread's critical path, bead first then the screen, the long-press and mid-slide tap fixes, TalkBack tab position. Codex writes Android and is out until 2026-11-11.
- **Navigation, next (from the 2026-11-08 review, none built):** a light haptic tick on every tab change; tapping the selected tab returns to its top (pop to root, scroll to top, Search focuses its field; Kaname already does); warm each tab's first render shortly after launch so the first visit is not the slow one; the bar leaves with the keyboard's own curve instead of vanishing.
- **BUILT 2026-11-07, iOS 1.3.112:** bar split into still buttons + moving notch/collar/bead (only the bead's glyph per frame); Add's + spins one turn per slot travelled (1 from Receipts, 4 from Schedule), faster the further; calendar chips built once and Equatable. **Left:** the fixed selection ring on the centre axis (a design change), deferring the new tab's first render.
- **Bottom bar** (`ItMeniscusBar.swift`): `ItMeniscusBarBody` is Animatable, so the whole bar (five Buttons with gestures, labels, icon fades) is rebuilt every frame of the bead's slide. Split it: the buttons/labels a static layer, only the bead + collar + notch animated (offset + the notch Shape's `animatableData`). Start the new tab's heavy first render as the bead lands, not mid-slide.
- **Calendar strip** (`WeekStripView.swift`): 181 non-lazy chips (kept non-lazy after the 1.3.101 blank-space bug) are all rebuilt whenever `centred` changes during a swipe, each re-formatting its date in `summary()`, plus a `matchedGeometryEffect` pill. Fix: compute the 181 chip models once (refresh when a week's dots arrive); make `DayChip` skip redraws when unchanged; replace the sliding pill with a fixed selection ring on the centre axis that the days scroll under (same idea as the duplicate number row on the Kaname line).
- **Add's + rotation** (`ItBeadSpin`): Tim asked for more and faster turns the further the bead travels (Schedule furthest). Agreed form: the + rolls like a wheel along the bar, turn = distance travelled ÷ radius, in step with the slide, then settles into the × as now. Reduce Motion skips it.
- iPhone only; render in the simulator before a build. Needs its own chat.
- **Day carousel rebuilt 2026-11-08, iOS 1.3.132** (`DayCarouselView.swift`, rules in `DayCarouselRules.swift`): the pill is fixed on the line and the days pass under it (text turns white exactly at the pill's edge); the day loads only once the strip has come to rest on it, then the list fades in; the day either side is preloaded; fixed stale dots after Mine / All crews, dates beyond the strip's window, "today" after midnight, "Nov" lagging the line. **Left:** how far a flick carries (judge on the phone; one line, `scrollTargetBehavior`); a pause of a tenth of a second exactly on a day mid-drag loads that day early; VoiceOver focus moving along the strip still changes the day (as before).
- **Android to match (Tim, 2026-11-08):** the native branch (`codex/native`, `SchedulePlusScreens.kt`) has no day carousel: three week strips (`NewWeekStrip`, `CrewWeekStrip`, `WeekStrip`) that swipe a whole week and are disabled while a day loads. Build the iPhone's carousel there (fixed pill on the Schedule axis, land-then-load, neighbour preload) from `DayCarouselRules`. Needs its own plan; Codex writes Android and is out until 2026-11-11.

## Android meniscus bar: implement the redesign (Tim, 2026-11-09: "The android meniscus bar has been redesigned and needs to be implemented")
- Next Android build after 2.2.10 (the self-update build), so it reaches the phone and tablet by itself as 2.2.11. Existing Android bar: `ui/ittai/MeniscusGeometry.kt` (task 34, codex/native `3d5b627`, `a3410cc`, `55b2f23`). The iPhone's bar was rebuilt 2026-11-08 (bead on Core Animation, one sampled spring, bead first then the screen; `ItMeniscusBar.swift`, `ItMeniscusMotion.swift`, iOS 1.3.130). **Open:** which design to follow — the iPhone's current bar, or a new mock from Tim.

## One Android app for every business (Tim, 2026-11-09: "resolve the 'mowology flavour' and ittai edition permanently. Having two deployments is wasteful.")
- Today a flavour is a separate build, not a theme: `mowology` (package `ca.mowology.crew`, mowology.ca baked in, native switches off until the feed says true), `ittai` / `tenanttest` (package `ca.mowology.crew.test`, one business's address baked in per APK, native switches on by default). Mowology's crew also still have the older 1.8.0 web-shell line.
- Wanted: one installed app; the business is found at sign-in (address chosen at run time instead of `BuildConfig.SERVER_HOST` in `config/ServerConfig.kt`); look, name inside the app and switches come from the business's own feed and settings (`AppVersionFeed` already answers per business). Mowology becomes an ordinary business on it.
- To settle in the plan: one package id (a one-time reinstall on the phone and the tablet), launcher name and icon (one for everyone), app links, the web-shell config, the test-business banner and the "never talk to production" guard, push config, moving off the debug signing key, and when the 1.8.0 line can be retired (after the native field test on mowology.ca, started 2026-11-09). iPhone: one app already, but `APIEndpoints.swift` hard-codes mowology.ca.
- **Decided (Tim, 2026-11-09):** the design is one app per platform, one database per business, sign-in sends the person to their business. An email that belongs to more than one business sees a "which business?" list after sign-in. Database changes are to run automatically for every business (today: 147 hand-run runner pages, two migration folders that have drifted, a new business copies Mowology's layout). Plan, in order: (1) one updater over every registered business with `migrations_log`, one migrations folder; (2) a state page and a check after each deploy; (3) new businesses brought up to date by the updater; (4) a small central directory (email → business → address, no client data); (5) shared sign-in that returns the business's address with a token checked against that business; (6) Android one app; (7) iPhone reads the address from sign-in (16 files name mowology.ca); (8) retire Android 1.8.0 after the native field test. Plan approval and order still open.
- **State (2026-11-11):** (1) LIVE 2026-11-09 — `SchemaUpdater`, every 5 min for every business. iPhone groundwork for (7) LIVE: `Server.swift`, 1.3.136. (4)+(5) LIVE 2026-11-11 — `https://ittai.ca/api/sign-in.php` (BusinessDirectory + PhoneSignIn, tokens carry `biz`); Mowology joins once `$ITTAI_DEFAULT_APP_URL` is in tenants.php; positive proof with test users owed. (2), (3), (6), (7), (8) not started; (6)+(7) wait for Codex's app refactor to land.

## Clock-in on the Dynamic Island / Lock Screen (Tim, 2026-11-07: "Jobber shows itself clocked in at the very top even though the app is backgrounded. I like that")
- A Live Activity while clocked in: the running timer in the Dynamic Island and on the Lock Screen (job name or "On break"), started on Clock in, ended on Clock out, updated on break start/end.
- Needs a Widget Extension target with ActivityKit (iOS 16.1+; Xcode 15.2 can build it), `NSSupportsLiveActivities` in Info.plist, and push updates (APNs live-activity token) so it stays right when the server changes the clock (auto clock-out, office edits). Android: an ongoing notification with a chronometer.
- Needs its own chat; Xcode Cloud signing for a second target (bundle id, capability) checked first.
## Penny's onboarding asks the book questions, one by one (Tim 2026-11-06)
- **Terms near the start:** right after "About you", Penny shows the terms and privacy policy and the owner accepts (records the version in `terms_versions`/acceptance — the sign-in gate, migration 1381, is off today).
- **GST:** registered? number, and how often they file. Not registered → GST rate 0, so contract billing and invoices add none (Claudia, G9–G11).
- **Year-end:** who does it — an accountant (→ the existing invite step) or "I do it myself" (Penny says plainly the terms ask for a review before filing, and offers the accountant login).
- **Payroll:** how they run it today (e.g. Wave) — Ittai doesn't, so Penny knows not to expect it.
- One question per screen on web, iPhone and Android; iPhone hard-codes the company step (`ItPennyOnboarding.swift`), Android to check — parity is part of the job.

## iPhone receipt capture takes blurred photos (Tim 2026-11-06) — being handled in another session
- The edge-detect scanner on iPhone auto-captured while the phone was moving: a motion-blurred receipt, "No text found".
  Wanted: auto-capture only when the frame is steady and sharp (or turn auto-capture off / add a manual shutter), and a
  "too blurry — retake" check before upload. Screenshot in the 2026-11-06 Claudia chat. iPhone receipts area.

## Timesheets to payroll — QuickBooks and Wave (Tim 2026-11-06) — Tim: the QuickBooks TimeActivity part "is useful" (wanted)
- **QuickBooks:** the Accounting API's `TimeActivity` (hours per employee, per customer/job) fits the connection Ittai already has.
  Hours tied to a pay type (regular/overtime) for QuickBooks Payroll need Intuit's Premium "Payroll Compensation" API, which has no
  sandbox. Unsettled: whether QuickBooks Payroll **Canada** turns TimeActivity into paycheques — ask Intuit / test on Tim's company.
- **Wave:** no payroll or hours in Wave's public API (third-party report, unconfirmed by Wave docs). Realistic path: a weekly hours
  export in whatever file Wave Payroll imports (check Wave's import format first), or Wave's own timesheet entry by hand.
- Start: the onboarding payroll answer (`onboarding_payroll`, `onboarding_payroll_app`) says which one a business needs.

## Penny warns a non-registered business nearing the $30,000 GST limit (Tim 2026-11-06)
- For a business that answered "not registered" (`ops_settings.gst_registered = 'no'`), Penny sums taxable sales (invoices) over
  each rolling four calendar quarters and warns as it nears the small-supplier limit ($30,000 in Canada) — e.g. at 80% and on
  crossing, saying it must register and charge GST from the supply that takes it over (check the exact CRA timing rule in the
  `regions` skill / with Sit Lim before wording it). Shows on Penny's card and the brief; never changes the setting itself.

## Intuit's official "Connect to QuickBooks" button (Tim 2026-11-06)
- Ours is plain text "Connect QuickBooks" (`public/crm/accounting/quickbooks.php:179`, `public/crm/onboarding/step.php:261`, and the
  phone apps). Intuit's technical requirements ask for their supplied button (text-only since 2021, refreshed artwork 2024; English and
  French, png/svg, base + hover), shown only while not connected, replaced by Disconnect once connected. Download the current pack
  from developer.intuit.com (sign in) and swap on web, iPhone and Android before the app assessment / Marketplace listing.
- Brand rules to keep: never "QuickBooks"/"QB"/"Intuit" in Ittai's name, app card or logo; Ittai's name/logo bigger than any Intuit mark
  and at the top of ittai.ca; plain factual text ("connects to QuickBooks Online") is fine; no QuickBooks logo elsewhere without approval.

## One shared QuickBooks return address for every business (2026-11-06)
- Today each business returns from Intuit to its own `https://<business>.ittai.ca/crm/accounting/quickbooks-callback.php`
  (`QboConnectionService::redirectForThisBusiness()`), and every one must be listed in the Intuit app's Redirect URIs. Fine for a
  handful; before many businesses join, use one address (e.g. `https://ittai.ca/quickbooks/callback/`) that reads the signed `state`
  to find the business and hands the code to it. Check Intuit's limit on how many redirect URIs one app may list.
- Intuit listing URLs in use: launch + connect/reconnect `https://ittai.ca/quickbooks/`, disconnect `https://ittai.ca/quickbooks/disconnected/`,
  EULA `https://ittai.ca/terms/`, privacy `https://ittai.ca/privacy/`.

## Save Intuit's `intuit_tid` with every QuickBooks error (Intuit app review, 2026-11-06)
- `QboClient` keeps the error message but not the `intuit_tid` response header Intuit support asks for. Store it with each failed
  call (qbo_links message / connection last_error) and show it in the held-item reason. Also: read the OAuth endpoints from Intuit's
  discovery document instead of the fixed URLs in `QboOAuth` (the review asked about both; answered No).

## Two-step sign-in (MFA) for owners and office staff (Intuit app review, 2026-11-06)
- Ittai sign-in is email + password (web) or PIN (crew phones), no second step. Intuit's assessment asks (answered No). Owners and
  anyone with books/QuickBooks access should get a second step (authenticator app or emailed code) before Ittai holds many
  businesses' QuickBooks data. Third-party notes say store-level security applies past 500 connections — check Intuit's current rule.

## Business keys: enforce lowercase letters, digits, dashes (security review follow-up, 2026-11-06)
- `PdfStore`/`StoredPdfPath` require `[a-z0-9-]+` business keys; `Tenant::valid()` doesn't enforce it. A key with `_` or a capital
  would make every PDF write fail. Add the check to `Tenant::valid()` (or a registry test). All current keys pass.

## Trackimo per business (security review follow-up, 2026-11-06)
- `TrackimoService` signs in with Mowology's own TRACKIMO_* constants for every business. Files are now per business (#8), but a
  second business turning tracking on would read Mowology's trucks. Before then: per-business Trackimo credentials (encrypted,
  like the QuickBooks keys) and refuse tracking where none are set.

## Found when live code came back into git (security audit fix 3, 2026-11-09)
- **LookbackService calls the model directly** (`app/Modules/Accounting/Services/LookbackService.php:977`), skipping LlmGateway's retry, kill switch and cost log. `NoDirectAnthropicCallsTest` carries a dated exception for it; route the call through `LlmGateway::send()` and remove the exception.
- **Old tests not adopted:** `EtransferNudgeServiceTest` and `InvoiceServiceTest` on the old branches are older than the live services and fail against them (nudge copy now reads the address from settings; `ensurePaymentIntent` has a lock). Both services have no current test.
- **Left server-only, for the delete list:** `/customer/invoice-v2.php`, `/crm/api/test-invoice-create.php` (`invoice-v2.php` sends no CSP). `/crm/api/salt-manual-trigger.php` turned out to be the live backend of `/crm/salt/trigger.php` (admin-only, CSRF), so it came into git as the server runs it (B3); it returns exception messages in its JSON to the admin — tidy when the salt page is next touched.
- **Test-tenant outbound gate never deployed** (B2, branch `claude/test-tenant`, now on GitHub): `app/Core/OutboundGuard.php`, `TenantIdentity.php` and the gate calls in 36 senders, crons and Stripe files. Only its `IdempotencyGuard` was live, and that came in through `claude/server-idempotency`. The rest was not adopted (not live; its scan test would fail without the wiring). Merge it on its own when a test tenant is wanted.
- **MiaLlmGateway calls the model directly** (`app/Modules/Marketing/Services/MiaLlmGateway.php`), like LookbackService; dated exception in `NoDirectAnthropicCallsTest`. Route it through `LlmGateway::send()` and remove the exception.
- **B2 branch tests older than the live code, not adopted** (each service now lacks that check): `ReceiptAiHardeningTest` (whole file: tests `ReceiptBookkeeperService::sanitize()`, which the live service does not have — the live prompt-injection hardening took another shape); `ReceiptProfileTest` ×3 (exact prompt wording; `tax_unchecked` for a province without rates — the live `ReceiptProfile` never sets it, worth a look); `MiaRecommendationWriterTest` ×2 (expected the monthly cap to be checked before the model call; live code reports the model error first — worth a look); `MiaWorkspacePageTest` ×2 (CSS token guards against a later page than the one deployed); `VisitCompletionLaborTest` ×1 (asserted the source of an older `VisitCompletionService`).
- **`claude/mia-autonomous-marketing-913456` is now the only copy of Mia's website work history** (52 commits); the 46 live files are in `feature/language`. The branch's docs (`docs/marketing/*`) came with it.


## Otto warns the owner about weather and public holidays, per business (Tim, 2026-11-09)
- Tim was not told by Otto (app or push) that heavy rain was forecast for Tue 2026-11-10 with Wed 2026-11-11 a public holiday, so Tuesday's jobs had to move to Thursday. "Otto should be the one telling me."
- Wanted: Otto looks a few days ahead and tells the owner on his card, in the brief and by push when a scheduled day has weather over the business's threshold or is a public holiday in its region, and proposes the move (skipping holidays and non-working days); the owner confirms.
- Per business: "Not all Ittai tenants may want this service (maybe they work indoors)" — one onboarding question plus Otto's settings; location from the business (today `weather_guard` uses Vancouver and is left out of the per-business crons); holidays from the region rules.
- Needs its own chat and a plan first. Brief saved 2026-11-09 (carousel chat); Part 1 (why nothing was said) traced there.

## Android receipt camera: wait for steady, light on (Tim 2026-11-09)
iPhone 1.3.133 won't shoot a receipt until the phone, the receipt and the focus are steady, and keeps the light on
(`ReceiptCameraView`, `ReceiptSteadyGate`). Android still shoots whenever tapped. Wait until the iPhone's steadiness
numbers are tuned on real receipts, then copy them. **Shared:** the rule and the numbers. **Private:** nothing.

## Add Job: last-charged price on Android and web (Tim 2026-11-09)
iPhone 1.3.135 offers "Last charged $X" under the price box (`FieldJobService::lastPrice`, `?mode=last_price`). The server
side is shared; Android and the web overlay (`public/crm/js/field-job.js`) still need the button. **Shared:** the matching
rule. **Private:** each business's own prices.

## Getting paid without cards: what is left (Tim 2026-11-09)
- A pasted payment link: moved up to "How a business starts and gets paid" at the top of this file (2026-11-10).
- Intuit's official Connect button still needs the artwork pack from Tim's developer sign-in (see above).
- The pay link has only been seen with online payment off (the test company has no QuickBooks Payments). Prove the real page on a live QuickBooks once Production keys arrive.


## iPhone photo links that expire while the screen is open (2026-11-10)
- Penny's card now re-issues its 15-minute photo links (1.3.137). The Receipts tab, a receipt's detail screen and the full-screen pager hold the same kind of link and do not. Answered duplicate cards keep their old links too. No 403 from those yet; check the access log before building anything.

- **Yui decision carousel (Tim, 2026-11-10):** like Penny's expenses carousel: one decision at a time, "Who is this?", statement requests and unanswered client replies as cards. Web and iPhone. Plan to bring to Tim once the statement job is live.

## iOS meniscus bar: Codex audit 2026-11-11 (read-only; refactor is behaviour-preserving, these stay here)
Audit by Codex (`scripts/codex-ios-build.sh audit`), judged by Claude against `Architecture/iOS-Swift-Standards-Checklist.md` in the vault. The refactor takes only what changes no behaviour (tests, tokens, slot identity, force unwrap, main-actor, smoothness tool). **Deferred, each needs Tim's call or a device check:**
- **Bar labels are fixed 11 pt, scaled down to 8.8 pt** (`ItMeniscusBar.swift:184-187`); below the 12 pt token minimum and they do not follow Dynamic Type. Large Content Viewer is there (the system tab bar's own answer), so this is a design decision: scaled caption with an accessibility-size layout, or keep.
- **Add dial cannot scroll** in landscape / Split View / accessibility sizes (`AddDial.swift`, about 328 pt of rows).
- **Keyboard:** the bar vanishes on show/hide booleans, not the keyboard's frame, duration and curve (`MainTabView.swift:204`); already in the "bar leaves with the keyboard's curve" backlog item.
- **Tenant colour contrast** is trusted, not enforced, for the 11 pt selected label and the bead glyph; enforce in token generation, add Increase Contrast treatment.
- **Haptics** only on Schedule reselect; decide the rule on a device.
- **RTL:** slot geometry assumes physical-left for index 0; the app ships `en` only today, so a future item (logical-to-physical slot mapping is in the refactor as a pure, tested function).
- **Frame-rate range** requests 80 to 120 Hz minimum on 60 Hz devices (`ItMeniscusMotion.swift:285`): harmless, verify on both display classes.
- **Human checks still Tim's:** spring feel at 60/120 Hz, whether the one-turn-later screen switch feels immediate under load, the 0.25-slot reselect threshold, VoiceOver order, Large Content Viewer, Full Keyboard Access, Display Zoom, landscape/iPad reach of the Add dial.
- **Refactor round 1 DONE 2026-11-11 (committed locally, not pushed, no TestFlight):** Codex wrote it (`codex-ios-build.sh`), Claude reviewed. Identity vs slot mapping (`slotIndex(forID:in:)`), RTL slot mapping (`physicalSlotIndex`), Kaname force unwrap removed, drawn fallback when a system symbol is missing, geometry tests for 3 to 6 tabs and spring/threshold edges. **Proved against the simulator:** the four demo screens (admin, add, crew, receipts) are pixel-identical to the pre-refactor build outside the status-bar clock; the stall demo's longest freeze is 16 ms in both. **Caught in review (Codex would have shipped them):** it swapped vector `Image(systemName:)` for a rasterised `UIImage` (icon edges moved), and it rewrote `bar-smoothness.py` to read the nominal frame rate, which misreads the simulator's variable-rate recordings ("STALLED" on a smooth video): reverted. **Left for later:** a frame-rate-aware smoothness tool (must be proven on real `simctl` recordings, which drop frames), `@MainActor` on the motion types (Codex could not verify call sites without the project), tokens for the bar's numbers (next step with the design spec).
- **Meniscus design spec + tokens DONE 2026-11-11 (committed locally, not pushed):** `SPEC-v1.2.md` section 14; `tokens.json` → `meniscus` (23 numbers) generated into `ItMeniscusToken`; iPhone geometry, bar and motion read the tokens (same numbers; simulator screens pixel-identical once the Add dial has settled, stall demo longest freeze 16 ms); `MeniscusTokensTest` fails if iPhone re-hardcodes a number or Android's `MeniscusGeometry.kt` drifts (equal today). **Next:** Android audit and refactor (Codex via `codex-build.sh`, `codex/native`), moving Android to generated Kotlin tokens (`ittai-kotlin-tokens.mjs` lives in the Android repo), confirming each behaviour in section 14 or listing the gap. Not tokenised yet: the Add dial's own numbers, the label minimum scale, the Reduce Motion dissolve, the Add roll turns per slot (not verified on Android).
- **Bar lab on the iPhone BUILT 2026-11-11 (Tim: "test enhancements of the meniscus bar on the iPhone"; all five, off by default):** Account → Appearance → "Bar lab (testing)": haptic tick on tab change; tap the selected Search / Account tab = back to top (Search also puts the cursor in the field; Receipts, Schedule, Kaname keep their own reselect); bar follows the keyboard's frame and curve; labels follow Dynamic Type (capped at accessibility 1, bar grows to fit); Add dial scrolls when the screen is too short (a plain column when it fits). Keys `ittai.bar.*` (`ItBarLab.swift`); DEBUG launch argument `-mwBarLab a,b,c`. Codex wrote it; Claude fixed three defects Codex's tests missed (iOS 18-only `.searchFocused`, a pop-to-root that could not work, a dial that floated mid-screen when scrolling was on). **Proved:** all off = pixel-identical to the pre-bar-lab build except a 1-level shadow rounding in the dial column; labels on at default size = identical; dial on = identical when it fits; larger text screenshots look right. **Not provable here:** the haptic and keyboard-curve feel, landscape dial scrolling (the simulator would not rotate). **2026-11-11 Tim tried it: "haptic on the navbar was nice" (verdict on the other four still open; adopting haptics = on by default, spec section 14, Android haptic parity).** **Tim decides after trying it:** which stay, which go; if any is adopted it moves to the spec (section 14) and the Android side.
- **Schedule day headers: holidays, weather, stats on the calendar itself (2026-11-11, from the Otto flow-line chat; Tim chose "on the calendar itself", named no stats):** plan with Tim before coding; data that exists and a suggested shape are in the handoff note `~/Library/Application Support/Claude/side-session-notes/local_5117bf0f-046f-4f9a-a57f-585db1715650/schedule-day-headers-handoff.md`. Not started; belongs in its own chat.
- **Kaname screen: one decision at a time (Tim, 2026-11-11):** Kaname does not need to show everything that needs doing, only the single most important decision, always actionable. The flow line that offers decisions needing unifying holds about 6 items, shown one at a time. Not started; design with Tim in its own chat (the flow-line chats own the flow line; Charlie/Kaname brief ranking is in the `charlie-chief-of-staff` skill).
