# mowology.ca review + strata/commercial sign-up funnel (Mia)

Written 2026-11-13. Live site checked the same day (curl + page fetch). Mia's rules from `.claude/skills/mia-marketing` and `docs/crm/mia-email-hub.md`; voice from `.agents/product-marketing-context.md`. Nothing here is deployed. All copy is draft for Tim's approval, in the voice card (no exclamation marks, no "elevate/solution/exceptional", superlatives only with a number).

---

## 1. Verdict

The site is technically sound and positioned correctly (photo-verified grounds care for strata and PMs). It loses on **trust consistency, thin proof, an empty blog, and a quote form built for homeowners**. The funnel gap is bigger than the site gap: today a council member who isn't ready to ask for a quote has nowhere to go, and Mia (who only warms existing clients) never sees them.

### Top 5, in order
1. **Fix the contradictions that a careful council would catch** (§2.1). Cheap, same day.
2. **Replace the self-rated "5.0 from 5 reviews" markup and "5★ average" with the real number** (56 Google reviews, 4.5). Risk and credibility issue.
3. **Rebuild the strata/commercial quote path** (§4): the form defaults to Residential and asks about lawn size.
4. **Give Mia a prospect lane** (§5): a lead magnet plus a consented nurture sequence that hands to Sam the moment a quote is asked for.
5. **Publish the first six articles** (§3.4). `/blog` says "First guides are on the way" while the article generator already exists (`ArticleGeneratorService`).

---

## 2. Content and copy review

### 2.1 Facts that disagree (verified live)
| Where | Says | Should be |
|---|---|---|
| `/about` | "since 2010" (4 times) and "Since 2012" (once) | 2012 everywhere. `SITE_FOUNDED` in `public/includes/bootstrap.php` is 2012; the About page text is hard-coded. |
| Home hero | "5★ average rating" | 4.5 on Google (56 reviews) per the marketing context. "5-star average" is on the do-not-use list. |
| Home JSON-LD | `aggregateRating` 5.0 from `ratingCount` 5 | Either the real Google figure or remove. Google treats a site rating its own business as self-serving, and the figure contradicts what searchers see in the map pack. |
| Home hero | "100% photo-verified service" | Say what is true: "A timestamped photo report after every visit." "100%" invites one counter-example. |
| Home closing CTA | "Ready to Elevate Your Property's Landscape?" | Banned word, and the context file already names this line as off-voice. |
| Home stats | "14+ years", "250+ properties" hard-coded | Drive from `SITE_YEARS_IN_BUSINESS` and a setting, so they stop drifting. |
| Strata page | "Detailed Monthly Reports" | Confirm the monthly report to the PM exists as a product before a council relies on it. `[CHECK: owner]` |
| Commercial FAQ | work at "early morning or off-peak" vs footer hours Mon-Fri 8-16 | Say the office hours are the phone hours, the crew hours are separate. |

### 2.2 Message and positioning
- **Strong:** the photo report is a real, ownable difference. "Photo-verified" in the H1 passes the competitor test.
- **Missing:** the electric equipment. The owner confirmed it on 2026-09-28 and Google already sends "electric lawn mowing service vancouver" searches to the site. Not on any page. Say "available on request", never "every visit".
- **Homepage tries to serve three audiences with equal weight.** Strata/PM is the best-fit segment per positioning; the hero should speak to them and give homeowners a one-line path.
- **No proof next to the claims on the money pages.** The strata and commercial pages carry no testimonial, no count, no rating, no named reference. Linda N. (Strata President) and Michael T. (Property Manager) are already on the homepage; put them on the strata page.
- **Commercial page leans on strata proof** ("trust with strata councils") without a commercial example. Get one named commercial reference or case `[CHECK: owner]`.
- **Objections unanswered:** price (no range or reframe), switching from the incumbent contractor, what happens if a visit is missed, how a strata ends the contract. Pull from `copywriting/references/objection-handling-service-buyers.md`.

### 2.3 Rewritten copy (draft; the Chief check applied)
**Homepage hero**
- H1: **Landscaping for strata and commercial properties, with a photo after every visit.**
- Sub: Weekly grounds care in Vancouver, Burnaby and Richmond. Your crew sends a timestamped photo report when the visit is done, so you can see the work without driving over.
- Primary CTA: **Get a quote for my property** · Secondary: **See what a visit report looks like** (new sample-report page, §4)
- Alternates for H1: "The landscaping contractor whose every visit is documented." / "Photos after every visit. A crew that knows your property."

**Strata page hero**
- H1: **Strata landscaping your council can forward to the next council.**
- Sub: One crew, one contact, and a photo record of every visit. When a resident asks "was it cut?", you have the answer and the time it was cut.
- CTA: **Book a free site walk** · helper line under the button: "Reply within one business day. No obligation. We'll send a written proposal your council can review."

**Commercial page hero**
- H1: **Commercial grounds care that looks the same every week.**
- Sub: Offices, retail, clinics and business parks across Metro Vancouver. Scheduled visits, insured crews, and a photo report so you never have to check.
- CTA: **Get a commercial quote**

**Closing CTA (replaces "Elevate")**
- **Tell us about the property. We'll walk it and send a written proposal.**

**FAQ answers** (40-60 words, written for AI extraction; one example)
- *How do I know the work was done?* After every visit your crew sends a timestamped photo report to you and your property manager. If a resident or council member asks, you have the record. Photos are kept with the property file.

**Meta descriptions to change** (current ones are fine for home, strata, commercial; these are weak):
- `/services`: "Weekly grounds care for strata, commercial and residential properties in Vancouver, Burnaby and Richmond. Mowing, hedges, beds, cleanups, snow. Photo report after every visit."
- `/about`: "Mowology has maintained Metro Vancouver properties since 2012. Meet the crew and see how every visit is documented."
- `/quote` (see §3.2): add one.

---

## 3. SEO review

Checked live 2026-11-13. HTTP 200 on all main pages, TTFB 0.2-0.5 s (the `/contact` page took 2.2 s once; re-test).

### 3.1 What is working
- Unique titles and meta descriptions on home, services, about, contact, portfolio, blog, strata, commercial.
- One H1 per page, self-referencing canonicals, JSON-LD on every public page (LocalBusiness on home; Service + FAQPage on strata and commercial). Note: `WebFetch` reported "no schema" and was wrong; the markup is in the raw HTML.
- No images without alt text on the pages sampled. WebP in use. Sitemap has 20 URLs, robots.txt is clean and references it.
- Internal links from home to strata and commercial pages and from them to related services.

### 3.2 Issues
| # | Issue | Impact | Evidence | Fix |
|---|---|---|---|---|
| 1 | Self-rated `aggregateRating` 5.0 / 5 reviews on LocalBusiness | High (trust, possible rich-result penalty) | Home JSON-LD | Use the real Google figures from `business_settings` or drop `aggregateRating` and keep `review` items. |
| 2 | `/quote` 302s to `/jobFlow/jobFlow-getQuote.php`; that page has no canonical and no JSON-LD, and is not in the sitemap | Medium | curl -L | Make `/quote` the canonical page (serve it, don't redirect), or canonical the jobFlow URL to a landing page. Add the strata and commercial quote landing pages (§4). |
| 3 | Blog is empty ("First guides are on the way"), yet `/blog` is in the sitemap and the footer | High (content depth, topical authority) | `/blog` has zero post links | Publish six articles (§3.4). Until then noindex `/blog` or drop it from the sitemap. |
| 4 | Founding year conflict (2010 vs 2012) | Medium (E-E-A-T, LocalBusiness `foundingDate` says 2012) | `/about` | §2.1. |
| 5 | LocalBusiness schema has no street address | Medium (local pack, NAP match with Google profile) | Home JSON-LD, no `streetAddress` | Add the address that matches the Google Business Profile exactly, or `areaServed` with a service-area business setup. `ListingsService::compare()` checks NAP across listings; run it first. |
| 6 | Strata and commercial pages are the money pages but last modified 2026-02-17 / 2026-02-12 | Medium | sitemap | Refresh with the proof in §2.2, and the sitemap `lastmod` moves with it. |
| 7 | Keyword overlap across `landscape-maintenance-vancouver`, `lawn-maintenance`, `yard-maintenance-vancouver`, `residential-lawn-care-kitsilano`, `kitsilano-landscaping` | Medium | footer links, sitemap | Map one primary keyword each in `docs/marketing/seo-audit-and-plan-2026-09.md`; merge or differentiate the two Kitsilano pages. |
| 8 | No electric-service content despite existing impressions | Medium (free traffic) | Context file §2 | Section on `professional-lawn-mowing-care` and a short FAQ on home. |
| 9 | Images lack `loading="lazy"`; only 1-2 images per page on money pages | Low | grep | Lazy-load below the fold; add one real project photo per service page (consent and privacy rules in `MediaPickerService`). |
| 10 | No Strata-specific supporting pages (BC strata budget, AGM landscaping, contractor change over) | High (opportunity) | site map | §3.4. |

Not checked and worth a pass: Core Web Vitals field data (needs Search Console / PageSpeed), indexed-vs-submitted counts, Google Business Profile categories, backlinks. `WebsiteWatchService` already reads Search Console weekly; pull its last run rather than re-deriving.

### 3.3 Keyword map for the two target segments (draft)
| Page | Primary | Secondary | Intent |
|---|---|---|---|
| `/services/strata-landscaping-maintenance` | strata landscaping vancouver | strata grounds maintenance, strata landscaping contract | Solution-aware |
| `/services/commercial-landscape-maintenance` | commercial landscaping vancouver | commercial grounds maintenance, business park landscaping | Solution-aware |
| New: `/strata-landscaping-quote` | strata landscaping quote vancouver | strata landscaping cost | Ready to buy |
| New: `/commercial-landscaping-quote` | commercial landscaping quote vancouver | | Ready to buy |
| New: `/sample-visit-report` | landscaping photo report | | Proof / bottom of funnel |
| Blog: pricing article | how much does strata landscaping cost in vancouver | | Problem-aware |

### 3.4 Content to publish (first six; generator exists, Tim approves each)
1. How much strata landscaping costs in Metro Vancouver, and what changes the number `[CHECK: use our own quoted ranges, not market guesses]`
2. What to put in a strata landscaping contract (scope, extras, snow, missed-visit remedy)
3. Changing landscaping contractors mid-year: a checklist for councils
4. What a photo-verified visit report shows, with a sample (links to `/sample-visit-report`)
5. Commercial grounds care: a monthly checklist for property managers
6. Electric landscaping equipment for multi-unit properties: what changes and what doesn't

AI-search note (`ai-seo` skill): answer the question in the first 40-60 words of each article, keep FAQ blocks, and keep NAP and rating figures identical across the site, Google profile and listings.

---

## 4. Marketing review (site-side conversion)

- **Quote form (`/jobFlow/jobFlow-getQuote.php`).** Has `property_type` (Residential default, Strata, Commercial), service checkboxes, lawn-size radios, irrigation, urgency, three consents (`consent_quote`, `consent_marketing` optional "Promotions & Tips", `consent_sms`). For strata and commercial leads it **does not ask**: management company, number of buildings or units, who decides (council / PM), current contractor, contract start date, whether a site walk is wanted, or the budget cycle. Lawn size is irrelevant to them. The marketing consent label says "promotions", which Mia's rules forbid mentioning in a campaign; the label should describe what Mia actually sends.
- **Fix:** two short dedicated forms (strata, commercial) reached from each service page's CTA and carrying `property_type` pre-set; shared backend. See funnel step 3.
- **Proof placement.** Put the sample report, one testimonial and the insurance line within one scroll of every quote CTA.
- **Reviews.** 56 Google reviews at 4.5 is the strongest number the business owns and is not shown. Show "4.5 on Google (56 reviews)" with a link, dated, per the context file's rule.
- **Phone.** Good: tel link repeated. Add "Call or text" only if texting is actually answered; `[CHECK: owner]`.
- **Retargeting and Google Ads.** Not reviewed (no access). If paid search runs for "strata landscaping vancouver", send it to the new strata quote page, not the homepage.

---

## 5. Funnel: strata and commercial sign-ups, run by Mia

### 5.0 What exists, what must be built
Mia today **keeps existing and past clients warm**; she never emails a stranger. Her calendar (`mia_calendar`) already holds `pm_snow_preseason` (Jun), `pm_snow_signup` (Aug-Sep) and `pm_next_year` (Nov, "strata budgets / AGM"), but their audiences `property_managers` and `stratas` are **contacts of existing companies**. Sam owns anyone with an open quote or new request (`MiaFinder::samOwns()`, re-checked at send). So:

| Stage | Owner | Exists? |
|---|---|---|
| Attract (search, Google profile, referrals, PM word of mouth) | Mia (content/GBP/social, drafted for Tim) | Partly: GBP, social drafts, articles |
| Capture without a quote request (lead magnet) | Mia | **New** |
| Nurture a consented prospect | Mia | **New** (needs a prospect audience) |
| Quote requested, site walk, proposal, follow-up | Sam | Exists |
| Won: onboarding, first-visit photo, review ask, referral | Mia / Yui / `ReviewRequestService` | Mostly exists |
| Renew and expand (snow, next-year budget) | Mia calendar | Exists |

The funnel below only adds the **Capture and Nurture** rows, plus the page work in §4, and hands off cleanly to Sam.

### 5.1 The consent rule that shapes everything
Mia sends marketing email only to people who gave consent or have implied consent, checked at send time by `ConsentLedgerService::allows()`. A stranger who downloads a checklist **must tick an express-consent box** (unticked by default) and confirm (`public/optin-confirm.php` already handles a confirm link; check it is double opt-in before relying on it). Every email names the sender and address and carries an unsubscribe link, or `MiaWording::compose()` refuses. No cold email lists, no scraped council addresses. Marketing texts are off. I have not re-read the `regions` skill for CASL specifics in this pass; confirm the B2B implied-consent edge cases there before any outbound to property-manager firms that have not asked.

### 5.2 Funnel map

```
ATTRACT                    CAPTURE                    NURTURE (Mia)             CONVERT (Sam)         WIN + GROW
Google (strata/commercial  Strata Budget Checklist    5 emails over 21 days,    Site walk, proposal,  Onboarding photo,
 searches), GBP, articles,  Commercial Property        stops on reply/quote/     day-3 follow-up,      review ask, referral,
 referrals, PM intros       Manager checklist          booking                   verbal approval       Nov renewal campaign
        │                          │                          │                       │                     │
        ▼                          ▼                          ▼                       ▼                     ▼
 Service + quote landing   Opt-in form, express       Mia drafts, Tim approves   Quote request =       pm_snow_signup,
 pages with proof          consent box, confirm link  once; one OK covers all    Sam's lane            pm_next_year
```

**One promise throughout:** *we will show you the work, every visit.* Same line on the ad, page, form, email and first visit.

### 5.3 Step by step

**Step 1: Attract**
- Pages: strata, commercial, the two new quote pages, sample report, six articles (§3).
- Google Business Profile: weekly post (Mia drafts via `MiaChannelsService`), one post per fortnight is about strata or commercial, one photo per post that passes `MediaPickerService` rules.
- Referral: ask existing PM and strata clients for a one-line intro to a neighbouring building. `Referrals` module exists; rewards for reviews are forbidden, rewards for referrals are a separate decision for Tim `[CHECK: owner]`.
- Paid (optional, not reviewed): one search campaign each for strata and commercial terms, to the quote landing pages.

**Step 2: Capture, two lead magnets (both free, no discount)**
1. **Strata Landscaping Contract Checklist** (PDF, one page): 12 things a contract should state (scope, extras approval, snow, missed-visit remedy, insurance certificate, term and exit, reporting). *Button: "Send me the checklist"*.
2. **Property Manager's Monthly Grounds Checklist** (one page): what to look at on a site walk, with a place to attach a photo.
- Form fields: first name, email, "I'm a: council member / property manager / commercial owner". Consent box, unticked: *"Send me occasional emails from Mowology about grounds care for strata and commercial properties. I can unsubscribe at any time."*
- Landing line: **A one-page checklist for councils comparing landscaping quotes.** Sub: "Quotes often describe different jobs. This puts them side by side."

**Step 3: Quote path (for people ready now)**
- Strata form adds: strata plan number (optional), management company, number of buildings, who approves, current contractor (optional), when is the next AGM or budget meeting, preferred site walk day.
- Commercial form adds: property type, number of sites, access hours, start date.
- Both end with: *"We reply within one business day. Next: a site walk within 48 hours."* (the quote form's own promise).
- Sets `property_type` pre-selected; the existing backend creates the quote request, which Sam owns from that second. Mia stops (see 5.4).

**Step 4: Nurture, "Strata Councils" sequence (5 emails, Mia drafts, Tim approves once)**
Rules: signed Tim, first person, plain, one `{{first_name}}` merge field only, no discount, no exclamation marks, sender name + postal address + unsubscribe added by `MiaWording::compose()`. Subjects ≤ 45 characters.

| # | Day | Subject | Job | Body (draft) |
|---|---|---|---|---|
| 1 | 0 | Your checklist, {{first_name}} | Deliver; one question | "Here's the checklist you asked for [link]. One question: is your council comparing contractors now, or planning for next year's budget? Reply with a word. I read every reply. Tim" |
| 2 | 3 | What a photo report shows | Proof, show don't tell | "Most contractors say they were there. Ours send a timestamped photo when the visit is done. Here is a sample [link]. When a resident asks if the lawn was cut, you can answer in ten seconds. Tim" |
| 3 | 8 | Three quotes, three different jobs | Raise and resolve the main objection | "Councils tell me their three quotes don't match. One includes edging and bed weeding, one doesn't. Here are the five lines to compare [link]. If you'd like, I'll read your quotes with you. No charge. Tim" |
| 4 | 14 | Who is insured, and for how much | Risk | "Our crews carry $5M liability and WorkSafeBC coverage. I'll send the certificates to your manager on request. Ask whoever else is quoting for theirs. Tim" |
| 5 | 21 | Walk the property with me? | Ask | "If it helps, I'll walk the property, give you a written proposal your council can read, and attend the meeting if you want. Reply 'walk' and I'll find a day. If now isn't the time, no problem. I'll check in before your next budget. Tim" |

- **"Commercial" sequence:** same shape, subjects and proof adapted (multi-site portfolios, off-hours work, one contact, photo report). Draft after Tim approves the strata one.
- **Exit rules (in code via `MiaSequenceService::run()` style checks):** stop on any reply (reply → Sam lane), any quote request, any booking, any unsubscribe. Reminder and last call are covered by the single approval, per Mia's rules.
- **Long-tail:** if no reply by day 21, drop to the `pm_next_year` / `pm_snow_signup` calendar emails as a prospect, not weekly.

**Step 5: Convert (Sam)**
Site walk within 48 hours → written proposal with three expected objections answered and price shown per unit per month → day-3 follow-up leading with the objection raised on the walk → verbal or written approval. Offer to attend the AGM. This is already Sam's flow; the only change is the strata and commercial intake fields above feeding the proposal.

**Step 6: Win and grow (existing machinery)**
1. First visit: send the first photo report within the hour with a one-line note from Tim. (Photo report is the product; the first one is the proof.)
2. After 3 visits: `ReviewRequestService` review ask (no reward).
3. Month 3: ask the PM for an introduction to one other building they manage (Referrals).
4. Aug-Sep: `pm_snow_signup`. Nov: `pm_next_year` (strata budgets and AGM). Already seeded.

### 5.4 Build list (what has to be made, smallest first)
| # | Item | Where | Notes |
|---|---|---|---|
| 1 | Copy fixes in §2.1 | `public/about.php`, `index.php`, schema builder | No new logic. |
| 2 | Strata and commercial quote landing pages + forms | New public pages; reuse jobFlow backend | Thin controllers; any new SQL goes in a service under `app/Modules/` per CLAUDE.md rule 10. |
| 3 | Sample visit report page | `public/` + sanitised, consented, privacy-ok photos | `MediaPickerService` rules. Use a test property, not a client. |
| 4 | Lead magnet PDFs + opt-in form | New page; confirm link via existing `optin-confirm.php` | Express-consent box unticked; ledger row `ConsentLedgerService`. |
| 5 | Prospect audience for Mia | New `MiaCalendar` audience (e.g. `prospects_strata`) + two sequences | Today's audiences are existing-client only. Needs a migration and a test in `tests/Unit/` (registered in `tests/bootstrap.php`). `MiaCalendar::defaults()` must match the seed. |
| 6 | Hand-off rule | `MiaFinder::samOwns()` already excludes open quotes | Add: a prospect with a quote request leaves the sequence immediately. |
| 7 | Six articles | `ArticleService::save()` via generator | Tim approves each. |
| 8 | Real rating + address in schema | `bootstrap.php`, schema builder | Source of truth is `business_settings`. |
| 9 | Dashboard line for Mia | Card | "Prospects in nurture / replies / handed to Sam" so the funnel is measurable. |

### 5.5 Measurement
`mia_calendar_results` already records sent / clicked / replied / quoted / booked $ per campaign by year. Add per-sequence: opt-in rate on each landing page, reply rate on email 1, quote requests per 100 opt-ins, site-walk rate, proposal-to-win, and source (organic, GBP, referral, paid). Opens are not trusted (Apple proxy), so the success metric is replies and quote requests.

### 5.6 Ordering
1. This week: §2.1 fixes + real rating (no new code paths).
2. Next: strata quote page and form, sample report.
3. Then: lead magnet, prospect audience and the five emails (needs migration; Tim approves the first campaign through Mia's card as usual).
4. Ongoing: articles, one per fortnight.

---

## 6. Needs Tim
- Confirm the monthly report to PMs exists (strata page claims it).
- One named commercial client we can reference.
- Pick the tagline: "A higher degree of service" vs "photo-verified" as the line (open since 2026-09-28).
- OK to add electric-on-request to the lawn page and home FAQ.
- Approve the funnel scope (steps 1-4 of §5.4 first) so a plan and tests can follow.

## 7. Not verified
- Google Business Profile, Search Console data, Core Web Vitals field data, ad accounts: no access in this pass.
- Whether `optin-confirm.php` is double opt-in.
- CASL edge cases for B2B email to property-management firms (the `regions` skill was not read in this pass).
- Review totals (56 / 4.5) are from the context file dated 2026-09-28, not re-checked on Google today.
