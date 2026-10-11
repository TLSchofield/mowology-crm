---
name: mia-marketing
description: Use BEFORE touching anything marketing-related in the Mowology CRM / Ittai — Mia (the marketing head), her card, suggestions (reconnect, seasonal, quiet PM, referral, consent ask), the email campaign calendar, campaign approval and sending, reminders/last calls, send times, the watering-stage check, consent (CASL) and unsubscribes, review requests and Google review replies, Google Business Profile posts, Facebook/Instagram posts and the social publisher, the media library tags and photo picks, Search Console / SEO / the website watcher, CMS pages and blog articles, business listings. Also when Tim asks "why didn't this post", "who got that email", "is Facebook working", "draft a post/review reply/campaign", or reports wrong copy. Holds how Mia is allowed to act, the copy and consent rules, the single source of truth for each job, iPhone vs web parity, and the traps already hit.
covers:
  - app/Modules/Marketing/*
  - app/Modules/Social/*
  - app/Modules/CMS/*
  - app/Modules/Reviews/*
  - app/Modules/Consent/*
  - app/Modules/Referrals/*
  - app/Modules/Team/Api/team-mobile.php
  - public/crm/marketing/*
  - public/crm/gsc/*
  - public/crm/includes/mia-card.php
  - public/crm/js/mia-card.js
  - public/crm/js/mia-channels.js
  - public/crm/js/media-tags.js
  - public/crm/api/track-open.php
  - public/crm/api/track-click.php
  - ios/MowologyCRM/MowologyCRM/Features/Team/HeadCardView.swift
  - ios/MowologyCRM/MowologyCRM/Features/Team/HeadCardViewModel.swift
metadata:
  version: 1.0.0
  created: 2026-10-11
  owner: Tim Schofield (Mowology) — Mia drafts, Tim approves every public post and every send
---

# Mia — marketing for Mowology (and Ittai)

Mia is the CRM's marketing head (job title "Scout"; HubSpot-style): the email campaign calendar,
keeping past customers and property managers warm, Google Business Profile, social posts, the
website (Search Console, CMS), listings and the photo library. Sam owns open quotes, new leads and
any reply that says yes; Yui owns other one-to-one conversations. Read the reference that matches
the task:

| Task | Read |
|---|---|
| What Mia may draft, send, post or learn; consent; copy rules; approvals | `references/operating-rules.md` |
| iPhone vs web/Android — what each shows, where the gaps are | `references/surfaces.md` |
| Traps already hit (read before ANY change to sending, posting or tokens) | `references/traps.md` |
| Background: plan and as-built notes | `docs/crm/mia-plan.md`, `docs/crm/mia-email-hub.md`, `docs/crm/mia-channels.md` |
| SEO plan, keyword map | `docs/marketing/seo-audit-and-plan-2026-09.md` |
| Brand voice, standing answers | `.agents/product-marketing-context.md` |

**Location rules** (taxes, payroll, CASL/SMS, bylaws, licences — by country/province/city) live in the shared
`regions` skill (`.claude/skills/regions/`), not here. Read the file for the place the job is in.

## Before and after every change (Obsidian + Graphify)

**Before** writing or changing anything marketing-related:
1. Read the Obsidian notes —
   `~/Library/Mobile Documents/iCloud~md~obsidian/Documents/30-PROJECTS/Active/Mowology-CRM/Architecture/`
   `grep -n -i "<area>" Known-Failure-Patterns.md Tech-Debt-Map.md Decision-Log.md`
   (meta, facebook, social, publisher, gbp, campaign, seo, canonical, mia…). The Meta and
   social-publisher sections in Known-Failure-Patterns are required reading before touching tokens
   or the publisher cron.
2. Graph what you are about to touch: `python3 ~/.claude/tools/graphify.py touches <Class>`.
   Mia's services are read by Charlie, Sam, Yui and the iPhone (checked 2026-10-11):
   `MiaDeskService` → Charlie's brief, `TeamCardService`, team-mobile;
   `MiaCampaignService` → Sam's desk and unclaimed replies, Yui, contact team panel;
   `ConsentLedgerService` → Mia, Sam, Yui, field observations;
   `ReviewRequestService` → `pow-actions.php` and `VisitLifecycleService::updateVisitStatus()`.
3. Before deploying an existing `public/` file, `cmp` it against production (prod is often ahead —
   `mia-card.*`, `mowology-brand.css`, `database_appstack.php` and `social.php` are edited by other
   sessions). Never redeploy `master.css` from a branch (it is a flattened bundle on prod).

**After** the change:
1. A mistake found or a rule learned → `references/traps.md` (this skill) and
   Known-Failure-Patterns.md (Obsidian) the same day; a "why it's built this way" → Decision-Log.
2. A Mia feature changed on one surface → update `references/surfaces.md` (and the other surface,
   or record the gap there).
3. Names, paths or rules in this skill changed → update the skill in the same commit.
4. `/obsidian` (bare) at the end of the session writes the rest back.

## The rules that are never broken

1. **Mia drafts; Tim approves.** No email, campaign, Google post, review reply or social post goes
   out without Tim's click (two-tap "armed" buttons on the web). Approving a whole campaign covers
   its reminder and last call; nothing else is implied.
2. **No consent, no send — checked at send time.** `ConsentLedgerService::allows()` in the send
   path, unsubscribes always win, and every marketing email names the sender (company + postal
   address) and carries a working unsubscribe link (`MiaWording::compose()` refuses otherwise).
   This is CASL; there is no override.
3. **Marketing is email only.** Marketing texts are off (a text cannot carry an unsubscribe link).
   Service texts follow the carrier rules: 160 characters, no URLs or domains, no special
   characters, tell them to check their email, include (778) 846-9273 — `MiaWording::smsProblems()`.
   Texts go through `sendSms()` (native `mail()`), never PHPMailer.
4. **Tim's voice.** Short plain sentences, first person, signed Tim. No exclamation marks, no hype
   words (solution, leverage, elevate, exceptional), no emoji, no discounts slipped into a campaign,
   no rewards for reviews, never tell anyone to water a lawn during restrictions. The checkers
   (`MiaCampaignService::problems()`, `ReviewReplyDrafter::violations()`) are the rules; extend
   them rather than re-stating rules elsewhere.
5. **Never claim a channel works without checking it this session.** A "Verified" badge, a
   queued post or a quiet cron is not proof. Cite the query, the health check or the live page.
   Facebook/Instagram published nothing from 2026-06-18 to at least 2026-10-06 while looking
   healthy (see `references/traps.md`).
6. **No client's address, face, house number or plate in public content.** Photos are used only
   when the vision check says `privacy/ok` and the client has not opted out
   (`MediaPickerService::pick()` enforces it).

## One place for each job (improve it there, and every screen and app gets it)

| Job | The one place | Used by |
|---|---|---|
| Who Mia suggests contacting (reconnect, seasonal, quiet PM, referral, consent ask) | `MiaFinder` (`app/Modules/Marketing/Services/MiaFinder.php`) | `MiaDeskService::prepare()` |
| Sending a one-to-one suggestion (consent + Sam re-check, `communication_log`) | `MiaDeskService::decide()` | web card |
| Mia's items for Charlie / the Action Board / iPhone | `MiaDeskService::brief()` | Charlie, `TeamCardService`, team-mobile |
| Suggestion wording, SMS check, CASL wrapper | `MiaWording` | desk, campaigns |
| Consent per contact and channel | `ConsentLedgerService::allows()` (backfill: `ConsentLedgerService::refresh()`) | Mia, Sam, Yui, sequences |
| Campaign calendar (dates, audiences, conditions) | `mia_calendar` table + `MiaCalendar` (seed must match `MiaCalendar::defaults()`) | `MiaCampaignService` |
| Proposing / approving a campaign; copy checks | `MiaCampaignService::propose()`, `MiaCampaignService::approve()`, `MiaCampaignService::problems()` | card, daily cron |
| Reminders and last calls | `MiaSequenceService::run()` | `mia_email_daily` cron |
| Best send time per person | `SendTimeService` | campaign approval |
| Watering stage (Metro Vancouver) | `WaterRestrictionService::refresh()` | daily cron, copy checks |
| Daily email-hub run | `MiaEmailHubService::daily()` | `app/Modules/Marketing/Cron/mia_email_daily.php` |
| Actually sending queued campaign email | `app/Modules/Marketing/Cron/campaign_sender.php` (re-checks `canSendMarketing()`) | — |
| Post-visit review request | `ReviewRequestService::maybeSend()` | `pow-actions.php`, `VisitLifecycleService` |
| Google review replies (draft + rules) | `ReviewReplyDrafter::draft()` | `MiaChannelsService` |
| Google Business Profile (live vs drafts only, API calls) | `GbpService::mode()` and `GbpService` | channels, team-mobile, listings |
| Weekly Google post / reviews / channel summary | `MiaChannelsService` (`MiaChannelsService::decidePost()`) | web card, iPhone |
| Season themes for posts | `MiaSeasonThemes` | channels |
| Social drafts from crew photos | `MiaSocialService::draftWeek()` → `SocialDraftPipeline` | weekly cron |
| Publishing social posts | `SocialPublisher::enqueuePost()` + `app/Modules/Social/Cron/social_publisher.php` | editor, cron |
| Meta tokens and health | `SocialEncryption::decrypt()`, `SocialAccountHealth::sweep()`, `SocialConnectionState`, `MetaService::graphVersion()` | publisher, social page, Charlie |
| Picking a photo for any channel | `MediaPickerService::pick()` / `MediaPickerService::markUsed()` | email, GBP, social |
| Tagging photos (service, season, privacy, consent) | `MediaTagService` + `MediaTagRules` | uploads, daily cron |
| Search Console weekly watch | `WebsiteWatchService::run()` + `WebsiteWatchRules` | weekly cron |
| Listings NAP check | `ListingsService::compare()` | `public/crm/marketing/listings.php` |
| Blog article save | `ArticleService::save()` | `public/crm/api/publish-article.php` |
| Article → other channels | `ContentCascadeService::cascade()` | content engine |

**Not yet central (known):** there are two social post creation paths (wizard and editor) and the
wizard duplicates the enqueue SQL instead of calling `SocialPublisher::enqueuePost()`
(Tech-Debt-Map, 2026-09-25). `MiaDeskService` calls `sendEmail()` directly rather than
`sendCrmEmail()`. The SEO recommendation functions are loose functions in
`app/Modules/Marketing/Services/SeoFunctions.php`, not a class.

## Keeping this skill true

- `tests/Unit/Docs/HeadSkillsTest.php` fails the test suite when a file, class or function this
  skill names no longer exists, or when the skill loses its Obsidian + Graphify section or its
  `covers:` list. Fix the skill in the same commit as the code.
- At the end of any session that changed code listed in `covers:`, the Stop hook
  (`~/.claude/hooks/vault-writeback.py`) names this skill and the changed files, and sends you to
  update it.
- When a mistake is found in a send, a post or a channel's health, add it to
  `references/traps.md` the same day (date, what happened, the signature, the rule).
- Facts about outside services (Meta Graph version, GBP API access, Search Console accounts) carry
  the date they were last seen. Re-check before relying on them.
