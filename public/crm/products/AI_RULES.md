# Products Module — AI Rules

## Files
| File | Purpose |
|------|---------|
| `index.php` | Products hub — links to all tools |
| `products-manager.php` | Product catalog with CRUD modal, GGOB bundles |
| `cost-factors.php` | Labor rates, equipment, overhead, materials, calculator (Bootstrap tabs) |
| `area-measurement.php` | Google Maps polygon/rectangle measurement tool |
| `quote-requests.php` | View/filter incoming website quote submissions |
| `config.php` | PHP constants (GOOGLE_MAPS_API_KEY) — NOT an AppStack page |
| `process-quote-request.php` | Backend AJAX handler — NOT an AppStack page |

## AppStack Pattern
All UI pages use `dirname(__DIR__) . '/includes/appstack_head.php'` and `appstack_footer.php`.
`$activePage = 'products'`. Area measurement uses `$extraHead` for Google Maps API script.

## Database Tables
- `quote_requests` — contact_id, property_id, service_types, urgency, status, source
- `contacts` — first_name, last_name, email, phone, preferred_contact_method
- `properties` — address, city, postal_code, property_type, latitude, longitude, company_id
- `property_measurements` — property_id, measurement_name, measurement_type, area_sqft

## Quote Request Statuses
`new` → `reviewing` → `quoted` | `converted` | `declined`

## Key CSS Classes
Hub: `.mw-tools-grid`, `.mw-tool-card`, `.mw-badge-tag`
Products: `.mw-product-card`, `.mw-product-grid`, `.mw-product-ggob-indicator`
Cost factors: `.mw-calc-box`, `.mw-calc-row`, `.mw-profit-card`, `.mw-badge-active`
Measurement: `.mw-measure-map-container`, `.mw-measure-tools`, `.mw-area-item`
Quote requests: `.mw-qr-card`, `.mw-status-badge`, `.mw-urgency-badge`
