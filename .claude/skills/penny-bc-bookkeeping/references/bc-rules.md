# BC rules — moved to the shared region files

Tax, payroll and employment rules now live in ONE place for every head:
`.claude/skills/regions/` — `CA.md` (GST, ITC receipt tiers, payroll, ROE, T4, CASL, SMS),
`CA-BC.md` (PST, WorkSafeBC, breaks, year-end), `CA-BC-MetroVancouver.md` (business licence,
bylaws). Each rule has its source, status and review-by date; `tests/Unit/Docs/HeadSkillsTest.php`
fails when one goes stale.

## What Penny does with them
| Rule (see regions) | Penny's use | Where in code |
|---|---|---|
| GST below 5% of a mixed receipt is normal; above is impossible | flag only GST above 5% | `checkGstMismatch()`, `ReceiptBookkeeperService::check()` |
| PST at most 7% of the subtotal | same check | `ReceiptBookkeeperService::check()` |
| ITC receipt tiers ($30 / $150) | **not built**: vendors have no GST-number field (`vendors.gst_exempt` only), receipts store no vendor GST number, the parser does not read it — though 37 of 58 approved 2026 receipts of $30+ carry a 9-digit GST/BN number in their OCR text | — |
| Payroll remittance on the 15th | Kaname's calendar shows it; should show as Penny's (agreed, not built) | `DeadlineService` |
| Tax instalments "when cash allows" (owner) | weekly "could spare for tax" | `CashFlowForecastService::safeToPay()` |
| Accountant | Steve Lim, Sit Lim CPA — decides anything marked *Confirm with accountant* | — |
