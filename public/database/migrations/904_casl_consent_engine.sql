-- =====================================================
-- 904 — CASL Consent Engine
-- =====================================================
-- Adds per-channel express/implied consent timestamps
-- to contacts and admin attribution to consent_log.
-- Run via: CRM > Database > Migrations
-- =====================================================

-- Add express consent timestamps to contacts
-- (separate columns so we track per-channel independently)
ALTER TABLE contacts
    ADD COLUMN consent_email_express_at TIMESTAMP NULL DEFAULT NULL
        COMMENT 'When express email marketing consent was last given (or NULL if not given / revoked)',
    ADD COLUMN consent_sms_express_at   TIMESTAMP NULL DEFAULT NULL
        COMMENT 'When express SMS consent was last given (or NULL if not given / revoked)',
    ADD COLUMN consent_email_implied_at TIMESTAMP NULL DEFAULT NULL
        COMMENT 'Last transaction date — 2-year implied consent clock (set on invoice paid)';

-- Add admin attribution to consent_log
-- (NULL = captured via web form / automated; non-NULL = CRM user who toggled it)
ALTER TABLE consent_log
    ADD COLUMN updated_by_user_id INT NULL DEFAULT NULL
        COMMENT 'CRM user who made this consent change (NULL for web form / jobFlow)';

-- Backfill: set consent_email_express_at for contacts that already have receive_marketing = 1
-- Uses consent_timestamp if available, otherwise NOW() as a safe default
UPDATE contacts
SET consent_email_express_at = COALESCE(consent_timestamp, NOW())
WHERE receive_marketing = 1
  AND consent_email_express_at IS NULL;

-- Backfill: set consent_sms_express_at for contacts that already have receive_sms = 1
UPDATE contacts
SET consent_sms_express_at = COALESCE(consent_timestamp, NOW())
WHERE receive_sms = 1
  AND consent_sms_express_at IS NULL;

-- Backfill: set consent_email_implied_at from most recent paid invoice per contact
UPDATE contacts c
INNER JOIN (
    SELECT contact_id, MAX(paid_at) AS last_paid
    FROM invoices
    WHERE paid_at IS NOT NULL
    GROUP BY contact_id
) inv ON inv.contact_id = c.id
SET c.consent_email_implied_at = inv.last_paid
WHERE c.consent_email_implied_at IS NULL;
