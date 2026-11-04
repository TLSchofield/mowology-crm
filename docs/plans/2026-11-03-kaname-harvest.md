# Kaname harvest — what the brief, pulse and report skills add to the chief of staff (2026-11-03)

Status: **APPROVED and BUILT 2026-11-03** — see "Build record" at the end. Plan as approved: Survey (Sonnet, numbers only) of small-business `monday-brief`, `business-pulse`,
`growth-pulse`, `report-builder`, `report-pack`, `smb-router`, `month-end-prep`, `shared/{absent-is-not-zero, chain-seams}`,
sales `daily-briefing`, productivity `task-management`, operations `status-report`. Diffed against `CharlieBriefService`,
`CharlieRankService`, `CharlieDeskService`, `CharlieBriefEmail`, `CharlieUrgentService`, `HeadBriefReadyService`.

**First, a reconciliation (commit 35eeb5979).** Production was ahead of `feature/language` on ten Charlie files (the shown name from
`HeadIdentity`, customer mail routed into Penny's/Otto's/Mia's briefs, Pulse and invoice-rejection urgent alerts, the ready-brief
changes). Live copies are in the branch; suite 3589 green.

## What the survey found
Voice is Kaname's own already. What he lacks is what the plugins treat as the heart of a brief:
- **One status, the worst section** (business-pulse): the overall colour is the worst part, and it is *forced red* when a line names a
  specific customer, an amount and a deadline within 7 days. Thresholds "retuned after 4 weeks".
- **A short list is a quality test** (inbox-manager): about 5 "needs you" is right, 8 is fine, **twenty means the threshold is wrong**.
- **Every number carries its comparison** (monday-brief, report-builder): "▲ 8%", "(no prior baseline)", "No material changes", ▬ under 1%.
- **A scheduled run asks nothing and never skips for a data gap; it says what is missing once** (report-pack); **absent is not zero**: a head
  that returns nothing or fails must not read as "all clear"; a schedule is never claimed unless something ran.
- Max 3 findings; "the one thing" must name an amount, a person and a deadline; link related signals into one entry.

## What the live data says (read-only, `prod-run.sh`, 2026-11-03 19:40)
| Source | Today |
|---|---|
| Lines Kaname receives (3.1 s, all 8 heads answered, none failed) | **200**: Otto 101, Yui 37, Sam 31, Penny 15, Mia 5, Ada 5, House 4, Charlie 2 |
| Marked priority 1 | **58 of 200 (29%)**: Sam 25 of 31 (19 are one kind, replies to Mia's fall email), Yui 9 (all approvals with no accepted quote), Penny 7, Otto 8, Ada 5, Charlie 2 |
| Worded as a decision ("…? Tap", "Send…") | about **5 of 200**; the rest are status sentences |
| Overdue deadlines | CRA payroll remittance since Oct 15; WorkSafeBC since Oct 31 — both in the top three |
| Biggest money line | House: 31 overdue invoices, $11,278 |
| Snapshots kept | one per day, with the day's items, heads and bad news (`charlie_briefs`): yesterday's totals are available |
| Same issue reported by two heads | none found; the "619 look-alike pairs" are siblings of one kind (19 campaign replies, 5 "same purchase?" questions) |
| A wording bug of mine | Sam's quiet-client line reads "Gary Hughes has **gone gone** — usually every 32 days" (the state word is added after "has gone") |

## The pieces (pure classes + tests; wired into `CharlieDeskService::today()`, the card, the email; no migration)

### A. One status — `BriefStatus`
Per head: **red** if one of its lines is a deadline overdue or due within 7 days, or a priority-1 line with an amount ≥ $500 that is overdue,
failed or past due; **amber** if it has any other priority-1 line; **green** otherwise; a head that failed is **unknown**, never green; a head that
answered with nothing says so. Overall = the worst head, with the line that decided it. Today: RED, because of the two overdue deadlines and the
$11,278 overdue invoices. Shown as one chip at the top of the card and as the first line of the email: "RED — Payroll remittance to CRA overdue since
Oct 15". Thresholds live in ops_settings (`charlie_status_*`); until 4 weeks pass (first used 2026-11-03) the chip's tooltip says "thresholds are the
defaults, review after Dec 1".

### B. Urgency guard — `UrgencyGuard`
Within one head, at most **5** lines keep priority 1 in the ranking (order: Tim's clear yes, then amount, then oldest); the rest are ranked as priority 2
and marked `demoted`. A customer's clear yes still ranks ×5. A head over the cap is named once, quietly, on the card ("Sam marks 25 of 31 lines urgent; more
than 5 usually means its rule is too loose"), and written to the backlog for the head's owner session. Today it would take Sam from 25 to 5, Yui from 9 to 5.

### C. The comparison — `BriefTrend`
From yesterday's snapshot: "200 lines (▼ 14 since yesterday), 53 urgent (▼ 5)"; ▲ ▼ ▬ with ▬ under 1%; "no prior baseline" when there is none;
"No material changes" when nothing moved. On the card under the status chip and in the email.

### D. An honest footer — in `CharlieBriefEmail`
"Built 07:02 from 8 of 8 heads." and, when one failed, a single quiet line ("Penny could not be reached; her part is missing"), never repeated
(report-pack / absent-is-not-zero). A head that answered with no lines is "nothing to report", which is not the same as failed.

### E. Fix my own wording bug
"has gone gone" → "has gone quiet" / "looks lost" in `SalesDeskService` and `SamSignals`, with a test.

## Not built, and why
- **Collapsing 19 same-kind lines into one** (merge, don't staple): needs a noun per kind ("replies to Mia's fall email"); done head by head, so it belongs
  to each head's own session. Backlog, with the three biggest runs named.
- **Rewriting 195 status lines as decisions:** "Send 28 invoices? Tap" needs each head's own facts; Kaname never invents them. Backlog.
- **Routed mail that is not billing** (Penny's "Meghan wrote about billing: can we book you for a fall cleanup"): the router, not Kaname.
- **Cross-head de-duplication:** none exists in the live data.
- **Weekly pack / Friday recap:** a new product, not a rule.

## Dry run before anything ships
A: the status per head and overall, and which line decides it. B: Sam, Yui, Penny, Otto before and after; the top three before and after (the CRA payroll
line must stay on top). C: today against yesterday's snapshot. D: render the email from the frozen snapshot, check the footer. E: the new text.

## Verification
`tests/Unit/ChiefOfStaff/KanameHarvestRulesTest.php`; Codex on the new classes and `CharlieDeskService`; `CharlieDeskService`, `CharlieBriefEmail`,
`CharlieBriefService` and the card are taken from live first (done). Deploy dependency-first, OPcache, check the dashboard card and a rendered email
(`CharlieBriefEmail::render()` on the real snapshot, nothing sent).

## Build record (2026-11-03)
- Commits 35eeb5979 (live pull) and ddb2f2ffb: `BriefStatus`, `UrgencyGuard`, `BriefTrend` (pure), wired into `CharlieDeskService::today()` / `payload()`, `HeadBriefReadyService::todayPayload()`,
  `CharlieBriefEmail::render()`, the card (`charlie-card.php` / `.js` / CSS), and the wording fix in `SalesDeskService` / `SamSignals`; `tests/Unit/ChiefOfStaff/KanameHarvestRulesTest.php` (7 tests).
- **Dry run on live (rolled back, new desk with renamed inline classes, all 8 heads answered):** status **RED** — "Payroll remittance to CRA (CPP, EI, income tax) — overdue since Oct 15"
  (deciding line: the older of two overdue deadlines); heads: Charlie red, House red ($11,278 past due), the other six amber; **urgent lines 58 → 29** (Penny 7→5, Sam 25→5, Otto 8→5, Yui 9→5);
  trend "201 lines ▼ 4 since yesterday, 29 urgent (no prior baseline)" (yesterday's snapshot has no urgent count yet; tomorrow's will); the rendered email starts with the status and ends
  "Built 20:03 from 8 of 8 heads. The colours use the starting thresholds; review them after Dec 1."
- **Open for Tim:** the status says RED for the payroll remittance but "the one thing" is still a customer's "Ok" (ranking is learned from what he opens). His rule of 2026-10-11 says penalty dates first;
  a floor for forced-red lines in the ranking is a one-line change if he wants it. Not done without his say (outside the approved plan).
- Not built: grouping 19 same-kind lines, rewriting 195 status lines as decisions, the routed-mail mismatch — backlog.
