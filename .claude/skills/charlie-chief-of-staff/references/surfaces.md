# iPhone, Android and web — keeping Charlie the same everywhere

## How the three relate
- **Web** (desktop + phone browser) — dashboard Charlie card (`public/crm/includes/charlie-card.php`,
  `public/crm/js/charlie-card.js`, `public/crm/js/charlie-inbox.js`), Action Board
  (`public/crm/includes/action-board.php`, `public/crm/js/action-board.js`), Foreman calendar
  (`public/crm/foreman_calendar_appstack.php`, `public/crm/js/foreman-calendar.js`). All call
  `app/Modules/ChiefOfStaff/Api/charlie.php` (CSRF).
- **Android** (crew app, Capacitor) — shows the same web pages. A web change IS an Android change
  after deploy.
- **iPhone** (SwiftUI) — opening screen `ios/MowologyCRM/MowologyCRM/Features/Splash/CharlieMorningBrief.swift`
  (+ `CharlieBrainSplash.swift`), Team tab `ios/MowologyCRM/MowologyCRM/Features/Team/` (`TeamView`,
  `HeadCardView`, `HeadCardViewModel`), all through JWT `app/Modules/Team/Api/team-mobile.php`.

## The rules
1. **Logic lives in one server service** (SKILL.md "One place for each job"); pages, endpoints and
   the app only call it. team-mobile's column is `TeamCardService::column()`, written to match
   action-board.js — change both or neither.
2. **Every Charlie feature has a row below.** Changing it on one surface means the others in the
   same piece of work, or the gap written here.
3. **iPhone needs a build** (bump MARKETING_VERSION, push `feature/language` → TestFlight);
   web/Android need a deploy (lftp, OPcache reset). Server first, then the app that relies on it.

## Parity table (checked 2026-10-11)

| Feature | iPhone | Web / Android | Server (one place) | Gap |
|---|---|---|---|---|
| Opening brief (brain → ITTAI → strapline → brain flips to Charlie's face → brief) | `OpeningOverlay`, ready copy (`ready=1`), plays every open (`oncePerDay = false`) | none | `HeadBriefReadyService` | web has no opening brief (the dashboard card is the equivalent) |
| Order of brief lines | phone re-sorts: new first, then priority; 3 lines | score order (`CharlieRankService::rank()`) | `CharlieRankService` | iPhone ignores score — order can differ from the web |
| Which lines | Charlie's column only (deadlines, House) | the one thing + every head's top 3 | `CharlieDeskService::today()` | other heads' lines are not on the iPhone opening screen |
| Charlie's card items | Team tab, top 3 of Charlie's column | Charlie card + Action Board column | `TeamCardService::column()` | — |
| Open (teaches) | `act` open | `act` open | `CharlieDeskService::act()` | — |
| Not now | snooze (back tomorrow, **teaches nothing**) | "Later" = snooze; "Not today" = dismiss (teaches) | `CharlieDeskService::act()` | iPhone has no dismiss — can't teach "this matters less" |
| Deadline Done | not on iPhone | card button + Foreman calendar | `DeadlineService::mark()` | iPhone |
| Deadlines calendar (add/edit rules) | none | Foreman calendar | `DeadlineService::save()` | iPhone (desk job — acceptable) |
| Decision inbox, held messages, Override, rules | none | card + Foreman calendar | `CharlieInboxService`, `ConflictRules` | iPhone |
| "Which first?" / mute questions | none | card | `CharlieDeskService::questions()` / `CharlieDeskService::answer()` | iPhone |
| Ask Charlie | Team tab Ask box | endpoint exists (`charlie.php` `ask`); no web box found | `CharlieAskService::ask()` | web/Android has no Ask box |
| Brain | flip on face (`?mode=brains`) | brain button on the card | `TeamCardService::brain()` | — |
| Badges | none | under the photo | `CharlieBadgeService::badges()` | iPhone |
| Move to… / Done on routed messages | Team tab (`move` / `done`) | — | team-mobile → InboundRouteService | that service file is **not in this branch** — unverified |
| Morning email, urgent alerts | — | — | cron | email only, no push |

When you change a row, change the date in this file's heading.
