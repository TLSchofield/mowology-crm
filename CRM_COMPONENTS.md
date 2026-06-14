# CRM Component Library

**This document is binding.** Before writing any UI element in a CRM page, find the matching component here and use it exactly. Do NOT invent new markup patterns when a design system component already covers the use case.

All components are styled in `public/crm/css/mowology-brand.css`. All custom classes use the `.mw-` prefix.

---

## Quick Lookup

| I need a… | Use | Anti-pattern |
|-----------|-----|--------------|
| Page title + action buttons | `.mw-page-header` | raw `<h1>` + ad-hoc flex div |
| Primary / secondary button | `btn btn-primary` / `btn btn-outline-primary` | inline `style="background:#2D8659"` |
| Status badge (paid, pending…) | `.mw-badge-status` + modifier | `<span style="color:green">` |
| Bootstrap badge | `badge badge-success/danger/warning/info/secondary` | `<span class="badge" style="...">` |
| Filter tabs above a list | `.mw-filter-tabs` + `.mw-filter-tab` | custom `<ul>` or `<div>` nav |
| Summary numbers at top of page | `.mw-stat-card` | card + inline styles |
| Form layout (2-col grid) | `.mw-form-row` + `.mw-form-group` | `<div class="row"><div class="col-6">` |
| Text input / select | `.form-control` (Bootstrap, already themed) | `<input style="border:...">` |
| Date input on a form | `<input type="date" class="form-control">` | third-party date library |
| Date navigation picker (jump to week/day) | `.mw-datepicker-trigger` + `.mw-datepicker-popup` | `<input type="date">` in a navigation context |
| Overlay modal | `.mw-modal-overlay` + `.mw-modal` | Bootstrap `.modal` or `<dialog>` |
| "No results" placeholder | `.mw-empty-state` | raw `<p class="text-center text-muted">` |
| Success/error flash message | `mwToast()` JS function | `alert()` or inline DOM message |
| Table row with two lines | `.mw-cell-primary` + `.mw-cell-secondary` | nested `<small>` or inline `<br>` |
| Two-column content area | `.mw-content-grid` | `<div class="row"><div class="col-8">` |

---

## 1. Page Header

Every CRM page starts with this structure. Title on the left, action buttons on the right.

```html
<div class="mw-page-header">
  <div class="mw-page-header-left">
    <h1 class="mw-page-title">Page Title</h1>
    <p class="mw-page-subtitle">Optional subtitle or record count</p>
  </div>
  <div class="mw-page-actions">
    <button class="btn btn-primary btn-sm">
      <i data-feather="plus" class="mw-icon-xs"></i> New Item
    </button>
  </div>
</div>
```

**Reference:** `public/crm/clients_appstack.php` (line ~1924)

---

## 2. Buttons

Bootstrap button classes are already fully themed by `mowology-brand.css`. No custom button classes needed.

| Intent | Class |
|--------|-------|
| Primary action | `btn btn-primary` |
| Secondary / outline | `btn btn-outline-primary` |
| Destructive / cancel | `btn btn-secondary` |
| Small variant | `btn btn-sm` |
| Large variant | `btn btn-lg` |

```html
<!-- Primary -->
<button type="submit" class="btn btn-primary">Save Changes</button>

<!-- Outline / secondary action -->
<button type="button" class="btn btn-outline-primary">Cancel</button>

<!-- Small, in a table row -->
<button type="button" class="btn btn-primary btn-sm">Edit</button>
```

**Anti-pattern:** `<button style="background:#2D8659;color:#fff">` — never hardcode colors.

---

## 3. Badges

### Status badges (for record states: paid, pending, draft, etc.)

```html
<span class="mw-badge-status paid">Paid</span>
<span class="mw-badge-status pending">Pending</span>
<span class="mw-badge-status draft">Draft</span>
<span class="mw-badge-status overdue">Overdue</span>
```

The `mw-badge-status` class is driven by `getStatusBadge()` in PHP — use that function rather than writing the span manually:

```php
echo getStatusBadge($record['status']);
```

### General-purpose badges

Use Bootstrap badge classes (themed by mowology-brand.css):

```html
<span class="badge badge-success">Active</span>
<span class="badge badge-danger">Cancelled</span>
<span class="badge badge-warning">Needs Review</span>
<span class="badge badge-info">Scheduled</span>
<span class="badge badge-secondary">Archived</span>
<span class="badge badge-primary">New</span>
```

**Anti-pattern:** `<span style="background:green;color:white;padding:2px 6px;border-radius:3px">` — always use badge classes.

---

## 4. Filter Tabs

Used above list views to filter by status. The active tab has class `active`.

```html
<div class="mw-filter-tabs">
  <a href="?status=all" class="mw-filter-tab active">
    All <span class="count">42</span>
  </a>
  <a href="?status=active" class="mw-filter-tab">
    Active <span class="count">18</span>
  </a>
  <a href="?status=draft" class="mw-filter-tab">
    Draft <span class="count">7</span>
  </a>
</div>
```

For JS-driven filters (no page reload), use `.mw-filter-pill` instead of `.mw-filter-tab` and add `data-filter` attributes.

**Reference:** `public/crm/clients_appstack.php`

---

## 5. Stat Cards

Summary numbers displayed as a row at the top of list views or dashboards.

```html
<div class="row mb-4">
  <div class="col-6 col-md-3">
    <div class="mw-stat-card paid">
      <h4>Total Paid</h4>
      <div class="value">$4,200</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="mw-stat-card outstanding">
      <h4>Outstanding</h4>
      <div class="value">$800</div>
    </div>
  </div>
</div>
```

Color modifier classes: `paid` · `outstanding` · `sent` · `draft` · `overdue`

**Reference:** `public/crm/clients_appstack.php` (line ~2526)

---

## 6. Form Layout

All create/edit forms use a CSS grid layout, not Bootstrap columns.

```html
<form>
  <!-- Two-column row (default) -->
  <div class="mw-form-row">
    <div class="mw-form-group">
      <label for="firstName">First Name</label>
      <input type="text" class="form-control" id="firstName" name="first_name" required>
    </div>
    <div class="mw-form-group">
      <label for="lastName">Last Name</label>
      <input type="text" class="form-control" id="lastName" name="last_name" required>
    </div>
  </div>

  <!-- Three-column row -->
  <div class="mw-form-row three">
    <div class="mw-form-group">...</div>
    <div class="mw-form-group">...</div>
    <div class="mw-form-group">...</div>
  </div>

  <!-- Full-width field (spans both columns) -->
  <div class="mw-form-row">
    <div class="mw-form-group full">
      <label for="notes">Notes</label>
      <textarea class="form-control" id="notes" name="notes" rows="3"></textarea>
    </div>
  </div>
</form>
```

**Anti-pattern:** `<div class="row"><div class="col-md-6">` — use `mw-form-row` instead.

**Reference:** `public/crm/quotes/create.php`

---

## 7. Form Inputs

All inputs use Bootstrap's `.form-control` class — it is already fully themed (green focus ring, brand typography, styled select chevron).

```html
<!-- Text -->
<input type="text" class="form-control" name="company" placeholder="Company name">

<!-- Select — brand chevron is injected automatically -->
<select class="form-control" name="status">
  <option value="active">Active</option>
  <option value="inactive">Inactive</option>
</select>

<!-- Textarea -->
<textarea class="form-control" name="notes" rows="4"></textarea>

<!-- Date input on a form — use native input, it gets a brand calendar icon -->
<input type="date" class="form-control" name="due_date">

<!-- Input with icon prefix -->
<div class="input-group">
  <div class="input-group-prepend">
    <span class="input-group-text">$</span>
  </div>
  <input type="number" class="form-control" name="amount" step="0.01">
</div>
```

**Anti-pattern:** inline `style="border:1px solid #ccc"` on inputs — always use `.form-control`.

---

## 8. Date Navigation Picker

Use this for navigation controls where the user jumps to a date (e.g., "show schedule for week of…"). This is the custom `.mw-datepicker-*` component — it is a styled button that opens a branded calendar popup.

This is **not** for form inputs that set a date field on a record — use `<input type="date" class="form-control">` for that.

```html
<!-- Trigger button -->
<button type="button" class="mw-datepicker-trigger" id="mwDpTrigger"
        data-current="2026-04-28"
        data-view="week"
        aria-haspopup="true" aria-expanded="false">
  <svg class="mw-dp-cal-icon" width="14" height="14" ...></svg>
  <span class="mw-dp-date">Apr 28 – May 4, 2026</span>
  <svg class="mw-dp-chevron" width="12" height="12" ...></svg>
</button>

<!-- Popup (hidden until trigger clicked) -->
<div class="mw-datepicker-popup" id="mwDpPopup" role="dialog" aria-label="Date picker" hidden>
  <div class="mw-dp-header">
    <button type="button" class="mw-dp-nav-btn" id="mwDpPrevMonth">‹</button>
    <span class="mw-dp-month-label" id="mwDpMonthLabel"></span>
    <button type="button" class="mw-dp-nav-btn" id="mwDpNextMonth">›</button>
  </div>
  <div class="mw-dp-weekdays">
    <span>Mo</span><span>Tu</span><span>We</span><span>Th</span><span>Fr</span><span>Sa</span><span>Su</span>
  </div>
  <div class="mw-dp-grid" id="mwDpGrid"></div>
  <div class="mw-dp-footer">
    <button type="button" class="mw-dp-today-link" id="mwDpTodayBtn">Jump to Today</button>
  </div>
</div>
```

Copy the JS initialization from `public/crm/jobs/schedule.php` (search for `mwDpTrigger`).

**Anti-pattern:** `<input type="date">` inside a navigation control — use this component instead.

---

## 9. Modals

All overlay dialogs use the `.mw-modal-overlay` pattern. **Never use Bootstrap's `.modal` component** — it conflicts with AppStack's z-index stack.

```html
<!-- Overlay (hidden by default, add class "show" to open) -->
<div class="mw-modal-overlay" id="myModal">
  <div class="mw-modal"><!-- add mw-modal-wide for wide dialogs -->
    <div class="mw-modal-header">
      <h5 class="mw-modal-title">Dialog Title</h5>
      <button type="button" class="mw-modal-close" onclick="closeMwModal('myModal')">&times;</button>
    </div>
    <div class="mw-modal-body">
      <!-- form fields go here -->
      <div class="mw-form-group">
        <label>Field Label</label>
        <input type="text" class="form-control">
      </div>
    </div>
    <div class="mw-modal-footer">
      <button type="button" class="btn btn-outline-primary" onclick="closeMwModal('myModal')">Cancel</button>
      <button type="button" class="btn btn-primary" id="saveBtn">Save</button>
    </div>
  </div>
</div>
```

**JS helpers** (add these to the page if not already present):

```javascript
function openMwModal(id)  { document.getElementById(id).classList.add('show'); }
function closeMwModal(id) { document.getElementById(id).classList.remove('show'); }

// Close on overlay click
document.querySelectorAll('.mw-modal-overlay').forEach(overlay => {
  overlay.addEventListener('click', e => {
    if (e.target === overlay) overlay.classList.remove('show');
  });
});
```

**Wide modal:** add class `mw-modal-wide` to `.mw-modal` (720px max-width).

**Anti-pattern:** Bootstrap `.modal` / `.modal-dialog` / `$('#myModal').modal('show')` — use the `.mw-modal-overlay` system.

**Reference:** `public/crm/jobs/view.php`

---

## 10. Empty States

Shown when a list or section has no results.

```html
<div class="mw-empty-state">
  <div class="mw-empty-state-icon">
    <i data-feather="inbox"></i>
  </div>
  <p>No invoices found</p>
  <p class="text-muted">Create your first invoice to get started.</p>
  <a href="invoices/create.php" class="btn btn-primary btn-sm mt-2">New Invoice</a>
</div>
```

**Anti-pattern:** `<p class="text-center text-muted mt-4">No results.</p>`

**Reference:** `public/crm/clients_appstack.php`

---

## 11. Toast Notifications

Triggered via JavaScript. Never use `alert()` or inject a DOM message manually.

```javascript
// Success
mwToast('Changes saved successfully.', 'success');

// Error
mwToast('Something went wrong. Please try again.', 'error');

// Warning
mwToast('This action cannot be undone.', 'warning');

// Info
mwToast('Sync in progress…', 'info');
```

The `mwToast()` function and `.mw-toast-container` are global — loaded by `appstack_footer.php`. No extra HTML needed on the page.

**Anti-pattern:** `alert('Saved!')` or `document.getElementById('msg').style.display='block'`

**Reference:** `public/crm/jobs/schedule.php`

---

## 12. Table Rows — Two-Line Cells

When a table cell needs a primary value and a secondary detail beneath it:

```html
<td>
  <span class="mw-cell-primary">Tim Schofield</span>
  <span class="mw-cell-secondary">tim@mowology.ca</span>
</td>
```

**Anti-pattern:** `<td>Tim Schofield<br><small class="text-muted">tim@mowology.ca</small></td>`

---

## 13. Cards

Standard Bootstrap `.card` is fully themed. No wrapper needed.

```html
<div class="card">
  <div class="card-header">
    <h5 class="card-title">Section Title</h5>
  </div>
  <div class="card-body">
    <!-- content -->
  </div>
  <div class="card-footer">
    <!-- optional footer actions -->
  </div>
</div>
```

---

## 14. Tables

```html
<table class="table table-hover">
  <thead>
    <tr>
      <th>Name</th>
      <th>Status</th>
      <th>Amount</th>
      <th></th><!-- actions column -->
    </tr>
  </thead>
  <tbody>
    <tr>
      <td>
        <span class="mw-cell-primary">Record Name</span>
        <span class="mw-cell-secondary">Secondary detail</span>
      </td>
      <td><span class="mw-badge-status active">Active</span></td>
      <td>$1,200.00</td>
      <td>
        <a href="view.php?id=1" class="btn btn-outline-primary btn-sm">View</a>
      </td>
    </tr>
  </tbody>
</table>
```

**Anti-pattern:** `<table style="width:100%;border-collapse:collapse">` — always use `.table`.

---

## 15. CSS Tokens Quick Reference

All colors must use these variables — never hardcode hex values.

| Token | Value | Use |
|-------|-------|-----|
| `--mw-green` | `#2D8659` | Primary buttons, links, focus rings |
| `--mw-dark` | `#1A5F4A` | Hover states |
| `--mw-lime` | `#7FD858` | Active nav, success accents |
| `--mw-light` | `#E8F3F0` | Light tint backgrounds |
| `--mw-forest` | `#0D3B2E` | Sidebar background |
| `--mw-orange` | `#e85d04` | CTA accent (secondary) |

Typography, spacing, and shadow tokens are defined in `tokens.css` (loaded before `mowology-brand.css`). Use `--mw-text-*`, `--mw-weight-*`, `--mw-shadow-*`, `--mw-radius-*`.

---

## Adding New Components

When you design a new component that isn't listed above:

1. Add the CSS to `mowology-brand.css` under the appropriate section header, using `--mw-*` tokens and `.mw-` class prefix
2. Build the first implementation in a reference page
3. Add an entry to this document before the task is complete
