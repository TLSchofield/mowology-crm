-- 994_invoice_etransfer_payment_instructions.sql
-- Seed the customer-facing payment instructions with the Interac e-Transfer
-- details now that auto-deposit is enabled on info@mowology.ca.
--
-- This text is the single source of truth for "How to Pay":
--   - rendered on the invoice PDF (public/crm/templates/pdf/invoice.php)
--   - attached to every invoice email (public/crm/invoices/view.php)
--   - shown on the customer portal invoice page (public/customer/invoice.php)
--
-- Only fills the value when it is currently empty/NULL so any custom text a
-- staff member has already entered in Settings is never overwritten.

UPDATE business_settings
   SET invoice_payment_instructions =
       'Pay by Interac e-Transfer to info@mowology.ca. Auto-deposit is enabled — no security question or password needed. Please include your invoice number in the message so we can match your payment.'
 WHERE id = 1
   AND (invoice_payment_instructions IS NULL OR invoice_payment_instructions = '');
