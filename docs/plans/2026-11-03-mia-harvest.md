# Mia harvest — built 2026-11-03 (Tim: "complete them entirely"; continued in the parent chat)

Sources: small-business `social-content-engine`, `content-strategy`, `ad-manager`, `review-reputation`, `seo-ai-visibility`,
`marketing-monday`, `growth-pulse`, `shared/voice-profile.md`; the sibling `marketing` plugin (campaign-plan, content-creation,
draft-content, email-sequence, performance-report, brand-review, competitive-brief, seo-audit). Survey by a Sonnet subagent;
diff against Mia's services by a researcher; both in this chat's record.

## House rules kept over the plugin's (stricter wins)
One honest sentence per caption, at most 3 hashtags (plugin: hook/benefit/CTA + 3–5); no exclamation marks at all (plugin: "rare"
allowed); no emoji; review replies already ≤60 words; Tim's own prices are fine in a campaign (the slop test's price note is left
out there); no discounts in campaigns (already enforced).

## What shipped (file → rule)
- `VoiceProfile` — learned from the last 30 office emails (`sales_messages` outbound, cleaned of quoted history, signatures,
  HTML/CSS and Outlook class names): greeting, sign-off, average sentence length, contractions, exclamations, characteristic
  phrases (trigrams first), never-uses (watch list absent from every sample + owner list); owner edits appended with a reason.
  `check()` is the slop test: fillers/openers, superlatives and unmade promises, emoji, exclamation marks, run-on sentences,
  a price or percentage to confirm, a greeting that is not his. Cached a week in `ops_settings mia_voice_profile`
  (`MiaChannelsService::voiceProfile()`), shown on the channels card. Applied in `MiaCampaignService::problems()`.
- `ContentMix` — cadence by team size (solo 2/week + one email a month), 40/30/20/10 mix, "published / scheduled / first gap"
  opener, conflicts (two posts one day same service; a scheduled time in the past; an offer after it ends), caption and email
  shape checks (subject <50, preheader 40–90, 100–250 words, one ask). `MiaChannelsService::contentMix()` from `social_posts`.
- `MarketingWeek` — bands (leads yellow 0 to −20% / red below; reach −10%; review score 4.3 / 3.8; concentration 60–80%),
  one merged headline, exactly three actions ranked by worth with the number that justifies each, a quiet week in one line,
  failed sources named as n/a. `MiaChannelsService::week()`.
- `ReviewReplyDrafter` — template phrases added to BANNED ("sorry you feel this way", "pride ourselves", "our policy", "valued
  customer", "feedback is important to us"); a 1–2 star reply with no phone number is `no_contact`.
- `MiaChannelsService::findability()` — robots.txt blocks for GPTBot/ClaudeBot/PerplexityBot/Google-Extended/CCBot named as
  the owner's choice; llms.txt presence; cached a week. Shown as "Found by assistants?".
- `EvidenceMinimums` and `AdDiagnostics` — the ad bands (Working ≤15% off the trailing average / Watch 15–40% or frequency >3 /
  Problem >40% or 14+ days no results / No verdict), the change playbook (+20–30% at most; cut at 40% worse; fatigue = new
  creative) and the approval block in monthly dollars. **No ads account is connected, so these are not wired**; they sit
  ready, tested, for the day Meta or Google Ads is read.

## Dry run on live data (2026-11-03)
- Voice: 29 usable sent emails → opens "Hi <name>,", signs "Regards", 9.5 words a sentence, exclamation marks rare, says
  "would you like" / "we were at" / "took a few photos". The plugin's 25-word sentence rule flagged 15 of his own 30 mails → 40.
  At 40, 12 of 30 still get a note (run-on snippets, a price to confirm, one "!") — notes, not blocks.
- Calendar: 0 published in the last week, 0 scheduled, gap from this week — 11 posts sit in "publishing" and 2 failed
  (the Meta publisher has been dead since June; Mia now says so in the week page's actions).
- Week: leads 5 vs 1 green; social reach n/a (no metrics in 56 days); Google reviews n/a (none on file) — both named, not green.

## Not built, and why
- Ads wiring (no account). Quiet-customer rule in MiaFinder (Sam's `ClientRhythm` already shows them; two heads saying the
  same thing would be noise). Content-strategy brief (needs margin by service from Penny first — queued). Competitor scan
  (no source). Blog/SEO page-level checks beyond robots/llms.txt (the `seo_recommendations` cron owns pages — next pass).
