-- Migration 1340: payment emails remember whether their sender was PROVEN (security review #4/#5, 2026-10-12).
-- A Yardi remittance / Interac notice is recorded without a person only when the exact sender address
-- matches AND its DKIM signature verifies (app/Services/Mail/MailAuth.php — production adds no
-- Authentication-Results header, checked 2026-10-12). 1 = proven, 0 = not proven, NULL = stored before
-- this check existed (treated as not proven: recordWhenBanked() and autoRecordFullyCertain() need 1).
-- Until this runs, nothing auto-records (fail safe). MySQL 5.7.
ALTER TABLE etransfer_notifications ADD COLUMN sender_verified TINYINT(1) NULL DEFAULT NULL
  COMMENT '1 = exact sender + verified DKIM (MailAuth); 0/NULL = held for a person';
