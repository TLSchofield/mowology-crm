-- Migration 920: Obsidian Root™ enrollment status per property
-- Adds or_status to properties table with three states:
--   'none'     — no program sold (grey icon)
--   'sell'     — crew should offer the program (red ring)
--   'enrolled' — active program (full colour icon)

ALTER TABLE `properties`
    ADD COLUMN `or_status` ENUM('none','sell','enrolled') NOT NULL DEFAULT 'none';

CREATE INDEX `idx_properties_or_status` ON `properties` (`or_status`);
