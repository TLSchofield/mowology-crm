# Phone contacts in onboarding — server task (Codex web build)

Tim, 2026-11-11: build the onboarding on the iPhone, then reuse it as the template for Android. First step: bring the
phone's contacts in properly. Backlog order (docs/plans/ittai-backlog.md "Has something"): QuickBooks, **phone contacts**,
Google Business, Outlook, Gmail. Each screen sits on the Kaname tab.

The phone reads its own address book and shows the owner "Are these your clients?". **Only the contacts the owner keeps
leave the phone.** This task is the server half.

Rules: PHP 7.4+, MySQL 5.7 (no window/JSON functions), prepared statements, no autoloader (`require_once` + `new`),
new logic in a service with a PHPUnit test, no new tables, nothing Mowology-specific in copy ("Ittai" is the product).

## 1. A new onboarding module `contacts`

In `app/Modules/Onboarding/Services/OnboardingModules.php` add `OnboardingContactsModule extends OnboardingModule`
(owner `sam`, title "Your clients from your phone", skippable, not pinned, `needs()` empty). Follow the QuickBooks module's shape.
- Answer stored in `ops_settings` key `onboarding_contacts_answer` = `imported` | `skipped` (use `saveSetting`).
- `state()`: DONE when the answer is set, else TODO. `summary()`: "N clients from your phone" when imported (N from
  `onboarding_contacts_count`), "Skipped" when skipped, '' otherwise.
- `question()`: "Do you keep clients in your phone? Pick the ones you work for."
- Register it in `OnboardingService`'s default module list right after `OnboardingQuickBooksModule`, and add `'contacts'` to
  `OnboardingService::NEW_STEPS` so an app that does not declare it never sees it (existing mechanism; read `knowsFrom`).
- `OnboardingApiPresenter::data()` gets a `'contacts'` entry: `['answer' => 'imported'|'skipped'|null, 'count' => int]`.

## 2. Step actions on the existing `/api/onboarding/step.php`

`OnboardingStepService::apply()` gains key `contacts` with `do`:
- `import` — body `contacts` = list (max 200 per call, else error `too_many`) of
  `{ref, first, last, company, emails:[string], phones:[{label, number}], address:{street, city, province, postal}|null}`.
  `ref` is an opaque device id used only to echo results back. Returns `{ok:true, next, message, results:[{ref, outcome, contact_id}]}`
  with outcome `created` | `matched` | `skipped`. `import` may be called several times (batches); it does not set the answer.
- `finish` — body `count` (int ≥ 0); sets answer `imported` and `onboarding_contacts_count` (adds to the stored count when
  called again is NOT wanted: store the total the app sends).
- `skip` — sets answer `skipped`.
Errors use the existing `{ok:false, error, message}` shape with plain-English messages.

## 3. `ContactImportService` (new, `app/Modules/Contacts/Services/ContactImportService.php`)

Pure rules plus DB writes, constructed with the PDO. For each contact:
1. Skip when there is no name at all and no email and no phone (outcome `skipped`). A company-only card (no first/last, has
   `company`) becomes a contact whose first name is the company name and a linked company (`link_company` true, `company_mode` 'new'),
   the way `QboOnboardingService::applyClients` handles `companyOf`.
2. **Match an existing client**, in order: lowercase email equal to any `contacts.email`; phone digits (strip non-digits, drop a
   leading 1 on 11 digits, need 10 digits) equal to `contacts.mobile` or `contacts.phone` digits; first+last name (case-insensitive)
   plus the same street. Read how `QboOnboardingService::matchContact` does it and keep the same strictness (a name alone never matches).
   On a match: outcome `matched`, create nothing, change nothing.
3. Otherwise create through `ContactService::createContact` with: first/last (split a single-word name into first only), first email as
   `email`, the number labelled mobile/iPhone as `mobile` and the first other as `phone` (else the first goes to `mobile`), notes
   "Imported from phone contacts.", and **CASL: `receive_marketing` 0, `receive_sms` 0, `consent_quote_followup` 0** (an imported
   contact is not a subscriber). If an address has a street, add it with `addOwnPropertyToContact` like the QuickBooks import
   (skip silently when it throws; the contact still counts as created).
4. Inside one batch, two entries that match each other (same email or phone) create one contact; the second is `matched`.
Never log contact details. Trim and cap every string at its column's length.

## 4. Tests (`tests/Unit/`) — register new classes in `tests/bootstrap.php`

Pure functions tested without a DB: phone-digit normalising, name splitting, the labelling of mobile vs other numbers, the
dedupe key building, batch-internal duplicates. A module test for `state()` / `summary()` / `question()`. `knowsFrom` still
hides `contacts` from an app that does not declare it. Run `vendor/bin/phpunit` — the whole suite passes.

## Out of scope
iOS code, Google, Gmail, any new table, marketing consent screens, editing existing clients.
