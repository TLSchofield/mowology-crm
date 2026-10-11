# iPhone, Android and web — keeping Yui the same everywhere

## How the three relate
- **Web** (desktop + phone browser) — Yui's card on the dashboard deck
  (`public/crm/includes/yui-card.php` + `public/crm/js/yui-card.js`, API `app/Modules/Comms/Api/yui.php`
  via `public/crm/api/yui.php`).
- **Android** (Capacitor) — shows the same web pages; a web change is an Android change after deploy.
- **iPhone** (SwiftUI) — Team tab: `ios/MowologyCRM/MowologyCRM/Features/Team/TeamView.swift` builds a
  `HeadCardViewModel(head: "yui")` rendered by `HeadCardView.swift`, served by
  `app/Modules/Team/Api/team-mobile.php` (`mode=brief&head=yui`, `move`, `done`).

## The rules
1. Logic lives in one server service (SKILL.md "One place for each job"); pages and endpoints call it.
2. Every Yui feature has a row below. Changing one surface means changing the others in the same
   piece of work, or writing the gap here.
3. iPhone needs a build (bump MARKETING_VERSION, push `feature/language`); web/Android need a deploy
   (lftp + OPcache reset). Server first, then the app.

## Parity table (checked 2026-10-11)

| Feature | iPhone | Web / Android | Server (one place) | Gap |
|---|---|---|---|---|
| Yui's items (inbox, promises, accounts, renewals, arrears) | top items of her Action Board column (≤3) | all five sections on her card | `YuiDeskService::desk()` / `YuiDeskService::brief()` | iPhone shows only the brief |
| Draft a reply (Claude) | no | "Draft" → `yui.php` mode `draft` | `YuiDraftService::draftReply()` | iPhone by design (drafts stay on web) |
| Send email / text | no — opens the client | edit + Send (email or SMS) | `YuiDraftService::send()` | iPhone by design |
| Text rule check | — | client-side mirror in `yui-card.js` | `YuiRules::smsProblems()` | two copies of the rule |
| Handled | not now / done (team-mobile `act`, `done`) | "Handled" → mode `handled` | `YuiDraftService::handled()` | — |
| "Move to…" another head | menu in `HeadCardView.swift` → team-mobile `move` | CSS only in this tree; the select lives in `action-board.js` on branch feature/route-billing-to-penny | InboundRouteService (not in this tree) | web JS missing here — check live before deploying `action-board.js` |
| Clues (strata, roles, details) | unverified | under her card (`clues-card.js`, `app/Modules/Comms/Api/clues.php`) | `ClueService` | iPhone unverified |
| Badges / brain | brain on the face row (team-mobile) | photo, badges, brain on card | `YuiBadgeService::badges()`, `YuiBrainService::learned()` | — |
| Customer texts in the history | via `sales_messages` | via `sales_messages` | `TextBridgeService::ingest()` | — |
| Push notifications | APNs (`ApnsService::send()`) | Android FCM (`FcmService::send()`), status unverified | `PushDispatcher::drainQueue()` | Yui sends none yet |

When you change a row, change the date in this file's heading.
