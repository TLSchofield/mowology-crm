/**
 * Migration: Add ROI Pipeline Fields
 * Date: 2026-02-07
 * Purpose: Connect quote lifecycle to ROI tracking, add prospect/client classification
 */

-- ============================================================
-- 1. Add lead_event_id to quote_requests (track source lead)
-- ============================================================
ALTER TABLE quote_requests
ADD COLUMN lead_event_id INT DEFAULT NULL AFTER id,
ADD KEY idx_quote_requests_lead_event (lead_event_id),
ADD CONSTRAINT fk_quote_requests_lead_event FOREIGN KEY (lead_event_id) REFERENCES lead_events(id) ON DELETE SET NULL;

-- ============================================================
-- 2. Add lead_event_id to quotes (preserve attribution chain)
-- ============================================================
ALTER TABLE quotes
ADD COLUMN lead_event_id INT DEFAULT NULL AFTER company_id,
ADD KEY idx_quotes_lead_event (lead_event_id),
ADD CONSTRAINT fk_quotes_lead_event FOREIGN KEY (lead_event_id) REFERENCES lead_events(id) ON DELETE SET NULL;

-- ============================================================
-- 3. Add prospect_status to contacts (classification)
-- ============================================================
ALTER TABLE contacts
ADD COLUMN prospect_status ENUM('prospect','client','inactive') DEFAULT 'prospect' AFTER is_active,
ADD COLUMN lifecycle_stage ENUM('lead','opportunity','client','won','lost') DEFAULT 'lead' AFTER prospect_status,
ADD COLUMN first_quote_date DATETIME DEFAULT NULL AFTER lifecycle_stage,
ADD COLUMN first_job_date DATETIME DEFAULT NULL AFTER first_quote_date,
ADD COLUMN total_lifetime_value DECIMAL(12,2) DEFAULT 0 AFTER first_job_date,
ADD KEY idx_contacts_prospect_status (prospect_status),
ADD KEY idx_contacts_lifecycle_stage (lifecycle_stage);

-- ============================================================
-- 4. Add conversion event tracking for quote stages
-- ============================================================
-- Note: conversion_events table already supports quote_sent, quote_accepted via event_type enum
-- Entity lifecycle:
--   lead_events (initial visit)
--   -> quote_requests (quote_request conversion_event)
--   -> quotes (quote_sent, quote_accepted conversion_events)
--   -> jobs (job_created, job_completed conversion_events)

COMMIT;
