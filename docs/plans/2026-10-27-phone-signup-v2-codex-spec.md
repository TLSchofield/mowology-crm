# Phone sign-up v2 + the whole onboarding path on the phone — server task (Codex web build)

Owner's flow (Tim, 2026-10-27): **sign-up asks only for the person** — full name, cell number, email,
password — then **a verification code**, then **screen one of the EXISTING onboarding** (Penny's steps:
About you → Company name (registry lookup + confirm) → QuickBooks? (connect from the phone) or Your books →
Invite your accountant → You're set up). Nothing about the business is asked at sign-up; the business name
comes from the Company step. The claim model stays (decision log 2026-10-26): the operator provisions the
business first (`TenantProvisioner`), it sits unclaimed, and the owner's email is the shared secret.

This replaces the v1 contract in `docs/plans/2026-10-26-signup-onboarding-api-codex-spec.md` (deployed
2026-10-27, behind the OFF switch `android.native_signup`, no caller yet — change it freely).

Rules: PHP 7.4+, MySQL 5.7 (no window/JSON functions), prepared statements, no autoloader (require_once +
`new`), new logic in services with PHPUnit tests, no new tables (ops_settings holds the small state),
nothing Mowology-specific in copy ("Ittai" is the product name; the business's own name comes from
`business_settings`). The local site blocks outbound HTTP and has blank mail settings; see "Local site".

## 1. Sign-up: two calls

### `POST /api/auth/signup.php` — request a code
Body `{full_name, mobile, email, password, audience:"mobile"}`.
- `Tenant::isDefault()` → 404 `{ok:false,error:"not_available"}` (unchanged).
- Rate limit key `'signup:' . $email` with the existing `isLoginRateLimited/recordFailedLogin/clearLoginAttempts`.
- Validation (400 `{ok:false,error:"validation",fields:{…}}`, every failing field, exact messages):
  - `full_name` 2–80 chars after trim, must contain a space → first = up to the first space, last = the rest.
    Message "Enter your full name." (`SignUpService::splitName()` is pure, tested.)
  - `mobile` 10 digits after stripping everything else (a leading 1 on 11 digits is dropped). "Enter your mobile number (10 digits)."
  - `email` `FILTER_VALIDATE_EMAIL`. "Enter a valid email address."
  - `password` ≥ 10 chars. "Use at least 10 characters."
- Claim checks (same as v1, same codes): no admin owner row → 404 `not_available`; already claimed
  (`ops_settings tenant_claimed_at` set, or the owner's `last_login` non-empty) → 409 `taken`; email differs
  from the provisioned owner's → 400 validation `fields.email` "Use the email address this business was set up with."
- Then make a 6-digit code (`random_int(0, 999999)` zero-padded), store in ops_settings:
  `signup_code_hash` (password_hash of the code), `signup_code_expires_at` (now + 15 min, `Y-m-d H:i:s`),
  `signup_code_tries` = 0. A fresh request replaces the previous code.
- Send it: email ALWAYS via `sendCrmEmail($email, 'Your Ittai code', <html with the code, "expires in 15 minutes">)`;
  SMS as a bonus via `sendSms($mobile, 'Your Ittai code is 123456. It expires in 15 minutes.')` — plain text,
  no URL, under 160 chars; an SMS failure is logged and ignored. The email failing → 502 `{ok:false,error:"send_failed"}`
  and the stored code is cleared.
- 200 `{ok:true, sent:["email"] | ["email","sms"], expires_in:900}`. On the local site (see below) also `code`.

### `POST /api/auth/signup-confirm.php` — code → claim → token
Body `{email, code, full_name, mobile, password, audience:"mobile"}` (the app sends the sign-up fields again so
nothing sensitive is stored before the claim).
- 404 default tenant; rate limit key `'signup:' . $email` (a wrong code counts as a failed attempt).
- Validate the same fields (400 as above). `code` must be 6 digits: "Enter the 6-digit code."
- Code check inside the claim transaction, before anything is written: no stored code or expired → 400
  `{ok:false,error:"code_expired",message:"That code has expired. Ask for a new one."}`; `signup_code_tries` ≥ 5 →
  400 `code_expired` too (and the code is cleared); wrong → tries+1, 400 `{ok:false,error:"code_wrong",message:"That code isn't right."}`.
- Right code → the v1 claim, minus business name and trade, plus the mobile:
  owner row `FOR UPDATE`, unclaimed + email match re-checked (409/400 as above), `password_hash`, `is_active=1`,
  `OnboardingNameModule::save($ctx, first, last, first)`, `OnboardingNameModule::saveReach($ctx, 'text', $mobile)`,
  `ops_settings owner_user_id`, `tenant_claimed_at`, clear the three `signup_code_*` keys, `activity_log`
  'Sign-up (mobile)', the JWT minted INSIDE the transaction via the callback (`generateMobileJwt`), commit.
  Do NOT touch `business_settings.company_name` or `business_identity` (the Company step does that).
- 201 `{ok:true, token, user:{id,email,name,role:"admin"}, expires_in:2592000, business:{key,host,name},
  onboarding:{next}}` where `business.name` is `business_settings.company_name` (what the operator typed when
  provisioning; may be a placeholder) and `onboarding.next` is `OnboardingService::next()` (expected `"name"`
  — About you still has "how to reach" and "quiet hours" parts to confirm, or `"company"` when the module
  counts them done).

## 2. Onboarding map — unchanged
`GET/POST /api/onboarding/map.php` as built (`OnboardingApiPresenter`). Add to each step a `data` object
(section 4) so the phone can draw the step without a second call.

## 3. Onboarding steps on the phone — `POST /api/onboarding/step.php`
JWT + admin as built. Body `{key, do, …}`; every success returns `{ok:true, next, message?, data?}` where
`data` is that step's object from section 4 after the change; failures `{ok:false, error:"validation"|"refused",
message}` (400). Mirror `public/crm/onboarding/step.php`'s POST handling exactly — it is the web version of
the same screens; the modules do the work, the endpoint stays thin. Put the dispatch in
`OnboardingStepService::apply()` (extend; keep the constructor `(PDO $db, string $dbName)`).

| key | do | fields | does |
|---|---|---|---|
| name | save | first, last, preferred | `OnboardingNameModule::save` |
| name | reach | channel (`text`/`email`/`call` — whatever `saveReach` accepts), mobile | `saveReach` |
| name | quiet | from, to (HH:MM), weekends (bool) | `saveQuiet` |
| company | search | name | `BusinessIdentityService::start($name)` then `search($name)` → `data.matches` (as the web lookup page shows them: name, registration number, status, record_id) |
| company | confirm | record_id | `BusinessIdentityService::confirm($recordId)`; also `business_settings.company_name` = the confirmed operating or legal name (what `/crm/penny-onboarding_appstack.php` does on confirm — read it and do the same) |
| company | manual | name | `manual(['legal_name'=>$name])` + `business_settings.company_name` (v1 behaviour) |
| quickbooks | yes / no | — | `OnboardingQuickBooksModule::setAnswer`; `no` moves on (next = books) |
| quickbooks | read | — | `QboOnboardingService::pull()` (needs a connection; else refused "Connect QuickBooks first.") |
| quickbooks | accept | part | `QboOnboardingService::apply($part, $userId)` — on production `canWrite()` is false and apply() reports that; pass its message through unchanged |
| books | ack | — | `OnboardingBooksModule::acknowledge` |
| accountant | invite | name, email | `AccountantInviteService::invalid` → 400; else `invite($name,$email,$uid, 'https://' . host)` + `onboarding_accountant_invited`; `data.invite = {link, expires_at, name}` (nothing is sent, the owner passes the link on — same as web) |
| any skippable | skip | — | `OnboardingService::skip($key, true)` (map.php already has skip/unskip; accept it here too for one-call screens) |
| done | finish | — | refused unless `readyToFinish()` ("A step before this one still needs doing — the map shows which."); sets `onboarding_complete` = 1 |

Unknown key/do → 400 validation "No such step." / "Choose a valid action." (as built).

## 4. Per-step `data` (in map.php's steps and in step.php's reply)
- `name`: `{first, last, preferred, mobile, channel, quiet:{from,to,weekends}|null, parts:{name:bool,reach:bool,quiet:bool}, next_part}` from `OnboardingNameModule::known()/nextPart()`.
- `company`: `{entered_name, legal_name, operating_name, registration_number, status, confirmed:bool}` from `BusinessIdentityService::current()`.
- `quickbooks`: `{answer:"yes"|"no"|null, connected:bool, has_snapshot:bool, parts:[{key,label,done,summary}], can_write:bool}`.
- `books`: `{acknowledged:bool}`. `accountant`: `{invited_name|null, logins:int}`. `done`: `{ready:bool, complete:bool}`.
Build these in `OnboardingApiPresenter` (pure, tested) from values the services return; the endpoint gathers.

## 5. QuickBooks connect from the phone
The web connect is `quickbooks.php?connect=1&t=<csrf>` → `QboConnectionService::startUrl($_SESSION)` →
Intuit → `quickbooks-callback.php` (`requireLogin()`, `finish($_SESSION, …)`). The phone has a JWT and no web
session, and Intuit returns to the phone's browser tab, so:

- New `GET /api/onboarding/quickbooks-start.php` (JWT + admin): if `missing()` is non-empty → 409
  `{ok:false,error:"not_set_up",message}`; else `startUrl($state)` where `$state` is an ARRAY the endpoint
  then stores as JSON in ops_settings `qbo_oauth_phone` = `{state, at, site, user_id}` (one at a time; a new
  start replaces it). Reply `{ok:true, url}`. The app opens `url` in the phone's browser (Custom Tab).
- `quickbooks-callback.php`: BEFORE `requireLogin()`, if there is no `$_SESSION['qbo_oauth']` and the
  `state` in the query matches the stored `qbo_oauth_phone` (same `stateOk()` rule: TTL, site, `hash_equals`),
  run the phone path: build `$session = ['qbo_oauth' => <stored>]`, call `cancel()`/`finish()` with it exactly
  as the web path does (user id from the stored state), delete `qbo_oauth_phone`, and render a small plain
  HTML page (no CRM chrome, Ittai palette tokens only, no inline script needed): "QuickBooks connected — go
  back to the Ittai app." or the failure message, with the same one-line `error_log`. A state that matches
  neither → the existing behaviour. The web path is unchanged.
- The redirect URI stays `QBO_REDIRECT_URI` / `DEFAULT_REDIRECT` (one per host; the Intuit app must list the
  tenant host's callback — operator's job, noted in the backlog).
- After the tab closes the app re-reads the map; `quickbooks.data.connected` turns true (`company()` non-null).

## 6. Local site
When `defined('LOCAL_TEST_SITE') && LOCAL_TEST_SITE === true` (secrets.php on the local site only):
signup.php's reply also carries `code` (the plain 6 digits) so the flow can be walked without mail; sending
still runs through `sendCrmEmail`/`sendSms` and their failures are ignored there. Never on production
(a test asserts the key is absent when the constant is undefined).

## 7. Files
Change: `public/api/auth/signup.php`, `app/Modules/Onboarding/Services/SignUpService.php`,
`OnboardingStepService.php`, `OnboardingApiPresenter.php`, `public/api/onboarding/map.php`,
`public/api/onboarding/step.php`, `public/crm/accounting/quickbooks-callback.php`,
`app/Modules/QuickBooks/Services/QboConnectionService.php` (only if `startUrl`/`finish` need an array
parameter instead of `$_SESSION` by reference — they already take `array &$session`, so probably not).
New: `public/api/auth/signup-confirm.php`, `public/api/onboarding/quickbooks-start.php`.
Tests (sqlite, as the existing `tests/Unit/Onboarding/*` do): `SignUpServiceTest` rewritten for v2 (name
split, mobile normalising, code request/replace, expiry, five tries, wrong code, right code claims with mobile
and WITHOUT touching company name/identity, token-failure rollback, local-only `code` key),
`OnboardingStepServiceTest` for every row of the table (QuickBooks and registry via test doubles — the
constructors already accept injected providers/clients), `OnboardingApiPresenterTest` for the `data`
objects, a `QboPhoneCallbackTest` for the stored-state path (pure `stateOk` + the store/clear helper).
Register new services in `tests/bootstrap.php`. Run the full suite.

## 8. Not in scope
Self-serve host creation, approval queue, iOS, any change to the web onboarding pages, `token.php`.

## 9. Test-writing note
In tests never put a quoted literal of 8+ characters right after a `password`/`secret` key (`'password' => 'a long value'`): the
copy scanner reads it as a secret and stops the build. Use a variable or `str_repeat('p', 17)`.
