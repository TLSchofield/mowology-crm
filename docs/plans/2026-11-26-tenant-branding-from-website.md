# Plan: tenant logo and colours on everything the company sends (2026-11-26)

Plan only. Nothing built, nothing deployed. Written for Tim's approval.

## Decided (Tim, 2026-11-26)
- The Ittai app and the CRM screens do not change for any tenant.
- All company-sent PDFs and communications (emails, customer portal) carry the tenant's own logo and colours. SMS stays plain text (CLAUDE.md rule 11).
- On onboarding the system finds the tenant's logo and brand colours from their website; the owner approves a preview first. Applies to Sam Chapman's business and to Claudia's.
- Mowology keeps its current logo and green.
- New-tenant defaults (separate item, see memory `project-new-tenant-defaults-2026-11-26`): GPS off, quiz off, driver portal never used.

## What exists today (read from the code, 2026-11-26; spot-checked invoice.php)
- `business_settings` stores `logo_path`, `logo_alt_text`, `brand_color_primary`, `brand_color_secondary` (`app/Modules/Settings/Api/business-settings.php:102-105`, edited at `public/crm/settings.php:355-365`). Only the customer portal reads the logo (`app/Services/PortalBrand.php:133-137`). Nothing reads the colours.
- **Bug now:** the invoice and quote PDFs hardcode `mowology-logo.jpg` (`public/crm/templates/pdf/invoice.php:378-385`, `quote.php:288-299`), so every tenant's invoice shows Mowology's logo beside the tenant's name. The proof-of-work and salt report PDFs do the same (`PowPdfGenerator.php:52`, `SaltReportPdfGenerator.php:387`).
- Hardcoded Mowology colours: invoice `#233A5E`, quote `#2D8659`/`#0D3B2E`, statement and contract `#2D8659`, emails `EmailWrapper.php:41-47,121`, `TemplateRenderer.php:148,163`.
- The PDF settings loader selects no logo or colour column (`PdfGenerator.php:391-414`). All PDFs render through mPDF (`:575`).
- Logo upload: PNG or JPG, 2 MB, `public/crm/api/upload-logo.php`; stored per tenant under `/uploads/t/<tenant>/` (`app/Core/Uploads.php`). Not re-encoded.
- The only remote-fetch guard is `app/Services/Net/SafeHttpFetcher.php` (refuses private, loopback and numeric-IP addresses). Several other fetches are unguarded and are not touched here.

## Unknowns and how each is settled (cheapest first)
1. Does production have GD or Imagick, and can either read SVG or WebP? Read-only `scripts/prod-run.sh` snippet printing `extension_loaded` and `Imagick::queryFormats()`. If neither reads SVG, SVG-only sites fall back to the apple-touch-icon or favicon, or the owner uploads a PNG; no SVG rasteriser is added.
2. Are `logo_path` values usable by mPDF (it needs a local file path or data URI, not a web path)? Local test: render an invoice with a tenant logo.
3. Which tenant rows have a `logo_path` and colours set today (Sam and Claudia's may be empty)? Read-only query on production and the local test tenant.
4. Do iPhone and Android build any of these PDFs or emails themselves? Expected no (server-rendered); confirm with one grep in each app before saying "all".
5. Are there more PDF or email builders than the helper found (receipt PDF not found)? One more grep for `Mpdf` and `EmailWrapper::wrap` callers at build time.

## Steps (each proven before the next)
**Step 1 — Fix the wrong logo and wire what already exists.** Add `logo_path`, `logo_alt_text`, `brand_color_primary`, `brand_color_secondary` to the settings the PDF loader selects. A small `TenantBrand` service (`app/Modules/Settings/Services/TenantBrand.php`) returns `{name, logoFilePath, primary, secondary, tint, ink}` for the current tenant: Mowology (default tenant) returns today's logo and greens, so its output is byte-for-byte unchanged; any other tenant returns its own logo, or **no logo** (name as text) when none is set, never Mowology's. Wire it into the invoice, quote, statement and contract PDFs, `PowPdfGenerator`, `SaltReportPdfGenerator`, `EmailWrapper` and `TemplateRenderer`. Colours move from hex literals into values passed by `TenantBrand`. Tests: Mowology output unchanged; another tenant with no logo shows its name and no Mowology asset; another tenant with a logo shows it. Ship this alone, first.

**Step 2 — Design rules in code (`BrandPalette`, pure functions).** Input: a candidate primary and secondary. Output a safe palette. Rules: WCAG contrast of white text on the primary at least 4.5:1, else darken in small steps until it passes; pale colours (low contrast on white) are never used as text or thin rules, only as a tint behind dark ink; the brand colour appears only in the header rule, table header and total bar; amounts stay black; a greyscale render must keep header and body distinguishable (luminance gap check); layout stays fixed, only logo and colour vary; if two colours are too close, drop the secondary. Tests are table-driven with awkward colours (pale yellow, near-black, neon, grey).

**Step 3 — Fetch from the website (`BrandFetchService`).** Owner gives a web address. Fetch only through `SafeHttpFetcher` (re-check every redirect, size cap about 1.5 MB per file and 3 MB page, 8 second timeout, http and https only, no cookies). Logo candidates in order: `og:image`, an `<img>` in the header or nav whose name, alt, class or id contains "logo", `apple-touch-icon`, favicon. Colours in order: `meta theme-color`, CSS custom properties on `:root`, the most-used non-grey colours in the page's inline CSS and linked stylesheet (one stylesheet only), then the dominant colours of the chosen logo. Re-encode the chosen logo to PNG with GD (strips anything odd, fixes size to a sensible maximum), store under the tenant's upload folder, never overwrite until approved. Everything is a **proposal** held in a pending row; nothing touches `business_settings` yet.

**Step 4 — Owner preview and approval.** A Settings screen (and the onboarding step) shows the proposed logo and palette on a sample invoice and a sample email, with "Use this", "Change colours" and "Upload a logo instead". Approving writes `logo_path`, `logo_alt_text` and the two colours for that tenant only. If the site cannot be read, or has no logo, the screen says so plainly and offers the manual route. Onboarding wording is neutral (no trade words).

**Step 5 — Onboarding hook.** Add the website step to the new-business walkthrough for Sam and Claudia (after the first receipt snap, before the bank upload prompt), skippable, never blocking. Records the website address on the business.

## Verification
- Unit tests per service; `vendor/bin/phpunit` before every commit.
- Local fixture sites (served from a throwaway folder): clean logo and colours; messy colours; SVG-only logo; no logo; a site that redirects to a private address (must be refused); a very large image (must be refused); a slow server (must time out).
- Render invoice, quote, statement and contract PDFs and a sample email for Mowology (unchanged), a tenant with no logo, and a tenant with a fetched logo; look at each, plus a greyscale pass.
- Local test tenant first, then Claudia's rehearsal business, then Sam's real site, with Tim shown the preview before anything is saved.
- Deploy through the gate scripts, one file at a time; check production with the signed-in crawler recipe; step 1 gets its own live check on a tenant invoice.

## Offline
Server-side only; crew and field apps are not involved. PDFs and emails are generated when online as today. If the website is unreachable during onboarding, the step is skipped and can be redone later.

## Risks
- Step 1 changes Mowology's own documents if done carelessly; the test above pins Mowology's output.
- Websites with logos as CSS backgrounds or SVG-only will fail to fetch; the manual upload path is the answer, stated to the owner.
- Fetching a user-supplied address is an attack surface; only `SafeHttpFetcher` is allowed, with tests for private addresses and redirects.
- Colours scraped from a site can be ugly or low contrast; the rules and the owner's approval catch this.

## Rollback
Each step is code-only except the pending-proposal table in step 3 (additive; drop the table). Step 1 rolls back by reverting the template files; Mowology output was pinned unchanged.

## Decisions for Tim
1. Step 1 first, alone and deployed before the rest? Recommend yes (it removes Mowology's logo from tenants' invoices now).
2. Sam's and Claudia's website addresses for the final test run.
3. Where in the walkthrough the website step sits (recommend after the first receipt, before the bank upload).
