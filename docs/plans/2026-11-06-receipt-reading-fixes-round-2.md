# Receipt reading, round 2 — three fixes found by today's testing (2026-11-06)

Each fix is measured before it ships: the header fields with `scripts/receipt-replay.php` (every approved receipt's
stored text, scored against what Tim approved, `--baseline` lists what got better and what got worse), the line items
with a read-only production snippet (how many of the 433 stored 2025–26 receipts have items that add up; today 215).
A fix ships only if it makes nothing worse. All in `app/Services/Receipts/` (the receipt-layout chat's work is merged).

## 1. No invented GST on receipts that print no tax at all
**Now:** when a receipt prints a total but no GST line, `parseReceiptText()` (and `sanitizeReceiptAmounts()`) assume 5%
is inside the total and split it out. Tim, 2026-11-06: City of Vancouver landfill has no GST — #533 read $39.05 + $1.95
for a $41.00 ticket; the books carry these whole (#516 $38.00).
**Change:** keep the 5% guess only when the receipt mentions tax somewhere (GST / HST / TAX / a GST number) or the
vendor's approved receipts carried GST; a receipt that prints no tax word at all reads GST 0.00, subtotal = total.
**Unknown:** how many approved receipts with no tax word really had GST (gas stations print "GST incl."?). Settled by the
replay's GST field before/after; if any receipt gets worse, narrow the rule to the vendor-history half only.

## 2. The AI line-item step checks its sum against the receipt, not only the rules' subtotal
**Now:** `validateLlmLineItems()` rejects the AI's items unless they add up to `parseReceiptText()`'s subtotal. In
today's 21-receipt backtest that subtotal was misread on 6 of 12 rejections where the AI's items were right (Shell
36.99, Vital Auto 1,528.30, London Drugs 581.48…); Chevron fails by exactly its bottle deposits.
**Change:** also accept when the items add up to an amount printed under a subtotal label in the OCR text
(`receiptHeaderAmountLabelled()` already does this for headers), or to the printed total minus the printed taxes; for
deposits, count printed "Deposit" amounts toward the sum. The name check and every other guard stay.
**Proof:** re-run today's backtest snippet on the same 21 receipts (~$0.30): accepted count and that every newly
accepted receipt's items equal Tim's approved amount.

## 3. Google Vision rows the item reader can actually read
**Now:** `reconstructLinesFromVisionResponse()` joins a row's words with ONE space; `extractLineItems()` only reads
"NAME  12.34" (two+ spaces) as an item, so position rows add little. The phone-box builder (today) joins with two.
**Change:** join a Vision row's words with one space inside a word group and two spaces where the horizontal gap is
wider than ~2 character widths (the column gap), so "COFFEE LG      2.61" reads as an item.
**Proof:** items-add-up count over the stored receipts before/after; header replay unchanged (rows feed items only).

## Ship
Codex review (or the code-reviewer agent while Codex is out), deploy through the feature/language gate, verify on
production; iPhone 1.3.86 (camera bead tap fix + 1920 px photos) goes to TestFlight in the same push.

## Results (2026-11-06)
- **Fix 1 SHIPS.** Header replay on 285 approved receipts: GST 61.1% → 70.2%, subtotal 44.5% → 51.3%, total/PST/date
  unchanged; 48 values fixed. The 6 "worse" are City of Vancouver landfill receipts whose approved entry is itself off:
  #63 claims $2.38 GST on a $50 ticket; #142, #168, #276, #305 record amount = total ÷ 1.05 with no GST (cost understated).
  Those are books corrections for Tim, not reader regressions.
- **Fix 3 DROPPED.** Items-add-up over the corpus went 119 → 117 (2 fixed, 4 broken). The two-space join exposes two older
  faults in the Vision row builder: quantity lines ("3 EA @ 21.99 EA 65.97") become items and double-count (#60 COE), and
  rows from a tilted photo pair across lines (#163 Chevron: a deposit's 0.10 beside DIET COKE). A real fix needs tilt
  correction (as `reconstructLinesFromDeviceBoxes()` does) and quantity-line handling — a plan of its own.
