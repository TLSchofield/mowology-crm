# Mia — channels and the media library

Branch `feature/mia-channels` (base `origin/feature/yui-comms-head` @ `650578d7`), 2026-10-06.

Mia now looks after the public channels as well as email: Google Business Profile (GBP), social posts, the website (Search Console), business listings, and the photo library those channels draw from. **Mia drafts. Tim approves.** Nothing is posted publicly without Tim's click. Until Google approves GBP API access, Google is "drafts only": Tim copies each draft and pastes it into Google.

Renders (made-up data, headless Chrome + SQLite stub harness):
`docs/crm/renders/mia-channels.jpg`, `docs/crm/renders/listings.jpg` and `docs/crm/renders/media-tags.jpg`.

---

## Tim's to-do list

1. **Run migrations 1200, 1201 and 1202** using the CRM migration runner. Then reset OPcache (`/crm/api/opcache-reset.php`).
   - If `contacts.photo_optout` already exists on the server, the last statement of 1202 fails with "Duplicate column". That is harmless.
2. **Reconnect Facebook.** Go to CRM → Social Posts → Social Accounts → **Reconnect**, or open `/crm/api/social/accounts.php?action=oauth-init&platform=facebook`.
   - Tick the Page **and** the Instagram account.
   - This re-encrypts the page tokens under the current `SOCIAL_ENCRYPTION_KEY`. Facebook and Instagram have posted nothing since 2026-06-18.
3. **Request GBP API access** at <https://support.google.com/business/contact/api_default> and choose **"Application for Basic API Access"**. Use the Google Cloud project that holds the CRM's `GOOGLE_CLIENT_ID`.
   - Google's prerequisites: the profile must have been verified and active for 60+ days, and it must list a website.
   - You can see approval when the Business Profile API quota in Cloud Console shows **300 QPM** instead of 0.
4. **When Google approves:**
   - In that project, enable the *My Business Account Management*, *My Business Business Information* and *Google My Business* APIs.
   - Tick **"Google has approved API access"** on `/crm/marketing/listings.php`.
5. **Connect GBP:** open `/crm/api/social/accounts.php?action=oauth-init&platform=gbp` and pick the Mowology location. This uses the existing OAuth flow, and `GOOGLE_REDIRECT_URI` must be registered.
6. **Search Console.** The CRM's existing OAuth connection (`gsc_properties`) is used first, so nothing is needed if `/crm/gsc/` is syncing. If it isn't, there are two options:
   - reconnect at `/crm/gsc/connect.php`; or
   - add the CRM service account as a **Restricted** user on `sc-domain:mowology.ca` (Search Console → Settings → Users and permissions → Add user). The existing account is `mowology-vision@mowology-476119.iam.gserviceaccount.com`, from `GOOGLE_VISION_CREDENTIALS`. Optionally, you can point a dedicated key file at it with `define('GSC_SERVICE_ACCOUNT_JSON', '/home/mowology/public_html/app_config/<file>.json');` in `secrets.php`.
7. **Schedule the two cPanel crons** (lines below).
8. **Claim each listing** on `/crm/marketing/listings.php`:
   - Apple Business Connect, Bing Places, Nextdoor, Houzz, Yelp (claim it), LinkedIn company page, CHOA, BCLNA, YP.ca, Canada411, HomeStars (optional) and BBB (optional).
   - For each one, open **Update**, enter what the listing shows and save. The page flags any name, address, phone or website that differs from Settings → Business.
9. **GBP Place ID** (still owed from SEO Phase 0): copy the `ChIJ…` Place ID from the GBP dashboard or the Place ID Finder. You can then give `SITE_REVIEW_URL` / schema a `place_id` review link. Mia does not need it.
10. **Optional — vision tagging:** `ANTHROPIC_API_KEY` is already in `secrets.php`. The daily cap is `ops_settings.media_vision_daily_cap` (default 40).
    - Until the vision pass has run, **no photo is auto-picked for public use**, because privacy is unknown.

### cPanel cron lines

```
10 6 * * 1  /usr/local/bin/php /home/mowology/public_html/app/Modules/Marketing/Cron/mia_channels_weekly.php
40 5 * * *  /usr/local/bin/php /home/mowology/public_html/app/Modules/Marketing/Cron/mia_media_tags.php
```

Both are in the Cron Jobs registry (`/crm/database_appstack.php`) and can be run from there. Mia's card also triggers the weekly preparation lazily (at most once a week), so drafts appear even before the cron is scheduled.

### Secrets (`public/app_config/secrets.php` — never in git)

| Constant | Status | Used by |
|---|---|---|
| `SOCIAL_ENCRYPTION_KEY` | existing | Facebook/Instagram/GBP token encryption |
| `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI` | existing | GBP OAuth and the GSC token refresh |
| `APP_ENCRYPTION_KEY` | existing | GSC tokens (`gsc_properties`) |
| `META_GRAPH_VERSION` | existing, optional | Overrides the Graph pin (`v23.0`) without a deploy |
| `ANTHROPIC_API_KEY` | existing | Vision tagging |
| `GOOGLE_VISION_CREDENTIALS` | existing | Fallback GSC service-account key |
| `GSC_SERVICE_ACCOUNT_JSON` | **new, optional** | Path to a service-account key for Search Console |

---

## What was built

### Facebook / Instagram (verified in code)
- Most of the 2026-09-25 audit fixes were already on the base branch:
  - the Graph version is one constant, `MetaService::graphVersion()` = `v23.0`, overridable;
  - Instagram uses `views`/`reach`, not `impressions`;
  - `SocialEncryption` separates "can't decrypt" from "nothing stored".
- New:
  - `MetaService::INSTAGRAM_METRICS` and `mapInstagramInsights()` (pure, tested). The metric names were checked against Meta's Instagram media-insights docs: `impressions` is deprecated for media created after 2024-07-02, and `views` replaces it.
  - A test fails if any PHP/JS file hard-codes `facebook.com/vNN.N`.
  - `SocialConnectionState` turns `social_accounts.meta_json.health` into one answer: **"Reconnect Facebook"**. This appears as a red banner on `/crm/marketing/social.php` and in Mia's Channels section, and as a priority-1 item in Charlie's brief until it's fixed. It never says "no page token".

### Google Business Profile — `GbpService`
- Builds on `GoogleBusinessService`, which already has the OAuth connect/callback and token storage. Tokens are encrypted under the current key; an undecryptable token means "reconnect" and is never retried with an old key.
- Methods: `listReviews`, `replyToReview` (PUT `…/reviews/{id}/reply`), `createLocalPost`, `uploadPhoto` (POST `…/media` with `sourceUrl`), `businessInfo` (Business Information v1, `readMask`), `importReviews`.
- `mode()` returns `live` only when **approved + connected + token readable**. Otherwise it returns `drafts_only` with the setup steps.
- Review replies (`ReviewReplyDrafter`) follow Tim's voice:
  - no exclamation marks;
  - thank the reviewer by first name;
  - name the work, taken from the review's own words;
  - at most 60 words, signed "Tim";
  - low ratings get an apology and the office phone from `business_settings`.
- Reviews are pasted in (or imported when live) into `gbp_reviews`. On the card each review has Copy / I posted it / Post reply to Google (live only, two taps) / No reply needed.
- Weekly Google post: `MiaSeasonThemes` picks the theme and `MediaPickerService` picks a safe 4:3 photo.
  - The text never contains a phone number or a link, because Google rejects those. The CTA button carries them instead.

### Season themes — `MiaSeasonThemes` (implements `MiaThemeSource`)

| When | Themes |
|---|---|
| Jan | Spring early-bird |
| Feb–Mar | Spring cleanup, lime and aeration |
| Feb 1–Mar 14 | Hedges before nesting (BC Wildlife Act, nesting Mar 15–Aug 15) |
| Apr–May | Aeration/overseed (soil 10–15 °C); moss, beds and mulch |
| Jun–Aug | Summer lawn care. It never tells anyone to water a lawn; a dormant lawn is fine. |
| Aug 15–Sep 15 | Post-nesting hedges |
| Sep 15–Oct 15 | Fall aeration and top-dress |
| Oct–Nov | Bulbs; irrigation blow-outs |
| Oct–Dec | Leaf cleanup (city collection; don't blow leaves into the street) |
| Jun–Sep | Snow/salt contracts for property managers (sign before Oct 1) |

- Picking order: the most time-sensitive theme first, skipping themes used in the last 3 weeks, nudged by what performed well (`MiaSocialService::rankThemes`).
- **The calendar plugs in here.** Implement `MiaThemeSource::themesFor(DateTimeImmutable): array` and pass it to `new MiaChannelsService($pdo, $source)`.

### Social
- Each Monday Mia drafts up to 2 posts through the existing `SocialDraftPipeline`.
- Sources: visits completed in the last 21 days with a before **and** an after photo whose service fits a theme in season.
- **Only visits whose photos passed the vision privacy check and whose client hasn't opted out are used.**
- Captions are one honest sentence in Tim's voice with at most 3 hashtags. Posts stay `draft` in `social_posts`: Tim reviews them in the Social Post editor, and the existing publisher posts them once Facebook is reconnected.
- Engagement from `social_metrics_daily` feeds back into theme choice and photo choice.

### Website watcher — `WebsiteWatchService` + `WebsiteWatchRules`
- Each week it compares the last 28 days with the 28 before, allowing for Search Console's 3-day lag.
- The report covers:
  - top queries;
  - pages that lost ≥30% (and ≥3) of their clicks;
  - queries with ≥100 impressions on positions 1–15 whose CTR is under half the usual CTR for that position, each with a title idea;
  - service pages with no impressions;
  - pages for the current `seo_seasons` season that haven't been edited since 30 days before the season started.
- Every suggestion carries **Open in CMS**: the page editor when the page is a CMS page, otherwise the page list.
- It only suggests changes and never edits a page.

### Listings — `/crm/marketing/listings.php` (Growth → Listings) — `ListingsService`
- The master NAP comes from `business_settings`, plus hours from `ops_settings.business_hours` and categories from `ops_settings.listings_categories`.
- For each site it tracks status, profile URL, review count/rating, last checked, and what the listing shows. Comparisons are normalised for case, `&`/"and", Ltd/Inc, street abbreviations, postal-code spacing, phone digits and `www.`.
- Seeded profile URLs come from the site's `sameAs` list; review counts are as of 2026-09-28.
- In the first week of each month Charlie gets "Check Houzz and Yelp details match." (priority 3).

### Media library — `MediaTagService`, `MediaPickerService`
- Tags are namespaced in `media_assets.tags_json` and written unescaped (`"service/x"`):
  - `service/` comes from the plan's line items, else the service type;
  - `season/` comes from the capture date;
  - `stage/` and `pair/<visit>` link a before and an after of the same visit;
  - `area/` is coarse, from the postal FSA or lat/lng, **never the address**;
  - `type/home|strata|commercial`;
  - `subject/`, `quality/1-5` and `privacy/ok|blur-needed` come from the vision pass (claude-sonnet-5-5, json_schema output). Faces, children, house numbers, plates or an identifiable street front all mean `blur-needed`;
  - `consent/ok|no` comes from `contacts.photo_optout`. Tim's rule: use them all unless the client opted out;
  - `use/hero` is Tim's star;
  - `used/<yyyy-mm>-<channel>` is added on each use.
- Hooks:
  - crew uploads (`/crm/api/visit-photo-upload.php`) copy the photo into the library and tag it;
  - `MediaUploadService` uploads are tagged too;
  - both hooks are wrapped in try/catch, so an upload never fails because of tagging;
  - the daily cron backfills, runs the vision pass and learns.
- Learning: `media_usage` records which photo each post or campaign used and what it earned. `media_tag_scores` keeps a weight per service|subject|stage combination.
- UI:
  - Media Library → **Mia's tags** tab shows tags, a privacy badge and a hero star, with filters for service, season, stage and hero;
  - the contact page has an **"Opt this client out of photos"** button.
- Otto gets "completed with no photos" items (priority 3, the last 7 days).

#### `MediaPickerService` — stable interface (for the email campaign work)

```php
require_once APP_ROOT . '/Modules/Marketing/Services/MediaPickerService.php';
$picker = new MediaPickerService($pdo);
$picks  = $picker->pick(?string $service, ?string $season, string $channel, int $n = 1);
// $channel: email | instagram | facebook | gbp | web | social
// each pick: ['kind' => 'pair'|'single', 'media_id' => int, 'pair_id' => ?int, 'tags' => string[], 'score' => float,
//             'image'  => ['media_id', 'url', 'width', 'height'],   // the after (or the single photo)
//             'before' => ?variant, 'after' => ?variant]             // set for pairs
$picker->markUsed($pick, 'email', 'campaign', $campaignId);         // once the pick is actually used
```

- **Hard rules:**
  - privacy must be `ok` (vision-checked) and consent must not be `no`;
  - quality must be ≥ 4;
  - nothing used in the last 90 days is picked.
- **Ranking:** pairs first, then hero, service match, season match, quality, and the learned combination score.
- **Variants** are made lazily with GD and recorded in `media_variants` as `mia_<channel>`:
  - email: 1200 px wide;
  - Instagram/social: 1080 × 1080;
  - GBP/Facebook: 1200 × 900 (4:3).
- To credit clicks to the photos a campaign used: `UPDATE media_usage SET clicks = ? WHERE ref_type = 'campaign' AND ref_id = ?`. The daily cron folds this into the scores.

### Charlie's brief (from Mia)

| Item | Priority |
|---|---|
| Review waiting for a reply | 1 |
| Reconnect Facebook (until it's fixed) | 1 |
| Google post draft ready | 2 |
| Social drafts waiting | 2 |
| Weekly website insight | 3 |
| Monthly listings check | 3 |

These are merged in `MiaDeskService::brief()` (a small, additive block).

## Files

| Kind | Files |
|---|---|
| New services | `app/Modules/Marketing/Services/{MiaSeasonThemes, ReviewReplyDrafter, WebsiteWatchRules, WebsiteWatchService, ListingsService, MiaSocialService, MiaChannelsService, MediaTagRules, MediaTagService, MediaPickerService}.php`; `app/Modules/Social/Services/{GbpService, SocialConnectionState}.php` |
| API | `app/Modules/Marketing/Api/{mia-channels, media-tags}.php` with shims in `public/crm/api/` |
| Cron | `app/Modules/Marketing/Cron/{mia_channels_weekly, mia_media_tags}.php` with shims in `public/crm/cron/` |
| UI | `public/crm/marketing/listings.php`, `public/crm/js/{mia-channels, media-tags}.js`, `public/crm/includes/{media-tags-panel, photo-optout-control}.php`; CSS appended to `mowology-brand.css` |
| Small edits | `mia-card.php` (one container + one script tag), `MiaDeskService::brief()`, `OpsDeskService::brief()`, `MetaService` (metric constant), `social.php` (banner), `appstack_sidebar.php` (Listings), `database_appstack.php` (2 cron entries), `visit-photo-upload.php` + `MediaUploadService.php` (tag hooks), `cms-media_appstack.php` (tab), `clients_appstack.php` (opt-out button include) |
| Migrations | `1200_mia_channels.sql`, `1201_marketing_listings.sql` and `1202_media_tags.sql`, in both `database/migrations/` and `public/database/migrations/` |
| Tests | `tests/Unit/Marketing/{MiaChannelsTest, MediaTagsTest}.php` |

## Not verified

- All live Google/Meta/Anthropic calls are untested. These are the GBP endpoints, the Search Console queries, the vision request, and GBP `localPosts` with a photo `sourceUrl`.
- Production `public/` may be ahead of the repo. **`cmp` each edited existing file against prod before deploying it:** `visit-photo-upload.php`, `clients_appstack.php`, `cms-media_appstack.php`, `social.php`, `appstack_sidebar.php`, `database_appstack.php`, `MediaUploadService.php` and `OpsDeskService.php`.
- `media_assets` column names on prod (for example `service_type`, `captured_at` and `image_width`) were read from migrations, not from the live schema. The insert falls back to the older shape that `SocialDraftPipeline` uses.
- `contacts.photo_optout` and `properties.contact_id` / `job_plans.contact_id` on prod: lookups are guarded and fall back to "not opted out" if the columns are missing.
