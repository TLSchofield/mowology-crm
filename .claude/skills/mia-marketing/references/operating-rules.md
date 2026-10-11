# How Mia operates

## She drafts; Tim decides
- Never sends, posts or replies on her own. Every one-to-one email, campaign, Google post, review
  reply and social post waits for Tim's click. Web buttons that go public are two-tap ("armed" for
  6 s) — campaign approve, review post, Google post publish.
- One campaign approval covers its frozen reminder and last call (`MiaSequenceService::run()`),
  each re-checked for consent and for a reply, quote or booking first. Never more than 7 days late.
- Permission: `marketing.approve` (web `mia.php`, `mia-channels.php`; iPhone `gbp_post`). All web
  POSTs need the CSRF token. API routes use `?mode=`, never `?action=`.
- Lanes: a reply that shows interest goes to Sam; anyone with an open quote or new request is
  Sam's (`MiaFinder::samOwns()`, re-checked at send). Other conversations are Yui's.
- Never writes reviews or testimonials, never offers rewards for reviews, never shows a client's
  address or face.

## Consent (CASL) — the gate, not a filter
- `ConsentLedgerService::allows()` is asked at the moment of sending, not when the draft was made.
  Ledger order: unsubscribe (`marketing_unsubscribes`) always wins → express → implied
  (2 years from the latest completed job or paid invoice). Texts need express consent.
- `ConsentLedgerService::refresh()` backfills idempotently and lifts
  `contacts.consent_email_implied_at` so `canSendMarketing()` (the campaign sender's check) agrees.
- Every marketing email: sender name + postal address + working unsubscribe link, or
  `MiaWording::compose()` returns null and nothing goes.
- `consent_ask` suggestions ask for express consent while implied consent is within 6 months of
  expiring; the `[confirm link]` mark must stay in the body (it becomes their opt-in link).

## Copy rules (enforced in code — extend the checker, don't restate)
- Voice: first person, short plain sentences, signed Tim, contractions. "We / our crew" on the site;
  "I" only in signed emails and review replies (`.agents/product-marketing-context.md`).
- Never: exclamation marks; solution / leverage / elevate / exceptional / thrilled; emoji in body
  copy; superlatives without a number; claims about design flair.
- Campaigns (`MiaCampaignService::problems()`): no discounts (a discount is its own decision), no
  rewards for reviews, only `{{first_name}}` as a merge field, `{price:x}` tokens must resolve from
  `products` or approval is refused, never tell people to water; from Stage 2 no sentence may tie
  watering to a lawn, grass, seed or sod.
- Review replies (`ReviewReplyDrafter::violations()`): thank by first name, name the work in the
  reviewer's own words, at most 60 words, signed Tim; low ratings get an apology and the office
  phone.
- Google posts: no phone number or link in the text (Google rejects them); the CTA button carries
  them.
- Social captions: one honest sentence, at most 3 hashtags.
- SMS (service texts only — marketing texts are off): `MiaWording::smsProblems()` — ≤160
  characters, no URL or domain, ASCII only, "check your email", (778) 846-9273.

## Photos
- `MediaPickerService::pick()` hard rules: `privacy/ok` from the vision pass, consent not `no`
  (`contacts.photo_optout`), quality ≥ 4, not used in the last 90 days. Until the vision pass has
  run on a photo it is never picked for public use.
- Tim's rule (2026-10-06): use all client property photos unless the client opted out; privacy
  blur still blocks public use.

## She learns only from what Tim and customers do
| Lesson | Taught by | Where |
|---|---|---|
| Template per suggestion kind | Tim's last edited, sent version | `MiaWording` templates |
| Mutes / snoozes | Skip reasons: not a fit 365 d, talked 90 d, not now 60 d, never = forever | `mia_mutes` (`MiaDeskService::SKIPS`) |
| Send time per person | replies 3, clicks 2, believable opens 0.5; ≥3 points to learn; Apple MPP proxy opens ignored | `SendTimeService` |
| Theme and photo choice | engagement from `social_metrics_daily`, clicks in `media_usage` | `MiaSocialService`, `media_tag_scores` |
| What worked by year | sent / clicked / replied / quoted / booked $ (opens not kept) | `mia_calendar_results` |

## Every change is checked before it reaches the public
- Render UI before deploy (stub-DB harness); `cmp` existing `public/` files against prod.
- Before scheduling any queue-draining cron for the first time (publisher, campaign sender),
  count the backlog it will send.
- Production checks run through `scripts/prod-run.sh` (rolled back unless `--commit`).
