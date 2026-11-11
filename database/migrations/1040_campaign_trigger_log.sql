-- Migration 1040: Campaign Trigger Log (Event Bus)
-- Creates a write-side event log so any operational module can fire a named
-- campaign event without coupling to the automation engine internals.
-- The automation_runner reads unprocessed rows and evaluates matching rules.

CREATE TABLE campaign_trigger_log (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    event_name    VARCHAR(100) NOT NULL
                  COMMENT 'invoice_paid, quote_declined, photos_uploaded, lead_submitted, contract_renewal, etc.',
    entity_type   VARCHAR(50)  NOT NULL
                  COMMENT 'invoice, quote, job_visit, lead, contract',
    entity_id     INT          NOT NULL,
    contact_id    INT          NULL,
    payload       TEXT         NOT NULL COMMENT 'JSON-encoded event context at fire time',
    source_module VARCHAR(100) NOT NULL
                  COMMENT 'invoices, quotes, crew_app, jobflow, contracts',
    fired_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at  DATETIME     NULL     COMMENT 'NULL = pending; set by automation_runner after evaluating rules',
    KEY idx_event        (event_name, fired_at),
    KEY idx_contact      (contact_id, fired_at),
    KEY idx_unprocessed  (processed_at, fired_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
