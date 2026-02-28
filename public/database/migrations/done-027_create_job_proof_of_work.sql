-- Migration 027: Job Proof of Work Table
-- Purpose: Track completion requirements (checklist, photos, GPS) for each job
-- Date: February 8, 2026

CREATE TABLE IF NOT EXISTS job_proof_of_work (
  id INT AUTO_INCREMENT PRIMARY KEY,
  job_id INT NOT NULL UNIQUE,

  -- Requirements (copied from service_package at job creation)
  required_checklist_items JSON,
  required_photo_types JSON,
  gps_enforcement VARCHAR(20) DEFAULT 'optional',

  -- What blocks job completion
  checklist_blocks_completion BOOLEAN DEFAULT FALSE,
  photos_block_completion BOOLEAN DEFAULT FALSE,
  gps_blocks_completion BOOLEAN DEFAULT FALSE,

  -- Actual completion status
  checklist_items_completed JSON,
  checklist_completed_at TIMESTAMP NULL,
  checklist_completed_by INT NULL,

  photos_uploaded JSON,
  photos_completed_at TIMESTAMP NULL,

  gps_arrival_lat DECIMAL(10, 8) NULL,
  gps_arrival_lng DECIMAL(11, 8) NULL,
  gps_departure_lat DECIMAL(10, 8) NULL,
  gps_departure_lng DECIMAL(11, 8) NULL,
  gps_confirmed_at TIMESTAMP NULL,

  -- Overall completion status
  is_complete BOOLEAN DEFAULT FALSE,
  completed_at TIMESTAMP NULL,
  completion_notes TEXT,

  -- Timestamps
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE CASCADE,
  FOREIGN KEY (checklist_completed_by) REFERENCES users(id) ON DELETE SET NULL,
  KEY idx_job (job_id),
  KEY idx_is_complete (is_complete)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
