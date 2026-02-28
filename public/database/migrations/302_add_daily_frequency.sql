-- Add 'daily' to product_pricing_rules.default_frequency enum
ALTER TABLE product_pricing_rules
  MODIFY COLUMN default_frequency enum('one_off','daily','7_day','14_day','21_day','monthly','seasonal') DEFAULT 'one_off';
