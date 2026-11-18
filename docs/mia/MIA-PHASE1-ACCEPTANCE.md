# MIA Phase 1 acceptance (2026-10-26)

Criteria from `MIA-IMPLEMENTATION-PLAN.md`, with the evidence and, plainly, what is NOT yet proven.
Evidence = automated tests unless stated. "Live" = observed on production by a person.

| # | Criterion | Evidence | Not yet proven |
|---|---|---|---|
| 1 | Verified-only crawl of a site, ≤200 pages, obeys robots.txt, second run re-reads only what changed | `SiteCrawlerTest` (62), `MiaDemoWorkflowTest::testCrawlOnlyAfterTheOwnerProvedOwnership…`, `…RobotsTxtIsObeyed…`; conditional GET + content hash tested | A real crawl of mowology.ca has not been run by a person. Real cURL transport tested only against a local server (this Mac's PHP has no CA bundle, so real HTTPS fetches were not exercised). |
| 2 | Every audit rule tested; no false positive on a known-good fixture | `SiteAuditRulesTest` (44): each of 26 rules fires on bad data, clean site has zero findings | Rule thresholds (title 20–65, description 70–160, thin = 150 words) are conventional, not tuned on Mowology's pages. |
| 3 | A scored opportunity in the queue with plain-English reason, Estimate-labelled benefit, risk, before/after | `MiaOpportunityScorerTest` (34), `MiaRecommendationWriterTest` (66), `MiaDemoWorkflowTest` steps 2–5, UI checked in a browser against the real API on seeded SQLite | The real model has never been called; the wording prompts and the strictness of the linter are untested against real output. Click estimates use assumed benchmark click rates. |
| 4 | Approve / reject / later persist with actor and time; a rejected idea is not re-proposed unless its evidence changes | `MiaActionLedgerTest` (51), `MiaApiHandlerTest` (36), `MiaApiHttpTest` (10, real endpoint over HTTP), demo step 6–9 | MySQL row locking and the advisory lock were never executed (SQLite ignores them). |
| 5 | No external write path in Phase 1 | `MiaDemoWorkflowTest::testNothingCanBeWrittenToAWebsite` (every request a GET; no POST/PUT/FTP/mail calls in any Mia service; no action ever executed) | — |
| 6 | SSRF suite green; two-tenant tests green; full PHPUnit green | `SafeHttpFetcherTest` (75, ~35 hostile addresses, redirects, rebinding, proxy, budgets); every service has a two-site test; full suite 3,900+ tests | Real tenant isolation does not exist platform-wide: `business_settings` and the Search Console tables are single-tenant. Mia's own tables carry `site_id`. |
| 7 | `mia_llm_usage` shows per-run cost; the cap halts model calls but leaves deterministic output | `MiaLlmGatewayTest` (34), demo `testAiCostIsMeasuredAndCapped`, `MiaScheduledJobsTest` AI-limit case | The $3/$15 per-million-token price is copied from existing code; real spend not yet measured. |
| 8 | Page renders on a phone, light and dark | Checked in a browser at 375 px and desktop (no sideways scroll, ≥48 px targets); `MiaWorkspacePageTest` (9) | Dark mode and Ittai mode not checked; the real login, menu and production data not yet seen by me. |

## Also built and tested
- Weekly crons with locking, silent-on-success, crash recording, CLI boot test (`MiaCronBootTest`, 18).
- Charlie's morning brief lines, quiet unless worthwhile (`MiaQueueBriefTest`, 18).
- A proposal whose problem got fixed closes itself (found by the demo run, then built).

## Run it yourself
`php tests/tools/mia_demo.php` prints the whole nine-step demonstration. `php tests/tools/mia_ui_harness.php` serves the workspace page on seeded data.

## Open before Phase 2
D10 legal review of using customer photos and testimonials; D11 which branch is canonical; the platform tenant lookup (blocker for a second business); cPanel cron lines and migrations 1401/1402 run by Tim; first real crawl and first real model call observed.
