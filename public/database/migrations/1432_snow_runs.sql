-- Migration 1432: salt & snow runs (owner, 2026-11-03).
-- The daily "Salt & Snow route" stops (migration 1310) used to sit in every crew schedule every day and were
-- clockable any day, so a normal maintenance visit at the same property shared its stop card with the snow
-- visit and the "What was done here?" billing step fired during ordinary work. From now on a route stop is
-- only visible and startable while a run is open: one row here per run, opened from the schedule by the
-- office or the winter crew (ops_settings snow_route_crew_user_ids), closed by hand or after 20 hours.
-- Kept forever as part of the liability trail (who called the run, when), next to the per-visit GPS/photo proof.
CREATE TABLE IF NOT EXISTS snow_runs (
    id          INT          NOT NULL AUTO_INCREMENT,
    kind        VARCHAR(10)  NOT NULL DEFAULT 'salt' COMMENT 'salt | snow',
    started_at  DATETIME     NOT NULL,
    started_by  INT          NOT NULL COMMENT 'users.id who opened the run',
    ended_at    DATETIME     NULL,
    ended_by    INT          NULL,
    note        VARCHAR(255) NULL,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_snow_runs_open (ended_at, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Salt & snow runs: route stops show and start only while one is open';
