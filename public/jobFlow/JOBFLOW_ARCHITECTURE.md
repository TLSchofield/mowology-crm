# jobFlow Architecture

## Overview

jobFlow is a self-contained, three-step quote funnel for Mowology's website. It is completely independent from the CRM and the public site includes system. It uses its own HTML structure, session management, and helper library.

## Directory Structure

```
/public/jobFlow/
├── jobFlow-getQuote.php        # Step 1: Quote request form
├── jobFlow-confirm.php         # Step 2: Review & confirm
├── jobFlow-success.php         # Step 3: Success / thank you
├── recaptcha-helpers.php       # reCAPTCHA v2/v3 verification library
├── helpers/
│   ├── validators.php          # Input validation & sanitisation
│   ├── pricing.php             # All pricing logic (Phase 2)
│   └── classification.php      # Lead classification (Phase 3)
├── config/                     # Reserved for future config files
├── JOBFLOW_ARCHITECTURE.md
├── JOBFLOW_SECURITY.md
├── JOBFLOW_MONETIZATION.md
├── JOBFLOW_AUTOMATION.md
└── CHANGELOG.md
```

## Step Flow

```
GET  /jobFlow/jobFlow-getQuote.php    → Show quote form (Step 1)
POST /jobFlow/jobFlow-getQuote.php    → Validate → session → confirm
     ├─ address_confirmed=1           → address choice sub-step
     └─ main form                     → redirect to confirm
GET  /jobFlow/jobFlow-confirm.php     → Show review + upsells
POST /jobFlow/jobFlow-confirm.php     → Validate → write DB → redirect
GET  /jobFlow/jobFlow-success.php     → Session-gated thank you page
```

## Session Keys

| Key | Owner | Purpose |
|-----|-------|---------|
| `csrf_token` | getQuote | Step 1 CSRF protection |
| `csrf_confirm` | confirm | Step 2 CSRF protection |
| `jf_track` | getQuote | Campaign/UTM tracking array |
| `quote_data` | getQuote | Clean validated quote data |
| `temp_quote_data` | getQuote | Temp data for address confirmation sub-step |
| `quote_submitted` | confirm | Session gate flag for success.php |
| `submitted_name` | confirm | First name for personalised success message |
| `submitted_sms` | confirm | SMS consent flag for nudge rendering |

## Include Chain

```
getQuote.php / confirm.php / success.php
├── dirname(__DIR__)/app_config/session_config.php   [shim → /app/Core/session_config.php]
├── dirname(__DIR__)/app_config/config.php           [shim → /app/Core/config.php]
├── __DIR__/recaptcha-helpers.php                    [local]
├── __DIR__/helpers/validators.php                   [local]
├── __DIR__/helpers/pricing.php                      [local - Phase 2]
└── __DIR__/helpers/classification.php               [local - Phase 3]

confirm.php additionally:
├── dirname(__DIR__)/includes/notifications.php      [email+SMS]
└── dirname(__DIR__)/crm/includes/roi-functions.php  [shim → /app/Modules/Portfolio/Services/RoiFunctions.php]
```

## Database Tables Modified

| Table | Operation | When |
|-------|-----------|------|
| `contacts` | SELECT, INSERT | confirm POST |
| `properties` | SELECT, INSERT, UPDATE | confirm POST |
| `quote_requests` | INSERT | confirm POST |
| `consent_log` | INSERT ×3 | confirm POST |
| `activity_log` | INSERT | confirm POST |
| `contacts` | UPDATE (consent cols) | confirm POST |
| ROI tracking tables | via roi-functions.php | confirm POST |

## CSS Architecture

Each step has its own CSS file loaded via `master.css`:

```
/assets/css/pages/jobflow-quote.css    → Step 1 styles
/assets/css/pages/jobflow-confirm.css  → Step 2 styles
/assets/css/pages/jobflow-success.css  → Step 3 styles
```

All CSS uses public site tokens (`--mowology-*`). No inline styles.

## Extension Points

- **New fields**: Add to `validateQuoteForm()` in validators.php, store in session, display in confirm.php
- **New services**: Add to `VALID_SERVICE_TYPES` and `$serviceOptions` in getQuote.php
- **New upsells**: Add to `getPricingConfig()['upsells']` and update `getRelevantUpsells()` logic
- **New steps**: Insert between existing steps; session keys carry data forward
- **New DB columns**: Add to confirm.php INSERT/UPDATE statements; use fallback pattern for schema evolution
