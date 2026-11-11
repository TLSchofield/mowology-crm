-- Migration 1310: snow & salt contracts that set themselves up when signed (2026-10-08).
--
-- A signed snow/salt contract quote becomes: one per-visit contract + one DAILY
-- route plan per building (a stop every day Nov 1 - Mar 31). The crew records what
-- was actually done at each stop (salt / arctic salt / snow cleared / nothing
-- needed) and the run is invoiced at THAT rate only — never the sum of the rates.
--
-- snow_route_rates       which plan line is which rate (salt/arctic/snow/priority/areas)
-- visit_service_choices  what the crew did at one stop
-- snow_contract_setups   one row per signed quote: idempotency + what was created / why it failed

CREATE TABLE IF NOT EXISTS snow_route_rates (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    plan_id           INT NOT NULL,
    role              VARCHAR(20) NOT NULL,
    label             VARCHAR(150) NOT NULL,
    plan_line_item_id INT NULL,
    unit_price        DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_snow_route_rates_plan (plan_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS visit_service_choices (
    visit_id   INT NOT NULL PRIMARY KEY,
    choice     VARCHAR(20) NOT NULL,
    chosen_by  INT NULL,
    source     VARCHAR(20) NOT NULL DEFAULT 'crew',
    chosen_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS snow_contract_setups (
    quote_id    INT NOT NULL PRIMARY KEY,
    status      VARCHAR(20) NOT NULL,
    contract_id INT NULL,
    plan_id     INT NULL,
    detail      TEXT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO ops_settings (setting_key, setting_value, description)
VALUES ('snow_contract_autosetup_enabled', '1', 'Snow & salt: when a snow contract quote is signed online, create the contract + daily route plan automatically (1 = on, 0 = off)')
ON DUPLICATE KEY UPDATE description = VALUES(description);
