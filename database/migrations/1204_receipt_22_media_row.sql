-- 1204: expense #22's photo record (media_assets #60) never existed, so 1203's photo fix had nothing to
-- update. Create the record for the real file (uploaded by the truck tablet 2026-02-17, matched by eye:
-- City of Vancouver landfill, green waste, 0.44 t, $51.00) and point the receipt at it. (Tim, 2026-10-07)
-- Guarded: only if #60 is still missing and #22 still points at it; runs once.

INSERT INTO media_assets (original_filename, stored_filename, file_path, file_type, mime_type, created_by)
SELECT 'receipt-20260217-155956-699500fc8f14a.jpg', 'receipt-20260217-155956-699500fc8f14a.jpg',
       '/uploads/receipts/receipt-20260217-155956-699500fc8f14a.jpg', 'image', 'image/jpeg', 4
  FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM media_assets WHERE id = 60)
   AND EXISTS (SELECT 1 FROM expenses WHERE id = 22 AND receipt_media_id = 60)
   AND NOT EXISTS (SELECT 1 FROM media_assets WHERE file_path = '/uploads/receipts/receipt-20260217-155956-699500fc8f14a.jpg');

UPDATE expenses e
  JOIN media_assets m ON m.file_path = '/uploads/receipts/receipt-20260217-155956-699500fc8f14a.jpg'
   SET e.receipt_media_id = m.id
 WHERE e.id = 22 AND e.receipt_media_id = 60;
