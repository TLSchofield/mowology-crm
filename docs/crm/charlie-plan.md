# Charlie — Chief of Staff · Phase 1 plan

Status: **plan only, awaiting Tim's approval.** Branch `feature/charlie-chief-of-staff` (from `origin/feature/language` @ `067478f2`). Migration range 1170–1179.

Charlie reads every head, picks **the one thing that needs Tim today**, and builds a **7 am brief** of each head's top items. He learns from what Tim does first and what he waves away.

## 1. What already exists

| Thing | Where | Use for Charlie |
|---|---|---|
| Deck include, Charlie placeholder | `public/crm/includes/dept-heads-deck.php:42-47` (`$__team`), side column `:140-150` | Swap the placeholder for `charlie-card.php` |
| Deck gate | same file `:12` (`expenses.approve`), `:18` (returns if Penny's 1125 not run) | Charlie inherits both. If Penny isn't ready, Charlie doesn't show either. Fine today, but worth knowing |
| Deck on dashboard | `public/crm/dashboard_appstack.php:196` | — |
| Work Queue (live "needs attention") | `getWorkQueueItems()` `app/Services/CrmFunctions.php:2444`; dashboard `:100`, lanes `:353` | Its **critical** lane (overdue invoices, unbilled visits, stuck visits, stale plans) belongs to no head. Charlie reads it as a `house` source |
| Penny numbers | `BookkeeperDeskService::stats()` `app/Modules/Expenses/Services/BookkeeperDeskService.php:65` (read-only SQL) | Penny adapter |
| Penny questions | `PennyQuestionService::open()` `:209` is read-only. `scan()` `:43` **writes**, so it is never called. `firstName()` `:182` | Penny adapter, and Tim's first name |
| Penny badges / brain pattern | `PennyBadgeService::compute()` (pure, newest-first decisions); `PennyBrainService` on `fix/receipt-learning-baseline` (raw counts → baseline in `ops_settings` → `combine()` → `shapeNumber()`) | The model for Charlie's badges and brain units |
| Email | `sendEmail()` `app/Services/Messaging/MessagingService.php:64` (returns `[success, method, error]`), loaded through `public/crm/includes/messaging.php` | The 7 am email (only if Tim wants it) |
| Cron pattern | `app/Modules/Team/Cron/tracking_silent_alert.php` (paths bootstrap, CronLock, explicit `CrmFunctions.php`); shim `public/crm/cron/tracking_silent_alert.php`; registry `public/crm/database_appstack.php:311` | Template for the brief cron |
| `recordCronRun()` | `CrmFunctions.php:2899` | Called on every exit path |
| `ops_settings` (`setting_key`/`setting_value`) | `database/migrations/202_weather_scheduling.sql:9` | Charlie's settings and brain baseline |

**There is no existing daily digest, brief or morning-email cron.** I grepped for digest, morning and brief across `app/` and `public/crm/`. The only "morning" hits are weather display settings.

## 2. Unknowns, settled cheapest first

1. **HeadBrain API.** It isn't on `feature/sam-sales-head` yet (no commits ahead, no file in that worktree). Settle by reading Sam's branch when it lands. Until then Charlie's brain service only produces counts.
2. **Sam, Otto and Mia `brief()` shape and cost.** Settle from their branches. Each call is guarded and timed.
3. **cPanel crontab timezone.** Registry `:335` shows autopay "9 AM" with expr `0 17`, which looks like UTC. Avoid the question entirely: run the cron hourly and have it send only when it is 07:xx in America/Vancouver and today's brief hasn't been sent. Tim confirms the crontab line in cPanel.
4. **Prod, via Tim's logged-in Chrome (Database Manager, read-only):**
   - Tim's `users` row: id, email, first_name.
   - Whether other admins hold `expenses.approve`.
   - `ops_settings` columns (migration 1004 writes `key`/`value`, which disagrees with 202).
   - That 1125 and 1126 have run.
5. **Prod drift.** `cmp` prod's `dept-heads-deck.php`, `mowology-brand.css` and `database_appstack.php` against the repo before any deploy. `fix/receipt-learning-baseline` already changes the deck.
6. **Email delivery.** Does `sendEmail()` reach Tim's chosen address? Settle with one test send to Tim only, after approval.

## 3. Files to create

- `database/migrations/1170_charlie_chief_of_staff.sql`, with runner `public/crm/api/run-migration-1170-charlie.php`. Tables:
  - `charlie_items`: `item_key` UNIQUE, head, kind, text, url, priority, first_seen, last_seen, resolved_at, opened_at, dismissed_at, snoozed_until, times_top.
  - `charlie_briefs`: `brief_date` UNIQUE, user_id, payload TEXT, emailed_at, opened_at.
  - `charlie_prefs`: kind PK, score, decisions, muted.
  - `charlie_questions`: same shape as `penny_questions`.
  - TEXT, not JSON (MySQL 5.7).
- `app/Modules/ChiefOfStaff/Services/` — all `require_once`, global namespace:
  - `CharlieBriefService.php`: collects heads with `is_file`/`class_exists` guards and a try/catch per head.
  - `PennyBriefAdapter.php`
  - `HouseBriefAdapter.php`: Work Queue critical lane.
  - `CharlieRankService.php`: pure scoring plus learning.
  - `CharlieBadgeService.php`
  - `CharlieBrainService.php`
  - `CharlieQuestionService.php`
  - `CharlieBriefEmail.php`: pure HTML render.
- `app/Modules/ChiefOfStaff/Api/charlie.php`, with shim `public/crm/api/charlie.php`.
  - GET `?mode=today|brief|questions`
  - POST `{mode: open|dismiss|snooze|answer}` with CSRF
  - Calls `session_write_close()` right after auth.
- `app/Modules/ChiefOfStaff/Cron/charlie_morning_brief.php`, shim `public/crm/cron/charlie_morning_brief.php`, and a registry row (key `charlie_morning_brief`).
- `public/crm/includes/charlie-card.php` and `public/crm/js/charlie-card.js`. The card loads asynchronously, so the dashboard never waits on four heads.
- CSS subsection "CHARLIE — CHIEF OF STAFF" after the deck section. `.mw-` prefix, `--mw-*` tokens only.
- Tests in `tests/Unit/ChiefOfStaff/*`, plus entries in `tests/bootstrap.php`.

## 4. Ranking, learning, badges, brain, questions

**Score.** For each item:

`score = P[priority] × pref[kind] × age_boost × money_boost`

- `P` = 1 → 100, 2 → 40, 3 → 15.
- `pref[kind]` starts at 1.0 and is learned. It stays between 0.25 and 4.
- `age_boost` = 1 + 0.1 × days the item has stayed open, capped at 2.
- `money_boost` = 1 + log10(1 + value/100), only when the head supplies a value.
- **The one thing** is the highest score that isn't snoozed or muted.

**What Charlie learns.** The order Tim clears the 7 am items in:
- When Tim resolves item A while B (from the same brief) is still open, A's kind gains and B's kind loses. This is a small Elo-style step, K = 0.08.
- Tim's own clicks from the card count as "opened". Items that vanish from a head's brief count as "resolved".
- Dismiss counts as a strong loss.
- Three dismissals of one kind → a question.

**Badges.** Earned from Tim's real behaviour, and lost again when Charlie slips:
- **Called it:** Tim acted first on Charlie's pick on 5 of the last 7 days.
- **Clean sweep:** every brief item resolved the same day.
- **Early bird:** brief opened before 8 am, 5 days running.
- **Tuned in:** 10 kinds with 5 or more decisions each.

**Brain units, counted from a baseline like Penny's:**
- kinds whose preference moved more than 0.25 from 1.0
- mute rules Tim taught
- questions answered
- badges earned
- heads connected

**Questions.**
- When the top two items are within 10% of each other with no learned preference: "Hey Tim — which comes first: X or Y?"
- After repeated dismissals: "Want me to stop showing ___?"

## 5. Tests and verification

- **Unit (pure):** ranking and tie-breaks, the Elo update and its clamps, badge compute, brain combine, the Penny adapter mapping (stats array → items), an absent or throwing head gives no fatal, email HTML escaping, the cron's 07:xx Vancouver gate plus already-sent guard.
- `/Users/timschofield/Projects/mowology-crm/vendor/bin/phpunit` must stay all-green.
- Render the card locally with a stubbed DB (memory `feedback_render_crm_page_without_local_db`) before any deploy.
- After a deploy: the page renders to `</html>`, the API responds, the cron row shows a real "Last run".

## 6. Decisions for Tim

1. **Brief delivery.** Dashboard only, or dashboard plus a 7 am email (to which address)? An SMS nudge ("your brief is in your email", no URL) is optional.
2. Who sees Charlie: only Tim, or every `expenses.approve` user?
3. Should the brief go out on weekends?
4. Should Charlie own Work-Queue items that no head owns (overdue invoices, unbilled visits)?

## Contract pressure-test (for the lead)

- **Add optional item fields:**
  - `key`: a stable id, e.g. `sam:quote:123`. Without it Charlie can't learn or de-duplicate. Required, in my view.
  - `kind`
  - `value` (dollars)
  - `since` (date)
- `brief()` must be read-only and cheap: no AI calls, no `scan()`/`prepare()`, under 300 ms.
- Allow `head` = `house` for unowned items.
- **Deck merge risk.** Four branches editing the same `$__team` array will conflict. Proposal: the lead changes the deck once to skip any slug whose `<slug>-card.php` exists, and each head ships only its own include.
