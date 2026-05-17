-- ============================================================
-- Migration 1023: campaign_sends idempotency
-- Adds UNIQUE(campaign_id, contact_id) so re-queue or two
-- overlapping cron ticks can never create a duplicate send
-- (a contact gets at most one send row per campaign).
--
-- Run-once: enforced by migrations_log. The manager executes
-- each statement separately with NO transaction wrapper, so the
-- DELETE then ALTER is safe (DDL auto-commits per statement).
--
-- MySQL 5.7/8 safe: no window functions, no IF NOT EXISTS on ALTER.
-- ============================================================

-- ── 1. De-duplicate existing rows BEFORE adding the constraint ──────────────
-- For each (campaign_id, contact_id) group with >1 row, keep ONE survivor:
--   • the earliest row that was actually 'sent' (never lose send history), else
--   • the earliest row of any status.
-- Delete every other duplicate. Deterministic; no window functions.
DELETE cs
FROM campaign_sends cs
JOIN (
    SELECT campaign_id,
           contact_id,
           MIN(CASE WHEN status = 'sent' THEN id END) AS keep_sent,
           MIN(id)                                    AS keep_any
    FROM campaign_sends
    GROUP BY campaign_id, contact_id
    HAVING COUNT(*) > 1
) dup
  ON dup.campaign_id = cs.campaign_id
 AND dup.contact_id  = cs.contact_id
WHERE cs.id <> COALESCE(dup.keep_sent, dup.keep_any);

-- ── 2. Enforce uniqueness going forward ────────────────────────────────────
-- campaign_id and contact_id are both NOT NULL (migration 604), so a unique
-- key is safe. Application INSERTs use INSERT IGNORE so a duplicate attempt
-- becomes a no-op rather than a fatal error.
ALTER TABLE campaign_sends
  ADD UNIQUE KEY uq_campaign_contact (campaign_id, contact_id);
