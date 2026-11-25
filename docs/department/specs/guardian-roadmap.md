# Guardian roadmap (live agent; these are the next steps, each needs your go-ahead)
1. **Signed-in CRM pages:** nightly smoke of key pages with the existing `qa.crawler` account (reference_qa_crawler_live_check), checking that pages render fully (a PHP fatal gives a truncated 200). Touches production pages but reads no customer lists beyond what the pages show — needs your decision.
2. **Native apps:** iOS simulator and Android emulator screenshots + accessibility dumps through the parity tooling (`scripts/parity`); Maestro only if existing tools prove insufficient.
3. **Idempotency and offline tests:** duplicate-tap, interrupted upload, offline-then-sync, double invoice send — against the local test harness only, never production.
4. **Tenant isolation and security checks:** authorised dev/staging only; findings go to you, not into the general queue.
5. **Regression test generation:** every verified defect fix gets a phpunit or page-audit regression case (Forge's job once built; Guardian specifies it).
6. **API contract tests** for the endpoints the iOS/Android apps call.
Hard limits: no destructive tests, no production writes, no customer data, never marks its own recommendation approved.
