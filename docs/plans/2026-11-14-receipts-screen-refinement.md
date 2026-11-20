# iPhone Receipts screen refinement (plan, 2026-11-14)

Tim's brief (with a screenshot of the live list): fix the row layout, simplify the floating header, clearer status, honest handling of uncertain extraction. Bottom bar is approved and untouched. Kanso look, brand colours only (tenant colours are switched off app-wide).

## What the code does today (read 2026-11-14)
- `Features/Receipts/ReceiptsView.swift`: `toolbarContent` (~line 720) puts, in one bar, a "N pending" chip, `TimeChip`, Select, an ellipsis menu (Snap a label, Choose from Library, Archive & Export) and Refresh. That is why the title collapses to "R…".
- **The play button is `TimeChip`** (`Features/TimeClock/TimeChip.swift`): it opens the time clock ("Clock in" / shift). It is the same chip on every tab, so it is not a receipts action; on this screen it shows as a bare play icon with no label.
- `ExpenseRow` (~line 873): one line holds merchant + archive icon + anomaly icon; the second line holds date AND the category pill, so the pill wraps ("Materia / ls", "Office/ Admin"); amount, a confidence dot and the status badge share the right column; the merchant has no line limit under the Ittai style, so a long name pushes the amount.
- Warning icon (`anomalyIcon`): shown when `anomalyScore > 15` (high > 30), **not tappable**, and the model has only the number — no reason text.
- Confidence dot: `matchConfidence` tier, a bare dot with no meaning shown to the user.
- Merchant: `displayVendor = vendorName ?? vendorNameRaw ?? "Unknown vendor"`; nothing marks a value like "Page 1 of 1" as doubtful. The list model has no "merchant uncertain" flag and no raw OCR text; the detail screen may (to read before building).

## Changes
1. **Row:** merchant left (single line, truncates tail, `layoutPriority` below the amount); amount right, one line, never squeezed; second line = date; third line = category (never broken, `lineLimit(1)` + `fixedSize` on its own line, shortens to fit); thumbnail 44 pt always, one placeholder style (document glyph on a sunk square); status as a text pill (Pending / Draft / Rejected / Approved / Sent) kept, with accessible contrast; hairline separators; consistent 12 pt vertical rhythm. Large text: stack, as the row already does for accessibility sizes.
2. **Header:** title "Receipts" always shown in full; keep Select and Refresh visible; the "N pending" chip and Snap a label / Library / Archive & Export move into the ellipsis menu (a pending count badge on the ellipsis). `TimeChip` stays (it is the app-wide shift control) but gets its text label ("Clock in") back when there is room, otherwise it is not shown on this screen; the choice goes to Tim (see questions).
3. **Status clarity:** processing status (Pending / Draft / Approved / Sent, plain text pill) is separate from **attention** (a small tappable "Check" marker). Tapping a warning opens a sheet that states only what the data supports: "Penny flagged this as unusual" plus the amount/date facts and the two next steps (Open receipt, Mark as checked if the server supports it). Rejected shows the rejection reason only if the detail endpoint returns one; otherwise "Rejected" with "Open to see details". No invented reasons, no AI confidence figures.
4. **Uncertain merchant:** a local rule (pure Swift, tested) that marks a merchant as doubtful when it matches non-merchant text ("Page 1 of 1", "Receipt", "Thank you", only digits, "Unknown vendor") and shows "Check merchant" in the accent colour with the raw text beneath in meta; tapping opens the receipt detail where the merchant can be corrected. **Backend dependency:** a real per-field confidence/"needs review" flag and the raw OCR text in the list response; until then the rule is only a text check on `vendorName` / `vendorNameRaw`.
5. Scrolling: content inset so the last row clears the meniscus bar and the first row clears the floating header (safe areas).

## Verify
Debug launch flag `-mwReceiptsListDemo` with synthetic expenses: all statuses, a 60-character merchant, "Page 1 of 1", no thumbnail, long category, archived, anomaly high/medium. Screenshots on iPhone SE (narrow), iPhone 15, and large Dynamic Type; scroll to both ends. `ios/typecheck.sh`, `ios/LogicTests/run.sh` (new tests for the merchant rule and row layout choices), simulator build. No push without Tim's go-ahead.

## Who builds
Codex (`scripts/codex-ios-build.sh build receipts-list <task> --scope ios/MowologyCRM/MowologyCRM/Features/Receipts`), second Codex reviews, Claude reviews the real diff and runs the simulator. `ReceiptsView.swift` carries uncommitted changes of mine (the empty-state view), so commit first or Codex would build on stale text.

## Questions for Tim
1. The play button is the shift clock-in chip, not a receipts action. Hide it on Receipts, or keep it with its "Clock in" label?
2. OK that "Check merchant" is a local text rule now, with the real flag from the server as a later job?

## Update 2026-11-14 — shared header first (Tim: "do that for all views it would suit, NOT the spine views where the head is centralised"; "use Codex")
Order changes: **Job 1 = one shared header** for every screen that has a head (Schedule/Otto, Time Clock/Otto, Receipts/Penny, plus screens opened from them): a permanent compact **head line at the very top** (face, name, AI tag, "needs your attention · Review" or "All clear") and a **controls line** under it (title left; Clock in with its label and Refresh right; the rest in the ellipsis menu). Not on Kaname's tab or any head's spine screen. This answers question 1 above: Clock in stays, labelled, on the controls line on every screen. **Job 2 = the Receipts row, status and uncertain-merchant changes** below, on top of Job 1. Both run through `scripts/codex-ios-build.sh build`, one after the other (both touch `ReceiptsView.swift`), from a committed base because the script copies files from the working tree but builds in a scratch worktree cut from HEAD. Task text for Job 1: scratchpad `codex-header-task.md`.
