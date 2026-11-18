# MIA-TOOLS

**Design choice:** "tools" are PHP service methods behind one registry (`MiaToolRegistry`). In Phases 1–3 they are called by *orchestrating PHP* (cron / API), not by an LLM tool-use loop; the model only returns schema-validated JSON that PHP then validates again. This matches the codebase (no tool-calling exists today) and removes the largest injection surface. If a read-only tool-use loop is added later (Phase 4), it would call only the `read` tools below, under a per-run step and token cap.

## Contract every tool must satisfy
- **Input**: associative array validated by a declared schema (type, required, enum, max length, URL must pass `SafeHttpFetcher`). Unknown keys rejected.
- **Context** (not an argument): `$ctx = {site_id, user_id|null, actor: 'owner'|'cron'|'event', autonomy: level}` built by the backend from the session/cron identity. **No tool accepts `site_id` or a user id from model output or request body.**
- **Permission**: `marketing.approve` (existing) for owner-triggered; cron actor limited to `read`/`internal` classes.
- **Side-effect class**: `read` · `internal` (writes Mia's own tables) · `external_reversible` · `external_irreversible` · `spend`.
- **Audit**: every call writes `mia_action_events` (or `activity_log` for reads that touch customer data) with actor, tool, input hash.
- **Rate limit**: per-tool per-site counters (reuse `LlmDailyCap` pattern in `ops_settings`).
- **Errors**: typed result `{ok:false, code, message, retryable}`; never throw into the cron loop; external calls retried max 2 with backoff; permanent failure → `mia_actions.status=failed` + Ada-visible `cron_runs` warning.

## Catalogue (only what Phases 1–4 need; the brief's list is trimmed accordingly)

| Tool | Class | Phase | Input (summary) | Output | Approval |
|---|---|---|---|---|---|
| `website.verify` | internal | 1 | `base_url`; proof = meta tag / `/.well-known/ittai-mia.txt` token / DNS TXT | `{verified, method}` | owner starts it |
| `website.crawl` | read (outbound) | 1 | `website_id`, `max_pages≤200`, `mode=incremental\|full` | audit id, page counts | none; only verified sites |
| `website.audit` | internal | 1 | `audit_id` | findings (deterministic) | none |
| `website.getPage` | read | 1 | `website_id`, `url` (must be in inventory) | snapshot fields (never raw HTML to LLM unless sanitised/trimmed) | none |
| `search.getPerformance` | read | 1 | `date_range`, `dimensions[]`, `filters` | rows (+ freshness/lag note) | none |
| `search.findOpportunities` | internal | 1 | `window_days` | opportunities with `score_inputs` | none |
| `search.compareTrends` | read | 4 | two ranges, min-impression threshold | per-page/query deltas flagged `significant` or `noise` | none |
| `opportunity.score` | internal | 1 | opportunity id | score + inputs (**PHP only**) | none |
| `action.propose` | internal | 1 | type, opportunity id, draft ref, why, benefit, risk | action id | none (creates `proposed`) |
| `action.decide` | internal | 1 | action id, `approve\|reject`, reason | updated action | **owner only, session-bound** (never cron/LLM) |
| `action.execute` | per type | 2 | action id | execution record | requires `approved` row **and** autonomy policy pass |
| `memory.recordDecision` / `memory.recall` | internal | 1 | subject_key, evidence hash | suppression verdict | none |
| `content.createDraft` | internal | 2 | kind, source ids, template | `mia_content_drafts` id (LLM via gateway) | none (draft) |
| `content.preview` | read | 2 | draft id | signed, expiring preview URL (noindex) | none |
| `site.publishDraft` | **external_reversible** | 2 | action id | remote id, rollback ref | **owner approval each time** until Level 3 grant |
| `site.rollback` | external_reversible | 2 | execution id | result | owner |
| `media.score` | internal | 2 | photo ref | sharpness/exposure/res/dup group (GD, deterministic) | none |
| `media.privacyScreen` | internal | 2 | photo ref | flags (vision, advisory) | human confirm before any publish |
| `media.proposePairs` | internal | 2 | job/visit id | candidate before/after pairs w/ confidence | human confirm |
| `ops.getCompletedJobs` / `ops.getApprovedPhotos` | read | 3 | window, service | rows limited to consent-cleared | none |
| `penny.getServiceMargin` | read | 3 | service key, window | revenue, margin %, n | none |
| `sam.getFunnel` | read | 3 | window | stage counts by source | none |
| `yui.getApprovedFeedback` | read | 3 | service | only items with testimonial permission | none |
| `reviews.analyzeThemes` | internal | 3 | review ids | themes + counts (LLM batch) | none |
| `reviews.draftReply` | internal | existing | review id | draft (existing `ReviewReplyDrafter`) | owner posts |
| `goal.create` | internal | 4 | text → LLM draft → owner edit | goal w/ baseline | owner confirms |
| `plan.create` | internal | 4 | goal id | plan (estimates, not promises) | owner |
| `outcome.measure` | internal | 4 | action id, window | `mia_performance` verdict (with "inconclusive" allowed) | none |
| `report.monthly` | internal | 4 | month | concise report → Charlie | none |

Deliberately **not** provided: any tool that spends money, deletes remote content, edits navigation/legal pages, or emails/SMS customers (Mia's existing campaign path and Yui own that, behind the consent ledger).

## Schemas (example, `action.propose`)
```json
{"type":"object","additionalProperties":false,
 "required":["type","opportunity_id","title","why","expected_benefit","risk","evidence"],
 "properties":{
  "type":{"enum":["seo_meta_update","page_draft","project_story","gbp_post","review_reply","internal_link","fix_technical"]},
  "opportunity_id":{"type":"integer"},
  "title":{"type":"string","maxLength":140},
  "why":{"type":"string","maxLength":600},
  "expected_benefit":{"type":"string","maxLength":300},
  "risk":{"type":"string","maxLength":300},
  "evidence":{"type":"array","maxItems":10,"items":{"type":"object"}}}}
```
`expected_benefit` is rendered with the label "Estimate" and an uncertainty note; PHP rejects text containing guarantee language ("guarantee", "will rank", "#1", "first page") – same idea as `MiaCampaignService::problems()`.

## Prompt-injection and execution boundaries
1. All external text (pages, reviews, GSC queries, customer text) is wrapped as data (`DraftSafety`-style tags + data-note), length-capped, and stripped of HTML/scripts before reaching a model.
2. Model output is parsed as JSON against a schema; free text is shown to humans but never executed, never used as a URL/SQL/path, and never selects `site_id`, tool names or approval state.
3. `action.execute` re-loads the action and the draft from the DB; it ignores anything the model said at execution time.
4. Draft flags (unknown emails/links/phone numbers not in the business facts) carry over from `DraftSafety::flags()` and block auto-execution at any autonomy level.
5. Crawled pages cannot cause the crawler to follow off-domain links beyond a same-registrable-domain allowlist.

## Failure handling matrix
| Failure | Behaviour |
|---|---|
| DNS/SSRF violation | block, log `ssrf_blocked`, finding "site not fetchable" – never retried |
| robots disallow | skip URL, record as `blocked_by_robots` |
| 429/5xx from site | back off, halve rate, stop audit after 3 consecutive; partial audit flagged |
| GSC/GA quota or token expiry | mark connection `needs_reauth`, one Charlie/Ada item, no retry storm |
| LLM error/timeout/cap hit | action created without explanation ("Explanation unavailable") – deterministic content still shown |
| Publish partial failure | action `failed`, rollback attempted if connector `canRollback`, owner notified |
