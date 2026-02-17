-- Migration 601: Create calendar_stop_crew junction table for multi-crew assignment
-- Each calendar stop can have multiple crew members assigned.
-- The primary crew_id on calendar_stops remains as the "lead" crew for filtering/ordering.

CREATE TABLE IF NOT EXISTS calendar_stop_crew (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    stop_id INT NOT NULL,
    user_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_stop_user (stop_id, user_id),
    KEY idx_user_id (user_id),
    CONSTRAINT fk_csc_stop FOREIGN KEY (stop_id) REFERENCES calendar_stops(id) ON DELETE CASCADE,
    CONSTRAINT fk_csc_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Backfill existing crew assignments into the junction table
INSERT IGNORE INTO calendar_stop_crew (stop_id, user_id)
SELECT id, crew_id FROM calendar_stops WHERE crew_id IS NOT NULL;
