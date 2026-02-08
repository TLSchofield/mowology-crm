# Quotes Module — AI Rules

## Files
| File | Purpose |
|------|---------|
| `index.php` | Quote list with status tabs, search, filters |
| `create.php` | Quote form with line items, templates, sticky summary |
| `view.php` | Quote detail: send, resend, convert to job |

## AppStack Pattern
All pages use `dirname(__DIR__) . '/includes/appstack_head.php'` and `appstack_footer.php`.
`$activePage = 'quotes'`. No inline `<style>` — all CSS in `/crm/css/mowology-brand.css`.

## Database Tables
- `quotes` — quote_number (QUO-YYYY-NNNN), status, amount, property_id, access_token
- `quote_line_items` — quote_id, service_type, description, quantity, unit_price, line_total, sort_order
- `activity_log` — tracks send/view/accept events via quote_id column

## Key Functions (from `includes/functions.php`)
- `generateQuoteNumber()` — QUO-YYYY-NNNN format
- `calculateQuoteTotals($lineItems, $taxRate)` — returns subtotal/tax/total
- `getStatusBadge($status)` — returns HTML badge span
- `formatCurrency($amount)` — CAD formatting
- `generateAccessToken()` — for customer portal links
- `createJobFromQuote($quoteId, $userId)` — converts accepted quote to job
- `getServiceTemplates()` — preset line item templates

## Quote Statuses
`draft` → `sent` → `viewed` → `accepted` | `declined` | `expired`

## CSS Classes (mw- prefix)
List view: `.mw-filter-tabs`, `.mw-table`, `.mw-badge-status`, `.mw-action-btn-*`
Create: `.mw-form-row`, `.mw-form-group`, `.mw-template-dropdown`, `.mw-sticky-summary`
View: `.mw-content-grid`, `.mw-detail-row`, `.mw-line-items-table`, `.mw-signature-section`
