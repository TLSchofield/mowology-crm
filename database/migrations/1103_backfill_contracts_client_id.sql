-- Migration 1103: Backfill contracts.client_id
--
-- Migration 1050 added contracts.client_id but never backfilled it ("Backfilled
-- separately" — that step was never run). Individual-client contracts (linked
-- via contact_id, no property_manager company) have had client_id = NULL ever
-- since, which silently breaks any feature that resolves a contract's account
-- via client_id (e.g. the client prepaid-credit ledger, migration 1102).
--
-- Every contact already has a clients row (migration 1049 Step B — D1: EVERY
-- contact gets one client). This just links existing contracts to their
-- already-existing client row. Safe to re-run: only fills NULL client_id.
--
-- MySQL 5.7-compatible: no window functions, no DDL (data-only UPDATE).

UPDATE contracts c
JOIN clients cl ON cl.legacy_contact_id = c.contact_id
SET c.client_id = cl.id
WHERE c.client_id IS NULL;
