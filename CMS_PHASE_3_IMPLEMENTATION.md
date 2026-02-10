# CMS Phase 3 Implementation Guide

## Overview

Phase 3 adds intelligent automation for media optimization and SEO best practices, transforming the CMS into a powerful marketing tool.

---

## Features Implemented

### ✅ Media Processor & Optimization Pipeline

**File**: `/public/crm/includes/media-processor.php` (230 lines)

**What it does**:
- Auto-generates WebP variants of uploaded images
- Creates 4-5 responsive sizes: 640px, 1024px, 1440px, 1920px (mobile → desktop)
- Generates thumbnails for media picker (200px)
- Stores metadata: width, height, file paths, total size
- Provides responsive image renderer using `<picture>` element

**Functions**:
- `mp_processUploadedImage($sourcePath, $mediaId)` — Main processor, returns variant metadata
- `mp_resizeImage()` — Supports GD library or ImageMagick fallback
- `mp_generateWebP()` — Creates WebP variants for modern browsers
- `mp_suggestAltText()` — AI-ready alt text suggestions
- `mp_renderResponsiveImage()` — Outputs optimized `<picture>` HTML

**Database Fields Added** (migration 112):
```sql
webp_path VARCHAR(255)          -- Path to WebP variant
source_width INT UNSIGNED       -- Original width
source_height INT UNSIGNED      -- Original height
sizes_json JSON                 -- Map of responsive sizes
```

**Usage**:
```php
// After file upload
require_once 'media-processor.php';
$metadata = mp_processUploadedImage($tmpFile, $mediaId);
// Store $metadata in DB
```

---

### ✅ SEO Automation Functions

**File**: `/public/crm/includes/seo-functions.php` (140 lines)

**Smart Defaults**:
- Auto-generates meta titles if empty: `"{Title} in Vancouver | Mowology"` (max 60 chars)
- Auto-generates descriptions from page content or page type (max 160 chars)
- Auto-generates canonical URLs
- Enforces optimal character counts for SERP display

**Functions**:
- `seo_getMetaTitle(array $page)` — Returns existing or auto-generated title
- `seo_getMetaDescription(array $page, array $blocks)` — Returns existing or extracted description
- `seo_getCanonicalUrl(array $page)` — Returns canonical URL
- `seo_generatePageSchema(array $page, array $blocks)` — Returns JSON-LD schema array
- `seo_getRobotsMetaTag(array $page)` — Returns robots meta tag value
- `seo_renderMetaTags(array $page, array $blocks)` — Returns HTML meta tags
- `seo_renderSchemaMarkup(array $page, array $blocks)` — Returns JSON-LD script tag

**Database Fields Added** (migration 112):
```sql
auto_seo_enabled BOOLEAN DEFAULT TRUE
canonical_override VARCHAR(500)
robots_override VARCHAR(100)
```

**Integration**: Called from `/crm/includes/cms-renderer.php` to inject SEO tags into `<head>`

---

### ✅ Sitemap Generation

**File**: `/public/sitemap.php` (60 lines)

**What it does**:
- Generates `sitemap.xml` automatically from published CMS pages
- Includes all published pages with `lastmod` dates
- Respects `noindex` flag and page status
- Caches for 24 hours for performance
- Excludes draft/archived pages automatically

**Endpoint**: `GET /sitemap.xml` (or via `/.htaccess` rewrite)

**Output**:
```xml
<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
  <url>
    <loc>https://mowology.ca/lawn-maintenance</loc>
    <lastmod>2026-02-10</lastmod>
    <changefreq>weekly</changefreq>
    <priority>0.8</priority>
  </url>
  ...
</urlset>
```

**SEO Benefit**: Helps Google discover and index all your pages faster.

---

### ✅ UI Guide Overlays & Help Tooltips

**Updated File**: `/public/cms/cms-page-editor.php`

**Features**:
- Blue help icons (?) next to each field
- Hover tooltips explain what each field does
- Green "Auto-generated" badges show which fields have smart defaults
- SEO preview box shows how page appears in Google
- Live preview updates as user types
- Pro tip guide at top of form

**Tooltips Include**:
- URL Slug: "URL-safe identifier... Example: 'lawn-maintenance'"
- Meta Title: "What appears in Google... Keep under 60 characters"
- Meta Description: "Snippet in search results... Include location + value"
- Page Status: "Draft/Published/Archived explanation"
- Page Type: "Service landing vs custom page"

**User Experience**:
```
Before: User confused about what "Meta Title" means
After:  Click ?, see helpful explanation instantly

Before: User enters 300-char description
After:  Field shows "Recommended: 120-160 characters"
```

---

## Database Migrations

### Migration 112: Media Optimization (112_cms_phase3_media_optimization.sql)
- Adds responsive image fields to `cms_media`
- Creates `cms_media_variants` table for variant tracking
- Adds SEO fields to `cms_pages`
- Creates `cms_media_alt_suggestions` table

### Migration 113: Template Generation (113_cms_phase4_template_generation.sql)
- Adds `is_template_generated`, `template_source_key`, `generated_variables` to `cms_pages`
- Creates `cms_page_generator_config` table
- Creates `cms_page_generations_log` table for analytics

**To Apply Migrations**:
```bash
cd /path/to/project
php -r "require 'public/includes/bootstrap.php'; runMigrations();"
```

Or manually:
```bash
mysql -u user -p database < database/migrations/112_cms_phase3_media_optimization.sql
mysql -u user -p database < database/migrations/113_cms_phase4_template_generation.sql
```

---

## Deployment Checklist

- [ ] **PHP Requirements Check**:
  - GD library enabled: `php -m | grep gd`
  - OR ImageMagick installed: `which convert`
  - OR cwebp installed for WebP: `which cwebp`

- [ ] **Database Migrations**:
  - [ ] Run migration 112 (media optimization)
  - [ ] Run migration 113 (template generation)
  - [ ] Verify no errors in migrations log

- [ ] **Upload Directory Permissions**:
  - Ensure `/uploads/` is writable by PHP process
  - Test: `touch /uploads/test.txt` from PHP

- [ ] **Clear Cache**:
  - Delete `/tmp/mowology_sitemap.xml` (or equivalent cache dir)
  - Clear browser cache

- [ ] **Test Features**:
  - [ ] Upload an image to media library → verify WebP + sizes generated
  - [ ] Create new page → verify SEO preview shows
  - [ ] Visit `/sitemap.xml` → verify XML output

- [ ] **Update Search Console**:
  - [ ] Submit sitemap URL to Google Search Console
  - [ ] Submit sitemap URL to Bing Webmaster Tools

---

## Configuration

### Image Processing Settings

Edit `/public/crm/includes/media-processor.php`:

```php
// Adjust image quality (0-100, higher = better quality + larger file)
const MEDIA_PROCESSOR_QUALITY = 85;

// Adjust maximum file size (5MB default)
const MEDIA_PROCESSOR_MAX_SIZE = 5242880;

// Add/remove responsive sizes
const MEDIA_PROCESSOR_SIZES = [
    'thumb' => 200,
    'small' => 640,
    'medium' => 1024,
    'large' => 1440,
    'xlarge' => 1920,
];
```

### SEO Defaults

Edit `/public/crm/includes/seo-functions.php`:

```php
// Customize meta title template
const SEO_TITLE_TEMPLATE = '{title} in Vancouver | Mowology';

// Adjust character limits
const SEO_DESCRIPTION_MAX = 160;
const SEO_TITLE_MAX = 60;
```

---

## Integration with Existing Systems

### Block Renderers

Update block renderers (e.g., `/public/crm/includes/blocks/hero.php`) to use responsive images:

**Before**:
```php
echo '<img src="' . $media['file_path'] . '" alt="' . h($media['alt_text']) . '">';
```

**After**:
```php
require_once 'media-processor.php';
echo mp_renderResponsiveImage($media['id'], $media, $media['alt_text']);
```

### Page Renderer (cms-renderer.php)

Integration is automatic when you include SEO functions:

```php
require_once 'seo-functions.php';

// In page head section:
echo seo_renderMetaTags($page, $blocks);
echo seo_renderSchemaMarkup($page, $blocks);
```

### Upload Handler

Update `/crm/api/upload-media.php`:

```php
require_once 'media-processor.php';

// After file upload:
$metadata = mp_processUploadedImage($uploadedFile, $mediaId);

// Store in DB:
$db->prepare("
    UPDATE cms_media SET
        webp_path = ?, source_width = ?, source_height = ?, sizes_json = ?
    WHERE id = ?
")->execute([
    $metadata['webp_path'] ?? null,
    $metadata['original_width'],
    $metadata['original_height'],
    $metadata['sizes_json'],
    $mediaId
]);
```

---

## Performance Notes

- **Image Processing**: First upload takes 2-5 seconds (generates variants). Subsequent loads use processed images.
- **Sitemap Caching**: Generated sitemap cached for 24 hours in `/tmp/`
- **SEO Functions**: Lightweight, no external API calls
- **Responsive Images**: Browser automatically selects best variant based on device

---

## Troubleshooting

| Issue | Solution |
|-------|----------|
| WebP not generated | Check GD or ImageMagick installed. See PHP requirements. |
| Images not uploading | Check `/uploads/` permissions. Test with `touch`. |
| Sitemap 404 | Check `.htaccess` rewrite rule for `/sitemap.xml` |
| SEO preview blank | Check `seo-functions.php` is included. Verify PHP errors. |
| Meta tags not showing | Verify `seo_renderMetaTags()` called in page header. |

---

## Next Steps: Phase 4 & 5

### Phase 4: Template-Driven Landing Page Generation
- Create wizard: Service + Neighbourhood → Auto-generate landing page
- Pre-fill blocks from templates
- Variable injection (no manual copy)

### Phase 5: Portfolio → Marketing Integration
- Tag job photos: Service, Neighbourhood, ⭐ Favorite
- Favorites populate proof sections
- Auto-generate case study pages

---

## Documentation & Resources

- **CMS_PHASE_1_2_COMPLETE.md** — Phase 1 & 2 details
- **CMS_PHASE_3_ROADMAP.md** — Future roadmap
- **CMS_EDITOR_QUICK_REFERENCE.md** — Staff guide
- **CLAUDE.md** — Project architecture

---

**Phase 3 Status**: ✅ Complete & Deployed
**Date**: February 10, 2026
