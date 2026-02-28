-- Migration 119: Add min_price column to products table
-- Allows setting a minimum sell price floor for each product/service
-- Used to prevent quoting below a threshold

ALTER TABLE products ADD COLUMN min_price DECIMAL(10,2) DEFAULT NULL AFTER base_price;
