# MIA-CAPABILITIES

Legend: **Status** = Exists / Partial / Missing (verified against `origin/feature/language` @ `1320fe73`). **Cx** = technical complexity S/M/L. **Value** = H/M/L to a small field-service owner (my judgement, not measured). **Pri** = P1 (Phase 1) … P5.

| # | Capability | Status | External API / dependency | Feasibility notes | Cx | Value | Pri |
|---|---|---|---|---|---|---|---|
| 1 | SSRF-safe outbound fetcher | Missing | none | Pure PHP/curl; prerequisite for 2, 7, 21 | M | H (enabler) | P1 |
| 2 | Website crawler (robots, sitemap, rate limit, change tracking) | Missing (only a QA Selenium crawler of the CRM) | none | DOMDocument; 200-page cap | M | H | P1 |
| 3 | Technical SEO audit (titles, meta, headings, canonical, robots, alt, structured data, broken links, duplicates, freshness) | Missing as a crawler; partial via CMS `cms_seo_recalc` for own pages | none | Deterministic rules | M | H | P1 |
| 4 | Performance / mobile / accessibility checks | Missing | Google PageSpeed Insights API (free, key + quota) | Rate-limited, run on ≤5 key URLs weekly. Axe is vendored for CRM QA only | M | M | P1b |
| 5 | Search Console ingestion | **Exists** (read-only; `gsc_snapshots`, `gsc_query_page_stats`) | Search Console API | Add: device/geo breakdown if absent, URL Inspection (quota-limited ~2k/day/property), sitemap status | S–M | H | P1 |
| 6 | GSC opportunity detection (high-impr/low-CTR, declining pages, query-without-page) | **Partial** (`seo_recommendations` scoring; `WebsiteWatchService`) | — | Re-use; add decline detection with significance guard (min impressions) | S | H | P1 |
| 7 | Opportunity scoring (transparent) | Partial (`scoreRecommendation` for SEO only) | — | Generalise to all opportunity types, store inputs | M | H | P1 |
| 8 | AI recommendation generation + plain-language explanations | Partial (Claude article/draft paths) | Anthropic | Via `MiaLlmGateway` | M | H | P1 |
| 9 | Unified action queue, approval, rejection, audit | Missing (per-feature draft tables only) | — | The core Phase 1 deliverable | M | H | P1 |
| 10 | Marketing memory (goals, rejections, outcomes) | Partial (`mia_mutes`, `head_lessons`, `MiaThemeOutcomes`) | — | Structured tables + rejection fingerprints | M | H | P1 |
| 11 | Charlie morning-brief integration | **Exists** (`MiaDeskService::brief()`) | — | Add queue items | S | M | P1 |
| 12 | Connector interface w/ capability flags | Missing | — | Interface + `CmsConnector` (own site) | M | H | P2 |
| 13 | Draft page creation + preview + publish + rollback (own CMS) | Partial (`cms_pages`, revisions, schedule-publish) | — | Wrap in connector; rollback via CMS revisions | M | H | P2 |
| 14 | WordPress connector (draft-only) | Missing | WP REST API + application passwords | Capability varies by host/plugins; read + draft + media; publish gated | L | M (for external tenants) | P2 |
| 15 | Wix / other platforms | Missing | Wix APIs (OAuth app approval) | **Do not promise.** Declare capabilities per provider; investigate when a tenant needs it | L | L–M | P5 |
| 16 | Photo quality scoring (sharpness, exposure, resolution), duplicate detection | Missing (tagging exists) | Local GD/Imagick for sharpness/exposure/dedupe (deterministic); Claude vision only for relevance/privacy | Do deterministic metrics in PHP, vision only on shortlist | M | H | P2 |
| 17 | Before/after pairing | Partial (`BeforeAfterService`, `visit_photo_sets`, approval w/ `consent_state` migration 1118) | — | Add similarity scoring, human confirm stays | M | H | P2 |
| 18 | Privacy screen of photos (faces, plates, house numbers) | Missing | Claude vision (flag only) + human confirm | Never auto-publish on a pass; flag-and-ask | M | H | P2 |
| 19 | Project-story generation from completed job | Partial (pieces exist; `cms_case_studies_generated` table; **no generator found**) | Anthropic | Gate: consent_portfolio + crew heart + job value/service need + "adds genuine value" test; avoid thin pages | L | H | P2 |
| 20 | Review ingestion + sentiment/themes + reply drafts | Partial (`gbp_reviews`, `ReviewReplyDrafter`) | GBP API — **posting/reading live-mode is approval-dependent, unconfirmed** | Keep draft-only; themes via LLM on batches | M | M–H | P3 |
| 21 | Google Business Profile audit (completeness, categories, hours) | Partial (`GoogleBusinessService`: accounts, locations, createPost) | GBP APIs (access restricted; check current eligibility before promising) | Manual-checklist fallback if API not granted. **No browser automation** | M | M | P3 |
| 22 | Testimonial identification | Missing | — | Only with explicit customer permission; never invent | S | M | P3 |
| 23 | Operational reads from Otto/Sam/Penny/Yui | Partial (data exists, no query services) | — | Read-only services; Penny margin from `visit_margin_snapshots`-style data (**verify table names on this tree; a prior pass saw them only on an older tree**) | M | H | P3 |
| 24 | Funnel: inquiry → qualified → estimate → accepted → job → paid | Partial (`lead_events`, `quote_requests`, `quotes`, `roi_attribution`, `conversion_events`) | — | Join existing tables; define stages explicitly | M | H | P4 |
| 25 | Attribution beyond email | Partial (`AttributionService` = email last-touch) | — | Add first-party source capture (UTM/referrer/landing page, hash-stored) and a **disclosed** model | M | H | P4 |
| 26 | GA4 integration | Missing | GA Data API (service account, free quota) | **Requires tagging the public site first, plus consent handling (CASL/PIPEDA/cookies).** Recommend deferring; first-party capture covers lead sources | M–L | M | P4 (defer) |
| 27 | Goals → plan → baseline → review dates | Missing | Anthropic | Structured goal model; LLM turns natural language into a *draft* goal that the owner confirms | M | H | P4 |
| 28 | Scheduled strategy reviews, monthly exec report | Missing | — | Cron + templates; Charlie delivers | M | H | P4 |
| 29 | Delegated (Level 3) publishing with scoped grants | Missing | — | Only after P2 is stable and rollback proven; default off | L | M | P4 |
| 30 | Per-tenant usage/cost ledger + quotas | Missing (per-feature only) | — | `mia_llm_usage` | S–M | H (viability) | P1 |
| 31 | Facebook/Instagram publishing | Stub (`MetaService` throws "Phase 2") | Meta Graph API (app review) | Out of scope for this programme | L | L | — |
| 32 | Ittai-managed website platform (templates, hosting, domains, SSL, export) | Missing | hosting/CDN/DNS providers | Phase 5, only on evidence; mowology.ca's own CMS is the seed | XL | H long-term | P5 |
| 33 | Paid ads management / spend | Missing | Google Ads API | **Not proposed.** Spending safeguards are a separate approval | L | — | — |

## MVP (Phase 1) = rows 1–3, 5–11, 30 (+4 optional)
Produces value with **zero changes to any customer site**: audit findings and GSC opportunities become explained, scored, queued, decided and remembered.

## Things the brief asks for that I recommend *not* doing (yet)
- GA4 before first-party lead-source capture (cost, consent complexity, little incremental value for a site this size).
- Wix, Facebook/Instagram, paid ads: dependence on third-party approvals/spend; promise nothing.
- A free-roaming LLM tool-use agent. See architecture §4.
