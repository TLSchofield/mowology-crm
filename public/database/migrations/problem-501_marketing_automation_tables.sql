-- Migration 501: Marketing Automation Tables
-- Created: 2026-02-09
-- Purpose: Queue, logging, and page draft system for SEO recommendation automation

-- ============================================================================
-- MARKETING QUEUE (Background Job Processor)
-- ============================================================================

CREATE TABLE IF NOT EXISTS `marketing_queue` (
  `id` int NOT NULL AUTO_INCREMENT PRIMARY KEY,

  `job_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Job type: generate_draft|sync_gsc|update_status|measure_performance|suggest_meta',
  `recommendation_id` int COMMENT 'Reference to seo_recommendations (nullable for non-rec jobs)',
  `page_id` int COMMENT 'Reference to cms_pages (nullable for non-page jobs)',

  `status` ENUM('pending', 'processing', 'completed', 'failed', 'cancelled') COLLATE utf8mb4_unicode_ci DEFAULT 'pending',

  `payload` json COMMENT 'Job-specific input data (serialized as JSON)',
  `result` json COMMENT 'Job output/response (serialized as JSON)',
  `error_message` text COLLATE utf8mb4_unicode_ci COMMENT 'Error details if job failed',

  `attempts` int DEFAULT 0 COMMENT 'Number of retry attempts so far',
  `max_attempts` int DEFAULT 3 COMMENT 'Max retries before marking failed',

  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP COMMENT 'Job created',
  `started_at` timestamp NULL COMMENT 'Job processing started',
  `completed_at` timestamp NULL COMMENT 'Job completed or failed',

  KEY `idx_status` (`status`),
  KEY `idx_job_type` (`job_type`),
  KEY `idx_recommendation_id` (`recommendation_id`),
  KEY `idx_page_id` (`page_id`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_status_created` (`status`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT 'Background job queue for CMS + marketing automation';

-- ============================================================================
-- MARKETING LOGS (Audit Trail for Queue Jobs)
-- ============================================================================

CREATE TABLE IF NOT EXISTS `marketing_logs` (
  `id` int NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `queue_id` int COMMENT 'Reference to marketing_queue job',

  `action` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'e.g., generated_draft, published_page, synced_gsc',
  `details` text COLLATE utf8mb4_unicode_ci COMMENT 'Detailed log message',

  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,

  KEY `idx_queue_id` (`queue_id`),
  KEY `idx_action` (`action`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT 'Detailed logs for marketing queue job execution';

-- ============================================================================
-- SEO PAGE DRAFTS (AI-Generated Content Awaiting Publish)
-- ============================================================================

CREATE TABLE IF NOT EXISTS `seo_page_drafts` (
  `id` int NOT NULL AUTO_INCREMENT PRIMARY KEY,

  `recommendation_id` int COMMENT 'Reference to seo_recommendations (optional)',
  `cms_page_id` int COMMENT 'Reference to cms_pages if already published',

  `slug` varchar(191) COLLATE utf8mb4_unicode_ci UNIQUE NOT NULL COMMENT 'Proposed URL slug (validated as URL-safe)',
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Page title (from recommendation query)',
  `meta_title` varchar(255) COLLATE utf8mb4_unicode_ci COMMENT 'SEO title (auto-generated, <60 chars)',
  `meta_description` varchar(500) COLLATE utf8mb4_unicode_ci COMMENT 'SEO meta description (auto-generated, <160 chars)',
  `h1` varchar(255) COLLATE utf8mb4_unicode_ci COMMENT 'H1 heading (usually matches title)',

  `intro_text` text COLLATE utf8mb4_unicode_ci COMMENT 'Introduction paragraph (50-100 words)',

  `sections_json` json COMMENT 'Page body sections: [{ heading, content, cta_url, cta_text }, ...]',
  `internal_links_json` json COMMENT 'Suggested internal links: [{ anchor_text, target_url, context }, ...]',
  `images_json` json COMMENT 'Images to embed: [{ media_id, alt_text, caption, position }, ...]',
  `schema_json` json COMMENT 'JSON-LD structured data (LocalBusiness, Service, etc.)',

  `target_id` int COMMENT 'Reference to seo_targets (service/location)',
  `season_id` int COMMENT 'Reference to seo_seasons (if seasonal recommendation)',

  `status` ENUM('draft', 'review', 'scheduled', 'published', 'rejected', 'archived') COLLATE utf8mb4_unicode_ci DEFAULT 'draft' COMMENT 'Workflow status',

  `seo_score` int COMMENT 'Readability + keyword optimization score (0-100)',
  `keyword_focus` varchar(255) COLLATE utf8mb4_unicode_ci COMMENT 'Primary keyword target (from recommendation query)',
  `secondary_keywords_json` json COMMENT 'Secondary keywords/LSI terms to optimize for',

  `generated_by_ai` tinyint(1) DEFAULT 0 COMMENT 'Whether generated via AI (vs. template-based)',
  `ai_model_used` varchar(50) COLLATE utf8mb4_unicode_ci COMMENT 'e.g., gpt-4, template',
  `ai_prompt` text COLLATE utf8mb4_unicode_ci COMMENT 'AI prompt used for generation (for audit)',

  `created_by` int NOT NULL COMMENT 'User ID (admin or automation)',
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,

  `reviewed_by` int COMMENT 'User ID who reviewed draft',
  `reviewed_at` timestamp NULL,
  `review_notes` text COLLATE utf8mb4_unicode_ci,

  `published_by` int COMMENT 'User ID who published',
  `published_at` timestamp NULL COMMENT 'When draft became live page',
  `published_url` varchar(500) COLLATE utf8mb4_unicode_ci COMMENT 'Final published URL',

  `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  KEY `idx_slug` (`slug`),
  KEY `idx_status` (`status`),
  KEY `idx_recommendation_id` (`recommendation_id`),
  KEY `idx_cms_page_id` (`cms_page_id`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_target_id` (`target_id`),
  FULLTEXT `ftx_slug_title_keywords` (`slug`, `meta_title`, `keyword_focus`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT 'SEO-optimized page drafts generated from recommendations';

-- ============================================================================
-- RECOMMENDATION STATUS HISTORY (Audit Trail)
-- ============================================================================

CREATE TABLE IF NOT EXISTS `seo_recommendation_status_history` (
  `id` int NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `recommendation_id` int NOT NULL COMMENT 'Reference to seo_recommendations',

  `old_status` varchar(50) COLLATE utf8mb4_unicode_ci COMMENT 'Previous status',
  `new_status` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'New status',

  `old_score` float COMMENT 'Previous priority score',
  `new_score` float COMMENT 'New priority score',

  `action_taken` varchar(100) COLLATE utf8mb4_unicode_ci COMMENT 'What triggered the change (user action, auto, cron)',

  `details` text COLLATE utf8mb4_unicode_ci COMMENT 'Additional context about change',
  `user_id` int COMMENT 'User who triggered change (null for automation)',

  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci COMMENT 'IP for audit trail',

  KEY `idx_recommendation_id` (`recommendation_id`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `fk_status_history_rec_id` FOREIGN KEY (`recommendation_id`) REFERENCES `seo_recommendations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT 'Audit trail of recommendation status changes';

-- ============================================================================
-- MARKETING PERFORMANCE TRACKING
-- ============================================================================

CREATE TABLE IF NOT EXISTS `marketing_performance` (
  `id` int NOT NULL AUTO_INCREMENT PRIMARY KEY,

  `cms_page_id` int NOT NULL COMMENT 'Reference to cms_pages',
  `recommendation_id` int COMMENT 'Original recommendation (if applicable)',

  `date` date NOT NULL COMMENT 'Performance data date',

  -- GSC metrics
  `gsc_impressions` int COMMENT 'Search impressions from GSC',
  `gsc_clicks` int COMMENT 'Clicks from GSC',
  `gsc_ctr` float COMMENT 'Click-through rate from GSC',
  `gsc_avg_position` float COMMENT 'Average SERP position from GSC',

  -- Site metrics
  `page_views` int COMMENT 'Page views',
  `unique_visits` int COMMENT 'Unique visitors',
  `avg_time_on_page` float COMMENT 'Average time (seconds)',
  `bounce_rate` float COMMENT 'Bounce rate %',

  -- Conversion metrics
  `form_submissions` int COMMENT 'Contact/quote form submissions from this page',
  `leads_generated` int COMMENT 'Qualified leads attributed to page',

  -- Qualitative
  `notes` text COLLATE utf8mb4_unicode_ci,

  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY `uk_page_date` (`cms_page_id`, `date`),
  KEY `idx_date` (`date`),
  KEY `idx_recommendation_id` (`recommendation_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT 'Daily performance metrics for CMS pages (linked to recommendations)';

-- ============================================================================
-- UPDATE EXISTING seo_recommendations TABLE
-- ============================================================================

-- Add new columns to seo_recommendations if not already present
-- These fields link recommendations to the CMS and page draft workflow

ALTER TABLE `seo_recommendations` ADD COLUMN
  `cms_page_id` int COMMENT 'Link to cms_pages if recommendation was published';

ALTER TABLE `seo_recommendations` ADD COLUMN
  `seo_page_draft_id` int COMMENT 'Link to seo_page_drafts workflow';

ALTER TABLE `seo_recommendations` ADD COLUMN
  `status` ENUM('new', 'accepted', 'draft_created', 'published', 'monitoring', 'won', 'parked', 'ignored', 'archived')
    COLLATE utf8mb4_unicode_ci DEFAULT 'new' COMMENT 'Recommendation workflow status';

-- Migrate existing status values if they exist
UPDATE `seo_recommendations`
SET `status` = CASE
  WHEN `applied` = 1 THEN 'published'
  WHEN `notes` LIKE '%ignore%' THEN 'ignored'
  ELSE 'new'
END
WHERE `status` IS NULL;

-- Add workflow support columns
ALTER TABLE `seo_recommendations` ADD COLUMN
  `assigned_at` timestamp NULL COMMENT 'When recommendation was assigned to target';

ALTER TABLE `seo_recommendations` ADD COLUMN
  `assigned_by` int COMMENT 'User who assigned recommendation';

ALTER TABLE `seo_recommendations` ADD COLUMN
  `publish_reason` text COLLATE utf8mb4_unicode_ci COMMENT 'Why recommendation was published';

-- Add performance measurement columns
ALTER TABLE `seo_recommendations` ADD COLUMN
  `pre_publish_position` float COMMENT 'SERP position before publishing page';

ALTER TABLE `seo_recommendations` ADD COLUMN
  `post_publish_position` float COMMENT 'SERP position after publishing page (measured after 30 days)';

ALTER TABLE `seo_recommendations` ADD COLUMN
  `position_change` float COMMENT 'Change in SERP position (positive=improvement)';

ALTER TABLE `seo_recommendations` ADD COLUMN
  `page_performance_notes` text COLLATE utf8mb4_unicode_ci COMMENT 'Notes from performance measurement';

-- ============================================================================
-- Comments / Documentation
-- ============================================================================

/*
Marketing Automation Tables - Design Notes:

1. marketing_queue
   - Central job processor for background tasks
   - Job types: generate_draft, sync_gsc, update_status, measure_performance, suggest_meta
   - Supports retries (attempts vs. max_attempts)
   - Cron job queries "WHERE status='pending' ORDER BY created_at LIMIT 10"
   - payload/result stored as JSON (flexible schema per job type)

2. marketing_logs
   - Detailed audit trail for queue job execution
   - Links back to marketing_queue row via queue_id
   - Useful for debugging failed jobs and understanding automation decisions

3. seo_page_drafts
   - Holds AI-generated or template-generated page content
   - Workflow: draft → review → scheduled → published
   - Links to seo_recommendations (source of content) and cms_pages (published version)
   - seo_score: Readability + keyword optimization (0-100)
   - keyword_focus: Primary keyword from recommendation query
   - secondary_keywords_json: LSI terms and related keywords
   - ai_model_used: Tracks if generated by AI or template (for audit)
   - reviewer workflow: reviewed_by, reviewed_at, review_notes (admin approval)

4. seo_recommendation_status_history
   - Immutable audit trail of all status changes
   - Tracks score changes (useful for understanding recommendation prioritization)
   - Links changes to users or automation

5. marketing_performance
   - Daily snapshots of page performance metrics
   - Links to cms_pages and original seo_recommendations
   - Contains GSC metrics, site analytics, and conversion data
   - Unique constraint on (cms_page_id, date) prevents duplicates

6. seo_recommendations table updates
   - Added 'status' column with workflow states (replaces binary 'applied' logic)
   - Added assignment tracking (assigned_at, assigned_by)
   - Added performance measurement columns (pre/post position, change, notes)
   - Added publish_reason for workflow documentation
   - Links to cms_pages and seo_page_drafts for traceability

Recommendation Workflow States:

  new
    ↓ (user assigns target)
  accepted
    ↓ (system generates draft)
  draft_created
    ↓ (admin reviews and publishes)
  published
    ↓ (wait 30 days, measure performance)
  monitoring
    ├─ (goal met) → won [TERMINAL]
    ├─ (needs work) → published [RESTART]
    └─ (underperforming) → parked [TERMINAL]

  ignored [TERMINAL]
  archived [TERMINAL]

Cron Job Examples:

1. Every 5 minutes: Process pending queue jobs
   → SELECT * FROM marketing_queue WHERE status='pending' ORDER BY created_at LIMIT 10

2. Every hour: Score recommendations
   → SELECT * FROM seo_recommendations WHERE status='new' AND updated_at < NOW() - INTERVAL 1 HOUR

3. Every day: Measure performance of published pages
   → SELECT * FROM seo_recommendations WHERE status='published' AND published_at < NOW() - INTERVAL 30 DAY

4. Every week: Update statuses based on performance
   → Call performanceAnalysis() to update status (won/parked)
*/
