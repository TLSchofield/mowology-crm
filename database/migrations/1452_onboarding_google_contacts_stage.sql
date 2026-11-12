CREATE TABLE IF NOT EXISTS onboarding_google_contacts_stage (
    nonce CHAR(32) NOT NULL PRIMARY KEY,
    user_id INT NOT NULL,
    grant_hash CHAR(64) NOT NULL,
    payload MEDIUMTEXT NOT NULL,
    created_at DATETIME NOT NULL,
    KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
