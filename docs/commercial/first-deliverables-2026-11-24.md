# First commercial deliverables — 2026-11-24
**Read this first:** nothing here is validated by a customer. Every statement is labelled **Fact** (sourced), **Hypothesis**, or **Unknown**. No revenue, price, conversion or customer figure exists yet, so none appears.

## 1. Initial commercial strategy (hypothesis)
- **Objective now:** learn from 5-10 real conversations with owner-operated landscaping/property-maintenance businesses (1-10 field staff, Lower Mainland BC) before building any acquisition machine. Success this phase = documented answers to: what do they use today, what do they pay, what admin do they dread, would they trial Ittai.
- **Positioning to test (Hypothesis, from the owner's notes 2026-11-10):** Ittai runs the owner's business operations and organises the owner; the aim is making money, not just invoicing. Founder line to test against alternatives: *"I built the AI office I needed to run my own trade business."*
- **Order of work:** verify claims → legal check on outreach → research a small prospect set → founder-led conversations → measure → only then content/ads/expansion. Beacon and Ambassador stay dormant.

## 2. Verified product capability inventory
**Verified: 0.** Evidence found in the repo for 14 candidates (modules exist) — `ada capabilities`: quotes, invoices, jobs/scheduling, crew time clock + GPS, photo proof of work, receipts/expenses, payroll (BC only), marketing helper (social publishing known broken), client consent/unsubscribe, referrals, public enquiry forms, customer portal, onboarding (in progress), AI office heads (propose, owner approves).
Known limits to disclose: no card payments for other businesses (RPAA); native Android parity in progress; payroll BC-only. **Action for you:** verify the ones you are willing to demo/claim with `ada verify-capability`. Until then Scribe/Closer can claim nothing.

## 3. Initial ideal customer profile (Hypothesis, unvalidated)
Owner-operated landscaping / property-maintenance business, 1-10 field staff, Lower Mainland BC, doing quotes and invoices by hand or across several apps, owner doing admin evenings. Disqualify: large franchises, businesses with no recurring/seasonal work, those happy with a current system. **Unknown:** willingness to pay, current software spend, tech confidence — settle in conversations.

## 4. Competitive positioning assessment
**Facts (sourced, observed 2026-11-24; see research-2026-11-24.md and docs/marketing/competitor-checklist.md):** Jobber from $49/mo (monthly list), Housecall Pro from $79, ServiceM8 free-$349 by job volume, Tradify $47-61/user, ServiceTitan unpublished; AI offered mostly as paid add-ons (receptionist, writing helper); common public complaints: billing/price rises, hard cancellation, per-seat cost.
**Hypotheses:** an AI office handling the owner's admin end to end, plain pricing and easy exit, and BC-specific seasonal/recurring work are credible differences. **Unknown:** Ittai's own price.

## 5. Customer acquisition funnel
Discovery → website → understanding → trust → conversation/demo → trial → activation (first quote sent, first job done, first invoice) → paid → retained/referral. **Measurable today:** nothing past the website (no analytics, no trial/billing data). First instrumentation need: a trial/signup count and a "first quote sent" event, privacy-reviewed (Guardian/Empath).

## 6. Commercial pipeline architecture
Built: `scripts/dept/commercial.py` — 20 stages, dedupe, department-wide suppression, owner-confirmed eligibility, owner-approved outreach, no sending in code. See ARCHITECTURE.md; proven by `ada demo-commercial` and 29 tests.

## 7. Proposed demonstration (for conversations; uses only what you verify)
Scenario: *"Quote a typical garden clean-up from a site visit, send it, see when it's opened."* then schedule it and (if verified) show the completed-visit photo proof and invoice. Built from the prospect's own situation (ask first). Honest limits to state: no card payments through Ittai; setup paths still evolving.

## 8. Initial messaging library (Hypotheses — not usable until claims are verified)
- Problem lines: "Evenings spent on quotes and invoices." · "Enquiries that go unanswered while you're on the job."
- Outcome lines (no guarantees): "One place for clients, quotes, the crew's day and the books." · "Less admin, clearer cash position."
- Proof line (needs your OK and data): "Built and used daily to run Mowology, a Vancouver landscaping business."
- **Prohibited without evidence:** savings amounts, revenue lift, "AI does everything", competitor claims, testimonials.

## 9. Channel assessment (evidence-limited)
**Facts:** places owners talk exist (BCLNA, Landscape Ontario peer network, Jobber Community vendor forum). **Unknown:** which channel your ideal owners use, cost per lead. Start with **conversations through your own network and associations**, not paid channels. Mercury: fix sitemap/SEO basics (ittai.ca sitemap.xml returned 404 on 2026-11-24 — one fetch, to be re-checked).

## 10. Prospect research sample
**None produced.** The choice between a synthetic demo and real public businesses was not answered, so the default (synthetic) applied. Say "research a real sample" and Hunter will research public pages only, no contact, for your review.

## 11. 30-day plan
Week 1: verify claims; lawyer answers the 3 CASL questions; sign in CLI; `ada commercial on`. Week 2: Compass refreshes the competitor checklist with BuildOps/Aspire/LeanScaper; Hunter researches 10 real prospects (public pages); you review. Week 3: first 3-5 founder-led conversations (you contact them); Closer prepares each discovery/demo plan. Week 4: review what was learned; decide pricing hypothesis, ICP v2, and whether any channel/content investment is justified.

## 12. Executive briefing
Generated daily at `~/.mowology-dept/briefings/`; sample from the demo: run `ada demo-commercial`. Assumptions/missing: see LIMITATIONS.md.
