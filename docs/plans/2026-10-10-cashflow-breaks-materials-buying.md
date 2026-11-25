# Plan — cash-flow forecast, breaks, waste & minimums, buying prices (2026-10-10)

Owner asked for all four, "write plan and build". Order: most value from data we already hold first.

## 1. Cash-flow forecast (Penny) — BUILD FIRST
**Unknowns → how settled**
- Starting balance: last statement balance per bank account (BankBalanceCheckService::statements, accounts 1010/1020/1025). Shown "as of <last import>" (bank data runs to 2026-10-06). No guessing past the last statement.
- When clients really pay: median days between due date and payment for each client's paid invoices (12 months), else the company-wide median, clamped 0–90. Pure + tested.
- Money in: open invoices (balance_due > 0, sent/viewed/overdue) at due date + that client's lateness; >120 days past due = "doubtful" (listed, not counted). Scheduled one-off and recurring visits in the window, not yet invoiced, at visit date + 30-day terms + lateness. **Snow routes excluded** (a daily stop bills only when serviced — counting them would invent ~$200/day).
- Money out: Penny's recurring monthly bills (RecurringBillService), biweekly payees found the same way (truck loan $363.08), tax instalments as settings (CRA $875/mo, GST $3,500/mo, day of month editable), and "everyday spending" = average weekly bank spending over the last 8 weeks minus the bills above and transfers.
- Floor: ops_settings `cashflow_floor` (default $2,000).
**Steps** pure `CashFlowForecast::build()` (13 weekly buckets, running balance, low point) → DB collector `CashFlowForecastService` → page `/crm/accounting/cashflow.php` (weekly table + bars, every line explained) → Penny brief item when the low point is under the floor, naming the invoices to chase.
**Verify** unit tests on build(), lateness, biweekly detection, snow exclusion; run on production read-only and sanity-check against the bank (opening balance = statement).

## 2. Breaks (Otto + crew app)
- Table `time_clock_breaks` (entry, start, end, source manual|gps, confirmed). Setting `breaks_paid` (BC: 30-min meal break before 5 h; paid when the worker must stay available — owner to confirm policy).
- Crew: Start/End break on Home Base + iPhone Time Clock (JWT). Timesheets deduct unpaid breaks (TimeclockFunctions / OvertimeCalculator — total_minutes must stay the paid figure).
- Otto: GPS stops ≥20 min away from any job during a shift → "Was 12:10–12:40 a break?" (never auto-deducts). Shifts >5 h with no break flagged.
**Unknown to settle first:** where paid minutes are summed (every timesheet/report path) — graph before touching.

## 3. Waste & minimum orders (Otto + Sam)
- Products: `waste_pct`, `pack_size`, `pack_unit`, `min_order_qty`; supplier rules (Inline: $750 min, trays of 10/6, min 5 per variety) per vendor.
- Sam's closer: material quantity = measured need × (1 + waste) rounded up to packs/minimum, shown with the reason. Otto's delivery check uses the same numbers.
- Defaults until the owner gives his own: mulch 10%, soil 10%, sod 5%.

## 4. Buying prices & best time to buy (Penny)
- Needs the Inline import (separate plan, approved) + receipt line history (expense_line_items, vendor_product_skus).
- Price history per product per supplier; Penny flags rises and the cheapest supplier; "best time to buy" only after a season of weekly lists (shows what it has until then).
