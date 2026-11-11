-- Migration 1312: Penny's deposit alerts for claim-type e-Transfers (2026-10-08).
-- A "claim your deposit" e-Transfer sits at Interac until someone deposits it, and expires.
-- Penny keeps the deposit link + expiry from the email and pushes the owner until it's in.
ALTER TABLE etransfer_notifications
    ADD COLUMN deposit_url      VARCHAR(1024) NULL,
    ADD COLUMN expires_on       DATE NULL,
    ADD COLUMN claim_alerted_at DATETIME NULL,
    ADD COLUMN claim_reminded   TINYINT NOT NULL DEFAULT 0,
    ADD COLUMN deposited_at     DATETIME NULL;

INSERT INTO ops_settings (setting_key, setting_value, description)
VALUES ('penny_claim_alerts_enabled', '1', 'Penny: push the owner when an e-Transfer needs claiming (deposit link + expiry), with reminders 7 and 2 days before it expires (1 = on, 0 = off)')
ON DUPLICATE KEY UPDATE description = VALUES(description);

-- Transfers already in the inbox were handled by hand before this existed: no alerts for them.
UPDATE etransfer_notifications SET claim_alerted_at = NOW(), claim_reminded = 2 WHERE transfer_type = 'claim';
