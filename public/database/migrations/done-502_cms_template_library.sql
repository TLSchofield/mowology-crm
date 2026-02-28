-- Migration 502: CMS Template Library System
-- Created: 2026-02-09
-- Purpose: Store, version, and manage reusable page templates and block templates with full adjustability

-- ============================================================================
-- PAGE TEMPLATES (Blueprint for creating new pages)
-- ============================================================================

CREATE TABLE IF NOT EXISTS `cms_page_templates` (
  `id` int NOT NULL AUTO_INCREMENT PRIMARY KEY,

  `template_key` varchar(100) COLLATE utf8mb4_unicode_ci UNIQUE NOT NULL COMMENT 'Machine name (e.g., service_landing_v1)',
  `label` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Display name (e.g., "Service Landing Page")',
  `description` text COLLATE utf8mb4_unicode_ci COMMENT 'What this template is for',

  `layout_template` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT 'default' COMMENT 'Layout file to use',
  `page_type` ENUM('home', 'about', 'services', 'service_landing', 'portfolio', 'contact', 'custom', 'landing')
    COLLATE utf8mb4_unicode_ci DEFAULT 'custom' COMMENT 'Page category',

  `category` varchar(50) COLLATE utf8mb4_unicode_ci COMMENT 'Template category: landing|service|product|location|blog',
  `use_case` text COLLATE utf8mb4_unicode_ci COMMENT 'Intended use: "New service landing pages", etc.',

  `blocks_config_json` json NOT NULL COMMENT 'Array of block templates: [{type, position, config, editable_fields}]',
  `page_metadata_defaults_json` json COMMENT 'Default page values: {title, meta_description, og_image_path}',

  `version` int DEFAULT 1 COMMENT 'Template version number',
  `is_active` tinyint(1) DEFAULT 1,
  `is_locked` tinyint(1) DEFAULT 0 COMMENT 'Locked templates cannot be edited (system templates)',

  `preview_image_path` varchar(255) COLLATE utf8mb4_unicode_ci COMMENT 'Screenshot of template',

  `usage_count` int DEFAULT 0 COMMENT 'How many pages use this template',
  `last_used_at` timestamp NULL COMMENT 'Last time a page was created from this template',

  `created_by` int NOT NULL COMMENT 'User who created template',
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `updated_by` int COMMENT 'User who last updated template',
  `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  KEY `idx_template_key` (`template_key`),
  KEY `idx_category` (`category`),
  KEY `idx_is_active` (`is_active`),
  KEY `idx_usage_count` (`usage_count`),
  KEY `idx_last_used_at` (`last_used_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT 'Page templates for rapid page creation';

-- ============================================================================
-- PAGE TEMPLATE VERSIONS (History & Rollback)
-- ============================================================================

CREATE TABLE IF NOT EXISTS `cms_page_template_versions` (
  `id` int NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `page_template_id` int NOT NULL REFERENCES cms_page_templates(id) ON DELETE CASCADE,

  `version` int NOT NULL COMMENT 'Version number (1, 2, 3, etc.)',
  `blocks_config_json` json NOT NULL COMMENT 'Block configuration snapshot',
  `page_metadata_defaults_json` json,

  `change_summary` varchar(255) COLLATE utf8mb4_unicode_ci COMMENT 'What changed in this version',
  `changelog` text COLLATE utf8mb4_unicode_ci COMMENT 'Detailed changelog',

  `created_by` int NOT NULL,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY `uk_template_version` (`page_template_id`, `version`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `fk_page_template_versions_template_id` FOREIGN KEY (`page_template_id`) REFERENCES `cms_page_templates` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT 'Version history for page templates';

-- ============================================================================
-- BLOCK TEMPLATES (Reusable block configurations)
-- ============================================================================

CREATE TABLE IF NOT EXISTS `cms_block_templates` (
  `id` int NOT NULL AUTO_INCREMENT PRIMARY KEY,

  `block_template_key` varchar(100) COLLATE utf8mb4_unicode_ci UNIQUE NOT NULL COMMENT 'Machine name (e.g., hero_service_landing)',
  `label` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Display name',
  `description` text COLLATE utf8mb4_unicode_ci COMMENT 'What this block template does',

  `block_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Block type (hero, feature_grid, etc.)',
  `category` varchar(50) COLLATE utf8mb4_unicode_ci COMMENT 'Category: hero|proof|cta|social|pricing',

  `default_config_json` json NOT NULL COMMENT 'Default configuration (can be overridden)',
  `editable_fields_json` json COMMENT 'Which fields can be edited: ["headline", "cta_url", "images"]',
  `locked_fields_json` json COMMENT 'Which fields are locked from editing: ["colors", "layout"]',

  `version` int DEFAULT 1,
  `is_active` tinyint(1) DEFAULT 1,
  `is_locked` tinyint(1) DEFAULT 0 COMMENT 'System templates (locked)',

  `preview_image_path` varchar(255) COLLATE utf8mb4_unicode_ci,

  `usage_count` int DEFAULT 0 COMMENT 'How many pages use this block template',
  `performance_data_json` json COMMENT 'Aggregate performance: {avg_ctr, avg_conversion_rate}',

  `created_by` int NOT NULL,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `updated_by` int COMMENT 'User who last updated',
  `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  KEY `idx_block_type` (`block_type`),
  KEY `idx_category` (`category`),
  KEY `idx_is_active` (`is_active`),
  KEY `idx_usage_count` (`usage_count`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT 'Reusable block templates with adjustable configurations';

-- ============================================================================
-- BLOCK TEMPLATE VERSIONS (History & Rollback)
-- ============================================================================

CREATE TABLE IF NOT EXISTS `cms_block_template_versions` (
  `id` int NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `block_template_id` int NOT NULL REFERENCES cms_block_templates(id) ON DELETE CASCADE,

  `version` int NOT NULL,
  `default_config_json` json NOT NULL,
  `editable_fields_json` json,
  `locked_fields_json` json,

  `change_summary` varchar(255) COLLATE utf8mb4_unicode_ci,
  `changelog` text COLLATE utf8mb4_unicode_ci,

  `created_by` int NOT NULL,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY `uk_block_template_version` (`block_template_id`, `version`),
  CONSTRAINT `fk_block_template_versions_template_id` FOREIGN KEY (`block_template_id`) REFERENCES `cms_block_templates` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT 'Version history for block templates';

-- ============================================================================
-- PAGES CREATED FROM TEMPLATES (Audit trail & template tracking)
-- ============================================================================

CREATE TABLE IF NOT EXISTS `cms_pages_template_audit` (
  `id` int NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `page_id` int NOT NULL COMMENT 'Reference to cms_pages',
  `page_template_id` int REFERENCES cms_page_templates(id),
  `block_template_ids_json` json COMMENT 'Array of block template IDs used',

  `template_version_used` int COMMENT 'What version of page template was used',
  `created_from_template` tinyint(1) DEFAULT 1,

  `snapshot_of_template_config` json COMMENT 'Snapshot of template config at time of page creation',

  `created_by` int NOT NULL,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,

  KEY `idx_page_id` (`page_id`),
  KEY `idx_page_template_id` (`page_template_id`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT 'Audit trail: tracks which template was used to create each page';

-- ============================================================================
-- TEMPLATE GROUPS (Organize templates into collections)
-- ============================================================================

CREATE TABLE IF NOT EXISTS `cms_template_groups` (
  `id` int NOT NULL AUTO_INCREMENT PRIMARY KEY,

  `group_key` varchar(100) COLLATE utf8mb4_unicode_ci UNIQUE NOT NULL COMMENT 'Machine name (e.g., service_landing_collection)',
  `label` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Display name (e.g., "Service Landing Suite")',
  `description` text COLLATE utf8mb4_unicode_ci,

  `group_type` ENUM('page_templates', 'block_templates', 'mixed') COLLATE utf8mb4_unicode_ci DEFAULT 'mixed',
  `purpose` varchar(255) COLLATE utf8mb4_unicode_ci COMMENT 'What this group is for: "New service onboarding"',

  `is_featured` tinyint(1) DEFAULT 0 COMMENT 'Show in quick-access area',
  `is_active` tinyint(1) DEFAULT 1,

  `preview_image_path` varchar(255) COLLATE utf8mb4_unicode_ci,
  `documentation_url` varchar(500) COLLATE utf8mb4_unicode_ci COMMENT 'Link to guide on using this template group',

  `created_by` int NOT NULL,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  KEY `idx_is_featured` (`is_featured`),
  KEY `idx_is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT 'Collections of related templates';

-- ============================================================================
-- TEMPLATE GROUP MEMBERSHIP
-- ============================================================================

CREATE TABLE IF NOT EXISTS `cms_template_group_members` (
  `id` int NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `group_id` int NOT NULL REFERENCES cms_template_groups(id) ON DELETE CASCADE,

  `page_template_id` int COMMENT 'Reference to cms_page_templates (nullable)',
  `block_template_id` int COMMENT 'Reference to cms_block_templates (nullable)',

  `position` int DEFAULT 0 COMMENT 'Display order within group',

  KEY `idx_group_id` (`group_id`),
  KEY `idx_page_template_id` (`page_template_id`),
  KEY `idx_block_template_id` (`block_template_id`),
  KEY `idx_position` (`position`),
  CONSTRAINT `fk_template_group_members_group_id` FOREIGN KEY (`group_id`) REFERENCES `cms_template_groups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT 'Many-to-many: templates can belong to multiple groups';

-- ============================================================================
-- TEMPLATE CUSTOMIZATION PRESETS (Save common variations)
-- ============================================================================

CREATE TABLE IF NOT EXISTS `cms_template_presets` (
  `id` int NOT NULL AUTO_INCREMENT PRIMARY KEY,

  `preset_key` varchar(100) COLLATE utf8mb4_unicode_ci UNIQUE NOT NULL COMMENT 'Machine name',
  `label` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Display name (e.g., "Service Landing - Strata Maintenance")',
  `description` text COLLATE utf8mb4_unicode_ci,

  `base_page_template_id` int COMMENT 'Template this preset is based on',
  `base_block_template_id` int COMMENT 'Or: template this preset customizes',

  `customization_config_json` json NOT NULL COMMENT 'Overrides to base template: {field: new_value}',
  `applied_to_count` int DEFAULT 0 COMMENT 'How many pages use this preset',

  `created_by` int NOT NULL,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `updated_by` int,
  `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  KEY `idx_base_page_template_id` (`base_page_template_id`),
  KEY `idx_base_block_template_id` (`base_block_template_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT 'Save and reuse common template variations/customizations';

-- ============================================================================
-- TEMPLATE ANALYTICS (Performance tracking)
-- ============================================================================

CREATE TABLE IF NOT EXISTS `cms_template_performance` (
  `id` int NOT NULL AUTO_INCREMENT PRIMARY KEY,

  `page_template_id` int COMMENT 'Which page template',
  `block_template_id` int COMMENT 'Or which block template',

  `date` date NOT NULL,

  `pages_created` int DEFAULT 0 COMMENT 'Pages created using this template on date',
  `pages_published` int DEFAULT 0 COMMENT 'Pages published on this date',

  `avg_page_views` float COMMENT 'Average views per page created from this template',
  `avg_conversion_rate` float COMMENT 'Average conversion rate',
  `avg_time_on_page` float COMMENT 'Average time on page (seconds)',

  `avg_ctr` float COMMENT 'For blocks: average click-through rate',
  `avg_engagement` float COMMENT 'For blocks: engagement score (0-100)',

  `notes` text COLLATE utf8mb4_unicode_ci,

  UNIQUE KEY `uk_template_date` (
    `page_template_id`, `block_template_id`, `date`
  ),
  KEY `idx_date` (`date`),
  KEY `idx_page_template_id` (`page_template_id`),
  KEY `idx_block_template_id` (`block_template_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT 'Daily performance metrics for templates';

-- ============================================================================
-- SEED DEFAULT PAGE TEMPLATES
-- ============================================================================

INSERT IGNORE INTO `cms_page_templates`
(`template_key`, `label`, `description`, `layout_template`, `page_type`, `category`, `use_case`, `blocks_config_json`, `page_metadata_defaults_json`, `is_locked`, `created_by`)
VALUES
  (
    'service_landing_v1',
    'Service Landing Page',
    'Template for creating new service landing pages with hero, proof sections, FAQ, and CTA',
    'service_landing',
    'service_landing',
    'landing',
    'Create new service landing pages (e.g., Strata Maintenance, Hedge Trimming)',
    '[
      {"type": "hero", "position": 0, "label": "Hero Banner", "editable_fields": ["headline", "subheadline", "cta_text", "cta_url", "media_id"]},
      {"type": "feature_grid", "position": 1, "label": "Benefits Section", "editable_fields": ["heading", "features"]},
      {"type": "gallery", "position": 2, "label": "Portfolio Showcase", "editable_fields": ["media_ids"]},
      {"type": "faq", "position": 3, "label": "FAQ Section", "editable_fields": ["heading", "faqs"]},
      {"type": "cta", "position": 4, "label": "Final CTA", "editable_fields": ["heading", "primary_url", "primary_text"]}
    ]',
    '{"meta_title": "Service | Mowology Landscaping", "meta_description": "Professional landscaping service in Vancouver", "og_image_path": "/assets/images/og-service.jpg"}',
    0,
    1
  ),
  (
    'location_landing_v1',
    'Location Landing Page',
    'Template for location-based pages (cities, neighbourhoods, postcodes)',
    'default',
    'landing',
    'landing',
    'Create location pages: "Landscaping in Vancouver", "Services in Kitsilano"',
    '[
      {"type": "hero", "position": 0, "label": "Hero", "editable_fields": ["headline", "subheadline", "cta_url"]},
      {"type": "feature_grid", "position": 1, "label": "Why Choose Us In This Area", "editable_fields": ["heading", "features"]},
      {"type": "portfolio_showcase", "position": 2, "label": "Projects In [Location]", "editable_fields": ["filters"]},
      {"type": "testimonials", "position": 3, "label": "Local Testimonials", "editable_fields": ["testimonials"]},
      {"type": "cta", "position": 4, "label": "Get Local Quote", "editable_fields": ["heading", "primary_url"]}
    ]',
    '{"meta_title": "[Location] Landscaping Services | Mowology", "meta_description": "Professional landscaping services in [Location]"}',
    0,
    1
  ),
  (
    'homepage_v1',
    'Homepage',
    'Main homepage template with hero, services overview, portfolio, testimonials',
    'homepage',
    'home',
    'homepage',
    'Homepage for main website',
    '[
      {"type": "hero", "position": 0, "label": "Hero Banner", "editable_fields": ["headline", "cta_text"]},
      {"type": "feature_grid", "position": 1, "label": "Services Overview", "editable_fields": []},
      {"type": "portfolio_showcase", "position": 2, "label": "Featured Projects", "editable_fields": []},
      {"type": "testimonials", "position": 3, "label": "Client Testimonials", "editable_fields": []},
      {"type": "cta", "position": 4, "label": "Call to Action", "editable_fields": ["heading", "primary_url"]}
    ]',
    '{"meta_title": "Mowology | Professional Landscaping", "meta_description": "Full-service landscaping company in Vancouver"}',
    1,
    1
  )
ON DUPLICATE KEY UPDATE `template_key`=`template_key`;

-- ============================================================================
-- SEED DEFAULT BLOCK TEMPLATES
-- ============================================================================

INSERT IGNORE INTO `cms_block_templates`
(`block_template_key`, `label`, `description`, `block_type`, `category`, `default_config_json`, `editable_fields_json`, `is_locked`, `created_by`)
VALUES
  (
    'hero_service_landing',
    'Service Hero Banner',
    'Hero banner for service landing pages',
    'hero',
    'hero',
    '{"headline": "Our Service", "subheadline": "Premium landscaping service", "cta_text": "Get Started", "cta_url": "/quote"}',
    '["headline", "subheadline", "cta_text", "cta_url", "media_id"]',
    0,
    1
  ),
  (
    'benefits_features',
    'Benefits Feature Grid',
    '3-column feature grid highlighting benefits',
    'feature_grid',
    'proof',
    '{"heading": "Why Choose Us", "layout": 3, "features": [{"icon": "check", "title": "Benefit 1", "description": "..."}, {"icon": "check", "title": "Benefit 2", "description": "..."}, {"icon": "check", "title": "Benefit 3", "description": "..."}]}',
    '["heading", "features"]',
    0,
    1
  ),
  (
    'portfolio_grid_6',
    'Portfolio Gallery (6 items)',
    'Grid of 6 portfolio project images',
    'gallery',
    'proof',
    '{"media_ids": [], "layout": "grid"}',
    '["media_ids"]',
    0,
    1
  ),
  (
    'faq_standard',
    'Standard FAQ Accordion',
    'Expandable FAQ section',
    'faq',
    'proof',
    '{"heading": "Frequently Asked Questions", "faqs": []}',
    '["heading", "faqs"]',
    0,
    1
  ),
  (
    'cta_final_call',
    'Final Call-to-Action',
    'Bottom CTA section',
    'cta',
    'cta',
    '{"heading": "Ready to transform your landscape?", "subheading": "Let us help you create an amazing outdoor space", "primary_text": "Get Free Quote", "primary_url": "/quote"}',
    '["heading", "subheading", "primary_text", "primary_url"]',
    0,
    1
  )
ON DUPLICATE KEY UPDATE `block_template_key`=`block_template_key`;

-- ============================================================================
-- COMMENTS / DOCUMENTATION
-- ============================================================================

/*
CMS Template Library - Design Notes:

1. cms_page_templates
   - Stores complete page templates (blueprint for pages)
   - blocks_config_json: Array of block templates with positions
   - page_metadata_defaults_json: Default page metadata values
   - version: Track template versions (when template changes)
   - usage_count: How many pages created from this template
   - is_locked: System templates cannot be edited

2. cms_page_template_versions
   - Immutable snapshots of template configurations
   - Enables rollback if template is changed
   - Tracks what changed between versions (changelog)

3. cms_block_templates
   - Reusable block configurations
   - editable_fields_json: Specify which fields users can customize
   - locked_fields_json: Fields that cannot be changed
   - performance_data_json: Aggregate metrics (CTR, conversion rate)
   - version: Track template evolution

4. cms_template_groups
   - Organize templates into collections
   - "Service Landing Suite": Multiple page + block templates
   - "Homepage Collection": All homepage-related templates
   - Helps users find the right template quickly

5. cms_template_presets
   - Save common customizations as presets
   - Example: "Service Landing - Strata Maintenance" (customized variant)
   - Can be applied to new pages quickly

6. cms_pages_template_audit
   - Tracks which template created which page
   - Links pages back to their template source
   - Enables: "Update all pages using this template"

7. cms_template_performance
   - Daily metrics for templates
   - Which templates are most effective?
   - Which blocks perform best?
   - Data for optimization recommendations

Template Workflow Example:

1. Admin creates page template "service_landing_v1"
   → Defines 5 blocks with editable fields
   → Save in cms_page_templates

2. User creates new service page
   → Selects "Service Landing" template
   → Page created with all 5 blocks
   → Audit record created (template_id, version_used)
   → user_count incremented

3. User customizes page (edits blocks)
   → Changes headline, adds images, edits FAQ
   → Only editable_fields can be changed

4. Admin updates template "service_landing_v1"
   → Changes benefit order, adds new FAQ item
   → New version created (v2)
   → old_pages_using_v1 get notification: "Template updated"
   → Admin can choose: auto-update or keep old version

5. Admin saves customization as preset
   → "Service Landing - Strata (Vancouver Version)"
   → Next time, user can choose this preset
   → Same blocks + same customizations applied instantly

Performance Optimization:
- Which templates generate most pages?
- Which templates lead to most traffic?
- Which block templates have highest CTR?
- Recommends: "These templates are performing well"

Scalability:
- Add unlimited page templates
- Add unlimited block templates
- Group them logically
- Save common variations as presets
- Track performance over time
- Enable A/B testing variants

Search/Discovery:
- Filter templates by: category, use_case, performance
- Search by: label, description
- Sort by: usage_count, last_used_at, performance
- "Show me the best-performing service landing templates"
*/
