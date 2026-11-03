# Ittai backlog — features to carry from Mowology to every tenant

The running list of what Ittai tenants need. Add to it as Mowology proves things. Each item names
what is **shared** across all tenants (knowledge, formats, rules about the world) and what stays
**private** to a tenant (their people, prices, accounts and decisions). That split is the standing
rule.

Direction (council, 2026-10-12): keep the data discipline-neutral, and build and sell to field-service
trades first. The AI back office is the edge.

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

## Head harvest queue (Tim, 2026-11-03): complete each head entirely, in this order
1. **Sam** — in progress 2026-11-03 (client rhythm, comparables pricing, Penny→Sam payer terms, Sam→Penny pipeline line,
   signals digest, renewal radar, tiering, lead response times, quote hygiene, objection replies).
2. **Mia** — DONE 2026-11-03 (`docs/plans/2026-11-03-mia-harvest.md`); left: ads wiring when an account exists, content-strategy brief after margin-by-service, page-level SEO checks. Was: small-business pieces above + survey the sibling `marketing` plugin; diff against MiaCalendar / MiaSequenceService /
   MiaSocialService / MiaWording / ReviewReplyDrafter / MiaCampaignService.
3. **Ada** — `operations` plugin (runbook, risk-assessment, compliance-tracking, status-report, process-doc) and finance
   `audit-support`/`sox-testing` evidence standards; diff against AdaSecurityRules / AdaSecurityWatch / BooksWatchService.
4. **Otto** — `operations` plugin (capacity-plan, vendor-review, change-request) + small-business inventory/restock
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
