-- Migration 902: Add stripe_customer_id to contacts and companies
-- Enables "save card on file" via Stripe Customer objects.
-- Run once on production. Safe to re-run (column check via IF NOT EXISTS pattern).

ALTER TABLE contacts
    ADD COLUMN stripe_customer_id VARCHAR(100) NULL DEFAULT NULL AFTER notes,
    ADD COLUMN stripe_card_brand   VARCHAR(20)  NULL DEFAULT NULL AFTER stripe_customer_id,
    ADD COLUMN stripe_card_last4   VARCHAR(4)   NULL DEFAULT NULL AFTER stripe_card_brand,
    ADD COLUMN stripe_card_exp     VARCHAR(7)   NULL DEFAULT NULL AFTER stripe_card_last4;

CREATE INDEX idx_contacts_stripe_customer ON contacts (stripe_customer_id);
