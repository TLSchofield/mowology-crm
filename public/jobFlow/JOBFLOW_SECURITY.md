# jobFlow Security

## Phase 1 Hardening — Implemented Changes

### 1. CSRF Enforcement

**Before:** Tokens used but not regenerated after Step 1 success; CSRF token output lacked `htmlspecialchars()`.

**After:**
- Both tokens (`csrf_token`, `csrf_confirm`) use `bin2hex(random_bytes(32))` — 256-bit entropy.
- All form output uses `h()` / `htmlspecialchars(ENT_QUOTES, UTF-8)`.
- Step 1 token is regenerated after successful validation (before redirect to confirm).
- Step 2 token is regenerated after successful DB commit (before redirect to success).
- Verification uses `hash_equals()` (timing-safe) in both steps.

### 2. Strict Validation (`helpers/validators.php`)

All input is validated in `validateQuoteForm()` before being stored in session.

| Field | Validation Applied |
|-------|--------------------|
| first_name, last_name | Strip non-alpha chars, max 80 chars |
| email | `filter_var(FILTER_VALIDATE_EMAIL)` or null; empty is allowed |
| phone | Strip non-digits, require exactly 10 digits; format to `(XXX) XXX-XXXX` or reject |
| address | Strip + title-case; max 255 chars |
| city | Strip + title-case; max 100 chars |
| postal_code | Strip non-alphanumeric, require 6 chars; format to `A1A 1A1` or null |
| latitude / longitude | `filter_var(FILTER_VALIDATE_FLOAT)`, range checked (-90/90, -180/180) |
| property_type | Whitelist against `VALID_PROPERTY_TYPES` |
| service_types | Each value whitelist against `VALID_SERVICE_TYPES`; unknown values silently dropped |
| urgency | Whitelist against `VALID_URGENCY_VALUES` |
| preferred_contact | Whitelist against `VALID_PREFERRED_CONTACT` |
| description | `strip_tags()`, normalise whitespace, max 2000 chars |
| lawn_size | Whitelist against `VALID_LAWN_SIZES` |
| address_relationship | Not stored; no longer used from POST |

In confirm.php, session data is re-normalised using `whitelistValue()` on all enum fields before any database write. This double-validates: once at Step 1, once at Step 2.

### 3. Output Escaping

All dynamic output is wrapped in `h()` (`htmlspecialchars(ENT_QUOTES, UTF-8)`).

- Form values: `h($formData['field'])`
- Error messages: `h($error)`, `h($errors['field'])`
- CSRF tokens: `h($_SESSION['csrf_token'])`
- Review page: all `$data[]` fields escaped with `h()`
- Success page: `htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8')`
- `nl2br(h($description))` — escape first, then add line breaks

No raw POST data is ever rendered to HTML.

### 4. Session Gate on success.php

**Before:** Direct URL access (`/jobFlow/jobFlow-success.php`) worked with no session check.

**After:** `jobFlow-success.php` redirects to Step 1 unless `$_SESSION['quote_submitted'] === true`. This flag is set exclusively by `confirm.php` after a successful database commit. It is immediately unset on first render of success.php, so page refresh also redirects.

### 5. Production Error Mode

**Before:** `display_errors = 1` in both pages — PHP errors exposed to browser.

**After:**
- `ini_set('display_errors', '0')` in all three pages
- `ini_set('log_errors', '1')` — errors still captured in server error log
- `error_reporting(E_ALL)` — still catch everything, just not display

### 6. reCAPTCHA Enforcement

No changes were needed to the core reCAPTCHA logic (already well-implemented). Changes made:
- Confirm page now uses `$captchaOk` boolean to separate CSRF error from reCAPTCHA error paths
- Error handling is cleaner with explicit `$captchaOk = false/true` tracking

### 7. Include Paths

All includes use anchored paths:
- `dirname(__DIR__)` — from `/public/jobFlow/` reaches `/public/`
- `__DIR__` — from `/public/jobFlow/` reaches `/public/jobFlow/`
- No fragile relative paths (`../../../`) anywhere

## Remaining Considerations

- **Rate limiting**: No IP-based rate limiting on form submissions. reCAPTCHA provides bot protection but not rate limiting for authenticated humans. Future: consider adding a simple counter in `$_SESSION` or a Redis-based rate limiter.
- **Phone deduplication**: Contacts are matched by exact phone or email. Two submissions with different formatting of the same number could create duplicate contacts.
- **Session expiry**: Session data has no explicit TTL. PHP session GC handles cleanup. Old sessions with stale `quote_data` could theoretically be resubmitted, but the DB operation is idempotent (find-or-create contact/property).
