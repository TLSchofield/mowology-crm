-- Migration 605: Social Marketing Engine
-- Phase 1: Google Business Profile + Content Library + Scheduler
-- Phase 2 scaffold: Meta (Facebook / Instagram) + LinkedIn stubs
--
-- Depends on: media_assets (migration 030), contacts, users
-- Run: import this file via phpMyAdmin or mysql CLI

-- ── social_accounts ──────────────────────────────────────────────────
-- One row per connected platform account/location
CREATE TABLE IF NOT EXISTS social_accounts (
    id                      INT          AUTO_INCREMENT PRIMARY KEY,
    platform                VARCHAR(20)  NOT NULL,               -- gbp | facebook | instagram | linkedin
    account_name            VARCHAR(200) NOT NULL,
    account_id_external     VARCHAR(200) DEFAULT NULL,           -- Platform account/page ID
    location_id_external    VARCHAR(500) DEFAULT NULL,           -- GBP: full resource name e.g. accounts/123/locations/456
    location_name_display   VARCHAR(300) DEFAULT NULL,           -- Human-readable location name
    access_token_enc        TEXT         DEFAULT NULL,           -- AES-256-CBC encrypted
    refresh_token_enc       TEXT         DEFAULT NULL,           -- AES-256-CBC encrypted
    token_expires_at        DATETIME     DEFAULT NULL,
    token_scope             VARCHAR(500) DEFAULT NULL,
    is_active               TINYINT(1)   NOT NULL DEFAULT 1,
    is_verified             TINYINT(1)   NOT NULL DEFAULT 0,
    connected_by            INT          DEFAULT NULL,           -- users.id
    connected_at            DATETIME     DEFAULT NULL,
    last_sync_at            DATETIME     DEFAULT NULL,
    meta_json               TEXT         DEFAULT NULL,           -- Extra platform-specific data (JSON)
    created_at              TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_platform (platform),
    KEY idx_active   (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ── social_posts ─────────────────────────────────────────────────────
-- One row per piece of content (regardless of how many platforms)
CREATE TABLE IF NOT EXISTS social_posts (
    id               INT          AUTO_INCREMENT PRIMARY KEY,
    title            VARCHAR(300) DEFAULT NULL,                  -- Internal name only, not published
    caption          TEXT         NOT NULL,
    hashtags         TEXT         DEFAULT NULL,                  -- Space-separated
    cta_action       VARCHAR(50)  DEFAULT NULL,                  -- BOOK | LEARN_MORE | CALL | SHOP | SIGN_UP
    cta_url          VARCHAR(500) DEFAULT NULL,
    utm_campaign     VARCHAR(200) DEFAULT NULL,
    status           VARCHAR(30)  NOT NULL DEFAULT 'draft',
    -- draft | pending_approval | approved | scheduled | publishing | published | failed | cancelled
    scheduled_at     DATETIME     DEFAULT NULL,
    published_at     DATETIME     DEFAULT NULL,
    template_id      INT          DEFAULT NULL,                  -- social_templates.id
    visit_id         INT          DEFAULT NULL,                  -- Source visit (auto-generated posts)
    contact_id       INT          DEFAULT NULL,                  -- Related contact
    neighborhood     VARCHAR(200) DEFAULT NULL,
    city             VARCHAR(100) DEFAULT 'Vancouver',
    service_type     VARCHAR(100) DEFAULT NULL,
    created_by       INT          NOT NULL,                      -- users.id
    approved_by      INT          DEFAULT NULL,                  -- users.id
    fail_count       TINYINT      NOT NULL DEFAULT 0,
    last_fail_reason TEXT         DEFAULT NULL,
    next_retry_at    DATETIME     DEFAULT NULL,
    created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_status     (status),
    KEY idx_scheduled  (scheduled_at),
    KEY idx_created_by (created_by),
    KEY idx_template   (template_id),
    KEY idx_visit      (visit_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ── social_post_platforms ─────────────────────────────────────────────
-- One row per platform a post is targeted to (post can go to GBP + FB + IG)
CREATE TABLE IF NOT EXISTS social_post_platforms (
    id               INT           AUTO_INCREMENT PRIMARY KEY,
    post_id          INT           NOT NULL,                     -- social_posts.id
    account_id       INT           NOT NULL,                     -- social_accounts.id
    platform         VARCHAR(20)   NOT NULL,
    status           VARCHAR(20)   NOT NULL DEFAULT 'pending',
    -- pending | publishing | published | failed | skipped
    platform_post_id VARCHAR(500)  DEFAULT NULL,                 -- ID returned by platform API
    platform_url     VARCHAR(1000) DEFAULT NULL,                 -- Link to published post
    response_payload TEXT          DEFAULT NULL,                 -- Full API response JSON
    published_at     DATETIME      DEFAULT NULL,
    fail_reason      TEXT          DEFAULT NULL,
    retry_count      TINYINT       NOT NULL DEFAULT 0,
    KEY idx_post     (post_id),
    KEY idx_account  (account_id),
    KEY idx_status   (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ── social_templates ──────────────────────────────────────────────────
-- Reusable caption templates with variable substitution
CREATE TABLE IF NOT EXISTS social_templates (
    id               INT          AUTO_INCREMENT PRIMARY KEY,
    name             VARCHAR(200) NOT NULL,
    category         VARCHAR(50)  DEFAULT NULL,
    -- seasonal | upsell | proof_of_work | review_request | announcement
    caption_template TEXT         NOT NULL,
    -- Variables: {client_name} {service} {neighborhood} {city} {date} {crew_name}
    hashtag_preset   TEXT         DEFAULT NULL,
    cta_preset       VARCHAR(50)  DEFAULT NULL,
    cta_url_preset   VARCHAR(500) DEFAULT NULL,
    platform_targets VARCHAR(100) DEFAULT 'gbp,facebook,instagram',
    is_active        TINYINT(1)   NOT NULL DEFAULT 1,
    usage_count      INT          NOT NULL DEFAULT 0,
    created_by       INT          DEFAULT NULL,
    created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_category (category),
    KEY idx_active   (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ── social_post_media ─────────────────────────────────────────────────
-- Junction: which media_assets are attached to which post
CREATE TABLE IF NOT EXISTS social_post_media (
    id          INT     AUTO_INCREMENT PRIMARY KEY,
    post_id     INT     NOT NULL,                                -- social_posts.id
    media_id    INT     NOT NULL,                                -- media_assets.id
    sort_order  TINYINT NOT NULL DEFAULT 0,
    KEY idx_post  (post_id),
    KEY idx_media (media_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ── social_approvals ──────────────────────────────────────────────────
-- Approval thread: submitted → approved/rejected with comments
CREATE TABLE IF NOT EXISTS social_approvals (
    id          INT         AUTO_INCREMENT PRIMARY KEY,
    post_id     INT         NOT NULL,
    user_id     INT         NOT NULL,
    action      VARCHAR(30) NOT NULL,                            -- submitted | approved | rejected | comment
    comment     TEXT        DEFAULT NULL,
    created_at  TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_post (post_id),
    KEY idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ── social_metrics_daily ──────────────────────────────────────────────
-- Daily engagement snapshot per published post-platform pair
CREATE TABLE IF NOT EXISTS social_metrics_daily (
    id               INT       AUTO_INCREMENT PRIMARY KEY,
    post_platform_id INT       NOT NULL,                         -- social_post_platforms.id
    metric_date      DATE      NOT NULL,
    impressions      INT       NOT NULL DEFAULT 0,
    reach            INT       NOT NULL DEFAULT 0,
    clicks           INT       NOT NULL DEFAULT 0,
    likes            INT       NOT NULL DEFAULT 0,
    comments_count   INT       NOT NULL DEFAULT 0,
    shares           INT       NOT NULL DEFAULT 0,
    saves            INT       NOT NULL DEFAULT 0,
    fetched_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_post_date (post_platform_id, metric_date),
    KEY idx_date (metric_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ── social_queue ──────────────────────────────────────────────────────
-- Publisher queue with locking and retry logic
CREATE TABLE IF NOT EXISTS social_queue (
    id               INT         AUTO_INCREMENT PRIMARY KEY,
    post_id          INT         NOT NULL,
    account_id       INT         NOT NULL,
    platform         VARCHAR(20) NOT NULL,
    scheduled_at     DATETIME    NOT NULL,
    attempts         TINYINT     NOT NULL DEFAULT 0,
    max_attempts     TINYINT     NOT NULL DEFAULT 3,
    next_attempt_at  DATETIME    DEFAULT NULL,
    locked_at        DATETIME    DEFAULT NULL,                   -- Worker process lock
    locked_by        VARCHAR(50) DEFAULT NULL,                   -- Worker PID or hostname
    status           VARCHAR(20) NOT NULL DEFAULT 'pending',
    -- pending | processing | completed | failed
    result_payload   TEXT        DEFAULT NULL,
    created_at       TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_due     (scheduled_at, status),
    KEY idx_post    (post_id),
    KEY idx_locked  (locked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ── social_audit_log ──────────────────────────────────────────────────
-- Immutable audit trail for account changes, approvals, publishes
CREATE TABLE IF NOT EXISTS social_audit_log (
    id          INT         AUTO_INCREMENT PRIMARY KEY,
    user_id     INT         DEFAULT NULL,
    action      VARCHAR(50) NOT NULL,
    -- account_connected | account_disconnected | post_created | post_approved
    -- post_rejected | post_scheduled | post_published | post_failed | token_refreshed
    entity_type VARCHAR(30) DEFAULT NULL,                        -- account | post | template
    entity_id   INT         DEFAULT NULL,
    detail      TEXT        DEFAULT NULL,
    ip_address  VARCHAR(45) DEFAULT NULL,
    created_at  TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_user   (user_id),
    KEY idx_action (action),
    KEY idx_entity (entity_type, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ── social_utm_links ──────────────────────────────────────────────────
-- UTM attribution: which social posts drove quote requests / jobs
CREATE TABLE IF NOT EXISTS social_utm_links (
    id                 INT          AUTO_INCREMENT PRIMARY KEY,
    post_id            INT          DEFAULT NULL,
    utm_campaign       VARCHAR(200) DEFAULT NULL,
    utm_source         VARCHAR(100) NOT NULL DEFAULT 'social',
    utm_medium         VARCHAR(100) NOT NULL DEFAULT 'post',
    utm_content        VARCHAR(200) DEFAULT NULL,
    short_code         VARCHAR(20)  DEFAULT NULL,
    clicks             INT          NOT NULL DEFAULT 0,
    quote_requests     INT          NOT NULL DEFAULT 0,
    jobs_created       INT          NOT NULL DEFAULT 0,
    revenue_attributed DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    created_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_post       (post_id),
    KEY idx_short_code (short_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ── Seed: social_templates ────────────────────────────────────────────
INSERT INTO social_templates (name, category, caption_template, hashtag_preset, cta_preset, platform_targets) VALUES

('Lawn Maintenance — Proof of Work', 'proof_of_work',
'✅ Another pristine lawn in {neighborhood}!\n\nOur crew just finished a fresh cut, edge trim, and blow-off on this {neighborhood} property. Notice the crisp lines and clean borders — this is what weekly maintenance looks like.\n\nWant your lawn looking like this? Book a free quote today. 🌿',
'#VancouverLandscaping #LawnCare #VancouverLawn #CurbAppeal #Mowology #LawnMaintenance #YVRLandscaping',
'BOOK', 'gbp,facebook,instagram'),

('Spring Fertilizer Upsell', 'upsell',
'🌱 Spring Fertilization is OPEN in {city}!\n\nGive your lawn the nutrients it needs after a long winter. Our slow-release fertilizer program keeps grass lush and green all season — and prevents costly bare patches.\n\nAdd fertilizer to your maintenance plan. Ask us how! 🌿',
'#SpringLawn #FertilizerTreatment #LawnHealth #VancouverGardening #Mowology #GreenLawn',
'LEARN_MORE', 'gbp,facebook,instagram'),

('Fall Aeration Upsell', 'upsell',
'🍂 Fall is the BEST time for lawn aeration in {city}.\n\nAeration breaks up compacted soil so nutrients and water reach the roots — giving you a denser, healthier lawn next spring.\n\nWe''re booking aeration slots now. Spaces fill fast! 🌱',
'#LawnAeration #FallLawnCare #VancouverLandscaping #Mowology #HealthyLawn',
'BOOK', 'gbp,facebook,instagram'),

('Spring Power Rake Upsell', 'upsell',
'🌿 Spring Power Raking is here!\n\nThatch buildup smothers your lawn and invites disease. Our power rake service removes dead organic matter and gets your grass ready for the growing season.\n\nBook now before our spring slots fill up in {neighborhood}. 🌱',
'#PowerRake #SpringCleanup #VancouverLawn #LawnHealth #Mowology',
'BOOK', 'gbp,facebook,instagram'),

('Hedge Trimming Showcase', 'proof_of_work',
'✂️ Sharp hedges. Sharp edges. Sharp property.\n\nOur crew just completed a hedge trimming and shaping service in {neighborhood}. Clean geometric lines, cleared debris — looking pristine.\n\nHedge trimming is available as a one-time service or add-on to your plan. 🏡',
'#HedgeTrimming #VancouverLandscaping #CurbAppeal #Mowology #GardenMaintenance',
'BOOK', 'gbp,facebook,instagram'),

('Before / After Transformation', 'proof_of_work',
'🔄 Before → After.\n\nThis is why regular maintenance matters. See the difference a professional crew makes on this {neighborhood} property.\n\nLeft: overgrown and neglected. Right: fresh cut, trimmed edges, clean beds. 🌱\n\nYour lawn can look like this every week. Book today!',
'#BeforeAndAfter #LawnTransformation #VancouverLawn #Mowology #LawnCare',
'BOOK', 'gbp,facebook,instagram'),

('New Neighbourhood Route Opening', 'announcement',
'📍 Now serving {neighborhood}!\n\nWe''ve opened new weekly maintenance routes in {neighborhood}. If you''ve been waiting to get on our schedule — now is the time.\n\nLimited spots available — contact us today! 🌿',
'#VancouverLandscaping #NewNeighbourhood #LawnService #Mowology #RouteExpansion',
'BOOK', 'gbp,facebook,instagram'),

('5-Star Review Celebration', 'proof_of_work',
'⭐⭐⭐⭐⭐ We love hearing from happy clients!\n\nThank you to our {neighborhood} client for the kind words. Reviews like this inspire our crew every single day. 🙏\n\nWant to experience the Mowology difference? Free quotes available. 🌿',
'#5StarReview #HappyClient #VancouverLandscaping #Mowology #CustomerLove',
'LEARN_MORE', 'gbp,facebook,instagram');
