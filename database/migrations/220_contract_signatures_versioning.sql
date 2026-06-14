-- ============================================================
-- Migration 220: Contract E-Signature + Versioning
-- ============================================================
-- Adds digital signature workflow and amendment history to contracts.
-- Run via: /crm/api/run-migration-220.php

-- Add signature tracking columns to the contracts table
ALTER TABLE contracts
    ADD COLUMN signature_status ENUM('unsigned','pending','signed','declined') NOT NULL DEFAULT 'unsigned' AFTER status,
    ADD COLUMN current_version SMALLINT UNSIGNED NOT NULL DEFAULT 1 AFTER signature_status;

-- One row per signature request sent to the client.
-- A contract can have multiple (e.g., resent after decline, or new version after amendment).
CREATE TABLE contract_signatures (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    contract_id      INT NOT NULL,
    contract_version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    signature_token  VARCHAR(64) NOT NULL,
    token_expires_at DATETIME NOT NULL,
    signer_name      VARCHAR(255) DEFAULT NULL,
    signer_email     VARCHAR(255) DEFAULT NULL,
    status           ENUM('pending','signed','declined','expired') NOT NULL DEFAULT 'pending',
    signature_data   LONGTEXT DEFAULT NULL,           -- base64 PNG from SignaturePad
    signed_at        DATETIME DEFAULT NULL,
    signed_ip        VARCHAR(45) DEFAULT NULL,
    decline_reason   TEXT DEFAULT NULL,
    declined_at      DATETIME DEFAULT NULL,
    declined_ip      VARCHAR(45) DEFAULT NULL,
    sent_by          INT NOT NULL,                    -- FK users.id (not enforced to avoid cross-DB issues)
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_token (signature_token),
    KEY idx_contract (contract_id),
    KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Snapshot of contract terms at each amendment point.
-- Version 1 is created automatically when the first signature is requested.
-- Subsequent versions are created whenever a signed contract is amended.
CREATE TABLE contract_versions (
    id                   INT AUTO_INCREMENT PRIMARY KEY,
    contract_id          INT NOT NULL,
    version_number       SMALLINT UNSIGNED NOT NULL,
    changed_by           INT NOT NULL,
    change_reason        VARCHAR(500) DEFAULT NULL,
    -- Snapshot of key agreement terms at this version
    title                VARCHAR(255) DEFAULT NULL,
    billing_cycle        VARCHAR(20)  DEFAULT NULL,
    billing_amount       DECIMAL(10,2) DEFAULT NULL,
    invoice_timing       VARCHAR(20)  DEFAULT NULL,
    start_date           DATE DEFAULT NULL,
    end_date             DATE DEFAULT NULL,
    renewal_date         DATE DEFAULT NULL,
    auto_renew           TINYINT(1) DEFAULT 1,
    renewal_increase_pct DECIMAL(5,2) DEFAULT 0.00,
    notes                TEXT DEFAULT NULL,
    -- Signature status when this version was sealed
    signature_status     VARCHAR(20) DEFAULT 'unsigned',
    signed_at            DATETIME DEFAULT NULL,
    signed_by_name       VARCHAR(255) DEFAULT NULL,
    changed_at           TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_version (contract_id, version_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
