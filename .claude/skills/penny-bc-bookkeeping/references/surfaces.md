# iPhone, Android and web — keeping Penny the same everywhere

## How the three relate
- **Web** (desktop + phone browser) — CRM pages, e.g. `public/crm/expenses_appstack.php`.
- **Android** (crew app, Capacitor) — shows the same web pages. A web change IS an Android change
  (after deploy); only native pieces (camera permission, GPS service) live in `mowology-android`.
- **iPhone** (SwiftUI, `ios/MowologyCRM`) — native screens calling JWT endpoints under
  `app/Modules/*/Api/` (e.g. `expense-line-items.php`, `bookkeeper-mobile.php`).

## The rules
1. **Logic lives in one server service** (see SKILL.md "One place for each job"). Endpoints and
   pages only call it. Then a rule change reaches all three at once — the GST fix of 2026-10-11
   reached save, desktop, Penny's desk, the inbox and both apps through `detectAnomalies()`.
2. **Every Penny feature has a row below.** Adding or changing a feature on one surface means
   updating the others in the same piece of work, or writing the gap here so it is visible.
3. **iPhone needs a build** (bump MARKETING_VERSION, push `feature/language` → TestFlight);
   web/Android need a deploy (lftp, OPcache). Server first, then the app that relies on it.

## Parity table (checked 2026-10-11)

| Feature | iPhone | Web / Android | Server (one place) | Gap |
|---|---|---|---|---|
| Receipt line items: view, rename, remove, add, link | `ReceiptDetailView` (tap / swipe) | expense pop-up `#expLineItemsSection` (buttons) | `ExpenseLineItemService` | Android uses the desktop pop-up — not phone-shaped |
| Rescan a saved receipt photo | "Rescan the photo" (1.3.22) → `expense-line-items.php` op `rescan` | `#expRescanBtn` → `expenses.php` action `rescan` | `ExpenseRescanService::rescan()` | none in behaviour |
| Item load failure shown | yes, with Try again (1.3.22) | no — empty looks like failure | — | web/Android |
| Penny's reasons for a risk score | sentences (`RiskExplainer::say()` via `bookkeeper-mobile.php`) | score badge + flag chips; list icon is hover-only | `RiskExplainer` / `detectAnomalies()` | web/Android should show the sentences |
| Approve / reject | Penny ring + buttons | buttons (needs `expenses.approve`) | `ExpenseApprovalService` | — |
| Approving teaches SKU names | yes (server) | yes (server) | `recordConfirmedSkuNames()` | — |
| Mobile receipt capture | native camera, queue | `expenses_appstack.php?mode=quick` | intake services | Android native capture planned (after clock in/out) |
| Supplier forms, Otto buttons | server-sent buttons (1.3.20) | server-sent buttons (`otto-card.js`) | `SupplierFormService` | — |

When you change a row, change the date in this file's heading.
