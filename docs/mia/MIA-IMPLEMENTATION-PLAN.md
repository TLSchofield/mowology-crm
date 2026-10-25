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
