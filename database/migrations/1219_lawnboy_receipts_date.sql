-- 1219: two Lawnboy slips filed 2026-10-07 (11:22 and 11:32, by Nigel) were dated 2026-04-08 — the handwritten
-- date was misread. #412 $218.40 = 2 x 5 kg seed (shop stock); #413 $84.00 = composted bark mulch (Oakridge), photographed
-- at Lawnboy (8655 Cambie) during the 11:29 truck stop. Only the date changes; still pending Tim's approval. Idempotent.
UPDATE expenses SET expense_date = '2026-10-07'
 WHERE id IN (412, 413) AND expense_date = '2026-04-08' AND DATE(created_at) = '2026-10-07';
