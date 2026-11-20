-- 994_expense_ocr_jobs.sql
-- Async OCR queue for receipt uploads (Phase 3 High).
--
-- Receipt-upload endpoints enqueue a 'pending' row here instead of running
-- Tesseract / Google Vision inline (which took 6-15s per upload). A cron
-- (process_ocr_queue.php, every 1 minute) drains the queue and writes the
-- parsed receipt fields back to this row. Mobile clients poll
-- receipt-ocr-status.php to retrieve the result.
--
-- Schema notes:
--   * status flow: pending → processing → (complete | failed)
--   * attempts is incremented on each claim; >= 3 → terminal 'failed'
--   * parsed_json stores the full structured ReceiptIntake response
--     (parsed object, suggestions, field_confidences, gst_validation, etc.)
--     The headline parsed_vendor / parsed_total / parsed_date columns
--     duplicate the most-queried fields for cheap list-view rendering.
--   * No FK on media_id — media_assets.id is INT UNSIGNED but we rely on
--     application-level integrity (the upload endpoint inserts media_assets
--     immediately before this row, in the same request).

CREATE TABLE expense_ocr_jobs (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    media_id        INT UNSIGNED NOT NULL,
    expense_id      INT UNSIGNED NULL,
    user_id         INT UNSIGNED NOT NULL,
    status          ENUM('pending','processing','complete','failed') NOT NULL DEFAULT 'pending',
    parsed_vendor   VARCHAR(255) NULL,
    parsed_total    DECIMAL(10,2) NULL,
    parsed_date     DATE NULL,
    parsed_raw_text MEDIUMTEXT NULL,
    parsed_json     MEDIUMTEXT NULL,
    error           TEXT NULL,
    attempts        TINYINT UNSIGNED NOT NULL DEFAULT 0,
    started_at      DATETIME NULL,
    completed_at    DATETIME NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_status_created (status, created_at),
    KEY idx_media          (media_id),
    KEY idx_user           (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
