# Plan — a Stripe holding account so chequing matches the bank line for line

Status: **asked for by Tim 2026-10-13 ("sounds good"); waiting for his yes on this plan before any build.** Owner: Penny.

## Why
On 2026-10-13 chequing (1010) showed books $162.88 above the bank. The real position was hidden by card timing:
- each card payment posts to 1010 on the day the client pays (`stripe_payment` entries);
- the bank gets one lump payout two to five days later, minus Stripe's fee;
- on Oct 13, $1,291.09 of payments from Oct 8–11 were still with Stripe (payouts $165.14 and $999.88, plus $88.20 pending);
- so the books and the statement never agree day by day, and real errors hide inside the difference.
  Allowing for the timing, books were about $1,128 **below** the bank, and nothing named the cause.

## What changes
1. **New account 1015 "Stripe clearing"** (asset; a child of cash on the balance sheet).
2. **Card payments post Dr 1015 / Cr A/R**, not Dr 1010. Same date, same amount.
3. **A payout posts Dr 1010 (net) + Dr 6850 fees / Cr 1015 (gross)** on the bank-line date. `StripePayoutService::apply` already reads every charge and fee in a payout; it would post this one entry instead of only the fee line.
4. **1015's balance always equals Stripe's balance** (pending + in transit). Penny shows both side by side; any difference is a refund, dispute or a payment Stripe doesn't know about.
5. **Chequing then compares line for line**: every 1010 line is a bank line. Whatever is left in the balance check is a real error with a date.

## Unknowns and how each is settled (cheapest first)
| Unknown | How it's settled |
|---|---|
| Which code paths post card payments to 1010 | `graphify touches` on `stripe_payment` and `InvoicePaymentPlanner::entryFor`, then read each one. |
| Payouts already booked (2025–2026) | Repost them through the reversal-and-repost path, entry by entry. FY2025 is locked, so start at the FY2026 opening; 2025 stays as filed. |
| Single-charge payouts matched at import | Same entry shape, so `BankImportService`'s Stripe match uses the same posting helper. |
| Effect on the accountant's figures | No change to revenue or fees in total; only timing between two cash accounts. Tell Ricole in the held-questions email (only with Tim's yes). |
| Refunds and disputes | Payouts containing them are already refused by `StripePayoutService::check`; they stay manual. |

## Steps, each proven before the next
1. Read-only replay: rebuild 2026 1010 and 1015 on paper from the journal and Stripe. Proof: 1015 at Oct 13 = $1,291.09 − fees on those payments, and 1010 matches the statement within the remaining real errors, listed by name.
2. Code: posting helper and tests (payment → 1015; payout → 1010 + 6850 / 1015). Local site first.
3. Repost 2026 card payments and payouts, with a preview first and then a commit.
4. Balance check shows a 1015 vs Stripe row. Penny skill and traps updated.

## Found on the way (already done 2026-10-13)
- $29.68 Stripe fees on payouts of Oct 7, 9 and 13 booked.
- `StripePayoutService` built `LedgerSyncService` with one argument, so every fee's journal post failed quietly at booking (left to the nightly sync). Fixed and deployed.
- Alex Shi Ke Wang: the Sep 2 deposit was wrongly marked "already recorded = INV-0303"; it is now linked to INV-0361, re-dated Sep 2.
