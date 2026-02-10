# CMS Implementation Guide

## Quick Start: Getting CMS Running

This guide walks you through the **first 2 weeks** of CMS implementation, starting with database setup and ending with your first service landing page running on the CMS.

---

## Week 1: Foundation Setup

### Step 1.1: Run Database Migrations

```bash
# From your local environment or server terminal
mysql -u username -p database_name < database/migrations/500_cms_core.sql
mysql -u username -p database_name < database/migrations/501_cms_marketing.sql
```

**What was created:**
- `cms_pages` — Page records
- `cms_blocks` — Content blocks
- `cms_block_types` — Block type registry (pre-populated with 10 types)
- `cms_menus` — Menu definitions
- `cms_menu_items` — Menu items (hierarchical)
- `media_assets` — Media library
- `cms_page_revisions` — Version history
- `marketing_queue` — Background job queue
- `seo_page_drafts` — Generated page drafts
- Supporting tables for marketing automation

### Step 1.2: Create Layout Templates

Create the following files in `/public/layouts/`:

#### `/public/layouts/default.php`
```php
<?php
// Default layout - used by most pages
// Variables available: $page, $blocks

require __DIR__ . '/../crm/includes/cms-renderer.php';
?>

<main role="main" class="cms-page">
  <div class="container">
    <?php echo cms_renderSections($blocks); ?>
  </div>
</main>
```

#### `/public/layouts/homepage.php`
```php
<?php
// Homepage layout - hero + full-width sections
// Variables available: $page, $blocks
?>

<main role="main" class="cms-page cms-page--homepage">
  <?php echo cms_renderHero($blocks); ?>

  <div class="container">
    <?php echo cms_renderSections($blocks, 1); ?>
  </div>
</main>
```

#### `/public/layouts/service_landing.php`
```php
<?php
// Service landing page layout (matches current service page design)
// Variables available: $page, $blocks
?>

<main role="main" class="cms-page cms-page--service-landing">
  <?php echo cms_renderHero($blocks); ?>

  <section class="proof-sections">
    <div class="container">
      <?php echo cms_renderSections($blocks, 1); ?>
    </div>
  </section>
</main>
```

#### `/public/layouts/contact.php`
```php
<?php
// Contact page layout (contact form + sidebar info)
?>

<main role="main" class="cms-page cms-page--contact">
  <div class="container">
    <div class="row">
      <div class="col-md-8">
        <?php echo cms_renderSections($blocks); ?>
      </div>
      <div class="col-md-4 contact-sidebar">
        <!-- Contact info, hours, etc. -->
      </div>
    </div>
  </div>
</main>
```

#### `/public/layouts/portfolio.php`
```php
<?php
// Portfolio page layout (grid + filters)
?>

<main role="main" class="cms-page cms-page--portfolio">
  <div class="container-fluid">
    <?php echo cms_renderSections($blocks); ?>
  </div>
</main>
```

### Step 1.3: Create Block Renderers

Create the following files in `/public/crm/includes/blocks/`:

#### `/public/crm/includes/blocks/hero.php`

```php
<?php
/**
 * Hero Block Renderer
 *
 * Config structure:
 * {
 *   "headline": "Main heading",
 *   "subheadline": "Optional subheading",
 *   "cta_text": "Button text",
 *   "cta_url": "/path/to/page",
 *   "media_id": 123,
 *   "media_alt": "Alt text"
 * }
 */

$headline = $config['headline'] ?? 'Welcome';
$subheadline = $config['subheadline'] ?? '';
$ctaText = $config['cta_text'] ?? 'Learn More';
$ctaUrl = $config['cta_url'] ?? '#';
$mediaId = $config['media_id'] ?? null;
$mediaAlt = $config['media_alt'] ?? 'Hero image';
?>

<section class="hero-block" role="region" aria-label="Hero section">
  <div class="hero-content">
    <h1 class="hero-headline"><?php echo h($headline); ?></h1>
    <?php if ($subheadline): ?>
      <p class="hero-subheadline"><?php echo h($subheadline); ?></p>
    <?php endif; ?>

    <?php if ($ctaUrl): ?>
      <a href="<?php echo h($ctaUrl); ?>" class="btn btn-primary btn-lg">
        <?php echo h($ctaText); ?>
      </a>
    <?php endif; ?>
  </div>

  <?php if ($mediaId): ?>
    <?php $media = cms_getMediaAssetById($mediaId); ?>
    <?php if ($media): ?>
      <div class="hero-image">
        <img
          src="<?php echo h($media['file_path']); ?>"
          alt="<?php echo h($mediaAlt ?: $media['alt_text']); ?>"
          class="img-fluid"
          loading="lazy"
        >
      </div>
    <?php endif; ?>
  <?php endif; ?>
</section>
```

#### `/public/crm/includes/blocks/rich_text.php`

```php
<?php
/**
 * Rich Text Block Renderer
 *
 * Config structure:
 * {
 *   "html_content": "<p>HTML content (sanitized)</p>",
 *   "alignment": "left|center|right"
 * }
 */

$htmlContent = $config['html_content'] ?? '';
$alignment = $config['alignment'] ?? 'left';
?>

<section class="rich-text-block rich-text-block--<?php echo h($alignment); ?>">
  <div class="container">
    <div class="rich-text-content">
      <?php echo $htmlContent; // Already sanitized on save ?>
    </div>
  </div>
</section>
```

#### `/public/crm/includes/blocks/cta.php`

```php
<?php
/**
 * CTA Block Renderer
 */

$heading = $config['heading'] ?? '';
$subheading = $config['subheading'] ?? '';
$primaryText = $config['primary_text'] ?? 'Get Started';
$primaryUrl = $config['primary_url'] ?? '#';
$secondaryText = $config['secondary_text'] ?? '';
$secondaryUrl = $config['secondary_url'] ?? '';
?>

<section class="cta-block">
  <div class="container">
    <div class="cta-content text-center">
      <?php if ($heading): ?>
        <h2><?php echo h($heading); ?></h2>
      <?php endif; ?>

      <?php if ($subheading): ?>
        <p class="cta-subheading"><?php echo h($subheading); ?></p>
      <?php endif; ?>

      <div class="cta-buttons">
        <a href="<?php echo h($primaryUrl); ?>" class="btn btn-primary btn-lg">
          <?php echo h($primaryText); ?>
        </a>

        <?php if ($secondaryUrl): ?>
          <a href="<?php echo h($secondaryUrl); ?>" class="btn btn-secondary btn-lg">
            <?php echo h($secondaryText); ?>
          </a>
        <?php endif; ?>
      </div>
    </div>
  </div>
</section>
```

#### `/public/crm/includes/blocks/feature_grid.php`

```php
<?php
/**
 * Feature Grid Block Renderer
 */

$heading = $config['heading'] ?? '';
$intro = $config['intro'] ?? '';
$features = $config['features'] ?? [];
$layout = $config['layout'] ?? 3; // 3 or 4 columns
?>

<section class="feature-grid-block">
  <div class="container">
    <?php if ($heading): ?>
      <h2 class="text-center"><?php echo h($heading); ?></h2>
    <?php endif; ?>

    <?php if ($intro): ?>
      <p class="lead text-center"><?php echo h($intro); ?></p>
    <?php endif; ?>

    <div class="row row-cols-1 row-cols-md-<?php echo intval($layout); ?> g-4">
      <?php foreach ($features as $feature): ?>
        <div class="col">
          <div class="feature-card">
            <?php if (!empty($feature['icon'])): ?>
              <div class="feature-icon">
                <i data-feather="<?php echo h($feature['icon']); ?>"></i>
              </div>
            <?php endif; ?>

            <h3><?php echo h($feature['title'] ?? ''); ?></h3>
            <p><?php echo h($feature['description'] ?? ''); ?></p>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
```

#### `/public/crm/includes/blocks/faq.php`

```php
<?php
/**
 * FAQ Block Renderer (Accordion)
 */

$heading = $config['heading'] ?? 'Frequently Asked Questions';
$faqs = $config['faqs'] ?? [];
?>

<section class="faq-block">
  <div class="container">
    <?php if ($heading): ?>
      <h2 class="text-center mb-5"><?php echo h($heading); ?></h2>
    <?php endif; ?>

    <div class="accordion" id="faq-accordion">
      <?php foreach ($faqs as $index => $faq): ?>
        <div class="accordion-item">
          <h3 class="accordion-header">
            <button
              class="accordion-button <?php echo $index > 0 ? 'collapsed' : ''; ?>"
              type="button"
              data-bs-toggle="collapse"
              data-bs-target="#faq-<?php echo $index; ?>"
            >
              <?php echo h($faq['q'] ?? ''); ?>
            </button>
          </h3>
          <div
            id="faq-<?php echo $index; ?>"
            class="accordion-collapse collapse <?php echo $index === 0 ? 'show' : ''; ?>"
            data-bs-parent="#faq-accordion"
          >
            <div class="accordion-body">
              <?php echo $faq['a'] ?? ''; // Assume pre-sanitized ?>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
```

#### `/public/crm/includes/blocks/gallery.php`

```php
<?php
/**
 * Gallery Block Renderer
 */

$mediaIds = $config['media_ids'] ?? [];
$layout = $config['layout'] ?? 'grid'; // grid|carousel
?>

<section class="gallery-block">
  <div class="container">
    <?php if ($layout === 'carousel'): ?>
      <div id="gallery-carousel" class="carousel slide">
        <div class="carousel-inner">
          <?php foreach ($mediaIds as $idx => $mediaId): ?>
            <?php $media = cms_getMediaAssetById($mediaId); ?>
            <?php if ($media): ?>
              <div class="carousel-item <?php echo $idx === 0 ? 'active' : ''; ?>">
                <img
                  src="<?php echo h($media['file_path']); ?>"
                  alt="<?php echo h($media['alt_text']); ?>"
                  class="d-block w-100"
                  loading="lazy"
                >
              </div>
            <?php endif; ?>
          <?php endforeach; ?>
        </div>
      </div>
    <?php else: ?>
      <!-- Grid layout -->
      <div class="row row-cols-1 row-cols-md-3 g-4">
        <?php foreach ($mediaIds as $mediaId): ?>
          <?php $media = cms_getMediaAssetById($mediaId); ?>
          <?php if ($media): ?>
            <div class="col">
              <img
                src="<?php echo h($media['file_path']); ?>"
                alt="<?php echo h($media['alt_text']); ?>"
                class="img-fluid gallery-image"
                loading="lazy"
              >
            </div>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</section>
```

#### `/public/crm/includes/blocks/custom.php`

```php
<?php
/**
 * Custom HTML Block Renderer (Admin-only)
 *
 * WARNING: This block outputs raw HTML. Only admins can create/edit.
 */

$htmlContent = $config['html_content'] ?? '';

// Output raw (assume admin has pre-sanitized)
echo $htmlContent;
?>
```

### Step 1.4: Update .htaccess for CMS Routing

**File: `/public/.htaccess`**

Add these rules (before any existing RewriteRules):

```apache
# CMS Routing: Route CMS-eligible pages through cms-render.php
# Check if CMS page exists before routing (optional, for performance)

RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteCond %{REQUEST_URI} !^/cms-render\.php
RewriteCond %{REQUEST_URI} !^/admin
RewriteCond %{REQUEST_URI} !^/crm
RewriteCond %{REQUEST_URI} !^/api
RewriteCond %{REQUEST_URI} !^/app_config
RewriteCond %{REQUEST_URI} !^/uploads
RewriteCond %{REQUEST_URI} !^/assets
RewriteCond %{REQUEST_URI} !^/customer
RewriteCond %{REQUEST_URI} !^/jobFlow
RewriteCond %{REQUEST_URI} !^/sessions
RewriteCond %{REQUEST_URI} !^/loginAuth

# Route eligible pages to CMS renderer
# Examples: /, /about, /services, /services/strata-landscaping, /portfolio
RewriteRule ^(.*)$ /cms-render.php?page=$1 [L,QSA]
```

---

## Week 2: Migrate Service Landing Pages

### Step 2.1: Create Migration Script

**File: `/public/crm/api/migrate-service-pages.php`**

```php
<?php
/**
 * Migrate service landing pages from static files to CMS
 *
 * Usage: Visit /crm/api/migrate-service-pages.php?action=preview|execute
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/../includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/cms-functions.php';

// Admin-only
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
requireLogin();
if ($_SESSION['user']['role'] !== 'admin') {
    die('Unauthorized');
}

$action = $_GET['action'] ?? 'preview';

// Find all service data files
$serviceDataDir = dirname(__DIR__) . '/includes/service-data';
$serviceFiles = glob($serviceDataDir . '/*.php');

$results = [];

foreach ($serviceFiles as $file) {
    $slug = pathinfo($file, PATHINFO_FILENAME);
    $data = require $file;

    $results[$slug] = migrate_service_page($data, $action === 'execute');
}

// Output results
header('Content-Type: application/json');
echo json_encode($results, JSON_PRETTY_PRINT);

/**
 * Migrate a single service page
 */
function migrate_service_page(array $data, bool $execute = false): array
{
    $db = getDB();

    try {
        // Create page record
        $pageData = [
            'slug' => $data['slug'],
            'title' => $data['title'],
            'meta_title' => $data['meta_title'] ?? $data['title'],
            'meta_description' => $data['meta_description'] ?? '',
            'page_type' => 'service_landing',
            'layout_template' => 'service_landing',
            'status' => 'draft', // Start as draft for review
        ];

        if (!$execute) {
            return [
                'status' => 'preview',
                'action' => 'would create page',
                'page_data' => $pageData,
                'blocks_count' => count($data['proof_sections'] ?? []) + 2,
            ];
        }

        // Create page
        $pageId = cms_savePage($pageData, null, $_SESSION['user']['id']);

        // Create hero block
        $heroConfig = $data['hero'] ?? [];
        cms_saveBlock($pageId, 'hero', 0, $heroConfig);

        // Create proof sections
        $position = 1;
        foreach ($data['proof_sections'] ?? [] as $section) {
            // Map proof section type to block type
            $blockType = match($section['type']) {
                'checklist' => 'feature_grid',
                'benefits' => 'feature_grid',
                'process' => 'feature_grid',
                'before_after' => 'gallery',
                default => 'rich_text',
            };

            cms_saveBlock($pageId, $blockType, $position++, $section);
        }

        // Create FAQ block if exists
        if (!empty($data['faq'])) {
            cms_saveBlock($pageId, 'faq', $position++, ['faqs' => $data['faq']]);
        }

        // Create CTA block
        if (!empty($data['cta'])) {
            cms_saveBlock($pageId, 'cta', $position++, $data['cta']);
        }

        return [
            'status' => 'success',
            'page_id' => $pageId,
            'slug' => $data['slug'],
            'blocks_created' => $position,
        ];
    } catch (Exception $e) {
        return [
            'status' => 'error',
            'slug' => $data['slug'],
            'error' => $e->getMessage(),
        ];
    }
}
```

### Step 2.2: Preview & Execute Migration

1. Visit: `https://yoursite.com/crm/api/migrate-service-pages.php?action=preview`
2. Review the output (what will be created)
3. If satisfied, visit: `https://yoursite.com/crm/api/migrate-service-pages.php?action=execute`

### Step 2.3: Test Service Page in CMS

1. Go to `https://yoursite.com/cms-render.php?page=strata-landscaping-maintenance`
2. Compare with original: `https://yoursite.com/services/strata-landscaping-maintenance.php`
3. Verify layout, content, and styling match
4. Check that links work

### Step 2.4: Publish Service Page

Once tested:
1. Go to admin panel (TBD: create admin page)
2. Find page "strata-landscaping-maintenance" (status: draft)
3. Click "Publish"
4. Page now live on CMS

### Step 2.5: Monitor & Iterate

Keep the original service pages live for 2 weeks while monitoring:
- Page load time
- User engagement (scroll depth, time on page)
- Any 404s or broken links
- Mobile responsiveness

Once confident, remove old service pages.

---

## Next Steps: Admin UI

Once the above is working, build the CMS admin UI:
- `/crm/cms-pages_appstack.php` — CRUD for pages
- `/crm/cms-blocks_appstack.php` — Block editor
- `/crm/cms-menus_appstack.php` — Menu manager
- `/crm/cms-media_appstack.php` — Media library

See `/CMS_ARCHITECTURE.md` for detailed specifications.

---

## Troubleshooting

### CMS page not found (404)
- Verify page slug is exactly as stored in database (lowercase)
- Check page status is "published"
- Test with `/cms-render.php?page=slug` directly
- Check `.htaccess` rewrite rules are active

### Blocks not rendering
- Check block type exists in `cms_block_types` table
- Verify block renderer file exists: `/crm/includes/blocks/{type}.php`
- Check block config matches schema
- Look for errors in PHP error log

### Styling not applied
- Verify CSS classes exist in site stylesheets
- Check CSS is loaded in `appstack_head.php` or layout template
- Inspect browser console for 404s on CSS files
- Verify Bootstrap is available

### Performance issues
- Implement caching in `cms_getBlocksByPageId()` (already in place, adjust TTL)
- Consider adding `Memcached` for production
- Profile database queries
- Optimize image sizes in media library

---

## Production Deployment Checklist

- [ ] Database migrations run on production
- [ ] Layout templates created and tested
- [ ] Block renderers created and tested
- [ ] `.htaccess` rewrite rules updated
- [ ] Service pages migrated and published (phase 1)
- [ ] Error handling tested (bad slugs, 404s)
- [ ] Mobile rendering verified
- [ ] SSL certificate valid
- [ ] Analytics tracking implemented (page views)
- [ ] Backup of original pages taken before deletion
- [ ] SEO redirects in place for old URLs (if applicable)

---

**Next:** Build admin CMS UI (Phase 3)
