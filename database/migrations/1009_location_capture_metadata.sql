-- Migration 1009: Per-ping client capture time + richer location metadata
--
-- Context: the iOS app's GPS ping loop posts to /api/schedule/location.
-- Until now the server stamped every row with `timestamp = NOW()` on
-- INSERT. That worked for live pings but produced time-warped trails
-- whenever the iOS PingQueue drained an offline backlog — 100 queued
-- pings all stamped within seconds of each other on drain, even when
-- they were captured over hours of offline work.
--
-- This migration adds:
--
--   client_timestamp  DATETIME — when CLLocation actually captured the
--                                fix on-device. iOS now sends this with
--                                every ping (live AND drained queue). Use
--                                COALESCE(client_timestamp, timestamp) for
--                                chronological queries; use `timestamp`
--                                alone for "when did the server hear about
--                                this ping" diagnostics.
--
--   speed     FLOAT — m/s, optional. CLLocation reports negative when
--                     invalid; client converts to NULL in that case.
--
--   course    FLOAT — heading in degrees, optional. Same negative-means-
--                     unknown convention as speed.
--
--   altitude  FLOAT — metres above sea level, optional. Always sent when
--                     a fix exists; may be coarse without a barometer.
--
-- All new columns are NULLABLE so older iOS clients (no extra fields)
-- continue to INSERT successfully — backwards-compatible additive change.

ALTER TABLE crew_location_history
    ADD COLUMN client_timestamp DATETIME NULL AFTER timestamp,
    ADD COLUMN speed            FLOAT    NULL AFTER client_timestamp,
    ADD COLUMN course           FLOAT    NULL AFTER speed,
    ADD COLUMN altitude         FLOAT    NULL AFTER course;
