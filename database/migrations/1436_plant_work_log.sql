-- 1436: plant_work_log — the property's plant history, one row per plant per task per visit.
-- Written by PlantWorkService::recordFromChecklist when a visit's checklist is saved: the checklist lines that
-- carry a plant key (care:<card id>:<task n> from the care library, plan:<plan id>:<line n> from a pruning plan)
-- become rows here, ticked or not. Read by the Plants card, the visit report and the client portal.
-- Also: properties.plant_checklist — 0 stops plant lines being added to that property's visit checklists.

CREATE TABLE IF NOT EXISTS plant_work_log (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  site_id          INT           NOT NULL DEFAULT 1,
  property_id      INT           NOT NULL,
  plant_record_id  INT           NOT NULL,
  visit_id         INT           NULL,
  source           VARCHAR(16)   NOT NULL DEFAULT 'care' COMMENT 'care | pruning_plan | manual',
  task_key         VARCHAR(80)   NOT NULL COMMENT 'care:<card id>:<n> | plan:<plan id>:<n> | manual',
  task             VARCHAR(255)  NOT NULL COMMENT 'the checklist line as the crew saw it',
  done             TINYINT(1)    NOT NULL DEFAULT 0,
  done_at          DATETIME      NULL,
  done_by          INT           NULL,
  note             VARCHAR(500)  NULL,
  scheduled_for    DATE          NULL     COMMENT 'the visit date',
  created_at       DATETIME      NOT NULL,
  updated_at       DATETIME      NULL,
  UNIQUE KEY uq_pwl_visit_plant_task (visit_id, plant_record_id, task_key),
  KEY idx_pwl_property (property_id, done_at),
  KEY idx_pwl_plant (plant_record_id, done_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE properties ADD COLUMN plant_checklist TINYINT(1) NOT NULL DEFAULT 1 COMMENT '0 = no plant care lines on this property''s visit checklists';
