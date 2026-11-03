# Plan — Penny's ranked "possible twin" check on approved receipts

Status: **plan only, written 2026-10-29. No code until Tim says go.** Owner: Penny. Build: Codex (`scripts/codex-web-build.sh`), Claude reviews.

## Why
Chequing (1010) is $2,105.37 under the bank at October. Tracing May with `StatementBooksCheckService::check()` (read-only prod-run, 2026-10-29):
- 8 approved receipts ($531.33) are second photos of purchases whose bank line is already linked to another receipt, so the purchase is booked twice. 6 are firm ($444.67: #106, #311, #186, #181, #163, #146); 2 were dismissed as "not a duplicate" by a person (#229/#148, #152/#261) and stay Tim's call.
- 2 bank lines are linked to the wrong receipt: #159 has a total of $0.00 (line 10915 is really #212's $17.81), and #114 is rejected (line 10918 is really #219's $2.61).
- Penny did see the pieces: 7 of the 11 carry a `DUPLICATE_DAY` flag, all were accepted on 21 and 26 Oct, each has an open `payment_source` question. Nobody was told "this double-counts $200".

Root cause, read in code: `DuplicateReceiptService::pairsFor()` only looks at `draft` / `pending_approval` receipts. Once a receipt is approved it is never twin-checked again, and `removeCopy()` refuses an approved copy ("remove the other instead"). Nothing ranks by dollars.

## Printed time and ticket number lead (Tim, 2026-10-29: "times from the receipts are a major indicator")
`receipt_facts` (migration 1227) already holds each receipt's printed time (`time_first`, `time_last` for scale tickets), ticket / invoice / transaction / auth number and card last 4. Read-only prod check on the May pairs:

| Pair | Printed facts | Reading |
|---|---|---|
| #106 / #102 Shell | both 11:04, auth 396190 | same slip |
| #181 / #160 Chevron | both 08:13, invoice 009817 | same slip |
| #163 / #210 Chevron | both 07:53, transaction 255437 | same slip |
| #186 / #105 Landfill | both Time In/Out 09:55-10:02 | same ticket |
| #212 / #159 Chevron | both 16:03, invoice 001302 | same slip (#159 was read as $0.00) |
| #311 / #147 Shell | same auth 340960; time on one only | same slip |
| #146 / #316 Shell | #316 has no time or number | not provable from facts; falls back to the count rule |
| #229 / #148 Eagle Disposal | scale ticket 08:08-08:16, card slip 08:17 | **one visit, two documents** (the slip prints 1 minute after Time Out) |
| #152 / #261 Chevron | invoice 0027 vs 0627, no times | probably one slip with a misread digit; weak |
| #219 vs #114 / #214 | 08:14 inv 001852 vs 07:35 inv 006752 | **different purchases**: #219 is real; #114 and #214 are twins of each other |

Coverage: 211 of 267 approved 2026 receipts have a printed time and 171 a ticket number (Chevron 93/96, landfill 24/24, Shell 17/20). Lawnboy (0/22) and Amazon (0/8) have none and use the count rule.

**Correction (2026-10-29):** I told Tim the two existing dismissals (#229/#148, #152/#261) were a person's decision. They are not: `dismissed_by` is NULL, so Penny dismissed them herself from the printed facts (`applyReceiptFacts()`, "different ticket / time = two receipts"). Both look like false "different" calls (a scale ticket plus its card slip; a misread digit). The detector must not treat a system dismissal as final; only a person's dismissal is.

Ranking of evidence, strongest first:
1. Same date + same printed time (or same Time In/Out) → twin.
2. Same ticket / invoice / auth number → twin.
3. A card slip printing within a few minutes after a scale ticket's Time Out, same vendor and total → one visit.
4. Ticket numbers one character apart, same date and total, no times → possible (OCR misread); asked, never assumed.
5. No facts → the count rule (more receipts than bank lines of that amount).
Different time **and** different number → not twins (the 5/1 vs 5/6 $2.61 Chevron case).

## Merge, not reject (Tim, 2026-10-29)
A twin is **merged into the kept receipt and its bank line**, so a mistake can be split off again with the record intact (e.g. the "copy" turns out to be a personal cash purchase or a different payment method, while the bank line was something else).

What exists today and why it is not enough: `removeCopy()` / "merge" in `DuplicateReceiptService` fills the kept receipt's empty fields and then REJECTS the copy ("Duplicate of receipt #X"). The gate's only way back is `reopen`, which takes an **approved** receipt to draft; a rejected one cannot be reopened. So today a wrong merge cannot be undone through the gate.

Design:
1. **New table `expense_merges`** (one row per merged copy): `copy_id`, `kept_id`, `bank_transaction_id`, `evidence_json` (printed time, ticket / auth number, amounts, why Penny thought so), `copy_before_json` (status, payment method, category, the journal entry id it had), `merged_by`, `merged_at`, `unmerged_by`, `unmerged_at`, `note`. A separate table, not new columns on `expenses` (shared schema); migration number allocated when built, MySQL 5.7 safe.
2. **New gate transition `merge`** (approved copy → set aside as "Merged into #X", posted entry reversed, audited): the copy's photo, printed facts and notes stay attached to the kept receipt and to the bank line as "Also on this purchase: #106 (same slip, 11:04, auth 396190)". Nothing is deleted; reports count the purchase once (`AccountingService::countedSql()` already counts one linked line once).
3. **New gate transition `unmerge`** (the split): puts the copy back to draft from `copy_before_json`, marks the merge row unmerged, and the person sets the right payment method (cash, personal, another card) and approves it through the normal path, so it re-posts on the right account (personal items go to 1300 with no ITC, per the 2026-10-19 trap).
4. **"Split off" button** on the kept receipt and on Penny's card for a merged copy; every merge and split writes `HeadLessons::record()` so Penny learns which signals were right.

## What changes
1. A pure detector finds, among **approved** receipts, a receipt with **no bank-line link** whose purchase (same vendor, same total, date within the existing day span) is already covered by a receipt **linked** to a bank line. It also finds the "bad link" kind: a bank line linked to a receipt whose total differs from the line, or that is rejected, while another receipt matches the line exactly.
2. Each find becomes a `penny_questions` row, kind `possible_twin` (the unique key `kind + expense_id` already stops repeats): "Is #106 (Shell $200, 6 May) the same purchase as #102? If yes, set #106 aside."
3. One tap answers: **Same purchase** MERGES the copy into the kept receipt and its bank line (next section: reversible, nothing is deleted); **Different purchase** writes a person's row to `expense_duplicate_dismissals` and a Penny lesson; **Later** leaves it.
4. Questions are ranked by dollars at stake and the brief leads with them. Penny **never acts alone** (rule 3 of the skill).

## Unknowns and how each is settled (cheapest first)
| Unknown | How it is settled |
|---|---|
| How big is the problem across 2026, not just May? | Read-only `prod-run` of the draft detector over all approved 2026 receipts. Output: count, dollars, month. Nothing is written. |
| Does the detector find the right ones and no others? | Backtest on the known set: it must hit the 6 firm pairs, the 2 dismissed pairs must be suppressed, and #212 / #219 must come out as bad-link finds. Every other hit is listed for Tim to eyeball (false-positive rate). |
| Two real purchases the same day (two $200 fills) look like twins | Printed time and ticket number lead (section above, via `receipt_facts` / `ReceiptFactsService`); the count rule (more receipts than bank lines of that amount in the window) is the fallback when facts are missing. Both proved in the backtest. |
| Are the facts reliable enough? | Backtest also reports how often the facts row is missing or contradicts the bank-line count; a misread digit must not hide a twin (#152/#261), so number comparison is tolerant by one character and time carries the weight. |
| Which copy is kept | Default: keep the one linked to the bank line, unless it is unreadable (total 0, impossible amounts) or rejected, then relink to the good one. For #152 / #163 / #146 the older receipt is the unlinked one; the 15 Oct trap kept the original and moved the link. **Tim decides the rule (below).** |
| Can an approved copy be merged and split safely? | The gate's `reject` transition reverses the posted entry, and `reopen` is approved to draft only (read in `ExpenseGate.php`), so `merge` and `unmerge` are new transitions. Proved with a `prod-run` **preview** on one real pair: merge closes the books-vs-bank gap by exactly the copy's amount; then split puts it back exactly (status, method, entry) and the gap returns. |
| What does the split restore, and to what? | `copy_before_json` holds the copy's exact prior state; the split restores it as a draft and a person re-approves with the corrected payment method. Proved in the same preview round trip. |
| Are May to Aug locked? | Query `accounting_periods` (and `StatementCloseService` locks). A locked month turns the reject into a late posting that `LockExceptionService` classifies; the plan must say so on the card. Settled before step 3. |
| Where does the question show? | `PennyQuestionService::open()` already feeds the brief (`PennyBriefAdapter`) and the desk. Add the kind there; web/Android first. iPhone has no card for it: write the gap in `references/surfaces.md`. |
| Cost | Pure SQL, no LLM calls. |

## Steps, each proven before the next
1. **Sizing + backtest** (read-only, prod-run preview). Proof: 6 firm pairs found, 2 dismissed suppressed, #212/#219 flagged bad-link, all other hits listed. Stop and re-think if the extra hits are mostly false.
2. **Detector as a pure function** (`TwinFinder`, in `app/Modules/Expenses/Services/`) with unit tests using these cases as fixtures. Registered in `tests/bootstrap.php`. Proof: `vendor/bin/phpunit` green.
3. **Merge and split** (migration for `expense_merges`; gate transitions `merge` / `unmerge`; a method beside `removeCopy`; all-or-nothing; refuses a locked month unless Tim allows). Proof: prod-run preview on #106 → #102: merge closes the gap by $200.00, split restores it exactly, nothing else moves; unit tests for both directions.
4. **Question, wording, ranking, brief line**; `references/surfaces.md` row; lesson written through `HeadLessons::record()`. Proof: local render as admin (no local DB harness), then phpunit.
5. **Nightly run** in `penny_prepare` (reads, writes only `penny_questions`); a quiet "N possible twins, $X" signal in `BooksWatchService`. Proof: cron run named in `cron_runs`; zero rows rejected by the cron.
6. **Codex review, then deploy** (`scripts/deploy-safe.sh`, OPcache reset, curl login). Proof on production: May questions appear with the right dollars; no receipt changed status without a tap.
7. **Write-back:** Penny skill (`traps.md` area "Receipts" + `one-place.md` row), Obsidian Known-Failure-Patterns, Tim-To-Do.

## Decisions for Tim
1. **Which copy is kept** — the one linked to the bank line (simplest), or always the oldest receipt (the 15 Oct way, may need a relink)?
2. **System dismissals** (#229/#148, #152/#261 are Penny's own, not a person's) may be re-opened by the new check; a dismissal by a person stays final. Photos would settle the Eagle Disposal GST (3.19 on the card slip vs 0.00 on the scale ticket).
3. **Smallest amount worth asking about** — all, or $5 and over? ($2.61 and $6.39 would still be asked under "all".)
4. **Locked months** — may a twin in a locked month be set aside as a late posting, or is it held?

## Not doing
- No automatic rejects. No emailing the accountant. No change to FY2025 (filed, locked).
- No change to how waiting receipts are checked; that path stays as is.
- Art's Nursery $1,255.00 (cash, 2 Aug) is a different problem (how it was paid) and stays a question for Tim.

## Step 1 result (2026-10-29, read-only prod-run + offline backtest, 324 receipts, 1,266 bank lines)
- **Found all 8 known twins**: 6 by same printed time / number (grade A, $521.64), #229 by scale ticket + slip 1 minute apart (B, $72.28), #152 by a one-digit ticket difference (C, $14.38), #146 by the count rule only (D, $7.03). May total $531.33, matching the books-vs-bank trace to the cent. Nothing in other months.
- **Bad links (4 in 2026)**: #159 total $0.00 on line 10915 (real receipt #212), #114 rejected on line 10918 (real receipt #219), plus two new: line 7014 (29 Apr) $36.75 linked to #188 at $36.05, and line 36605 (31 Aug) $184.40 linked to #359 at $134.40 — a $50.00 difference worth reading.
- **False positives (3 hits, all Lawnboy #413/#414/#417, $84.00 each, 7 Oct)**: three real mulch loads the same day; they share a 3-digit "doc number" (003/006) and have no printed time. Rule fixed in the design: a ticket number counts only at 5+ characters or with a matching time; Lawnboy and Amazon (no times) get the count rule only.
- **Not twins, correctly left alone**: Sep receipts with no bank line (statement lines never imported), Art's Nursery $1,255.00 (cash, no line).

## Step 2 result (2026-10-29): `TwinFinder` built by Codex, reviewed by Claude
- `app/Modules/Expenses/Services/TwinFinder.php` (pure, static, PHP 7.4 safe) + `tests/Unit/Expenses/TwinFinderTest.php` (13 tests) + one `require_once` in `tests/bootstrap.php`. Codex's own reviewer found one real flaw (competing evidence let a matching time override unrelated long numbers) and Codex fixed it; full suite 3,348 tests green in the Codex run, 13/13 here.
- Run on the real 2026 data (324 receipts, 1,266 lines, 0.02 s): all 8 known twins and all 4 bad links found; same dollars as the prototype ($531.33 in May). Differences from the prototype: #152 (ticket numbers `0027` / `0627`, 4 characters) is now caught by the count rule (grade D) instead of grade C, because numbers under 5 characters no longer count.
- Known weak spot: Lawnboy #413 / #414 / #417 ($84.00 each, 7 Oct) still come out as grade D ("3 receipts vs 1 bank line"). They are probably three real loads, so grade D is shown as a question and never acted on. Possible tightening for step 4: skip grade D when the receipts carry different ticket numbers of any length.
- Performance note: pairs are compared n by n, fine at hundreds of receipts; bucket by vendor key and total if a tenant has thousands.
