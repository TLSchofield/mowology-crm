# MIA-IMPLEMENTATION-PLAN

Effort figures are engineering-day estimates for one developer *including* Codex-written code and Claude review (the repo's `scripts/codex-web-build.sh` path), excluding owner-side waiting (Google approvals, DNS, legal). They are judgement, not measurements.

## Step 0 – Pre-conditions (before any code)
1. **Rebase onto the live lineage.** This worktree was cut from a June snapshot; I fast-forwarded it to `origin/feature/language` @ `1320fe73`. Vault notes imply production is ahead (migrations ≥1395, `testcompany` tenant). Run `scripts/prod-pull.sh` / `prod-drift.sh` and confirm what branch holds the newer Mia/tenant work **before** numbering migrations or touching shared files (`mia.php`, `tests/bootstrap.php`, `mowology-brand.css`).
2. Confirm the tenant-resolver story (Ittai backlog blocker) – Phase 1 works single-tenant with `site_id=1`.
3. Owner decisions below.

## Decisions requiring owner approval
| # | Decision | Recommendation |
|---|---|---|
| D1 | Extend existing Mia (not rebuild) | **Yes** |
| D2 | Add a shared `MiaLlmGateway` + `mia_llm_usage` ledger and per-tenant monthly $ cap (default $5) | **Yes** |
| D3 | New generic action ledger `mia_actions` over existing draft tables (not migrating them) | **Yes** |
| D4 | Mowology's own CMS is the first connector; WordPress in Phase 2 | **Yes** |
| D5 | Defer GA4; capture first-party lead source instead | **Yes** (GA4 needs site tagging + cookie consent) |
| D6 | Web UI in AppStack with `--mw-*`; no Ittai web theme layer yet | **Yes** (confirm) |
| D7 | No LLM tool-use loop before Phase 4; Level 3 autonomy not built before Phase 4 | **Yes** |
| D8 | Standardise on one `TokenVault` for new connectors | **Yes** |
| D9 | Promise nothing on Wix, Facebook/Instagram, paid ads | **Yes** |
| D10 | Legal review of project-story/testimonial use of customer data before Phase 2 publishing is switched on | **Required** |
| D11 | Which branch is canonical for Mia work (`feature/language` vs `feature/mia-*` still ahead by 42–113 commits of an older base) | Needs Tim |

## Phase 1 – Marketing Intelligence Foundation (draft-only; no customer site is modified)
Milestones in dependency order:

| # | Work | Files (create unless noted) | Tests | Days |
|---|---|---|---|---|
| 1.1 | `SafeHttpFetcher` | `app/Services/Net/SafeHttpFetcher.php`; `tests/Unit/Net/SafeHttpFetcherTest.php`; edit `tests/bootstrap.php` | SSRF table, redirects, size/time caps | 2 |
| 1.2 | Migration A: `mia_actions`, `mia_action_events`, `mia_decisions`, `mia_memory`, `mia_llm_usage` + runner | `database/migrations/14NN_mia_action_ledger.sql`, `public/crm/api/run-migration-14NN.php` | migration applies twice safely | 1 |
| 1.3 | `MiaLlmGateway` | `app/Modules/Marketing/Services/MiaLlmGateway.php` | cap, cost calc, schema reject, injection wrapping | 2 |
| 1.4 | `MiaActionService` + `MiaAutonomyPolicy` (levels 0–1 only) + `MiaMemoryService` | same dir | approval enforcement, replay no-op, rejection suppression, two-tenant | 3 |
| 1.5 | Migration B: websites, pages, audits, findings | `14NN_mia_website_intel.sql` | | 1 |
| 1.6 | Website verification | `MiaWebsiteService.php` | verify gating | 1 |
| 1.7 | `SiteCrawler` (robots, sitemap, rate limit, conditional GET, change tracking) | `Marketing/Services/Site/SiteCrawler.php`, `RobotsParser.php` | local fixture server tests | 4 |
| 1.8 | `SiteAuditRules` (deterministic) | `Site/SiteAuditRules.php` | one test per rule | 3 |
| 1.9 | Opportunity generalisation + scorer reading GSC tables (`gsc_query_page_stats`) and findings | `MiaOpportunityScorer.php` (reuse `SeoFunctions::scoreRecommendation` ideas) | scoring determinism, thresholds | 3 |
| 1.10 | Explanation + draft generation (title/meta rewrites, internal-link suggestions) | extends `MiaLlmGateway` callers; `MiaRecommendationWriter.php` | linter (no guarantees), fixtures | 3 |
| 1.11 | Crons: `mia_audit_weekly`, `mia_search_weekly`; register in `public/crm/database_appstack.php` list | `Marketing/Cron/*.php`, `public/crm/cron/*.php` shim | CLI boot test (see feedback: CLI boot after tenant change) | 2 |
| 1.12 | API modes in `Marketing/Api/mia.php` (`actions`, `action_detail`, `decide`, `connect_website`) | edit `mia.php` | permission + CSRF | 2 |
| 1.13 | UI: Mia workspace page, action preview, website-connect flow; `.mw-mia-*` CSS | `public/crm/marketing/mia_appstack.php` (+ partials), edit `mowology-brand.css`, sidebar | render-as-role check, `check-pages.py` for inline JS | 5 |
| 1.14 | Charlie brief items from the queue | edit `MiaDeskService::brief()` | brief shape test | 1 |
| 1.15 | Mowology demo (see below) | fixtures / synthetic data | | 1 |
| | **Total Phase 1** | | | **≈ 34 days** (~7 weeks) |

**Acceptance criteria (Phase 1):**
1. Verified-only crawl of mowology.ca (or a staging copy) completes ≤ 200 pages, respecting robots, with change tracking on the second run (unchanged pages = 304/hash-same, no re-analysis).
2. Every audit rule has a passing test; zero false-positive on a known-good fixture.
3. At least one scored opportunity appears in the queue with plain-English why, evidence links, Estimate-labelled benefit, risk and before/after text.
4. Approve/reject/"not now" persist with actor + timestamp; a rejected recommendation is not re-proposed unless its evidence hash changes materially.
5. No external write path exists in Phase 1 (grep-verifiable: no connector `publish`).
6. SSRF suite green; two-tenant tests green; `vendor/bin/phpunit` fully green.
7. `mia_llm_usage` shows per-run cost; cap halts model calls but leaves deterministic output.
8. Renders correctly on a phone-width viewport, light and dark.

### Phase 1 demonstration (Part 20) – draft-only
Tenant: Mowology (Vancouver; Burnaby, Richmond; priority: strata maintenance), real data only where already in the system, otherwise synthetic.
1. Mia crawls the site (or a staging copy; never writes).
2. Rule finds e.g. "/services/strata-landscaping has a 22-char title and no Burnaby/Richmond mention" and GSC shows impressions with low CTR for strata queries.
3. Opportunity scored (inputs visible).
4. Mia drafts a new title + meta description + a paragraph, with plain-English explanation.
5. Appears in the queue (and Charlie's brief).
6. Owner reviews preview, approves/rejects with reason.
7. Decision recorded in `mia_actions`, `mia_action_events`, `mia_decisions`.
8. Status shown ("Approved – waiting to be applied by you" in Phase 1, since no publish path exists; applying is manual or Phase 2).
9. Re-run: rejected idea suppressed; approved idea marked done; a related new suggestion cites the earlier decision.

## Phase 2 – Execution (after Phase 1 acceptance + D10)
| Work | Days |
|---|---|
| `WebsiteConnector` interface + capability flags; `CmsConnector` (drafts → `cms_pages` with revisions, rollback via revision) | 5 |
| Preview (signed, noindex) + publish/rollback actions, execution log (`mia_executions`) | 4 |
| `WordPressConnector` (REST, application password, draft-only first) | 7 |
| Photo scoring (GD sharpness/exposure/dedupe), privacy screen, pairing, `mia_media_*` + permission gate | 8 |
| Project-story generator with gate (consent, crew heart, service need, "adds value" test, anti-thin-page rule) | 8 |
| `TokenVault` | 3 |
| Tests + security regression | 5 |
| **≈ 40 days** | |
Acceptance: nothing publishes without an approved action whose draft hash matches; rollback proven on the CMS connector; no photo without permission row; EXIF stripped; a story is refused when it duplicates an existing page.

## Phase 3 – Operational intelligence (≈ 20 days)
Read-only query services for Otto (completed jobs/approved photos), Penny (service margin; **verify which tables exist on this lineage**), Sam (funnel), Yui (approved feedback); `mia_events` outbox + drain; review themes; GBP audit (if API access exists). Acceptance: completing a job with consented photos produces at most one evaluated opportunity (dedup), visible in the queue within 15 minutes, and none if gates fail.

## Phase 4 – Autonomous growth (≈ 30 days)
Goals/plans, scheduled reviews, funnel + first-party attribution (`mia_attribution`), `outcome.measure`, monthly report to Charlie, Level 3 grants (scoped, expiring, revocable), optional read-only tool-use loop, GA4 (if D5 reversed). Acceptance: measured verdicts with "inconclusive" allowed; a revoked grant stops autonomous execution immediately; a Level 3 action appears in the audit and the next brief.

## Phase 5 – Ittai website platform
Not scheduled. Entry condition: Phases 1–4 stable, tenant isolation real, ≥N tenants asking. Needs separate architecture (templates, hosting, domains/SSL, export/portability).

## Cross-cutting
- **Deployment:** lftp via `scripts/deploy-safe.sh` after `prod-pull.sh`; OPcache reset after deploy; verify pages render fully (PHP fatal = truncated 200). Pushing does not deploy. No deployment without Tim's say-so.
- **Process:** per global rules, Codex writes web changes with `codex-web-build.sh`, Claude reviews; Codex review before deploy; if Codex exits 75 use `web-builder`/`code-reviewer` agents.
- **Risks:** stale branch lineage (D11); Google API approvals (GBP) and quotas; tenant isolation not real yet; shared-host CPU; model drift/quality (mitigate with linter and human approval); scope creep from the 33-item capability list (hold to the phase boundaries).

## Phase 2 progress (2026-10-26)
- **2.0 done (not deployed):** `GbpService` builds the v4 `accounts/A/locations/L` path (the connect screen only saved `locations/L`, so reviews, posts and photos would have returned 404). Needs Google to enable `mybusiness.googleapis.com` for project 526825690041 before live calls work (restricted API, owner cannot enable it).
- **2.1 done (not deployed):** `WebsiteConnector`, `CmsConnector`, `MiaPublishService`, migration 1403 (`mia_executions`), API modes `action_apply` and `action_rollback`, Apply and Undo buttons. Rules proven by tests: approved plus matching draft hash only, compare-and-set write, live page checked before and after, undo restores the exact old values and refuses if the page was edited since.
- **Finding that narrowed 2.1:** several live pages (for example `/services/strata-landscaping-maintenance`) are rendered from `public/includes/service-data/*.php`, and their `cms_pages` rows only hold `{{placeholders}}`. A write there would be saved and never seen. The connector refuses template rows, and the live-page check refuses any page whose live title differs from the CMS row. In practice only `/about`, `/services`, `/contact`, `/home` and CMS-made pages (articles) can be applied by Mia. The rest stay "apply it yourself".
- **Preview:** for title and description the useful preview is before-and-after text (shown in the workspace). A signed full-page preview exists in `cms-render.php` (HMAC `_pid/_exp/_tok`) and is the route for body content in a later step.
- **2.2 done (not deployed):** `MiaTokenVault` (table `mia_secrets` is in migration 1403), unused until a WordPress connector exists.
- **Held:** 2.3 WordPress connector (no WordPress site to test on), 2.4 photo scoring and 2.5 project stories (D10 legal review first).

## Phase 3 progress (2026-10-26)
- **3.0 done:** `MiaServiceKey` (one neutral key per kind of work, unknown spelling = `other` and reported) and `MiaFunnelRules` (won = `accepted` or `converted`, checked against `converted_at` on production the same day; `quoted` rows carry `converted_at` too, so status decides, not the timestamp).
- **3.1 done:** `MiaFeeds`, read-only, no names or comment text: Otto (completed visits with approved, consented photo pairs), Penny (margin per service key, unknown margins never averaged), Sam (funnel), Yui (ratings; empty today, Google reviews wait for the API approval).
- **3.2 built, not deployed:** migration 1404 (`mia_events`, unique on site + type + subject), `MiaEventService` (sweep, drain, gates re-checked at drain time, adopt-an-existing-action so one visit gets one question ever), cron `mia_events_drain` (every 15 minutes, registry entry added, crontab line still to add). Paused or observe-only leaves events pending. A proposal is a `project_story` question with no draft; drafting stories still waits for the D10 legal review (2.5), so approving one has no effect yet.
- **Codex review of 3.2:** real and fixed: crash window between saving the action and marking the event (now adopts the prior action), sweep and drain-gate depended on a row limit (now paged and a direct per-visit check). Not real: site scoping (the CRM tables hold one tenant per database, no site column), early cron bootstrap (same convention as every other cron).
- **Not built yet:** arrival-page capture on the public quote form (needs its own Codex review).

## Phase 2.5 photo stories (2026-10-26, unheld by Tim; deployed, OPcache reset still needed)
- **Destination is the existing Portfolio** (`portfolio_projects`, status draft/published), not a new CMS article type. Approving a story saves a DRAFT card; the owner publishes it in the CRM Portfolio page. Undo deletes the card only while it is still an untouched draft (every field compared under a row lock).
- **Facts the model sees:** kind of work, neighbourhood, month, business name. Nothing else. Output checked by the shared wording rules plus a name check and a plain-vocabulary check (a few unusual words are shown to the owner as "Check: ...", many reject the draft).
- Pieces: `MiaStoryWriter`, `MiaStoryPublisher`, drain asks for wording at proposal time (`MiaEventService`), workspace labels for stories. Codex review: undo race, partial-edit undo, consent re-check, name-check bypass and number race fixed. Not changed: XSS (portfolio.php escapes every field with htmlspecialchars).
- **Known limits:** wording is drafted once when the question is created (a failed draft leaves the question without wording, no retry yet); the vocabulary list will need a few additions over time; testimonials stay held.

## One decision, every channel (2026-10-26, Tim: phone first, iOS first)
- **Built, tested, not deployed:** `MiaBundleService` (preview, approve-and-publish, undo, reconcile), `MiaHeartFlow` (the owner's heart approves the pair and tells Mia at once), handler mode `action_approve_publish`, web preview in the Growth tab. Every switch is off: `mia_publish_mode` = bundle, `mia_publish_channels` (facebook, instagram), `mia_publish_delay_minutes` (default 10), `mia_single_operator` = 1.
- **How it posts:** the bundle only fills the existing social queue (`social_posts`, `social_queue`); the existing publisher cron sends each post after the delay. Undo cancels still-pending posts in one transaction; the portfolio card is removed only if untouched. Instagram cannot be deleted through the API, so it takes a second confirm.
- **The heart:** with the switch on, an owner's heart approves the pair (consent still checked by `BeforeAfterService::canApprove`), skips the older template post, and tells Mia now. A 15-minute cron sweep is the safety net.
- **Codex review:** fixed - cancelled posts in one transaction, in-flight vs posted told apart, place must be a neighbourhood (never an address, never a numbered hashtag), LiteSpeed finish plus cron sweep for the after-reply work. Not changed: the heart endpoint's earlier synchronous pair queueing (already there before), owner-typed story text is the owner's responsibility (they see the exact words).
- **Still to do:** first real Facebook post with Tim holding Approve; Instagram after that; iOS preview screen and push; Android; season-timing rule; page-draft generation for approved page suggestions.

## Page generation (2026-10-26, Tim approved the plan)
- **Built, tested, NOT deployed:** `MiaPageWriter` (facts-only; refuses when no service matches the search), `MiaPageMarkup` (the only way text becomes HTML, everything escaped), `MiaPageExtras` (real published portfolio projects appended word for word as the case studies, plus a quote link), `MiaPagePublisher` (unpublished CMS page `blog/<slug>` via `ArticleService`, undo while untouched), `MiaActionService::reopen`, handler mode `page_write`, preview/edit/save in the Growth tab. Needs migration 1405 (`mia_content_drafts.body`).
- **Facts rule:** the model sees the business name and contact details, the services that match the search (name + the owner's description), the other service names and the places worked in. The search words are targeting input only: they are NOT facts for the checks (a search for "24 hour insured" cannot make either word true). No case study is ever written by the model.
- **Checks (PHP, not trusted):** shared wording rules for title and description; for the body: no claim that cannot be checked, no number not in the facts, no link/email/foreign phone, no capitalised name not in the facts, no filler phrase, 350-1100 words, at least three sections and a last FAQ section with at least three real questions. One repair attempt (also used when the reply is in the wrong shape). Owner edits are not blocked but Mia's checks are shown beside the page.
- **Cost and control:** only on the owner's click ("Write this page"), one writer per suggestion at a time (MySQL lock), 4 cents per try on production, up to two tries.
- **Codex review:** fixed - search words as facts, FAQ shape, one-writer lock, cleanup that could strip a page published meanwhile, visible checks on edits. Not changed: owner edits are not blocked (owner's business, owner's words).
- **Real run (rolled back) on production for "landscape maintenance":** passed first try, 576 to 666 words, matched only the Garden Maintenance service, real projects appended: two published ones.
