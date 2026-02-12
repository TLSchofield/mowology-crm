# Mowology — System Architecture

Companion to `CLAUDE.md` (rules). This file documents what exists and where.

---

## Directory Map

```
/Users/timschofield/Projects/mowology-crm/
│
├── CLAUDE.md                          ← AI editing rules
├── ARCHITECTURE.md                    ← This file
├── README.md                          ← Project overview
├── LICENSE.txt                        ← License
├── PHASE_1_SUMMARY.md                 ← Phase 1 build summary
├── database/                          ← Database schema & migrations
│   ├── SCHEMA_MASTER.sql
│   ├── README.md
│   └── migrations/
│       ├── 001_restructure_core_tables.sql
│       ├── 002_property_measurements.sql
│       ├── 007_job_system.sql
│       └── create_password_reset_tokens_table.sql
│
└── public/                            ← Web root (DocumentRoot on cPanel)
    │
    ├── index.php                      ← Public homepage
    ├── services.php                   ← Services overview page
    ├── portfolio.php                  ← Portfolio page
    ├── about.php                      ← About page
    ├── contact.php                    ← Contact page
    ├── quote.php                      ← /quote redirect → jobFlow-getQuote.php (passes query params)
    ├── script.js                      ← Public site JS (mobile menu, etc.)
    ├── robots.txt                     ← Search engine directives
    ├── .htaccess                      ← Apache: rewrites (/quote, /services/slug), file protection
    ├── .gitignore                     ← Excludes secrets, logs, IDE, OS files
    ├── php.ini                        ← PHP overrides for cPanel
    │
    ├── services/                      ← Service landing pages (conversion-optimized, one per service)
    │   ├── strata-landscaping-maintenance.php  ← /services/strata-landscaping-maintenance
    │   ├── commercial-landscape-maintenance.php ← /services/commercial-landscape-maintenance
    │   └── hedge-trimming.php                   ← /services/hedge-trimming
    │
    ├── app_config/                    ← Configuration (NOT in git)
    │   ├── config.php                 ← DB connection (Database class, h(), csrf helpers)
    │   ├── secrets.php                ← Credentials, API keys (NEVER edit via AI)
    │   └── session_config.php         ← Session hardening, cookie params, save path
    │
    ├── includes/                      ← Public site shared components (LOCKED LAYOUT)
    │   ├── bootstrap.php              ← Session start, site constants (SITE_NAME, SITE_URL, SITE_LOCALE, etc.), h(), asset()
    │   ├── head.php                   ← <!DOCTYPE>, <html>, <head>: title, meta desc/keywords, canonical URL,
    │   │                                 OpenGraph, Twitter Card, favicon, Google Fonts, master.css, analytics placeholder
    │   │                                 Variables: $pageTitle, $pageDescription, $pageKeywords, $pageImage, $extraHead
    │   ├── header.php                 ← <body>, <header>, <nav> with root-relative links
    │   │                                 Variable: $activeNav (home|services|portfolio|about|contact)
    │   ├── footer.php                 ← <footer>, /script.js, </body>, </html>
    │   ├── functions.php              ← Shared utility functions
    │   ├── notifications.php          ← Flash message display
    │   ├── service-template.php       ← Rendering engine for service landing pages
    │   │                                 Receives $service array → renders hero, proof sections, FAQ, CTA
    │   └── service-data/              ← Static content for service landing pages (CMS-replaceable)
    │       ├── strata-landscaping-maintenance.php
    │       ├── commercial-landscape-maintenance.php
    │       └── hedge-trimming.php
    │
    ├── loginAuth/                     ← Central authentication system
    │   ├── auth.php                   ← Core: loads session + config, provides all auth functions
    │   ├── login.php                  ← Login form page
    │   ├── logout.php                 ← Logout handler
    │   ├── reset_password.php         ← Password reset
    │   └── forms/                     ← Legacy form components
    │       ├── login.php
    │       └── forgot_password.php
    │
    ├── assets/                        ← Public site static assets
    │   ├── css/
    │   │   ├── master.css             ← Single entry point (@import hub — DO NOT add styles here)
    │   │   ├── cms.css                ← CMS placeholder (empty)
    │   │   ├── base/
    │   │   │   ├── variables.css      ← Design tokens (--mowology-*, --color-*, --text-*, --bg-*)
    │   │   │   ├── reset.css          ← CSS reset
    │   │   │   ├── typography.css     ← Font stacks (Montserrat, Open Sans)
    │   │   │   └── utilities.css      ← Helper classes
    │   │   ├── layout/
    │   │   │   ├── layout.css         ← .container (max-width: 1200px)
    │   │   │   ├── header.css         ← .navbar, .nav-links, .mobile-menu-toggle
    │   │   │   ├── sections.css       ← .section-title, .section-subtitle
    │   │   │   ├── page-hero.css      ← .page-hero (inner page banners)
    │   │   │   └── footer.css         ← .footer, .footer-grid
    │   │   ├── components/
    │   │   │   ├── buttons.css        ← .btn, .btn-primary, .btn-secondary
    │   │   │   ├── forms.css          ← .form-group, .form-control
    │   │   │   ├── cards.css          ← .card, .card-header
    │   │   │   ├── alerts.css         ← .alert, .alert-danger
    │   │   │   └── cta.css            ← .cta-section
    │   │   └── pages/
    │   │       ├── home.css
    │   │       ├── services.css
    │   │       ├── contact.css
    │   │       ├── portfolio.css
    │   │       ├── about.css
    │   │       ├── lead-capture.css
    │   │       ├── jobflow-quote.css
    │   │       ├── jobflow-confirm.css
    │   │       ├── jobflow-success.css
    │   │       └── service-landing.css   ← slh-* (hero) + slp-* (proof sections) for /services/* pages
    │   ├── js/
    │   │   ├── app.js
    │   │   └── settings.js
    │   ├── favicon/
    │   │   ├── favicon.ico
    │   │   ├── favicon-16x16.png
    │   │   ├── favicon-32x32.png
    │   │   ├── apple-touch-icon.png
    │   │   ├── android-chrome-192x192.png
    │   │   ├── android-chrome-512x512.png
    │   │   └── site.webmanifest
    │   └── img/
    │       ├── hero/                  ← Responsive hero images (3 sizes)
    │       ├── icons/
    │       ├── optimized/
    │       ├── portfolio/
    │       ├── services/
    │       ├── team/
    │       └── thumbnails/
    │
    ├── jobFlow/                       ← Public quote request workflow (3 steps)
    │   ├── jobFlow-getQuote.php       ← Step 1: Form (name, email, phone, address, services)
    │   ├── jobFlow-confirm.php        ← Step 2: Review and confirm
    │   └── jobFlow-success.php        ← Step 3: Thank you page
    │
    ├── cms/                           ← CMS directory (placeholder — not built yet)
    │   └── AI_CSS_RULES_CMS.md        ← CSS rules for public site styling
    │
    ├── crm/                           ← CRM Backend
    │   ├── login_secure.php           ← Login page (standalone, Mowology branded)
    │   ├── logout_secure.php          ← Logout handler
    │   │
    │   │── ─── AppStack Pages (ACTIVE SYSTEM) ───
    │   ├── dashboard_appstack.php     ← Dashboard (stats, activity, quick actions)
    │   ├── clients_appstack.php       ← Clients (Phase 2 placeholder)
    │   ├── quotes_appstack.php        ← Quotes (Phase 5 placeholder)
    │   ├── map_appstack.php           ← Territory Map (Phase 4 placeholder)
    │   │
    │   ├── includes/                  ← Shared layout components
    │   │   ├── appstack_head.php      ← <head>: meta, favicon, fonts, classic.css, mowology-brand.css
    │   │   ├── appstack_sidebar.php   ← Sidebar nav ($activePage, $user, $navItems array)
    │   │   ├── appstack_topbar.php    ← Top navbar (user dropdown, logout)
    │   │   ├── appstack_footer.php    ← Footer, closing tags, app.js
    │   │   ├── bootstrap.php          ← Alt bootstrap (loads session + config + auth, sets $user, $db)
    │   │   ├── functions.php          ← Helpers: generateQuoteNumber(), formatCurrency(), etc.
    │   │   ├── messaging.php          ← Unified email + SMS delivery (PHPMailer SMTP email, native mail() SMS)
    │   │   ├── email_logger.php       ← Writes email send attempts to /crm/email-logs/ for debugging
    │   │   ├── smtp_mailer.php        ← DEPRECATED — superseded by messaging.php
    │   │   ├── sms_gateway.php        ← DEPRECATED — superseded by messaging.php
    │   │   ├── email_helper.php       ← DEPRECATED — superseded by messaging.php
    │   │   ├── notifications.php      ← Alert display
    │   │   └── sidebar.php            ← CDN sidebar (still used by quotes/, jobs/, invoices/ modules)
    │   │
    │   ├── css/
    │   │   ├── classic.css            ← AppStack vendor (440 KB) — DO NOT MODIFY
    │   │   ├── corporate.css          ← AppStack theme variant — DO NOT MODIFY
    │   │   ├── mowology-brand.css     ← BRAND OVERRIDE (--mw-* tokens, all customisation here)
    │   │   ├── classic.scss           ← Source SCSS (reference only)
    │   │   ├── corporate.scss         ← Source SCSS (reference only)
    │   │   └── dashboard.scss         ← Source SCSS (reference only)
    │   │
    │   ├── js/
    │   │   ├── app.js                 ← AppStack vendor JS — DO NOT MODIFY
    │   │   └── settings.js            ← AppStack settings JS
    │   │
    │   │── ─── Feature Modules ───
    │   ├── quotes/
    │   │   ├── index.php              ← Quote list (filters, search, status badges)
    │   │   ├── create.php             ← Create quote (line items, tax calc)
    │   │   └── view.php               ← View single quote
    │   ├── jobs/
    │   │   ├── index.php              ← Job list
    │   │   ├── create.php             ← Create job (from quote or standalone)
    │   │   ├── view.php               ← View job details
    │   │   └── schedule.php           ← Calendar view
    │   ├── invoices/
    │   │   ├── index.php              ← Invoice list
    │   │   └── create.php             ← Create invoice
    │   ├── products/
    │   │   ├── index.php              ← Products list
    │   │   ├── products-manager.php   ← Admin product CRUD
    │   │   ├── quote-requests.php     ← Customer quote requests
    │   │   ├── process-quote-request.php ← AJAX handler
    │   │   ├── cost-factors.php       ← Pricing factors
    │   │   ├── area-measurement.php   ← Area calculator
    │   │   └── config.php             ← Local config (utility functions, reCAPTCHA, email)
    │
    ├── customer/                      ← Public customer-facing pages (no login required)
    │   └── quote.php                  ← Customer quote view with digital signature
    │
    ├── sessions/                      ← PHP session storage (runtime)
    ├── uploads/                       ← File upload storage (runtime)
    │
    └── crinum/                        ← AppStack Vendor Template (ENTIRELY VENDOR — DO NOT MODIFY)
        ├── index.php                  ← Landing page
        ├── login.php                  ← Vendor login form
        ├── dashboard-default.php
        ├── css/classic.css, modern.css, corporate.css
        ├── js/app.js, settings.js
        ├── functions/db.php, functions.php
        ├── includes/navSide.php, navTop.php, header.php, footer.php
        ├── schedule/                  ← FullCalendar integration
        │   ├── index.php
        │   ├── addEvent.php, editEvent*.php
        │   ├── css/ (Bootstrap, FullCalendar)
        │   ├── js/ (jQuery, Moment, FullCalendar)
        │   └── php/utils.php, get-events.php
        └── img/avatars, brands, flags, photos, screenshots
```

---

## Authentication Flow

```
Browser → /loginAuth/login.php
            │
            ├── require_once 'auth.php'
            │     ├── require_once /app_config/session_config.php  (session_start, cookie hardening)
            │     └── require_once /app_config/config.php          (Database class, helpers)
            │           └── require_once /app_config/secrets.php   (DB_HOST, DB_PASS, API keys)
            │
            ├── POST: loginUser($email, $password)
            │     ├── SELECT from users WHERE email = ? AND is_active = 1
            │     ├── password_verify() against bcrypt hash
            │     ├── session_regenerate_id(true)
            │     ├── Set $_SESSION: user_id, user_email, user_name, user_role,
            │     │                  login_time, last_activity, csrf_token
            │     └── Update users.last_login
            │
            └── Redirect → /crm/dashboard.php (or dashboard_appstack.php)

Protected CRM page:
    require_once __DIR__ . '/../loginAuth/auth.php'  ← loads session, DB, auth functions
    requireLogin()                     ← redirects to login if no session
    $user = getCurrentUser()           ← returns ['id','email','name','role']
```

### Session Variables

| Key | Type | Set By |
|-----|------|--------|
| `user_id` | int | `loginUser()` |
| `user_email` | string | `loginUser()` |
| `user_name` | string | `loginUser()` |
| `user_role` | string | `loginUser()` — values: `admin`, `staff` |
| `login_time` | int | `loginUser()` — unix timestamp |
| `last_activity` | int | `checkSessionTimeout()` — updated each request |
| `csrf_token` | string | `generateCSRFToken()` — 64 hex chars |

### Session Config

- Cookie name: `MOWOSESS`
- Lifetime: browser session (0)
- HttpOnly: true
- SameSite: Lax
- Secure: true when HTTPS detected
- Save path: `/home/mowology/tmp` (cPanel)
- Idle timeout: 1800 seconds (30 min)

---

## Database

### Connection

```php
$db = getDB();  // returns PDO singleton
// OR
$db = Database::pdo();
```

- Charset: `utf8mb4`
- Error mode: `PDO::ERRMODE_EXCEPTION`
- Fetch: `PDO::FETCH_ASSOC`
- Emulated prepares: OFF

### Key Tables

| Table | Purpose |
|-------|---------|
| `users` | Admin/staff accounts (id, email, password_hash, full_name, role, is_active, last_login) |
| `clients` | Client records (status: new_inquiry / quoted / won / active / archived / lost) |
| `companies` | Business entities linked to clients |
| `properties` | Physical addresses with coordinates (lat/lng), sq footage |
| `property_measurements` | Lawn/driveway measurements |
| `quotes` | Quote records (status: draft / sent / accepted / declined / expired) |
| `quote_items` | Line items on quotes |
| `jobs` | Job records (status: scheduled / in_progress / completed / cancelled / on_hold) |
| `invoices` | Invoice records (status: sent / viewed / paid / partial / overdue) |
| `invoice_items` | Line items on invoices |
| `products` | Service/product catalog |
| `cost_factors` | Pricing multipliers |
| `activity_log` | Audit trail (user_id, client_id, action, details, created_at) |

### Document Number Formats

| Type | Pattern | Generator |
|------|---------|-----------|
| Quote | `QUO-YYYY-NNNN` | `generateQuoteNumber()` |
| Job | `JOB-YYYY-NNNN` | `generateJobNumber()` |
| Invoice | `INV-YYYY-NNNN` | `generateInvoiceNumber()` |

---

## CSS Load Order

### Public Site

```
<link href="/assets/css/master.css">
  └── @import base/reset.css
  └── @import base/variables.css       ← --mowology-*, --color-*, --text-*, --bg-*
  └── @import base/typography.css
  └── @import base/utilities.css
  └── @import layout/*.css
  └── @import components/*.css
  └── @import pages/*.css
  └── @import cms.css
```

### CRM (AppStack pages)

```
<link href="/crm/css/classic.css">     ← AppStack vendor (Bootstrap 4 + theme) — NEVER EDIT
<link href="/crm/css/mowology-brand.css"> ← Brand overrides — ALL customisation here
```

Both loaded via `/crm/includes/appstack_head.php`.

### Login Page

Self-contained inline CSS with `--mw-*` tokens matching the brand file. Uses Montserrat font (same as public site).

---

## Brand Tokens — Single Source of Truth

**The canonical color values are:**

| Color | Hex | Public Site Token | CRM Token |
|-------|-----|-------------------|-----------|
| Primary green | `#2D8659` | `--mowology-green` | `--mw-green` |
| Dark green | `#1A5F4A` | `--mowology-dark` | `--mw-dark` |
| Lime accent | `#7FD858` | `--mowology-lime` | `--mw-lime` |
| Light tint | `#E8F3F0` | — | `--mw-light` |
| Forest (deepest) | `#0D3B2E` | — | `--mw-forest` |
| Orange CTA | `#e85d04` | `--color-accent` | `--mw-orange` |

**If the brand colors change, update both:**
1. `/assets/css/base/variables.css` (public site)
2. `/crm/css/mowology-brand.css` (CRM)

---

## Public Site Layout Contract (LOCKED)

Every public page MUST follow this exact include order:

```php
<?php
require __DIR__ . '/includes/bootstrap.php';

$pageTitle       = 'Page Name – ' . SITE_NAME;
$pageDescription = 'Meta description for this page.';
$activeNav       = 'home';          // home|services|portfolio|about|contact
// Optional overrides:
// $pageKeywords = 'lawn care, vancouver';
// $pageImage    = '/assets/img/custom-og.jpg';
// $extraHead    = '<link rel="stylesheet" href="/assets/css/extra.css">';

require __DIR__ . '/includes/head.php';     // outputs: <!DOCTYPE> through </head>
require __DIR__ . '/includes/header.php';   // outputs: <body>, <header>, <nav>
?>

<!-- page content here -->

<?php require __DIR__ . '/includes/footer.php'; // outputs: <footer>, </body>, </html> ?>
```

### Variable Reference

| Variable | Default | Used in |
|---|---|---|
| `$pageTitle` | `SITE_NAME` | `<title>`, og:title, twitter:title |
| `$pageDescription` | `''` (omitted if empty) | meta description, og:description, twitter:description |
| `$pageKeywords` | `''` (omitted if empty) | meta keywords |
| `$pageImage` | `/assets/img/hero/hero-lawn-care-1920x1080.jpg` | og:image, twitter:image |
| `$activeNav` | `''` | header.php nav highlighting |
| `$extraHead` | `''` | injected before `</head>` (page-specific CSS/JS) |

### What head.php outputs automatically

- Charset, viewport, title
- Canonical URL (built from `SITE_URL` + `$_SERVER['REQUEST_URI']`)
- OpenGraph tags (og:type, og:site_name, og:title, og:url, og:locale, og:description, og:image)
- Twitter Card (summary_large_image)
- Favicon links (apple-touch-icon, 32x32, 16x16, webmanifest)
- Google Fonts (Montserrat + Open Sans)
- `/assets/css/master.css`
- Analytics placeholder (Google Analytics / Tag Manager)

---

## Service Landing Page System

Service landing pages use a data-driven template architecture designed for CMS migration:

```
/services/<slug>.php              ← Thin page file (3 lines: bootstrap, load data, load template)
/includes/service-data/<slug>.php ← Static content array (CMS-replaceable)
/includes/service-template.php    ← Rendering engine (shared by all service pages)
/assets/css/pages/service-landing.css ← All styles (slh-*, slp-* prefixes)
```

**URL routing:** `.htaccess` rewrites `/services/<slug>` → `/services/<slug>.php`

**Current pages:**
| URL | Source | CTA tracks as |
|-----|--------|---------------|
| `/services/strata-landscaping-maintenance` | `strata-landing` | `quote_requests.source = 'strata-landing'` |
| `/services/commercial-landscape-maintenance` | `commercial-landing` | `quote_requests.source = 'commercial-landing'` |
| `/services/hedge-trimming` | `hedge-landing` | `quote_requests.source = 'hedge-landing'` |

**Adding a new service page:** See CLAUDE.md section 9.

---

## Messaging Module (`/crm/includes/messaging.php`)

Single unified module for all CRM email and SMS delivery. Created Feb 2026 by consolidating `smtp_mailer.php`, `sms_gateway.php`, and `email_helper.php`.

### CRITICAL: Email vs SMS Delivery Methods

```
┌─────────────────────────────────────────────────────────────────┐
│  EMAIL  →  PHPMailer SMTP (primary) + native mail() fallback   │
│  SMS    →  Native mail() ONLY — NEVER use PHPMailer for SMS    │
└─────────────────────────────────────────────────────────────────┘
```

**Why SMS must use native `mail()`:** PHPMailer adds MIME headers (Content-Type, MIME-Version, Message-ID, multipart boundaries, etc.) that Canadian carrier email-to-SMS gateways reject or silently discard. Native `mail()` with minimal headers (just `From` and `X-Mailer`) is the only method proven to deliver SMS via these gateways. This was verified through production testing on Feb 11, 2026 — diagnostics using `mail()` delivered SMS successfully, while the same message routed through PHPMailer was silently dropped by every carrier.

**Why SMS sends to ALL 10 carriers:** We cannot detect which carrier a phone number belongs to. The server's `mail()` always returns `true` (message accepted for relay) regardless of whether the carrier actually delivers it. Stopping at the first "successful" carrier would mean only that carrier's customers receive the SMS. Non-matching carriers silently discard the message — there is no bounce or error.

### Public API

```php
require_once __DIR__ . '/messaging.php';

// Email — uses PHPMailer SMTP, falls back to mail()
$result = sendEmail($to, $subject, $htmlBody, $attachmentPath, $fromName);
// Returns: ['success' => bool, 'method' => string, 'error' => string|null]

// SMS — uses native mail() to all 10 Canadian carrier gateways
$result = sendSms($phone, $message, $senderName);
// Returns: ['success' => bool, 'carrier' => string|null, 'attempts' => int, 'errors' => string[]]
```

### SMS Implementation Details

- From address: `no-reply@mowology.ca`
- Headers: Only `From` and `X-Mailer` — nothing else
- Subject: Empty string (carriers ignore it)
- Body: Plain text only, max 160 chars (auto-truncated)
- No HTML, no links/URLs, no MIME encoding
- Sends to ALL carriers in `CANADIAN_SMS_GATEWAYS` constant (Bell, Rogers, Telus, Koodo, Virgin, Fido, Freedom, PC Mobile, Eastlink, SaskTel)

### Email Implementation Details

- PHPMailer SMTP: `mail.mowology.ca:465` (SSL), authenticated via `SMTP_USER`/`SMTP_PASS` from `secrets.php`
- From: `no-reply@mowology.ca`, Reply-To: `office@mowology.ca`
- Supports HTML body + optional PDF attachment
- Falls back to native `mail()` if PHPMailer unavailable or throws exception
- Logs all attempts via `email_logger.php` to `/crm/email-logs/`

### Backward Compatibility Aliases

These exist so diagnostics and older code paths don't break:

| Alias | Maps To | Returns |
|-------|---------|---------|
| `sendCrmEmail(...)` | `sendEmail()` | `bool` |
| `sendEmailViaSMTP(...)` | `sendEmail()` | `bool` |
| `sendSmsViaMail(...)` | `sendSms()` | `array` (original shape) |

All wrapped in `function_exists()` guards.

### Helper Functions

| Function | Purpose |
|----------|---------|
| `hasSmConsent(int $contactId): bool` | Check if contact opted into SMS |
| `companyPrefersAttachment(int $companyId): bool` | Check PDF attachment preference |
| `sendQuoteNotificationSms(...)` | Convenience wrapper for quote SMS |
| `testEmailConfig(string $email): array` | Send test email |
| `testSmsGateway(string $phone, string $carrier): array` | Send test SMS |

### Files Superseded (kept for reference, no longer included)

| File | Replaced By |
|------|-------------|
| `/crm/includes/smtp_mailer.php` | `messaging.php` — PHPMailer SMTP logic |
| `/crm/includes/sms_gateway.php` | `messaging.php` — carrier gateway logic |
| `/crm/includes/email_helper.php` | `messaging.php` — native mail() email + `companyPrefersAttachment()` |

### Callers

| Page | Uses |
|------|------|
| `/crm/quotes/view.php` | `sendEmail()` for quote email, `sendSms()` for quote SMS notification |
| `/crm/invoices/view.php` | `sendCrmEmail()` alias for invoice email |
| `/crm/diagnostics/` | Loads own copies of functions with `function_exists()` guards |

---

## Feature Module Pages — Current Include Pattern

The pages in `/crm/quotes/`, `/crm/jobs/`, `/crm/invoices/` still use `sidebar.php` from the CDN layout system. `/crm/products/` uses its own inline sidebar. All authentication now goes through `/loginAuth/auth.php`. When these pages are rewritten, they should be migrated to the AppStack template pattern documented in CLAUDE.md section 3.

---

## jobFlow Data Flow

```
Service landing page CTA → /quote?service=X&property_type=Y&src=Z
    │
    └── quote.php redirects (302) → /jobFlow/jobFlow-getQuote.php?service=X&...

Customer fills form on /jobFlow/jobFlow-getQuote.php
    │
    ├── On GET: capture tracking params to $_SESSION['jf_track']
    │   (service, property_type, src, promo, utm_source, utm_medium, utm_campaign, utm_content, utm_term, referrer)
    ├── Pre-select form fields from tracking params (property_type dropdown, service checkbox)
    ├── Server-side validation + reCAPTCHA v2
    ├── Data cleaning: cleanPhone(), cleanPostalCode(), cleanAddress(), cleanName()
    ├── Store in $_SESSION['temp_quote_data'] (includes 'tracking' array)
    │
    └── Redirect → /jobFlow/jobFlow-confirm.php
                     │
                     ├── Display summary for review
                     ├── $quoteSource built from tracking['src'] (default: 'website')
                     ├── INSERT quote_requests.source = $quoteSource
                     ├── Activity log includes source
                     ├── Cleanup: unset $_SESSION['jf_track']
                     │
                     └── Redirect → /jobFlow/jobFlow-success.php
                                     │
                                     └── Display confirmation + quote number
```

---

## Helper Functions Available

### From `/crm/includes/functions.php`

| Function | Returns | Purpose |
|----------|---------|---------|
| `generateQuoteNumber()` | `'QUO-2026-0001'` | Next sequential quote number |
| `generateJobNumber()` | `'JOB-2026-0001'` | Next sequential job number |
| `generateInvoiceNumber()` | `'INV-2026-0001'` | Next sequential invoice number |
| `calculateQuoteTotals($items, $taxRate)` | `['subtotal', 'tax_amount', 'total']` | Line item math |
| `formatCurrency($amount)` | `'$1,234.56'` | Currency formatting |
| `formatDate($date)` | `'Jan 15, 2026'` | Date display |
| `formatDateTime($datetime)` | `'Jan 15, 2026 3:30 PM'` | Datetime display |
| `getStatusBadge($status, $type)` | HTML `<span>` | Colored badge for status |
| `createJobFromQuote($quoteId, $userId)` | `['success'=>bool, ...]` | Convert quote to job |
| `updateJobStatus($jobId, $status, $userId, $notes)` | `bool` | Transition job status |
| `getDashboardStats()` | `['quotes'=>[...], ...]` | Dashboard summary data |
| `getPropertyDetails($propertyId)` | `array` | Full property record |
| `getStaffMembers()` | `array` | All staff users |
| `getServiceTemplates()` | `array` | Predefined service templates |

### From `/app_config/config.php`

| Function | Returns | Purpose |
|----------|---------|---------|
| `getDB()` | `PDO` | Database connection singleton |
| `Database::pdo()` | `PDO` | Same as above (static) |
| `h($string)` | `string` | htmlspecialchars shorthand |
| `secure_random_token($bytes)` | `string` | Hex-encoded random bytes |
| `csrf_token()` | `string` | Get/create CSRF token |
| `csrf_verify($token)` | `bool` | Validate CSRF token |

### From `/loginAuth/auth.php`

| Function | Returns | Purpose |
|----------|---------|---------|
| `isLoggedIn()` | `bool` | Check session |
| `requireLogin()` | `void` or exit | Redirect if not authenticated |
| `getCurrentUser()` | `array` or `null` | `['id','email','name','role']` |
| `isAdmin()` | `bool` | Check admin role |
| `loginUser($email, $password)` | `bool` | Authenticate + create session |
| `logoutUser()` | `void` | Destroy session |
| `generateCSRFToken()` | `string` | Create CSRF token |
| `verifyCSRFToken($token)` | `bool` | Validate CSRF token |
| `logActivity($userId, $clientId, $action, $details)` | `void` | Write to activity_log |
| `checkSessionTimeout($seconds)` | `void` or exit | Enforce idle timeout |
