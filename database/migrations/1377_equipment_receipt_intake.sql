-- 1377 — Otto adds machines from purchase receipts by himself (owner, 2026-10-19: "capital equipment
-- should just be put in there, he can move it out if need be").
-- One row per receipt line Otto has looked at, so a line is added once, and a line moved out
-- ("Not equipment") is never added again. EquipmentReceiptIntakeService.
CREATE TABLE IF NOT EXISTS equipment_receipt_intake (
  id INT AUTO_INCREMENT PRIMARY KEY,
  expense_id INT NOT NULL,
  line_item_id INT NOT NULL DEFAULT 0 COMMENT '0 = the whole receipt (no line items)',
  outcome VARCHAR(12) NOT NULL COMMENT 'added | moved_out',
  equipment_id INT NULL,
  line_name VARCHAR(255) NULL,
  line_total DECIMAL(10,2) NULL,
  decided_by INT NULL COMMENT 'NULL = Otto; the user who moved it out',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_eri_line (expense_id, line_item_id),
  KEY idx_eri_equipment (equipment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
