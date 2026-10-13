# Plan — Penny proves her own books, and every head learns (Charlie oversees)

Status: **plan only, waiting for Tim's approval** (2026-10-13). Nothing in here is built yet.

## Why
On 2026-10-12 the books were **$9,404 away from Vancity** and every cause was found by hand in a
long session, not by Penny. The causes, in order of size:

1. Bank lines imported twice (Wave payroll, Visa bill, Stripe fee): $4,472.51.
2. Income counted twice: $7,006.14.
3. Costs counted twice in reports: $3,565.96.
4. Receipt #384 (Vital Auto) booked to the credit card although it was paid from chequing.
5. BGC's payment marked paid in Jobber a week late, as the wrong method.
6. Colyvan paid January twice, then a cheque bounced.
7. Same-day deposit-and-reversal pairs.
8. Stale QuickBooks receipts at year-end.

Penny must find these herself. Each head must also get measurably better over time, and Charlie must
be able to tell whether they are.

## Part A — one learning system for every head (built once, Penny first)

**A1. Learning record.** Table `head_lessons`:
- head
- subject (vendor, payer, bank wording, etc.)
- what the head said, and what was right
- who corrected it, why, and when
- `site_id` (tenant-private)

Every place a person overrides a head's answer writes one row. Penny's places: category, payer,
payment method, match, split, and "already recorded". Existing learners (bank rules, vendor
line-item memory, payer aliases) read from it instead of each keeping their own notes.

**A2. Rules from repeated lessons, with guardrails.**
- The same correction 3+ times with no contradiction becomes a candidate rule.
- A rule never learns from the owner's own name or from generic bank words; this follows the
  2026-10-12 lesson.
- A one-off correction stays a one-off.

**A3. Trial (shadow) mode for every new rule.**
- A candidate rule runs silently next to the human decision and is scored.
- It switches on only after it agrees with the human on enough cases: N ≥ 10 at ≥ 95%.
- A switched-on rule that is overridden twice in a row goes back to trial.

**A4. Mistakes become permanent tests.**
- Every real mistake becomes a PHPUnit case using fake data shaped like the real one. The first
  batch is the 2026-10-12 list above.
- A change that would bring a mistake back fails before deploy.

**A5. Scorecard, one format for every head.** Table `head_scores`: head, metric, period, value,
target.
- Penny writes hers nightly and monthly.
- Other heads join when they are upgraded.
- Penny's metrics:
  - % of deposits matched without help
  - % of receipts not edited by a person
  - bank gap at month end, and its causes
  - days to close a month
  - questions asked of Tim
  - "sure" answers later corrected
  - duplicates caught before posting

**A6. Shared across Ittai tenants.**
- Shared: receipt layouts, product names and bank wording patterns.
- Private to each business: amounts, payers and rules learned from its owner. This follows the
  standing shared-vs-private rule.

## Part B — Penny's upgrade (in priority order)

**B1. Monthly bank tie-out.**
- Chequing and the Visa are tied to the bank's statement every month, using the 2026-10-12 method:
  - Books: SUM on 1010 to the statement's last date.
  - Bank: the CSV running balance on that date.
  - Duplicate statement lines are found by date and amount, keeping the most copies any one
    file holds. The opening difference is shown separately.
- She shows the gap and **names its causes**: duplicate imports, entries posted with no bank line,
  receipts whose method doesn't match the bank, same-day reversal pairs, statement months missing.

**B2. Deposit detective.** For unmatched deposits she tries, in order:
1. Remittance and e-Transfer emails (DKIM-verified only).
2. Payer aliases.
3. Jobber "marked paid later" catch-up, with no 3-day window.
4. Combinations of cheques that add up to one deposit.
5. Duplicate payments.

Only leftovers reach Tim, on one tick-list page like `renders/payer-questions-2026-10-12.html`.

**B3. Anomaly catcher, as things happen:**
- The same receipt saved twice or more.
- A second payment on an invoice already paid.
- A bank chargeback, which she pairs with the payment it reverses.
- A receipt whose payment method doesn't match the bank line. Already built on 2026-10-12 as
  `ReceiptFundingCheck`.
- An expense counted twice in reports. Fixed 2026-10-12 with `countedSql`; it gets a test.

**B4. Month close.**
- When a month ties to the cent and has no open questions, she proposes closing it.
- Tim taps Close. The month locks, and that month's receipts are zipped and stored for the
  accountant. Receipts are never emailed (Tim's rule).

**B5. Accountant alignment.**
- She holds the opening balances agreed with Sit Lim, once Ricole replies.
- At year-end she compares her trial balance with the accountant's QuickBooks figures, and every
  difference becomes a lesson (A1).

## Part C — Charlie oversees the heads

- **Weekly report card in Tim's Monday brief.** Charlie reads `head_scores` for every head and
  compares them with past weeks. In plain words he says who improved, who is stuck and who got worse.
- **When a head stalls** (a metric flat for 4 weeks, or worse for 2), Charlie looks at that head's
  recent lessons and failed tests and proposes one specific fix. Tim approves it before anything
  changes; Charlie never edits another head.
- **Charlie does not grade himself.** His own score (brief items Tim used vs ignored) goes to Tim
  and to the quarterly review.
- **Ada watches the machinery:**
  - the learning and scoring jobs ran;
  - the tests ran on each deploy;
  - no head has stopped reporting, which would otherwise look like a healthy head.
- **Quarterly Fable review** of every head, plus after any big change. Year-end accountant
  corrections count as ground truth.

## Unknowns, and how each is settled (cheapest first)

| Unknown | How it's settled |
|---|---|
| Every place a person overrides Penny today, so all of them write lessons | grep the code for the override paths (ExpenseGate, bank-desk actions, PennyQuestionService answers, reconciliation attach/detach). One reading pass. |
| Whether existing learners can share one record without losing what they've learned | Read BankRuleLearning, bank_memo_payers and vendor line-item memory. Migrate with a preview on production via prod-run, rolled back. |
| Shadow-mode thresholds (N ≥ 10, ≥ 95%) | Replay the last 6 months of Tim's real corrections on the local copy and read the hit rate. If too strict or loose, adjust before switching on. |
| Whether Vancity statements reliably reach Penny monthly | Today they are CSVs Tim uploads. Check the import history for gaps. If uploads are irregular, the tie-out shows "statement missing" rather than a false gap. |
| How the Visa is matched (statement CSV vs PDF) | Check the existing credit-card import path (`project_credit_card_statement_import`). One reading. |
| Charlie's brief has room for a report card without becoming noise | Mock it on the local site with the 2026-10-12 numbers and show Tim one example before building. |

## Steps, and how each is proven before the next

1. **A1 + A4 first:** the learning record, plus the 2026-10-12 mistakes as tests. Proof: tests fail
   on the old code paths and pass on the current ones, and phpunit is green.
2. **A5 scorecard for Penny.** Proof: the numbers on the local copy match a hand count on the same
   data, and production numbers come from a read-only preview.
3. **B1 tie-out.** Proof: it reproduces the 2026-10-12 hand result (gap and causes) from the same
   data.
4. **B3, then B2.** Proof: replaying January–October 2026 finds the cases found by hand on
   2026-10-12, and the tick list has fewer items than before.
5. **A2 + A3 rules in shadow.** Proof: the shadow hit rate on replayed corrections is shown on
   Penny's card. Nothing switches on until it meets the bar.
6. **B4 month close.** Proof: closing a month locks it, the zip exists, and reopening needs Tim.
7. **Part C, Charlie's report card.** Proof: a sample brief on the local copy is approved by Tim
   before going live.
8. **Ada machinery checks.** Proof: stopping the scoring job on the local copy turns Ada amber.

Every step: tested on the local site first, deployed with the 3-way merge, and checked on
production. Penny's skill (traps, ledger, operating rules) is updated as each step lands.

## Out of scope for this plan
- Upgrading the other heads' own learning. They join A5/C one at a time later, after Penny.
- Automatic changes to the accountant's QuickBooks (read-only comparison only).
