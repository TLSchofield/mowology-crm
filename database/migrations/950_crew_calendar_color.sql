-- Migration 950: Add calendar_color to users for schedule crew color coding
-- Each crew member gets a hex color used for stop card borders and avatars on the schedule.
ALTER TABLE users ADD COLUMN calendar_color VARCHAR(7) NULL DEFAULT NULL;
