-- Migration 121: Add employee management fields to users table
-- Adds hourly_rate, phone, emergency_contact, hire_date for employee management

ALTER TABLE users ADD COLUMN phone VARCHAR(20) NULL AFTER full_name;
ALTER TABLE users ADD COLUMN hourly_rate DECIMAL(8,2) NULL AFTER phone;
ALTER TABLE users ADD COLUMN hire_date DATE NULL AFTER hourly_rate;
ALTER TABLE users ADD COLUMN emergency_contact VARCHAR(255) NULL AFTER hire_date;
ALTER TABLE users ADD COLUMN notes TEXT NULL AFTER emergency_contact;
