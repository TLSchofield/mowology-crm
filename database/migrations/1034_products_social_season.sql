-- ============================================================
-- Migration 1034: Products → Social Season Cadence
-- ============================================================
-- Adds two columns to products so the social campaign scheduler
-- can read service-specific posting cadence from the catalogue
-- instead of using the generic Vancouver landscaping defaults.
--
-- social_cadence: 12 comma-separated integers (Jan–Dec), e.g.
--   "0,0,0,0,0,0,0,0,2,4,3,1" = Fall Cleanup
--   "0,1,2,3,4,4,4,3,2,2,1,0" = Year-round lawn care
--   Value 0 = no posts that month; 1–5 = target posts/month
--
-- service_type: free-text key matching social_templates.service_type
--   e.g. "Fall Cleanup", "Lawn Care", "Hedge Trimming"
--   Allows the scheduler to look up this product by service name.
-- ============================================================

ALTER TABLE products
    ADD COLUMN service_type    VARCHAR(100) NULL DEFAULT NULL AFTER best_season,
    ADD COLUMN social_cadence  VARCHAR(50)  NULL DEFAULT NULL
        COMMENT 'Jan-Dec post targets, comma-separated: "0,0,0,0,0,0,0,0,2,4,3,1"'
        AFTER service_type;
