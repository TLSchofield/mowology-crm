# MIA-DATABASE

Proposal only. **Do not create these until the migration number is confirmed** against production (`scripts/prod-query.sh` / Migrations tab): this tree's highest file is `1380_invoice_refunds.sql` but vault notes mention 1395+ applied. Reserved gaps exist (1372–1379). I use `14NN` below as a placeholder range.

## Conventions followed [confirmed from repo]
- `database/migrations/NNNN_name.sql`; `CREATE TABLE IF NOT EXISTS`; MySQL 5.7-safe (no JSON column functions, no generated columns, no window functions); `utf8mb4` / `utf8mb4_general_ci`; **no foreign keys** (collation drift on prod) – integrity enforced in services; no `ALTER ... IF NOT EXISTS`; no runtime DDL; each migration gets a `run-migration-NNNN.php` guarded by `MigrationRunnerGuard`.
- Tenancy: **every new table has `site_id INT NOT NULL DEFAULT 1`** (same as `head_lessons`, CMS tables), indexed as the *leading* column of the main composite index, and every service query binds it. Decision D3 in `client-account-model.md` makes `clients.site_id` the spine; Mia tables hang off `site_id` directly because they are not client-owned. Until a real "current tenant" lookup exists (backlog blocker) the value is always 1; the columns make the later switch mechanical.
- JSON stored as `MEDIUMTEXT` and validated in PHP.
- Tokens: never new plaintext columns. Reuse `gsc_properties` / `social_accounts` encrypted-token pattern; a `mia_connections` row stores only a reference + scopes + status.

## Tables (conceptual → concrete)

| Table | Purpose | Key columns | Main indexes |
|---|---|---|---|
| `mia_connections` | one row per external link (website, GSC, GA4, GBP, WordPress) | site_id, kind, label, external_ref, scopes, status, token_ref (→ encrypted store), verified_at, last_ok_at, last_error | (site_id,kind) |
| `mia_websites` | sites Mia may read | site_id, base_url, mode (`existing`/`managed`), connector, capabilities (CSV flags), verified_via, verified_at, crawl_cap, robots_hash | (site_id) unique(site_id,base_url) |
| `mia_site_pages` | crawl inventory + change tracking | site_id, website_id, url_hash (sha1), url, status_code, content_hash, title, meta_desc, h1, canonical, robots_meta, word_count, schema_types (CSV), etag, last_modified, first_seen_at, last_seen_at, last_changed_at, last_crawl_at | unique(site_id,website_id,url_hash); (site_id,last_changed_at) |
| `mia_audits` | one run | site_id, website_id, kind, started_at, finished_at, pages, status, cost_usd, summary | (site_id,started_at) |
| `mia_audit_findings` | deterministic findings | site_id, audit_id, page_id, rule_key, severity, evidence (text), fingerprint (sha1 rule+url+evidence-class), first_seen_audit_id, resolved_at | (site_id,fingerprint), (site_id,audit_id) |
| `mia_search_metrics` | **only if `gsc_query_page_stats` can't serve**; otherwise reuse existing GSC tables | — | — |
| `mia_goals` | owner objectives | site_id, text_original, metric_key, baseline_value, baseline_at, target_value, target_date, status, review_at, created_by | (site_id,status) |
| `mia_plans` | strategy per goal | site_id, goal_id, summary, assumptions, costs_estimate, measurement_plan, version, status | (site_id,goal_id) |
| `mia_opportunities` | scored candidates | site_id, type, subject_key (e.g. `page:<hash>`, `service:<key>`), score, score_inputs (JSON text), confidence, evidence_hash, status, goal_id, first_seen_at, last_scored_at | (site_id,status,score), (site_id,subject_key) |
| `mia_actions` | **uniform approval ledger** | site_id, opportunity_id, type, side_effect (`none`/`internal`/`external_reversible`/`external_irreversible`/`spend`), status (`proposed`/`approved`/`rejected`/`executing`/`done`/`failed`/`rolled_back`/`expired`), title, why, expected_benefit, risk, evidence (JSON text), draft_ref_table, draft_ref_id, preview_url, autonomy_level_at_creation, proposed_at, decided_by, decided_at, decision_note, executed_at, result | (site_id,status,proposed_at), (site_id,draft_ref_table,draft_ref_id) |
| `mia_action_events` | append-only audit | site_id, action_id, event, actor (`user:ID`/`mia`/`system`), detail, created_at | (site_id,action_id,created_at). Never deleted (same stance as `AuditTrail`) |
| `mia_executions` | each external call made | site_id, action_id, connector, operation, request_hash, response_status, remote_id, rollback_ref, started_at, finished_at | (site_id,action_id) |
| `mia_autonomy_grants` | Level 3 scopes | site_id, action_type, scope (JSON text: sections/categories), limits (per day/week, spend), granted_by, granted_at, expires_at, revoked_at | (site_id,action_type) |
| `mia_content_drafts` | page/post drafts not yet in any CMS | site_id, kind, title, body_ref/body, seo_title, seo_desc, schema_json, source_job_id, status, version | (site_id,status) |
| `mia_media_assets` | per-photo marketing metadata (layers on `visit_photos`/media system) | site_id, photo_ref, sharpness, exposure, width, height, dup_group, marketing_score, privacy_flags (CSV), alt_text, caption, status | (site_id,photo_ref), (site_id,dup_group) |
| `mia_media_permissions` | explicit publish rights | site_id, photo_ref, client_id, basis (`portfolio_consent`/`tenant_owned`/`staff`), consent_ref (→ `consent_ledger`/`visit_photos.consent_portfolio`), scope (`website`/`social`/`ads`), address_ok, faces_ok, plates_ok, reviewed_by, reviewed_at, expires_at, revoked_at | (site_id,photo_ref) |
| `mia_events` | outbox | site_id, event_key, dedupe_key, payload (small JSON), priority, created_at, processed_at, attempts | unique(site_id,dedupe_key), (processed_at,priority) |
| `mia_attribution` | funnel + source per lead | site_id, lead_event_id, quote_request_id, source_type, landing_page, campaign_ref, model (`last_touch_30d` etc.), confidence | (site_id,lead_event_id) |
| `mia_performance` | measured outcomes of an action | site_id, action_id, metric_key, before_value, after_value, window_days, measured_at, verdict (`improved`/`flat`/`worse`/`inconclusive`), note | (site_id,action_id) |
| `mia_decisions` | what the owner decided and why (memory of rejections) | site_id, subject_key, evidence_hash, decision, reason_code, reason_text, decided_at, suppress_until | (site_id,subject_key) |
| `mia_memory` | durable brand/messaging/priority facts | site_id, kind (`brand`,`approved_message`,`prohibited`,`priority`,`style`), key, value, source (`owner`/`learned`), updated_at | unique(site_id,kind,key) |
| `mia_llm_usage` | cost ledger | site_id, task, model, input_tokens, output_tokens, cost_usd (computed in PHP from a price constant), ok, error, created_at | (site_id,created_at), (site_id,task) |
| `mia_profile` | marketing profile (business type, services, areas, budget, capacity, approval prefs, prohibited) | site_id, key/value rows or one row | unique(site_id) |

Existing tables reused untouched: `mia_suggestions`, `mia_campaigns`, `mia_channel_drafts`, `mia_questions`, `mia_mutes`, `gbp_reviews`, `mia_web_reports`, `gsc_*`, `seo_recommendations` (+ status history), `head_lessons`, `consent_ledger`, `visit_photos`, `cms_pages`, `lead_events`, `quote_requests`.

## Tenant isolation
- Leading `site_id` on every index; service constructors take `$siteId` and refuse `0`/null.
- Unit test per service: "query for site 2 never returns site 1 rows" (needs two seeded sites; `sites` table exists).
- `mia_actions.draft_ref_*` is resolved only through a whitelist of tables, and the target row's `site_id` is re-checked.
- Tenant never taken from request body, URL or model output.

## Retention
| Data | Keep | Why |
|---|---|---|
| `mia_action_events`, `mia_executions`, `mia_decisions` | indefinitely (audit) | accountability, avoids re-proposing |
| `mia_site_pages` text | latest + hash history 12 months; strip bodies of superseded versions after 90 days | storage |
| `mia_audit_findings` | 24 months | trend reports |
| `mia_events` processed | 30 days | outbox |
| `mia_llm_usage` | 24 months | billing/costing |
| review text / customer quotes | follow existing privacy module retention (**legal review**) | PIPEDA/PIPA |

## Migration strategy
1. Verify production max migration + `prod-drift.sh`.
2. One migration per concern (core queue; website/crawl; memory/decisions; media; usage) – each independently revertible by leaving unused.
3. Add runner + `tests/bootstrap.php` entries; apply locally; apply to production only on explicit approval.
4. No data backfill required; first audit populates.
