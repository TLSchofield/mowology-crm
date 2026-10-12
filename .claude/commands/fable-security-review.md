---
description: Independent site-wide security review by Fable reviewers (read-only), split into sections, findings verified by the lead before any fix
---

# Fable security review — Mowology CRM / Ittai

**When to run:** only after every fix agent from the 2026-10-12 reviews has finished and its work is
committed and deployed (Tim, 2026-10-12: "have fable run it separately AFTER all updated code is
finished"). Read-only throughout. **No code changes until Tim approves the remediation plan.**

## Tim's brief (verbatim — the standard every reviewer is held to)

> Act as a senior application security engineer conducting an adversarial security audit of this
> entire CRM. Your task is to identify exploitable security vulnerabilities, not to reassure me that
> the application is secure. Do not modify any code during the initial audit.
>
> **Phase 1: Understand the application.** Inspect the entire repository and identify: programming
> languages, frameworks and dependencies; application entry points and routing; database schema and
> database access patterns; authentication and session management; user roles and permissions;
> company/tenant isolation; all API endpoints; file uploads, downloads and storage; payment, banking,
> email, AI and third-party integrations; background jobs, scheduled tasks and webhooks; deployment
> configuration, environment variables and secrets; logging, monitoring, backup and recovery
> mechanisms. Identify the actual production architecture where configuration is available. Clearly
> distinguish verified facts from assumptions.
>
> **Phase 2: Conduct the security audit.** Use the current OWASP Top 10 and OWASP ASVS as references.
> Investigate at least: (1) broken access control and IDOR; (2) cross-tenant data leakage;
> (3) privilege escalation and role bypass; (4) authentication, password reset and session
> vulnerabilities; (5) SQL injection and other injection; (6) XSS, CSRF and unsafe HTML rendering;
> (7) API authentication, authorisation, rate limiting and abuse; (8) secrets, credentials,
> cryptography and sensitive data exposure; (9) file upload, download and storage; (10) payment
> manipulation, duplicate processing and webhook verification; (11) unsafe imports, exports,
> reporting and bulk operations; (12) AI-agent prompt injection, excessive tool permissions and
> unauthorised actions; (13) SSRF, unsafe URL fetching and insecure integrations; (14) security
> misconfiguration and exposed development files; (15) dependency vulnerabilities and supply-chain
> risks; (16) logging, alerting, audit trails and incident response; (17) database privileges,
> backups and recovery; (18) race conditions, duplicate requests and unsafe error handling.
> Investigate actual code paths. Do not mark a control as secure simply because a related library is
> installed or a UI element is hidden.
>
> **Phase 3: Trace authorisation end to end.** For every sensitive operation, trace the request from
> the UI through the API, business logic and database. Determine whether the server verifies: the
> identity of the requesting user; the user's permission to perform the action; the user's
> permission to access the specific record; the company or tenant the record belongs to; any
> restrictions on the requested fields or changes. Inspect list, detail, search, export, download,
> update, delete, reporting and background-job pathways. Check whether identifiers or permissions
> supplied by the browser can be manipulated.
>
> **Phase 4: Produce evidence-based findings.** For every vulnerability: severity (Critical, High,
> Medium, Low); confidence (Confirmed, Probable, Unverified); affected file, function, endpoint or
> configuration; relevant code locations; the attack scenario; preconditions; business impact; a
> safe reproduction procedure using test data; the recommended fix; a regression test that prevents
> recurrence. Distinguish confirmed vulnerabilities from potential weaknesses and missing evidence.
> Do not claim exploitability unless the code and execution path support it.
>
> **Phase 5: Test the fixes.** Identify opportunities for automated security tests, prioritising:
> cross-tenant isolation; unauthorised access to individual records; role and permission
> enforcement; authentication and session handling; input validation and injection; payment
> integrity; file access controls; webhook authentication and duplicate delivery; AI-agent
> permission boundaries. Run only non-destructive tests against authorised test environments. Do not
> perform destructive actions, expose real customer information, or change production settings.
>
> **Final deliverables:** (1) an executive risk summary; (2) a ranked vulnerability register; (3) a
> list of verified security controls; (4) a list of controls that could not be verified; (5) the ten
> highest-priority remediation actions; (6) an automated security-testing plan; (7) a
> deployment-readiness assessment. Do not give the application a blanket security certification.
> State what was actually inspected, what tests were executed, what remains unverified and what
> requires independent penetration testing. Do not modify the code until I approve the remediation
> plan.

## How the lead runs it

1. **Preconditions:** no fix agents running; `git status` clean for code; note HEAD in every prompt so
   all reviewers audit the same code. Collect the 2026-10-12 entries from every head's
   `references/traps.md` — reviewers must check each of those fixes holds (no bypass), not re-report.
2. **Phase 1 (one Fable agent):** the architecture inventory — produces the facts sheet (marked
   verified vs assumed) that every Phase 2 reviewer gets as context. Wait for it.
3. **Phases 2–4 (Fable agents, one per section below, launched together):** each gets Tim's brief
   above, the Phase 1 facts sheet, its section, and the reviewer rules. Max ~1,200 words each.
4. **Lead verification:** check every Critical/High against the code, and with read-only
   `scripts/prod-run.sh` previews where data matters. Test-only reproductions on test records;
   never real customer data, never sends, never production settings.
5. **Phase 5 + deliverables:** the lead writes the seven deliverables as one report (publish as an
   Artifact for Tim), then STOPS for Tim's approval of the remediation plan.

## Reviewer rules (paste into each prompt)

- Read-only: do NOT edit, commit, deploy, or send any request to the live site. Work only in the
  current git worktree (give its absolute path; never the parent repo).
- Read `CLAUDE.md` and the relevant head skill(s) first. Production: PHP 8.3, MySQL 8 but code must
  stay MySQL 5.7-compatible; cPanel shared hosting; `public/` is the web root; `secrets.php` holds
  all credentials; `.htaccess` controls what's reachable; `public/.htaccess` maps `.php .php8 .phtml`
  to PHP 8.3.
- For every finding: file path + line; a concrete attack or failure (who sends what → what they
  get); severity — critical (remote code, account takeover, money moved, bulk data exposure),
  high, medium, low; confidence confirmed (traced in code) / plausible (say what you couldn't
  verify). No style opinions, no "consider refactoring".
- Also check that each 2026-10-12 security fix in your section actually holds (no bypass).
- Output: ranked list, at most 12 findings, then one line on what is sound. Max 900 words.

## Sections (Phases 2–4, one Fable reviewer each)

1. **Authentication & sessions** — `public/loginAuth/*`, `app/Core/Auth/*` (session, JWT issue/verify,
   role/is_active per request, throttling, password reset), `app/Core/session_config.php`,
   `public/api/auth/*`, logout and device-token handling.
2. **Authorisation & IDOR across the APIs** — every `app/Modules/*/Api/*.php`, `public/crm/api/*.php`,
   JWT mobile endpoints: role checks, crew-on-visit / ownership checks, CSRF on every
   state-changing POST, GET requests that change state, `?action=` under `/api/`.
3. **Files: uploads, downloads, storage** — every upload handler (photos, receipts, CMS media,
   documents, PDFs), `.htaccess` in `public/uploads`, `public/_media`, `public/vendor`,
   `public/crm/email-logs`, serve/download endpoints (`serve-receipt`, `receipt-image`,
   `documents/download`), path traversal, extension vs MIME, executable files in the web root,
   backups/logs/.md/.sql/.git reachable over HTTP.
4. **Public & client-facing surfaces** — `public/*.php` site pages, `public/customer/*` (quote,
   invoice, portal, visit report — token strength, expiry, IDOR), CMS rendering (stored XSS),
   tracking links (open redirect), unsubscribe/opt-in tokens, forms (spam, injection, CSRF).
5. **Money & messaging abuse** — payment paths (Stripe webhooks signature, e-Transfer/Yardi
   readers trusting forged email, invoice pay links), email/SMS/push sending (header injection,
   sending to the wrong person, CASL consent gate), anything an outsider can trigger that costs
   money or sends messages.
6. **Secrets, ops & infrastructure** — `scripts/*` (credential handling, TLS), `public/crm/api`
   admin tools (migrations runners, opcache reset, cron "run now", dev-context), cron shims and
   `_guard.php`, logging of secrets/PII, error output leaking SQL, Ada's health checks reporting
   green when broken, iOS app secrets/keychain/ATS (`ios/`).
7. **Tenancy, AI agents & data flows** — company/tenant isolation (`*_id` scoping in queries, Ittai
   multi-tenant readiness), the AI heads (Penny/Otto/Sam/Mia/Yui/Charlie/Ada) — prompt injection from
   emails/receipts/web pages into Claude calls, what each agent may write/send without approval,
   imports/exports/bulk operations, SSRF in URL fetches (supplier price scraping, link previews).
8. **Backups, logging, audit & dependencies** — backups and restore path (what exists, where, tested
   or not), audit trails for money/permission changes, alerting, Composer dependencies
   (`composer.lock` versions vs known advisories), iOS/Android dependency manifests.
