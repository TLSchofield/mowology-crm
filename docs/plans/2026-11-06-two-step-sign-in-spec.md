# Build spec — two-step sign-in, web, opt-in (sign-in security plan step 2, 2026-11-06)

Plan: `docs/plans/2026-11-06-sign-in-security-plan.md` (approved). Tim: "two-step sign-in (authenticator codes, backup codes,
trusted devices), opt-in first". PHP 8.x syntax OK in new files but keep 7.4-compatible where the surrounding code is; MySQL 5.7;
no namespaces; services hold logic; AppStack page rules from CLAUDE.md (no inline `<style>`, `.mw-` classes in mowology-brand.css).

## Scope of this phase
- **Web sign-in only** (`public/loginAuth/login.php` → `loginUser()` in `app/Core/Auth/auth.php:275`). Phone token sign-in
  (`public/api/auth/token.php`) is **unchanged** in this phase (old apps can't show a code); say so in a comment there.
- **Opt-in**: nobody is asked for a code until they turn it on themselves. No "required" switch yet.

## Data — migration `database/migrations/1440_two_step_sign_in.sql` (+ copy in public/database/migrations if that is the convention — check how 1432–1435 are laid out and runner pages `public/crm/api/run-migration-*.php`; add a runner `run-migration-1440.php` like the recent ones: admin + POST + CSRF)
- `user_two_step` — `user_id INT PK`, `totp_secret_enc TEXT NOT NULL`, `enabled_at DATETIME NULL` (NULL = set up but not confirmed),
  `last_step BIGINT NULL` (replay guard), `created_at`.
- `user_backup_codes` — `id`, `user_id`, `code_hash VARCHAR(255)`, `used_at DATETIME NULL`, index on user_id.
- `user_trusted_devices` — `id`, `user_id`, `token_hash CHAR(64)`, `user_agent VARCHAR(255)`, `created_at`, `last_seen_at`, `expires_at`, index on token_hash.
- utf8mb4_general_ci; `CREATE TABLE IF NOT EXISTS`. Missing tables (migration not run in that business) = nobody enrolled — never an error,
  never a pass for someone who IS enrolled (if the tables exist and the user is enrolled, the code is required).

## Service — `app/Core/Auth/TwoStepService.php` (one place; unit-tested)
- **TOTP (RFC 6238)**: SHA1, 30 s, 6 digits, base32 160-bit secret from `random_bytes`; verify accepts ±1 step and refuses any step
  `<= last_step` (no reuse). Unit-test against RFC 6238 Appendix B vectors (adapted to 6 digits) and base32 round-trip.
- Secret stored encrypted with `QboTokenVault` (`app/Modules/QuickBooks/Services/QboTokenVault.php`) using `MFA_SECRET_KEY` if defined,
  else `QBO_TOKEN_KEY` (both live only in secrets.php). No key → two-step setup is unavailable (page says so); never store a plain secret.
- `status($userId)`, `beginSetup($userId)` (new secret, enabled_at NULL), `confirmSetup($userId, $code)` (enables; creates 10 backup codes,
  returns them once: 10 chars from an unambiguous alphabet, grouped `xxxxx-xxxxx`, stored with `password_hash`), `disable($userId)`
  (removes secret, codes and trusted devices), `regenerateBackupCodes($userId)`, `verify($userId, $input)` (TOTP or an unused backup code,
  which is then marked used), trusted devices: `trust($userId, $ua)` → raw token for the cookie, `isTrusted($userId, $rawToken)`,
  `devices($userId)`, `revokeDevice($userId, $id)`, `revokeAllDevices($userId)`.
- Log to activity_log: two-step turned on/off, backup code used, device trusted/revoked, failed code (no code values ever logged).

## Sign-in flow
1. `loginUser()` after a correct password: if the user has two-step enabled and the request has no valid trusted-device cookie for that
   user → do NOT set the signed-in session keys. Instead `session_regenerate_id(true)` and store
   `$_SESSION['two_step_pending'] = ['uid'=>…, 'at'=>time(), 'tries'=>0, 'is_driver'=>…, 'role'=>…, 'pwd_v'=>…]`, and make login.php
   send the browser to `/loginAuth/two-step.php`. Keep loginUser's signature/return for every other caller; add the smallest seam
   (e.g. a by-reference `$twoStepPending` flag, or a separate function) — read the whole function and both callers first.
   Factor the "set the signed-in session keys + post-login cleanup" block into one function used by both the password path and the
   code path, so the two can't drift.
2. `public/loginAuth/two-step.php` (same Ittai sign-in look as login.php: `signin-chrome.php`): one field "6-digit code from your
   authenticator app, or a backup code", a "Trust this device for 30 days" checkbox, CSRF (the stateless login CSRF login.php uses),
   Cancel link back to sign-in. Pending state expires after 5 minutes; 5 wrong codes → pending cleared, back to sign-in with a message,
   and the attempt counts against the existing login throttle (`recordFailedLogin`). Correct → full sign-in exactly as loginUser would
   (same destination rules as login.php: accountant home, driver portal, app-launch, terms gate) and, if ticked, set the trusted-device
   cookie: name per business like the session cookie (`ITTD_<key>` / `MWTD` for the default business), 30 days, Secure, HttpOnly,
   SameSite=Lax, path=/; value = raw token; DB keeps only sha256.
3. A password change (wherever `AuthFreshness`/`pwd_v` is bumped) and `disable()` revoke all trusted devices for that user.

## Settings page — `public/crm/account/two-step.php` (AppStack, any signed-in user, own account only)
- Off: explain in two sentences; "Turn on" → shows QR code + the key as text (grouped) + the `otpauth://totp/Ittai:<email>?secret=…&issuer=Ittai`
  link; field to enter the first code to confirm; then shows the 10 backup codes once with "I've saved these".
- On: status, "Get new backup codes", trusted devices list (browser, added, last used) with Revoke each / Revoke all, "Turn off" (asks for a
  current code or backup code first).
- QR: generate client-side with a small vendored MIT library saved to `public/crm/js/vendor/qrcode.min.js` (e.g. qrcode-generator by
  Kazuhiko Arase); if it can't be vendored, show key + link only and say so in the report. Add a link to this page wherever the user's
  own account/profile menu is (check `appstack_topbar.php`).
- Admin reset: on the employee edit screen, users with `users.manage` get "Turn off two-step sign-in" for another user (calls `disable()`,
  logged). Find the right file (app/Modules/Team/Api/employees.php + its page); keep it minimal.

## Tests (tests/Unit)
TOTP vectors, window, replay; base32; backup codes single-use and hashed; trusted token hashed, expiry, revoke; pending-state expiry and
5-try limit (pure helpers); missing tables → not enrolled; enrolled + tables → code required. Use sqlite in-memory like existing tests.

## Done when
`php -d memory_limit=2G vendor/bin/phpunit` passes (baseline 4188), `php -l` clean, local render of two-step.php and the settings page
(use the local php -S on public/ — the sign-in page renders without a DB), and a report ≤ 300 words listing files, the migration, and
anything unsettled.
