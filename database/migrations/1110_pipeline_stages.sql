-- 1110_pipeline_stages.sql
-- Found via the QA crawler (tools/crm-crawl.php): the Quotes page's
-- "Pipeline Value / Weighted Forecast / Win Rate / Avg Days in Stage"
-- summary widget calls /crm/api/pipeline.php?action=stats, which queries
-- quotes.pipeline_stage/probability/stage_entered_at and the
-- pipeline_stages table — none of which were ever migrated, so the
-- widget has been 500ing (caught, logged, shown as "--") since it shipped.
--
-- Minimal fix: add the missing columns/table so the (already NULL-safe,
-- COALESCE-wrapped) stats query stops erroring. No backfill — existing
-- quotes simply have no pipeline_stage until manually moved through the
-- pipeline going forward.
--
-- Executable form: public/crm/run-migration-1110.php (introspects live
-- columns first, per the same schema-drift caution as migration 1109).

ALTER TABLE `quotes`
    ADD COLUMN `pipeline_stage` varchar(50) DEFAULT NULL,
    ADD COLUMN `probability` tinyint DEFAULT NULL,
    ADD COLUMN `stage_entered_at` datetime DEFAULT NULL,
    ADD COLUMN `lost_reason` varchar(255) DEFAULT NULL;

CREATE TABLE IF NOT EXISTS `pipeline_stages` (
    `id` int NOT NULL AUTO_INCREMENT,
    `stage_key` varchar(50) NOT NULL,
    `stage_label` varchar(100) NOT NULL,
    `stage_order` int NOT NULL DEFAULT 0,
    `stage_color` varchar(20) DEFAULT '#6B7280',
    `default_probability` tinyint DEFAULT 0,
    `is_active` tinyint(1) DEFAULT 1,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_stage_key` (`stage_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO `pipeline_stages` (`stage_key`, `stage_label`, `stage_order`, `stage_color`, `default_probability`) VALUES
    ('new',          'New',           0, '#6B7280', 10),
    ('contacted',    'Contacted',     1, '#3B82F6', 25),
    ('quoted',       'Quoted',        2, '#8B5CF6', 50),
    ('negotiating',  'Negotiating',   3, '#F59E0B', 75),
    ('closed_won',   'Closed Won',    4, '#22C55E', 100),
    ('closed_lost',  'Closed Lost',   5, '#EF4444', 0);
