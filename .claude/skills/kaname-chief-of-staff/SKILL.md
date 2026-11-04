---
name: kaname-chief-of-staff
description: Use BEFORE touching anything Kaname does in the Mowology CRM / Ittai — Kaname (the Chief of Staff head), the owner's morning brief, the iPhone opening screen (brain → ITTAI → strapline → Kaname's face → brief), the dashboard Kaname card and Action Board, how items are ranked and learned, the ready brief (head_brief_ready), the charlie_morning_brief cron and its email, urgent alerts, the deadlines calendar (Foreman calendar), the decision inbox and conflict rules, Ask Kaname, the House / Work Queue lines, or any head's brief() that feeds Kaname. Also when Tim asks "what's on my desk", "why is X first", "why didn't the brief show…", or reports a wrong line in the brief. Holds what Kaname may and may not do, how the brief is built, the single place for each job, the iPhone/web differences and the traps already hit.
covers:
  - app/Modules/ChiefOfStaff/*
  - app/Modules/Team/Api/team-mobile.php
  - public/crm/api/charlie.php
  - public/crm/includes/charlie-card.php
  - public/crm/includes/action-board.php
  - public/crm/foreman_calendar_appstack.php
  - public/crm/js/charlie-card.js
  - public/crm/js/charlie-inbox.js
  - public/crm/js/action-board.js
  - public/crm/js/foreman-calendar.js
  - ios/MowologyCRM/MowologyCRM/Features/Splash/*
  - ios/MowologyCRM/MowologyCRM/Features/Team/*
metadata:
  version: 1.0.0
  created: 2026-10-11
  owner: Tim Schofield (Mowology)
---

# Kaname — Chief of Staff for Mowology (and Ittai)

**Naming (2026-10-21):** the chief of staff is shown as "Kaname" (it was "Charlie"); the owner may rename them. The shown name, pronoun and photo come only from `app/Services/HeadIdentity.php` (`HeadIdentity::chiefName()` / `chief()`); JS reads `data-chief-name` printed by the page. Internal keys stay `charlie` (class names `Charlie*`, tables `charlie_*`, `?head=charlie`, `mw-charlie-*`, `charlie.jpg`, `ops_settings` keys) — never rename those, and never hard-code the shown name.

Kaname decides what reaches Tim's desk, and in what order. He does not do the heads' work: he
collects every head's brief, ranks it, learns Tim's order from what Tim deals with first, keeps
the deadlines calendar, and interrupts only for urgent things. Read the reference that matches:

| Task | Read |
|---|---|
| How a brief is built: heads → rank → view → ready copy → phone/web/email | `references/brief-pipeline.md` |
| What Kaname may do, decide, learn; owner decisions of 2026-10-11 | `references/operating-rules.md` |
| Traps already hit (read before changing the brief or the opening screen) | `references/traps.md` |
| iPhone vs Android vs web — keeping Kaname the same everywhere | `references/surfaces.md` |

**Location rules** (taxes, payroll, CASL/SMS, bylaws, licences — by country/province/city) live in the shared
`regions` skill (`.claude/skills/regions/`), not here. Read the file for the place the job is in.

## Before and after every change (Obsidian + Graphify)

**Before** changing anything in Kaname's area:
1. Read the Obsidian notes —
   `~/Library/Mobile Documents/iCloud~md~obsidian/Documents/30-PROJECTS/Active/Mowology-CRM/Architecture/`
   `Integration-Map.md` "Kaname's ready brief" first, then `grep -n -i "charlie\|brief" Known-Failure-Patterns.md
   Decision-Log.md Tech-Debt-Map.md`.
2. Graph what you are about to touch: `python3 ~/.claude/tools/graphify.py touches <Class>`.
   `CharlieDeskService` and `CharlieRankService` reach the cron, the web card, the Action Board,
   the Foreman calendar, team-mobile and Yui/Sam services — a change there changes every surface.
3. A field added to the live `?mode=brief&head=charlie` reply in `app/Modules/Team/Api/team-mobile.php`
   must also be added in `HeadBriefReadyService::buildCharlie()`, or the opening screen won't have it.
4. After hooking a service into the cron, read the next `cron_runs.summary` for
   `charlie_morning_brief` — the cron loads less than team-mobile does (see traps).

**After** the change:
1. A mistake found → `references/traps.md` (this skill) and Known-Failure-Patterns.md the same
   day; a "why it's built this way" → Decision-Log.
2. A Kaname feature changed on one surface → update `references/surfaces.md` (or record the gap).
3. Names, paths or rules in this skill changed → update the skill in the same commit
   (`tests/Unit/Docs/HeadSkillsTest.php` fails otherwise).
4. `/obsidian` (bare) at the end of the session writes the rest back.

## The rules that are never broken

1. **Kaname holds no money, filing, signing or messaging power of his own.** Deadlines carry a
   checklist and links only; inbox buttons post only to a head's own endpoint
   (`CharlieInboxService::safeActions()` drops anything not in its `ENDPOINTS` list). Ask Kaname
   is read-only.
2. **Brief lines are decisions, not status** ("Send 28 invoices? Tap"), ordered by the cost of
   waiting (money and penalty dates first), **three at most**, readable on a phone while Tim is
   away (owner, 2026-10-11). Most current lines are still status — see operating-rules.
3. **Bad news leads.** Urgent alerts, overdue deadlines, held conflicts and heads that could not be
   reached are said first (`CharlieDeskService::badNews()`). A head that failed resolves nothing.
4. **Kaname learns from the owner only, and only from what Tim did** — opening, dealing with,
   "Not now". Another admin gets a read-only ranking (team-mobile) and teaches nothing.
5. **A head's `brief()` is read-only and cheap.** Kaname calls it every 15 minutes. Never call a
   head's writing or AI-spending methods from a brief (Penny's adapter must not call `scan()` or
   `prepare()`).
6. **Never claim what the brief or the owner saw without checking** — the ready copy, the live
   build and the phone's own re-ordering can differ (see `references/brief-pipeline.md`).

## One place for each job (improve it there, and every screen and app gets it)

| Job | The one place | Used by |
|---|---|---|
| Ask every head for its brief | `CharlieBriefService::collect()` | desk, inbox, team-mobile (other admins) |
| Rank items (priority × learned weight × age × money × yes) | `CharlieRankService::score()` / `CharlieRankService::rank()` | desk, inbox, team-mobile |
| Learn Tim's order (pairwise) | `CharlieRankService::learnPair()` via `CharlieDeskService::act()` / `CharlieDeskService::sync()` | web card, Action Board, iPhone Team tab |
| Today's view (one thing, rest, heads, bad news) | `CharlieDeskService::today()` | cron, charlie.php, team-mobile, Foreman calendar, Action Board |
| Card = urgent + inbox + today | `CharlieForemanService::card()` | cron, charlie.php, team-mobile, sales-head-mobile, ready brief |
| One head's column (who owns a line) | `TeamCardService::column()` + `TeamCardService::headKey()` | team-mobile, ready brief |
| Ready brief for the opening screen | `HeadBriefReadyService::rebuildCharlie()` / `HeadBriefReadyService::get()` | cron (every run), team-mobile `ready=1` |
| Remove an acted-on item from the ready copy | `HeadBriefReadyService::drop()` via `tmDropReady()` | team-mobile act / move / done |
| Deadlines calendar and its brief lines | `DeadlineService` (+ `DeadlineRules` for dates) | brief (head 'charlie'), inbox, Foreman calendar |
| Decision inbox, batching, conflict rules | `CharlieInboxService` + `ConflictRules::apply()` | charlie.php, web card |
| Urgent alerts (failed payment ≥ $500, same-day weather) | `CharlieUrgentService::find()` + `CharlieForemanService::sendUrgent()` | cron |
| Morning email | `CharlieBriefEmail::render()` | cron |
| Ask Kaname | `CharlieAskService::ask()` (facts first: `CharlieFactAnswerer::answer()`) | iPhone Team tab; charlie.php `ask` |
| Penny's lines | `PennyBriefAdapter::fromStats()` | brief |
| Work Queue critical lane ("House") | `HouseBriefAdapter::fromWorkQueue()` | brief |
| Badges under his photo | `CharlieBadgeService::badges()` | web card |
| Kaname's words | `CharlieVoice` | desk, cron, email |

Who uses it (graphify, 2026-10-11): `CharlieForemanService` — `app/Modules/ChiefOfStaff/Api/charlie.php`,
`app/Modules/ChiefOfStaff/Cron/charlie_morning_brief.php`, `app/Modules/ChiefOfStaff/Services/HeadBriefReadyService.php`,
`app/Modules/Sales/Api/sales-head-mobile.php`, `app/Modules/Team/Api/team-mobile.php`.
`CharlieDeskService` — also `public/crm/includes/action-board.php`, `public/crm/includes/charlie-card.php`,
`public/crm/foreman_calendar_appstack.php`, `app/Modules/Comms/Services/YuiDraftService.php`.
`HeadBriefReadyService` — only the cron and team-mobile.

## Keeping this skill true

- `tests/Unit/Docs/HeadSkillsTest.php` fails the test suite when a file, class or function this
  skill names no longer exists, or when the skill loses its Obsidian + Graphify section or its
  `covers:` list. Fix the skill in the same commit as the code. Every name here was checked
  against the code on 2026-10-11.
- At the end of any session that changed code listed in `covers:`, the Stop hook
  (`~/.claude/hooks/vault-writeback.py`) names this skill and the changed files, and sends you to update it.
- When a mistake is found in the brief or Kaname's behaviour, add it to `references/traps.md`
  the same day (what happened, the signature, the rule that prevents it).
- When an "agreed, not built" item in `references/operating-rules.md` ships, move it to the built
  list and update `references/surfaces.md`.
