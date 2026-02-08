# CRM Includes — AI Rules

## Include Contract

| File | Auto-included by | Output |
|------|-----------------|--------|
| `appstack_head.php` | Page files | `<!DOCTYPE>` through `<div class="container-fluid p-0">` (includes sidebar + topbar) |
| `appstack_footer.php` | Page files | Closes container → main → wrapper, outputs footer + `app.js` |
| `appstack_sidebar.php` | `appstack_head.php` | Sidebar nav from `$navItems` array |
| `appstack_topbar.php` | `appstack_head.php` | Top bar with user dropdown |
| `functions.php` | Page files (manual require) | Shared helper functions |
| `notifications.php` | Quote send flow | Email sending helpers |

## Variables for appstack_head.php
- `$pageTitle` (string, optional) — `<title>` content
- `$activePage` (string, optional) — sidebar highlight key
- `$user` (array, optional) — logged-in user data
- `$extraHead` (string, optional) — extra `<head>` markup (e.g., Google Maps script)

## Valid $activePage Keys
`dashboard` | `clients` | `quotes` | `jobs` | `invoices` | `schedule` | `map` | `products` | `settings`

## functions.php — Function Reference
| Function | Returns |
|----------|---------|
| `generateQuoteNumber()` | `QUO-YYYY-NNNN` |
| `generateJobNumber()` | `JOB-YYYY-NNNN` |
| `generateInvoiceNumber()` | `INV-YYYY-NNNN` |
| `generateAccessToken()` | Random hex token |
| `calculateQuoteTotals($items, $taxRate)` | `[subtotal, tax, total]` |
| `formatCurrency($amount)` | `$X,XXX.XX` |
| `formatDate($date)` | `Jan 1, 2026` |
| `formatDateTime($datetime)` | `Jan 1, 2026 1:00 PM` |
| `getStatusBadge($status, $type)` | HTML badge span |
| `logActivityExtended(...)` | void (inserts activity_log) |
| `getStaffMembers()` | Array of users |
| `createJobFromQuote($quoteId, $userId)` | `[success, job_id]` |
| `timeAgo($datetime)` | `"2 hours ago"` |
| `formatServiceTypes($csv)` | Array of labels |

## Legacy Files (DO NOT USE for new pages)
`sidebar.php`, `header.php`, `layout_top.php`, `layout_bottom.php`, `bootstrap.php` — old layout system, kept for reference only.
