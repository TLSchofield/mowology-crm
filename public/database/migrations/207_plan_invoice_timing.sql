-- Migration 207: Add invoice_timing to job_plans
-- Controls when invoices are generated for recurring plan visits
-- after_visit: Invoice after each completed visit
-- end_of_month: Group all visits and invoice at month end
-- upfront: Invoice sent before service (prepay)

ALTER TABLE job_plans
    ADD COLUMN invoice_timing ENUM('after_visit', 'end_of_month', 'upfront') NOT NULL DEFAULT 'after_visit'
    AFTER pricing_model;
