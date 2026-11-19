# Harvesting Anthropic's small-business plugin into Ittai — assessment (2026-11-03)

Source: `~/.claude/plugins/marketplaces/knowledge-work-plugins/small-business/` v1.35.1
(44 skills + `shared/`). We harvest the **logic** (thresholds, scoring, gates, cadences,
wording rules) and re-implement it natively in the heads' services. We do NOT use its
MCP connectors (Gusto, QuickBooks, HubSpot…): Ittai already is the system of record.

## What the plugin has that Ittai does not (verdict: worth harvesting, as rules not code)

Ranked by fit to a field-service CRM. "Where" = the head/service that owns it on feature/language.

| # | Harvest | Concrete rule (source) | Where in Ittai |
|---|---|---|---|
| 1 | Lead scoring + flat-score guard + inbound routing | 0–100 = engagement + fit + urgency (each 0–25) − recency penalty; reply ≤14d +15, open ≤7d +8, urgency keyword +15, age 7–21d +10, >60d −5; if top and bottom differ <10 pts show a ladder, not a ranking (`lead-triage/reference/hubspot-scoring.md:93-167`). Inbound buckets hot/qualified/unclear/out-of-scope, urgency beats fit, reply <100 words with real slots (`speed-to-lead/reference/qualification.md:40-86`) | Sam — `SamFollowupService`, `SalesInboxService`, `EmailLeadService` |
| 2 | Quiet-customer detection + win-back | quiet = gap > 2× the client's own median gap, gone = > 3×, needs 3+ jobs; rank by past value; negative review then silence = phone call (`review-reputation/reference/churn-signals.md:14-46`). Win-back days 0/+10/+28, first message does not pitch, stop on any reply (`reactivate/SKILL.md:41-63`) | Sam/Yui — new `ClientRhythmService`; fits recurring maintenance clients |
| 3 | Invoice reminder tone by payment history | last 12 months, ≥3 invoices: good-payer ≥75% on time → gentle; 25–50% late → neutral; >50% late → firm; one email per client, one call to action; skip anyone paid in last 14 days (`invoice-chase/reference/tone-matching.md:7-44`, `reference/v2_sources.md:30-40`) | Penny — sits **under** ReminderGate (grace + AP/deposit/statement gates stay; plugin has no day ladder) |
| 4 | Shared integrity invariants for every head | absent ≠ zero (empty field is not a fact, empty result may be a failed query); a chained total is context, never another step's denominator; never claim a schedule was set unless one was; bank-detail / new-payee / urgent-payment asks go to owner with NO draft, verify by phone (`shared/absent-is-not-zero.md`, `chain-seams.md`, `untrusted-content.md:24-40`) | All heads — a `HeadInvariants` doc + tests; Yui/Penny inbox routers for the payee rule |
| 5 | Pulse thresholds + "merge, don't staple" brief | runway ≥6 mo green / 3–6 yellow / <3 red; A/R 31–60 yellow, 60+ red or 61+ bucket >10% of A/R; pipeline coverage 2× / 1–2× / <1×; stale deal 7d; overall = worst section, forced red if named client+amount+deadline within 7d; name every invoice >30d; brief ends with "the one thing" (`business-pulse/reference/thresholds.md:11-79`, `monday-brief/SKILL.md:47-68`) | Kaname — `CharlieBriefService`, `OwnerFreedomService` |
| 6 | Timesheet anomaly rules | OT >125% of trailing 4-period avg; any shift >12 h; >16 h/day; 7 consecutive days; hours >10% over schedule; never fill a missing punch; flag = who, when, both numbers, cost (`payroll-prep/reference/anomaly_rules.md:19-84`) | Otto/Penny — before `WavePayrollImportService`; auto_clockout + forgot_clockout crons already catch the punch half |
| 7 | Bill/receipt controls | duplicate = same vendor+invoice no., or same total within 5 days, or same total staged in 90 days; auto-code only after 3 agreeing prior bills; recurring bill moved >10% → flag; expense >$25 with no receipt (`ap-processor/reference/*.md`, `month-end-prep/SKILL.md:144-147`) | Penny — `PennyTwinCheck` already does twins; the "3 agreeing bills" rule matches our lessons threshold ("twice = rule") — decide 2 vs 3 |
| 8 | Quote pricing from comparables | price from 2–3 comparable past jobs with arithmetic shown; flag >15% off rate card; defaults deposit 30–50%, 30-day validity; mandatory "not included"; never invent a rate/quantity/lead time; log lost quotes (`proposal-builder/reference/pricing_method.md:23-117`) | Sam — `CloserPricing` / `CloserRateCard` (we have the rate card; the comparables + "show the arithmetic" is the new part) |
| 9 | Voice profile + slop test + cadences | learn voice from 15–30 sent emails (greeting, sign-off, sentence length, never-uses); cold 4 touches days 0/+4/+11/+21 with word ceilings 120/60/80/40; never two sends in 48 h; trades send 6–8am or after 5pm; banned-opener list (`shared/voice-profile.md`, `outreach-composer/reference/sequence_patterns.md`, `slop_test.md`); social mix 40% useful / 30% proof / 20% offer / 10% people, posts/week by team size 2/3–4/5 (`social-content-engine/reference/calendar.md:63-84`) | Yui (`YuiRules`, `YuiDraftService`), Mia (`MiaSequenceService`, `MiaCalendar`) |
| 10 | Materials reorder + cash-forecast bands | 7d and 28d velocity, stockout on the higher, sizing on 28d; target = (60 + lead) days − inbound; buffer = min(30, max(7, lead/2)); forecast band = stddev/mean of payment lag capped 50%, <3 payments → 30% (`inventory-planner/reference/velocity_and_reorder.md`, `cash-flow-snapshot/SKILL.md:87-113`) | Otto (supplier lists), Penny (`CashFlowForecastService`) |

Smaller but cheap: hiring-screener rubric (0–3 × weight, four bands, quote the evidence), contract-review
flags (Net-60+ flag, Net-90 hard, 60-day auto-renew window, missing liability cap), inbox-manager rule
"more than ~5 'needs you' means the threshold is wrong", report-builder sanity checks (partial period,
double count, totals tie, max 3 findings).

## What NOT to harvest
- tax-prep / tax-season-organizer: 100% US (SE tax, 1099-NEC, safe harbor). **No GST/HST/PST/CRA anywhere in the plugin.** Penny's BC rules stay ours.
- Gusto payroll parts, SAM.gov/Grants.gov sourcing, canva-creator, build-connector, smb-router (we have heads).
- Its connector layer entirely.

## Conflicts to decide
- review-reputation says win-back "stop after one"; reactivate runs 3 messages. Pick one (recommend: one no-pitch message, then the owner decides).
- Penny lessons promote a correction to a rule after **2** agreeing cases; plugin auto-codes after **3**. Keep 2 for vendors we already know, 3 for new categories?
- Plugin's `## Business context` block (country, currency, FY end, weekly cadence, output preference, brand colours) is the plugin's tenant settings — check `business_settings` has each field before a second tenant.

## Suggested order (each is its own plan, each a service + test)
1. Shared invariants doc + tests (cheap, protects everything after).
2. Sam lead score + inbound buckets.
3. Client rhythm (quiet/gone) → feeds Sam win-back and Kaname's brief.
4. Penny reminder tone under ReminderGate.
5. Kaname pulse thresholds.
Then 6–10 on evidence.

Branch note: this assessment was written in worktree `claude/small-business-skills-crm-5f34ef`, which is based on `main` and 1611 commits behind `origin/feature/language`. Any build must start from a worktree synced to `feature/language` (memory: feedback_stale_worktree_base).
