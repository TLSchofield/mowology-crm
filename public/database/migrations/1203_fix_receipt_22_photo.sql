-- 1203: expense #22 (City of Vancouver landfill, green waste, 2025-05-14) — Tim, 2026-10-07.
-- Its photo record (media_assets #60) pointed at a file that doesn't exist, so the receipt showed
-- "Couldn't load the photo" on the web card and in the app. The photo is on the server, uploaded by
-- the truck tablet on 2026-02-17, matched by eye: the $51.00 / 0.44 t green-waste ticket.
-- The amounts were also read as $48.57 total — that is $51.00 before GST. Correct: 48.57 + 2.43 GST = 51.00.
-- Guarded: only touches the rows if they still are what they were on 2026-10-07.

UPDATE media_assets
   SET file_path = '/uploads/receipts/receipt-20260217-155956-699500fc8f14a.jpg',
       stored_filename = 'receipt-20260217-155956-699500fc8f14a.jpg'
 WHERE id = 60
   AND EXISTS (SELECT 1 FROM expenses WHERE id = 22 AND receipt_media_id = 60);

UPDATE expenses
   SET amount = 48.57, gst_amount = 2.43, total = 51.00
 WHERE id = 22 AND total = 48.57 AND status IN ('draft', 'pending_approval');
