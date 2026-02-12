-- Migration 203: Add weather SMS alert toggle to users table
-- Allows team members to opt in/out of receiving weather alert SMS messages

ALTER TABLE users
  ADD COLUMN receive_weather_sms TINYINT(1) NOT NULL DEFAULT 1
  AFTER location_tracking_enabled;
