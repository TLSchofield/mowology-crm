# The AI line-item step sees the receipt photo (2026-11-06)

**Goal:** when the rules can't read a receipt's line items (they don't add up), the AI step gets the cropped photo as well as
the OCR text, so receipts whose *text order* is the problem (Chevron/Shell split columns, invoice tables, scanned PDFs)
get their items. The acceptance checks stay exactly as they are: items must add up to the printed subtotal, and every item
name must be printed in the OCR text. Anything that fails is thrown away as today.

## What is true today (checked on production, 2026-11-06)
- `extractLineItemsWithLlm()` (app/Services/Receipts/ReceiptLlmExtractor.php) sends OCR text only. One caller:
  `enhanceLineItemExtraction()`, used by phone upload (ReceiptIntakeService), web rescan (receipt-intake.php) and
  ExpenseRescanService. All three already pass `file_path`.
- 23 calls in the last 30 days, ~900 input tokens each; 9 receipts in 60 days took their items from it.
  Daily cap 60 (`LlmDailyCap`), kill switch `ops_settings.receipt_llm_extraction = '0'`.
- Penny's prepare (ReceiptBookkeeperService, cron, 59 calls/30 days) already sends the photo (PDFs as a document block)
  and proposes items later; this change makes the *first* read, the one the crew and the review screen see, as good.
- Production has GD and Imagick; `ImageForLlm` (app/Services/Media) already shrinks big photos to 1568 px for Claude.

## Unknowns and how each is settled
1. **Does the photo actually raise acceptance, or does the "name printed in the OCR text" check now reject more?**
   The model may read a name better than the OCR printed it. Settle by a backtest before the switch goes on (step 4).
2. **File formats on the three paths** — JPEG from phones, PDF from rescans of emailed bills, possibly HEIC/PNG.
   Settled by rule: JPEG/PNG/WebP → image block (shrunk by `ImageForLlm` rules when over 3.5 MB); PDF → document block
   when under 3.5 MB (as Penny's loader does); anything else → text only, exactly as today.
3. **Latency on phone upload** (iPhone waits 45 s). The AI step already runs inside the upload; a photo adds input tokens.
   Settle from `llm_calls.duration_ms` in the backtest; if the median rises by more than ~5 s, use the photo on
   rescans and the queue only, not the live upload.
4. **Cost** — about +1,500 input tokens per call (a 1568 px image), ~23 calls a month: small, and the daily cap stays.
5. **Prompt injection through the photo** — the system line that marks `ocr_text` as untrusted data is extended to the image.

## Steps
1. `ReceiptLlmExtractor.php`: `extractLineItemsWithLlm()` gains an optional `?string $filePath`. A small loader
   (reuse `ImageForLlm` for images; PDF as a document block) adds the photo before the JSON text in the user turn. Prompt:
   "the image is the receipt itself — use it to see which price belongs to which item; names must still be as printed".
   New switch `ops_settings.receipt_llm_image` ('1' on, '0' off), **default off until step 4 passes**.
2. `ReceiptLineItemIntelligence.php`: pass `$ctx['file_path']` through. Nothing else changes; `validateLlmLineItems()`
   is untouched.
3. Unit tests: request built with an image block / document block / no block (bad type, missing file, switch off);
   oversize photo is shrunk; acceptance checks unchanged (existing tests).
4. **Backtest on production, read-only** (`scripts/prod-run.sh`, rolled back): the ~30 recent receipts where the rules'
   items don't add up (`line_items_source` null or failing), each run text-only and with the photo. Report accepted / rejected
   (sum_mismatch, name_absent) / latency / cost for both. Switch on only if the photo version accepts more and
   rejects no receipt the text version accepted.
5. Codex review, deploy (feature/language gate), switch on, verify on one real upload, write up in docs/crm/receipts.md
   and the Penny skill.

## Not in this change
Reading header fields from the photo (`enhanceHeaders`), column-aware Vision rows (receipt-layout chat), the API bake-off.

## Backtest result (2026-11-06) — FAILED the bar; switch stays off
21 stored receipts whose rule-read items don't add up (2025 + 2026: Chevron, Shell, Esso, City of Vancouver landfill,
COE, Eagle Disposal, Kerrisdale, Southlands, Home Depot, Hunters, Vital Auto, London Drugs, University Landscapers PDF,
Anthropic PDF, Amazon PDF, cab, Choices), each sent text-only and with the photo, read-only on production.
- Same verdict on every receipt: 9 accepted both ways, 12 rejected both ways for the same reason. Items identical
  apart from name spelling. Photo calls: 2–5× the input tokens (≈2,400–5,800 vs ≈350–2,300), similar time (2–7 s).
- So the photo adds cost and no reads. The code stays deployed (dormant) behind `receipt_llm_image` = off.

**What actually blocks the AI step:** the reference subtotal, not the reading. On 6 of the 12 rejections the AI's items
sum exactly to the amount the owner approved, but the gate compares them with the rules' subtotal, which was misread:
Shell #518 (rules 35.23, AI and owner 36.99), Southlands #481 (99.92 vs 104.92), Vital Auto #384 (1.52 vs 1,528.30),
London Drugs #382 (610.55 vs 581.48), Amazon #428 (17.06 vs 15.99), Choices #483 (10.49 vs 9.99). Chevron #528/#524/#513
fail by exactly the bottle deposits (the prompt excludes deposits; the printed subtotal includes them). The rest are
real misses (City of Vancouver weight tickets, a cab receipt with no items, an Anthropic invoice).
Next step to decide: let the gate accept items that add up to a subtotal *printed under its own label* in the OCR text
(`receiptHeaderAmountLabelled()` already exists for headers), and count Chevron deposits as lines — a plan of its own.
