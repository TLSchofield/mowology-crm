-- Migration 208: Add invoice_timing to contracts
-- Moves billing trigger (when to send invoices) to the contract level.
-- Plans under a contract inherit this setting; standalone plans keep their own.
-- after_visit: Invoice after each completed visit
-- end_of_month: Group all visits into one invoice at month end
-- upfront: Invoice sent before service begins (prepay)

ALTER TABLE contracts
    ADD COLUMN invoice_timing ENUM('after_visit', 'end_of_month', 'upfront') NOT NULL DEFAULT 'after_visit'
    AFTER billing_amount;
