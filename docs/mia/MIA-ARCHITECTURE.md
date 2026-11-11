# MIA-ARCHITECTURE

Status: **DRAFT for owner review (2026-10-25). Nothing here is built. No code was changed.**
Basis: code at `origin/feature/language` @ `1320fe73` (2026-10-20). Facts marked **[confirmed]** were read from that tree by an investigation pass this session; **[assumed]** means inferred. Spot-check before building: the vault notes say migrations through ~1395 and the `testcompany` tenant exist on production, but this tree's highest migration file is `1380_invoice_refunds.sql` and the word `testcompany` appears nowhere in it. Either production is ahead of this branch or those changes live on another branch. **Resolve before numbering migrations.**

## 0. The most important finding

**Mia already exists and is not a stub.** The brief reads as greenfield; the repo says otherwise. So this is an *extension* programme, not a build. [confirmed]

| Exists today | Where |
|---|---|
| Mia as a head: Desk / Brain / Badge services, skill doc, dashboard card, Charlie `brief()` | `app/Modules/Marketing/Services/Mia*.php` (~12k lines of marketing services), `.claude/skills/mia-marketing/` |
| Who-to-contact finder, wording rules, campaigns, sequences, seasonal calendar, send-time learning, theme outcomes | `MiaFinder`, `MiaWording`, `MiaCampaignService`, `MiaSequenceService`, `MiaCalendar`, `SendTimeService`, `MiaThemeOutcomes` |
| Weekly GBP post + social drafts from crew photos, Google review import + reply drafts, weekly website watch (GSC 28-day vs prior 28) | `MiaChannelsService`, `ReviewReplyDrafter`, `WebsiteWatchService`; tables `gbp_reviews`, `mia_channel_drafts`, `mia_web_reports` (migration 1200) |
| Photo library tagging by Claude vision (daily cap 40) | `MediaTagService`, `MediaPickerService` |
| GSC OAuth + daily sync + rule-scored `seo_recommendations` with status history and an apply API | `public/crm/gsc/*`, `Marketing/Cron/seo_recommendations.php`, `SeoFunctions.php` |
| Article generator (Claude) + draft-only content cascade; `/blog` live | `ArticleGeneratorService`, `ContentCascadeService` |
| Email-campaign last-touch attribution (30 d) | `AttributionService`, `TrackingLinks` |
| Consent ledger checked at send time (CASL); photo portfolio-consent request (per photo, `consent_portfolio`) | `ConsentLedgerService`, `PhotoConsentRequestService` |
| Review requests after every completed visit, no gating, no incentive | `ReviewRequestService` |
| Push / email / morning-brief notification path | `Push/PushDispatcher`, `CharlieBriefService`, `charlie_morning_brief` cron |

**Mia's standing rule today: she drafts, Tim decides.** Nothing sends or posts without a click (`marketing.approve`, CSRF, two-tap on public actions). That is the Level 1 behaviour in the brief. The programme below keeps it as the default and adds the missing machinery to *measure* and (later, optionally) *delegate*.

## 1. Repository facts that constrain the design [confirmed]

- **Stack:** vanilla PHP, MySQL 5.7-compatible SQL (production is reportedly 8.0 but the rule is 5.7-safe), PDO prepared statements, **no namespaces, no autoloader** for app classes (`require_once` + `new XService($db)`); new services must be added to `tests/bootstrap.php` and get a `tests/Unit/` test. PHPUnit 10.5.
- **No agent framework.** Each head = plain services + API files + crons + skill doc. Roster is hard-coded in `CharlieBriefService::HEADS`, `PushHeads`, `CharlieRankService`, `TeamCardService`.
- **LLM calls:** no shared wrapper. Each service does its own `curl` to `api.anthropic.com/v1/messages` with `ANTHROPIC_API_KEY`; model hard-coded per class (`claude-sonnet-5-5`; `ArticleGeneratorService`/`ContentCascadeService` still `claude-sonnet-4-6`). No tool-calling anywhere; structured output only in `MediaTagService`/receipts via `output_config.format=json_schema`. Cost logged per feature (`charlie_asks`, `yui_actions`, `sam_followups`); `LlmDailyCap` is a per-bucket daily counter in `ops_settings`. **No central usage ledger.**
- **Prompt-injection defence:** only `Copy/DraftSafety` (wraps customer text in `<customer_message>`, data-note, flags unknown emails/links/bank details). Used by Sam and Yui, **not** by Mia's channel drafters.
- **Tenancy is not built.** One database, global `DB_*` constants. Artefacts only: `sites` stub (migration 1046, row 1 = mowology), `site_id DEFAULT 1` on CMS tables, `clients.site_id`, `head_lessons.site_id`. `docs/plans/ittai-backlog.md` calls "no current-tenant lookup" a **blocker before a second tenant**. `business_settings` is one row (`id=1`).
- **Cron:** standalone scripts at `app/Modules/<Mod>/Cron/*.php`, CLI-first with a web shim + `_guard.php`; runs logged to `cron_runs`; `app/Core/CronLock.php` flock (per server). No scheduler abstraction, no queue.
- **Secrets:** `public/app_config/secrets.php`. Tokens encrypted AES-256-CBC: Social via `SocialEncryption` (`SOCIAL_ENCRYPTION_KEY`), Google/GSC via inline copies keyed `APP_ENCRYPTION_KEY` (duplicated in 5+ files). No shared vault class, no per-tenant keys.
- **Audit:** `activity_log` (+ `AuditTrail` never-delete), per-feature log tables. **No event bus** (the `CampaignConnector` emitter writes `campaign_trigger_log` for the 4 email-automation modules only). No unified AI-action audit table.
- **Design:** the "Ittai Design System v1.2" (not named "Kanso" in the repo) lives in `docs/design/ittai-v1.2/` (`tokens.json`, light/dark/field). **It is implemented only in native iOS (SwiftUI `ItTokens.swift`).** Web CRM is AppStack Bootstrap 4 with `--mw-*` tokens in `mowology-brand.css`; there is no Ittai web CSS. `tools/build-tokens.mjs` referenced by the README is missing.
- **Crawler/SSRF:** none. `tools/crm-crawl.php` is a Selenium QA crawler of the CRM itself. No `gethostbyname`/private-range guard anywhere for outbound fetches.
- **GA4:** absent. No `gtag` on the public site.
- **Migrations:** `database/migrations/NNNN_name.sql`, no FKs (collation drift on prod), no runtime DDL, run by per-migration `run-migration-NNNN.php` guarded by `MigrationRunnerGuard`. Gaps are intentional reserved ranges.

## 2. Proposed architecture (extend, don't replace)

```
                      ┌────────────── Charlie brief() ──────────────┐
 Owner ── approve ──▶ │ Mia Action Queue  (mia_actions + mia_action_events)
                      └───▲───────────────────────────▲──────────────┘
         OBSERVE          │ propose                   │ execute (only after approval / policy)
 ┌───────────────┐  ┌─────┴───────────┐  ┌────────────┴─────────────┐
 │ Collectors    │  │ Opportunity     │  │ Connectors (capability-  │
 │ SiteCrawler   │─▶│ Engine          │  │ declared)                │
 │ GSC / (GA4)   │  │ deterministic   │  │ CmsConnector (own site)  │
 │ GBP / reviews │  │ scoring + LLM   │  │ WordPressConnector (P2)  │
 │ Ops/Sales/    │  │ explanations    │  │ GbpConnector (existing)  │
 │ Penny reads   │  └─────┬───────────┘  └──────────────────────────┘
 └──────▲────────┘        │
        │          MiaLlmGateway (one place: model, caps, cost ledger,
  mia_events          schema validation, DraftSafety wrapping)
  (outbox, batched)        │
                     Marketing memory (mia_memory, mia_decisions)
```

### 2.1 Components (new unless marked)
1. **`SafeHttpFetcher`** (`app/Services/Net/`): the prerequisite for anything outbound. Resolve DNS then validate every resolved IP against private/loopback/link-local/metadata ranges (v4+v6), pin the IP for the connection (defeat DNS rebinding), cap redirects (manual, re-validate each hop), cap bytes/time, allow only http/https and ports 80/443, set honest `User-Agent`. No new dependency: `curl` options only.
2. **`SiteCrawler` + `SiteAuditRules`** (`Marketing/Services/Site/`): robots.txt parser (own, small), sitemap reader, polite rate-limit (≥1 s/req, 200 pages default cap), conditional GET (ETag / Last-Modified) and content-hash change tracking in `mia_site_pages`. Parsing with `DOMDocument` (built in). All checks in §4.1 of the brief that are objective (titles, meta, headings, canonicals, robots, sitemap, broken links, structured data presence, alt text, duplicate content by shingle hash, freshness) are **deterministic rules**; the LLM only explains and drafts. Page content is stored as untrusted data and never put in a system prompt.
3. **`MiaLlmGateway`**: single wrapper for *new* Mia calls: model per task class (small model for classification/tagging, Sonnet-class for drafting), `LlmDailyCap` bucket per tenant+task, writes every call to `mia_llm_usage` (tokens, cost, task, tenant), JSON-schema output, `DraftSafety`-style wrapping for any external text, hard timeout/retry policy. Existing services keep their own curl until migrated (not in scope).
4. **Opportunity engine** (`MiaOpportunityScorer`): pure PHP. Score = f(commercial value from Penny margin data, search opportunity from GSC impressions/position, confidence, effort, time sensitivity, capacity, risk). Inputs and weights stored with each opportunity so the owner can see "why". The LLM supplies *structured assessments* (e.g., relevance 0–3 with rationale) which are clamped by PHP; it never emits the final number.
5. **Action queue** (`MiaActionService`, tables `mia_actions`, `mia_action_events`): a thin, uniform ledger *over* the existing draft tables (`mia_suggestions`, `mia_campaigns`, `mia_channel_drafts`, `seo_recommendations`). It does not migrate them. Each row: type, side-effect class, status, evidence JSON, risk, expected benefit (labelled estimate), preview ref, approval state, executor, result. This is the missing generic approval/audit layer (no shared one exists today).
6. **Autonomy policy** (`MiaAutonomyPolicy`): per-action-type level 0–3 in `ops_settings`/new table; default 1 for everything; level 3 requires a scoped, expiring grant row with limits and a kill switch. Enforced in `MiaActionService::execute()` in the backend, never in the prompt.
7. **Connector abstraction** (`WebsiteConnector` interface + capability flags, as in the brief): first implementation is **`CmsConnector` for Mowology's own site** (draft pages in `cms_pages`, revision history exists) because that is the only target that exists and it avoids a WordPress dependency for the demo. `WordPressConnector` (REST + application passwords, draft-status only) is Phase 2.
8. **Memory** (`mia_memory`, `mia_decisions`): structured records (goal, brand rule, approved/rejected recommendation + reason + fingerprint, outcome). Rejection fingerprints suppress re-proposal unless the evidence hash materially changes. Reuses the `head_lessons` "once is a hint, twice is a rule" pattern rather than inventing another.
9. **Events:** no bus exists and a generic one is not justified. Add an **outbox table `mia_events`** written by a single-line call at the 5–6 real trigger points (job completed, photos uploaded, review received, quote accepted, invoice paid, website audit done). A cron drains it in batches with dedup keys; expensive analysis is scheduled, not per-event. Failures never break the originating request (same swallow-and-log stance as `CampaignEventEmitter`).
10. **Cross-head contracts:** keep the existing pattern (read-only `brief()`, shared `subject_key`, ownership re-check such as `samOwns()`). Add read-only *query services* per head (`OttoCompletedJobs`, `PennyServiceMargin`, `SamFunnel`, `YuiApprovedFeedback`) that return plain arrays; **Mia may call them, they never call Mia**, and none invokes an LLM, so recursion is impossible by construction.

### 2.2 Operating cycle mapped to cron
Observe/Understand daily & weekly crons (`mia_audit_weekly`, `mia_search_weekly`, `mia_events_drain` every 15 min, `mia_report_monthly`), registered like `mia_email_daily` is. Each uses `CronLock`, writes `cron_runs`, is idempotent via natural keys (`site_id + week + type`).

### 2.3 Security boundaries
See MIA-SECURITY-REVIEW.md. In short: every new table has `site_id`; every query includes it; the tenant is derived server-side (never from AI output); AI arguments never reach SQL or an executor without passing a PHP validator; external text is data; publishing only through `MiaActionService::execute()` after an approval row exists.

## 3. Reuse / extend / build

| Reuse as-is | Extend | Build new |
|---|---|---|
| Mia desk/finder/wording/campaigns, consent ledger, GSC sync, `seo_recommendations`, `WebsiteWatchService`, photo consent, `ReviewRequestService`, push + Charlie brief, `LlmDailyCap`, `DraftSafety`, `CronLock`, `activity_log` | `WebsiteWatchService` (add crawl findings), `AttributionService` (add organic/GSC + lead-source), `GoogleBusinessService` (reviews/insights **only if API approved**), `MediaTagService` (add quality score + before/after pairing), `ArticleGenerator`/cascade (project-story template), Charlie brief (Mia items) | `SafeHttpFetcher`, crawler + audit rules, `mia_actions` queue + autonomy policy, `MiaLlmGateway` + usage ledger, opportunity scorer, memory/decisions, `mia_events` outbox, connector interface (+CMS, WordPress), goal/plan model, GA4 (deferred) |

## 4. Tradeoffs (why this shape)
- **Not a new framework / queue / vector store.** Repo is cPanel shared hosting with cron; adding Redis/queues would be a deployment project on its own. DB tables + cron match how everything else runs.
- **Deterministic first.** Matches the existing preference ("rules not AI" in Ada, template-driven Mia). Cuts cost and hallucination.
- **No LLM tool-calling loop in Phases 1–3.** The brief's "typed tools" are implemented as PHP services with schema-validated inputs that *orchestrating PHP* calls; the LLM returns structured JSON. A true tool-use loop is Phase 4 at most, and only for read-only tools. Rationale: no tool-calling exists in the codebase, and an unbounded loop is the main cost and injection risk.
- **Ledger over existing drafts rather than migrating them** keeps working modules untouched.

## 5. Decisions needing owner approval
See the list at the end of the chat summary and in MIA-IMPLEMENTATION-PLAN.md §Decisions.
