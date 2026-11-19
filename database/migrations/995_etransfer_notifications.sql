-- 995_etransfer_notifications.sql
-- Inbox of parsed Interac e-Transfer notification emails (info@ + office@).
-- A cron poller (app/Modules/Accounting/Cron/etransfer_inbox_poll.php) reads the
-- mailbox over IMAP, parses each Interac email, tries to match it to an open
-- invoice, and drops a row here. Staff confirm/record from the "Pending
-- e-Transfers" panel on /crm/invoices (notify + one-click confirm — never
-- auto-records, because the e-Transfer amount can differ from the invoice
-- total, e.g. the customer adds GST).

CREATE TABLE etransfer_notifications (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    mailbox             VARCHAR(64)     NOT NULL,                 -- info@mowology.ca / office@mowology.ca
    dedup_key           VARCHAR(255)    NOT NULL,                 -- Interac reference # (preferred) or Message-ID
    reference_number    VARCHAR(64)     DEFAULT NULL,             -- Interac "Reference Number"
    message_id          VARCHAR(255)    DEFAULT NULL,             -- email Message-ID
    sender_name         VARCHAR(160)    DEFAULT NULL,             -- "Sent From"
    amount              DECIMAL(10,2)   DEFAULT NULL,             -- "Amount: $X (CAD)"
    memo                TEXT            DEFAULT NULL,             -- customer "Message"
    invoice_hint        VARCHAR(32)     DEFAULT NULL,             -- invoice # parsed from the memo
    transfer_type       VARCHAR(20)     NOT NULL DEFAULT 'unknown', -- autodeposit / claim / unknown
    email_subject       VARCHAR(255)    DEFAULT NULL,
    email_date          DATETIME        DEFAULT NULL,
    matched_invoice_id  INT             DEFAULT NULL,
    match_method        VARCHAR(20)     DEFAULT NULL,             -- invoice_number / amount / none
    match_confidence    VARCHAR(10)     DEFAULT NULL,             -- high / medium / none
    status              VARCHAR(20)     NOT NULL DEFAULT 'pending', -- pending / recorded / dismissed
    recorded_invoice_id INT             DEFAULT NULL,
    recorded_by         INT             DEFAULT NULL,
    created_at          DATETIME        NOT NULL,
    processed_at        DATETIME        DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_dedup (dedup_key),
    KEY idx_status (status),
    KEY idx_matched_invoice (matched_invoice_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
