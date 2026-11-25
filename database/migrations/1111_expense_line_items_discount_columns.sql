-- Migration 1111: Expense line item discount/adjustment columns
-- Lets a discount/markdown line net against its preceding product line while
-- preserving "was $X, discounted to $Y" for display, and lets true order-level
-- adjustments (no preceding product, e.g. deposits/coupons) be flagged
-- distinctly from real purchased products.

ALTER TABLE expense_line_items
    ADD COLUMN original_unit_price DECIMAL(10,2) NULL
        COMMENT 'Pre-discount unit/list price, when a discount was netted into this line'
        AFTER unit_price,
    ADD COLUMN is_adjustment TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'Standalone discount/deposit/coupon row that is not a purchasable product'
        AFTER original_unit_price;
