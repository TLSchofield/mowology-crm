# Plan: e-Transfer memos that name invoices without the year

Status: **plan only, written 2026-11-05. No code until Tim says go.** Owner: Penny. Area: e-Transfers → invoices.

## Why
Tim, 2026-11-05: "Penny can't see Alison Birdsall." A $232.84 Interac e-Transfer (4 Nov, 7:31 pm, auto-deposited to
the iCloud inbox) had the memo "Payment for invoices 0065, 0066, 0118 and 0440. …". The invoices page showed it amber:
"Clears Alison Birdsall's balance … The sender's name matches no client — maybe paying for someone. Check it."

What actually happened (checked 2026-11-05):
- `EtransferInboxService::extractInvoiceNumbers()` only reads numbers written with the year ("2026-0065",
  "INV-2026-0065"). Run on this memo it returns nothing; with "2026-0065, 0066, 0118 and 0440" it returns all four.
- With no invoice number and a sender whose name is not Alison's, the card fell through to
  `suggestFifoAllocationByValue()`: $232.84 is exactly Alison's four open $58.21 invoices, so the guess is right,
  but it is labelled as a no-name guess.
- Recording it through the card teaches the sender (`etransfer-confirm.php` → `learnSender()` →
  `PayerIdentityService::learn()`), so the next transfer from this person will match by name.

## The trap a parser-only fix would spring
`matchInvoice()` turns several memo numbers into ONE match: "the first one still owing", confidence high. Fixing only
the reader would make this card green with **INV-2026-0065 ($58.21) as the match** and the record form pre-filled
with that invoice for the whole $232.84, which overpays one invoice and leaves three open.
(`autoRecordFullyCertain()` would not record it by itself: it requires the amount to equal that one invoice's
balance, and that check stays.)

## What changes
1. **Read year-less numbers, only after the word "invoice(s)" / "inv(s)"** ("invoices 0065, 0066, 0118 and 0440",
   "inv #65"). A bare number with no such word is never read (phone numbers, addresses, "2 cuts").
   The reader returns them as *short* numbers; the year is resolved against the books, never assumed.
2. **Resolve a short number by lookup:** the invoice whose number ends in that number (`INV-%-0065`).
   Accepted only when exactly one invoice has it, or when several do and exactly one of them still owes.
   Otherwise it is not a match and the memo shows "names invoice 0065: more than one, pick it".
3. **"The memo names these invoices" as one rule in one place** (`EtransferInboxService`, new
   `namedInvoices()`): when the memo names several invoices and their open balances add up to the transfer amount
   to the cent, the suggestion is a spread across exactly those invoices, shown green:
   "Pays the 4 invoices named in the memo (Alison Birdsall)". If they don't add up, it shows what they come to
   and what's left over, amber, and the person decides.
4. **Both screens use it:** the invoices page card (`public/crm/invoices/index.php`) and Penny's desk
   (`EtransferDeskService`) each build the same suggestion separately today; both call the new function first.
5. **The sender line says what's true:** when the memo's invoices decide it, the "matches no client" line is
   replaced by "Sent by <name>, not Alison Birdsall. Recording this teaches Penny they pay for her."
6. **Penny still proposes; a person records** (Tim's rule, 2026-11-05: confirmation teaches her). The auto-record
   bar is not lowered: several named invoices are never auto-recorded in this build.
7. **Re-read the waiting ones:** `rematchPending()` uses the new reader so Alison's transfer (and any others like it)
   turns green without a new email. It still never raises an unproven notice to high (security review #5).

## Unknowns and how each is settled (cheapest first)
| Unknown | How |
|---|---|
| Can the same short number belong to two invoices (INV-2025-0065 and INV-2026-0065, Jobber-era imports)? | Read-only `scripts/prod-run.sh`: count invoice numbers by their last part; list any repeats and whether more than one still owes. |
| How do customers actually write it? | Same prod-run over every `etransfer_notifications.memo`: how many name invoices without the year, and in what forms ("inv 65", "#0065", "invoice no. 65"). These become the test cases. |
| Would the new reader misread anything already recorded? | Same prod-run: run old and new reader over every recorded memo; list every memo where the result changes and check each by eye. |
| Does Alison's transfer come out right? | Unit test with this memo and amount, plus a prod-run preview of `rematchPending()` (rolled back) showing the four invoices, green. |
| Are both screens showing the same thing? | Local render of the invoices page card and Penny's desk with a fixture transfer. |

## Steps, each proven before the next
1. **Read-only checks** above. Stop if short numbers repeat across years more than rarely.
2. **Reader + lookup + `namedInvoices()`** with unit tests: this memo; year-less with one, several and no matches;
   numbers that don't add up; a memo naming an already-paid invoice; no "invoice" word → nothing read.
   Registered in `tests/bootstrap.php`. Proof: `vendor/bin/phpunit` green.
3. **Both screens and the wording** (invoices page card, `EtransferDeskService`). Proof: local render of both.
4. **Codex review**, then deploy with `scripts/deploy-safe.sh` (prod-pull first) and an OPcache reset.
   Proof on production: Alison's transfer shows green with the four invoices, nothing recorded until a tap.
5. **Write-back:** Penny skill (`one-place.md` row for "memo names invoices", `traps.md` entry for the
   first-match overpay trap), Obsidian Known-Failure-Patterns.

## Not doing
- No auto-recording of multi-invoice transfers. No change to bank-deposit matching.
