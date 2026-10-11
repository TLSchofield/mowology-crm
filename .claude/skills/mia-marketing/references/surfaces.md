# iPhone, Android and web — keeping Mia the same everywhere

## How the three relate
- **Web** (desktop + phone browser) — Mia's card on the dashboard:
  `public/crm/includes/mia-card.php` + `public/crm/js/mia-card.js` (suggestions, campaign, email
  hub) and `public/crm/js/mia-channels.js` (reviews, Google post, social, website, listings).
  API: `app/Modules/Marketing/Api/mia.php` and `app/Modules/Marketing/Api/mia-channels.php`
  (shims in `public/crm/api/`). Other screens: `public/crm/marketing/social.php`,
  `public/crm/marketing/listings.php`, `public/crm/marketing/campaigns.php`,
  `public/crm/marketing/recommendations.php`, Media Library "Mia's tags" tab.
- **Android** (crew app, Capacitor) — shows the same web pages. A web change IS an Android change
  after deploy.
- **iPhone** (SwiftUI, `ios/MowologyCRM`) — Team tab, shared `HeadCardView` / `HeadCardViewModel`
  for Mia, calling `app/Modules/Team/Api/team-mobile.php` (`?mode=brief&head=mia`, `gbp_post`).

## The rules
1. **Logic lives in one server service** (SKILL.md "One place for each job"). The iPhone's Google
   post buttons call `MiaChannelsService::decidePost()` — exactly the web's `post_decide`.
2. **Every Mia feature has a row below.** Changing one surface means updating the other in the
   same piece of work, or writing the gap here.
3. **iPhone needs a build** (bump MARKETING_VERSION, push `feature/language` → TestFlight);
   web/Android need a deploy (lftp, OPcache reset). Server first, then the app.

## Parity table (checked 2026-10-11)

| Feature | iPhone | Web / Android | Server (one place) | Gap |
|---|---|---|---|---|
| Mia's items (Action Board column) | list with Open / Not now; Open goes to the web | Action Board + card | `MiaDeskService::brief()` via `TeamCardService` | — |
| One-to-one suggestions (edit, Send, Skip with reason, Snooze) | not built — items open the web | card carousel | `MiaDeskService::decide()` | iPhone (opens the web card) |
| Campaign proposal + approval | not built | card proposal box, two-tap approve | `MiaCampaignService::approve()` | **planned gap**: wrap `mia.php` `campaign` / `campaign_decide` in team-mobile, test-send first (Tech-Debt-Map 2026-10-10) |
| Email hub (watering stage, next on calendar, running, what worked) | not shown | card | `MiaEmailHubService` | iPhone |
| Weekly Google post: publish (live only) / I posted it / skip | yes (`gbp_post`) | yes (`post_decide`) | `MiaChannelsService::decidePost()`, `GbpService::mode()` | none in behaviour |
| Google review replies (paste, draft, copy, post) | not built | card | `MiaChannelsService`, `ReviewReplyDrafter` | iPhone |
| Social drafts review + publish | not built | Social Post editor | `SocialDraftPipeline`, `SocialPublisher` | iPhone |
| Reconnect Facebook banner | not shown | `social.php` banner + card | `SocialConnectionState` | iPhone (Charlie's brief item still reaches it) |
| Website watch, listings, media tags | not shown | card, listings page, media library | `WebsiteWatchService`, `ListingsService`, `MediaTagService` | iPhone (desktop jobs; acceptable) |
| "Move to…" on a routed message (to Mia) | yes | yes | InboundRouteService (required by team-mobile, but its file is not on this branch — unverified) | — |

When you change a row, change the date in this file's heading.
