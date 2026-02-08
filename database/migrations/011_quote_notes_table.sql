-- Migration 011: Create quote_notes table
-- Adds support for quote-level notes/comments similar to job_notes

CREATE TABLE IF NOT EXISTS `quote_notes` (
  `id` int NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `quote_id` int NOT NULL COMMENT 'Foreign key to quotes table',
  `note_type` enum('general', 'customer_request', 'issue', 'follow_up', 'internal') NOT NULL DEFAULT 'general',
  `content` text NOT NULL COMMENT 'The note text content',
  `is_visible_to_customer` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Flag for future customer portal display',
  `created_by` int COMMENT 'User ID of note creator',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,

  CONSTRAINT `fk_quote_notes_quote_id`
    FOREIGN KEY (`quote_id`) REFERENCES `quotes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_quote_notes_user_id`
    FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,

  INDEX `idx_quote_notes_quote_id` (`quote_id`),
  INDEX `idx_quote_notes_created_at` (`created_at` DESC),
  INDEX `idx_quote_notes_type` (`note_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
COMMENT='Quote-level notes and internal comments';
