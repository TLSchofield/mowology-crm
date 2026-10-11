# iPhone, Android and web — keeping Sam the same everywhere

## How the three relate
- **Web** (desktop + phone browser) — Sam's card on the dashboard
  (`public/crm/includes/sam-card.php` + `public/crm/js/sam-card.js`, API
  `public/crm/api/sales-head.php` → `app/Modules/Sales/Api/sales-head.php`), and every quote
  screen (`public/crm/quotes/`).
- **Android** (crew app, Capacitor) — shows the same web pages. A web change IS an Android change
  (after deploy).
- **iPhone** (SwiftUI) — Team tab Sam card (`ios/MowologyCRM/MowologyCRM/Features/Team/SamCardView.swift`,
  `SamCardViewModel.swift`, models in `ios/MowologyCRM/MowologyCRM/Core/Models/SalesModels.swift`)
  calling the JWT endpoint `app/Modules/Sales/Api/sales-head-mobile.php`. The iPhone has **no
  quote screens** (no build, send, Closer, recipient picker).
- **Client** — `public/customer/quote.php` (token link, any browser): view, untick lines, sign.

## The rules
1. **Logic lives in one server service** (see SKILL.md "One place for each job").
   `sales-head-mobile.php` calls the same `SalesDeskService` / `SamFollowupService` /
   `SamQuestionService` methods as the web — nothing re-implemented.
2. **Every Sam feature has a row below.** Adding or changing a feature on one surface means
   updating the others in the same piece of work, or writing the gap here.
3. **iPhone needs a build** (bump MARKETING_VERSION, push `feature/language` → TestFlight);
   web/Android need a deploy (lftp, OPcache). Server first, then the app that relies on it.
4. Use `?mode=` on `/api/` endpoints, never `?action=` (the router appends its own).

## Parity table (checked 2026-10-11)

| Feature | iPhone | Web / Android | Server (one place) | Gap |
|---|---|---|---|---|
| Follow-up queue: draft, edit, send email/text, skip | yes | yes | `SalesDeskService::queue()`, `SamFollowupService::send()` | — |
| Text check (≤160, no links) | client check `SalesSMS` + server | client check in `sam-card.js` + server | `SamFollowupService::smsProblems()` | rule in three copies; server is the authority |
| Claude reply draft | yes (card + replies waiting) | yes | `SamFollowupService::draftReply()` | — |
| Replies waiting (unclaimed) | yes, with Draft reply / Send via `SamReplyCard::load()` | yes (Open) | `SalesDeskService::unclaimed()` | web has no Draft/Send on these items (unverified beyond `sam-card.js`) |
| "Handled" on a reply | `mode=handled` → `CharlieDeskService::act()` | via `/crm/api/charlie.php` act dismiss | `CharlieDeskService::act()` | — |
| "Move to…" another head | yes (admins) | not on Sam's web card | InboundRouteService — referenced by `sales-head-mobile.php` but **not in this repo** (prod only, unverified) | web; repo drift |
| Just approved, line by line ✓/✗ | yes ("Got it") | yes ("Got it") | `QuoteApprovalService::pending()` | — |
| Sam's questions (expired quotes) | yes | yes | `SamQuestionService::answer()` | — |
| New leads | yes | yes, plus "Not a lead" | `SalesDeskService::leads()` | iPhone has no "Not a lead" |
| Maybe a lead (email enquiries) | no (mobile desk sends no `maybe_leads`) | yes | `EmailLeadService::maybes()` | iPhone |
| Asked first (field photo notes → quote) | no | yes (`ask_build` / `ask_send_quote` / `ask_close`) | Field ask services | iPhone |
| Thread (emails + texts with a client) | yes | yes | `SalesDeskService::touches()` | — |
| Text bridge status | yes | yes | `TextBridgeService::status()` | — |
| "Send me a test push" | no | yes | `QuoteViewNotifier::testPush()` | iPhone (receives the push) |
| Closer price + trip/delivery lines | no | quote page (`public/crm/includes/closer-panel.php`, `public/crm/js/closer-panel.js`) | `CloserService::forQuote()`, `TripLineSuggester::forQuote()` | iPhone has no quote screen |
| Build / send quote, recipient, CC, line lock | no | `public/crm/quotes/create.php`, `public/crm/quotes/view.php` | `QuoteService`, `QuoteRecipientService` | iPhone has no quote screen |
| Client unticks lines, signs | — | `public/customer/quote.php` | `QuoteLineChoiceService::setDeclined()` | — |
| Sam's brain + badges | yes (tap face) | yes | `SamBrainService::learned()`, `SamBadgeService::badges()` | — |

When you change a row, change the date in this file's heading.
