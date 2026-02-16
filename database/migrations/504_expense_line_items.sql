-- Migration 504: Expense Line Items
-- Persists parsed receipt line items and links them to products for inventory tracking.

CREATE TABLE IF NOT EXISTS expense_line_items (
    id INT NOT NULL AUTO_INCREMENT,
    expense_id INT NOT NULL,
    product_id INT NULL COMMENT 'FK to products — null until user links',
    name VARCHAR(255) NOT NULL COMMENT 'Item name from OCR or manual entry',
    quantity DECIMAL(10,3) NOT NULL DEFAULT 1.000,
    unit_price DECIMAL(10,2) NULL COMMENT 'Per-unit price (null if not parsed)',
    line_total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    sku_raw VARCHAR(100) NULL COMMENT 'Raw barcode/SKU from OCR',
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_eli_expense (expense_id),
    KEY idx_eli_product (product_id),
    CONSTRAINT fk_eli_expense FOREIGN KEY (expense_id) REFERENCES expenses(id) ON DELETE CASCADE,
    CONSTRAINT fk_eli_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
