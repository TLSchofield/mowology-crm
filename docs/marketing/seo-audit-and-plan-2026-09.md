# mowology.ca — SEO + AI Search Audit and Plan

**Date:** 2026-09-22
**Scope:** Public site only (`/public/` root, CMS-rendered pages, `/services/*` landing pages). CRM, jobFlow and customer portal are out of scope and correctly blocked in robots.txt.
**Status (2026-09-22):** Phase 0 shipped and verified on production (commit `9e7eb8fb` + sitemap cache fix). Not done from Phase 0: font consolidation (the live `master.css` is a flattened bundle that must not be redeployed from this branch), the hero CLS check, and the Search Console steps, which need the owner's login. The `/services` hub is a CMS (database) page, so its cards still need linking from the CMS editor; the footer and homepage now carry those links sitewide. A fifth landing page exists in the CMS (`/services/residential-lawn-care-kitsilano`) and is now in the sitemap, but the footer/related-services lists are file-driven and do not include it yet.

**Phase 2 build shipped (2026-09-23, commit `810e0cfd`):** the Content Engine now publishes to `/blog/<slug>` (articles are CMS pages under `blog/` with the `article` layout, no schema change), `/blog` index + `/blog/feed` RSS are live, articles emit BlogPosting + FAQPage schema, and every LocalBusiness node carries `@id`, `sameAs` and opening hours. Footer/related-services lists are now built from one helper that includes CMS-authored landing pages. Still owner-gated: the founding-year text inside the CMS About page, the `/services` hub cards, GSC submission, GBP Place ID, and real price ranges for the pricing guide.

**Goal:** Rank for strata / commercial / residential landscaping searches in Vancouver, Burnaby and Richmond on Google (organic + map pack) and get cited by AI answer engines (Google AI Overviews, ChatGPT, Perplexity, Claude, Gemini).

---

## 1. Executive summary

The site's on-page basics are better than most local competitors: every page has a unique title, description, canonical, FAQ + LocalBusiness + Service schema on the service pages, WebP hero with srcset, Lighthouse SEO 100 and Accessibility 100. That is not where the problem is.

The site is losing for three structural reasons:

1. **Google is indexing four copies of the site.** `http://`, `http://www.`, `https://www.` and `https://` all return 200 with no redirect (the HTTPS redirect in `.htaccess` is commented out). On top of that, `/about.php`, `/about` and `/about/` all serve 200, each with a *different* self-referencing canonical. Google's index already shows `http://www.mowology.ca/about.php` and `https://mowology.ca/home` as separate pages. Every link and every ranking signal is being split 4–8 ways.
2. **The four money pages are orphans.** `/services/strata-landscaping-maintenance`, `/services/commercial-landscape-maintenance`, `/services/hedge-trimming` and `/services/professional-lawn-mowing-care` receive **zero internal links**. The `/services` hub does not link to them, the homepage does not, the nav does not, and they do not link to each other. They are reachable only through the sitemap. Google treats unlinked pages as unimportant.
3. **There is almost nothing to rank.** Eight indexable pages, 300–800 words each, no blog, no city pages, no pricing content, no seasonal content. Competitors that outrank Mowology (Meridian, Terraform, Eco Property, West Coast Lawns, Terra Firma) each have 20–80 pages built as service × city grids plus guides. The CRM already contains an AI article generator, a content cascade, and a Search Console sync with a recommendations engine, but the pipeline stops at "draft": the CMS has no article page type and no `/blog/` route, so nothing has ever been published.

A fourth issue undermines both Google's and the AI engines' trust in the entity: **the business facts disagree with each other**. The site says "Est. 2019", "8+ years experience" and "since 2010"; BBB says 2012; listings call it "Mowology Lawns & Landscapes Ltd"; the street address on Yelp, YellowPages, BBB and HERE (2845 W 15th Ave) appears nowhere on the site; hours are 8–4 on the site and 8–6 on listings; the schema email is `office@` while llms.txt says `hello@`; and the footer's Google-review link is a placeholder that lands on google.com.

**Fixing items 1, 2 and 4 is one or two days of work and is entirely in code. Item 3 is the 6-month program.**

### Top 5 actions, in order

| # | Action | Effort | Impact |
|---|--------|--------|--------|
| 1 | Consolidate to one canonical URL per page: 301 host + scheme, 301 `.php` and `/home` to clean URLs, fix `/services` redirect leak, compute canonical from page identity not `REQUEST_URI`, switch nav to clean URLs | 0.5 day | Critical |
| 2 | Link the service landing pages from the `/services` hub, the homepage "Who We Serve" cards, the footer, and each other | 0.5 day | Critical |
| 3 | Replace the stale static `sitemap.xml` with the dynamic generator, add the static pages, remove the two dead entries, resubmit in Search Console | 0.25 day | High |
| 4 | One source of truth for business facts (name, address or service-area, founding year, hours, email, GBP URL) in `business_settings`, rendered into schema, footer, llms.txt and every listing | 1 day + listing edits | High |
| 5 | Add an `article` page type and `/blog/` route to the CMS so the existing content engine publishes; then ship 2 articles/month plus the missing service and city pages (§6) | 3–5 days build, then ongoing | Highest long-term |

---

## 2. What was checked, and what could not be

**Checked directly (live site, 2026-09-22):** HTTP status, redirects and headers for 11 URL variants; title, description, canonical, robots meta, H1/H2, word counts, image alts, internal links and JSON-LD on all 10 public URLs; live `robots.txt`, `sitemap.xml`, `llms.txt`; 404 handling; static asset caching; PageSpeed Insights (mobile, Lighthouse 13.5); repo code for `head.php`, `.htaccess`, `sitemap.php`, `cms-render.php`, the Marketing and GSC modules; Google results for the five core commercial queries and for the brand.

**Not checked (needs the owner):**

- **Search Console data.** The CRM has a working GSC sync (`/crm/gsc/`) and a recommendations engine (`/crm/marketing/recommendations.php`), but the session's browser was not logged in and I will not enter credentials. Pull 16 months of query/page data before Phase 2 so content targets are based on real impressions, not guesses.
- **Google Business Profile.** Whether it exists, is verified, which categories, how many reviews, whether it is set as a service-area business. The broken review link in the footer suggests it has never been wired to the site.
- **Backlink profile.** No Ahrefs/Semrush access. The plan assumes it is thin, which is normal for a local contractor.
- **AI citation baseline.** Run the 20 queries in §7 through ChatGPT, Perplexity and Google (AI Overview) once by hand and log the result. Repeat monthly.
- **Prod vs repo drift on `/public/`.** The live homepage shows an "Est. 2019" hero badge; the repo's `index.php` shows "Vancouver's Trusted Landscapers". `public/` has never been audited for drift. `cmp` every public file against production before deploying it.

---

## 3. Technical findings

### 3.1 Duplicate hosts and URL variants — **Critical**

Evidence (all return `200 OK`, no `Location` header):

```
http://mowology.ca/            200
http://www.mowology.ca/        200
https://www.mowology.ca/       200
https://mowology.ca/           200
https://mowology.ca/index.php  200
https://mowology.ca/about.php  200  canonical → /about.php
https://mowology.ca/about      200  canonical → /about
https://mowology.ca/about/     200  canonical → /about
https://mowology.ca/home       200  canonical → /home   (duplicate of /)
```

Google search results for the brand already return `http://www.mowology.ca/about.php`, `http://www.mowology.ca/contact.php`, `http://www.mowology.ca/` and `https://mowology.ca/home` as distinct results.

Root causes:

- `public/.htaccess` lines 64–65: the HTTPS redirect is commented out. There is no www → apex rule at all.
- `public/includes/head.php` line 27: canonical is built from `$_SERVER['REQUEST_URI']`, so every URL variant canonicalises to itself. A canonical must come from the page's identity (its slug), never from the request.
- `header.php` / `footer.php` link to `/about.php`, `/contact.php`, `/portfolio.php` and `/jobFlow/jobFlow-getQuote.php`, while the sitemap lists `/about`, `/contact`, `/portfolio`, `/quote`. Internal links vote for one URL set, the sitemap for another.
- The CMS `home` page is reachable at `/home` and at `/`, and the two renders produce different titles ("Mowology — Professional Landscaping in Greater Vancouver" vs the `index.php` title).

Fix (all in `.htaccess` + `head.php` + nav):

1. Uncomment and extend the redirect block: `http → https`, `www → apex`, in one 301 hop.
2. 301 `/index.php` → `/`, `/home` → `/`, `/(about|contact|portfolio|privacy)\.php` → `/$1`, and strip trailing slashes on non-directory paths.
3. In `head.php`, compute `$canonicalUrl` from a `$canonicalPath` each page declares (or from the CMS slug), defaulting to the clean form. Never from `REQUEST_URI`.
4. Change nav/footer/llms.txt links to the clean URLs.
5. Add `Strict-Transport-Security` once the redirect is live.
6. After deploy: Search Console → URL Inspection on the old variants, and submit the new sitemap.

### 3.2 `/services` redirect leaks a query string — **High**

```
GET /services  →  301  https://mowology.ca/services/?page=services
```

`services/` is a real directory, so Apache's `DirectorySlash` fires *after* the rewrite to `cms-render.php?page=services` and re-exposes the internal parameter. The page then canonicalises to `/services/` while the sitemap and all nav links use `/services`. Three nav links per page hit a 301 on every click.

Fix: in `.htaccess`, `DirectorySlash Off` scoped to that rule, or rewrite `^services/?$` with `[L]` and no `QSA` after an explicit `RewriteCond %{REQUEST_URI} !/$`. Pick `/services` (no slash) as canonical everywhere and add an explicit 301 from `/services/` to it.

### 3.3 Service landing pages are orphaned — **Critical**

Link extraction from the live HTML:

| Page | Links to `/services/*` |
|------|------------------------|
| `/` | none |
| `/services` (hub) | none |
| each `/services/*` page | none (only `/`, `/about.php`, `/contact.php`, `/portfolio.php`, `/services`) |
| header / footer | none |

Fix:

- `/services` hub: a card per landing page with a descriptive anchor ("Strata landscaping maintenance in Vancouver, Burnaby & Richmond"), not "Learn more".
- Homepage "Who We Serve": link Strata → strata page, Commercial → commercial page, Residential → lawn-mowing page.
- Footer: a "Services" column listing all landing pages.
- Each landing page: a "Related services" block linking the siblings, and a contextual link inside the copy (hedge trimming → strata page, etc.).
- `service-template.php` is the right place; do it once and every current and future landing page inherits it.

### 3.4 Sitemap is static, stale and wrong — **High**

The dynamic generator `public/sitemap.php` exists, but the static file `public/sitemap.xml` sits next to it, so Apache serves the static file and the generator never runs. The static file:

- is dated 2026-02-04 to 2026-02-07 on every entry;
- omits `/services/professional-lawn-mowing-care` and `/privacy`;
- lists `/get-free-quote` (returns 404) and `/quote` (302s into `/jobFlow/`, which robots.txt disallows);
- lists `/services` while the page canonicalises to `/services/`.

`sitemap.php` itself only emits CMS rows plus the portfolio, so it would also omit the four `/services/*.php` landing pages and `about`/`contact` if those are not CMS pages.

Fix: delete `sitemap.xml`, add `RewriteRule ^sitemap\.xml$ /sitemap.php [L]`, extend `sitemap.php` with a static list of non-CMS public pages (the four landing pages, portfolio, privacy), drop `/quote`, and use real file mtimes for `lastmod`. Resubmit in Search Console.

### 3.5 Homepage H1 is unreadable to parsers — **High**

The hero animates one word per `<span class="mw-hero__word">` with no whitespace between spans. Extracted text content is:

```
ProfessionalLandscapingServicesinMetroVancouver
```

Visually fine (inline-block + margin), but crawlers and LLMs see a single 47-character token, so the page's most important keyword phrase does not exist as text. Fix: emit a space after each span (or `&nbsp;` inside), or animate via `aria-hidden` spans with a visually-hidden plain-text copy of the H1.

### 3.6 Titles and descriptions over length — **Medium**

| Page | Title chars | Desc chars |
|------|-------------|------------|
| `/` | 93 | 155 |
| `/services/professional-lawn-mowing-care` | 83 | 181 |
| `/services/commercial-landscape-maintenance` | 54 | 190 |
| `/services/strata-landscaping-maintenance` | 58 | 172 |
| `/services/hedge-trimming` | 65 | 164 |

Google truncates titles around 60 characters and descriptions around 155–160. Suggested titles are in §6.

### 3.7 Business-entity data is inconsistent — **High (for both Google and AI)**

| Fact | Site | Third parties |
|------|------|---------------|
| Legal/brand name | "Mowology" | "Mowology Lawns & Landscapes Ltd" (Yelp, YP, HERE), "Mowology Lawn and Landscapes Ltd." (BBB) |
| Founded | **Resolved 2026-09-23: 2012** (owner confirmed; matches BBB). `SITE_FOUNDED` in `bootstrap.php` now drives the hero badge, years-in-business stats, `foundingDate` schema, About copy and llms.txt. Previously "Est. 2019", "8+ years" and "since 2010" at once. | 2012 (BBB) |
| Address | none on site; schema has no `address` or `geo` | 2845 W 15th Ave, Vancouver V6K 3A1 on Yelp, YP, BBB, HERE |
| Hours | Mon–Fri 8:00–16:00 (llms.txt); no `openingHours` in schema | Mon–Fri 8:00–18:00 (listings) |
| Email | `office@mowology.ca` (schema) | `hello@mowology.ca` (llms.txt) |
| Google review link | none on the live site (the repo's footer carried a placeholder `g.page/r/mowology/review` that was never deployed) | — |
| `sameAs` in schema | none | Yelp, HomeStars, BBB, YellowPages, Instagram, Facebook all exist |

Google's local algorithm and every LLM build their picture of "Mowology" from the *agreement* between these sources. Right now there is no single fact they can trust.

Fix: decide the truth once (founding year is the important one), store it in `business_settings`, render from there into: `LocalBusiness` schema (`address` or, if the W 15th address is a home and the business is a service-area business, omit the street and keep `areaServed` and match GBP's SAB setting), `openingHoursSpecification`, `sameAs[]`, `foundingDate`, footer NAP, `llms.txt`, About page. Then update Yelp, HomeStars, BBB, YellowPages, HERE, Bing Places, Apple Business Connect and GBP to match. Add a review link using the real Place-ID review URL (`https://search.google.com/local/writereview?placeid=…`).

Related: the reviews embedded in the homepage `LocalBusiness` schema are "self-serving" under Google's review-snippet policy and will not produce stars in results. Harmless, but do not expect rich results from them. Stars come from GBP.

### 3.8 Performance — **Medium**

PageSpeed Insights, mobile, 2026-09-22: Performance **83**, Accessibility 100, Best Practices 100, SEO 100. FCP 2.9 s, **LCP 3.3 s** (target < 2.5 s), CLS **0.096** (borderline, target < 0.1), TBT 0 ms. No field (CrUX) data, so Google is not yet using real-user CWV for this site.

Causes found:

- **No `Cache-Control` on static assets at all** (`master.css`, hero WebP, `script.js` return only `Last-Modified`). Lighthouse: "Use efficient cache lifetimes, 169 KiB". `.htaccess` forces `no-store` on PHP responses (a deliberate fix for LiteSpeed) but nothing sets long cache lifetimes for `/assets/`.
- **Four Google Font families** loaded render-blocking (DM Sans, Montserrat, Open Sans, Playfair Display). Pick two, self-host them, `font-display: swap`, preload the LCP font.
- Image delivery, 117 KiB (hero 1080w WebP is 150 KB; re-encode at quality 70–75 and add a 768w rung).
- Layout shift from the animated hero words (they start at `opacity:0; transform: translateY(32px)` and reserve no height) — likely the CLS source.
- No HSTS header.

None of this is why the site is not ranking, but LCP and CLS are ranking-adjacent and the fixes are cheap.

### 3.9 Robots, crawlability, security — **OK, minor notes**

- `robots.txt` allows all user agents, including GPTBot, ClaudeBot, PerplexityBot, Google-Extended. Keep it that way. Consider blocking `CCBot` only (training-only crawler) if desired.
- `Crawl-delay: 1` is ignored by Google; harmless.
- The quote CTA points into `/jobFlow/`, which is disallowed. Correct for a form, but it means there is no indexable "get a landscaping quote Vancouver" page. A thin public `/quote` landing wrapper that embeds the form is worth considering.
- 404 handling is correct (real 404 status).

---

## 4. Content and authority findings

### 4.1 Page inventory

| URL | Words | Target it can plausibly own today |
|-----|-------|-----------------------------------|
| `/` | 790 | brand, "landscaping Vancouver Burnaby Richmond" |
| `/services` | 470 | none specific (hub) |
| `/services/strata-landscaping-maintenance` | 540 | strata landscaping Vancouver |
| `/services/commercial-landscape-maintenance` | 527 | commercial landscape maintenance Vancouver (already appears on page 1 in one of the test searches) |
| `/services/hedge-trimming` | 610 | hedge trimming Vancouver |
| `/services/professional-lawn-mowing-care` | 815 | lawn mowing Vancouver |
| `/about` | 793 | brand |
| `/portfolio` | 719 | before/after image search |
| `/contact` | 291 | brand |

Nine pages. Competitors ranking for the strata query have between ~20 (West Coast Lawns) and ~80 (Meridian: a page per service per city) indexable pages.

### 4.2 Where Mowology shows up today

Across five commercial queries tested (strata landscaping Vancouver, commercial landscape maintenance Vancouver Burnaby, hedge trimming Vancouver, lawn mowing Vancouver Burnaby Richmond, best strata landscaping companies Vancouver):

- **Appears:** commercial landscape maintenance (landing page), lawn mowing (homepage, via the `http://www` duplicate).
- **Absent:** strata landscaping (the most valuable query), hedge trimming, and every "best landscaping companies" listicle (RenovationFind, TheBestVancouver, Beyond Ltd).

Third-party presence that already exists and helps: Yelp (10 reviews, updated June 2026), HomeStars (with reviews naming Tim and Nigel), BBB profile, YellowPages, HERE. These are exactly the sources LLMs cite, so keeping them accurate matters more than most on-site work.

### 4.3 Content gaps versus competitors

Services named in `llms.txt` or on the services page with **no page of their own**: spring cleanup, fall cleanup, lawn care programs (aeration, fertilization, lime, overseeding), pruning, garden bed maintenance, green waste removal, snow removal and salting (the quote form offers it).

Cities named in every title with **no page of their own**: Burnaby, Richmond. Neighbourhoods around the W 15th address with no mention: Kitsilano, Point Grey, Kerrisdale, Dunbar, Shaughnessy, Mount Pleasant.

Question-intent content, which is what AI engines cite, is nearly absent: no pricing guide, no "how often should strata landscaping happen", no "what to ask when tendering a strata landscaping contract", no seasonal calendar for the Lower Mainland.

### 4.4 E-E-A-T

Strong raw material, weak presentation: photo-verified visits, before/after portfolio, named crew in reviews, $5M insurance, 250+ properties. But no author or founder bio, no "last updated" dates, no credentials (WorkSafeBC, BCLNA membership, insurance certificate) shown, and the founding-year contradiction actively damages trust.

---

## 5. AI search findings

| Check | Status |
|-------|--------|
| AI crawlers allowed | Yes |
| `llms.txt` present | Yes, but Lighthouse "Agentic Browsing" flags it as not following the spec: needs a single H1, a blockquote summary, H2 sections whose items are Markdown links with one-line descriptions; it currently links to `.php` URLs and the raw jobFlow form |
| `llms-full.txt` | No |
| Machine-readable pricing (`/pricing.md`) | No, and no pricing anywhere on the site |
| FAQ blocks with schema | Yes on the four service pages (good, this is the strongest AI asset the site has) |
| Definition-style opening paragraphs | Partial |
| Stats with sources / dates | No |
| Author attribution | No |
| Visible "last updated" | No |
| Comparison / cost / how-to content | None |
| Third-party citations (Yelp, HomeStars, BBB) | Yes, but with conflicting facts |

Verdict: AI engines *can* read the site, but there is little on it worth quoting, and what they read elsewhere contradicts it.

---

## 6. The plan

### Phase 0 — Technical consolidation (week 1, all code, no content)

1. `.htaccess`: https + apex 301; `.php` → clean; `/home` and `/index.php` → `/`; `/services/` → `/services` with no leaked query; `sitemap.xml` → `sitemap.php`; long `Cache-Control` for `/assets/`; HSTS.
2. `head.php`: canonical from page identity; add `<meta name="robots" content="max-image-preview:large">`.
3. Nav, footer, `llms.txt`: clean URLs only.
4. `service-template.php` + `/services` hub + homepage cards + footer: internal links per §3.3.
5. Homepage H1 whitespace fix; reserve hero height to kill the CLS.
6. Titles/descriptions to length (below).
7. `sitemap.php`: add static pages, drop dead entries, real `lastmod`.
8. Fonts: two families, self-hosted, preloaded.
9. Deploy, `cmp` against prod first (public/ drift), OPcache reset, verify each URL's status and canonical with curl, then Search Console: submit sitemap, inspect the four duplicate variants, request indexing of the four landing pages.

Suggested titles (≤ 60 chars):

| Page | Title |
|------|-------|
| `/` | Landscaping & Grounds Maintenance Vancouver \| Mowology |
| `/services/strata-landscaping-maintenance` | Strata Landscaping Maintenance Vancouver \| Mowology |
| `/services/commercial-landscape-maintenance` | Commercial Landscape Maintenance Vancouver \| Mowology |
| `/services/hedge-trimming` | Hedge Trimming Vancouver & Burnaby \| Mowology |
| `/services/professional-lawn-mowing-care` | Lawn Mowing & Lawn Care Vancouver \| Mowology |

### Phase 1 — Entity, local and reviews (weeks 1–3, mostly outside the codebase)

1. Decide the founding year and legal name once. Put name, founding year, address-or-SAB decision, hours, email, phone, GBP Place ID and social URLs in `business_settings`; render everywhere from there (schema, footer, About, llms.txt).
2. Google Business Profile: claim/verify if not done; primary category "Landscaper", secondary "Lawn care service", "Commercial property maintenance" equivalents; service list matching the site; service areas Vancouver, Burnaby, Richmond; 20+ real job photos; weekly Posts fed by the Content Cascade (GBP is already a cascade target); Q&A seeded with the FAQ content; UTM on the website link.
3. Fix the footer review link to the real Place-ID URL and verify `ReviewRequestService` (already hooked into `end_visit`) is sending it. Review velocity on GBP is the single biggest map-pack lever.
4. Align Yelp, HomeStars, BBB, YellowPages, HERE, Bing Places, Apple Business Connect to the same NAP, hours, categories and description. Add `sameAs` for each to the schema.
5. Add `foundingDate`, `openingHoursSpecification`, `hasOfferCatalog` (services), `knowsAbout`, `areaServed` with `GeoCircle` or the three cities to the `LocalBusiness` node; make the homepage the only page that emits the full organisation node and have every other page reference it by `@id`.

### Phase 2 — Content engine and architecture (weeks 2–8)

**Build (3–5 days):**

1. CMS: add `article` to the `page_type` enum (new migration; the enum lives in `cms_pages`), a `/blog/{slug}` route (or `/guides/` — pick one and keep it), an article template with author box, published/updated dates, `BlogPosting` + `FAQPage` schema, and a related-services block that links to the landing pages.
2. Wire the Content Engine's "publish to CMS" step (currently `articleUrl: '' // set after CMS publish (future)` in `content-engine.php`), so Generate → Edit → Publish → Cascade is one flow. Requires `ANTHROPIC_API_KEY` in `secrets.php`.
3. Blog index page, RSS feed, articles in `sitemap.php`.
4. A `/pricing` page (human) and `/pricing.md` (agents) with real ranges: per-visit residential mowing, monthly strata maintenance bands by property size, hedge trimming per linear metre, seasonal cleanup ranges. Ranges are enough; the point is to be the only local firm that answers "how much does strata landscaping cost in Vancouver" at all.
5. A `/service-areas/{city}` template fed by real data the CRM already has: number of properties maintained in that city, neighbourhoods served, before/after pairs from that city, a map. Only build a city page where there is genuinely local content to put on it; empty city pages are worse than none.

**Publish (ongoing, 2 pieces/month via the engine):**

New service pages (each with FAQ schema, before/after, a real case study, ≥ 800 words):

- Spring cleanup Vancouver; fall cleanup and leaf removal; lawn aeration and overseeding; lawn fertilization programs; pruning and shrub care; garden bed maintenance and mulching; snow removal and salting for strata.

City pages: Burnaby, Richmond, then Vancouver neighbourhoods (Kitsilano, Kerrisdale, Point Grey) once there are photos and property counts to show.

Guides (the AI-citation layer), each opening with a 40–60-word direct answer:

- How much does strata landscaping cost in Vancouver (with the ranges from `/pricing`)
- How often should a strata property be landscaped in the Lower Mainland (a month-by-month calendar)
- What to include in a strata landscaping RFP: a checklist strata councils can download (lead magnet)
- In-house caretaker vs contracted landscaping for strata: a cost comparison table
- Laurel vs cedar hedge trimming: timing, height rules, City of Vancouver bylaw notes
- Winter grounds care for commercial properties: liability and salting

Every page: named author (Tim, founder, year founded, years in the trade), visible "Updated" date, at least one sourced statistic (City of Vancouver, BC Strata Property Act, BCLNA, Environment Canada), internal links up to the relevant landing page and down to related guides.

### Phase 3 — Authority (weeks 4–26)

1. Get listed in the pages that already rank for "best landscaping companies Vancouver": RenovationFind, TheBestVancouver, HomeStars "Best of" (needs review volume), Beyond Ltd's list. These are the pages LLMs cite for "best" queries.
2. Industry: BC Landscape & Nursery Association member listing; CHOA (Condominium Home Owners Association of BC) business member directory, which strata councils actually search; WorkSafeBC clearance letter and insurance certificate on the About page.
3. Property-management vendor lists: every PM firm Mowology already invoices (the CRM knows them) has a preferred-vendor page or portal. Ask for a listing and a link.
4. Local: Kitsilano / West Side business associations, community sponsorships with a link, one local news mention per year (a before/after transformation story works).
5. Social via the existing Content Cascade: Instagram and Facebook posts per article, plus short vertical before/after videos to YouTube Shorts, since YouTube is heavily cited by AI Overviews.

### Phase 4 — AI search layer (parallel with Phase 2)

1. Rewrite `llms.txt` to the llmstxt.org format; add `llms-full.txt` (concatenated Markdown of every public page, regenerated by cron from the CMS).
2. `/pricing.md` as above.
3. Add a "Quick answer" block at the top of every service page and guide (40–60 words, self-contained, keyword in the first sentence).
4. Add `dateModified`, `author` and `publisher` to all schema; visible dates on all pages.
5. Monthly manual visibility check (20 queries in §7) across ChatGPT, Perplexity and Google AI Overview; record who is cited; feed gaps back into the content queue. If budget allows, Otterly or Peec AI automates this.

---

## 7. Keyword map and query set

Primary targets (page → query cluster):

| Page | Head query | Long-tail cluster |
|------|------------|-------------------|
| Strata landing | strata landscaping Vancouver | strata landscape maintenance Burnaby / Richmond, strata landscaping company, strata grounds maintenance, landscaping for strata councils |
| Commercial landing | commercial landscape maintenance Vancouver | commercial landscaping Burnaby, office park landscaping, retail property grounds maintenance |
| Lawn mowing landing | lawn mowing service Vancouver | lawn care Vancouver, weekly lawn mowing Burnaby, lawn maintenance Richmond |
| Hedge trimming landing | hedge trimming Vancouver | laurel hedge trimming, cedar hedge trimming, hedge shaping Burnaby |
| Pricing guide | strata landscaping cost Vancouver | landscaping maintenance prices Vancouver, how much does lawn mowing cost Vancouver |
| RFP guide | strata landscaping RFP | landscaping contract for strata, how to hire a strata landscaper |
| City pages | landscaping Burnaby / landscaping Richmond | landscaper Kitsilano, landscaping company Kerrisdale |

20-query AI visibility set (run monthly):

1. best strata landscaping company Vancouver
2. strata landscaping maintenance Vancouver
3. commercial landscape maintenance Vancouver
4. landscaping company for property managers Vancouver
5. how much does strata landscaping cost in Vancouver
6. how often should strata landscaping be done
7. what should be in a strata landscaping contract
8. lawn mowing service Vancouver
9. lawn care company Burnaby
10. landscaping Richmond BC
11. hedge trimming Vancouver
12. when to trim laurel hedges Vancouver
13. fall cleanup service Vancouver
14. spring cleanup landscaping Vancouver
15. lawn aeration Vancouver
16. snow removal for strata Vancouver
17. landscaping company Kitsilano
18. photo-verified landscaping service
19. Mowology reviews
20. Mowology landscaping Vancouver

---

## 8. Measurement and targets

Baseline everything from the CRM's GSC sync before Phase 0 ships (clicks, impressions, average position for the 20 queries; indexed page count; Core Web Vitals in Search Console).

| Horizon | Target |
|---------|--------|
| 30 days | One canonical URL per page in the index; zero `www`/`.php`/`/home` duplicates; all landing pages indexed and receiving impressions; LCP < 2.5 s, CLS < 0.1 |
| 90 days | Blog live with ≥ 6 pieces; pricing and RFP guides published; GBP verified with ≥ 25 reviews and weekly posts; NAP consistent on all 8 listings; page 1 for "commercial landscape maintenance Vancouver" (already close) and "hedge trimming Burnaby" |
| 180 days | 30+ indexable pages; top 5 for "strata landscaping Vancouver"; map-pack presence for landscaping queries within ~5 km of the base; cited in at least one AI answer for the cost or RFP queries |

Realism note: the head term "landscaping Vancouver" is owned by 25–35-year-old firms and directories. Mowology's route to the top is the strata / property-manager niche, the map pack, and question-intent content that nobody local has written. That is a niche the incumbents are not defending well, which is why it is winnable.

---

## 9. Files this plan touches

| Area | Files |
|------|-------|
| Redirects, caching, HSTS, sitemap route | `public/.htaccess` |
| Canonical logic, robots meta | `public/includes/head.php` |
| Nav / footer links, services column, review link | `public/includes/header.php`, `public/includes/footer.php` |
| Hub and landing-page internal links | `public/services_static.php` or the CMS `services` page, `public/includes/service-template.php`, `public/services/*.php` |
| Homepage H1, cards, hero CLS | `public/index.php`, `public/assets/css/pages/home.css` |
| Sitemap | `public/sitemap.php` (delete `public/sitemap.xml`) |
| Schema and entity facts | `app/Modules/Marketing/Services/SeoFunctions.php`, `business_settings`, `public/llms.txt` |
| Blog / article type | new migration on `cms_pages.page_type`, `public/crm/includes/cms-renderer.php`, `public/cms-render.php`, `public/crm/marketing/content-engine.php`, `app/Modules/Marketing/Services/ContentCascadeService.php` |
| Existing tooling to lean on | `/crm/gsc/` (Search Console sync), `/crm/marketing/recommendations.php`, `ArticleGeneratorService`, `ReviewRequestService`, `BeforeAfterService` |
