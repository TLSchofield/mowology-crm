# Competitor checklist — Ittai vs the field-service tools (review quarterly)

**Why (Tim, 2026-11-08):** "BuildOps has some strong features. We should have a comparison check list that contains
competitor information that can be reviewed regularly." Competitors named by Tim: BuildOps, ServiceTitan (with
Aspire, its landscaping product), Jobber, Housecall Pro, LeanScaper, and Hootsuite for Mia's social posting.

**How to read it:** ✓ has it · ◐ partial, add-on or top tier only · ✗ doesn't · ? not confirmed.
Competitor cells come from vendor pages, press and review sites, read 2026-11-08; many were marketing copy or third-party
blogs, not hands-on. The Ittai column is from Mowology's own records, and cells marked † have not been re-checked against
the code. **Before quoting any cell in a pitch, check it again** (only claim what live data proves).

**Review:** quarterly, on the 3rd of Feb, May, Aug and Nov. The scheduled task for it was blocked by the permission
check on 2026-11-08 and is not set up yet; until it is, ask a chat for "the competitor checklist review". Each review
updates the cells, adds a line to the log at the bottom, and lists new features worth copying.

## 1. Field-service software

| | **Ittai** | **BuildOps** | **ServiceTitan / Aspire** | **Jobber** | **Housecall Pro** |
|---|---|---|---|---|---|
| Who it's for | 1–15 person outdoor trades, phone-only owner, Canada first | Commercial HVAC/electrical/plumbing, 10–200 techs; not landscaping | Mid-large residential trades; Aspire for landscapers ~$3M+ | Solo to ~15 users, home services incl. lawn | US home services 1–20 staff, moving to HVAC/plumbing |
| Price | Not yet (SaaS Feb 2027) | Quote only, est. $150–300/user/mo, annual, 4–8 wk setup | Quote only, est. $250–500+/tech/mo + setup | Published USD: $29–$399+/mo by tier and users | Published USD: $59 / $149 / $299+ /mo, user caps |
| Scheduling and dispatch | ✓ | ✓ | ✓ (Dispatch Pro add-on on core) | ✓ | ✓ |
| Route planning | ✓ route planner | ◐ | ? Aspire / ◐ AI dispatch on core | ◐ new 2025, no clustering | ◐ new May 2026, map pins |
| Quotes, options, e-sign | ✓ quotes, client unticks lines, e-sign, verbal approval | ✓ Good/Better/Best, approval flow | ✓ Aspire proposals + mobile signature | ◐ optional lines (Grow), multi-option in beta | ✓ options + e-sign; proposals add-on |
| Invoices and payments | ✓ card (Stripe), e-Transfer matched automatically, Yardi/SparcPay remittances; autopay in dry run † | ✓ ACH/financing ? | ◐ | ◐ card, US-only ACH, no e-Transfer seen | ✓ card, ACH, Wisetack + Klarna financing |
| Recurring contracts | ✓ job plans, contract billing, snow/salt contracts | ✓ service agreements | ✓ | ◐ recurring jobs + auto-billing | ✓ maintenance plans |
| Client portal / online booking | ◐ token portal for quotes and invoices, web quote form; no self-booking † | ✓ portal, booking ? | ? Aspire / ✓ core | ✓ booking, client hub, website builder | ✓ portal + online booking |
| Crew app: offline | ◐ offline schedule; Add-menu offline queue planned | ✓ | ? | ◐ disputed (Jan 2026 forms/timers) | ◐ weak, needs signal |
| Crew app: GPS, time clock, photos, checklists | ✓ native iPhone + Android, GPS, time clock, photo pins, visit checklist | ✓ | ◐ Aspire GPS + PIN clock-in | ◐ GPS only via partners | ✓ GPS on Essentials+ |
| Job costing | ? † | ✓ praised | ✓ Aspire's strongest feature | ◐ Grow+ | ◐ MAX only |
| Accounting | ✓ own ledger (Penny) + QuickBooks Online one-way send † | ◐ QBO two-way, gaps | ◐ QuickBooks; Canada tax on Desktop only | ◐ QBO two-way, Xero | ◐ QBO sync, Essentials+ |
| AI receptionist (calls) | ✗ | ? | ✓ AI Virtual Agent | ✓ $29/mo | ✓ CSR AI (paid) |
| AI agents that do back-office work | ✓ heads: Penny (books), Sam (sales), Mia (marketing), Otto (ops), Yui (mail), Kaname (brief) | ✓ new Nov 2026: Revenue, Coordinator, Billing agents; Collections, AP previewed | ✓ Titan Intelligence, Atlas | ◐ Copilot, Automations | ◐ AI Team (Analyst, Coach, Marketing, Accountant Q&A) |
| AI bookkeeping (receipts, bank matching) | ✓ Penny | ✗ (AP preview) | ✗ | ✗ | ✗ (Q&A only) |
| Marketing: reviews, campaigns | ◐ Mia: campaigns, review asks, Google posts; Google API and Facebook/Instagram posting broken † | ? | ◐ Marketing Pro add-on | ✓ Marketing Suite $99 | ✓ a strength |
| Purchasing, suppliers, inventory | ✓ supplier lists, Otto's best-price finder, receipt line items; no purchase orders † | ✓ POs, van inventory | ◐ | ◐ supplier catalogs beta, US Home Depot | ◐ price book only |
| Owner reporting | ✓ Owner Freedom dashboard, morning brief | ✓ KPI dashboards | ✓ | ◐ exports capped | ◐ advanced on MAX |
| Landscaping-specific | ✓ plant ID + care, area/lot measurement, snow/salt, mulch pricing, pruning planner | ✗ | ◐ Aspire chemical + snow (third-party) | ✗ | ✗ |
| Canada: CAD, GST/PST, e-Transfer | ✓ | ? | ◐ | ? (Canadian company, unconfirmed) | ? |
| Canada: French | ? † | ? | ? | ? | ? |

## 2. Their strongest features — worth a look for Ittai

| From | Feature | Does Ittai have it? | Worth doing? |
|---|---|---|---|
| BuildOps | **Revenue Agent:** finds repairs written up but never quoted, and quotes never sent, then drafts the quote or follow-up for approval | ◐ Sam follows up sent quotes; crew photo → recommended service → quote is planned | Yes. Closest match to Ittai's heads; extend Sam to "noted on a visit, never quoted" |
| BuildOps | **Billing Agent:** applies contract billing rules, builds invoices, flags negative margins | ◐ contract billing runs; no margin flag | Margin flag needs job costing first |
| BuildOps | **Service Coordinator Agent:** checks open jobs daily for 20+ stall reasons | ◐ Kaname's brief and Otto's suggestions | Yes. A named "stalled jobs" list in the brief |
| BuildOps, Aspire | **Job costing:** live labour and materials against the bid | ? | Yes, high value; time clock + receipts + quotes already hold the data |
| BuildOps | Good/Better/Best quote options | ◐ line untick | Maybe; options suit installs |
| ServiceTitan, Jobber, Housecall Pro | **AI receptionist** answering calls and booking | ✗ (text bridge reads texts) | Big gap for phone-only owners; Yui is the natural owner |
| Housecall Pro | Financing (Wisetack, Klarna) on quotes and invoices | ✗ | Later; mostly US partners |
| Housecall Pro, Jobber | Marketing suite: review requests, campaigns, referral programmes | ◐ | Fix Mia's broken Google and Meta posting first |
| Jobber | Online booking + free website builder | ◐ | The embed plan covers websites; self-booking is a gap |
| Jobber | Supplier catalogs with live price and stock while quoting | ✓ supplier lists + Otto | Ittai is ahead; keep it a selling point |
| LeanScaper | **Crew huddle capture:** record the morning huddle, summarise it, route action items to boards | ✗ | Interesting for Otto; cheap to try with the phone's recorder |
| LeanScaper | Usage-priced, unlimited users, free tier | — | Pricing idea for the Feb 2027 SaaS launch |

## 3. Where Ittai already leads (from this pass)
- **AI bookkeeping:** nobody else codes receipts and matches bank lines and e-Transfers. Penny is unique here.
- **Canada:** e-Transfer matching, GST/PST and BC rules built in. The US tools are USD-first, and even Jobber shows no
  e-Transfer support.
- **Landscaping depth:** plant ID and care, measurement, snow/salt and supplier prices. Only Aspire comes close, and
  it's priced for $3M+ firms.
- **Price for a 1–5 person crew:** BuildOps and ServiceTitan are out of reach. Jobber and Housecall Pro climb steeply
  through tiers and add-ons.

## 4. LeanScaper — not a CRM
An AI coaching layer for landscapers. The founder is also LMN's founder. Its site says it is "not a software company"
and doesn't replace your scheduler or QuickBooks. It offers Lana (an AI operations manager), a crew-huddle recorder,
SOP builder, scorecards and a budget-vs-actual workflow, plus QuickBooks Online read. Priced $0 / $300 / $750 /
$1,500 a month by AI usage, with unlimited users. Reviews are very thin (one 1-star review on G2, 8 App Store
ratings). It competes with Ittai's heads for the owner's attention, not with the CRM.

## 5. Hootsuite vs Mia's social posting

| | **Mia (Ittai)** | **Hootsuite** |
|---|---|---|
| Price | Included | From $99/user/mo, approvals on the ~$399 tier |
| Google Business Profile, Facebook, Instagram | ◐ built, Google API disabled and Facebook/Instagram tokens broken since June † | ✓ plus TikTok, LinkedIn, YouTube, Pinterest, Threads, Bluesky |
| Drafts posts from job photos | ✓ | ✗ |
| Owner approves before posting | ✓ | ◐ top tiers only |
| AI captions and images | ✓ captions † | ✓ OwlyGPT captions, images |
| Unified inbox incl. Google review replies | ◐ review replies † | ✓ |
| Analytics, competitor benchmarks, listening | ✗ | ✓ |
| Scheduling calendar, bulk scheduling | ◐ † | ✓ |

**Read:** Hootsuite is wider (networks, inbox, analytics). Mia is the only one that builds posts from the crew's
photos and asks first. Mia's posting being broken is the real gap, not the feature list.

## Review log
- **2026-11-08:** first pass (six research helpers, vendor pages + review sites). BuildOps launched approval-gated AI
  agents on 5 Nov 2026, the closest thing yet to Ittai's heads. ServiceTitan opened its Max AI package to all
  residential contractors (Oct 2026). Housecall Pro launched trade packages (Jul 2026). Ittai column not re-checked
  against code (†).
