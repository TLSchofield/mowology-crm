-- 1112_etransfer_notifications_source.sql
-- Let etransfer_notifications hold rows from a second inbox poller — Yardi/Tribe
-- property-management "EFT Payment" remittance emails (DoNotReply@yardi.com,
-- Reply-To apqueries@tribemgmt.com) — alongside the existing Interac e-Transfer
-- rows, so the "Pending e-Transfers" panel on /crm/invoices and the recording
-- flow (EtransferInboxService::recordPayment / InvoiceReconciliationService)
-- are reused as-is rather than building a parallel table + UI.
--
-- Unlike Interac transfers, a Yardi remittance email gives the exact invoice
-- number per line, so most rows never reach this table at all — the poller
-- auto-records them immediately (see YardiEftInboxService::ingest) and only
-- falls back to a pending row when an invoice number doesn't resolve or the
-- amount doesn't exactly match the invoice balance.
--
-- Run on production via a run-migration script (tryExec pattern), like 1110.

ALTER TABLE etransfer_notifications
  ADD COLUMN source VARCHAR(20) NOT NULL DEFAULT 'interac' AFTER mailbox,
  ADD INDEX idx_source (source);
