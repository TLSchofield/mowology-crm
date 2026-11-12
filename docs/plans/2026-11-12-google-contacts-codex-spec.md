# Google contacts in onboarding — server task (Codex web build)

Tim, 2026-11-12 (plan approved: `/Users/timschofield/.claude/plans/playful-strolling-planet.md`). The owner's Google contacts join the phone
contacts as a second way into the existing `contacts` onboarding step. Server half; the iPhone is a separate task.

Rules: PHP 7.4+, MySQL 5.7 (no window/JSON functions), prepared statements, no autoloader (`require_once` + `new`), new logic in services
with PHPUnit tests registered in `tests/bootstrap.php`, nothing Mowology-specific in copy. **Never log or store a Google token, never log
contact names/emails/numbers.** Read `app/Modules/Contacts/Services/ContactImportService.php`, `app/Modules/Onboarding/Services/OnboardingStepService.php`,
`public/api/onboarding/step.php`, `app/Modules/QuickBooks/Services/QboOAuth.php` (the pure-helper + injected-HTTP style to copy) and
`public/ittai.ca/api/sign-in.php` (directory mode) first.

## Flow
1. App (JWT, admin) → `POST /api/onboarding/step.php {key:"contacts", do:"google_start", knows:"contacts"}` →
   `{ok:true, url, nonce}`: the Google consent URL. Error `not_configured` (message "Google is not set up on this server yet.") when
   `ITTAI_GOOGLE_WEB_CLIENT_ID` or `ITTAI_GOOGLE_WEB_CLIENT_SECRET` is undefined or empty.
2. The phone opens `url` in a browser session. Google redirects to `https://ittai.ca/api/google-contacts-callback.php?code=…&state=…`.
3. The callback (directory mode, no business database of its own) verifies `state`, opens that business's DB with `Database::forBusiness($key)`,
   exchanges the code, fetches the contacts, stages them, then answers `302 Location: ittai-google://done?ok=1&nonce=<nonce>`.
   Any failure: `302 ittai-google://done?ok=0&reason=<denied|expired|failed>` — `denied` when Google sent `error=access_denied`, `expired` for a bad or
   old state, `failed` otherwise (log one `error_log` line with the HTTP status only, no body, no token).
4. App → `{key:"contacts", do:"google_list", nonce}` → `{ok:true, contacts:[…]}` in the **same wire shape the phone sends**:
   `{ref, first, last, company, emails:[string], phones:[{label, number}], address:{street, city, province, postal}|null}`. Staged rows are
   deleted when read (second call: `{ok:false, error:"expired", message:"That Google list is no longer available. Start again."}`).
5. The app then calls the existing `do:"import"` (batches ≤200) with an extra `source:"google"`; `finish` and `skip` are unchanged.

## Files
- `app/Modules/Contacts/Services/GoogleContactsOAuth.php` — pure statics + an instance with an injected HTTP callable (`fn(string $method, string $url, array $headers, ?string $body): array{status:int, body:string}`):
  - `authUrl(clientId, redirectUri, state)`: `https://accounts.google.com/o/oauth2/v2/auth`, `response_type=code`, scope `https://www.googleapis.com/auth/contacts.readonly`,
    `access_type=online`, `prompt=select_account`, `include_granted_scopes=false`.
  - `signState(array $payload, string $secret): string` = `base64url(json) . '.' . hash_hmac('sha256', part, $secret)`; payload `{b: businessKey, u: userId, n: nonce(32 hex), e: expiry unix}`.
    `verifyState(string, string $secret, int $now): ?array` — constant-time compare (`hash_equals`), null when malformed/tampered/expired.
  - `exchangeCode(code, redirectUri)` → POST `https://oauth2.googleapis.com/token` form (`code, client_id, client_secret, redirect_uri, grant_type=authorization_code`) → access token string or null.
  - `fetchContacts(accessToken)` → GET `https://people.googleapis.com/v1/people/me/connections?personFields=names,emailAddresses,phoneNumbers,addresses,organizations&pageSize=1000&sortOrder=FIRST_NAME_ASCENDING[&pageToken=…]`
    with `Authorization: Bearer`, follow `nextPageToken` up to **5 pages**; map with `mapPerson`; drop nulls; sort by display name; return the list. A non-200 throws `RuntimeException('Google People returned HTTP N')`.
  - `mapPerson(array $person): ?array`: `ref` = `resourceName`; first/last = `names[0].givenName/familyName`; company = `organizations[0].name`; emails = every `emailAddresses[].value`;
    phones = `phoneNumbers[]` with label = lowercased `type` (else `formattedType`, else "other") and number = `value`; address = first `addresses[]` with street from `streetAddress`
    (newlines → ", "), `city`, `region` → province, `postalCode` → postal, else null. Return null when there is no name, no company, no email and no phone.
- `app/Modules/Contacts/Services/GoogleContactsStageService.php` (PDO): `stage(string $nonce, int $userId, array $contacts): void`, `take(string $nonce, int $userId): ?array`
  (null when missing, wrong user, or older than 30 min; deletes the row either way), and every call first deletes rows older than 30 min.
- `database/migrations/1452_onboarding_google_contacts_stage.sql`: `CREATE TABLE IF NOT EXISTS onboarding_google_contacts_stage (nonce CHAR(32) NOT NULL PRIMARY KEY, user_id INT NOT NULL, payload MEDIUMTEXT NOT NULL, created_at DATETIME NOT NULL, KEY idx_created (created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;` (no DELIMITER, safe to run twice).
- `public/ittai.ca/api/google-contacts-callback.php`: directory mode exactly like `sign-in.php` (define `ITTAI_DIRECTORY_MODE` first, same bootstrap, also require `app/Core/Auth/JwtAuth.php` is NOT needed). State secret =
  `hash_hmac('sha256', 'google-contacts-state', BLUEMOON_JWT_SECRET)` (use a small shared static `GoogleContactsOAuth::stateSecret()` so start and callback agree; fail closed when the constant is short/missing).
  GET only (405 otherwise). Reads `code`, `state`, `error`. Never echoes input.
- `OnboardingStepService` (key `contacts`): add `google_start` (build state `{b: Tenant::key(), u: $userId, n: random 32 hex, e: now+600}`, return `{ok, url, nonce}`), `google_list`, and accept `source` on `import`.
  Inject the OAuth/stage collaborators via an optional constructor callable like the existing factories so tests need no network. The redirect URI constant lives in `GoogleContactsOAuth::REDIRECT_URI = 'https://ittai.ca/api/google-contacts-callback.php'`.
- `public/api/onboarding/step.php`: pass `url`, `nonce`, `contacts` through to the JSON output the same way `results` is.
- `ContactImportService::import(array $rows, string $source = 'phone')`: the contact note becomes "Imported from Google contacts." when `$source === 'google'`, else unchanged. Nothing else about matching or consent changes (still no marketing/SMS/follow-up consent).
- `tests/bootstrap.php`: register the two new services. Tests: `tests/Unit/Contacts/GoogleContactsOAuthTest.php` (state round trip, tamper, expiry, wrong secret, authUrl params, mapPerson incl. no-name null and address newlines, exchange success/failure with a fake HTTP, pagination stops at 5 pages and follows tokens, non-200 throws),
  `GoogleContactsStageServiceTest.php` (stage/take, one-time, wrong user, expiry), and additions to `tests/Unit/Onboarding/OnboardingStepServiceTest.php` (not_configured, google_start returns url+nonce, google_list expired, source reaches the import note).

## Do not
No token storage, no refresh tokens, no `secrets.php` edits (Tim adds the client secret), no changes to other onboarding steps, no Gmail scopes, no new composer packages.

## Addendum 2026-11-12 (security fix, already in the code)
`stage(nonce, userId, contacts, grant)` keeps only `sha256(grant)` (`grant_hash` column); the callback creates the grant and puts it only in the redirect (`&grant=`); `google_list` needs `nonce` + `grant` and `take()` compares with `hash_equals`. A wrong user, nonce-only or wrong grant neither returns nor consumes the list. State expiry is 5 minutes.
