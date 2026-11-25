# Plan — Sam's lead score and inbound buckets (harvest piece 1)

Date 2026-11-03 · Branch `claude/small-business-skills-crm-5f34ef`, fast-forwarded to `origin/feature/language` (cd8b93998).
Source rules: Anthropic small-business plugin, `lead-triage/reference/hubspot-scoring.md:93-167` and
`speed-to-lead/reference/qualification.md:40-90`. Harvest map: `docs/plans/2026-11-03-small-business-skills-harvest.md`.

## What Sam does today (verified this session)
- Leads = `quote_requests` rows with status new/reviewing and no quote, ≤ LEAD_MAX_AGE_DAYS old
  (`SalesDeskService::leads()` :406). Columns we can use: `urgency` (inquiring|soon|asap), `service_types`,
  `project_description` (jobFlow writes `tier:high|medium|low` into it), `source`, `created_at`, property address/city.
- `SalesDeskService::rankLeads()` :716 scores **dollars**: avg accepted quote for the service × tier (1.5/1.0/0.7)
  × urgency (asap 1.3, soon 1.1) × freshness. No engagement, no recency, no bucket, no flat guard. `hot` = tier high or asap.
- Signals that exist: inbound replies `sales_messages` (direction, channel, contact_id, sent_at); outbound touches
  `sales_messages` + `sam_followups` + `quotes.follow_up_sent_at`; quote opens/views on `quotes` only (post-quote).
  No click tracking, no portal-visit log, no call log. Email leads pass `EmailLeadService::score()` (strong/weak) before
  becoming a quote_request; repeat not-leads are learned (`learnedNotLead`).
- Sam's brief (`brief()` :478) lists 3 leads as "New lead: name — next", priority 1 if hot else 3. Sam never sends;
  every message is a draft (`DraftSafetyTest`).

## Design (recommendation)
Keep the dollar value (we know the money; the plugin does not) and add the plugin's **heat** as a second, additive
0–100 score plus a **bucket**. Order by heat, then value. One new pure class, no migration.

`app/Modules/Sales/Services/LeadScore.php` (static, PDO-free, like `SamBadgeService`):

| Dimension | Rule (plugin → Ittai mapping) |
|---|---|
| Engagement 0–25 | inbound `sales_messages` for the contact in last 14 d **+15**; quote opened/viewed in last 7 d **+8** (only when a quote exists); site visit **n/a** (we have no log — reasons say "no visit data", not 0); >3 outbound touches in 30 d with no reply **−5**. Floor 0. |
| Fit 0–25 | service we offer **and** have an accepted-quote average **25**; service we offer, no average **15**; address known but service unknown **12**; neither **5**. |
| Urgency 0–25 | `urgency=asap` or keyword in description ("urgent", "asap", "this week", "deadline", "budget approved") **+15**; `urgency=soon` **+10** (not cumulative with +15); age 7–21 d **+10**, <7 d **+5**, >60 d **−5**. Cap 25, floor 0. |
| Recency penalty | last **outbound** touch <24 h **−25**; 1–3 d **−10**; 4–7 d **−5**. (Don't nag someone we wrote to this morning.) |
| Heat | engagement + fit + urgency − recency, clamped 0–100, with a `reasons[]` list naming each rule that fired. |

**Flat guard** (plugin rule, kept verbatim): if max heat − min heat < 10 across the list, do **not** show a ranked score.
Order by the ladder instead and flag `ranked_by = 'signal'`: untouched (no outbound) first → urgency asap > soon →
value desc → newest. The desk then hides the number and says "ordered by what the CRM knows".

**Buckets** (one per lead, computed in the same class):
- `hot` — urgency ≥ 15, **or** repeat customer (contact has an accepted quote or completed job), **or** `source` is a
  referral, **or** value > 1.5 × average accepted quote. Rule: urgency beats fit.
- `qualified` — fit ≥ 15 and not hot. Default.
- `unclear` — fit < 15 (service or address missing). Sam drafts **one** question (what / where / when, whichever is missing).
- `out_of_scope` — service not in the tenant's offered list, or address outside the service area, or sender learned
  not-a-lead. Sam drafts an honest "not a fit" reply. Never auto-sent.
- When torn, take the more attentive bucket (hot over qualified, qualified over unclear).

Surfacing: `brief()` items become "Hot lead: name — call now (replied yesterday, asap, $1,900 avg)" at priority 1;
qualified stay priority 3; unclear carry a drafted one-question reply through the existing draft path; out-of-scope
appear as a short "not a fit, reply drafted" item. Sam's desk page shows heat + reasons, or the ladder notice when flat.

## Unknowns and how each is settled (cheapest first)
1. **Where "services we offer" and the service area live** for a tenant — grep `business_settings` keys and
   `Products`/`Settings` services. If no area list exists, out-of-scope uses only the offered-services test and the
   learned not-lead list; address stays a fit signal only. Settled by grep before coding.
2. **Repeat-customer query** — reuse the accepted-quote query behind `averageByService()`; confirm `jobs` status values
   for "completed" from `/crm/database_appstack.php` schema authority. Grep.
3. **Which page renders Sam's desk items** and whether it can show a reasons line — find the consumer of
   `SalesDeskService::brief()` (`HeadBrain::withItems`, `app/Services/HeadBrain.php:186`). Read one file.
4. **Pre-quote replies not linked to a contact** — `SalesInboxService::classify` matches known contacts only, so a brand-new
   lead who replies before having a contact row scores engagement 0 with reason "no reply data". Accepted; noted in reasons.
5. **Does the 10-point flat guard fit our data?** — cannot be settled by reading. Settled by a read-only dry run: a CLI
   script prints heat/bucket/reasons for the live last-60 quote_requests via `scripts/prod-query.sh` (SELECT only, before
   deploy). If every list is flat, the ladder is what ships and the number stays hidden; the threshold is a constant.

## Steps and verification
1. `LeadScore.php` + `tests/Unit/Sales/LeadScoreTest.php`: one test per rule row above, the clamp, the flat guard, bucket
   precedence (urgency beats fit; torn → attentive), reasons text. Register in `tests/bootstrap.php`. `vendor/bin/phpunit`.
2. `SalesDeskService::leads()` gathers signals in two batched queries (inbound/outbound `sales_messages` per contact in
   30 d; repeat-customer flags) and `rankLeads()` adds `heat`, `bucket`, `reasons`, `ranked_by`; ordering = heat, value.
   Extend `SalesDeskServiceTest`. Fail-soft: any signal query error → signals empty, reasons say so (absent ≠ zero).
3. `brief()` wording and priorities per bucket; unclear/out-of-scope drafts through the existing draft path (DraftSafety
   tests must still pass; no new send path).
4. Desk page: heat + reasons line, ladder notice when flat (brand CSS in `mowology-brand.css` only).
5. Dry run against production data (read-only) and eyeball the order with Tim's knowledge of the leads.
6. Codex review (`scripts/codex-review.sh`), fix real findings. Local render as owner. Deploy with `scripts/deploy-file.sh`
   one file at a time, dependency first (LeadScore → SalesDeskService → page), `opcache-reset.php`, check Sam's desk live.
7. Vault write-back: Decision-Log (heat beside dollars, flat guard reason), Feature-Impact-Chains (Sam lead flow).

Out of scope here: client-rhythm/quiet detection (piece 3), reminder tone (piece 4), any schema change, any auto-send.
Roughly 250 lines of PHP + 150 of tests.
