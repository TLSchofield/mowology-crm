-- 1370 — receipt_capture_keys (2026-10-16, Android native receipts 1.7.0)
--
-- A phone sends each receipt with its own reference (client_ref, made on the phone when the photo is taken).
-- When the answer is lost and the phone sends the receipt again, the server finds the reference and answers
-- with the expense already made, instead of storing the photo twice and making a second expense
-- (ReceiptCaptureService::capture). One row per person + reference.

CREATE TABLE IF NOT EXISTS receipt_capture_keys (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    client_ref VARCHAR(64) NOT NULL,
    media_id INT NULL,
    expense_id INT NULL,
    source VARCHAR(20) NOT NULL DEFAULT 'app',
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_user_ref (user_id, client_ref),
    INDEX idx_expense (expense_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
