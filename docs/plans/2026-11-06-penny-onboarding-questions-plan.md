# Penny's onboarding asks the book questions, one by one — plan (2026-11-06)

Tim: "these questions could be asked during the onboarding, one by one … T&C should be right near the beginning."
Penny-led (shadow first: Ittai keeps the books alongside QuickBooks until they agree).

## New order
1. About you (exists)
2. **Terms** — new: links to `/terms.php` and `/ittai-privacy.php`, one Accept button, records the version
3. **GST** — new: "Are you registered for GST?" → yes: number + how often you file · no · not sure
4. **Year-end** — new: "Who does your year-end?" → an accountant (→ the existing invite step) · I do it myself
5. **Payroll** — new: "How do you run payroll today?" → no staff on payroll · another app (name) · my accountant
6. Company, QuickBooks / books, accountant invite, done (exist)

## Unknowns, and how each is settled
| # | Unknown | Settled by | Answer so far |
|---|---|---|---|
| 1 | Does "not registered" stop GST everywhere? | read `TaxEngine::gstRate()` | **No.** A rate of 0 counts as "not set" and falls back to 5%. Needs an explicit `gst_registered` flag that `gstRate()` honours (0 when not registered). 9 callers — graphify each. |
| 2 | What else changes for a non-registrant | penny-bc-bookkeeping skill + `ExpenseGate::gstRate()` | A non-registrant claims no input tax credits: receipt GST becomes part of the cost, nothing to 2210. ExpenseGate has its own rate — must use the same flag. Confirm the rule in the `regions` skill. |
| 3 | Does activating the terms lock anyone out? | read `TermsService`, `login.php` | Gate is per business database (the row lives in that business's DB), web sign-in only. Accepting in onboarding records it, so the owner is never blocked. Staff she adds will be asked on their first web sign-in — intended. |
| 4 | What does an old phone app do with a step it doesn't know? | read Android `OnboardingStepScreens.kt` | **Shows the Done screen** (`else -> DoneStep`). iPhone to check. So the server sends the new steps only to apps that say they know them (app version), until both apps ship. |
| 5 | Which terms version is activated, and where | `terms_versions` (migration 1381) | One row per business DB, `doc_key = 'platform'`, version `2026-11-06`; activated by the onboarding step on first accept for that business, never for Mowology without Tim. |

## Steps (each proven before the next)
1. **Server, behind tests** — four modules in `OnboardingModules.php` + `OnboardingStepService` actions; answers saved with `saveSetting`; GST flag through `TaxEngine::gstRate()` and `ExpenseGate`. Unit tests for each answer, including "not registered → no GST on a contract invoice and full cost on a receipt".
2. **Codex build + independent Codex review** (money + sign-in) — `scripts/codex-web-build.sh`, then `scripts/codex-review.sh`.
3. **Web** — the steps show on the web onboarding page; render locally.
4. **Deploy server** (Tim's go) with old apps shielded (unknown 4); check on rehearsal2.
5. **Android** via `scripts/codex-build.sh`, **iPhone** by hand or Codex — one screen each, same wording; then the version shield comes off.
6. Claudia's walk on rehearsal2: answer "not registered" and confirm a contract draft and a receipt carry no GST.

Not in scope: running payroll, filing GST.

## Progress
- **2026-11-06 server part BUILT, not deployed.** Codex at its limit → built by web-builder, reviewed by code-reviewer (no defects
  for registered businesses; 9 non-registrant gaps found and fixed: invoice edit re-charging 5%, work-order invoice, quote upsell,
  quote totals + JS preview, product line rates, the two calculateQuoteTotals defaults, GST report ITCs, "GST (5%)" on 0% invoices,
  terms row re-activation). `TaxEngine::gstRegistered()` / `chargedRate()` are the one switch. 4169 tests OK.
- One registered-business change, on purpose: editing a 0% invoice no longer silently re-charges 5%.
- **Left (literal 5% that doesn't charge a non-registrant today):** MulchPricingService "GST added on top" wording, QboSyncService
  GST_RATE claim check, product-manager default gst_rate, receipt parser/anomaly reading, Jobber history.
- **2026-11-06 DEPLOYED** (31 files; 8 built on the live copy because production was ahead — G10–G11 lines stay out until their own deploy). Walked on rehearsal2 as a rolled-back preview: web path name→terms→gst→yearend→payroll, old-app path unchanged; bad GST number refused; "not registered" → GST rate 0 and a $100 quote line totals $100; Mowology still 5%. Every deployed page answers 302/401/405, no 500. Not yet seen: the four web screens signed in (Tim), and a real accept on rehearsal2 (makes its terms row current).
- **Before deploy (done except the signed-in look):** render the four web screens; accept terms on rehearsal2 (terms_versions + acceptance rows); answer "not
  registered" there and check a contract draft, a quote and a receipt carry no GST; Mowology records nothing.
- **Next:** Android + iPhone screens (send `knows=terms,gst,yearend,payroll`).

- **2026-11-06 order + QuickBooks pre-fill DEPLOYED.** GST/year-end/payroll follow QuickBooks; when QuickBooks was read Penny says what it shows (GST number, fiscal year, QuickBooks Payroll on/off) and asks only what's missing. Checked on rehearsal2 (rolled back): facts read, confirm saves the QuickBooks number, old apps unchanged. rehearsal2 keeps its own saved step order.
