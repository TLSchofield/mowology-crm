-- Migration 1065: persist route pins on calendar_stops
-- Lets the day-view "pin as 1st / last stop" survive a page reload.
-- Values: 'first' | 'last' | NULL (unpinned). One first + one last per day
-- is enforced in application code (set-route-pin.php), not by a constraint.
-- Safe to run multiple times (SHOW COLUMNS check in the runner).

ALTER TABLE calendar_stops ADD COLUMN route_pin VARCHAR(5) NULL DEFAULT NULL COMMENT 'first|last route endpoint pin, NULL = unpinned';
