-- Migration 204: Add weather_policy column to products table
-- Allows each product/service to specify its weather sensitivity

ALTER TABLE products
  ADD COLUMN weather_policy VARCHAR(20) NOT NULL DEFAULT 'ANY'
  AFTER featured;
