-- Migration 1367: plants found on site (2026-10-15)
-- Plan: docs/plans/2026-10-15-plant-id-to-bed-care.md
--   plant_records — one plant identified at a property: the photos (whole / closer / close-up), where they were
--                   taken (point, accuracy, compass heading), Pl@ntNet's top matches, and the bed it grows in.
--                   The bed is kept by id AND name AND point: saving a property's measurements replaces every
--                   bed row (new ids), so the name / point find it again.
--   Tenant-neutral (site_id); the species list is universal, a property's plants are the tenant's own.
-- MySQL 5.7 compatible; safe to re-run.

CREATE TABLE IF NOT EXISTS plant_records (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  site_id          INT           NOT NULL DEFAULT 1,
  property_id      INT           NOT NULL,
  measurement_id   INT           NULL     COMMENT 'property_measurements.id when matched (may go stale on a re-save)',
  bed_name         VARCHAR(120)  NULL     COMMENT 'the bed''s name at the time — finds it again after a re-save',
  bed_how          VARCHAR(10)   NULL     COMMENT 'inside | facing | near | picked',
  media_ids        VARCHAR(100)  NOT NULL COMMENT 'media_assets ids, comma-separated: whole plant, closer, close-up',
  lat              DECIMAL(10,7) NULL,
  lng              DECIMAL(10,7) NULL,
  accuracy_m       DECIMAL(7,1)  NULL,
  heading_deg      DECIMAL(5,1)  NULL     COMMENT 'compass heading the camera faced, 0 = north',
  matches          TEXT          NULL     COMMENT 'Pl@ntNet top matches as JSON text [{scientific, common, score}]',
  scientific_name  VARCHAR(160)  NULL,
  common_name      VARCHAR(160)  NULL,
  score            DECIMAL(5,3)  NULL     COMMENT 'Pl@ntNet score of the name chosen, 0–1',
  status           VARCHAR(12)   NOT NULL DEFAULT 'suggested' COMMENT 'suggested | unsure | confirmed | removed',
  notes            VARCHAR(255)  NULL,
  created_by       INT           NULL,
  created_at       DATETIME      NOT NULL,
  confirmed_by     INT           NULL,
  confirmed_at     DATETIME      NULL,
  updated_at       DATETIME      NULL,
  KEY idx_pr_property (property_id, status),
  KEY idx_pr_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
