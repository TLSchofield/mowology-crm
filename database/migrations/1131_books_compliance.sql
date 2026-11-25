-- Migration 1131: the books follow the bookkeeping-agent guardrails (2026-10-05)
-- Purpose (Tim approved fixes 1-4 of the compliance check):
--   1. Append-only journal: corrections are reversing entries, never deletes. A
--      reversed entry (reversed_by_entry_id set) must not block posting the corrected
--      entry for the same invoice / receipt, so (source_type, source_id) stops being
--      UNIQUE (LedgerService now skips reversed entries in its once-only check).
--   2. Who proposed each journal entry: journal_entries.proposed_by
--      ('system' = nightly sync, 'penny' = her suggestion the owner approved,
--      'owner' = the owner's own action).
--   3. Autonomy is earned: a learned bank rule acts on its own only after 50
--      confirmations (was 2). Rules below 50 are switched off — Penny still proposes
--      them on her card; each approval counts toward 50.
--   4. Every Penny suggestion records the prompt version that produced it.
-- Run once. The UNIQUE drop is the only change that can't be re-run (MySQL 5.7 has no
-- DROP INDEX IF EXISTS); the runner reports it as already done.

ALTER TABLE journal_entries DROP INDEX uq_source;
ALTER TABLE journal_entries ADD COLUMN proposed_by VARCHAR(20) NULL COMMENT 'system | penny | owner' AFTER created_by;
ALTER TABLE expense_suggestions ADD COLUMN prompt_version VARCHAR(20) NULL AFTER model;

UPDATE transaction_rules SET is_active = 0
WHERE source = 'learned' AND is_active = 1 AND learned_count < 50;
