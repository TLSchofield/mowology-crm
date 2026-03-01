-- ============================================================================
-- Migration 920: CMS Page Redirects (P1-A)
-- Stores 301/302 redirect rules — auto-populated when a page slug changes.
-- ============================================================================

CREATE TABLE IF NOT EXISTS cms_page_redirects (
    id          INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    from_slug   VARCHAR(500)     NOT NULL COMMENT 'Old slug that was renamed',
    to_slug     VARCHAR(500)     NOT NULL COMMENT 'New/current slug to redirect to',
    status_code SMALLINT         NOT NULL DEFAULT 301 COMMENT '301 Permanent, 302 Temporary',
    is_active   TINYINT(1)       NOT NULL DEFAULT 1,
    hit_count   INT UNSIGNED     NOT NULL DEFAULT 0 COMMENT 'Analytics: how often this redirect fires',
    created_at  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_from_slug (from_slug(191)),
    KEY         idx_active_slug (is_active, from_slug(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='CMS slug-change redirect rules — auto-inserted by cms_savePage()';
