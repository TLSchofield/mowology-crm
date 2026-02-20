# jobFlow Changelog

## 2026-02-19 — Phases 1, 2, 3 (Full Rebuild)

### Phase 1 — Security Hardening

**CSRF:**
- Added `htmlspecialchars()` around all CSRF token outputs
- CSRF token now regenerated after Step 1 success (before redirect to confirm)
- CSRF token regenerated after Step 2 success (before redirect to success)
- `hash_equals()` timing-safe comparison used in both steps

**Input Validation (new: `helpers/validators.php`):**
- `validateQuoteForm()` — single function validates all Step 1 fields
- Email validated with `filter_var(FILTER_VALIDATE_EMAIL)`
- Phone requires exactly 10 digits; rejects partial numbers
- Service types whitelisted against `VALID_SERVICE_TYPES` constant
- Property type, urgency, preferred contact, address_relationship all whitelisted
- Description capped at 2000 chars via `strip_tags()` + substr
- Latitude/longitude cast to float with range validation
- All constants (`VALID_SERVICE_TYPES`, `VALID_PROPERTY_TYPES`, etc.) defined in validators.php

**Output Escaping:**
- `h()` helper defined in all three pages
- All dynamic output wrapped in `h()` or `htmlspecialchars(ENT_QUOTES, UTF-8)`
- CSRF token outputs now escaped
- Error messages escaped
- Review page data escaped

**Session Gate (`jobFlow-success.php`):**
- Direct URL access redirected to Step 1
- Requires `$_SESSION['quote_submitted'] = true` set by confirm.php after DB commit
- Session data consumed on first render (refresh → redirect to Step 1)

**Production Error Mode:**
- `display_errors` set to `0` in all three pages
- `log_errors` set to `1` — errors captured in server log, not exposed to browser

**Confirm page:**
- Added `$captchaOk` boolean to separate CSRF and reCAPTCHA error paths
- `match()` expression used for property type mapping (cleaner than if/else chain)
- Improved error logging with file:line context

---

### Phase 2 — Conversion Optimisation

**New: `helpers/pricing.php`**
- `getPricingConfig()` — master pricing table; all prices in one place
- `calculateEstimate()` — per-visit price range with upsell additions and irrigation surcharge
- `getRelevantUpsells()` — returns contextual upsells based on selected services
- `getSeasonalMessage()` — returns seasonal urgency/tip banner content
- `getResponseTimeBadge()` — dynamic response time trust message

**Step 1 (getQuote.php):**
- Trust badges row below header (free estimate, no obligation, response time)
- Seasonal banner (April/May urgency, March/October tips, snow season tip)
- Service selection redesigned as visual icon cards (checkboxes hidden, visual toggle)
- Lawn size selector (small/medium/large) — shown when lawn service selected
- Irrigation toggle — shown when lawn service selected
- Form section headings ("Your Details", "Property Details", "Services Needed")
- Optional email field labelled clearly as optional
- Phone placeholder `(604) 555-1234` for format guidance
- Privacy note below submit button
- CSRF token and error messages now all escaped

**Step 2 (confirm.php):**
- Price estimate display (range, note) from `calculateEstimate()`
- Upsell cards section — contextual upsells with badges, descriptions, prices
- Upsells captured from POST, stored in session, written to classification notes
- Availability urgency banner (spring months or asap urgency)
- Lawn size and irrigation displayed in review table if provided
- JavaScript moves upsell checkboxes into confirm form before submit

**Step 3 (success.php):**
- Personalised greeting "Thank you, [First Name]!"
- Enhanced next-steps section with icons and descriptive text
- SMS opt-in nudge (shown only when consent was not given)
- Referral incentive box ($25 credit for neighbour referral)
- Info row layout improved with strong/description structure

**CSS additions (`jobflow-quote.css`):**
- `.trust-badges`, `.trust-badge`
- `.seasonal-banner`, `.seasonal-urgency`, `.seasonal-tip`, `.seasonal-icon`
- `.form-section-title`
- `.service-grid`, `.service-card`, `.service-icon`, `.service-label`
- `.size-selector`, `.size-card`, `.size-label`
- `.toggle-label`
- `.optional-label`, `.consent-intro`, `.form-privacy-note`

**CSS additions (`jobflow-confirm.css`):**
- `.estimate-section`, `.price-estimate`, `.price-range`, `.price-note`
- `.upsell-section`, `.upsell-grid`, `.upsell-card`, `.upsell-badge`, `.upsell-price`, `.upsell-desc`, `.upsell-note`
- `.urgency-banner`

**CSS additions (`jobflow-success.css`):**
- `.success-lead`
- `.info-row div` (flex column with gap)
- `.sms-nudge`, `.referral-box`, `.success-actions`

---

### Phase 3 — Automation

**New: `helpers/classification.php`**
- `classifyJobType()` — rule-based job type label; AI extension hook documented
- `classifyValueTier()` — 10-point scoring system → high/medium/low
- `isHighPriorityLead()` — boolean priority flag
- `suggestFrequency()` — recommended service frequency
- `classifyLead()` — composite function returning all classification data + tag array

**confirm.php automation:**
- `classifyLead()` called before DB write
- Classification metadata appended to `project_description` as structured suffix
- Activity log entry includes value tier and `[PRIORITY]` flag
- Internal notification email receives `value_tier`, `is_priority`, and `upsells` fields
- Session keys `quote_submitted`, `submitted_name`, `submitted_sms` set for success.php

**UTM tracking:**
- All jf_track params capped at 100 chars each (was unbounded)
- `utm_term` added to trackable params list

---

### Files Created

- `helpers/validators.php`
- `helpers/pricing.php`
- `helpers/classification.php`
- `helpers/` directory
- `config/` directory (reserved)
- `JOBFLOW_ARCHITECTURE.md`
- `JOBFLOW_SECURITY.md`
- `JOBFLOW_MONETIZATION.md`
- `JOBFLOW_AUTOMATION.md`
- `CHANGELOG.md`

### Files Modified

- `jobFlow-getQuote.php` — full rewrite (same URL, same flow)
- `jobFlow-confirm.php` — full rewrite (same URL, same flow)
- `jobFlow-success.php` — full rewrite (same URL)
- `assets/css/pages/jobflow-quote.css` — Phase 2 CSS appended
- `assets/css/pages/jobflow-confirm.css` — Phase 2 CSS appended
- `assets/css/pages/jobflow-success.css` — Phase 2 CSS appended

### Files NOT Modified

- `recaptcha-helpers.php` — no changes needed (already correct)
- `includes/notifications.php` — no structural changes (caller passes extra fields)
- URL structure — all three pages at same paths, no redirect needed
