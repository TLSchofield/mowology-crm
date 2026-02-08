# Claude Code — Mowology Project Instructions

This file governs ALL AI-assisted edits to the Mowology CRM, CMS, and public website.
Read the companion `ARCHITECTURE.md` for the full system map. This file is the rules.

---

## Identity

- **Project:** Mowology — landscaping CRM + public website
- **Stack:** Vanilla PHP 7.4+, MySQL 5.7+ (PDO), plain CSS, vanilla JS, Bootstrap 4 (via AppStack vendor)
- **Hosting:** cPanel shared hosting (auto-deploys from GitHub)
- **Email:** Native PHP mail() with RFC-compliant MIME formatting (office@mowology.ca)
- **Build tools:** None. No npm, Webpack, Sass compilation, or Composer.

---

## 1. Cardinal Rules

1. **NEVER modify AppStack vendor files.** The files in `/crm/css/classic.css`, `/crm/css/corporate.css`, `/crm/js/app.js`, and the entire `/crinum/` directory are vendor code. Brand customisation goes in `/crm/css/mowology-brand.css` only.
2. **NEVER modify `/public/app_config/secrets.php`.** It contains credentials and is not in git.
3. **NEVER put credentials, API keys, or secrets in any file other than `secrets.php`.**
4. **NEVER add inline `<style>` blocks to AppStack-based PHP pages.** All CRM styling goes through `mowology-brand.css`.
5. **NEVER hardcode color hex values.** Use CSS custom properties (`--mw-*` in CRM, `--mowology-*` on public site).
6. **NEVER add npm, Webpack, Sass, or Composer.** This is a zero-dependency project using only PHP and native browser APIs.
7. **NEVER create new CSS files without adding them to the correct import chain** (`master.css` for public site, `appstack_head.php` for CRM).

---

## 2. Project Boundaries — Know What You're Editing

There are **four separate systems** in this repo. Know which one you're touching:

| System | Directory | Auth | CSS | Layout Includes |
|--------|-----------|------|-----|-----------------|
| **Public site** | `/public/` root (index.php, services.php, etc.) | None (public) | `/assets/css/master.css` imports | `/includes/head.php`, `header.php`, `footer.php` |
| **CRM (AppStack)** | `/public/crm/*_appstack.php`, `/crm/quotes/`, `/crm/jobs/`, `/crm/invoices/`, `/crm/products/` | `/loginAuth/auth.php` | `/crm/css/classic.css` + `/crm/css/mowology-brand.css` | `/crm/includes/appstack_*.php` |
| **jobFlow** | `/public/jobFlow/` | `/app_config/session_config.php` + `/app_config/config.php` | `/assets/css/pages/jobflow-*.css` | Self-contained pages |
| **Customer portal** | `/public/customer/` | Token-based (no login) | Self-contained | Self-contained |

**Do NOT cross system boundaries** unless explicitly asked (e.g., don't import CRM CSS into the public site).

### Public Root — Clean Boundary

The `/public/` root should ONLY contain:
- Public website pages: `index.php`, `services.php`, `about.php`, `contact.php`, `portfolio.php`
- Infrastructure: `.htaccess`, `robots.txt`, `php.ini`, `.gitignore`, `.user.ini`, `script.js`
- Subdirectories: `/app_config/`, `/assets/`, `/cms/`, `/crm/`, `/crinum/`, `/customer/`, `/includes/`, `/jobFlow/`, `/loginAuth/`, `/sessions/`, `/uploads/`

Do NOT add CRM pages, config files, or admin code to the public root.

### Legacy / Deprecated Code

The `/crinum/` directory is the vendor AppStack template. Do not modify it. **All CRM pages now use the AppStack include system.** The feature modules (`/crm/quotes/`, `/crm/jobs/`, `/crm/invoices/`, `/crm/products/`) were migrated from inline/CDN layouts to AppStack in Feb 2026. All inline CSS was extracted into `mowology-brand.css`. There are no remaining legacy CRM page layouts.

**Note:** Files in `/crm/products/` that are NOT AppStack pages (they serve different purposes):
- `config.php` — PHP config constants (Google Maps API key)
- `process-quote-request.php` — Backend AJAX handler (returns JSON/HTML, not a CRM page)
- `get-quote.html`, `quote-success.html` — Static HTML pages for the public quote form flow

---

## 3. CRM — AppStack Page Template

Every new CRM page MUST follow this structure (mirrors the public site's include contract):

**Root-level pages** (`/crm/*_appstack.php`):
```php
<?php
require_once __DIR__ . '/../loginAuth/auth.php';
requireLogin();
$user = getCurrentUser();

$pageTitle = 'Page Name';     // shown in <title>
$activePage = 'nav-key';      // highlights sidebar item
// $extraHead = '';            // optional: extra markup before </head> (e.g., Google Maps script)
?>
<?php include 'includes/appstack_head.php'; ?>

          <!-- page content here -->

<?php include 'includes/appstack_footer.php'; ?>
```

**Subdirectory pages** (`/crm/quotes/`, `/crm/jobs/`, `/crm/invoices/`, `/crm/products/`):
```php
<?php
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
requireLogin();
$user = getCurrentUser();

$pageTitle = 'Page Name';
$activePage = 'nav-key';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

          <!-- page content here -->

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
```

**Key difference:** Subdirectory files use `dirname(__DIR__)` to reach the `/crm/includes/` directory reliably regardless of the working directory.

**Do NOT add** `<!DOCTYPE>`, `<html>`, `<head>`, `<body>`, `<div class="wrapper">`, sidebar, topbar, `<main>`, or `<div class="container-fluid">` to individual pages — these are all output by `appstack_head.php`. Similarly, do NOT close these tags — `appstack_footer.php` handles all closing tags.

### Valid `$activePage` Keys

`dashboard` | `clients` | `quotes` | `jobs` | `invoices` | `schedule` | `map` | `products` | `settings`

To add a new nav item, edit `/crm/includes/appstack_sidebar.php` and add to the `$navItems` array. Use a Feather icon name for the `icon` key.

### CRM Include Chain

```
appstack_head.php   →  outputs: <!DOCTYPE>, <html>, <head>…</head>, <body>,
                       <div class="wrapper">, sidebar, <div class="main">,
                       topbar, <main class="content">, <div class="container-fluid p-0">
appstack_footer.php →  outputs: </div> (container-fluid), </main>,
                       <footer>, </div> (.main), </div> (.wrapper),
                       <script> app.js, </body>, </html>
```

### AppStack Include Files — What They Expect

| Include | Required Variables | Provides |
|---------|-------------------|----------|
| `appstack_head.php` | `$pageTitle` (optional), `$activePage` (optional), `$user` (optional), `$extraHead` (optional) | Everything from `<!DOCTYPE>` through `<div class="container-fluid p-0">` — includes sidebar and topbar |
| `appstack_footer.php` | None | Closes container-fluid, main, content; outputs footer, loads `app.js`, closes `</body></html>` |

**Note:** `appstack_sidebar.php` and `appstack_topbar.php` are included automatically by `appstack_head.php`. Do NOT include them separately in page files.

---

## 4. CSS Rules

### CRM Branding (`/crm/css/mowology-brand.css`)

This is the ONLY file for CRM style customisation. It loads after `classic.css` and overrides via specificity.

**Brand tokens (defined in `:root`):**

| Token | Value | Usage |
|-------|-------|-------|
| `--mw-green` | `#2D8659` | Primary buttons, links, badges |
| `--mw-dark` | `#1A5F4A` | Hover states, gradients |
| `--mw-lime` | `#7FD858` | Active nav items, success accents |
| `--mw-light` | `#E8F3F0` | Light backgrounds, borders |
| `--mw-forest` | `#0D3B2E` | Sidebar background, deepest dark |
| `--mw-orange` | `#e85d04` | CTA accent (secondary) |

**CSS sections in `mowology-brand.css`** (organized with comment headers):
- AppStack overrides (sidebar, topbar, cards, buttons)
- Dashboard (stat cards, quote request cards, urgency badges)
- Quote Workflow page
- List Views (stats, filters, tables, badges, empty states)
- Create/Edit Forms (form rows, groups, errors, recurring options, totals)
- Detail Views (content grid, detail rows, page header, messages)
- Job View (notes, photos, modals)
- Quote View (line items table, signature, activity)
- Schedule/Calendar (grid, job cards, legend, navigation)
- Products Module (tools grid, tool cards, badge tags)
- Products Manager (product cards, GGOB indicators, cost breakdowns)
- Cost Factors (calc boxes, profit cards, badge variants)
- Area Measurement (map container, measurement tools, area items)

When adding new CRM styles:
- Add them to `mowology-brand.css` under the appropriate section
- Use `--mw-*` variables for all colors
- Override AppStack classes by matching or exceeding their specificity — avoid `!important` unless overriding an existing `!important`
- Custom classes MUST use the `.mw-` prefix (e.g., `.mw-stat-card`, `.mw-filter-tab`, `.mw-form-row`)

### Public Site CSS (`/assets/css/`)

See `/public/cms/AI_CSS_RULES_CMS.md` for the full public site CSS ruleset. Key points:
- All styles in `/assets/css/` via modular imports
- Design tokens in `/assets/css/base/variables.css` using `--mowology-*` naming
- Never add `<style>` blocks to PHP files
- New page styles go in `pages/<name>.css` and get added to `master.css`

### Token Naming Convention

| Context | Prefix | Example |
|---------|--------|---------|
| Public site | `--mowology-*` | `--mowology-green: #2D8659` |
| CRM (AppStack) | `--mw-*` | `--mw-green: #2D8659` |
| Semantic (public) | `--color-*`, `--text-*`, `--bg-*` | `--color-primary: var(--mowology-green)` |

The hex values are identical. The prefixes differ because the CRM operates in a different CSS context (AppStack vendor overlay).

---

## 5. PHP Conventions

### Authentication

- Always use `requireLogin()` at the top of protected pages
- Always use `$user = getCurrentUser()` to get the logged-in user
- Always use `getDB()` or `Database::pdo()` to get a PDO connection
- Always use prepared statements for user-supplied data: `$db->prepare("... WHERE id = ?")->execute([$id])`
- Always use `htmlspecialchars()` or `h()` when outputting user data to HTML
- Always use `generateCSRFToken()` in forms and `verifyCSRFToken()` on submission

### Public Site Layout — Locked Contract

Every public page uses exactly these 4 includes in this order. Do NOT duplicate their output.

```
bootstrap.php  →  session, constants (SITE_NAME, SITE_URL, SITE_PHONE_*, SITE_EMAIL, SITE_YEAR, SITE_LOCALE)
head.php       →  outputs: <!DOCTYPE>, <html>, <head>...</head>  (favicon, OG, Twitter Card, fonts, master.css)
header.php     →  outputs: <body>, <header>, <nav>
footer.php     →  outputs: <footer>, script.js, </body>, </html>
```

**Variables to set BEFORE including head.php:**

| Variable | Required | Default | Purpose |
|----------|----------|---------|---------|
| `$pageTitle` | Yes | `SITE_NAME` | `<title>` + og:title |
| `$pageDescription` | Yes | `''` | meta description + og:description |
| `$activeNav` | Yes | `''` | Highlights nav item (home/services/portfolio/about/contact) |
| `$pageKeywords` | No | `''` | meta keywords (optional) |
| `$pageImage` | No | hero image | og:image path (web-root relative) |
| `$extraHead` | No | `''` | Extra markup before `</head>` (e.g., reCAPTCHA script) |

**head.php automatically provides:** favicon, canonical URL, OpenGraph tags, Twitter Card, Google Fonts, master.css, analytics placeholder.

**NEVER add these to individual pages** — they are already in the includes:
- `<!DOCTYPE html>` or `<html>` tags
- `<meta charset>` or `<meta viewport>`
- Favicon links
- Font `<link>` tags
- `<body>` or `</body>` tags
- `</html>`

### CRM Include Chain

```
require_once __DIR__ . '/../loginAuth/auth.php'  →  loads session_config.php, config.php, provides requireLogin(), getCurrentUser(), etc.
```

### jobFlow Include Chain

```
require_once '/app_config/session_config.php'
require_once '/app_config/config.php'
```

jobFlow pages are self-contained (own `<head>`, no shared header/footer) by design.

### Naming Conventions

| What | Convention | Example |
|------|-----------|---------|
| PHP functions | camelCase | `getCurrentUser()`, `generateQuoteNumber()` |
| PHP classes | PascalCase | `Database` |
| Database tables | plural snake_case | `quotes`, `activity_log` |
| Database columns | snake_case | `created_at`, `quote_number`, `is_active` |
| File names | kebab-case or snake_case | `login_secure.php`, `jobFlow-getQuote.php` |
| CSS classes | kebab-case | `.stat-card`, `.btn-mowology`, `.sidebar-brand` |
| CSS variables | `--prefix-name` | `--mw-green`, `--mowology-dark` |
| Document numbers | `PREFIX-YYYY-NNNN` | `QUO-2026-0001`, `JOB-2026-0001`, `INV-2026-0001` |

### Database

- Engine: MySQL 5.7+ with `utf8mb4` charset
- Access: PDO singleton via `getDB()` or `Database::pdo()`
- Error mode: `PDO::ERRMODE_EXCEPTION`
- Fetch mode: `PDO::FETCH_ASSOC`
- Emulated prepares: OFF

---

## 6. File Locations Quick Reference

### Files You WILL Edit

| File | What it controls |
|------|-----------------|
| `/crm/css/mowology-brand.css` | All CRM branding/styling on top of AppStack |
| `/crm/includes/appstack_sidebar.php` | CRM sidebar navigation items |
| `/crm/includes/appstack_head.php` | CRM `<head>` content (fonts, meta, CSS links) |
| `/crm/includes/appstack_topbar.php` | CRM top navigation bar |
| `/crm/includes/appstack_footer.php` | CRM footer + closing scripts |
| `/crm/includes/functions.php` | Shared CRM helper functions |
| `/crm/*_appstack.php` | Individual CRM page content |
| `/crm/quotes/*.php` | Quote CRUD pages |
| `/crm/jobs/*.php` | Job CRUD pages |
| `/crm/invoices/*.php` | Invoice CRUD pages |
| `/crm/products/*.php` | Product management pages |
| `/assets/css/base/variables.css` | Public site design tokens |
| `/assets/css/**/*.css` | Public site modular styles |

### Files You MUST NOT Edit

| File | Why |
|------|-----|
| `/crm/css/classic.css` | AppStack vendor CSS |
| `/crm/css/corporate.css` | AppStack vendor CSS |
| `/crm/js/app.js` | AppStack vendor JS |
| `/crinum/**/*` | Entire AppStack vendor template |
| `/app_config/secrets.php` | Contains credentials |

### Files That Probably Don't Need Changes

| File | Notes |
|------|-------|
| `/loginAuth/auth.php` | Central auth module — stable |
| `/app_config/config.php` | Core config — stable |
| `/app_config/session_config.php` | Session setup — stable |

---

## 7. Adding New CRM Features — Checklist

When building a new CRM feature:

1. Create the PHP page using the AppStack template from section 3 — only use `appstack_head.php` and `appstack_footer.php` includes; do NOT add boilerplate HTML tags
2. Set `$pageTitle` and `$activePage` appropriately; use `$extraHead` for extra `<head>` content
3. If it needs a new sidebar entry, add to `$navItems` in `appstack_sidebar.php`
4. Put any new styles in `mowology-brand.css` using `--mw-*` tokens
5. Use prepared statements for all database queries
6. Escape all output with `htmlspecialchars()` or `h()`
7. Include CSRF tokens in all forms
8. Use Feather icons via `data-feather="icon-name"` attribute
9. Use Bootstrap 4 grid classes for layout (`.row`, `.col-md-6`, etc.)
10. Use AppStack card components for content sections (`.card`, `.card-header`, `.card-body`)

---

## 8. Adding New Public Site Pages — Checklist

Copy this exact skeleton:

```php
<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

$pageTitle = 'Page Name | Mowology Landscaping';
$pageDescription = 'One-sentence description for search engines.';
$activeNav = 'home';  // home|services|portfolio|about|contact

require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/header.php';
?>

  <!-- page content here -->

<?php require __DIR__ . '/includes/footer.php'; ?>
```

Then:
1. Create `/assets/css/pages/<name>.css` for page-specific styles
2. Add `@import "pages/<name>.css"` to `master.css`
3. Use `--mowology-*` and `--color-*` tokens for all colors
4. Use root-relative URLs for all links (`/services.php` not `services.php`)

---

## 9. Adding New Service Landing Pages — Checklist

Service landing pages use a **data-driven template system**. Each page is a thin PHP file + a data array. The template (`/includes/service-template.php`) renders the page.

### Step 1: Create the data file

Create `/includes/service-data/<slug>.php` returning an array with this shape:

```php
return [
    'slug'  => 'my-service',
    'title' => 'My Service',
    'meta_title'       => 'My Service Vancouver | Mowology',
    'meta_description' => 'Description for search engines.',
    'hero'  => [ 'headline' => '...', 'subheadline' => '...', 'cta_text' => '...', 'cta_url' => '/quote?service=xxx&src=my-landing' ],
    'proof_sections' => [ /* checklist, benefits, process, before_after */ ],
    'faq'   => [ ['q' => '...', 'a' => '...'] ],
    'cta'   => [ 'headline' => '...', 'primary_url' => '/quote?service=xxx&src=my-landing' ],
    'schema' => [ 'service_type' => '...', 'area_served' => ['Vancouver', 'Burnaby', 'Richmond'] ],
    'form_presets' => [ 'service' => 'xxx', 'property_type' => '' ],
];
```

### Step 2: Create the page file

Create `/services/<slug>.php`:

```php
<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';

$service = require dirname(__DIR__) . '/includes/service-data/<slug>.php';
require dirname(__DIR__) . '/includes/service-template.php';
```

That's it. The `.htaccess` rewrite makes `/services/<slug>` work without `.php`.

### Step 3: CTA URLs

All CTA buttons should use `/quote?service=<key>&src=<slug>-landing` so jobFlow:
- Pre-selects the service checkbox
- Tracks which landing page the lead came from
- Stores the source in `quote_requests.source`

### Available proof section types

| Type | Array key | Renders |
|------|-----------|---------|
| `checklist` | `items` (string[]) | Checkmark list |
| `benefits` | `items` ([title, desc][]) | Card grid with green left border |
| `process` | `steps` ([title, desc][]) | Numbered circles |
| `before_after` | `pairs` ([before, after, caption][]) | Side-by-side images |

### CSS

All service landing page styles live in `/assets/css/pages/service-landing.css` (already in master.css). Prefixes: `slh-` (hero), `slp-` (proof sections). Do NOT create separate CSS files per service.

### CMS transition

When the CMS is built, swap the `require` of the static data file for a database read returning the same array shape. The template stays the same.

---

## 10. Security Requirements

- All user input must be escaped before HTML output (`h()` or `htmlspecialchars()`)
- All SQL queries with user data must use prepared statements
- All forms must include CSRF tokens
- Never store credentials outside `/app_config/secrets.php`
- Never expose database errors to users (catch PDOException, show generic message)
- Session cookies must be httponly and samesite=Lax (configured in `session_config.php`)
- File uploads (future) must validate type, size, and store outside web root

---

## 11. Deployment Notes

- Hosting: cPanel at mowology.ca
- Session save path: `/home/mowology/tmp` (cPanel workaround)
- Database naming: cPanel prefix `mowology_` (e.g., `mowology_landscape_crm`)
- `.htaccess` blocks direct access to config and auth PHP files
- `/app_config/` and `secrets.php` are in `.gitignore`
- No build step needed — edits are live after push
