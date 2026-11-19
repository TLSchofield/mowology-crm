-- Migration 1430: Mia's invoice-note suggestions — what was shown, what was tapped, what it led to,
-- and the automation rules the owner approved. No client names: ids, service words, season only.
-- MySQL 5.7 safe. (Sibling session used 1423; 1430 leaves room.)

CREATE TABLE IF NOT EXISTS mia_invoice_suggestion_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT NOT NULL DEFAULT 0,
    property_id INT NOT NULL DEFAULT 0,
    contact_id INT NOT NULL DEFAULT 0,
    library_key VARCHAR(60) NOT NULL,
    method VARCHAR(30) NOT NULL,
    wording_version INT NOT NULL DEFAULT 1,
    services VARCHAR(255) NOT NULL DEFAULT '',
    season_month TINYINT NOT NULL DEFAULT 0,
    audience VARCHAR(10) NOT NULL DEFAULT 'home',
    action VARCHAR(12) NOT NULL DEFAULT 'shown',
    outcome VARCHAR(12) NOT NULL DEFAULT 'none',
    shown_at DATETIME NOT NULL,
    acted_at DATETIME NULL,
    outcome_at DATETIME NULL,
    INDEX idx_key (library_key, id),
    INDEX idx_invoice (invoice_id),
    INDEX idx_contact (contact_id, method)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS mia_invoice_rules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    library_key VARCHAR(60) NOT NULL,
    status VARCHAR(12) NOT NULL DEFAULT 'proposed',
    shown_at_decision INT NOT NULL DEFAULT 0,
    evidence_shown INT NOT NULL DEFAULT 0,
    evidence_inserted INT NOT NULL DEFAULT 0,
    evidence_converted INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    decided_at DATETIME NULL,
    UNIQUE KEY uk_key (library_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
