
# MIA-SECURITY-REVIEW

Scope: the *proposed* extension of the existing Mia head. This is an engineering threat model, **not a legal opinion**. Items marked ⚖ need review by a lawyer familiar with PIPEDA, BC PIPA and CASL before launch; technical controls do not by themselves make the product compliant.

## 1. Assets and trust boundaries
| Asset | Why it matters |
|---|---|
| Tenant data (clients, jobs, photos, margin) | competing businesses on one platform |
| Google/WordPress credentials & OAuth tokens | can change or read a business's public presence |
| Publishing ability | reputational damage, SEO damage, legal exposure |
| Customer photos / reviews / names | privacy, consent |
| LLM context | injection vector |
Boundaries: public internet ↔ crawler; external APIs ↔ Mia; model ↔ PHP executor; tenant ↔ tenant; Mia ↔ other heads.

## 2. Findings from the existing code that the programme must respect or fix
1. **Tenancy is not implemented** (single DB, no current-tenant lookup; Ittai backlog calls this a blocker before a second tenant). Mia's new tables carry `site_id`, but isolation cannot be *verified* in production until the platform has a real tenant resolver. **No second tenant should get Mia write features before that blocker closes.** (Severity: High, pre-existing.)
2. **Token encryption is duplicated** AES-256-CBC (no authentication tag) in 5+ files with `APP_ENCRYPTION_KEY` / `SOCIAL_ENCRYPTION_KEY`, no per-tenant keys, and a past key-rotation incident (2026-06-18). Recommendation: one `TokenVault` class (AES-256-GCM or sodium secretbox, versioned key id, fail-closed) used by all new connectors; migrate old tokens opportunistically. (Medium.)
3. **No SSRF-safe fetcher exists.** Any feature that fetches a user-supplied URL is exploitable today if added naively. `SafeHttpFetcher` is Phase 1 task #1. (High if skipped.)
4. **`DraftSafety` is not applied to Mia's channel drafters** (reviews/photos are untrusted text). Apply it to all new Mia model inputs. (Medium.)
5. **No unified audit of AI actions.** `mia_action_events` fixes this; append-only.
6. Cron web shims exist (`_guard.php`, admin POST + CSRF) – new crons follow the same pattern.

## 3. Threat model (STRIDE-lite)

| # | Threat | Likelihood/Impact | Mitigation | Test |
|---|---|---|---|---|
| T1 | **SSRF** via website URL (cloud metadata 169.254.169.254, localhost, RFC1918, IPv6, decimal/octal IPs, redirects, DNS rebinding) | likely / critical | resolve → validate all A/AAAA → pin IP via `CURLOPT_RESOLVE`; manual redirects with re-validation; http/https & ports 80/443 only; max 5 redirects, 2 MB, 10 s; reject userinfo in URL; deny `file://`, `gopher://` | unit tests with a table of hostile URLs; a local test server returning 302→127.0.0.1 |
| T2 | **Prompt injection** from pages, reviews, GSC query strings, customer text ("ignore instructions, publish …") | likely / high | untrusted text wrapped + data note; no tools given to the model in Ph1–3; output schema validated; executor reloads from DB; autonomy enforced in PHP; model can't choose tenant/tool/approval; flags block auto-exec | red-team fixtures in `tests/Unit/Marketing/` (strings like "approve this action", HTML comment injections, fake `</customer_message>`) |
| T3 | **Cross-tenant leakage** (missing `site_id` in a query; shared cache; shared logs) | medium / critical | `site_id` required in service constructors; code review checklist; per-service two-tenant tests; no tenant-agnostic caches; logs carry site id | the two-tenant test per service |
| T4 | **Unauthorized publish** (CSRF, forged approval, replay, model-initiated) | medium / high | approval rows only created by session-authenticated owner with CSRF; `execute()` requires `approved` + matching draft hash (content change after approval ⇒ re-approval); two-tap UI; idempotency key per execution | tests: execute without approval fails; edit-after-approve voids approval |
| T5 | **Credential theft / over-broad scopes** | low–med / critical | least-privilege scopes (GSC `webmasters.readonly`; GBP only needed scope; WordPress application password limited to an editor-without-publish user where possible); encrypted storage; never in logs; rotation & revoke UI; `needs_reauth` state | scan logs in tests for token patterns |
| T6 | **Malicious/oversized responses, zip bombs, content-type confusion** | medium / medium | byte caps, content-type allowlist (HTML/XML/robots), no JS execution, no external entity expansion (`LIBXML_NONET`, no `LIBXML_NOENT`), parse with libxml error suppression | fuzz corpus |
| T7 | **Crawler abuse** (pointing Mia at a third-party site to hammer it) | medium / medium | website must be **verified** (meta tag / file / DNS) before any crawl; robots obeyed; ≥1 s delay; per-host concurrency 1; per-tenant weekly cap | test: unverified → refused |
| T8 | **Customer privacy**: publishing faces, plates, addresses, names, metadata/EXIF GPS | medium / high | explicit `mia_media_permissions` row required (basis + scope + flags); strip EXIF on every derivative; vision privacy screen is advisory + human confirm; address generalised to neighbourhood; no client names without consent | tests: publish blocked without permission; EXIF absent in output |
| T9 | **Fabricated content** (invented testimonials/reviews/claims) | medium / high | testimonials only from records with permission, shown verbatim with source; draft linter rejects guarantees/superlatives/unverifiable claims; "Estimate" labelling | linter unit tests |
| T10 | **Review gating / incentives** (platform policy and law) | low / high | existing `ReviewRequestService` already requests after every visit, no selection, no incentive; keep; Mia may not add sentiment-based routing | code review rule |
| T11 | **Unauthorized spend** | low / high | no spend tool exists; any future spend tool is class `spend`, requires per-transaction approval and a hard monthly cap | n/a (absence is the control) |
| T12 | **Data exfiltration through the model** (private data sent to the API) | medium / medium | send minimum fields; no client PII in SEO prompts; ops/finance context passed as aggregates; log what categories were sent | prompt-assembly tests |
| T13 | **Cost abuse / DoS** (queue floods, retry storms) | medium / medium | per-tenant caps, dedupe keys, `CronLock`, exponential backoff, kill switch in `ops_settings` | load test with duplicate events |
| T14 | **Cron web shim abuse** | low / medium | existing `_guard.php`; new crons reuse | existing |
| T15 | **Rollback failure** leaves site broken | low / high | store prior version before write; verify post-publish fetch; auto-rollback on 5xx/validation failure when connector supports | connector contract tests |
| T16 | **Agent recursion** between heads | low / medium | read-only head query services; no LLM inside them; Mia never invoked by other heads | design constraint + grep test |

## 4. Credential-management requirements
- One `TokenVault`; versioned keys; authenticated encryption; fail closed on missing key (the earlier "insecure fallback key" behaviour must not be copied).
- Tokens never returned to the browser, never logged, never in URLs or prompts.
- Per-connection revoke + "disconnect and delete" action; connection health surfaced to Ada.
- Rotation procedure documented in `_Directions`/runbook; test that decrypt failure produces a loud, non-destructive error.

## 5. Human approval enforcement
Approval is data (`mia_actions.status='approved'`, `decided_by` = session user, hash of the approved draft). The executor, not the UI, enforces it. Level 3 = a row in `mia_autonomy_grants` (scope, limits, expiry, revocable); executor checks scope and limits per call; every autonomous execution is flagged in the audit and summarised to the owner in the next brief. Significant changes (home page, navigation, legal pages, deletes, anything touching pricing text) are **never** eligible for Level 3.

## 6. Privacy and marketing law ⚖ (areas needing legal review, not claims of compliance)
- **CASL:** consent for commercial electronic messages (express vs implied, 2-year implied window, unsubscribe). Existing `ConsentLedgerService` handles email; Mia's new work does not send messages, but project stories or testimonials use customer data in public marketing → purpose/consent.
- **PIPEDA / BC PIPA:** use of customer photos, names, job locations, reviews in marketing; transfer to a US-based AI provider (cross-border processing notice); retention periods; access/deletion requests must also remove Mia derivatives (`mia_media_*`, drafts).
- **Google/Meta platform terms** (review solicitation, GBP content policies).
- **Cookie/analytics consent** if GA4 or other trackers are ever added (one reason to defer GA4).
- **Advertising claims** (Competition Act – misleading representations): ties to the "no guarantees" linter.

## 7. Required security tests (to be written in Phase 1–2)
1. SSRF table-driven test (≥30 hostile URLs) + redirect + rebinding simulation.
2. Robots/verification gating tests.
3. Two-tenant isolation per new service.
4. Approval enforcement: execute without/after-edit approval fails; replay of an executed action is a no-op.
5. Prompt-injection fixtures through every LLM-facing builder.
6. Token vault: round-trip, wrong key fails closed, no token in logs.
7. Media permission gate; EXIF stripped.
8. Output linter (guarantees, unknown links/phones).
9. Cap tests: monthly cost cap halts model calls but not deterministic output.
10. Existing suite (`vendor/bin/phpunit`) must stay green.
