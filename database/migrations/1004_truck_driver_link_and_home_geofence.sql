-- Migration 1004: Truck-driver link + home geofence for forgotten clock-out detection
--
-- Adds:
--   users.assigned_truck_user_id  — driver row → their assigned truck user row
--   users.home_lat / home_lng     — employee home coordinates for forgot-to-clock-out SMS
--   users.home_radius_meters      — geofence radius around home (default 250m)

-- Truck-driver link: on the DRIVER's row, points to the truck device user
ALTER TABLE `users`
  ADD COLUMN `assigned_truck_user_id` INT NULL DEFAULT NULL
    COMMENT 'FK to users.id where device_type=truck — auto-clocks out truck when driver clocks out'
  AFTER `device_type`;

ALTER TABLE `users`
  ADD COLUMN `home_lat` DECIMAL(10,6) NULL DEFAULT NULL
    COMMENT 'Home latitude for forgot-to-clock-out geofence detection'
  AFTER `assigned_truck_user_id`,
  ADD COLUMN `home_lng` DECIMAL(10,6) NULL DEFAULT NULL
    COMMENT 'Home longitude for forgot-to-clock-out geofence detection'
  AFTER `home_lat`,
  ADD COLUMN `home_radius_meters` INT NOT NULL DEFAULT 250
    COMMENT 'Geofence radius in meters — employee is considered "home" when GPS is within this distance'
  AFTER `home_lng`;

-- Track whether forgot-to-clock-out SMS was sent for this shift
ALTER TABLE `time_clock_entries`
  ADD COLUMN `forgot_sms_sent_at` DATETIME NULL DEFAULT NULL
    COMMENT 'When the forgot-to-clock-out SMS was sent for this shift (NULL = not yet sent)'
  AFTER `notes`;

-- ops_settings entry to control the home-detection SMS feature
INSERT INTO `ops_settings` (`key`, `value`, `description`)
VALUES ('forgot_clockout_sms_enabled', '1', 'Send SMS reminder when employee GPS is near home but still clocked in')
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`);
