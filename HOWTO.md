# Mowology — How-To Guides

Step-by-step checklists for common development tasks. Extracted from CLAUDE.md to keep that file concise.

---

## Adding New CRM Features

When building a new CRM feature:

1. Create the PHP page using the AppStack template from CLAUDE.md section 3 — only use `appstack_head.php` and `appstack_footer.php` includes; do NOT add boilerplate HTML tags
2. Set `$pageTitle` and `$activePage` appropriately; use `$extraHead` for extra `<head>` content
3. If it needs a new sidebar entry, add to `$navItems` in `appstack_sidebar.php`
4. Put any new styles in `mowology-brand.css` using `--mw-*` tokens
5. Use prepared statements for all database queries
6. Escape all output with `htmlspecialchars()` or `h()`
7. Include CSRF tokens in all forms
8. Use Feather icons via `data-feather="icon-name"` attribute
9. Use Bootstrap 4 grid classes for layout (`.row`, `.col-md-6`, etc.)
10. Use AppStack card components for content sections (`.card`, `.card-header`, `.card-body`)

Or use the `/new-page` slash command to scaffold a page automatically.

---

## Adding New Public Site Pages

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

## Adding New Service Landing Pages

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

## Adding New API Endpoints

Use the `/new-api` slash command, or follow the template in `.claude/commands/new-api.md`.

Key rules:
- Always call `session_write_close()` after `getCurrentUser()` and before heavy DB work
- Always verify CSRF token on POST requests
- Use `writeSystemLog()` for errors (not `error_log`)
- Catch `Throwable` not `Exception` — require failures throw `Error`
- Use `(int)`, `trim()`, `filter_var()` to sanitize all input before DB
- All DB queries must use prepared statements with `?` placeholders
- Return `{'success': true, ...}` on success, `{'error': 'message'}` on failure
- Never expose stack traces or DB errors to the response

If the endpoint needs business logic beyond simple CRUD, create a service class first at `app/Modules/[Module]/Services/[Name]Service.php`.

---

## Deployment

See `DEPLOYMENT.md` for FTP credentials, lftp commands, and the full deploy workflow.

Quick reference: `/deploy public/crm/file.php`
