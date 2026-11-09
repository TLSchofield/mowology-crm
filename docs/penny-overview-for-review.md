# Penny: the AI bookkeeper. Overview for outside review

*Written 2026-10-21 from the code and the project's own notes. It describes what exists; anything planned or only an idea is labelled so. It contains no customer data, credentials or account numbers.*

## 1. Context

Mowology is a three-person landscaping company in British Columbia (Canadian dollars, GST 5% and BC PST 7%, December year-end). Its owner runs the business from a CRM the team built: plain PHP and MySQL on shared hosting, deployed by FTP, with web, Android (a wrapper around the web pages) and a native iPhone app. The CRM is also being turned into a multi-tenant product for other trades, so anything about Penny should work for businesses other than this one.

The CRM is organised as a small company of **department heads**, each an AI-assisted role that owns one lane and hands work to the next:

| Head | Lane |
|---|---|
| Charlie | Chief of staff: ranks everyone's items into a daily brief |
| Sam | Sales: quotes, leads, "yes" replies, pipeline |
| Mia | Marketing |
| Yui | Client conversations; drafts only |
| Otto | Operations: jobs, routes, machines |
| **Penny** | **Finance: books, receipts, bank, payments, payroll, month-end** |

Handovers are explicit (for example Penny passes an account to Yui at 60+ days overdue).

## 2. Penny's role in one paragraph

Penny is the bookkeeper. She reads receipts, proposes where each purchase belongs, matches bank lines to receipts and invoices, records and reconciles payments, imports payroll into the ledger, keeps the books provably in agreement with bank statements, and tells the owner only the decisions that need him. **She proposes and the owner decides.** She learns from the owner's confirmed corrections so that she needs fewer of them over time. She is deterministic code first; Claude is called only for the unclear cases, with daily caps.

## 3. The rules she never breaks

1. **Never state a fact about the books that was not checked in the same session.** "Unverified" is an acceptable answer.
2. **Income is counted once, on the invoice.** A bank deposit that pays an invoice is a cash-clearing transfer, never a second income line.
3. **She never moves money, pays a bill or stores banking details, and she never locks a month herself.** Locking a period has no undo, so the owner does it once the month-end proof passes. Phone receipt capture is capture-only; approval happens at a desk.
4. **Every write to the live books is previewed first** (run in one transaction, rolled back unless confirmed).
5. **She learns only from confirmed facts.** Imports, guesses and single sightings teach nothing.

Plus: *book the standard way* (textbook / CRA treatment, never a house method), and every automatic entry must say why in its memo so the accountant can trace it back to the paper. Where the standard is a judgement call she applies the default and lists the question for the accountant.

## 4. What she does

**Receipts**
- Reads photos and PDFs: a code parser first, text layer for PDFs, Tesseract, a vision model, then a Claude read for what is still unclear.
- Suggests category, job or client, GST/PST split and line items, each with a reason, side by side with what was captured.
- Checks before approval: GST above 5% of the subtotal is flagged (below is not), impossible amounts, duplicates, handwriting dates that look wrong, foreign-currency bills, personal purchases (booked to the shareholder account, no tax credit).
- "Penny's read" appears wherever a receipt is open (web, Android, iPhone): where she differs from the capture, with her reason; "Use Penny's version" or "Keep mine". "Ask Penny" runs one live read under a daily cap.
- Review is grouped by vendor. One correction triggers a re-read of that vendor's other waiting receipts. Trust is earned per vendor (for example five receipts approved unchanged in a row); any edit resets it.
- Emailed receipts arrive through an inbox reader into a pending-review list. **Added 2026-10-20:** each can be opened in the full editor and re-read; the re-read fills blank fields only, never overwrites a saved value, and refuses when the vendor it read looks like the company's own name (our own invoices forwarded in).

**Bank and cards**
- Imported bank lines that land on a default account (Miscellaneous or Other Services) come to her desk. She proposes an account in this order: the receipt it matches, a vendor named in the text, what the owner taught her for this payee, an active rule, a few plain facts (fuel brands, bank fees, card payments). She never proposes a default account.
- Approving moves the line **and its journal entry** together (append-only: reverse, then repost; all or nothing). Locked months are refused.
- Rules form only after **two owner confirmations from different lines**; imports and default accounts never teach. One approval offers "put this payee's other lines there too".
- Ties receipts to bank lines in three strengths. Her desk **proposes** a matching receipt at moderate confidence (the owner confirms). A sweep **links on its own** only when the match is strong (exact amount, within 3 days, vendor on the statement, and no other receipt close behind); it is a runner with a dry run first, not a silent background job. A nightly check re-opens an approved card or debit receipt that has no statement line, so the cost is never counted twice.
- On demand, Claude explains a line she cannot place (strict JSON, account validated against the chart, daily cap, cached per payee).

**Chasing what never arrived**
- Every card or bank spending line from 2026 with no receipt and no plausible match becomes a "missing receipt" item, **minus the kinds that never have one** (bank fees, interest, loans, payroll, transfers, pre-authorised insurance and utilities; the owner edits that list).
- She works out who probably made the charge: the card's holder, else a truck run to a matching place that day, else the one non-owner clocked in then, else the owner.
- Cadence: one push the day after the charge, a second three days later, then none; never in quiet hours (21:00 to 07:00); old charges found on the first run are listed, not pushed. The item stays on that person's card and in the owner's Monday summary.

**Checking herself**
- Once a week she re-checks a random sample of 20 past decisions the owner approved: does the receipt still say what was approved, is the bank line still on the account approved? Code only, no model cost. The error rate is kept and shown on her card so the owner knows how much to trust her.
- Every time the owner overrides her it is recorded as a lesson (once is a hint, twice in a row becomes a rule; lessons fade and can be revoked; they are private to the tenant). Earned badges (vendor trust, fuel, job finder, tax ace, a run of unchanged approvals) and a learning dashboard show what she has learned and are lost on a slip.

**Customer payments and invoices**
- Records a payment through one allocation service used by the bank desk, e-Transfer inbox, remittance reader and invoice screens.
- Links a deposit to an invoice automatically only when certain: exact amount, method fits (cheque with cheque, e-Transfer with e-Transfer), recorded within 3 days of the deposit, and no other payment of that amount nearby. When several invoices could fit she looks for the exact-sum set. Everything else goes to a review list.
- Chases a client only when sure they have not paid: no candidate payment in any channel, statements fresh enough to cover the gap, and late *for that client's own habit*. Otherwise the status is "can't tell yet".
- Every invoice email carries its PDF; an invoice whose PDF cannot be made is held, not sent.
- Asks "unbilled materials" questions: a materials receipt allocated to a job with no matching invoice line or contract coverage prompts "did you forget to bill this?".

**Payroll**
- Imports the payroll provider's monthly report into one journal entry (wages, employer CPP/EI, source deductions payable, net pay clearing). Employees' e-Transfers are net pay; remittance debits clear the source-deductions account; each shareholder's transfers are pay up to their net, the rest goes to their loan account. Proposed, then approved.

**Ledger integrity and month-end**
- Double-entry journal alongside the single-entry view; reversals only append; locked periods; every change inside a locked period is classified.
- Month-end proof per bank or card statement, statement-coverage gaps, nightly "books watch", receipt-vs-bank check, Stripe and undeposited-funds holding accounts, GST return (GST34), GIFI mapping and export, a read-only login for the accountant that can see the ledger and nothing else, cash-flow forecast.

**Reporting to the owner**
- Her items appear in the chief of staff's brief as **decisions, not status** ("Send 28 invoices?"), at most three, ordered by the cost of waiting.
- **Added 2026-10-20:** on the cost-factors page, a "Penny's check" card compares the hand-typed overhead items and revenue / jobs / hours figures with the ledger, and offers changes with the source shown. Suggest-only: Accept uses the page's normal save.

## 5. How she learns (and does not)

| Lesson | Taught by | Threshold |
|---|---|---|
| Bank-line category rules | Owner decisions only | 2 confirmations, different lines; imports and default accounts never teach |
| Bank wording to payer | Certain deposit links, owner entries | 2 agreeing, none disagreeing |
| Receipt line names, layout, noise | Owner corrections; approvals confirm names | 2 sightings |
| Vendor trust | Approvals unchanged | a run of unchanged approvals; any edit resets |
| Reader knowledge of a vendor's receipt layout | Penny's accepted items | taught on approval |

Her suggestions keep a per-field scorecard (kept / corrected) so her accuracy can be measured.

## 6. Safety rails

Approval gates for anything that moves money or sends to a client; one write gate for expenses (audited, honours locks); append-only journal; all-or-nothing row-plus-journal changes; locked periods never silently changed; previews rolled back unless confirmed; daily caps on every model call (by default about 40 receipts prepared and 30 guidance calls a day); "never claim an unchecked fact"; the accountant is the authority on tax judgement calls.

## 7. Known gaps and open problems (honest list)

- **Nothing revisits what she already filed.** New knowledge (a vendor added, a payee identified) does not reach lines she or the owner already filed; reviewed lines never return to her desk. A "revisit" provision is designed (locate affected lines, propose by payee, hand locked-year lines to the accountant), not built.
- **A large backlog sits on the Miscellaneous default account** (about 300 lines over twelve months). Much of it is payroll awaiting the monthly report import, plus personal spending and payments to people, which need the owner's or accountant's judgement.
- **Overhead and margin inputs were hand-typed** and feed quote prices; the check card is the first step, not a full ownership of those numbers.
- **Product price comparison is not working.** A cross-vendor price function exists but nothing calls it and it matches only identical product names. *Idea, not built:* normalise products and unit price (per litre, per bag), then show "cheaper elsewhere" on receipts and in the brief.
- **Production runs ahead of the repository** in places because several AI sessions deploy independently; the main hazard is overwriting each other's newer files.
- **Cost control** relies on daily caps and code-first gates; per-tenant onboarding of hundreds of receipts is an open cost question.
- **Scanned PDFs** the server cannot read fall back to a manual "Penny's read".

## 8. Questions worth thinking about

1. **Autonomy boundary.** Where should she act without a click, and what evidence earns it? Today: only on owner-authored evidence, after repeated confirmation, with undo and a log. Is "propose by payee, one decision per payee" the right default for a backlog of hundreds?
2. **Trust and measurement.** Is a per-field kept-versus-corrected scorecard enough to decide when a vendor or rule can run alone? How should drift (a vendor changing its receipt) be detected?
3. **Locked-period discoveries.** A new fact that shows a past year was mis-filed cannot be fixed by moving lines. What is the cleanest way to hand an accountant a traceable, minimal list?
4. **Own documents versus purchases.** The same mailbox carries the company's own invoices and its bills; a reader once took the company's own name as the vendor. What is a robust, general test?
5. **Price intelligence.** How should products be matched across vendors and units, and what gap is worth an alert?
6. **Multi-tenant learning.** Which of her knowledge is universal (reading a chain's receipt layout) and which is private (this business's categories)? How should it be shared?
7. **Explainability.** Each automatic entry says why in its memo. Is that enough for an accountant to trust a machine-assisted ledger, and what else would they ask for?
8. **Failure modes.** Where might a confident, wrong, silent change slip through the current rails?

## 9. Constraints that shape any proposal

Shared PHP 8.3 / MySQL 5.7-compatible hosting, FTP deploys, no queue workers (cron and requests only), model calls billed per use and capped, a single owner who reviews on a phone, an accountant who needs standard, traceable entries, and multi-tenant ambitions.
