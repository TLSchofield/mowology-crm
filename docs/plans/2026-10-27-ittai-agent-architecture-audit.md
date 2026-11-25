# Ittai agent architecture audit and roadmap — 2026-10-27

Scope: Phases 1, 3, 4, 8 and 13 of the AI-workforce brief, plus the three highest-impact improvements.
Phases 5, 6, 7, 9, 10, 11 and 12 are designed in outline only where they follow from the audit.
**Nothing here has been built.** Every statement tagged **[V]** was read in code at the cited place
(three read-only agent passes on `feature/language` + `claude/waveapps-terms-protection`, with the
load-bearing claims re-checked by hand). **[P]** means proposed. Where I did not find something it says
"not found".

## 0. Read this first: two code lines
Production work is split. `origin/feature/snow-contracts` holds 57 commits that `feature/language` lacks
(`HeadStateService`, `PennyKnowledgeService`, `AdaServerErrors`, Charlie/Penny desk changes — [V] by
`git diff --name-only HEAD...origin/feature/snow-contracts`). This audit read `feature/language`; the
conclusions below were checked against the snow-contracts head files that overlap and do not change,
but any build must start from a reconciled base (existing vault note: "base on the branch holding the
feature you are continuing").

## 1. Current architecture (all [V])
**The "heads" are not agents. They are desks.** Each head is a set of PHP services over its own tables
that deterministically find work (SQL and rules), show it to the owner, and call a language model only to
draft or read something, mostly on a click or a capped cron.

| Layer | What exists |
|---|---|
| Language models | One provider: Anthropic. 11 call sites, each a hand-rolled `curl_init('https://api.anthropic.com/v1/messages')` (BankGuidance, CharlieAsk, YuiDraft, ReceiptBookkeeper, ReceiptLlmExtractor, OttoManual, SamFollowup, ArticleGenerator, ContentCascade, MediaTag, PlantCare). No shared client, router or provider abstraction. Model ids are scattered constants: `claude-sonnet-5-5` (6 sites), `claude-sonnet-4-6` (2), `claude-opus-5-5` (1), `claude-opus-5` (1). |
| Identity and prompts | No identity registry. Each head's persona is a hardcoded string in its own service; Yui and Sam build theirs per call in `YuiRules::replyPrompt` / `SamFollowupService::replyPrompt` with the shared copy-rules file. Several prompts carry no persona at all (ReceiptBookkeeper, ReceiptLlmExtractor, ArticleGenerator, ContentCascade, PlantCare). |
| Business data | Direct SQL over the authoritative tables. Models see JSON snapshots put in the user turn. Only PlantCare uses a model tool (web search restricted to trusted domains); no site uses tool-calling against the app. |
| Memory | `head_lessons` (migration 1348): append-only rows of what the owner corrected, `site_id`, source, reason; confidence is computed at read time (`HeadLessons::rule()`: two consistent answers become a rule; 365-day window). `otto_lessons` is a separate key-value table. Penny's lessons are injected into the receipt prompt (8 per vendor). `kb_*` vendor knowledge commons shares vendor facts across tenants (candidate/shared/curated/blocked). No episodic memory, no vector store, no per-head conversation memory. |
| Background work | cPanel crontab, registered by a human; registry array in `public/crm/database_appstack.php:311-366`; run log `cron_runs` via `recordCronRun()`; `CronLock` flock mutex. Ada (`AdaDeskService::cronChecks`) flags missing, failing or slow crons. |
| Detection | Deterministic: overdue invoices (`invoice_overdue`, `invoice_reminders`), stale quotes (`SalesDeskService`, learned wait per service), unclaimed client replies (`UnclaimedReplyService`), quote requests unanswered 4 h, Charlie urgent alerts (autopay failures, disputes, books watch, security watch). No LLM is used to detect. |
| Coordination | No event bus, outbox or task queue between heads. Charlie *pulls* each head's `brief()` in-process (`CharlieBriefService::collect`), dedupes by item key into `charlie_items`, ranks with learned preferences (`CharlieRankService`, 60-day half-life) and caches in `head_brief_ready`. Hand-offs are direct calls (Yui reads `SalesDeskService::queue()`), shared tables, and inbound routing (`InboundRouteService`). No Sam→Penny or Yui→Otto action queue. |
| Autonomy | Heads propose, a human clicks. Sam/Yui "NEVER send" (header comments, `send()` only from a click); Otto "nothing runs without the owner's click"; Penny prepares into `expense_suggestions`; Mia queues only approved campaigns. The one earned-autonomy path is bank rules (`BankRuleLearning`, 2 owner confirmations, then it acts alone; contrary decisions switch it off). Penny's "5 in a row = trusted vendor" is a **badge only** — nothing reads it to skip approval ([V] grep). Other auto paths: `products.field_auto_send`, `autopay_live_mode` (off by default). |
| Permissions | Session login + CSRF on every head API; permission keys (`billing.edit` Yui/Sam, `expenses.approve` Penny, `marketing.approve` Mia, `jobs.edit` Otto). Charlie: owner only (admin may use `ask`). No tool registry; endpoints call services directly by `mode`. |
| Audit | Per-head tables with decider/status (`yui_actions`, `charlie_inbox_actions`, `otto_suggestions`, `sam_acceptances`, `sam_followups`, `expense_suggestions`, `bank_guidance`, `charlie_asks`). No unified activity ledger. |
| Errors | Per-site: error text stored on the row, message returned; no HTTP retry on any model call. Retries exist for OCR jobs (3 attempts), Charlie alerts (3, hourly), social publishing (backoff), media vision (3). |
| Tenancy | Isolation is **a separate database per tenant**; the head tables have no `site_id`. Only `head_lessons` (shared) carries `site_id`. [V] `site_id` is absent from the CREATE TABLE of yui_actions, charlie_*, otto_*, sam_*, expense_suggestions, mia_campaigns. There is **no "current tenant" resolver**: `HeadLessons`, `PennyLessons::forVendor` and callers default to `site_id = 1`. |
| Cost | Daily call caps per site: ten use their own counters (ops_settings keys or counts from their own tables); only ReceiptBookkeeper and ReceiptLlmExtractor use `LlmDailyCap::take`. `LlmDailyCap` **fails open** on a DB error. Tokens/cost are logged in `bank_guidance`, `charlie_asks`, `equipment_manual_reads`, `plant_care_cards` (cost_usd); tokens only in `yui_actions`, `sam_followups`, `expense_suggestions`, `media_tag_state`; **nothing** for ReceiptLlmExtractor (usage returned and ignored), ArticleGenerator, ContentCascade. No per-tenant/agent/skill rollup. |
| Tests | Strong unit coverage of head logic (Penny badge/brain/self-audit/lessons, Charlie rank/ask/inbox rules, Yui rules/routing, Mia, Sam lessons, Ada rules, Knowledge promotion), run on the 3,338-test suite. Weekly `PennySelfAuditService` re-checks sampled decisions against later corrections. **Not found:** golden-set/LLM evals, prompt-injection tests, cross-tenant leak tests for heads, Sam/Otto API integration tests. |
| Master Studio | **Not found.** Nearest: the dashboard heads deck (`public/crm/includes/dept-heads-deck.php`, per-head `*-card.php`) and Charlie's `today` overview. `tools/marketing-studio/` is a separate marketing tool, not an agent studio. |

## 2. Strengths
1. **Deterministic-first is already the culture.** Detection, ranking and money rules are SQL/pure functions; models draft or read. This is exactly the principle the brief asks for and it is why costs are bounded today.
2. **Human-in-the-loop by default, with learned, revocable autonomy** (bank rules: two confirmations, auto-off on contradiction). A good template for per-action autonomy.
3. **Learning is auditable.** `head_lessons` keeps every correction with source and reason; confidence is derived, not asserted.
4. **Output validation exists where it matters** (JSON schemas plus `validate()` against the chart of accounts; Otto's intervals must carry a verbatim quote; receipt line items checked against OCR sums).
5. **Injection awareness exists at the drafting sites** (`DraftSafety::wrap`, `DATA_NOTE` for Yui/Sam).
6. **Failure isolation:** a head that fails is shown as failed, not treated as resolved (`CharlieBriefService`); Ada watches crons.
7. **Cross-tenant knowledge commons with a promotion rule** (tenant_count ≥ 2) already separates shared from private.

## 3. Weaknesses
1. **Every model call is bespoke.** Duplicated cost math, refusal/`max_tokens` handling, timeouts (45–150 s), no retry, no provider seam, a model id per file. Changing a model or provider is an 11-file edit.
2. **No unified cost view and uneven caps.** Three sites log nothing; ten caps are separate; the shared cap fails open. Ittai cannot yet answer "what did Penny cost tenant X this month".
3. **No durable task or event layer.** Work is rediscovered by each desk on each pass; there is no record that "a task was created, handed to Otto, completed". Hand-offs between heads do not exist as data. `sam_followups` and `yui_actions` have no unique key, so duplicate rows are possible.
4. **Autonomy is per feature flag, not per action.** No approval queue shared across heads, no global kill switch ("disabling a head means removing its cron or permission").
5. **Tenancy depends on one assumption** (a DB per tenant) that is not enforced in code: a shared-table call path defaults to `site_id = 1` ([V]). The backlog already lists this as a blocker before a second tenant.
6. **Prompt-injection defence is uneven.** Fenced at Yui/Sam only. BankGuidance, ReceiptBookkeeper, ReceiptLlmExtractor, OttoManual, ArticleGenerator, ContentCascade put untrusted text (bank descriptions, OCR, manuals, request fields) in prompts with no defence; mitigated mainly by schemas, validators and no-write-without-approval. **ContentCascade writes drafts** (`social_posts`, `marketing_campaigns`) and updates `business_settings.email_signature_text` from model output.
7. **Content honesty risk:** ArticleGenerator rule 5 tells the model to invent a case study when no real project is supplied. This conflicts with the AI-use and honesty position the new Terms of Service takes. Treat as P0 to change.
8. **Ghost cron:** `penny_chase` is registered (hourly) but `app/Modules/Expenses/Cron/penny_chase.php` is not on either branch ([V]).
9. **Cron scheduling is manual** (a person adds each crontab line). Fine for one tenant; a barrier for self-onboarding tenants.
10. **No evals** that would let autonomy be raised on evidence.

## 4. Security concerns
- Injection surface listed above; severity is limited by "no write until approved" for Yui/Sam/Otto/Penny but not for ContentCascade/MediaTag writes.
- Keys: one `ANTHROPIC_API_KEY` shared by all tenants; no per-tenant spend ceiling beyond call counts.
- `LlmDailyCap` fails open, and a flood path (inbound receipt email) depends on it.
- No kill switch; no per-action audit of "who/what authorized this".
- Cross-tenant: relies on DB-per-tenant; `head_lessons` and `kb_*` are the shared tables and are exactly where a missed `site_id` leaks.

## 5. Architecture decision (Phase 3)
**Recommend Architecture C (hybrid), in a pragmatic form.** Reasons from the code: the heads already behave as independent specialist desks sharing infrastructure (`HeadLessons`, `HeadBrain`, Charlie's pull). They are deterministic services, not LLM-reasoning loops, so "seven independent agents with separate reasoning" (A) would multiply cost and drift, and "one runtime with seven prompts" (B) would force deterministic desks through a model. C keeps the seven identities as deterministic *workers* over a shared *spine* (gateway, task/event table, action policy, ledger), and calls a model only inside a skill when interpretation is needed. This also matches the stated preference. Do **not** add an agent framework or queue server; MySQL tables plus the existing cron are enough at 1–10-crew scale.

## 6. Autonomy model (Phase 8) — proposal [P]
Levels 0–4 as briefed, stored as rows `(tenant, head, action_key, level, limits_json)` so autonomy is per action. Defaults: everything that exists today = level 2 (prepare, owner approves). Level 3 only for actions with proven accuracy on the eval set. Money-moving actions (sending invoices, charging, paying, posting to the ledger) capped at level 2 until explicitly raised by the owner per action. Bank rules already behave as level 3 for one action and become the first row.

## 7. Recommendations (prioritized)
**P0 — critical**
1. **Single LLM gateway** (`LlmGateway`): one place for HTTP, timeout, one retry on 429/5xx/overload, model alias → id config, structured-output support, refusal/`max_tokens` handling, token+cost logging to one `llm_calls` table (tenant, head, skill, model, tokens, cost, outcome), the daily cap (fail **closed** on money-adjacent buckets, open on read-only), and the injection fence (untrusted text wrapped and labelled, system note). Migrate the 11 sites one at a time behind it; the existing injectable `$transport` seam makes this testable.
2. **Tenant resolver + guard.** One `Tenant::siteId()` used by `HeadLessons`, `PennyLessons`, kb access; remove the `= 1` defaults; add a cross-tenant leak test over the shared tables.
3. **Stop inventing content:** remove ArticleGenerator rule 5; require a real project or return "need details". Fence ContentCascade inputs and keep its writes as drafts only.
4. **Reconcile the two branches** before building on them.

**P1 — high value**
5. **Task spine** (`agent_tasks` + `agent_task_events`): durable tasks with `tenant`, `owner_head`, `origin_head`, `kind`, `entity_type/id`, `priority`, `state` (open, working, waiting_approval, done, failed, cancelled), `attempts`, `max_attempts`, `due_at`, `correlation_id`, **`dedupe_key` UNIQUE**, `payload_json`. Deterministic detectors (the SQL that already exists) write tasks; heads claim and complete them; Charlie reads the table instead of re-pulling six `brief()` calls and surfaces only escalations. Start with three flows: overdue invoice, stale quote, unclaimed client reply.
6. **Action policy + activity ledger** (`agent_action_policy`, `agent_activity`): every head action (draft, send, post, apply) records head, skill, input refs, decision, approver, result, reversible?, cost. Includes a global and per-head kill switch checked by the gateway and the action layer.
7. **Skill registry (code, not prompts):** a PHP class per skill with name, owner head, inputs, preconditions, validator, autonomy ceiling, version — wrapping what already exists (reconcile line, classify inbound mail, draft follow-up). Prompts live next to the skill, not in one giant system prompt.
8. **Eval harness** using what exists as ground truth: Penny backtest + self-audit, Yui routing history, Sam lessons, Otto missed-visit reviews. Store results per skill/version; gate autonomy raises on them. Add injection and cross-tenant cases.
9. **Cost rollup** screen from `llm_calls` (per tenant/head/skill/model).

**P2 — future**
10. Model routing by skill (cheap model for classification, stronger for reasoning) after gateway data shows what each skill costs.
11. Episodic/semantic memory beyond lessons (add confidence/expiry/delete to a unified memory table, source-attributed; never promote model output to fact without owner confirmation).
12. Master Studio (identity/skills/triggers/autonomy/activity/eval/simulation per head) built on tables 5–9, so it is a view, not new logic; follows Ittai v1.2 design rules.
13. Cron self-registration for new tenants; replace the manual crontab step.
14. Inter-head delegation protocol (a task row with `origin_head`/`owner_head`, bounded depth and TTL) once item 5 is live.

## 8. Roadmap (order, files, migrations, risk)
| # | Item | Touches | Migration | Complexity | Risk / test |
|---|---|---|---|---|---|
| 1 | LlmGateway + `llm_calls` | new `app/Services/Llm/LlmGateway.php`; migrate one site first (CharlieAsk), then others | yes (`llm_calls`) | M | each site keeps its transport seam; golden test per site; cap behaviour test |
| 2 | Tenant resolver | `HeadLessons`, `PennyLessons`, `app/Modules/Knowledge/*` | no | S–M | leak test; verify production DB-per-tenant assumption |
| 3 | Content honesty | `ArticleGeneratorService`, `ContentCascadeService` | no | S | unit test refuses without a real project |
| 4 | Branch reconcile | git | no | M | full suite, per `feedback_check_branch_before_push` |
| 5 | Task spine + 3 flows | new `app/Modules/Agents/Services/TaskService.php`; detectors in `invoice_reminders`, `SalesDeskService`, `UnclaimedReplyService`; `CharlieDeskService` reads tasks | yes (2 tables) | L | dedupe tests, restart-safe, tasks idempotent |
| 6 | Policy + ledger + kill switch | new `AgentPolicy`, `AgentActivity`; call from send/apply paths | yes (2 tables) | L | permission-boundary and approval tests |
| 7 | Skills | new `app/Modules/Agents/Skills/*` wrapping existing services | no | M | no behaviour change on wrap |
| 8 | Evals | `tests/Evals/*`, backtest runners | maybe (results table) | M | deterministic where possible |
| 9 | Cost view | CRM page over `llm_calls` | no | S | |
| 10+ | Routing, memory, Studio, delegation | after 1–6 | | | |

Order: 3 and 4 immediately (small, protective); 1; 2; then 5; then 6 and 9 together; 7 and 8 alongside 5; Studio last.

## 9. The three highest-impact improvements
**1. The LLM gateway.** Approach: build `LlmGateway`, migrate sites one at a time starting with the safest read-only one, delete duplicated cost/refusal code as each moves. Success: 11/11 sites on it; 100% of calls in `llm_calls` with cost; one place to change model/provider (a model change = 1 config edit, was 11 files); fenced untrusted input on every site; cap that fails closed on money buckets; per-tenant monthly cost visible.
**2. The task spine with deterministic detectors.** Approach: `agent_tasks` with a unique `dedupe_key`; start with overdue invoice → Penny, stale quote → Sam, unclaimed reply → Yui; Charlie reads tasks and only surfaces escalations. Success: zero duplicate tasks in a week of production; ≥95% of tasks reach a terminal state within their deadline; tasks survive a cron restart; Charlie's brief build time and failure count drop (today it calls every head's `brief()` each run); owner-visible "done without you" count.
**3. Action policy, activity ledger and kill switch.** Approach: every head action passes one `AgentPolicy::allow(tenant, head, action)` and writes one `agent_activity` row; seed with today's behaviour (level 2) and bank rules (level 3). Success: every sent/applied/posted action traceable to head, approver and policy; kill switch tested to halt all heads in one step; no action runs above its configured level (permission-boundary tests); autonomy raised only where eval accuracy meets a stated threshold.

## 10. First end-to-end workflow (Phase 12) — outline [P]
New enquiry email → `icloud_inbox_poll`/`InboundRouteService` already routes to Sam/Yui → create task `lead.new` (dedupe on message id) → Sam: match/create lead, prepare quote (level 2, owner approves) → on approval, task `job.schedule` to Otto (deterministic conflict check; owner approves the slot) → on completion, Penny's invoice flow (existing; ledger rules) → Yui drafts each customer message via the gateway, owner sends → every step writes `agent_activity` → Charlie surfaces only tasks past deadline or failed. Each hand-off is a task row, so it survives restarts and needs no uninterrupted model conversation.

## 11. Open questions for Tim
- Confirm production is DB-per-tenant for every head table (the audit assumes it from `PennyKnowledgeService`'s header and the testcompany setup).
- Which branch is the base: `feature/language` or `feature/snow-contracts`?
- Fail-closed vs fail-open on the shared cap for receipt mail (cost vs losing a receipt).
- Whether the "invent a case study" rule was deliberate marketing policy.

Evidence method: three read-only Explore agent passes (call anatomy; events/cron; autonomy/permissions/tenancy/tests), then manual re-check of: 11 call sites by grep, model ids by grep, `site_id` on head tables by parsing every CREATE TABLE, Penny trust badge usage, `penny_chase.php` absence on both branches, cap users.
