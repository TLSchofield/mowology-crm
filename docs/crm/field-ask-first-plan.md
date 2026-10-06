# Field recommendations: "Ask first" or "Send quote" (plan)

Status: **approved and built 2026-10-06** on this branch (not deployed). Written 2026-10-06 for Tim's approval.

Tim's answers to section 9: (1) admin **or manager** sends asks and quotes (permission
`billing.edit`); crew only draft. (2) Plain letter with Mowology's name, address and an
unsubscribe line in a plain footer. (3) CASL as proposed. (4) Other defaults as planned:
4 photos at 1024px attached, Tim reads replies himself, "no reply yet" after 7 days, 30-day
duplicate window, Aeration wording for his approval. Built notes: the iOS sheet lives in
`RecommendationSection.swift` (no new Xcode file); see `docs/crm/schedule.md` for the as-built summary.
Branch `feature/field-ask-first` (cut from `origin/feature/language`).

## 1. What Tim asked for

After a crew member (or Tim) on a visit picks a published service and takes photos, the
field app offers two buttons:

| | **Ask first** (nudge) | **Send quote** (today's behaviour) |
|---|---|---|
| Goes to | The person who **decides the work**: the property's on-site contact (`property_contacts`, role `site_supervisor`, via `OnsiteContactService`), else the site/billing contact (`properties.site_contact_id`) | The person who **signs off the spend**: `QuoteService::resolveContact()` (company primary contact / managing contact / site contact), e.g. Darren for Cambridge Apartments |
| Contains | A short note in Tim's voice, the visit photos, **no price**, "would you like us to do this?" | Priced quote, photos, portal link to accept |
| Sends | **Never automatically.** Tim reads and edits it first | Only when `products.field_auto_send = 1` and the price is fixed and non-zero; otherwise a draft for Tim |
| Price needed | No | Yes. $0 is refused in the field and routed to the office "needs a price" |
| After | Reply lands in office@ → `sales_messages` → Sam's card shows "Build the quote" for that observation | Existing quote follow-up (Sam) |

## 2. What exists today (file:line on `feature/language`)

**Service** `app/Modules/Products/Services/FieldRecommendationService.php`
- `OPEN_STATUSES` :31 (duplicate guard, 30 days, per property + product, `findRecentDuplicate()` :641).
- `getFieldOptions()` :48, `isAutoSendEligible()` :159 (flag + fixed price + price > 0: already fails closed).
- `create()` :181 records `field_observations` with `source='service'`, links photos (`linkPhotos()` :674, `media_links` context `field_observation`, `client_visible`), auto-sends only if eligible, else leaves `pending`.
- `buildQuote()` :298 (idempotent on `quote_id`; flat price or `QuoteCalculator` if measured), `send()` :425 (`sendEmail()` + `EmailWrapper`, inline `<img>` thumbnails via `photoHtml()` :505, max 4, remote URLs, **not attachments**).
- `resolveTarget()` :610 takes `p.site_contact_id` only. **No on-site contact logic anywhere in this flow.**

**Endpoint** `app/Modules/Schedule/Api/recommendation.php` (routed as `/api/schedule/recommendation`): `GET ?mode=options`, `POST action=create`. Session (CSRF) or JWT via `requireLoginOrJwt()`. Crew limited to their own visits.

**Office review queue**: `public/crm/products/recommendations.php` :234-237 ("Approve & Send Quote", "Send Info Email", "Dismiss") → `app/Modules/Products/Api/field-observations.php` `approve` :345 (legacy 605 "info email", no price, ~190 lines of inline SQL/HTML in the API file, rule-10 debt) and `approve-quote` :535 (`buildQuote()` + `send()` with optional price override).

**Web / Android** (Android is the same WebView page): `public/crm/js/schedule-pill-workflow.js` :2736 `openRecommendModal()`, chips show `$price` :2797, one button "Send Recommendation" :2767, photos uploaded one by one :2857, `sendRecommendation()` :2883.

**iOS**: `ios/.../Features/Schedule/RecommendationSection.swift` (VM `submit()` :73, one submit button :307), mounted in `VisitDetailView.swift` :478; models `Core/Models/RecommendationOption.swift`; endpoints `Core/Network/APIEndpoints.swift` :85-89, :257-263, JWT lists :427/:501/:512; offline `Core/Offline/RecommendationQueue.swift` (UserDefaults key `mw.recommendation.queue.v1`, `PendingItem` Codable), drained from `AppPhotoQueueDrainService.swift` :64.

**Contacts**: `app/Modules/Contacts/Services/OnsiteContactService.php` `getForProperty()` :67 (guarded by `isAvailable()`; table from migration 1122). `QuoteService::getWithContact()` :51 + `preferManagingContact()`, `resolveContact()` :424.

**Email**: `app/Services/Messaging/MessagingService.php` `sendEmail()` :64 takes **one** `?string $attachmentPath`; PHPMailer sets `From: no-reply@`, `Reply-To: office@mowology.ca` by default (`_createMailer()` :1024; the `mail()` fallbacks :1138/:1204 also set it). So replies already reach office@. `canSendMarketing()` :257 (unsubscribe list, express, implied ≤ 2 years). `generateUnsubscribeUrl()` in `app/Services/Messaging/TemplateRenderer.php` :231.

**Not on `feature/language`** (only on side branches, so this plan depends on them):
- Sam: `origin/feature/sam-sales-head` (migration 1140: `sam_followups.learned_body`, `sales_messages`; `SalesDeskService`, `SamFollowupService::unfill()/fill()`, `sales_inbox_poll.php`, `sam-card.js`, `app/Services/Copy/mowology-copy-rules.md`).
- Mia: `origin/feature/mia-marketing-head` (migration 1161 `consent_ledger`; `app/Modules/Consent/Services/ConsentLedgerService.php` `allows()`).

Every use of those below is guarded by a table/class probe, so this feature can deploy before or after them. Sam's half (§6.4) cannot be merged until Sam's branch is in the deploy branch.

## 3. Unknowns, and the cheapest way to settle each

Prod is read only through Tim's logged-in Chrome (schema authority `/crm/database_appstack.php`, Migrations tab, endpoints). Roughly 10 minutes, all before code.

| # | Unknown | Settle by |
|---|---|---|
| U1 | Are Sam (1140) and Mia (1161) live and migrated on prod? `sales_messages` / `consent_ledger` tables exist? | Chrome: schema tab, search both tables; open `/crm/api/sales-head.php?mode=desk` |
| U2 | Is `sales_inbox_poll` actually running, so a reply lands in `sales_messages`? | Same desk JSON (latest inbound `sent_at`), or cPanel cron list |
| U3 | Does the 1122 `property_contacts` table exist, and does Cambridge Apartments have Gaby as `site_supervisor` **with an email** and Darren as `site_contact_id`/company primary? | Chrome: Cambridge Apartments property page (on-site contact card) + schema tab |
| U4 | Are Aeration and Fall Clean Up published with a price and a pricing rule? | Chrome: `/api/schedule/recommendation?mode=options` (label, price, `fixed_price`, `auto_send`) |
| U5 | Are 1024w JPEG variants ready by the time Tim sends (sync or a cron)? | Chrome schema tab: `media_variants` rows for the newest `photo_type='issue'` media. If missing, the send resizes with GD at send time (code path exists in `MediaVariantGenerator`) |
| U6 | Host SMTP message size limit | Assume cPanel Exim default (50 MB); we cap at 4 photos × 1024px ≈ 1–2 MB. Settled for real by the first test send to Tim's own address (rollout step 4) |
| U7 | CASL: is the ask a commercial electronic message, and on what basis is it allowed? | Not code. Tim decides (D4). Reasoning in §5 |
| U8 | Will the on-site contact reply from the address on file? (Sam matches replies by contact email only) | Accept the risk; a reply from an unknown address still sits in office@ and Tim sees it. Optional later: match on the `Re:` subject |

## 4. Design

### 4.1 Flow

1. Field app: pick service → photos → note (optional) → the app shows **who each button goes to**
   ("Ask first → Gaby Ruiz, on-site" / "Send quote → Darren Lee, billing") from a new
   `GET /api/schedule/recommendation?mode=recipients&visit_id=N`.
2. **Ask first** → `POST action=create, intent=ask`. Server records the observation with
   `intent='ask'`, `status='ask_draft'`, `ask_contact_id`, and builds the draft (subject, body)
   from the service's template.
   - **Tim (admin) on the phone**: the app shows the draft in an editable sheet with the photo
     strip and a Send button → `POST action=ask_send` (subject, body). Server re-resolves the
     recipient and consent, never trusting the client's address.
   - **Crew**: saved as `ask_draft` and the toast says "Saved for Tim to send". Tim sends it
     from the "Ask first" tab on `recommendations.php` (same editable draft).
3. On send: `sendEmail()` with the photos **attached**, plain letter format signed by Tim, CASL
   footer (§5). Status → `asked`, `asked_at`. Logged to `activity_log`, to `sales_messages`
   (outbound, `mailbox='field_ask'`, `contact_id` = asked contact, if the table exists) and
   to `field_ask_messages` (suggested vs final, for learning).
4. Customer replies to office@ → `sales_inbox_poll` stores it against the contact →
   `SalesDeskService::fieldAsks()` finds `asked` observations with an inbound message from
   `ask_contact_id` (or the billing contact) newer than `asked_at` → a card on Sam's desk:
   "Gaby replied about Fall Clean Up at Cambridge Apartments" + the snippet +
   **Build the quote** / **Not now**. Tim reads the reply himself; no yes/no parsing (D7).
5. **Build the quote** → `buildQuote()` on the **same** observation (price editable, $0 refused)
   → the existing `send()` to the billing contact, shown as a draft first unless the product is
   auto-send. Status `ask_yes` → `quote_created` → `email_sent`.
6. **Send quote** (button 2) is today's `create()` with `intent='quote'`, plus: a product with no
   price or a $0 result never sends and lands in the queue tagged "needs a price"; the field
   button reads "Send to office for pricing" when `price <= 0`.

### 4.2 Rules

- **Recipient for Ask**: on-site contact with an email → else site contact with an email →
  else refuse with "No email for anyone at this property" (call instead). If the on-site and
  billing contact are the same person, the "next step" line says "I'll send you the quote".
- **Duplicates**: `ask_draft`, `asked`, `ask_yes` join `OPEN_STATUSES`. Pressing **Send quote**
  for a property+service that has an open ask **upgrades that observation** (`buildQuote()` on
  it) rather than creating a second one, so the photos and history stay together.
- **Never auto-sends an ask.** Replays from the iOS offline queue always create `ask_draft`.
- **Old app builds** send no `intent` → default `'quote'` → exactly today's behaviour. This is
  what makes web-first, iOS-later safe.
- **Rule 9**: all mail via `sendEmail()`. **Rule 10**: all logic in services; the API files
  stay thin. No SMS in this feature (ask texts would need express consent and can't carry
  photos).

### 4.3 Photos

`sendEmail()` grows an optional trailing `array $attachments = []` (paths), backward compatible
with the single `$attachmentPath` callers: PHPMailer loops `addAttachment()`, the `mail()`
fallback builds one multipart with N parts. Attach up to **4** photos as the 1024w JPEG variant
(`media_variants`, `responsive`/`jpeg`), else a GD resize of the original to 1024px into a temp
file; total capped at 6 MB (drop extras, add "more photos on request"). Named
`cambridge-apartments-1.jpg`. No remote `<img>` (Outlook blocks them and they make a personal
note look like a newsletter).

### 4.4 Templates and learning

One shared skeleton, a **per-service pitch** sentence, and Tim's edits learned per service.

Placeholders: `{first_name} {place} {when} {service} {pitch} {next_step} {billing_first} {owner} {phone}`.
`{when}` = "this morning" / "this afternoon" / "today" from the visit's clock time.
`{next_step}` = "I'll send the quote to {billing_first} for sign-off and book a date with you"
when the asked contact is not the billing contact, else "I'll send you the quote and book a date".

**Fall Clean Up** (Tim's approved wording, as the default):

> Subject: {place}: fall cleanup (photos from today)
>
> Hi {first_name},
>
> We were at {place} {when} and took a few photos of the beds. They're attached.
>
> They're ready for their fall cleanup: we trim and prune the shrubs, clear out the beds and
> put them to bed for the winter, so they come back tidy in spring instead of overgrown.
>
> Would you like us to go ahead? Just reply "yes" and {next_step}.
>
> Thanks,
> Tim
> Mowology · (778) 846-9273

**Aeration** (new, same voice; Tim to approve):

> Subject: {place}: lawn aeration (photos from today)
>
> Hi {first_name},
>
> We were at {place} {when} and took a few photos of the lawn. They're attached.
>
> The soil is packed down hard, which is when aeration earns its keep: we pull small plugs out
> of the lawn so water, air and feed reach the roots again. The grass thickens up through the
> fall, and moss has less to work with next spring.
>
> Would you like us to go ahead? Just reply "yes" and {next_step}.
>
> Thanks,
> Tim
> Mowology · (778) 846-9273

**Any other service** (fallback): "We were at {place} {when} and noticed {service} is due. A few
photos are attached." + the product's own pitch if set, else nothing + the same ask. Copy was
checked against the voice card (`.agents/product-marketing-context.md` §7) and
`mowology-copy-rules.md`: no exclamation marks, no "just checking in", one easy ask, no price,
no invented proof, signed "I" by Tim.

**Storage**: default skeleton + Fall Clean Up / Aeration pitches in code
(`FieldAskService::DEFAULTS`, matched by product id once U4 gives the ids, falling back to a
label match); a per-product override `products.field_ask_pitch` editable on the **Ask first**
tab of `recommendations.php` (deliberately not on the product form: prod `api-products.php` is
behind the repo, memory 2026-10-04, so it must not be redeployed for this).

**Learning** (Sam's pattern): when Tim's sent subject/body differs from the suggestion, store
it with this customer's details put back as `{placeholders}` (`learned_subject`,
`learned_body`) on `field_ask_messages`. The next draft for **that product** uses the latest
learned version ("written your way"). `fill()/unfill()` mirror `SamFollowupService` (pure
static, unit tested); when Sam merges, both move to one shared `app/Services/Copy/` helper.

## 5. CASL (U7) — reasoning for Tim's decision, not legal advice

An unsolicited "would you like us to do fall cleanup?" encourages a purchase, so it is a
**commercial electronic message**. A service note that only reports work done would be
transactional; this is not that, so it needs a basis:

- **Company-managed property** (strata, PM, commercial: the property is linked to a company, or
  the on-site contact's `employer_company_id` is set): the Electronic Commerce Protection
  Regulations s.3(a)(ii) business-to-business exemption (employee of one organisation to an
  employee of another that has a relationship with it, about the recipient organisation's
  activities). The active maintenance contract is the relationship.
- **Homeowner**: implied consent from the existing business relationship (paid invoice or
  completed job in the last 2 years) or express consent. Gate with
  `ConsentLedgerService::allows($contactId, 'email')` when the class and `consent_ledger` exist
  (Mia), else `canSendMarketing()` (which reads the same implied/express dates).
- **Always**: honour `marketing_unsubscribes` (hard block, both paths), and add a one-line
  footer with the legal name, mailing address and an unsubscribe link
  (`generateUnsubscribeUrl()`). Cheap, and it satisfies the identification/unsubscribe form
  rules whichever basis applies.
- When the gate says no, the app says so plainly ("No email consent on file for Gaby. Call her,
  or send the quote to Darren") and the ask cannot be sent.

## 6. Changes per platform

### 6.1 Data — migration `1180_field_ask_first.sql` (MySQL 5.7, ADD COLUMNs guarded in the runner like 1114)

- `field_observations` ADD `intent VARCHAR(10) NOT NULL DEFAULT 'quote'` (`ask`|`quote`),
  `ask_contact_id INT NULL`, `ask_role VARCHAR(20) NULL` (`onsite`|`site`),
  `asked_at DATETIME NULL`, `replied_at DATETIME NULL` (stamped when Sam first sees the reply),
  `ask_sent_by INT NULL`; index `(intent, status, asked_at)`. New status values (VARCHAR, no
  enum change): `ask_draft`, `asked`, `ask_yes`.
- `products` ADD `field_ask_pitch TEXT NULL`.
- CREATE `field_ask_messages` (`id`, `observation_id`, `product_id`, `contact_id`,
  `suggested_subject`, `suggested_body`, `final_subject`, `final_body`, `learned_subject`,
  `learned_body`, `drafted_by` `template|learned`, `status` `sent|edited|failed`, `error`,
  `sent_by`, `sent_at`, `created_at`; indexes on `(product_id, status, sent_at)` and
  `observation_id`), `utf8mb4_general_ci`.
- 1181–1185 kept free. All new reads `SHOW COLUMNS`/`SHOW TABLES` guarded so deploy-before-migrate
  is safe (the 1114 lesson).

### 6.2 Server (ships with web)

| File | Change |
|---|---|
| `app/Modules/Products/Services/FieldAskService.php` **new** | `recipients(visitId)`, `consent(contactId, propertyId)`, `draft(obsId)`, `send(obsId, user, subject, body)`, `learn()`, static `fill/unfill/isEdited`, `DEFAULTS` copy, attachment picking |
| `app/Modules/Products/Services/FieldRecommendationService.php` | `create()` takes `intent`; ask → `ask_draft` + `ask_contact_id`; open-ask upgrade on Send quote; statuses added to `OPEN_STATUSES`; `send()` refuses `amount <= 0`; `getFieldOptions()` adds `has_price` |
| `app/Services/Messaging/MessagingService.php` | `sendEmail(..., array $attachments = [])`, multi-part `mail()` fallback. Backward compatible |
| `app/Modules/Schedule/Api/recommendation.php` | `GET mode=recipients`, `POST action=create` with `intent`, `POST action=ask_send` (admin/manager only), `GET mode=ask_draft&observation_id` |
| `app/Modules/Products/Api/field-observations.php` | `list` returns intent/ask fields; `ask-send`, `ask-pitch` (save `field_ask_pitch`) thin actions calling `FieldAskService`. Legacy `approve` untouched |
| `public/crm/products/recommendations.php` + `public/crm/css/mowology-brand.css` | "Ask first" tab: drafts waiting, editable subject/body, photo strip, recipient + consent line, Send; per-service pitch editor. `.mw-` classes, `--mw-*` tokens, no inline styles |
| `database/migrations/1180_field_ask_first.sql` (+ runner, same pattern as 1114) | §6.1 |
| `docs/crm/schedule.md` | Document both paths |

### 6.3 Web / Android — `public/crm/js/schedule-pill-workflow.js`

Chips show "Price TBC" when `has_price` is false. Replace the one button with **Ask first** and
**Send quote** (or "Send to office for pricing"), each with its recipient line from
`mode=recipients`. Ask first for an admin opens the editable draft step; for crew, saves and
toasts. CSRF: this page is not AppStack, so use `state.csrf` with the existing
`/crm/api/get-csrf.php` refresh-and-retry (Known-Failure-Patterns: `MW_CSRF_TOKEN` is
AppStack-only). Android needs no APK: the WebView loads this from the server.

### 6.4 Sam (lands on/after `feature/sam-sales-head`)

`SalesDeskService::fieldAsks()` + include them in `queue()`/`brief()` as kind `ask_replied`
(priority 1, like `replied`); `sales-head.php` `POST mode=field_build {observation_id, price?}`
and `mode=field_park`; `sam-card.js` / `sam-card.php` render the card with the reply snippet,
photos count, **Build the quote** (then the existing quote draft/send to the billing contact)
and **Not now**. Asked-but-silent after 7 days appears as a gentle "no reply yet" line, not a
nudge draft (Tim can decide later whether Sam should nudge asks).

### 6.5 iOS (batched; one `feature/language` push later)

| File | Change |
|---|---|
| `Features/Schedule/RecommendationSection.swift` | Two buttons with recipient lines; "Price TBC"; admin → `AskDraftSheet` (editable subject/body, photo strip, Send); crew → "Saved for Tim" |
| `Features/Schedule/AskDraftSheet.swift` **new** | Register with `ios/add_swift_file.py`, then **verify with `xcodebuild`** (it misfiles; KFP) |
| `Core/Models/RecommendationOption.swift` | `hasPrice`; `RecommendationRecipientsResponse`, `AskDraftResponse`; create response gains `intent`, `draft` |
| `Core/Network/APIEndpoints.swift` | `recommendationRecipients(visitId)`, `recommendationAskDraft(obsId)`, `recommendationAskSend`; add to the JWT/method lists (:427, :501, :512) |
| `Core/Offline/RecommendationQueue.swift` | `PendingItem.intent: String?` (optional so `v1` items still decode → `quote`); asks replay as `ask_draft` only |

## 7. Tests (`vendor/bin/phpunit`, all must pass; register in `tests/bootstrap.php`)

`tests/Unit/Products/FieldAskServiceTest.php`:
- recipient: on-site with email wins; on-site without email → site contact; none → refusal; same person → "send you the quote" line.
- consent: company-linked → allowed (B2B); homeowner implied in date → allowed; expired → refused; unsubscribed → refused on both paths.
- draft: Fall Clean Up and Aeration render with no `$`, no `!`, no "just checking in"; `{next_step}` both forms; learned body used over default; `unfill()` round-trips names/place.
- `send()` refuses an observation not in `ask_draft`; never called from `create()` (no auto-send for asks).
- attachments: picks ≤4, prefers 1024w JPEG, respects 6 MB cap.

`FieldRecommendationServiceTest.php` additions: `intent` defaults to `quote`; new statuses block
duplicates; Send quote on an open ask reuses it; `send()` refuses `amount <= 0`.

`MessagingService`: a test that the single-path call shape still works and N attachments reach
the mailer (mock).

Sam branch: `SalesDeskServiceTest` for `fieldAsks()` (reply after `asked_at` → card; before →
none; reply from billing contact also counts).

Manual (Tim, rollout step 4): one real ask to a test property whose on-site contact is Tim's own
address, four photos, reply "yes", watch the Sam card.

## 8. Rollout order

1. Tim settles U1–U5 in Chrome (§3) and answers §9. No code before this.
2. Build server + web on this branch; phpunit green; render the modal and the Ask first tab
   with the stub-DB method before deploying (memory: render before deploy).
3. Migration 1180 via Database → Migrations; confirm columns in the schema tab.
4. Deploy server + web files by lftp from the deploy branch, `cmp` each against prod first
   (prod-ahead drift), never `api-products.php`. OPcache reset. Verify the pages render to
   `</html>`. Test send to Tim's own address (U6), check attachments and Reply-To.
5. Sam half deploys with or after Sam's branch (needs 1140 on prod); verify the reply →
   "Build the quote" loop with the test property.
6. iOS last, in the next iOS batch: one push to `feature/language` → Xcode Cloud → TestFlight.
   Old iOS builds keep working meanwhile (no `intent` = today's behaviour).

## 9. Decisions for Tim

1. **Who may send an ask?** Recommended: crew can only draft; Tim (admin/manager) sends.
2. **Look of the ask email:** plain letter signed by Tim (recommended) or the branded wrapper.
3. **Photos:** attach up to 4 at 1024px (recommended), or link to a portal page.
4. **CASL basis:** B2B exemption for company-managed properties + consent ledger / implied
   consent for homeowners, unsubscribe footer always (recommended). Your call; not legal advice.
5. **Where pitches are edited:** Ask first tab on the recommendations page (recommended, avoids
   redeploying `api-products.php`) or the product form after that file is reconciled.
6. **Approve the Aeration wording** in §4.4 (Fall Clean Up is yours from today).
7. **"Yes" detection:** you read the reply and press Build the quote (recommended), or Sam
   pre-selects on a "yes".
8. **Silent asks:** after 7 days just show "no reply yet" (recommended), or let Sam draft a nudge.
9. **Duplicate window:** an open ask blocks a second ask for the same service at that property
   for 30 days (same as quotes). OK?
