-- 1121 — Per-crew visit endorsements
-- One row per crew member who endorsed a visit. job_visits.is_flagged stays as the
-- combined "anyone endorsed" flag that reviews, the portfolio queue and social read.
-- Run via /crm/api/run-migration-1121.php (idempotent, includes the backfill).

CREATE TABLE IF NOT EXISTS visit_endorsements (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    visit_id    INT NOT NULL,
    user_id     INT NOT NULL,
    created_at  DATETIME NOT NULL,
    UNIQUE KEY uq_visit_user (visit_id, user_id),
    KEY idx_ve_user (user_id),
    CONSTRAINT fk_ve_visit FOREIGN KEY (visit_id) REFERENCES job_visits(id) ON DELETE CASCADE,
    CONSTRAINT fk_ve_user  FOREIGN KEY (user_id)  REFERENCES users(id)      ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Backfill: visits already endorsed are credited to the crew member they were assigned to.
INSERT IGNORE INTO visit_endorsements (visit_id, user_id, created_at)
SELECT jv.id, jv.assigned_crew_id, COALESCE(jv.completed_at, NOW())
FROM job_visits jv
JOIN users u ON u.id = jv.assigned_crew_id
WHERE jv.is_flagged = 1;
