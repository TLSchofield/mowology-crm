# How Kaname's brief is built

## The heads (`CharlieBriefService::collect()`)
Each source returns `['head', 'headline', 'count', 'items' => [key, text, url, priority 1–3, kind,
value, since, yes]]`. Missing heads are skipped; a head that throws goes into `failed` (never fatal,
never read as "dealt with"). Slower than 1.5 s is logged. Memoised per request.

| Head | Source |
|---|---|
| charlie | `DeadlineService::brief()` — deadlines inside their reminder window, overdue first |
| penny | `PennyBriefAdapter` — reads `BookkeeperDeskService` stats and `PennyQuestionService` open questions (Penny has no `brief()` of her own) |
| sam / otto / mia / yui | `SalesDeskService`, `OpsDeskService`, `MiaDeskService`, `YuiDeskService` — each one's `brief()` |
| house | `HouseBriefAdapter` — Work Queue critical lane via `getWorkQueueItems()` (overdue invoices, unbilled visits, stuck visits, stale plans); kinds a head takes over go in `HouseBriefAdapter::SKIP` (empty today) |

## Ranking (`CharlieRankService`)
score = priority weight (1→100, 2→40, 3→15) × learned weight for the kind (0.25–4, `charlie_prefs`)
× age (+10%/day waited, max ×2) × money (1 + log10(1 + value/100)) × 5 if a customer's clear yes.
Muted kinds and hidden keys (snoozed, dismissed today, resolved) are left out.
Note: this is a *learned* order, not yet "cost of waiting" in Tim's sense — a penalty date only
counts through the head's priority and `since`.

## The view (`CharlieDeskService::today()`)
`one` (top item), `rest` (up to 11), `heads` (each head's top 3 + waiting count), `bad`
(`CharlieDeskService::badNews()`), `say` (`CharlieVoice`). Also syncs `charlie_items`, freezes the
day's snapshot in `charlie_briefs`, and may ask Tim "which first?" on a close call.
`CharlieForemanService::card()` adds urgent alerts and held inbox messages to the bad news first.

## Where it goes
| Consumer | What it shows |
|---|---|
| Web dashboard Kaname card (`public/crm/includes/charlie-card.php` + `public/crm/js/charlie-card.js`) | `charlie.php?mode=today`: bad news, the one thing, Open / Done / Later / Not today, questions, inbox |
| Action Board (`public/crm/js/action-board.js`) | one column per head, top 3 each, reusing the card's list; house + unknown heads sit in Kaname's column |
| iPhone Team tab | team-mobile `?mode=brief&head=<head>` — that head's column via `TeamCardService::column()` |
| iPhone opening screen | team-mobile `?mode=brief&head=charlie&ready=1` — the **ready copy** of Kaname's column |
| Email, 7 am Mon–Fri Vancouver | cron → `CharlieBriefEmail::render()` from the frozen snapshot; once a day |

## The ready copy (`head_brief_ready`, migration 1319)
- Built by `HeadBriefReadyService::rebuildCharlie()` on every cron run (`2,17,32,47 * * * *`) and,
  when the copy is over 2 minutes old, again after team-mobile has replied (`ready=1`).
- `HeadBriefReadyService::state()`: under 2 min fresh; 2 min–1 h served then rebuilt; over 1 h not
  served — built live (7–11 s).
- On every read, keys dismissed today / snoozed / resolved are removed
  (`HeadBriefReadyService::without()`); act / move / done in the app drop the key at once.
- Only the opening screen uses it. The Team tab and the web stay live.

## What the opening screen actually shows (checked 2026-10-11)
The ready copy is **Kaname's column only** — deadlines, House lines and any unknown head, top 3 by
score. Penny's, Sam's, Otto's, Mia's and Yui's lines are not in it. The phone then re-orders it
(`MorningBriefStore.build` in `ios/MowologyCRM/MowologyCRM/Features/Splash/CharlieMorningBrief.swift`):
new since yesterday first, then by priority (not score), at most 3 lines, the rest counted as
"ongoing on the Team tab". Each line is labelled with its `head` ("House", "Kaname") and a tap
opens that head on the Team tab.
