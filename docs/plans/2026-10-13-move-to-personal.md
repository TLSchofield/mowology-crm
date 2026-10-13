# Plan — "Personal" on a receipt (and on a bank line)

Status: **plan only, waiting for Tim's approval** (2026-10-13). First case done by hand:
HelloFresh $86.54 (#416) set aside as personal.

## Why
Owners mix cards. Three different things all get called "personal", and each needs different books:

| What happened | Books | Today |
|---|---|---|
| A. Personal purchase, personal card (HelloFresh) | Out of the books; kept on record | Reject, with reason typed by hand |
| B. Business purchase, personal card (fuel on own Visa) | Expense stays (deductible, GST ITC kept); paid by **1300 shareholder account** = company owes owner | Not possible: funding is card/bank only |
| C. Personal purchase, business card/bank | Bank line stays; booked to **1300** = owner owes company (s.15(2): repay or include within one year of year-end) | Possible only through the bank line "move" tool, not from the receipt |

Wave keeps a separate personal ledger. A second accounting program for the owner's personal side is
not worth building: the owner's own taxes are a T1 return. What's worth having is a **personal list**
(what was moved out, with a T1 tag: medical, donation, other) that exports for the accountant.

## What gets built
1. **"Personal" button** in Edit Expense (web, desktop; phones stay capture-only, rule of 2026-09-03).
   It asks one question, "Who paid?", with the answer pre-filled from the payment method and any
   matched business bank line:
   - personal card / cash from my pocket → asks "Was it for the business?"
     - **No** → case A: set aside through the gate's audited reject, reason "Personal".
     - **Yes** → case B: payment method `owner_paid`, so funding = 1300.
   - business card / bank → case C: the receipt is set aside, and the bank line moves to 1300 with
     BankLineMoveService. The shareholder entry is visible to the accountant.
2. **Bank-line "Personal"** on Penny's bank desk for case C with no receipt (same move to 1300).
3. **Personal list**: Expenses → filter "Personal", with T1 tag and CSV export. Columns: date,
   vendor, amount, who paid, case, tag.
4. **Penny learns it.** Each "Personal" click writes a head lesson (vendor → personal). Suggestions
   only show a "probably personal" chip after repeated agreement (shadow-mode rule from the Penny
   plan). The seed list of always-personal vendor types (meal kits, streaming, groceries) is
   **shared**; each owner's own vendors stay **private**.
5. **Accountant view**: shareholder-account continuity (Accountant's Ledger step 5) lists every
   B and C entry with its receipt.

## Unknowns, and how each is settled (cheapest first)
| Unknown | How it's settled |
|---|---|
| Does Sit Lim use one shareholder account (1300, either sign) or separate "due to" / "due from"? The 2025 FS shows "Due from shareholder". | One line in the next note to Ricole. Until then, 1300 for both (standard for a single owner-manager). |
| How funding is chosen per payment method | Read `LedgerSyncService::fundingAccountFor()`; add `owner_paid` → 1300. One reading. |
| Whether rejecting an already-sent receipt should be allowed for case A | Same audited `allow_locked` path the merge uses. Owner/admin only. |
| GST on case B | ITC is claimable when the business paid it through the owner (owner reimbursed or loan credited). Keep the ITC; confirm with Ricole in the same note. |
| Migration shape (MySQL 5.7) | `expenses.personal_kind` ENUM('', 'personal', 'owner_paid', 'company_paid_personal') + `personal_tag` VARCHAR(20). Tested on the local site first. |

## Steps, each proven before the next
1. Migration + `PersonalExpenseService` (cases A/B/C) with unit tests on fake data. Proof: phpunit,
   and the journal for each case balances (DR/CR checked in tests).
2. Edit Expense button + bank desk action. Proof: local site, one receipt per case, ledger checked.
3. Personal list + CSV. Proof: local export opens cleanly.
4. Penny lesson + shadow suggestion. Proof: replaying today's HelloFresh click yields a lesson row
   and no live chip until the bar is met.
5. Deploy (3-way merge), check on production with one real receipt, Penny skill updated.
