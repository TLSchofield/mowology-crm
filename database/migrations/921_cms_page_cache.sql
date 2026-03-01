-- ============================================================================
-- Migration 921: CMS HTML Page Cache (P1-B)
-- Persistent rendered-HTML cache per page. One row per page_id.
-- Invalidated by cms_invalidatePageHtmlCache() on page/block saves.
-- ============================================================================

CREATE TABLE IF NOT EXISTS cms_page_cache (
    page_id     INT UNSIGNED     NOT NULL COMMENT 'FK → cms_pages.id',
    cache_html  LONGTEXT         NOT NULL COMMENT 'Full rendered HTML output',
    cached_at   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ttl_seconds INT UNSIGNED     NOT NULL DEFAULT 3600 COMMENT 'Desired TTL; enforced in PHP',
    PRIMARY KEY (page_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Persistent HTML cache for CMS pages; invalidated on any page/block change';
