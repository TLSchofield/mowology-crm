# Penny screens and functional backlog, built by Codex (plan, 2026-11-14)

Tim, 2026-11-14: "Can we have codex work on all penny screen and functional backlog?" Codex is back (the LIMIT marker said 18 Nov, but the scripts only skip it while it is under an hour old; Tim says it is back).

**Roles (standing rule):** Codex writes in a redacted scratch copy and a second Codex reviews; the Swift/Kotlin/PHP checks decide; Claude reviews the real diff, runs the simulator/emulator/local site, and commits. Codex never commits, pushes, deploys, touches secrets or sees client data. Money code (ledger, GST, bank rules) gets a Claude read of every changed line before it is kept.

## Step 0 — find what is already built (no code changes)
One read-only Codex audit per platform against the Penny items below: web `app/Modules/Expenses`, `public/crm` Penny pages; iPhone `Features/Team/Penny*`, `Features/Receipts`; Android `office/Penny*`, `receipts/`. Output: a status table per item (built / partly / open, with file evidence). This stops us building what exists (on 2026-10-24 nine "owed" migrations were already run).
- `scripts/codex-ios-build.sh audit penny-ios <task> --scope ios/MowologyCRM/MowologyCRM/Features/Team --scope ios/MowologyCRM/MowologyCRM/Features/Receipts`
- `scripts/codex-web-build.sh` audit for web; `scripts/codex-build.sh` for Android (Android repo).

## Penny SCREENS (from `docs/plans/ittai-backlog.md`)
1. **Bank-match card** — invoice number left, three possible matches stacked right, non-suggested faded, focus indigo, decision stays in the viewport (replaces "1/3"). Spine line must meet the icons (new, 2026-11-14).
2. **"Wrong" button that teaches** — "What is this?" (Interest / Fee / Transfer / Refund / Other), Penny files it and learns a per-business rule for that memo (`BankRuleLearning`).
3. **One "What Penny knows" shape** (the brain ladder) instead of two shapes and two counts.
4. **Receipt photo links** re-issued on the Receipts tab, receipt detail and pager (the Penny card already does, 1.3.137).
5. **One question per receipt** (`penny_one_question`): tap answers; "Personal" → shareholder account; "I don't know" → open-items list (Heritage plan, findings 6 and 20).
6. **Statement ask** after a few receipts, with a phone CSV upload (Heritage finding 22).
7. **"Done by myself" list** on her card, each item with why, Verify strengthens the rule.

## Penny FUNCTIONAL backlog
8. GST warning for a business nearing the $30,000 small-supplier limit (wording through the `regions` skill / the accountant first).
9. Onboarding book questions one by one (GST registered, year-end who, payroll) — apps must declare `knows` (Heritage finding on `knows`).
10. Bill and receipt controls harvested from the small-business plugin (duplicate rules, recurring bill moved >10%, expense >$25 with no receipt, auto-code).
11. Split mixed receipts by line; look-back review of approved receipts (rules first, AI only where needed, never edits locked/filed GST months); Penny on money screens.
12. Heritage: ReceiptProfile (arborist), accountant package, import his spreadsheet in the CRA way, caps raising, card balance check for TD.
Statuses are unknown until Step 0; some of these are built.

## Order and gates
1. Step 0 audits (read-only, cheap). Claude merges them into one status table and brings it to Tim.
2. Tim picks the order (suggested: 1 → 2 → 3 on iPhone, then 5 and 6, then the functional items 8 → 9 → 10).
3. Each item: spec file → Codex build in scratch worktree → Codex review → Claude diff review → tests → simulator/emulator/local walk → commit. iPhone first; Android and web parity as separate jobs.
4. Nothing ships without Tim's go-ahead; iPhone pushes start TestFlight.

## Unknowns and how they are settled
- Which items are built: Step 0.
- Codex usage left: the first audit shows it (exit 75 = out; then the matching Claude agent does the job and the status line says so).
- Android Codex build tool lives in the Android repo (`scripts/codex-build.sh`): check it still works after the 2.2.15 branch.

## Rules added 2026-11-14 (Tim)
- **One decision at a time.** A head's decision page shows the current decision and nothing else competing with it: the summary rows ("1 deposit matched to invoices waiting", "Look-back: 156 proposals…") are noise there. The count on the "Waiting now" node says how much is waiting; the next decision appears when one is answered; other queues are reached by tapping that node, not by rows between the nodes. The "How this works" info node sits directly under the head and collapses to its icon once read.
- **Android in the same job.** Every screen/layout item above, and the shared header and Receipts jobs in `2026-11-14-receipts-screen-refinement.md`, has an iPhone half AND a native Android half in the same sequence (`scripts/codex-build.sh` in the Android repo for the Android half). Status is reported per platform.
