# Server side of the phone sign-up → onboarding contract (Codex web build task, 2026-10-26)

The Android crew app (mowology-android-codex `codex/native`, behind `android.native_signup`) already calls the
three endpoints below and nothing else. This task builds them. The app is the client of record: field names,
status codes and body shapes are fixed by it — do not rename anything.

Context you need (read these files first, they are in scope):
- `public/api/auth/token.php` — the mobile sign-in. Sign-up must issue a token byte-for-byte compatible with it
  (same claims, same tenant-derived secret `jwtSecret()`, same `pwd_v`, `aud` = `ios`, 30 days).
- `app/Core/Auth/JwtAuth.php` (`requireJwt()`, `jwtIsAdmin()`, `jwtSecret()`, `jwtPasswordVersionFor()`),
  `app/Core/Auth/JwtService.php`, `app/Core/Auth/auth.php` (`isLoginRateLimited` / `recordFailedLogin` /
  `clearLoginAttempts`).
- `app/Core/Tenant.php` (`Tenant::key()`, `isDefault()`, `appUrl()`), `app/Core/TenantProvisioner.php` (what a
  freshly provisioned business already has: `business_settings` row with `company_name` + `company_email`, ONE
  user — the owner, `role='admin'`, the owner's email, a one-time password hash, `username = <key>-owner`).
- `app/Modules/Onboarding/Services/OnboardingService.php`, `OnboardingModule.php` (`OnboardingContext`),
  `OnboardingModules.php` (`OnboardingNameModule::save/saveReach`, `OnboardingCompanyModule::state`).
- `app/Modules/Settings/Services/BusinessIdentityService.php` (`manual()` writes `business_identity`),
  `app/Modules/Settings/Services/Registry/OrgBookBcProvider.php` (constructor arg only; `manual()` makes no
  network call).
- `app/Services/BusinessIdentity.php` — reads `business_settings.company_name`, `business_identity`,
  `ops_settings business_trade / business_city / owner_user_id`.
- `tests/Unit/Onboarding/OnboardingServiceTest.php` — the sqlite in-memory pattern to copy for new tests.

Rules of this repo that apply (CLAUDE.md): PHP 7.4 syntax, MySQL 5.7 SQL, prepared statements, no autoloader
(`require_once` + `new`), **no business logic in the endpoint files** — they are thin controllers over a service
in `app/Modules/Onboarding/Services/`, every new service registered in `tests/bootstrap.php` and covered in
`tests/Unit/`. No trade-specific words baked in (multi-discipline tenants). Never touch `secrets.php`,
`token.php`'s behaviour, or anything under `public/crinum`.

Routing fact: `public/api/.htaccess` rewrites only `/api/<module>/<action>` WITHOUT `.php`, and the local test
router does the same; a request to `/api/onboarding/map.php` must therefore hit a REAL file. So the two
onboarding endpoints are real files under `public/api/onboarding/`, exactly like `public/api/auth/token.php`
(same upward `paths.php` search, same JSON headers). Do NOT add `onboarding` to the `$moduleMap` in
`public/api/index.php`.

──────────────────────────────────────────────────────────────────────────────
1. `POST /api/auth/signup.php`  — new file `public/api/auth/signup.php`, logic in new
   `app/Modules/Onboarding/Services/SignUpService.php`
──────────────────────────────────────────────────────────────────────────────
No auth. JSON body: `{business_name, trade, owner_first, owner_last, email, password, audience:"mobile"}`.
`trade` ∈ `gardening | landscaping | lawn_care | snow | cleaning | other` (keep the list as a public const on
the service: `SignUpService::TRADES`).

Semantics — "claiming" a provisioned business. The request arrives at the business's own host
(`<key>.ittai.ca`), so `Tenant::key()` is already that business and `getDB()` is its database. The business row
and its owner login already exist (TenantProvisioner, run by the operator from the name and email the owner
gave). Sign-up claims it:

  a. `Tenant::isDefault()` (the original business, mowology.ca) → **404** `{ok:false, error:"not_available"}`.
     Also 404 when the tenant has no `users` row at all (nothing to claim — provisioning did not finish).
  b. Method ≠ POST → 405. Body not JSON / fields missing → treat missing as empty and validate.
  c. Validate every field; collect ALL failures, return **400** `{ok:false, error:"validation", fields:{<field>:
     "<message>"}}` with these exact rules and messages (the app shows them under the fields):
       business_name  trimmed length 2–60      "Enter a business name (2–60 characters)."
                      (2, not the app's 1: BusinessIdentityService::manual() keeps the web's two-character minimum;
                      the app shows the server's field message — decided at review 2026-10-26)
       trade          in TRADES                "Choose a trade."
       owner_first    trimmed 1–40             "Enter the owner's first name (1–40 characters)."
       owner_last     trimmed 1–40             "Enter the owner's last name (1–40 characters)."
       email          FILTER_VALIDATE_EMAIL    "Enter a valid email address."
       password       strlen ≥ 10              "Use at least 10 characters."
     Put the rules in a pure static `SignUpService::validate(array $in): array` (tested).
  d. Rate limit BEFORE touching the database with credentials: `isLoginRateLimited('signup:' . $email, $ip)` →
     **429** `{ok:false, error:"rate_limited"}`. Every 400/409 below calls
     `recordFailedLogin('signup:' . $email, $ip)`; success calls `clearLoginAttempts(...)`.
  e. Claimed check (pure rule `SignUpService::claimState(array $owner, ?string $claimedAt): string` returning
     `'unclaimed' | 'claimed'`, tested): the business is CLAIMED when `ops_settings tenant_claimed_at` is set,
     OR the owner row (`role='admin'`, lowest id) has `last_login IS NOT NULL` (they already signed in with the
     one-time password). Claimed → **409** `{ok:false, error:"taken"}`.
  f. The email must be the provisioned owner's email (case-insensitive, trimmed). Otherwise **400**
     `{ok:false, error:"validation", fields:{email:"Use the email address this business was set up with."}}`.
     (Why: the host alone is guessable before the owner claims it; the email the owner gave the operator is the
     shared secret. Say this in a comment.)
  g. On success, in ONE transaction on the tenant DB:
       - `users` (the owner row): `password_hash = password_hash($password, PASSWORD_DEFAULT)`, `is_active = 1`,
         then `OnboardingNameModule::save($ctx, $first, $last, '')` (sets first/last/full/preferred). Do not
         create a second user.
       - `business_settings` (id 1 / the first row): `company_name = business_name`.
       - `business_identity`: `(new BusinessIdentityService($db, new OrgBookBcProvider($dbName), $ownerId))
         ->manual(['legal_name' => $businessName])` — the same write the web's manual form does, provenance
         `owner_provided`; the owner can refine it with the registry lookup on the web later. If that call
         returns `ok === false`, roll back and answer 500 `{ok:false, error:"server"}`.
       - `ops_settings` (via `OnboardingContext::saveSetting`, descriptions as in the codebase style):
         `business_trade = trade`, `owner_user_id = ownerId`, `tenant_claimed_at = now (Y-m-d H:i:s)`.
       - `activity_log`: `(user_id, client_id NULL, action 'Sign-up (mobile)', details 'Business claimed, JWT
         issued', ip_address)` — non-fatal if it fails (same as token.php).
  h. Issue the token: add `function generateMobileJwt(array $user, int $ttl = 2592000): string` to
     `app/Core/Auth/JwtService.php` that builds EXACTLY token.php's mobile payload (`iss mowology`, `aud ios`,
     `sub`, `email`, `name` = full_name, `role`, `iat`, `exp`, `pwd_v = substr(sha256(password_hash), 0, 8)`),
     signed with `jwtSecret()`. Do NOT change `token.php` or `generateMowologyJwt()`. Unit-test that the payload
     keys and `pwd_v` derivation match token.php (decode the middle segment).
  i. **201**:
     ```
     {ok:true, token, user:{id, email, name, role:"admin", business_name},
      expires_in: 2592000,
      business:{key: Tenant::key(), host: <host part of Tenant::appUrl()>, name: business_name},
      onboarding:{next: <OnboardingService::next() after the writes — "name" (reach/quiet parts remain) or null>}}
     ```
     Headers as token.php: `Content-Type: application/json; charset=utf-8`, `X-Content-Type-Options: nosniff`;
     no CORS grant for an absent Origin. Never log or echo the password.

──────────────────────────────────────────────────────────────────────────────
2. `GET|POST /api/onboarding/map.php` — new file `public/api/onboarding/map.php`
──────────────────────────────────────────────────────────────────────────────
`$jwtUser = requireJwt();` then `$svc = new OnboardingService(new OnboardingContext(getDB(), (int)$jwtUser['id']))`.
Put the body-building in a small pure static on a new `app/Modules/Onboarding/Services/OnboardingApiPresenter.php`
(or a static on SignUpService — your call, but pure and tested):

  GET → **200**
  ```
  {ok:true,
   steps:[{key, title, state: "done"|"next"|"todo"|"skipped"|"not_needed", head: <row['owner'] display name>,
           skippable: bool}],   // in OnboardingService::map() order, every row including "done"
   next: <key|null>, ready_to_finish: bool}
  ```
  (map() rows already carry `state` with those values and `owner` = the head's shown name; pass them through,
  rename `owner` → `head`. Drop `pinned`.)

  POST JSON `{key, do}` with `do` ∈ `skip | unskip | move_up | move_down`:
    - `jwtIsAdmin($jwtUser['role'])` required → else **403** `{ok:false, error:"forbidden"}`.
    - unknown key / unknown do → **400** `{ok:false, error:"validation", message}`.
    - `skip` → `$svc->skip($key, true)`, `unskip` → `skip($key, false)`, `move_up` → `move($key, -1)`,
      `move_down` → `move($key, +1)`. These return arrays; if one reports a refusal (look at how index.php reads
      them — e.g. `['ok' => false, 'message' => …]`), answer **400** `{ok:false, error:"refused", message}`.
    - then the same 200 body as GET.

──────────────────────────────────────────────────────────────────────────────
3. `POST /api/onboarding/step.php` — new file `public/api/onboarding/step.php`
──────────────────────────────────────────────────────────────────────────────
`requireJwt()`, admin/manager only (403 otherwise). JSON body by `key`:
  - `{key:"name", first, last, preferred?, channel?, mobile?}` → `OnboardingNameModule::save($ctx, first, last,
    preferred ?? '')`; if `channel` is non-empty also `saveReach($ctx, channel, mobile ?? '')`. Either returning
    `ok:false` → **400** `{ok:false, error:"validation", message}` (the module's own message).
  - `{key:"company", name}` → `BusinessIdentityService::manual(['legal_name' => trim(name)])` as in 1g;
    `ok:false` / InvalidArgumentException → 400 with the message.
  - any other key → **400** `{ok:false, error:"validation", message:"That step has no phone form yet."}`.
  Success → **200** `{ok:true, next: <OnboardingService::next()>}`.
  Put the per-key dispatch in the service (`SignUpService::applyStep(...)` or a `OnboardingStepService`), the
  endpoint stays a thin controller.

──────────────────────────────────────────────────────────────────────────────
Tests (phpunit, sqlite in-memory, no network) — add under `tests/Unit/Onboarding/`
──────────────────────────────────────────────────────────────────────────────
  - `SignUpServiceTest`: validate() collects every failure with the exact messages; TRADES; claimState() for
    unclaimed / claimed-by-setting / claimed-by-last_login; the owner-email rule; a full claim against the sqlite
    schema (tables `users`, `business_settings`, `business_identity`, `ops_settings`) leaves password_hash
    verifying, names set, company_name set, business_trade / owner_user_id / tenant_claimed_at set, and
    `next()` === 'name'; a second claim is refused.
  - `OnboardingApiPresenterTest` (or inside the above): map body shape from a map() fixture; `owner`→`head`.
  - `JwtServiceTest`: generateMobileJwt payload keys/values and pwd_v derivation (define `BLUEMOON_JWT_SECRET`
    in the test only if not defined; `Tenant::forTests(null)` for the default key).
  Register new service files in `tests/bootstrap.php` next to the existing Onboarding requires (line ~451).
  The whole suite must pass: `php -d memory_limit=1G vendor/bin/phpunit`.

Deliver: the new files, the JwtService addition, bootstrap lines, tests. In the summary say which refusal
shapes `skip()`/`move()` return and how you mapped them, and anything in the contract you could not satisfy.
