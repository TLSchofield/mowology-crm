-- Migration 500: Core CMS Tables
-- Created: 2026-02-09
-- Purpose: Foundation for database-driven CMS pages, blocks, menus, media, and revisions

-- ============================================================================
-- CMS PAGES
-- ============================================================================

CREATE TABLE IF NOT EXISTS `cms_pages` (
  `id` int NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `slug` varchar(191) COLLATE utf8mb4_unicode_ci UNIQUE NOT NULL COMMENT 'URL slug (e.g., home, about, services)',
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Display title',

  `meta_title` varchar(255) COLLATE utf8mb4_unicode_ci COMMENT 'SEO title (60 chars)',
  `meta_description` varchar(500) COLLATE utf8mb4_unicode_ci COMMENT 'SEO meta description (160 chars)',
  `meta_keywords` varchar(255) COLLATE utf8mb4_unicode_ci COMMENT 'SEO keywords (optional)',
  `canonical_url` varchar(500) COLLATE utf8mb4_unicode_ci COMMENT 'Canonical URL (auto-generated or manual)',
  `og_image_path` varchar(500) COLLATE utf8mb4_unicode_ci COMMENT 'OpenGraph image path',

  `page_type` ENUM('home', 'about', 'services', 'service_landing', 'portfolio', 'contact', 'custom', 'landing')
    COLLATE utf8mb4_unicode_ci DEFAULT 'custom' COMMENT 'Page category for templates',
  `layout_template` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT 'default'
    COMMENT 'Layout file to use: default|service_landing|contact|homepage|portfolio',

  `status` ENUM('draft', 'published', 'archived') COLLATE utf8mb4_unicode_ci DEFAULT 'draft' COMMENT 'Publication status',
  `publish_at` datetime COMMENT 'Scheduled publish date (future publishing)',
  `unpublish_at` datetime COMMENT 'Scheduled unpublish date (auto-archive)',
  `noindex` tinyint(1) DEFAULT 0 COMMENT 'Add noindex to robots meta tag',

  `seo_score` int COMMENT 'SEO readability + keyword score (0-100)',
  `view_count` int DEFAULT 0 COMMENT 'Page view tracking',

  `created_by` int NOT NULL COMMENT 'User ID who created page',
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `updated_by` int COMMENT 'User ID who last updated page',
  `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `published_by` int COMMENT 'User ID who published page',
  `published_at` timestamp NULL COMMENT 'Publication timestamp',

  KEY `idx_slug` (`slug`),
  KEY `idx_status` (`status`),
  KEY `idx_page_type` (`page_type`),
  KEY `idx_layout_template` (`layout_template`),
  KEY `idx_created_at` (`created_at`),
  FULLTEXT `ftx_title_description` (`title`, `meta_description`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT 'CMS managed pages with metadata and publication status';

-- ============================================================================
-- CMS BLOCK TYPES (Registry)
-- ============================================================================

CREATE TABLE IF NOT EXISTS `cms_block_types` (
  `id` int NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `block_type` varchar(50) COLLATE utf8mb4_unicode_ci UNIQUE NOT NULL COMMENT 'Unique block type code',
  `label` varchar(100) COLLATE utf8mb4_unicode_ci COMMENT 'Display label (e.g., "Hero Banner")',
  `description` text COLLATE utf8mb4_unicode_ci COMMENT 'Description for admin UI',

  `schema_json` json COMMENT 'JSON schema describing expected config_json structure',
  `preview_image_path` varchar(255) COLLATE utf8mb4_unicode_ci COMMENT 'Thumbnail for block picker',
  `renderer_path` varchar(255) COLLATE utf8mb4_unicode_ci COMMENT 'Path to PHP renderer (e.g., /crm/includes/blocks/hero.php)',

  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  KEY `idx_is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT 'Registry of available block types and their configurations';

-- ============================================================================
-- CMS BLOCKS (Page Content Blocks)
-- ============================================================================

CREATE TABLE IF NOT EXISTS `cms_blocks` (
  `id` int NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `page_id` int NOT NULL COMMENT 'Parent page',
  `block_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Type of block',
  `position` int NOT NULL DEFAULT 0 COMMENT 'Render order (0=first)',

  `config_json` json NOT NULL COMMENT 'Block-specific configuration (validated against type schema)',
  `content_json` json COMMENT 'Optional dynamic content (e.g., database results)',

  `visibility_json` json COMMENT 'Optional visibility rules: {devices: [mobile, desktop], roles: [all, admin]}',

  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  KEY `idx_page_id` (`page_id`),
  KEY `idx_block_type` (`block_type`),
  KEY `idx_position` (`position`),
  CONSTRAINT `fk_cms_blocks_page_id` FOREIGN KEY (`page_id`) REFERENCES `cms_pages` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT 'Content blocks that compose pages';

-- ============================================================================
-- CMS MENUS
-- ============================================================================

CREATE TABLE IF NOT EXISTS `cms_menus` (
  `id` int NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `menu_key` varchar(50) COLLATE utf8mb4_unicode_ci UNIQUE NOT NULL COMMENT 'Machine name (e.g., header_nav, footer_nav)',
  `label` varchar(100) COLLATE utf8mb4_unicode_ci COMMENT 'Display label',
  `description` text COLLATE utf8mb4_unicode_ci COMMENT 'Description for admin',

  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  KEY `idx_menu_key` (`menu_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT 'Menu definitions (header, footer, etc.)';

CREATE TABLE IF NOT EXISTS `cms_menu_items` (
  `id` int NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `menu_id` int NOT NULL COMMENT 'Parent menu',
  `parent_id` int COMMENT 'Parent menu item (for nested menus)',

  `label` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Display text',
  `url` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Link URL (relative or absolute)',
  `title_attr` varchar(255) COLLATE utf8mb4_unicode_ci COMMENT 'Hover tooltip text',

  `target` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT '_self' COMMENT '_self or _blank',
  `rel_attr` varchar(100) COLLATE utf8mb4_unicode_ci COMMENT 'HTML rel attribute (nofollow, noopener, etc.)',

  `is_active` tinyint(1) DEFAULT 1,
  `position` int NOT NULL DEFAULT 0 COMMENT 'Sort order within menu',

  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  KEY `idx_menu_id` (`menu_id`),
  KEY `idx_parent_id` (`parent_id`),
  KEY `idx_position` (`position`),
  KEY `idx_is_active` (`is_active`),
  CONSTRAINT `fk_cms_menu_items_menu_id` FOREIGN KEY (`menu_id`) REFERENCES `cms_menus` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cms_menu_items_parent_id` FOREIGN KEY (`parent_id`) REFERENCES `cms_menu_items` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT 'Navigation menu items (hierarchical)';

-- ============================================================================
-- MEDIA ASSETS (Library)
-- ============================================================================

CREATE TABLE IF NOT EXISTS `media_assets` (
  `id` int NOT NULL AUTO_INCREMENT PRIMARY KEY,

  `original_filename` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Original filename on upload',
  `stored_filename` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Sanitized, hashed filename',
  `file_path` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Relative path: /uploads/2026/02/abc123.jpg',

  `file_type` ENUM('image', 'video', 'document', 'archive') COLLATE utf8mb4_unicode_ci DEFAULT 'image',
  `mime_type` varchar(50) COLLATE utf8mb4_unicode_ci COMMENT 'e.g., image/jpeg',
  `file_size` int COMMENT 'Bytes',

  `image_width` int COMMENT 'Pixel width (images only)',
  `image_height` int COMMENT 'Pixel height (images only)',
  `aspect_ratio` varchar(20) COLLATE utf8mb4_unicode_ci COMMENT 'e.g., 16:9, 4:3, 1:1',

  `alt_text` varchar(255) COLLATE utf8mb4_unicode_ci COMMENT 'SEO alt text',
  `caption` text COLLATE utf8mb4_unicode_ci COMMENT 'Caption/description',
  `description` text COLLATE utf8mb4_unicode_ci COMMENT 'Detailed description',

  `tags_json` json COMMENT 'Array of tags: ["service/strata", "location/vancouver", "season/spring"]',
  `is_favorite` tinyint(1) DEFAULT 0 COMMENT 'Mark as favorite for quick access',

  `usage_count` int DEFAULT 0 COMMENT 'Tracks how many blocks reference this asset',

  `created_by` int NOT NULL COMMENT 'User ID who uploaded',
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  KEY `idx_is_favorite` (`is_favorite`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_created_by` (`created_by`),
  KEY `idx_file_type` (`file_type`),
  FULLTEXT `ftx_alt_caption_description` (`alt_text`, `caption`, `description`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT 'Media asset library with metadata and usage tracking';

-- ============================================================================
-- CMS PAGE REVISIONS (Version History)
-- ============================================================================

CREATE TABLE IF NOT EXISTS `cms_page_revisions` (
  `id` int NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `page_id` int NOT NULL COMMENT 'Parent page',

  `slug` varchar(191) COLLATE utf8mb4_unicode_ci COMMENT 'Slug at time of revision',
  `title` varchar(255) COLLATE utf8mb4_unicode_ci COMMENT 'Title at time of revision',
  `meta_title` varchar(255) COLLATE utf8mb4_unicode_ci,
  `meta_description` varchar(500) COLLATE utf8mb4_unicode_ci,

  `blocks_snapshot` json COMMENT 'Serialized blocks state at time of revision',

  `revision_type` ENUM('draft', 'published', 'restore') COLLATE utf8mb4_unicode_ci DEFAULT 'draft' COMMENT 'Type of revision',
  `revision_message` varchar(500) COLLATE utf8mb4_unicode_ci COMMENT 'Admin note about this revision',

  `created_by` int NOT NULL COMMENT 'User who created revision',
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,

  KEY `idx_page_id` (`page_id`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_revision_type` (`revision_type`),
  CONSTRAINT `fk_cms_page_revisions_page_id` FOREIGN KEY (`page_id`) REFERENCES `cms_pages` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT 'Page revision history for rollback and audit';

-- ============================================================================
-- SEED DEFAULT BLOCK TYPES
-- ============================================================================

INSERT IGNORE INTO `cms_block_types`
(`block_type`, `label`, `description`, `renderer_path`, `is_active`)
VALUES
  ('hero', 'Hero Banner', 'Large header with headline, subheading, image, and CTA button', '/crm/includes/blocks/hero.php', 1),
  ('feature_grid', 'Feature Grid', 'Grid of feature cards (3 or 4 columns) with icons and descriptions', '/crm/includes/blocks/feature_grid.php', 1),
  ('testimonials', 'Testimonials', 'Carousel or grid of customer testimonials with quotes and photos', '/crm/includes/blocks/testimonials.php', 1),
  ('cta', 'Call-to-Action', 'Prominent section with heading, subheading, and primary/secondary CTAs', '/crm/includes/blocks/cta.php', 1),
  ('faq', 'FAQ Accordion', 'Expandable FAQ section with questions and answers', '/crm/includes/blocks/faq.php', 1),
  ('gallery', 'Gallery/Carousel', 'Image gallery (grid or carousel/slider) with optional captions', '/crm/includes/blocks/gallery.php', 1),
  ('service_cards', 'Service Cards', 'List of services with titles, icons, descriptions, and links', '/crm/includes/blocks/service_cards.php', 1),
  ('rich_text', 'Rich Text', 'WYSIWYG content block with paragraphs, headings, lists, and links', '/crm/includes/blocks/rich_text.php', 1),
  ('portfolio_showcase', 'Portfolio Showcase', 'Embedded portfolio project cards with filtering by service/location/tags', '/crm/includes/blocks/portfolio_showcase.php', 1),
  ('custom', 'Custom HTML', 'Raw HTML/JavaScript block (admin-only for safety)', '/crm/includes/blocks/custom.php', 1)
ON DUPLICATE KEY UPDATE `is_active`=1;

-- ============================================================================
-- SEED DEFAULT MENUS
-- ============================================================================

INSERT IGNORE INTO `cms_menus`
(`menu_key`, `label`, `description`)
VALUES
  ('header_nav', 'Header Navigation', 'Main navigation bar in site header'),
  ('footer_nav', 'Footer Navigation', 'Navigation links in site footer'),
  ('sidebar_nav', 'CRM Sidebar Navigation', 'Admin sidebar navigation (may be managed separately)')
ON DUPLICATE KEY UPDATE `menu_key`=`menu_key`;

-- ============================================================================
-- Comments / Documentation
-- ============================================================================

/*
CMS Core Tables - Design Notes:

1. cms_pages
   - slug: UNIQUE, URL-safe (lowercase alphanumeric + hyphens)
   - status: draft (editing) | published (live) | archived (hidden)
   - publish_at/unpublish_at: For scheduled publishing (future feature)
   - noindex: Draft pages get <meta name="robots" content="noindex">
   - view_count: Simple page analytics (can be queried for traffic dashboard)

2. cms_block_types
   - Registry of available block types
   - Extensible: Add new types by inserting row
   - Each type has a renderer PHP file
   - schema_json: Describes expected config_json structure (admin form generation)

3. cms_blocks
   - Each block is a row in a page
   - position: Render order (0=first, 1=second, etc.)
   - config_json: Block-specific settings (hero image, feature items, etc.)
   - content_json: Optional runtime content (e.g., dynamic query results)
   - visibility_json: Conditional rendering (responsive, role-based, etc.)

4. cms_menus
   - Decoupled from pages for flexibility
   - Support multiple menus (header, footer, sidebar)

5. cms_menu_items
   - Hierarchical (parent_id for nested menus)
   - Supports external links, internal pages, or custom URLs
   - rel_attr: For SEO hints (nofollow for affiliates, noopener for external)

6. media_assets
   - Centralized media library
   - tags_json: Flexible tagging for queries (e.g., "service/strata", "location/vancouver")
   - usage_count: Tracks references (useful for cleanup/deletion)
   - FULLTEXT index on alt_text + caption + description for media search

7. cms_page_revisions
   - Audit trail for pages
   - blocks_snapshot: JSON of all blocks at time of revision (enables rollback)
   - revision_type: draft/published/restore (shows intent)

Migration Strategy:
- Phase 1: Service landing pages → CMS pages + blocks
- Phase 2: Static pages (home, about, contact) → CMS pages + blocks
- Phase 3: Portfolio integration → portfolio_showcase blocks
- Phase 4: Marketing automation → Linked to seo_page_drafts table (migration 501)
*/
