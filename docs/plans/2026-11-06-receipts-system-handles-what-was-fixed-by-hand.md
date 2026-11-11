# Receipts: the system does what was fixed by hand today (2026-11-06)

Tim: "you shouldn't be doing these by hand, you should be running them through the CRM system to improve it."
Today six photographed receipts (#533–#538) were corrected, filed and traced to bank lines by hand. Each fix below makes
the CRM do that by itself. **The proof set is those six:** after the change, re-running each through the system's own
path (`ExpenseRescanService::rescan()` + the new steps, preview only) must give what was set by hand.

| # | Printed | What the system did | Hand fix |
|---|---|---|---|
| 533 | Landfill $41.00, no tax | $39.05 + 1.95 GST (5% guess) | fixed since in the reader (no-tax rule) |
| 534 | Chevron $16.67 | right | filed + traced to bank line 6101 |
| 536 | Kerrisdale $59.90 | right | filed + traced 5360; catalogue |
| 537 | Northwest: G.S.T. 4.53, P.S.T. 6.34 | 5% guess, column headings as items | dotted-label fix live; items by hand |
| 538 | Lordco: Goods 27.98, GST 1.40, PST 1.96 | vendor "Costco", 5% guess, "GST Total" items | by hand |

## 1. A 2025 receipt files itself and traces to its 2025 bank line (Tim's rule)
**Now:** a receipt dated before `ExpenseGate::CLOSED_BEFORE` sits waiting; filing (`ExpenseApprovalService::fileAsBooked()`)
and linking (`BankImportService::attachExpenseMatch()`) are manual.
**Change:** after a receipt is saved (intake, rescan, inbox), if it is dated in a closed year: look for unmatched 2025
bank lines with the same amount (±$0.02) within ±4 days. Exactly one, and the line's description shares a word with the
vendor → file + link it (actor "penny", reason "Filed: 2025 receipt traced to its bank line"), and the phone says so.
None or several → it stays waiting and Penny asks one question (which line / how was it paid). Nothing is ever posted.
**Proof:** #534, #536 (and #533, #537, #538 once their totals read right) link to exactly the lines set by hand; #535
(no line) stays waiting with a question. Unit tests on the pure "which line" rule.

## 2. A receipt that prints its taxes never gets a guessed tax
**Now:** when the rules can't pair printed tax labels with amounts (Lordco's stacked "GST Total / PST Total"), they guess
5% inside the total; the sums then "add up", so the AI header step (`enhanceHeaders`, which only accepts amounts printed
under their own label) never runs.
**Change:** an estimated GST on a receipt that names GST/PST counts as "needs a better read" → the existing AI header step
runs and its printed-and-labelled check decides. With the right subtotal, the AI line-item step's items then pass.
**Proof:** #537 and #538 come out as set by hand (GST/PST and the items); the header replay over 285 approved receipts
shows nothing worse (the AI step does not run there, so the change must be inert for receipts already read right);
a backtest of the new trigger on corpus receipts with an estimated GST and a printed tax label (~$0.30).

## 3. No vendor rather than the wrong vendor
**Now:** with no Lordco vendor on file, the fuzzy matcher picked "Costco" (#538).
**Change:** a fuzzy vendor match is accepted only when the receipt's own name agrees with it beyond letter-shape
similarity (first word equal, or a known alias); otherwise a new vendor is proposed from the printed name, as intake
already does when nothing matches.
**Unknown:** how often today's fuzzy matches are right. Settled by scoring vendor matching over the 285 approved receipts
(approved vendor_id is the truth) before and after: no correct match may be lost.

## Order and ship
1 → 2 → 3, each with its proof; code-reviewer review (Codex out until Nov 11); deploy through the feature/language gate;
re-run the six; write up in docs/crm/receipts.md and Penny's skill.
