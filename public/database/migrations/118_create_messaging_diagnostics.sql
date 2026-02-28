-- Migration 118: Create messaging_diagnostics table
-- Stores test results from the Messaging Diagnostics module
-- Used by /crm/diagnostics/ for tracking email/SMS test history

CREATE TABLE messaging_diagnostics (
  id INT AUTO_INCREMENT PRIMARY KEY,
  test_type ENUM('mail_function','phpmailer','sms_gateway','consent_check','full_pipeline') NOT NULL,
  test_status ENUM('pass','fail','partial','error') NOT NULL,
  test_input TEXT COMMENT 'JSON-encoded input parameters',
  test_output TEXT COMMENT 'JSON-encoded result details',
  duration_ms INT COMMENT 'Execution time in milliseconds',
  error_message VARCHAR(1000),
  run_by INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_test_type (test_type),
  INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
