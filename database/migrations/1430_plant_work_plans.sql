-- 1430: plant_work_plans — a Claude-drafted work plan (kind 'pruning' first) for a plant on a property.
-- One row per draft; the newest row that is not rejected is the plan. plan = JSON text (see PruningPlanService::schema()).
-- Tenant-neutral: nothing landscaping-specific in the columns; `kind` names the trade's job.

CREATE TABLE IF NOT EXISTS plant_work_plans (
  id                 INT AUTO_INCREMENT PRIMARY KEY,
  site_id            INT           NOT NULL DEFAULT 1,
  property_id        INT           NOT NULL,
  plant_record_id    INT           NOT NULL,
  kind               VARCHAR(20)   NOT NULL DEFAULT 'pruning',
  media_ids          VARCHAR(100)  NOT NULL COMMENT 'media_assets ids the plan was drafted from, comma-separated, photo 0 first',
  scientific_name    VARCHAR(160)  NULL,
  common_name        VARCHAR(160)  NULL,
  species_confidence DECIMAL(4,3)  NULL     COMMENT 'Claude''s confidence that the species is right, 0–1',
  plan               TEXT          NULL     COMMENT 'JSON text: species, form_goal, now, main, actions[], multi_year[], site_constraints[], checks_on_site[], client_summary, crew_checklist[]',
  anchors_usable     TINYINT(1)    NOT NULL DEFAULT 1 COMMENT '0 = show the numbered list only, no badges on the photo',
  status             VARCHAR(12)   NOT NULL DEFAULT 'draft' COMMENT 'draft | approved | scheduled | done | rejected | failed',
  model              VARCHAR(40)   NULL,
  cost_usd           DECIMAL(8,4)  NULL,
  error              VARCHAR(255)  NULL,
  created_by         INT           NULL,
  created_at         DATETIME      NOT NULL,
  approved_by        INT           NULL,
  approved_at        DATETIME      NULL,
  job_plan_id        INT           NULL     COMMENT 'job_plans.id once scheduled',
  quote_id           INT           NULL     COMMENT 'quotes.id once quoted',
  after_media_ids    VARCHAR(100)  NULL     COMMENT 'the after photos from the visit that did the work',
  updated_at         DATETIME      NULL,
  KEY idx_pwp_property (property_id, status),
  KEY idx_pwp_plant (plant_record_id),
  KEY idx_pwp_job (job_plan_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
