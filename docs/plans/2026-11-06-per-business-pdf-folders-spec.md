# Build spec — per-business PDF folders (security review 2026-11-06, finding #1, CRITICAL)

Approved by Tim 2026-11-06. Report: https://claude.ai/artifact/DRYh8vJgvvSrRTzjkHNVWY

## Problem
Every business writes PDFs into the same folders under `STORAGE_ROOT/pdfs/<folder>/` with names that repeat between businesses
(`INV-2026-0001_v1.pdf`, `salt_{id}_v{n}.pdf`, `visit_{id}_v{n}.pdf`, `visit_{id}.pdf`, `trip_<driver>_<date>.pdf`, statements).
Each business has its own database, so numbers/ids collide: business B's first invoice overwrites Mowology's and either side can
be emailed/served the other's file. Production (2026-11-06): only Mowology has PDFs; no other business has made one yet.

## Rule
- **Default business (Mowology, `Tenant::isDefault()`)**: unchanged — same folders, same stored paths. Its 284 invoice / 45 quote
  PDFs and every other existing file must keep working with no migration.
- **Any other business**: files go to `STORAGE_ROOT/pdfs/t/<key>/<folder>/` (`<key>` = `Tenant::key()`, already validated
  `[a-z0-9-]`), and the stored `pdf_path` carries the same `t/<key>/` segment, e.g. `app/Storage/pdfs/t/rehearsal2/invoices/INV-2026-0001_v1.pdf`
  or `storage/pdfs/t/rehearsal2/salt-reports/salt_3_v1.pdf` (keep each writer's existing prefix style).

## Build
1. New `app/Core/PdfStore.php` (global, no namespace, `final class PdfStore`, pure helpers + one mkdir), the one place that knows this:
   - `segment(): string` → `''` for the default business, `'t/<key>/'` otherwise (CLI/cron: Tenant is booted per business — check `Tenant::boot`/`--tenant`).
   - `dir(string $folder): string` → absolute folder (creates it 0750 if missing); `$folder` must be one of `StoredPdfPath::FOLDERS` (+ `statements` if statements are stored — check `PdfGenerator.php:338-345`).
   - `relative(string $folder, string $file, string $prefix = 'storage'): string` → stored path with the segment.
   - `absoluteFromStored(string $stored): ?string` → for readers; delegates to `StoredPdfPath::resolve` with the current business's key.
   Register in `tests/bootstrap.php` if the tests need it.
2. `StoredPdfPath::resolve()` accepts an optional `t/<key>/` segment **only when `<key>` equals the current business's key** (pass the
   key in; default business accepts no `t/` segment at all). A path for another business's folder → `null`. Keep every existing check
   (allow-listed folder, `.pdf`, no `..`, realpath stays inside that exact folder).
3. Writers — use `PdfStore::dir()` / `relative()`:
   `app/Services/Pdf/PdfGenerator.php` (invoices, quotes, statements; constructor `storagePath` ~34, save ~546-576, and the reader ~293-305),
   `app/Services/Salt/SaltReportPdfGenerator.php`, `app/Services/Pow/PowPdfGenerator.php`, `app/Services/Pow/ZoneReportPdfGenerator.php`
   (it writes under `publicRoot/storage/...` — move it to `STORAGE_ROOT` via PdfStore like the others; check its readers),
   `app/Modules/Driver/TripReportPdf.php`, `app/Modules/Invoices/Services/InvoiceFromVisitService.php:119` (salt-report dir).
4. Readers that rebuild a path from `basename(pdf_path)` + a fixed folder must use `PdfStore::absoluteFromStored()` instead:
   `public/crm/api/salt-report.php:123`, `public/customer/salt-report.php:69-71`; check every other file that reads `pdf_path`
   (grep -rln pdf_path app public — 25 files: email attachments, portal PDF/download pages, stripe webhook, visit pages, trip reports)
   and fix any that assume the flat folder. `public/crm/documents/download.php` goes through `StoredPdfPath::resolve` — pass the key.
5. Do not move or rename any existing file. No migration.

## Tests (tests/Unit)
- `PdfStore`: default → no segment; tenant `rehearsal2` → `t/rehearsal2/`; `relative()` shapes for each prefix.
- `StoredPdfPath::resolve`: default accepts the old paths and refuses `t/...`; tenant accepts its own `t/<key>/...`, refuses another
  key's and refuses the flat (default) path; existing tests still pass. Use `Tenant::forTests()` to switch businesses.
- A writer test (PdfGenerator or a small seam) proving two businesses with the same invoice number get different absolute paths.

## Done when
`php -d memory_limit=2G vendor/bin/phpunit` passes (baseline 4174), `php -l` clean, and the report lists every file changed and every
`pdf_path` reader checked (fixed / already fine).
