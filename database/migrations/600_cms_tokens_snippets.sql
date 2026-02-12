-- Migration 600: CMS Token Engine + Content Snippets
-- Created: 2026-02-12
-- Purpose: Token replacement system for dynamic content, reusable copy library

-- ============================================================================
-- CONTENT SNIPPETS (Reusable copy pools with key/variant system)
-- ============================================================================

CREATE TABLE IF NOT EXISTS `content_snippets` (
  `id` int NOT NULL AUTO_INCREMENT PRIMARY KEY,

  `snippet_key` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL
    COMMENT 'Dot-notation key: hero.residential_lawn_care.v1',
  `variant` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'default'
    COMMENT 'Copy variant: residential, strata, commercial, default',
  `block_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL
    COMMENT 'Target block type: hero, feature_grid, cta, faq, etc.',

  `content_json` text COLLATE utf8mb4_unicode_ci NOT NULL
    COMMENT 'Block config JSON with {{TOKEN}} placeholders',
  `notes` text COLLATE utf8mb4_unicode_ci
    COMMENT 'Internal notes about this snippet (tone, intent, A/B test notes)',

  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY `uk_snippet_key` (`snippet_key`),
  KEY `idx_variant` (`variant`),
  KEY `idx_block_type` (`block_type`),
  KEY `idx_is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT 'Reusable copy templates for block content with token placeholders';

-- ============================================================================
-- TOKEN DICTIONARY (Registry of available tokens with defaults)
-- ============================================================================

CREATE TABLE IF NOT EXISTS `token_dictionary` (
  `id` int NOT NULL AUTO_INCREMENT PRIMARY KEY,

  `token` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL
    COMMENT 'Token name without braces: CITY, SERVICE, BRAND',
  `default_value` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT ''
    COMMENT 'Default value when no page override exists',
  `description` varchar(500) COLLATE utf8mb4_unicode_ci
    COMMENT 'What this token represents',
  `category` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT 'custom'
    COMMENT 'Category: brand, location, service, custom',
  `example_value` varchar(255) COLLATE utf8mb4_unicode_ci
    COMMENT 'Example for admin UI: Vancouver, Lawn Mowing, etc.',

  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY `uk_token` (`token`),
  KEY `idx_category` (`category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT 'Registry of available {{TOKEN}} placeholders with default values';

-- ============================================================================
-- PAGE TOKENS (Per-page token overrides)
-- ============================================================================

CREATE TABLE IF NOT EXISTS `page_tokens` (
  `id` int NOT NULL AUTO_INCREMENT PRIMARY KEY,

  `page_id` int NOT NULL COMMENT 'Reference to cms_pages',
  `token` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL
    COMMENT 'Token name: CITY, SERVICE, etc.',
  `value` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL
    COMMENT 'Override value for this page',

  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY `uk_page_token` (`page_id`, `token`),
  KEY `idx_page_id` (`page_id`),
  CONSTRAINT `fk_page_tokens_page_id` FOREIGN KEY (`page_id`) REFERENCES `cms_pages` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT 'Per-page token value overrides';

-- ============================================================================
-- SEED DEFAULT TOKENS
-- ============================================================================

INSERT IGNORE INTO `token_dictionary`
(`token`, `default_value`, `description`, `category`, `example_value`)
VALUES
  ('BRAND', 'Mowology', 'Company brand name', 'brand', 'Mowology'),
  ('PHONE', '778-846-9273', 'Phone number (display format)', 'brand', '778-846-9273'),
  ('PHONE_TEL', '7788469273', 'Phone number (tel: link format)', 'brand', '7788469273'),
  ('EMAIL', 'office@mowology.ca', 'Contact email address', 'brand', 'office@mowology.ca'),
  ('YEAR', '2026', 'Current year', 'brand', '2026'),
  ('TAGLINE', 'A Higher Degree of Service', 'Brand tagline', 'brand', 'A Higher Degree of Service'),
  ('CITY', 'Vancouver', 'Primary city name', 'location', 'Vancouver'),
  ('AREA', 'Greater Vancouver', 'Service area name', 'location', 'Greater Vancouver'),
  ('NEIGHBOURHOOD', '', 'Specific neighbourhood', 'location', 'Kitsilano'),
  ('PROVINCE', 'BC', 'Province/state abbreviation', 'location', 'BC'),
  ('SERVICE', 'Landscaping', 'Primary service name', 'service', 'Lawn Care'),
  ('SERVICE_SLUG', 'landscaping', 'URL-safe service name', 'service', 'lawn-care'),
  ('PRIMARY_KEYWORD', 'professional landscaping', 'SEO primary keyword', 'service', 'residential lawn care'),
  ('SECONDARY_KEYWORD', 'landscape maintenance', 'SEO secondary keyword', 'service', 'lawn mowing service'),
  ('CTA_URL', '/quote', 'Default CTA link destination', 'brand', '/quote?service=general'),
  ('CTA_TEXT', 'Get Your Free Quote', 'Default CTA button text', 'brand', 'Get Your Free Quote'),
  ('TRUST_STATEMENT', 'Photo-verified results on every visit', 'Trust/proof statement', 'brand', 'Photo-verified results on every visit');
