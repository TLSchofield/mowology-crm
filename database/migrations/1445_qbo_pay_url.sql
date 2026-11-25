-- 1445 — the QuickBooks pay link for an invoice, kept once QuickBooks has given it (QboClientPayService).
-- Businesses other than the original take no cards; their clients pay on QuickBooks' own page.
ALTER TABLE qbo_links ADD COLUMN pay_url VARCHAR(500) NULL AFTER message;
