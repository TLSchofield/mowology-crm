-- Migration 1368: the plant care library (2026-10-15)
-- Plan: docs/plans/2026-10-15-plant-id-to-bed-care.md (step 4)
--   plant_care_cards — the library: one card per species (or a "general" card per genus), keyed by the GBIF
--                      accepted species id so every name for a plant finds it (Pl@ntNet said "Dendranthema
--                      grandiflorum", GBIF 3091555 — a synonym of Chrysanthemum × morifolium, 3150767).
--                      Care tasks with month windows for the coast, toxicity, sun, soil, size; written by
--                      Claude in its own words from trusted gardening sources (links kept in `sources`);
--                      approved or edited by the owner once, then shown on every property with the plant.
--                      Universal knowledge (no site_id); `climate` keeps other regions apart later.
--   plant_name_keys  — cache of GBIF name lookups: any name → accepted species id, accepted name, genus.
-- MySQL 5.7 compatible; safe to re-run.

CREATE TABLE IF NOT EXISTS plant_care_cards (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  scientific_name  VARCHAR(160)  NOT NULL COMMENT 'accepted name (species), or the genus for a general card',
  climate          VARCHAR(40)   NOT NULL DEFAULT 'pnw-coastal' COMMENT 'Pacific Northwest coast (Vancouver, zone 8b)',
  level            VARCHAR(8)    NOT NULL DEFAULT 'species' COMMENT 'species | genus',
  gbif_key         INT           NULL     COMMENT 'GBIF accepted usage key (species level)',
  genus            VARCHAR(80)   NULL,
  plant_group      VARCHAR(20)   NULL     COMMENT 'hedge | shrub | perennial | grass | tree | groundcover | bulb | vine | annual',
  common_name      VARCHAR(160)  NULL     COMMENT 'the name the owner wants shown (e.g. Garden mum)',
  card             TEXT          NULL     COMMENT 'JSON text: {tasks:[{task, from, to, how}], toxic_pets, toxic_note, sun, soil, size, hardiness, notes}',
  sources          TEXT          NULL     COMMENT 'JSON text: [{url, title}] the pages the facts came from',
  status           VARCHAR(10)   NOT NULL DEFAULT 'queued' COMMENT 'queued | draft | approved | rejected | failed',
  origin           VARCHAR(12)   NOT NULL DEFAULT 'library' COMMENT 'library (starter list) | identified (a crew found it)',
  batch_id         VARCHAR(80)   NULL     COMMENT 'Anthropic message batch that drafted it',
  model            VARCHAR(40)   NULL,
  searches         INT           NOT NULL DEFAULT 0,
  cost_usd         DECIMAL(8,4)  NULL,
  drafted_by       INT           NULL,
  drafted_at       DATETIME      NULL,
  approved_by      INT           NULL,
  approved_at      DATETIME      NULL,
  created_at       DATETIME      NULL,
  updated_at       DATETIME      NULL,
  UNIQUE KEY uq_pcc_name (scientific_name, climate, level),
  KEY idx_pcc_gbif (gbif_key),
  KEY idx_pcc_genus (genus, level),
  KEY idx_pcc_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS plant_name_keys (
  name             VARCHAR(160)  NOT NULL PRIMARY KEY COMMENT 'lower-cased name as asked',
  gbif_key         INT           NULL     COMMENT 'accepted usage key (NULL = GBIF had no confident match)',
  accepted_name    VARCHAR(200)  NULL,
  genus            VARCHAR(80)   NULL,
  family           VARCHAR(80)   NULL,
  looked_up_at     DATETIME      NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
