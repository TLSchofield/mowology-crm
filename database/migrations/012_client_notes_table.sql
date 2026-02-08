-- Migration 012: Create client_notes table
-- Adds support for contact/client-level notes

CREATE TABLE IF NOT EXISTS `client_notes` (
  `id` int NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `contact_id` int NOT NULL COMMENT 'Foreign key to contacts table',
  `note_type` enum('general', 'customer_request', 'issue', 'follow_up', 'internal') NOT NULL DEFAULT 'general',
  `content` text NOT NULL COMMENT 'The note text content',
  `created_by` int COMMENT 'User ID of note creator',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,

  CONSTRAINT `fk_client_notes_contact_id`
    FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_client_notes_user_id`
    FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,

  INDEX `idx_client_notes_contact_id` (`contact_id`),
  INDEX `idx_client_notes_created_at` (`created_at` DESC),
  INDEX `idx_client_notes_type` (`note_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
COMMENT='Client/contact-level notes and internal comments';
