-- ============================================================================
-- Migration 1120: Wet (paper) contract signatures
-- ============================================================================
-- Migration 1119 gave contracts terms and an electronic signature that records
-- exactly what was on screen. Some clients will still want to print, sign and
-- email it back — strata councils in particular, where a signature may need a
-- meeting behind it.
--
-- A scanned page proves far less than the electronic path: no IP, no timestamp
-- from our side, no acknowledgement event. What it CAN be tied to is the terms
-- revision printed in the PDF's footer, so that is what gets recorded. Without
-- it a filed scan is a signature with no provable link to the disclaimers,
-- which is the exact gap 1119 exists to close.
--
-- The scan itself goes in the existing unified media system (migration 300):
-- media_assets for the file, media_links with context_type = 'contract' and
-- category = 'signed_copy'. No new storage table.
--
-- MySQL 5.7-safe.
-- ============================================================================

ALTER TABLE contract_signatures
    ADD COLUMN signature_method ENUM('electronic','wet') NOT NULL DEFAULT 'electronic' AFTER status,
    -- Which terms revision the PAPER copy carried, read off the PDF footer by
    -- whoever files it. Null for electronic signatures, where terms_body_hash
    -- already proves it exactly.
    ADD COLUMN wet_terms_version INT UNSIGNED DEFAULT NULL AFTER terms_body_hash,
    -- media_assets.id of the returned scan.
    ADD COLUMN wet_media_id INT DEFAULT NULL AFTER wet_terms_version,
    ADD COLUMN wet_filed_by INT DEFAULT NULL AFTER wet_media_id,
    ADD COLUMN wet_filed_at DATETIME DEFAULT NULL AFTER wet_filed_by;

CREATE INDEX idx_contract_sig_method ON contract_signatures (signature_method);
