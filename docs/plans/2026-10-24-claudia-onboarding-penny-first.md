# Claudia onboarding — she starts with ONE button: snap a receipt (plan, 2026-10-24, awaiting Tim's approval; nothing built)

Tim, 2026-10-24: "claudia onboarding should start with Penny and the expenses app" → clarified: "she will only have the receipt snap button enabled."
Claudia: a gardener, Android phone, Ittai's first founding business. Everything else (clients, quotes, jobs, invoices, schedule, books) is OFF for her until Tim switches it on, one piece at a time.
Rules: tenant-neutral, phone-only (she finishes everything on her phone), nothing she sees says Mowology.

## Sign-up first: she still names her business (Tim, 2026-10-24)
The business-name step is unchanged and comes BEFORE the snap screen. Order: sign-up → name her business → (a few plain questions) → the snap-only screen.
- Her business name sets her business identity (`BusinessIdentity`: the name on receipts, emails, sign-in page and AI prompts), and suggests her address under ittai.ca (`<name>.ittai.ca`, she can edit it, checked for clashes and reserved words).
- Same step asks only: province, GST registered yes/no (+ number), year end. They are the onboarding questions Penny reads (`OnboardingQuestions`), phrased without accounting words.
- Today a business is created by `TenantProvisioner` run by Tim (database, registry line, uploads folder, owner login). For Claudia: Tim runs provisioning with the name she gives (a one-line command or an admin screen, not self-serve yet), then she signs in and finishes the name/questions step herself on her phone. A fully self-serve sign-up (she creates her own database) is a later stage, not needed for the first founding business.
- Open unknown: whether the existing "business name" flow Tim means is the Ittai name/domain finder or `BusinessIdentity` setup; settle by reading the sign-up/onboarding code before building, and reuse it rather than writing a second one.

## What she sees (after that) — Tim, 2026-10-24: "the rest of the app buttons stay grey and inactive except the AI Office"
- The whole menu is visible, so she can see what Ittai will do for her, but every item except the **AI Office** is grey and inactive. A greyed item says
  "Not turned on yet" in words (never colour alone), is not a link, is skipped by keyboard focus order only as a disabled control with that label, and does nothing when tapped except show a one-line note.
- Her home is the **AI Office**, with the one big **Snap a receipt** button (48 px+) and her last few snaps with a status word each ("Read", "Waiting for you", "Filed").
- Inside the AI Office only Penny is active at `snap_only`. The other heads (Sam, Otto, Yui, Mia, Ada, Kaname) appear greyed with the same note, because their cards would open features that are off.
  (Settled: visible-but-grey, so each can be introduced when it unlocks.)
- After a snap: Penny's read (vendor, date, total, tax, category) with Confirm / Fix on the same screen. Not done until the server confirms.
- Tim turns pieces on later (e.g. "Expenses list", then "Bank", then "Clients"); each greyed item becomes live when its switch flips, and the snap button never moves.

## Meeting the heads one at a time (Tim, 2026-10-24: "onboarding this way allows a user to be introduced to each AI agent")
The staged app doubles as the introduction. Each head is greyed in her AI Office until it is unlocked; the first time one opens she gets a short
"Meet <name>" card: what this head does for her, in one or two plain sentences; the one thing to try first; what it will never do without her yes.
- Greyed heads stay visible as a preview (answers the open question above): she sees who is coming, and that nothing is hidden from her.
- Unlock order (a suggestion, Tim decides): **Penny** (receipts, from day one) → **Otto** (jobs, schedule, places) → **Sam** (clients and quotes) → **Yui** (messages) → **Mia** (marketing) → **Ada** (technology) → **Kaname** (chief of staff).
  A head's menu items and endpoints unlock with it, so "Penny" = snap + receipts list; "Sam" = clients + quotes; and so on.
- State is stored per business as an ordered list of unlocked heads (`app_unlocked_heads`), not three fixed stages: the earlier `app_stage` idea becomes "which heads are on"; `snap_only` = only Penny, and only her snap button inside Penny.
- Unlocking is Tim's switch at first (Claudia keeps pace with what she has tried); later it can offer itself ("You've filed 10 receipts. Ready to meet Otto?"), but never turns itself on without her tap.
- The intro text names each head through `HeadIdentity` (the chief of staff is shown as Kaname, key `charlie`), is tenant-neutral (no Mowology words), and is written once for all businesses, so every new tenant gets the same introduction.
- Intro seen/not seen is saved per user, so it shows once and can be reopened from the head's card.

## How: a "snap-only" fence, copying what already works
`AccountantFence` already gives the outside accountant a login that sees only the Ledger: a role, a deny-by-default allowlist of pages checked on every request
(session_config.php + auth.php), a landing redirect, JWT refused. Same pattern, for a **tenant stage** instead of a role:
- Setting `app_unlocked_heads` per business (an ordered list; default = all heads, so Mowology and testcompany are untouched; Claudia starts with `penny`, and Penny's own unlock starts at the snap button only).
- `SnapFence` (new, pure rules + tests like `AccountantFenceTest`): at `snap_only` only the AI Office page(s) with Penny, the snap/receipt upload/read/confirm endpoints, CSRF refresh, logout and the sign-in pages are reachable; anything else under `/crm`, `/api` etc. redirects to the snap screen (pages) or 403 (APIs).
- The sidebar (`appstack_sidebar.php` nav items) reads the stage: items not on at her stage render as disabled (grey, not a link, labelled "Not turned on yet"); the fence still refuses their URLs, so greying is not the only protection.
- The snap button lives on the AI Office home for her (reuse the existing receipt capture + Penny read UI and endpoints; no second capture system; check `COMPONENTS.md` first).
- Switching stages is Tim's action (a setting on her business), logged.

## Hard rule (Tim, 2026-10-24): "You are not to change the way the app functions"
This is layering, not rewriting. For every existing business and every existing page the app behaves exactly as today.
- **Off unless set.** With no `app_unlocked_heads` setting (Mowology, testcompany, every business today) the fence, the greyed menu and the intro cards do nothing. They only exist for a business that has the setting.
- **No existing page, endpoint, table column or flow is edited in how it works.** The snap screen CALLS the existing receipt capture/read/confirm endpoints as they are. Mowology's capture-only mobile rule is untouched; Claudia's phone confirmation is a separate path or a tenant setting that defaults to today's behaviour.
- **The only shared files touched are tiny hooks** (the fence check, the sidebar rendering of a disabled item) written as no-ops when the setting is absent, and deployed with the 3-way merge against live (live `session_config.php`, `appstack_sidebar.php`, brand.css all differ from the repo).
- **Proof it did not change anything:** (1) a test that, with the setting absent, every path in the app is allowed and the sidebar output is byte-identical to before; (2) the full phpunit suite green; (3) local site as Mowology admin, crew and accountant, before/after screenshots of the menu and AI Office; (4) after deploy, the same pages on production render to `</html>` as before.
- **Reversible:** deleting the setting returns a business to the normal app; deleting the hook files returns the code to today.

## Unknowns, and how each is settled (one source each)
1. **Which endpoints the snap flow needs**: read the web receipt capture page and its API calls; the allowlist is exactly those (nothing more). Verify by a test that every other CRM path is refused.
2. **Phone approve, not capture-only**: Mowology's mobile receipts are capture-only. For her the same screen must confirm/fix on the phone. Becomes a tenant setting (`receipts_mobile_approve`), Mowology's behaviour unchanged. Read `ExpenseApprovalService` and how capture-only is enforced.
3. **Province / GST** (Penny's rules are BC-tuned): ask Claudia; check what is region-tagged (`regions` skill). Outside BC: tax shown as "needs your accountant", never guessed.
4. **Where emailed receipts go**: a per-business address is optional; start with snap only (no inbox) so the first week needs no mail setup.
5. **Penny's overnight work** (reads, ledger sync) needs the **tenant cron runner** (not built); snap-time reads happen on request so they work without it, but filing and sync wait for it.
6. **AI cost**: whose key pays for her reads and a daily cap (Tim).
7. **The migration page error** seen today (uncaught exception after "Run it") must be understood before provisioning her business (needs the error_log lines).
8. **Her chart of accounts** (testcompany's 66 accounts) reviewed for a gardener before real use.
9. **Sharing**: her receipts feed the shared vendor knowledge only after founding terms are agreed; until then her contribution switch is OFF (per the shared-knowledge plan).

## Stages (each proved before the next)
0. Pre-work: error_log lines for the migration page; tenant cron runner; fix what blocks provisioning. Prove on testcompany.
1. `SnapFence` (renamed HeadFence if it covers every head) + the `app_unlocked_heads` setting, tested on testcompany with fake receipts: the fence blocks every other path; Mowology unaffected (tests + local site as both).
2. The snap screen at 375 / 768 / 1024 / 1440, Android Chrome installed to the home screen; Codex review (money + tenancy + auth).
3. Provision Claudia (dry-run first, `TenantProvisioner`), `app_unlocked_heads = [penny]` (snap button only); her first five real receipts with Tim watching.
4. Tim widens her stage as she is ready (list of receipts, bank, then clients and jobs), each a deliberate switch.

## Safeguards
- Cross-tenant test: her receipts/vendors/amounts never appear in Mowology's data and the reverse.
- Penny proposes; nothing is filed until Claudia confirms; no tax advice.
- Rollback: set her stage back, or remove her registry line (host returns 404).

## Decisions needed from Tim
- Approve the snap-only stage approach.
- Claudia's province, GST status, who does her taxes.
- Whose Claude key and the daily cap for her reads.
- The order you'd switch features on after receipts (suggested: receipt list → bank → clients/quotes → jobs/invoices).
