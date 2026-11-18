-- Migration 1381: Terms of Service acceptance (Ittai tenants)
-- Purpose: records which version of the platform Terms of Service each user accepted.
--
-- terms_versions: one row per published version. The sign-in acceptance gate is ON
-- only while a row has is_current = 1, so inserting the first row (after legal review)
-- is the switch that turns it on; deleting or clearing is_current turns it off.
-- terms_acceptances: one row per (user, version). Old rows keep the version they accepted.
--
-- Safe to re-run: CREATE TABLE IF NOT EXISTS. MySQL 5.7-compatible. No rows are seeded,
-- so the gate stays OFF until a version is activated.

CREATE TABLE IF NOT EXISTS terms_versions (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    doc_key      VARCHAR(40)  NOT NULL DEFAULT 'platform',
    version      VARCHAR(40)  NOT NULL,
    is_current   TINYINT(1)   NOT NULL DEFAULT 0,
    activated_at TIMESTAMP    NULL DEFAULT NULL,
    created_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_terms_doc_version (doc_key, version),
    INDEX idx_terms_current (doc_key, is_current)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS terms_acceptances (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT          NOT NULL,
    doc_key     VARCHAR(40)  NOT NULL DEFAULT 'platform',
    version     VARCHAR(40)  NOT NULL,
    accepted_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    ip_address  VARCHAR(45)  NULL,
    user_agent  VARCHAR(255) NULL,
    UNIQUE KEY uq_terms_accept (user_id, doc_key, version),
    INDEX idx_terms_accept_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
