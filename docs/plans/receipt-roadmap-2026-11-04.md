# Receipts: faster capture, faster learning, complete vendors (plan, 2026-11-04)

Tim asked: make the receipt process faster, make it learn faster, make the vendor directory complete, have Otto
draw vendor boundaries — "an award winning app". Sources: a Codex audit of the whole pipeline (read-only, checked
claim by claim; one claim was wrong and is dropped), the replay corpus (285 approved receipts), and production
counts run today.

## Where it stands (measured 2026-11-04)

| Fact | Number |
|---|---|
| Parser alone, total right (replay of 285 approved receipts) | 73.7% (64.2% this morning) |
| Phone read vs server read on totals, 19 receipts with an expense | phone right 14, server right 9 |
| Vendors with a GST number | 13 of 64 (the biggest vendor, 113 receipts, has none though every receipt prints it) |
| Store locations geocoded / exactly on the building / with hours | 28 / 8 / 0 of 62 |
| Vendors linked to the shared vendor commons | 0 of 64 |
| Tesseract on production | not installed, so every receipt pays for a Google Vision read |

## The target

One owner, one phone, one tap. A receipt photographed in a truck is read on the phone instantly, arrives with
vendor, totals, card, job and category already right, and only what is genuinely uncertain asks for a tap. Every
read, correction, bank match and approval teaches the reader, and every fix is proven on the whole corpus before it
ships. The vendor directory fills itself from the receipts, and Otto knows each store's lot.

## Step 0 — before anything else (S)

- **Merge the open Otto branch** `claude/suspicious-pasteur-4fa5a2` into feature/language: lot borders and the
  per-business placing cron are live on production but on no main branch (141 commits apart). Same drift as this
  morning's merge.

## Phase 1 — quick wins (S each, days not weeks)

1. **GST number reader.** `VendorIdentity::gstNumber()` misses `GST:` / `R743318321` (label on one line, old R-prefixed
   number on the next). Fix it, then backfill every vendor's GST number from its approved receipts (empty fields only).
2. **Phone read fills the server's gaps.** When the server reads no total, or a total that fails subtotal + taxes =
   total, take the phone's total. Keep the server's GST (the phone's GST is weaker). Measured on the replay first.
3. **Card from the last four digits.** The parser reads `card_last4`; the review sheet throws it away and defaults to
   credit card. Map last4 to the company card/holder and prefill it.
4. **Job preselected when it is clear.** The server scores GPS, crew and time; the sheet starts at "No Job". Select it
   when the score and its margin over the next job are clear, one tap to undo.
5. **Vendor clean-up.** Merge or retire junk vendors (two named "amount") and same-name duplicates, through the
   existing vendor merge, each listed for Tim first.

## Phase 2 — speed (M)

6. **Fast draft, finish in the background.** Today one upload can wait on Vision, then a line-item AI read, then a
   header AI read in sequence. Save and show the draft at once; let line items and repairs arrive moments later,
   never overwriting a field the user touched.
7. **iPhone capture in one call, like Android.** Android sends photo + details in one idempotent request; iPhone
   uploads and saves separately, so a failure leaves an unreviewed draft. Move iPhone to the Android endpoint.
8. **Review by exception.** The sheet shows every field equally. Collapse fields that two readers agree on or the
   arithmetic proves; show "Submit — 6 fields confirmed"; open only conflicts, estimates and weak matches.
   This is the feature that makes it feel like magic.
9. **Retake or later, never a silent blank.** A failed read says so, offers retake, keeps the photo, retries in the
   background.

## Phase 3 — learning (M)

10. **Corpus as the gate.** Nightly corpus refresh; every parser change replays all receipts and ships only with zero
    made worse (tool built today: `scripts/receipt-replay.php`). The phone's text is now stored too (migration 1433).
11. **A weekly "misses by pattern" card for Penny.** Grouped failures ("total = truth ÷ 1.05, 6 receipts, 4 vendors"),
    so one fix closes a class.
12. **Truth without waiting for approval.** Phone and server agree and the arithmetic holds → provisional lesson,
    confirmed or reversed later by the desk. Never treated as approval of the expense itself.
13. **Global lessons vs store lessons.** "Total printed above its label" is a rule for every receipt; SKU names and
    category defaults stay per store. Today almost every lesson is per vendor.
14. **Crew corrections teach (Tim, 2026-11-04: yes).** A crew member's corrections are recorded as lessons at save,
    marked provisional; the desk's approval confirms them and a desk change to the same field reverses them.
    Replaces the earlier rule that a crew capture waits for the desk.

## Phase 4 — vendors and Otto (M)

15. **Vendor record fills itself.** From each receipt (empty fields only): GST number, phone, website, store number,
    address → `VendorDetailsService`. A "vendor gaps" line on Penny's card for what receipts cannot supply.
16. **Locations placed exactly.** Geocode the 34 unplaced store locations from receipt addresses (the nightly
    trip cron already geocodes; most lack an address today).
17. **Otto draws each store's lot.** `ParcelLotService::lotAt()` already returns a lot for any point (BC lot maps).
    Store it as the location's border; `SupplierStopService` uses the border instead of its 80 m circle, so a stop
    at a big-box yard or a nursery counts, and a stop next door does not.
18. **Hours.** Receipts don't carry them. Options: Google Places (a key and a small cost per lookup, once per store) or
    Tim types them for the handful that matter. *Tim decides.*
19. **Link to the vendor commons.** 0 of 64 vendors are linked; link on GST number / phone / domain so tenants share
    vendor identity (never prices).

## Unknowns and how each is settled

- Phone total better than server's at scale? → replay with stored phone text after a week of uploads (1433 must run).
- Lot maps outside BC → `ParcelLotService` returns `no_source`; vendors elsewhere keep the circle.
- Card last4 → holder mapping exists? → check `penny_card_holders` on production before building 3.
- Fast-draft async: does the cPanel cron cadence (1 min?) make "moments later" true? → measure the OCR queue cron.

## Order

Step 0, then Phase 1 (items 1–5) as one batch, then 6–8, then 10–13 and 15–17 in parallel (different files).
