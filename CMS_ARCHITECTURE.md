# Mowology CMS + Marketing Automation Architecture

## Executive Summary

This document outlines the design for converting the Mowology front-end into a **CMS-managed system** powered by database-driven pages and an **automated marketing engine** that converts GSC recommendations into published content.

**Non-destructive approach:** Existing pages remain functional. CMS runs parallel until ready to switch. Service landing pages migrate first (already template-ready), then static pages (home, about, contact), then portfolio. No rewrites.

---

## Part A: CMS System Design

### 1. Core Concept

**Goal:** Every front-end page (home, about, services, portfolio, contact, and future landing pages) is rendered from database records, not hardcoded PHP files.

**Principle:** Pages are composed of **blocks** (hero, feature grid, testimonials, CTA, etc.). Blocks are versioned, reusable, and have their own edit history.

**Rendering:** A single router (`/public/cms.php?page=home` or via `.htaccess` rewrite to `/home`) loads the page from the database, assembles its blocks, and renders via a template engine.

---

### 2. Data Model

#### 2.1 Core CMS Tables

```sql
CREATE TABLE cms_pages (
    id INT PRIMARY KEY AUTO_INCREMENT,
    slug VARCHAR(191) UNIQUE NOT NULL,
    title VARCHAR(255) NOT NULL,
    meta_title VARCHAR(255),
    meta_description VARCHAR(500),
    meta_keywords VARCHAR(255),
    canonical_url VARCHAR(500),
    og_image_path VARCHAR(500),

    page_type ENUM('home', 'about', 'services', 'service_landing', 'portfolio', 'contact', 'custom', 'landing') DEFAULT 'custom',
    layout_template VARCHAR(50) DEFAULT 'default', -- default|service_landing|contact|homepage

    status ENUM('draft', 'published', 'archived') DEFAULT 'draft',
    publish_at DATETIME,
    unpublish_at DATETIME,
    noindex TINYINT DEFAULT 0,

    seo_score INT,
    view_count INT DEFAULT 0,

    created_by INT NOT NULL REFERENCES users(id),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_by INT REFERENCES users(id),
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    published_by INT REFERENCES users(id),
    published_at TIMESTAMP NULL,

    INDEX (slug),
    INDEX (status),
    INDEX (page_type),
    INDEX (layout_template)
);

CREATE TABLE cms_blocks (
    id INT PRIMARY KEY AUTO_INCREMENT,
    page_id INT NOT NULL REFERENCES cms_pages(id) ON DELETE CASCADE,
    block_type VARCHAR(50) NOT NULL, -- hero|feature_grid|testimonials|cta|faq|gallery|service_cards|custom
    position INT NOT NULL, -- render order

    config_json JSON NOT NULL, -- block-specific configuration
    content_json JSON, -- optional dynamic content

    visibility JSON, -- optional: { "devices": ["mobile", "desktop"], "roles": ["all", "admin"] }

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX (page_id),
    INDEX (block_type),
    INDEX (position)
);

CREATE TABLE cms_block_types (
    id INT PRIMARY KEY AUTO_INCREMENT,
    block_type VARCHAR(50) UNIQUE NOT NULL,
    label VARCHAR(100),
    description TEXT,
    schema_json JSON, -- expected config structure
    preview_image_path VARCHAR(255),
    renderer_path VARCHAR(255), -- e.g., /crm/includes/blocks/hero.php
    is_active TINYINT DEFAULT 1
);

CREATE TABLE cms_menus (
    id INT PRIMARY KEY AUTO_INCREMENT,
    menu_key VARCHAR(50) UNIQUE NOT NULL, -- header_nav|footer_nav|sidebar_nav
    label VARCHAR(100),
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE cms_menu_items (
    id INT PRIMARY KEY AUTO_INCREMENT,
    menu_id INT NOT NULL REFERENCES cms_menus(id) ON DELETE CASCADE,
    parent_id INT REFERENCES cms_menu_items(id),

    label VARCHAR(255) NOT NULL,
    url VARCHAR(500) NOT NULL, -- e.g., /services, /about, /portfolio?category=landscaping
    title_attr VARCHAR(255), -- hover text

    target VARCHAR(10) DEFAULT '_self', -- _self|_blank
    rel_attr VARCHAR(100), -- nofollow, noopener, etc.

    is_active TINYINT DEFAULT 1,
    position INT NOT NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX (menu_id),
    INDEX (parent_id),
    INDEX (position)
);

CREATE TABLE cms_page_revisions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    page_id INT NOT NULL REFERENCES cms_pages(id) ON DELETE CASCADE,

    slug VARCHAR(191),
    title VARCHAR(255),
    meta_title VARCHAR(255),
    meta_description VARCHAR(500),

    blocks_snapshot JSON, -- serialized blocks state at time of revision

    revision_type ENUM('draft', 'published', 'restore') DEFAULT 'draft',
    revision_message VARCHAR(500),

    created_by INT NOT NULL REFERENCES users(id),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX (page_id),
    INDEX (created_at)
);

CREATE TABLE media_assets (
    id INT PRIMARY KEY AUTO_INCREMENT,

    original_filename VARCHAR(255) NOT NULL,
    stored_filename VARCHAR(255) NOT NULL, -- sanitized, hashed
    file_path VARCHAR(500) NOT NULL, -- /uploads/2026/02/abc123.jpg

    file_type ENUM('image', 'video', 'document', 'archive') DEFAULT 'image',
    mime_type VARCHAR(50),
    file_size INT,

    image_width INT,
    image_height INT,
    aspect_ratio VARCHAR(20), -- 16:9, 4:3, 1:1, etc.

    alt_text VARCHAR(255),
    caption TEXT,
    description TEXT,

    tags JSON, -- ["service/strata", "location/vancouver", "season/spring"]
    is_favorite TINYINT DEFAULT 0,

    usage_count INT DEFAULT 0, -- tracks how many blocks use this media

    created_by INT NOT NULL REFERENCES users(id),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX (tags),
    INDEX (is_favorite),
    INDEX (created_at),
    FULLTEXT (alt_text, caption, description)
);
```

---

#### 2.2 Block Registry

Block types are pre-defined and extensible. Each block type has:
- A unique `block_type` code
- A JSON schema describing its `config_json` structure
- A renderer PHP file

**Built-in Block Types:**

| Type | Purpose | Config |
|------|---------|--------|
| `hero` | Hero banner with CTA | `{ headline, subheadline, cta_text, cta_url, media_id, media_alt }` |
| `feature_grid` | 3/4-col feature cards | `{ heading, intro, features: [{ icon, title, desc }], layout: 3\|4 }` |
| `testimonials` | Carousel or grid of testimonials | `{ heading, testimonials: [{ quote, author, role, media_id }] }` |
| `cta` | Call-to-action section | `{ heading, subheading, primary_url, primary_text, secondary_url, secondary_text }` |
| `faq` | Accordion FAQ | `{ heading, faqs: [{ q, a }] }` |
| `gallery` | Image grid/carousel | `{ media_ids: [], layout: grid\|carousel }` |
| `service_cards` | List of services | `{ heading, services: [{ title, icon, desc, link }] }` |
| `rich_text` | WYSIWYG content | `{ html_content, alignment: left\|center\|right }` |
| `portfolio_showcase` | Embedded portfolio cards | `{ filters: { service: '', location: '', tags: [] }, limit: 6, layout: grid\|slider }` |
| `custom` | Raw HTML/JS | `{ html_content }` (admin-only) |

---

### 3. Migration Strategy: Three Phases

#### Phase 1: Service Landing Pages (Lowest Risk)

**Current State:** `/services/<slug>.php` loads data from `/includes/service-data/<slug>.php`

**Change:** Minimal. Swap the static require with a database read returning the same array.

**Process:**
1. Create migration to insert existing service data into `cms_pages` + `cms_blocks`
2. Update `service-template.php` to check CMS first (cacheable)
3. Publish all service landing pages
4. Remove static `/includes/service-data/` files from git

**Timeline:** 1 day. No user-facing changes. Fully reversible.

---

#### Phase 2: Static Pages (Medium Risk)

**Pages:** Home, About, Contact, Quote, Get Free Quote

**Current State:** Hardcoded HTML + layout includes

**Change:** Move content into `cms_pages` + `cms_blocks`. Add a "preview" link in admin.

**Process:**
1. Create page records for each (status='draft')
2. Extract content into blocks (hero, features, testimonials, CTA, form)
3. Test rendering via `/cms?page=home` (parallel to live `/index.php`)
4. Compare rendering visually
5. Once identical, update `.htaccess` to route `/` to CMS page if published
6. Publish pages one by one (traffic light approach)

**Reversible:** If CMS page fails, `.htaccess` falls back to `/index.php`

---

#### Phase 3: Portfolio Integration

**Goal:** Embed real portfolio cards in services + home page automatically

**Process:**
1. Add `portfolio_showcase` block type
2. Create dashboard showing "Portfolio tagged for service X at location Y"
3. Manually add portfolio showcase blocks to service pages
4. Future: Auto-insert top-3 portfolio cards per service

---

### 4. CMS Page Renderer

**Single entry point: `/public/cms-render.php`**

```php
<?php
// Routes: /, /about, /services, /contact, /portfolio, etc.
// Called by .htaccess rewrite or explicit ?page=slug param

$pageSlug = getRequestedPageSlug($_REQUEST, $_SERVER);

$page = cms_getPageBySlug($pageSlug);

if (!$page || $page['status'] !== 'published') {
    // If page not found or not published, 404 or redirect to legacy page
    header("HTTP/1.1 404 Not Found");
    exit;
}

// Load layout template and render blocks
$layout = $page['layout_template'];
$blocks = cms_getBlocksByPageId($page['id']);

require "/layouts/{$layout}.php"; // outputs page
?>
```

**Layout Templates:**

1. **default.php** — Two-column with sidebar option
2. **homepage.php** — Full-width, hero + sections
3. **service_landing.php** — Hero + proof + FAQ + CTA (matches current service pages)
4. **contact.php** — Form + contact info sidebar
5. **portfolio.php** — Grid layout with filters

---

### 5. Block Renderer Registry

**File: `/public/crm/includes/blocks/renderer.php`**

```php
function cms_renderBlock($block) {
    $type = $block['block_type'];
    $path = BLOCKS_RENDERER_DIR . "/{$type}.php";

    if (!file_exists($path)) {
        return "<!-- Block {$type} renderer not found -->";
    }

    ob_start();
    include $path;
    return ob_get_clean();
}
```

**Block Renderer Files:**
- `/crm/includes/blocks/hero.php` — Hero block output
- `/crm/includes/blocks/feature_grid.php` — Feature grid output
- `/crm/includes/blocks/testimonials.php` — Testimonials carousel
- `/crm/includes/blocks/cta.php` — CTA section
- `/crm/includes/blocks/faq.php` — FAQ accordion
- `/crm/includes/blocks/gallery.php` — Gallery/slider
- `/crm/includes/blocks/service_cards.php` — Service listing
- `/crm/includes/blocks/rich_text.php` — WYSIWYG content
- `/crm/includes/blocks/portfolio_showcase.php` — Portfolio cards
- `/crm/includes/blocks/custom.php` — Raw HTML (escaped)

Each renderer:
- Receives `$block` array with `config_json` decoded
- Safely outputs HTML (escapes all user data)
- Uses site CSS classes + design tokens

---

## Part B: Marketing Automation Engine

### 1. Core Concept

**Goal:** Automatically convert GSC recommendations into published pages via a state machine.

**Pipeline:**
```
GSC Data → Recommendation (new) → User Reviews & Assigns Target → Draft Generated → Admin Reviews & Publishes → Monitoring (measure performance) → Status Updated (won/parked)
```

---

### 2. Recommendation State Machine

```
new (scored, awaiting action)
├─ assign target → accepted (targeted service/location assigned)
├─ ignore → ignored (skip this one)
└─ [timeout: auto-archived after 90 days]

accepted (target assigned, awaiting draft)
├─ generate draft → draft_created
├─ update target → accepted (modified target, rescore)
└─ [action: create page draft from recommendation]

draft_created (page draft ready for review)
├─ publish → published (activate page)
├─ edit draft → draft_created (modified content, keep reviewing)
└─ reject draft → accepted (revert to target assignment step)

published (page live)
├─ monitor → monitoring (measure performance vs recommendation)
├─ archive → archived (take down page)

monitoring (measuring performance)
├─ won (goal met) → won
├─ needs work → published (keep optimizing)
├─ underperforming → parked (deprioritize, keep live)

won (goal achieved)
└─ [terminal: recommendation succeeded]

parked (low priority, keep live but don't push)
└─ [terminal: deprioritized]

ignored (rejected/spam)
└─ [terminal: won't pursue]
```

---

### 3. Recommendation Actions

Each recommendation row in admin UI has buttons:

| Action | Precondition | Effect |
|--------|--------------|--------|
| **Assign** | status=new | Open modal to assign `target_id` + `season_id` → status=accepted |
| **Draft** | status=accepted | Generate page from template + SEO data → status=draft_created |
| **Preview** | status=draft_created | Show preview of generated page content |
| **Edit** | status=draft_created | Open editor to modify draft before publish |
| **Publish** | status=draft_created | Create CMS page, set to published → status=published |
| **Archive** | status=published | Unpublish page, set recommendation to archived |
| **Ignore** | status=new\|accepted | Skip recommendation → status=ignored |
| **Rescore** | any | Recalculate priority score |
| **View** | status=published | Navigate to published page |

---

### 4. Page Generation Template

**When recommendation status changes to "accepted", queue a background job:**

`generatePageFromRecommendation($recommendation_id)`

**Inputs:**
- Recommendation query, search volume, position, etc.
- Assigned target (city/postcode/neighbourhood)
- Assigned season
- Related portfolio projects (tags match service + location)

**Process:**
1. Generate meta title + description (SEO-optimized, keyword-rich)
2. Generate H1 (clear, semantic)
3. Generate intro paragraph (50-100 words)
4. Generate body sections:
   - Problem statement (why this matters)
   - Solution/service overview
   - Benefits (3-4 bullets)
   - Process/approach
   - Call-to-action
5. Pull best portfolio cards (3-4 images, tagged for this service + location)
6. Generate FAQ from common related queries (if available)
7. Generate internal links to related pages
8. Generate JSON-LD Schema (LocalBusiness + Service)
9. Store in `seo_page_drafts` table

**AI Support:** Optional. If OpenAI API key exists, use GPT-4 to draft sections. Otherwise, use template-based generation.

---

### 5. Marketing Queue + Cron

**Queue Table:**

```sql
CREATE TABLE marketing_queue (
    id INT PRIMARY KEY AUTO_INCREMENT,
    job_type VARCHAR(50), -- generate_draft|sync_gsc|update_status|measure_performance|suggest_meta
    recommendation_id INT REFERENCES seo_recommendations(id),
    page_id INT REFERENCES cms_pages(id),

    status ENUM('pending', 'processing', 'completed', 'failed') DEFAULT 'pending',

    payload JSON, -- job-specific data
    result JSON, -- job output/response
    error_message TEXT,

    attempts INT DEFAULT 0,
    max_attempts INT DEFAULT 3,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    started_at TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,

    INDEX (status),
    INDEX (recommendation_id),
    INDEX (created_at)
);

CREATE TABLE marketing_logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    queue_id INT REFERENCES marketing_queue(id),
    action VARCHAR(100),
    details TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

**Cron Job:** `/public/crm/api/cron-runner.php` (called every 5 minutes)

```php
<?php
// Auth via IP whitelist or secret token

$queue = getDB()->query("
    SELECT * FROM marketing_queue
    WHERE status = 'pending'
    ORDER BY created_at ASC
    LIMIT 10
")->fetchAll();

foreach ($queue as $job) {
    try {
        processQueueJob($job);
    } catch (Exception $e) {
        logQueueError($job['id'], $e);
    }
}
?>
```

**Job Processors:**
- `processGenerateDraft($rec)` — Generate page draft
- `processSyncGSC()` — Pull fresh GSC data, rescore all recommendations
- `processUpdateStatus($rec)` — Update recommendation status based on performance
- `processMeasurePerformance($page)` — Compare page performance to recommendation target

---

### 6. Marketing Automation Admin UI

**New CRM Page: `/crm/marketing-automation_appstack.php`**

**Dashboard Tabs:**

1. **Recommendations** — Table with sorting/filtering
   - Columns: Query, Score, Status, Target, Actions
   - Action buttons: Assign, Draft, Preview, Edit, Publish, Ignore
   - Batch actions: Assign multiple, Generate drafts

2. **Page Drafts** — Pending page drafts
   - Columns: Query, Title, Created, Status, Actions
   - Action buttons: Preview, Edit, Publish, Reject

3. **Published Pages** — Pages from recommendations
   - Columns: Query, URL, Status, Views, Performance, Actions
   - Action buttons: View, Edit, Archive

4. **Performance** — Tracking over time
   - Chart: Recommendations published vs. traffic gained
   - Chart: Average SERP position before/after publishing
   - Table: Page performance (query, views, CTR, position change)

5. **Queue Status** — Background jobs log
   - Table: Job type, status, created time, result
   - Alert: Job failures with retry option

---

## Part C: Implementation Roadmap

### Phase 0: Database Migrations
- [ ] Create CMS core tables (cms_pages, cms_blocks, cms_menus, media_assets, revisions)
- [ ] Create marketing queue + logging tables
- [ ] Migrate existing business settings to media_assets
- [ ] Seed cms_block_types with built-in renderers

### Phase 1: Core CMS Renderer
- [ ] Implement `cms-render.php` entry point
- [ ] Create `.htaccess` rewrite rules (parallel routing)
- [ ] Build block renderer framework
- [ ] Create 5 layout templates

### Phase 2: Admin CMS UI
- [ ] Build `/crm/cms-pages_appstack.php` (CRUD pages)
- [ ] Build `/crm/cms-blocks_appstack.php` (block editor)
- [ ] Build `/crm/cms-menus_appstack.php` (menu manager)
- [ ] Build `/crm/cms-media_appstack.php` (media library)

### Phase 3: Service Landing Migration
- [ ] Create migration: Insert existing services into cms_pages + cms_blocks
- [ ] Test `/cms?page=strata-landscaping-maintenance`
- [ ] Publish service pages via CMS
- [ ] Remove static `/includes/service-data/` files

### Phase 4: Static Pages Migration
- [ ] Extract home.php, about.php, contact.php into blocks
- [ ] Create draft pages in CMS
- [ ] Compare rendering to originals
- [ ] Publish one by one (traffic light)

### Phase 5: Marketing Automation
- [ ] Build marketing queue + cron runner
- [ ] Implement `generatePageFromRecommendation()` processor
- [ ] Create `/crm/marketing-automation_appstack.php` admin UI
- [ ] Build recommendation action handlers (assign, draft, publish)
- [ ] Integrate GSC sync into queue

### Phase 6: Portfolio Integration
- [ ] Add `portfolio_showcase` block type
- [ ] Add portfolio embedding to service pages
- [ ] Create portfolio auto-tagging logic
- [ ] Dashboard showing portfolio ROI per service/location

### Phase 7: Measurement & Optimization
- [ ] Build performance tracking dashboard
- [ ] Implement page view tracking in CMS renderer
- [ ] Build recommendation status auto-update logic
- [ ] Create alerts for underperforming recommendations

---

## Part D: Security & Quality Guardrails

### Data Validation

1. **User Input:**
   - All block `config_json` validated against schema before save
   - HTML content sanitized with `htmlspecialchars()` for display, `strip_tags()` for text

2. **Menu URLs:**
   - Must start with `/` or be root-relative
   - No `javascript:` or `data:` protocols
   - Validate against whitelist of internal paths

3. **Media Upload:**
   - Mime type validation (whitelist: jpeg, png, gif, webp)
   - File size limits (10MB image, 100MB video)
   - Filename sanitization (remove special chars, hash with random token)
   - Store outside web root

4. **Page Slugs:**
   - Unique constraint
   - Must be URL-safe (alphanumeric + hyphen only)
   - Reserved slugs: admin, crm, loginAuth, app_config, cms, api

### SEO Guardrails

1. **Duplicate Prevention:**
   - Check for slug collisions before page creation
   - Check for meta description similarity (flag if >80% match to existing page)
   - Check for keyword cannibalization (alert if targeting same query as published page)

2. **Canonical URLs:**
   - Auto-generate from base + slug
   - Allow manual override
   - Always include `<link rel="canonical">` in head

3. **Draft Noindex:**
   - All draft pages get `<meta name="robots" content="noindex">`
   - Published pages omit noindex (unless manually set)

4. **Sitemap:**
   - Auto-generate from published cms_pages only
   - Include lastmod + priority per page_type

### Performance

1. **Caching:**
   - Cache rendered blocks (1 hour TTL) by `page_id + block_id`
   - Cache page structure (15 min TTL) by `page_id`
   - Cache media assets metadata (1 day TTL)
   - Invalidate on page/block update

2. **Media Optimization:**
   - Resize images on upload (2000px max width, 85% JPEG quality)
   - Generate responsive variants (1200px, 600px, 300px)
   - Lazy-load images in blocks

---

## Part E: Success Metrics

### CMS Adoption

1. **Page Coverage:** % of front-end pages managed by CMS (target: 100% by end of Phase 4)
2. **Block Reuse:** Average blocks per page (target: 5-8)
3. **Publishing Frequency:** Pages published per week (target: 2-4 during active marketing)

### Marketing Automation

1. **Recommendation Conversion:** % of new recommendations assigned + actioned (target: >60%)
2. **Page Generation Speed:** Time from recommendation to published page (target: <1 week)
3. **Traffic Impact:** % traffic from CMS-generated pages vs. organic baseline (target: >20% uplift by month 6)
4. **ROI:** Cost per lead from CMS-generated pages vs. other channels (target: <20% of ad spend)

---

## Part F: Future Extensibility

1. **Multi-Language Support:** Add `language` column to cms_pages, duplicate content per locale
2. **A/B Testing:** Store variant blocks, rotate via `visibility` rules
3. **Dynamic CTAs:** Auto-insert lead-gen forms based on device/referrer/time
4. **Personalization:** Render different block content based on user location/history
5. **Voice Search Optimization:** Auto-generate FAQ from recommendation queries
6. **Structured Data:** Auto-generate rich snippets (FAQPage, HowTo, LocalBusiness)
7. **Predictive Publishing:** ML model to predict which recommendations will succeed before publishing

---

## Appendix: Files to Create/Modify

### New Files (Phase 0-2)

```
/public/cms-render.php — Main CMS page renderer
/public/layouts/default.php — Default page layout
/public/layouts/homepage.php — Homepage layout
/public/layouts/service_landing.php — Service landing layout
/public/layouts/contact.php — Contact page layout
/public/layouts/portfolio.php — Portfolio layout

/public/crm/includes/blocks/hero.php
/public/crm/includes/blocks/feature_grid.php
/public/crm/includes/blocks/testimonials.php
/public/crm/includes/blocks/cta.php
/public/crm/includes/blocks/faq.php
/public/crm/includes/blocks/gallery.php
/public/crm/includes/blocks/service_cards.php
/public/crm/includes/blocks/rich_text.php
/public/crm/includes/blocks/portfolio_showcase.php
/public/crm/includes/blocks/custom.php

/public/crm/includes/cms-functions.php — CMS helper functions
/public/crm/includes/cms-renderer.php — Block rendering engine

/public/crm/api/cron-runner.php — Background job processor
/public/crm/api/queue-processor.php — Individual job handlers

/public/crm/cms-pages_appstack.php — CMS page CRUD admin
/public/crm/cms-blocks_appstack.php — Block editor admin
/public/crm/cms-menus_appstack.php — Menu manager admin
/public/crm/cms-media_appstack.php — Media library admin
/public/crm/marketing-automation_appstack.php — Marketing automation dashboard

/database/migrations/500_cms_core.sql — Core CMS tables
/database/migrations/501_cms_marketing.sql — Marketing automation tables
/database/migrations/502_cms_seed.sql — Seed cms_block_types
```

### Modified Files

```
/.htaccess — Add CMS routing rules
/public/includes/bootstrap.php — Add CMS constants
/public/crm/includes/appstack_sidebar.php — Add CMS nav items
/public/crm/includes/functions.php — Add CMS helpers
```

---

## Appendix: Example: Service Landing Page Migration

**Before (Static):**
```
/includes/service-data/strata-landscaping-maintenance.php
/services/strata-landscaping-maintenance.php
```

**After (CMS):**
```
INSERT INTO cms_pages (slug, title, page_type, layout_template, status, created_by)
VALUES ('strata-landscaping-maintenance', 'Strata Landscaping Maintenance', 'service_landing', 'service_landing', 'published', 1);

-- Insert hero block
INSERT INTO cms_blocks (page_id, block_type, position, config_json)
VALUES (1, 'hero', 1, '{"headline":"...","subheadline":"...","cta_text":"...","cta_url":"..."}');

-- Insert proof sections
INSERT INTO cms_blocks (page_id, block_type, position, config_json)
VALUES (1, 'feature_grid', 2, '{"heading":"Benefits","features":[...]}');

-- Route: /services/strata-landscaping-maintenance
-- Via .htaccess: RewriteRule ^services/([a-z0-9-]+)/?$ /cms-render.php?page=$1 [L]
```

---

**This architecture is:**
- ✅ Non-destructive (parallel routing during transition)
- ✅ Scalable (supports unlimited pages + blocks)
- ✅ SEO-safe (maintains canonical URLs, noindex for drafts)
- ✅ Admin-friendly (UI for pages, blocks, menus, media)
- ✅ Automation-ready (queue-based background jobs)
- ✅ Zero-dependency (no new npm packages, works on shared hosting)
