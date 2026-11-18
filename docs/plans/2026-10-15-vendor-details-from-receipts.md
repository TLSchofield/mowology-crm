# Vendor details from receipts (2026-10-15)

Tim: "isn't Penny completing their details from the receipts? gst, website, address etc" → "build it".

## Facts (production, this session)
- 61 active vendors: 14 have a phone, 6 a website, 0 a GST number. Canadian Tire: nothing, 0 stores — its
  receipts print "STORE #605 - MARINE & ONTARIO / 8277 Ontario Street, Vancouver".
- The only fill is in `ReceiptIntakeService` (phone photo intake, and only when the vendor was matched):
  phone/website when empty, and one 'Main' store when the vendor has none. Email-in, web save, iPhone save never fill.
- GST numbers are read (`VendorIdentity::gstNumber`) for matching but only the commons link writes
  `vendors.gst_number`, and no vendor is linked yet (kb backfill not run). The vendor form has no GST field.
- Store addresses come only from GPS on phone photos (migration 1124).

## Build
1. `VendorDetailsService` (Expenses module, one place): `read($ocrText)` → phone, website, GST number, store
   number, store label, address, city (reusing `extractVendorLocationFromOcr`, `VendorIdentity::gstNumber`).
   `fillFromReceipt($expenseId)`: approved/forwarded receipts only (Penny learns from confirmed facts), the
   receipt's vendor, its stored OCR text. Vendor: phone / website / GST number **only when empty** — never
   overwrites what a person typed. Store: matched by store number, else street address, else phone → seen
   count + empty fields filled; else a new store (source 'receipt'; geocoded later by SupplierStopService).
2. Called from `ExpenseGateHooks::learnConfirmed` (every approval path: web, iPhone, email-in, Penny).
3. Vendor form: GST number field (vendors.php create/update accept it).
4. Backfill from approved receipts already in the books — previewed, then committed.
Junk vendors ("AMOUNT", "(B)", "CONTTLESS)") are a separate job: list, show receipts, Tim confirms each merge.

## Verify
Unit tests on `read()` with real receipt text (Canadian Tire, Rona); phpunit; local page; production preview of the backfill.
