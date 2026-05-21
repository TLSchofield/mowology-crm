-- 1037_company_stripe.sql
-- Add Stripe payment columns to companies for company-level autopay.
-- Invoices linked to a company will use the company card; personal/contact-only
-- invoices continue to use the contact card. No existing data is changed.

ALTER TABLE companies
    ADD COLUMN stripe_customer_id       VARCHAR(100)  NULL DEFAULT NULL,
    ADD COLUMN stripe_payment_method_id VARCHAR(100)  NULL DEFAULT NULL,
    ADD COLUMN stripe_card_brand        VARCHAR(20)   NULL DEFAULT NULL,
    ADD COLUMN stripe_card_last4        VARCHAR(4)    NULL DEFAULT NULL,
    ADD COLUMN stripe_card_exp          VARCHAR(7)    NULL DEFAULT NULL,
    ADD COLUMN autopay_enabled          TINYINT(1)    NOT NULL DEFAULT 0,
    ADD COLUMN autopay_enrolled_at      TIMESTAMP     NULL DEFAULT NULL;
