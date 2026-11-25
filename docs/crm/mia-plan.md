# Mia — Marketing & Relationships head: plan (Phase 1, awaiting Tim's approval)

Branch `feature/mia-marketing-head` (from `origin/feature/language`). No feature code yet.
Mia's job: keep existing/past customers and property managers warm. Sam owns open quotes and new leads. **Mia never sends on her own. Tim sends.**

## 1. What the code and data already hold

| Area | Where | What it means for Mia |
|---|---|---|
| Placeholder card | `public/crm/includes/dept-heads-deck.php:45` | **Do not edit the deck in this branch.** The lead changes it once, on `feature/sam-sales-head`, so that any existing `<slug>-card.php` replaces that head's placeholder. Mia just ships `mia-card.php`. |
| Consent | `app/Services/Messaging/MessagingService.php:257` `canSendMarketing()`, `:218` `hasSmConsent()`, `:64` `sendEmail()`, `:123` `sendSms()` | Checks the `marketing_unsubscribes` hard block → express consent → implied consent (`consent_email_implied_at` less than 2 years old). It runs one query per contact, so Mia loads the unsubscribes in bulk. |
| Implied consent gap | Only `public/crm/invoices/record-payment.php:194` sets `consent_email_implied_at` | Customers who paid by Stripe, autopay or e-Transfer may have no implied consent recorded, even though they paid. |
| Reviews | `app/Modules/Reviews/Services/ReviewRequestService.php:60` `maybeSend()`, called from `public/crm/api/pow-actions.php:759` on every `end_visit` | **Already sends automatically** (email, plus SMS if consented) for each visit the crew flagged (`job_visits.is_flagged`). Limits: 30-day cooldown and 3 requests per lifetime (`:160`). `contacts.has_reviewed` is a manual checkbox (`clients_appstack.php:760`). Nothing imports Google reviews: `GoogleBusinessService` only makes posts. |
| Referrals | `app/Modules/Referrals/Services/ReferralRewardService.php`: `getOrCreateLink():201`, `sendInviteEmail():226` (manual, through `Referrals/Api/referrals.php:192`), `maybeAwardCompletion()` (auto, `pow-actions.php:773`), gate `referral_program_enabled:340` | Tables: `referral_links` (has `invites_sent`, `last_invite_sent_at`), `referrals` (pending → converted → rewarded), `referral_rewards`. |
| Other automatic senders | `PhotoConsentRequestService::maybeSend` (`pow-actions.php`, 90-day cooldown); `automation_runner.php` triggers `job_completed:243`, `client_inactive:290`, `season_change:310`, `product_not_purchased:331`; seeded rules `database/migrations/700_marketing_automation_engine.sql:189` ("Post-Job Thank You + Review Request", "Inactive Client Re-engagement (6 months)", "Spring Season Campaign", all seeded **off**); `run-migration-comms.php:171` "Invoice Overdue Reminder" seeded **on** | Any of these could overlap with Mia. Their state on prod is unknown. |
| Past work | `job_visits` (`status='completed'`, `completed_at`) → `job_plans` (`service_type`, `status`, `property_id`) → `properties.site_contact_id` → `contacts`. `contacts.last_service_date` and `total_lifetime_value` are refreshed by the cron `public/crm/cron/refresh_purchase_history.php` | Lapsed and seasonal customers are found from visits and plans, not from that cron's fields, because it may not be scheduled. |
| Property managers | `properties.property_manager_id` → `companies` (`primary_contact_id`, `billing_contact_id`); see `InvoiceRouting.php:121` | A company counts as a PM because it manages properties, not because of `company_type`. That field is advisory: Tech-Debt-Map:207 lists the Pacific Quorum duplicate and firms typed BUSINESS. |
| Contact history | `communication_log` (`030_create_missing_core_tables.sql:227`), written by `campaign_sender.php:214` | Mia writes one outbound row for each send. Replies will come from Sam's `sales_messages` table once it exists; Mia does not build an inbox reader. |
| Shared brain | `app/Services/HeadBrain.php` and `public/crm/js/head-brain.js` (lead worktree, not yet committed) | Mia calls `(new HeadBrain($db, 'mia'))->learned($raw, $labels /* kind => [one, many] */)`, with its start line stored in `mia_brain_baseline`. The card renders a `.mw-head-brain` element with these data attributes: head, units, bright, parts, since, empty, teach. |

## 2. Unknowns, settled cheapest first

1. (The lead already disabled the 9 automatic quote follow-up rules, ids 2, 6, 10, 14, 18, 22, 26, 30, 34. No other rules were touched.) Which `automation_rules` are enabled on prod, and which auto-sends actually fired in the last 90 days (`automation_logs`). → Tim's Chrome, `/crm/database_appstack.php`.
2. Review volume: flagged visits, `review_request_sent_count > 0`, `has_reviewed = 1`, and the `google_review_url` value. → Chrome.
3. `referral_program_enabled`, plus row counts for `referrals` and `referral_links`. → Chrome.
4. Consent coverage: contacts with a paid invoice in the last 2 years compared with contacts that have `consent_email_implied_at` set, and the size of `marketing_unsubscribes`. → Chrome.
5. Pool sizes: lapsed customers at 9, 12 and 18 months; seasonal matches for October and November last year; quiet PMs by quarterly visits per manager company. → Chrome (read-only SELECTs).
6. ~~Which quote statuses mean "open"~~ **Settled by the lead.** Sam's "open" means either of these, and anything named ZZTEST is excluded:
   - quotes with `status IN ('sent','viewed')` AND (`valid_until IS NULL OR valid_until >= CURDATE()`)
   - `quote_requests` with `status IN ('new','reviewing')`, `quote_id IS NULL`, created in the last 60 days

   Mia uses exactly this rule, kept in one constant or method.
7. Whether the purchase-history and seasonal-trigger crons are scheduled, and whether any campaign is `sending`. → cPanel cron list and Chrome.
8. ~~When `sales_messages` will land~~ **Settled.** It comes in Sam's migration 1140, with these columns: mailbox, message_key, direction (inbound/outbound), channel, contact_id, from_addr, to_addr, subject, snippet, sent_at. Mia counts an inbound row for the same contact within 30 days of her send as a reply. The `SHOW TABLES` guard stays.
9. Live collation of `contacts` and `companies`, so the new foreign keys match. → Chrome `SHOW CREATE TABLE`.

## 3. Files to create

- **Migration** `database/migrations/1160_mia_marketing_head.sql` plus a runner. It adds:
  - `mia_suggestions`: kind (reconnect, seasonal, pm_quiet, review, referral), contact, company and property ids, the reason in JSON, the drafted subject/body/SMS, status (open, sent, edited, skipped, snoozed, expired), skip reason, the text actually sent, who decided and when, and the outcome (booked, quote, reply, none) with its date and reference.
  - `mia_questions`.
  - `mia_mutes`: never contact / snooze-until, per contact.
- **Services** in `app/Modules/Marketing/Services/`:
  - `MiaFinder`: pure eligibility and scoring rules.
  - `MiaWording`: drafts, Tim's learned template for each kind, and an SMS checker (160 characters at most, no URLs or domains, includes (778) 846-9273, tells them to check their email).
  - `MiaDeskService`: `ready`, `stats`, `queue`, `prepare` (lazy and capped), `decide`, `brief`.
  - `MiaOutcomeService`, `MiaBadgeService`, `MiaBrainService` (on top of HeadBrain), `MiaQuestionService`.
- **API** `app/Modules/Marketing/Api/mia.php` with `?mode=` values stats, queue, decide, questions, answer, brain; CSRF; `expenses.approve`-level permission. Shim at `public/crm/api/mia.php`.
- **UI** (the deck file is not touched): `public/crm/includes/mia-card.php`, `public/crm/js/mia-card.js`, and a CSS subsection "MIA — MARKETING HEAD".
- **Tests**: unit tests registered in `tests/bootstrap.php`.

## 4. What Mia does

**Finds.** These apply to every kind:
- Nobody with an open quote or a new quote request; Sam owns those.
- Nobody with an active plan, a future visit or an active contract.
- Nobody unsubscribed or muted.
- Nobody already contacted by Mia in the last 60 days.

The kinds:
- **Reconnect**: completed work, but nothing in N months (default 12). Ranked by lifetime value.
- **Seasonal**: last year the customer bought a service type in a window around today's date (from 21 days before to 30 days after), and they haven't booked it this year. The window must handle the year boundary correctly (Known-Failure-Patterns rule).
- **Quiet PM**: a manager company's completed visits or billed dollars over the last 90 days dropped by 50% or more against the same period last year. Mia writes to the company's primary contact.
- **Review**: a happy recent customer (crew-flagged visit, `has_reviewed = 0`, under the request cap) whom the automatic request skipped or didn't reach.
- **Referral**: a customer of two or more seasons who reviewed or has high value, and hasn't had an invite in 12 months. Uses the existing referral link.

**Shows.** "Hey Tim — 3 fall-cleanup customers from last year haven't booked, and Pacific Quorum has gone quiet (2 visits since July vs 14 last fall)."
- Stats: reconnects due, quiet PMs, reviews this year, work booked within 30 days of Mia's messages ($).
- A carousel of cards with the editable email and SMS, and the buttons Send, Skip (reason chips: not a fit / already talked / not now / never) and Snooze.
- On Send, the server checks consent and open quotes again, then calls `sendEmail()` / `sendSms()` and writes `communication_log`.

**Learns.**
- Tim's last edited version of each kind becomes that kind's template.
- "Never" mutes the contact. "Not now" snoozes them for 60 days.
- A kind with a high skip rate gets a tighter threshold.
- Outcomes are recorded when there's a quote, plan or visit within 30 days, or a reply in `sales_messages`.

**Brain units:** templates learned, contact preferences, booked outcomes, questions answered, badges.

**Badges:** Rebooker (5 booked), PM Whisperer (3 quiet PMs back), Review Magnet (5 reviews after asks), Word of Mouth (first referral converted), In Tune (10 sent unedited in a row).

**Questions:**
- "Did Jane leave a Google review?" (sets `has_reviewed`)
- "Are the two Pacific Quorum records the same firm?"
- "Who's the PM at X now?"
- "Was the Smith job a one-off?"
- "No email or SMS consent — call instead?"

**`brief('Tim')`:** read-only and cheap. It runs no `prepare` and sends nothing. It returns `['head'=>'mia','headline','items'=>[['key'=>'mia:contact:123','text','url','priority'=>1|2|3,'kind'?,'value'?,'since'?]],'count']`. Each `key` is stable (`mia:contact:<id>` or `mia:company:<id>`), so Charlie can tell items apart from day to day.

## 5. Tests and verification

**Unit tests:**
- Eligibility windows, including a test from inside the year-end tail and one from the gap between seasons.
- PM drop detection.
- The open-quote and active-contract exclusions.
- The consent gate composition.
- The SMS checker.
- Wording fill.
- Badge `compute`.
- Outcome attribution window.
- The shape of `brief()`: stable keys, and no writes.
- The whole `vendor/bin/phpunit` suite must stay green.

**Rendering:** render the card locally with a stubbed DB (the method in memory `feedback_render_crm_page_without_local_db`) and show Tim before anything ships. Count candidate pools against prod with read-only queries in Chrome. No deploy and no push.

## 6. Decisions for Tim

1. **Automatic review requests.** `ReviewRequestService` sends after every crew-flagged visit. Keep it, or switch it off and let Mia propose each one for you to send? (The photo-consent and referral-reward emails are automatic too.)
2. **Automation rules.** Confirm that the seeded "Inactive Client Re-engagement", "Post-Job Thank You + Review Request" and "Spring Season Campaign" rules are off on prod. Delete them, so Mia is the one voice?
3. **Thresholds.** The lapse threshold (9, 12 or 18 months) and the seasonal window.
4. **Implied consent.** Work it out from paid invoices (and backfill `consent_email_implied_at`), or trust only the stored field?
5. **How a message goes out.** Send through the CRM (logged, from Mowology, replies to office@), or "copy and open in Mail" from your own inbox?
6. **Property managers.** Can 1:1 messages to PM contacts go out without `receive_marketing`? (Unsubscribes always block.)
7. **Contract renewals.** Contracts with `auto_renew = 0` that end within 60 days: Mia's or Sam's?
8. **Google reviews.** Import reviews later through the Google Business Profile API, or keep the manual `has_reviewed` checkbox?

---

## Phase 2: as built (2026-10-05)

Tim approved the plan. His marketing-agent design ("Scout") then updated it. These are the changes from the plan above.

- **Review requests are no longer gated by the crew heart.** `ReviewRequestService::maybeSend()` used to return early unless `job_visits.is_flagged` was set. It was also only called from `pow-actions.php` `end_visit`, so the timer, iOS and JWT completion path never asked for a review at all.
  - Now every completed visit asks, from both completion paths. `VisitLifecycleService::updateVisitStatus()` calls it too.
  - The same caps still apply: a 30-day cooldown, 3 requests per contact, opt-out and `has_reviewed`. SMS still needs SMS consent, and unsubscribes now always block.
  - The copy asks everyone the same way, with no incentive. The private "if something wasn't right" line now sits alongside the review link, never instead of it.
  - The heart now gates only the portfolio queue and what Mia features.
  - No customer rating exists in the CRM, so there is no low-rating follow-up to wire up yet.
- **Consent ledger** (migration 1161, `app/Modules/Consent/Services/ConsentLedgerService.php`).
  - Each consent is recorded per contact and channel: express, implied or withdrawn, with source, date, proof and expiry.
  - `refresh()` backfills it idempotently on Mia's prepare:
    - implied consent from the latest completed job and the latest paid invoice, 2 years from that date;
    - express consent from `consent_log`, confirmed double opt-ins and the contact's express fields;
    - withdrawals from `consent_log`.
  - Unsubscribes always win. Texts need express consent.
  - `refresh()` also lifts `contacts.consent_email_implied_at` to match, so the campaign sender's `canSendMarketing()` agrees.
  - It fills a missing `consent_email_express_at` from a confirmed opt-in. The opt-in confirm handler never set it, so confirmed people were being refused.
- **Consent is a gate in the send path.** `MiaDeskService::send()` asks `ConsentLedgerService::allows()` at the moment Tim clicks Send. `MiaWording::compose()` refuses to build an email without a sender (company name and postal address) and a working unsubscribe link.
- **Email only.** Marketing texts are off in v1, because a text can't carry an unsubscribe link and replies don't reach us.
- **New suggestion `consent_ask`.** Implied consent runs out within 6 months and there is no express consent, so Mia asks for it while goodwill is high. The customer's own opt-in link is generated when Tim sends.
- **First campaign: post-drought lawn recovery** (`MiaCampaignService`, `mia_campaigns` in 1160).
  - Watering restrictions lift on October 15. Mia proposes aeration, overseeding and top-dressing to current clients plus neighbours of clients (within 400 m).
  - The proposal shows the list size, the consent-passed count, and Tim's own published before/after pair if one fits.
  - Nothing is sent until Tim approves with one tap. Approval creates a `marketing_campaigns` row (`custom_list`, `sending`) with `campaign_sends` for those who pass the ledger at that moment. The existing campaign sender cron then sends it.
  - Campaign copy is refused if it contains a price or discount, an instruction to water, a reward for a review, or merge fields other than `{{first_name}}`.
- **Autonomy.** Mia proposes; Tim sends or approves every message and campaign. Never:
  - send without a consent record;
  - write reviews or testimonials;
  - offer rewards for reviews;
  - show a client's address or face in marketing.

### Later phases (not in v1)

- Google Business Profile autopilot (posts and review replies).
- Route-density targeting. This needs margin per property from the ledger.
- Paid Local Services Ads.
- Lead response. Sam owns new leads.
- Referral auto-credit beyond what `ReferralRewardService` already does.
- Marketing texts. These need a way to unsubscribe by text and to receive replies.
- Featuring hearted jobs in Mia's posts.
- A low-rating private follow-up, once a customer rating exists.
