# Plan: duplicate receipts on the phone (merge, facts first, big buttons)

Status: **plan only, written 2026-11-05. No code until Tim says go.** Owner: Penny. Builds on
`docs/plans/2026-10-29-penny-possible-twin-check.md` (its phase 2 "merge and split" was never built).

## Why
Tim, 2026-11-05, on the iPhone Penny card ("Possible duplicate · group 1 of 10", two Shell $20.00 receipts):
- deciding from two small photos is clumsy and the print is too small to read;
- he wants to **merge** one receipt into the other, not "keep this one";
- the buttons are too small and too close together: "too easy to hit the wrong one". They should be **larger and square**.

## What the code does today (read 2026-11-05)
- Pairs: `DuplicateReceiptService::pairsFor()`, waiting receipts only (draft / pending_approval) against any
  receipt with the same vendor and total within ±3 days (`ExpenseLookupService::findDuplicates()`), plus
  "same photo". Grouped by `groups()`, sent to the phone by `bookkeeper-mobile.php` (`dupes`).
- Printed facts are already read and saved: `receipt_facts` (migration 1227) holds printed date, time
  (first / last), document number and kind (invoice / transaction / auth / ticket), card last 4, terminal and store.
  `ReceiptFactsService::duplicateVerdict()` already returns **same** ("same invoice 7128480") or **different**.
  Penny acts on "different" alone (a system dismissal); she **never acts on "same"**, she only adds the reason.
- Each member carries `facts_line` and each group a `why`. **The web card shows them; the iPhone ignores both**
  (`BookkeeperModels.swift` has no field for them). This is why the phone card is all photos.
- "Keep this one" = `removeCopies()`: fills the kept receipt's empty fields from the copy (only while the kept one
  still waits), then REJECTS each copy as "Duplicate of receipt #X". **No undo**: the gate has no way back from
  rejected (`ExpenseGate::TRANSITIONS`, `reopen` is approved → draft only). When the kept receipt is already
  approved, nothing is carried over and the copy's photo stays on the rejected copy.
- Android (crew app, native): no duplicate screen found in `android/app/src/main` (to confirm, step 0).

## The new screen (iPhone first, web card the same words)
Top to bottom, one group at a time ("1 of 10"; moves on by itself after each answer):

1. **Penny's verdict, one line, large:** "Same receipt: invoice 7128480 is on both." or, when nothing printed
   matches, "Same total and date. Nothing printed proves it either way."
2. **Facts side by side**, one row each: vendor, date, printed time, total, number, card, status ("in the books" /
   "waiting"), sent in by. A row that matches gets a tick; a row that differs is highlighted. Missing values show "–".
3. **Photos**: two thumbnails. Tap opens them full screen: pinch to zoom, swipe between the two
   (the same spot on both, so a difference jumps out).
4. **Which one is kept** is chosen for him and said in words above the buttons: "Keeping #254 (already in the books)".
   "Keep the other instead" is a small text link there, well away from the big buttons.
5. **Two square buttons pinned to the bottom**, side by side, each half the width minus gutters
   (about 160×160 pt on his phone, never below 120), a 16 pt gap, big icon over a short label:
   - **Merge**: filled navy, merge icon, "into #254" under it;
   - **Different**: outline only, two-receipts icon, "keep both" under it.
   No other buttons sit near them; "Keep this one" under each photo and "Not duplicates — approve both" go away.
6. **Undo after every tap:** "Merged into #254 · Undo" stays up for about 6 seconds. Later, the kept receipt lists
   "Also on this purchase: #126" with **Split off**, so a wrong merge can be undone at any time.

Groups of 3 or more: same layout with a column per receipt (scrolls sideways), and "Merge all into #X".

## Merge, what it means in the books
- The copy is **set aside, not deleted**: rejected as "Merged into #X" (kept for the six-year record), as today.
- New: a row in **`expense_merges`** (`copy_id`, `kept_id`, evidence, `copy_before_json` with its exact prior status
  and fields, `merged_by` (person or Penny), `merged_at`, `unmerged_by`, `unmerged_at`). Same table the 29 Oct
  plan designed, so approved-vs-approved twins can use it later.
- The kept receipt **shows the copy's photo** ("Also on this purchase") by reading `expense_merges`, so the approved
  receipt itself is never edited. While the kept one still waits, empty fields are filled from the copy as today.
- **Split off** = new gate transition `unmerge`: allowed only for a rejected receipt that has an open merge row;
  puts it back to the status in `copy_before_json` (draft / pending), marks the row unmerged, writes the audit.
  It then goes through the normal approval path.
- Merging a **waiting** copy never touches the ledger (it was never posted). Approved-vs-approved twins (which do)
  stay in the 29 Oct plan's phase 2 and are not part of this build.

## Penny merges the certain ones herself (behind a switch, off until Tim agrees)
Only when **all** hold: the copy is waiting (never an approved one); same vendor and total; and
`duplicateVerdict()` says **same** from a 5+ character number, or the same printed date **and** time.
She merges into the kept one, writes `merged_by = Penny` and a `HeadLessons` entry, and her card says
"Merged 7 copies today (same invoice / time) · Review". Each one has Split off. A person's Split off teaches her
that signal was wrong for that vendor. Everything else is still asked.

## Unknowns and how each is settled (cheapest first)
| Unknown | How |
|---|---|
| Of today's groups, how many are "certain"? | Read-only `scripts/prod-run.sh`: `pairsInLine(60)` + `duplicateVerdict()` per group. Output: count of same / unknown, dollars. |
| Is "same" ever wrong? | Same prod-run over history: every person's dismissal (`dismissed_by` NOT NULL) and every "Duplicate of receipt" reject. A "same" verdict on a pair a person called different = a false positive. Must be 0 before the switch goes on. |
| Can `unmerge` restore exactly? | Unit tests both ways, then a prod-run **preview** (rolled back) on one real pair: merge, split, compare every column with before. |
| Locked months | A waiting copy has no posting, so a lock does not apply. Checked in the preview anyway (`accounting_periods`). |
| Square buttons fit small phones and big text | `ios/DesignRender` at 320 / 375 / 393 pt widths, light / dark / field, and the largest Dynamic Type. |
| Does Android show duplicates anywhere? | `git grep` in the Android source and the crew app's Penny screens. If none, record it in the Penny skill's `references/surfaces.md`. |

## Steps, each proven before the next
0. **Sizing + false-positive check** (read-only, above). Stop if "same" has any false positive.
1. **Server:** migration `expense_merges` (MySQL 5.7, next free number); `DuplicateReceiptService::merge()` replacing
   the inside of `removeCopies()` (same refusals, plus the merge row); `unmerge()`; gate transition `unmerge`.
   Members get a structured `facts` object and the group its `verdict`; the kept receipt lists merged copies.
   API modes `merge` / `unmerge` in `bookkeeper-mobile.php` and the web `receipt-review.php` (the old modes stay
   as aliases for older app builds). Proof: phpunit (new tests in `tests/Unit/Expenses/`), the preview round trip.
2. **iPhone screen** (`PennyCardView` duplicates section, a new full-screen photo pager, square buttons, undo
   toast, "Also on this purchase" + Split off on the receipt). Proof: compile, logic tests for the
   tick/highlight rules and the kept-receipt choice, design render at the sizes above.
3. **Web card** (`bookkeeper-card.js`): same words, Merge / Different, undo. Proof: local render, phpunit.
4. **Penny's auto-merge** in `penny_prepare` behind `business_settings` switch `penny_auto_merge_twins` (off).
   Proof: dry run on production prints what she would merge; Tim reads the list; then the switch.
5. **Codex review, deploy web (deploy-safe), TestFlight**, verify on production and on Tim's phone.
6. **Write-back:** Penny skill (`one-place.md` merge / unmerge rows, `surfaces.md`, `traps.md` if anything is found),
   Obsidian, Tim-To-Do.

## Decisions for Tim
1. **May Penny merge the certain ones herself** (waiting copies only, undo always there)? Today she only proposes.
2. **Which one is kept when both are waiting:** the one linked to a bank line, else the older? (Same open question as
   the 29 Oct plan.) When one is already in the books, that one is always kept.

## Not doing
- No approved-vs-approved merging here (29 Oct plan, phase 2). No deletes. No change to how pairs are found.
