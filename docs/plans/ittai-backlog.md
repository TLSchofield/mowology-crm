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
