-- Migration 1035 — Vehicle tablet GPS flag for salt report corroborating evidence
--
-- Marks a user account as a vehicle-mounted tablet so the salt report PDF
-- generator can pull its continuous crew_location_history track as a
-- backup / corroborating map page when the crew device GPS is sparse.

ALTER TABLE users ADD COLUMN is_vehicle_tablet TINYINT(1) NOT NULL DEFAULT 0
    COMMENT '1 = device is a vehicle-mounted tablet; pings go to crew_location_history continuously';

CREATE INDEX idx_users_vehicle_tablet ON users (is_vehicle_tablet);
