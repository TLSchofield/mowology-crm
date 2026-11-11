-- 1373 - Penny onboarding step 1: tenant-owned business identity
-- One row per tenant database. Registry facts stay local and are never copied to the knowledge commons.

CREATE TABLE IF NOT EXISTS business_identity (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    entered_name VARCHAR(120) NULL,
    legal_name VARCHAR(255) NULL,
    operating_name VARCHAR(255) NULL,
    registration_number VARCHAR(80) NULL,
    registration_type VARCHAR(40) NULL,
    legal_structure VARCHAR(120) NULL,
    jurisdiction VARCHAR(120) NULL,
    registration_status VARCHAR(40) NULL,
    source VARCHAR(20) NULL,
    source_record_id VARCHAR(120) NULL,
    registry_business_number VARCHAR(40) NULL COMMENT 'CRA BN reported by registry; never display as BC registration number',
    registry_verified_at DATETIME NULL,
    owner_confirmed_at DATETIME NULL,
    entered_name_provenance VARCHAR(20) NULL,
    legal_name_provenance VARCHAR(20) NULL,
    operating_name_provenance VARCHAR(20) NULL,
    registration_number_provenance VARCHAR(20) NULL,
    registration_type_provenance VARCHAR(20) NULL,
    legal_structure_provenance VARCHAR(20) NULL,
    jurisdiction_provenance VARCHAR(20) NULL,
    registration_status_provenance VARCHAR(20) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by INT NULL,
    CONSTRAINT chk_business_identity_singleton CHECK (id = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
