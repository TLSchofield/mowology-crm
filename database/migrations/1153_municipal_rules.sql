-- Migration 1153: Municipal rules for Otto the Dispatcher — equipment hours by city
-- Date: 2026-10-05
-- Purpose: when power equipment and leaf blowers may run, per municipality (matched to
--   properties.city) and per area inside one (matched by postal-code prefix, e.g. the
--   West End). Otto flags scheduled visits that fall outside the allowed hours. He never
--   moves anything himself. Rows not checked against the by-law text are 'unverified'
--   and only produce low-priority suggestions that say so.
-- Seeded from the City of Vancouver's power-equipment page (by-law 6555 PDF not yet read),
--   Richmond's general noise "daytime" (bylaw 8856, not landscaping-specific), and an empty
--   Burnaby row for Tim. Statutory holidays are computed in code (DispatchRules::bcHolidays).
-- MySQL 5.7 compatible.

CREATE TABLE IF NOT EXISTS municipal_rules (
  id INT AUTO_INCREMENT PRIMARY KEY,
  municipality VARCHAR(80) NOT NULL COMMENT 'Matches properties.city, case-insensitive',
  area VARCHAR(80) NULL COMMENT 'NULL = whole municipality; else municipal_areas.name',
  kind ENUM('hours','ban','note') NOT NULL DEFAULT 'hours' COMMENT 'note = shown, never enforced',
  equipment_class VARCHAR(20) NOT NULL DEFAULT 'power_equipment' COMMENT 'power_equipment | leaf_blower | all',
  power_source VARCHAR(10) NOT NULL DEFAULT 'any' COMMENT 'any | gas',
  day_type VARCHAR(16) NOT NULL DEFAULT 'any' COMMENT 'weekday | saturday | sunday_holiday | any',
  allowed_start TIME NULL,
  allowed_end TIME NULL,
  near_homes_m INT NULL COMMENT 'Rule applies within this many metres of homes',
  status VARCHAR(24) NOT NULL DEFAULT 'unverified' COMMENT 'verified | unverified | confirm_current_bylaw',
  source_url VARCHAR(255) NULL,
  note VARCHAR(500) NULL,
  confirmed_by INT NULL,
  confirmed_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_mr_city (municipality, day_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS municipal_areas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL,
  municipality VARCHAR(80) NOT NULL,
  fsa_prefixes VARCHAR(255) NOT NULL COMMENT 'Comma list of postal-code starts, e.g. V6E,V6G',
  status VARCHAR(24) NOT NULL DEFAULT 'unverified',
  note VARCHAR(500) NULL,
  UNIQUE KEY uq_ma_name (municipality, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO municipal_rules (municipality, area, kind, equipment_class, power_source, day_type, allowed_start, allowed_end, near_homes_m, status, source_url, note) VALUES
('Vancouver', NULL, 'hours', 'power_equipment', 'any', 'weekday',        '07:00', '22:00', NULL, 'unverified', 'https://vancouver.ca/home-property-development/power-equipment.aspx', 'Noise Control By-law 6555. Mowers, trimmers, edgers etc.; chainsaws excluded. Confirm against the by-law PDF.'),
('Vancouver', NULL, 'hours', 'power_equipment', 'any', 'saturday',       '07:00', '22:00', NULL, 'unverified', 'https://vancouver.ca/home-property-development/power-equipment.aspx', 'By-law 6555.'),
('Vancouver', NULL, 'hours', 'power_equipment', 'any', 'sunday_holiday', '10:00', '22:00', NULL, 'unverified', 'https://vancouver.ca/home-property-development/power-equipment.aspx', 'By-law 6555.'),
('Vancouver', NULL, 'hours', 'leaf_blower',     'any', 'weekday',        '08:00', '18:00', 50,   'unverified', 'https://vancouver.ca/home-property-development/power-equipment.aspx', 'Leaf blowers within 50 m of residential premises.'),
('Vancouver', NULL, 'hours', 'leaf_blower',     'any', 'saturday',       '09:00', '17:00', 50,   'unverified', 'https://vancouver.ca/home-property-development/power-equipment.aspx', 'Leaf blowers within 50 m of residential premises.'),
('Vancouver', NULL, 'ban',   'leaf_blower',     'any', 'sunday_holiday', NULL,    NULL,    50,   'unverified', 'https://vancouver.ca/home-property-development/power-equipment.aspx', 'No leaf blowers on Sundays or holidays within 50 m of homes.'),
('Vancouver', 'West End', 'ban', 'leaf_blower', 'gas', 'any',            NULL,    NULL,    NULL, 'unverified', 'https://vancouver.ca/home-property-development/power-equipment.aspx', 'West End blower ban. News reports say gas blowers; confirm whether battery blowers are also banned, and the boundary.'),
('Vancouver', NULL, 'note',  'all',             'gas', 'any',            NULL,    NULL,    NULL, 'confirm_current_bylaw', 'https://www.turfandrec.com/vancouver-moves-closer-to-banning-gas-powered-landscaping-equipment/', 'Council motion (2021) to phase out gas landscaping equipment; Metro Vancouver small-engine work. Confirm the current bylaw — not enforced.'),
('Richmond',  NULL, 'hours', 'power_equipment', 'any', 'weekday',        '07:00', '20:00', NULL, 'unverified', 'https://www.richmond.ca/city-hall/bylaws/property/noise.htm', 'Noise Regulation Bylaw 8856 general "daytime"; not landscaping-specific. Confirm.'),
('Richmond',  NULL, 'hours', 'power_equipment', 'any', 'saturday',       '07:00', '20:00', NULL, 'unverified', 'https://www.richmond.ca/city-hall/bylaws/property/noise.htm', 'Bylaw 8856 general "daytime". Confirm.'),
('Richmond',  NULL, 'hours', 'power_equipment', 'any', 'sunday_holiday', '10:00', '18:00', NULL, 'unverified', 'https://www.richmond.ca/city-hall/bylaws/property/noise.htm', 'Bylaw 8856 general "daytime". Confirm.'),
('Burnaby',   NULL, 'hours', 'power_equipment', 'any', 'weekday',        NULL,    NULL,    NULL, 'unverified', 'https://www.burnaby.ca/node/206', 'Noise Bylaw 7332 covers lawn mowing; hours not found yet — Tim fills.'),
('Burnaby',   NULL, 'hours', 'power_equipment', 'any', 'saturday',       NULL,    NULL,    NULL, 'unverified', 'https://www.burnaby.ca/node/206', 'Tim fills.'),
('Burnaby',   NULL, 'hours', 'power_equipment', 'any', 'sunday_holiday', NULL,    NULL,    NULL, 'unverified', 'https://www.burnaby.ca/node/206', 'Tim fills.');

INSERT IGNORE INTO municipal_areas (name, municipality, fsa_prefixes, status, note) VALUES
('West End', 'Vancouver', 'V6E,V6G', 'unverified', 'First cut by postal code. V6E also covers part of Coal Harbour — confirm, or replace with a map outline later.');
