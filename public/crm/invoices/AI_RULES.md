# Invoices Module — AI Rules

## Files
| File | Purpose |
|------|---------|
| `index.php` | Invoice list with stat cards (outstanding/paid/sent/overdue), filters |
| `create.php` | Invoice form with tax calculation, optional job link |

## AppStack Pattern
All pages use `dirname(__DIR__) . '/includes/appstack_head.php'` and `appstack_footer.php`.
`$activePage = 'invoices'`.

## Database Tables
- `invoices` — invoice_number (INV-YYYY-NNNN), company_id, property_id, job_id, subtotal, tax_rate, tax_amount, total, balance_due, status, access_token
- `invoice_line_items` — invoice_id, description, quantity, unit_price, line_total, job_id

## Key Functions
- `generateInvoiceNumber()` — INV-YYYY-NNNN format
- `generateAccessToken()` — for customer portal links
- `formatCurrency($amount)` — CAD formatting
- `getStatusBadge($status, 'invoice')` — returns HTML badge

## Invoice Statuses
`draft` → `sent` → `viewed` → `paid` | `overdue` | `cancelled`

## Tax
GST rate: 5% (hardcoded). Calculated as `subtotal * 0.05`.

## CSS Classes
`.mw-stat-card.outstanding/.paid/.sent/.overdue`, `.mw-action-btn-paid`
`.mw-totals-box`, `.mw-totals-row`, `.mw-form-row`, `.mw-form-group`
