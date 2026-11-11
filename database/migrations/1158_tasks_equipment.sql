-- Migration 1158: Link a task to the equipment it is about
-- Date: 2026-10-05
-- Purpose: when Tim accepts Otto's "blades are due" suggestion, the task created in the
--   existing tasks table points at the item, and marking the service done logs it.
--   Additive only; task_type is left alone (its ENUM may differ on production).
-- MySQL 5.7 compatible.

ALTER TABLE tasks ADD COLUMN equipment_id INT NULL;
CREATE INDEX idx_tasks_equipment ON tasks (equipment_id);
