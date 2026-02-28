-- Migration 503: SEO Template Library
-- Created: 2026-02-09
-- Purpose: Store, version, and manage SEO recommendations response templates

-- ============================================================================
-- SEO RESPONSE TEMPLATES (for recommendation answers/responses)
-- ============================================================================

CREATE TABLE IF NOT EXISTS `seo_response_templates` (
  `id` int NOT NULL AUTO_INCREMENT PRIMARY KEY,

  `template_key` varchar(100) COLLATE utf8mb4_unicode_ci UNIQUE NOT NULL COMMENT 'Machine name (e.g., improve_title_meta)',
  `label` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Display name (e.g., "Improve Title/Meta")',
  `description` text COLLATE utf8mb4_unicode_ci COMMENT 'What this template addresses',

  `recommendation_type` ENUM('create_page', 'improve_page', 'title_meta', 'internal_links', 'add_photos', 'schema', 'seasonal')
    COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Type of recommendation this handles',

  `target_type` ENUM('keyword', 'intent', 'location', 'seasonal', 'service', 'general')
    COLLATE utf8mb4_unicode_ci DEFAULT 'keyword' COMMENT 'What this template targets',

  -- Response configuration
  `action_type` varchar(50) COLLATE utf8mb4_unicode_ci COMMENT 'Action to take: create_page|update_page|add_block|modify_block',
  `action_details_json` json COMMENT 'Action-specific config (template_id, block_type, etc.)',

  `seo_focus_keywords_json` json COMMENT 'Array of keywords to optimize for: ["keyword1", "keyword2"]',
  `title_template` varchar(255) COLLATE utf8mb4_unicode_ci COMMENT 'Meta title template with variables: "[Service] in [Location] | Mowology"',
  `description_template` varchar(500) COLLATE utf8mb4_unicode_ci COMMENT 'Meta description template',
  `h1_template` varchar(255) COLLATE utf8mb4_unicode_ci COMMENT 'H1 heading template',

  `suggested_blocks_json` json COMMENT 'Array of block template IDs to suggest adding',
  `suggested_content_sections_json` json COMMENT 'Content sections to include: [{heading, word_count_target}]',
  `suggested_internal_links_json` json COMMENT 'Pages to link to: ["/services/...", "/about"]',

  `page_metadata_overrides_json` json COMMENT 'Page settings to apply: {canonical_url, og_image_path}',

  `difficulty_level` ENUM('easy', 'medium', 'hard') COLLATE utf8mb4_unicode_ci DEFAULT 'medium' COMMENT 'Implementation difficulty',
  `priority_weight` int DEFAULT 50 COMMENT 'Priority score (0-100), used in recommendation scoring',
  `estimated_impact` varchar(50) COLLATE utf8mb4_unicode_ci COMMENT 'Expected impact: +5-10 positions, +20% traffic, etc.',

  `category` varchar(50) COLLATE utf8mb4_unicode_ci COMMENT 'Template category: core|advanced|seasonal|experimental',
  `version` int DEFAULT 1,
  `is_active` tinyint(1) DEFAULT 1,
  `is_locked` tinyint(1) DEFAULT 0 COMMENT 'System templates (locked)',

  `preview_image_path` varchar(255) COLLATE utf8mb4_unicode_ci,
  `documentation_url` varchar(500) COLLATE utf8mb4_unicode_ci COMMENT 'Link to detailed documentation',

  `success_count` int DEFAULT 0 COMMENT 'How many recommendations used this template successfully',
  `failure_count` int DEFAULT 0 COMMENT 'How many recommendations using this template failed',

  `created_by` int NOT NULL,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `updated_by` int,
  `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  KEY `idx_recommendation_type` (`recommendation_type`),
  KEY `idx_target_type` (`target_type`),
  KEY `idx_category` (`category`),
  KEY `idx_is_active` (`is_active`),
  KEY `idx_success_rate` (`success_count`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT 'Templates for responding to SEO recommendations';

-- ============================================================================
-- SEO RESPONSE TEMPLATE VERSIONS
-- ============================================================================

CREATE TABLE IF NOT EXISTS `seo_response_template_versions` (
  `id` int NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `response_template_id` int NOT NULL REFERENCES seo_response_templates(id) ON DELETE CASCADE,

  `version` int NOT NULL,
  `action_details_json` json,
  `seo_focus_keywords_json` json,
  `title_template` varchar(255) COLLATE utf8mb4_unicode_ci,
  `description_template` varchar(500) COLLATE utf8mb4_unicode_ci,

  `change_summary` varchar(255) COLLATE utf8mb4_unicode_ci,
  `changelog` text COLLATE utf8mb4_unicode_ci,

  `created_by` int NOT NULL,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY `uk_response_template_version` (`response_template_id`, `version`),
  CONSTRAINT `fk_seo_response_template_versions_template_id` FOREIGN KEY (`response_template_id`) REFERENCES `seo_response_templates` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT 'Version history for SEO response templates';

-- ============================================================================
-- SEO RESPONSE ACTIONS (Implement template against recommendation)
-- ============================================================================

CREATE TABLE IF NOT EXISTS `seo_recommendation_responses` (
  `id` int NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `recommendation_id` int NOT NULL REFERENCES seo_recommendations(id),
  `response_template_id` int REFERENCES seo_response_templates(id),

  `status` ENUM('draft', 'review', 'in_progress', 'completed', 'blocked', 'abandoned')
    COLLATE utf8mb4_unicode_ci DEFAULT 'draft' COMMENT 'Action status',

  -- What was executed
  `action_taken` varchar(100) COLLATE utf8mb4_unicode_ci COMMENT 'What happened: page_created, page_updated, blocks_added',
  `result_data_json` json COMMENT 'Result details: {page_id, cms_page_id, changes_made}',

  -- Performance tracking
  `expected_impact` varchar(100) COLLATE utf8mb4_unicode_ci COMMENT 'Expected: +5 positions, +20% traffic',
  `actual_impact` varchar(100) COLLATE utf8mb4_unicode_ci COMMENT 'Measured after 30 days',
  `impact_measured_at` timestamp NULL,

  -- Metadata
  `notes` text COLLATE utf8mb4_unicode_ci COMMENT 'Admin notes about response',
  `blocked_reason` text COLLATE utf8mb4_unicode_ci COMMENT 'Why blocked (if status=blocked)',

  `created_by` int NOT NULL,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `completed_at` timestamp NULL,

  KEY `idx_recommendation_id` (`recommendation_id`),
  KEY `idx_response_template_id` (`response_template_id`),
  KEY `idx_status` (`status`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT 'Track implementation of SEO responses to recommendations';

-- ============================================================================
-- SEO TEMPLATE CONDITIONS (When to use template)
-- ============================================================================

CREATE TABLE IF NOT EXISTS `seo_template_conditions` (
  `id` int NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `response_template_id` int NOT NULL REFERENCES seo_response_templates(id) ON DELETE CASCADE,

  -- Query/Page conditions
  `min_search_volume` int COMMENT 'Only if query volume >= this',
  `max_search_volume` int COMMENT 'Only if query volume <= this',
  `min_position` int COMMENT 'Only if ranking position >= this (farther down)',
  `max_position` int COMMENT 'Only if ranking position <= this (higher up)',

  -- Target conditions
  `required_target_types` json COMMENT 'Template only applies if target is one of: ["city", "postcode"]',
  `required_services` json COMMENT 'Template only applies if service in: ["strata", "hedge_trim"]',
  `required_seasons` json COMMENT 'Template only applies if season in: ["spring", "summer"]',

  -- Page conditions
  `page_exists` tinyint(1) COMMENT 'Only if page already exists (1) or doesn\'t exist (0)',
  `page_indexed` tinyint(1) COMMENT 'Only if page is indexed in GSC (1) or not indexed (0)',

  -- Keyword conditions
  `keyword_contains` json COMMENT 'Only if query contains any of: ["strata", "maintenance"]',
  `keyword_excludes` json COMMENT 'Never if query contains: ["competitor"]',
  `keyword_intent` varchar(50) COLLATE utf8mb4_unicode_ci COMMENT 'Query intent: commercial|informational|navigational',

  KEY `idx_response_template_id` (`response_template_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT 'Conditions for when to automatically apply response templates';

-- ============================================================================
-- SEO IMPROVEMENT GUIDELINES (Best practices per template)
-- ============================================================================

CREATE TABLE IF NOT EXISTS `seo_improvement_guidelines` (
  `id` int NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `response_template_id` int NOT NULL REFERENCES seo_response_templates(id) ON DELETE CASCADE,

  `guideline_category` varchar(50) COLLATE utf8mb4_unicode_ci COMMENT 'For what: title|description|content|links|schema',

  `guideline_text` text COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'The guideline (e.g., "Title must include target keyword")',
  `best_practice` text COLLATE utf8mb4_unicode_ci COMMENT 'Best practice example',
  `anti_pattern` text COLLATE utf8mb4_unicode_ci COMMENT 'What NOT to do',

  `min_length` int COMMENT 'Minimum length (for title/description)',
  `max_length` int COMMENT 'Maximum length',
  `target_length` int COMMENT 'Ideal length',

  `keyword_placement` varchar(50) COLLATE utf8mb4_unicode_ci COMMENT 'Where keyword should appear: start|middle|end',
  `keyword_frequency_min` float COMMENT 'Min keyword density (0.5)',
  `keyword_frequency_max` float COMMENT 'Max keyword density (2.5)',

  `examples_json` json COMMENT 'Examples: [{good: "...", explanation: "..."}]',

  KEY `idx_response_template_id` (`response_template_id`),
  KEY `idx_guideline_category` (`guideline_category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT 'SEO guidelines and best practices for each template';

-- ============================================================================
-- SEED DEFAULT SEO RESPONSE TEMPLATES
-- ============================================================================

INSERT IGNORE INTO `seo_response_templates`
(`template_key`, `label`, `description`, `recommendation_type`, `target_type`, `action_type`, `difficulty_level`, `priority_weight`, `is_locked`, `created_by`)
VALUES
  (
    'create_service_page',
    'Create New Service Page',
    'Create a dedicated landing page for a high-volume service query',
    'create_page',
    'service',
    'create_page',
    'easy',
    85,
    1,
    1
  ),
  (
    'improve_title_description',
    'Improve Title & Meta Description',
    'Optimize existing page title and meta description for keyword',
    'title_meta',
    'keyword',
    'modify_block',
    'easy',
    70,
    1,
    1
  ),
  (
    'add_portfolio_proof',
    'Add Portfolio/Proof Section',
    'Add portfolio showcase block to existing page',
    'add_photos',
    'keyword',
    'add_block',
    'medium',
    60,
    1,
    1
  ),
  (
    'create_location_page',
    'Create Location Landing Page',
    'Create location-specific landing page for city/postcode',
    'create_page',
    'location',
    'create_page',
    'medium',
    75,
    1,
    1
  ),
  (
    'seasonal_content_addition',
    'Add Seasonal Content',
    'Add seasonal section/block to existing page',
    'seasonal',
    'seasonal',
    'add_block',
    'medium',
    65,
    1,
    1
  )
ON DUPLICATE KEY UPDATE `template_key`=`template_key`;

-- ============================================================================
-- COMMENTS / DOCUMENTATION
-- ============================================================================

/*
SEO Response Template Library - Design Notes:

1. seo_response_templates
   - Stores templates for responding to SEO recommendations
   - Each template defines:
     * What action to take (create page, update page, add block)
     * What content to create (title/description templates, keywords)
     * Which blocks to suggest adding
     * Expected impact and difficulty

2. seo_response_template_versions
   - Immutable snapshots of template evolution
   - Track what changed and why

3. seo_recommendation_responses
   - Links recommendations to response templates
   - Tracks: action taken, result, expected vs actual impact
   - Enables: "Which responses are working?"

4. seo_template_conditions
   - Smart conditions for auto-selecting best template
   - Example: "Use this template only if:
       - Search volume >= 100
       - Position >= 20 (not ranking yet)
       - Query contains 'strata'
       - Page doesn't exist yet"

5. seo_improvement_guidelines
   - Best practices and guidelines for each template
   - Examples of good/bad implementations
   - Min/max lengths, keyword placement, density

Workflow Example:

1. GSC data arrives:
   - Query: "strata landscaping vancouver"
   - Position: 35 (not ranking)
   - Volume: 150/month
   - No page found

2. Auto-select template:
   - Check conditions for all templates
   - seo_template_conditions matching:
     ✓ create_service_page (volume >= 100)
     ✓ create_location_page (has location intent)
     ✗ improve_title_description (no existing page)
   - Pick highest priority_weight: create_service_page (85)

3. Create recommendation:
   - recommendation_type: 'create_page'
   - Linked template: seo_response_templates.id
   - Suggested action: "Create service + location page"

4. Admin reviews:
   - Shows template action_details
   - Shows suggested_blocks
   - Shows guidelines for best practices
   - Clicks: "Create Page from Template"

5. System generates draft:
   - Use page_template from seo_response_templates
   - Pre-fill title_template, description_template
   - Add suggested_blocks
   - Create cms_page_drafts record

6. Admin publishes:
   - Page goes live
   - Response status: 'completed'
   - Measure performance after 30 days

7. Track success:
   - Update success_count if impact achieved
   - Update failure_count if impact not achieved
   - Use data to improve templates

Templates can be:
- Auto-selected based on conditions
- Manually chosen by admin
- A/B tested via conditions
- Updated as we learn what works best
*/
