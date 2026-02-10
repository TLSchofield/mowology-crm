# Admin UI Kit Architecture

**Purpose:** Create a shared, reusable system of UI components and partials for admin pages, without modifying AppStack or changing the global admin theme.

**Philosophy:** Incremental, low-risk, deployable component-by-component.

---

## Core Principle: Build ON TOP of AppStack, Don't Rebuild

```
AppStack Vendor (unchanged)
  ├─ /crm/css/classic.css
  ├─ /crm/css/corporate.css
  ├─ /crm/js/app.js
  └─ Works perfectly ✅

Our Admin UI Kit (NEW)
  ├─ /crm/includes/admin-ui-kit.php — Component library
  ├─ /crm/css/admin-ui-components.css — Component-specific styles
  ├─ /crm/js/admin-ui-components.js — Component JS
  └─ Uses AppStack as foundation, adds on top ✅
```

**Not building:**
- ❌ Replacing AppStack CSS
- ❌ Changing global admin theme
- ❌ New JavaScript framework
- ❌ Rebuilding existing admin pages

**We are building:**
- ✅ Reusable PHP components (tables, filters, badges, modals)
- ✅ Incremental enhancement
- ✅ Optional CSS for component styling
- ✅ Vanilla JS enhancements

---

## Component Architecture

### 1. UI Kit Foundation

**File: `/crm/includes/admin-ui-kit.php`** (500 lines)

```php
<?php
/**
 * Admin UI Kit - Reusable component library
 *
 * Usage:
 *   require_once 'admin-ui-kit.php';
 *   echo admin_table($data, $columns, $options);
 */

// ============================================================================
// COMPONENT CLASSES & FUNCTIONS
// ============================================================================

class AdminUIKit {
    // Configuration
    const THEME = 'appstack';
    const CSS_CLASS_PREFIX = 'aui-';

    // Component rendering
    public static function table($data, $columns, $options = []) { ... }
    public static function filterBar($filters, $activeFilters = []) { ... }
    public static function badge($text, $variant = 'default', $icon = null) { ... }
    public static function card($title, $content, $footer = null) { ... }
    public static function modal($id, $title, $content, $footer = null) { ... }
    public static function alert($message, $type = 'info', $dismissible = true) { ... }
    public static function emptyState($title, $description, $cta = null) { ... }
    public static function form($fields, $action, $method = 'POST') { ... }
    public static function breadcrumbs($items) { ... }
    public static function pagination($current, $total, $baseUrl) { ... }
    public static function ctaRow($items) { ... }  // Action buttons row
    public static function stats($items) { ... }   // Stat cards
}

// ============================================================================
// CONVENIENCE FUNCTIONS (Shorter names)
// ============================================================================

function admin_table($data, $columns, $options = []) {
    return AdminUIKit::table($data, $columns, $options);
}

function admin_filter($filters, $activeFilters = []) {
    return AdminUIKit::filterBar($filters, $activeFilters);
}

function admin_badge($text, $variant = 'default') {
    return AdminUIKit::badge($text, $variant);
}

// ... more convenience functions
```

### 2. Component: Table

```php
/**
 * Render a data table with sortable headers, row actions, batch actions
 *
 * Usage:
 *   echo admin_table(
 *       $pages,  // Array of data rows
 *       [  // Column definitions
 *           'title' => ['label' => 'Page Title', 'sortable' => true, 'width' => '40%'],
 *           'status' => ['label' => 'Status', 'badge' => true],
 *           'views' => ['label' => 'Views', 'align' => 'right'],
 *       ],
 *       [  // Options
 *           'row_actions' => [
 *               ['label' => 'Edit', 'url' => '/crm/edit?id={{id}}'],
 *               ['label' => 'Delete', 'action' => 'delete', 'confirm' => true],
 *           ],
 *           'batch_actions' => [
 *               ['label' => 'Publish', 'action' => 'publish'],
 *               ['label' => 'Archive', 'action' => 'archive'],
 *           ],
 *           'empty_state' => 'No pages found',
 *           'striped' => true,
 *           'hover' => true,
 *       ]
 *   );
 */
```

**Output:**
```html
<table class="aui-table table">
  <thead>
    <tr>
      <th><input type="checkbox" class="aui-select-all"></th>
      <th>Page Title <i class="aui-sortable"></i></th>
      <th>Status</th>
      <th>Views</th>
      <th class="aui-actions">Actions</th>
    </tr>
  </thead>
  <tbody>
    <tr>
      <td><input type="checkbox" class="aui-row-select"></td>
      <td>Homepage</td>
      <td><span class="badge badge-success">Published</span></td>
      <td class="text-right">1,542</td>
      <td class="aui-actions">
        <a href="/crm/edit?id=1">Edit</a>
        <a href="javascript:" data-action="delete">Delete</a>
      </td>
    </tr>
  </tbody>
</table>

<div class="aui-batch-actions hidden">
  <button class="btn btn-sm btn-primary">Publish Selected</button>
  <button class="btn btn-sm btn-warning">Archive Selected</button>
</div>
```

### 3. Component: Filter Bar

```php
/**
 * Render filter controls (dropdowns, search, date range)
 *
 * Usage:
 *   echo admin_filter([
 *       'status' => [
 *           'label' => 'Status',
 *           'type' => 'select',
 *           'options' => ['draft' => 'Draft', 'published' => 'Published'],
 *       ],
 *       'category' => [
 *           'label' => 'Category',
 *           'type' => 'select',
 *           'options' => [/* ... */],
 *       ],
 *       'search' => [
 *           'label' => 'Search',
 *           'type' => 'text',
 *           'placeholder' => 'Title or slug...',
 *       ],
 *       'date_from' => [
 *           'label' => 'Created from',
 *           'type' => 'date',
 *       ],
 *   ]);
 */
```

**Output:**
```html
<div class="aui-filter-bar">
  <form method="GET" class="form-inline">
    <div class="form-group">
      <label>Status</label>
      <select name="status" class="form-control form-control-sm">
        <option value="">All</option>
        <option value="draft">Draft</option>
        <option value="published">Published</option>
      </select>
    </div>

    <div class="form-group">
      <label>Category</label>
      <select name="category" class="form-control form-control-sm">
        <!-- ... -->
      </select>
    </div>

    <div class="form-group">
      <input type="text" name="search" placeholder="Search..." class="form-control form-control-sm">
    </div>

    <button type="submit" class="btn btn-sm btn-primary">Filter</button>
    <a href="?" class="btn btn-sm btn-secondary">Clear</a>
  </form>
</div>
```

### 4. Component: Badge

```php
/**
 * Render status badges (status, tags, labels)
 *
 * Usage:
 *   echo admin_badge('Published', 'success');
 *   echo admin_badge('Draft', 'warning');
 *   echo admin_badge('Archived', 'dark');
 */
```

**Output:**
```html
<span class="badge badge-success">Published</span>
<span class="badge badge-warning">Draft</span>
<span class="badge badge-dark">Archived</span>
```

### 5. Component: Card

```php
/**
 * Render a card container
 */
```

**Output:**
```html
<div class="aui-card card">
  <div class="card-header">Card Title</div>
  <div class="card-body">Content here</div>
  <div class="card-footer">Footer</div>
</div>
```

### 6. Component: Modal

```php
/**
 * Render a modal dialog
 *
 * Usage:
 *   echo admin_modal('confirm_delete', 'Delete Page?', 'Are you sure?', [
 *       'buttons' => [
 *           ['label' => 'Cancel', 'dismiss' => true],
 *           ['label' => 'Delete', 'class' => 'btn-danger', 'action' => 'confirm'],
 *       ],
 *   ]);
 */
```

**Output:**
```html
<div class="modal fade" id="confirm_delete" tabindex="-1" role="dialog">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Delete Page?</h5>
        <button type="button" class="close" data-dismiss="modal">
          <span>&times;</span>
        </button>
      </div>
      <div class="modal-body">Are you sure?</div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-danger">Delete</button>
      </div>
    </div>
  </div>
</div>
```

### 7. Component: Empty State

```php
/**
 * Render empty state (when no data)
 */
```

**Output:**
```html
<div class="aui-empty-state text-center py-5">
  <i class="fas fa-inbox" style="font-size: 3rem; color: #ccc;"></i>
  <h5>No pages found</h5>
  <p>Create your first page to get started</p>
  <a href="/crm/cms-pages?action=create" class="btn btn-primary">Create Page</a>
</div>
```

### 8. Component: CTA Row

```php
/**
 * Render action buttons row (for each table row)
 *
 * Usage (in table):
 *   'row_actions' => [
 *       ['label' => 'Edit', 'href' => '/crm/edit?id={{id}}', 'icon' => 'edit-2'],
 *       ['label' => 'View', 'href' => '/{{slug}}', 'target' => '_blank'],
 *       ['label' => 'Publish', 'action' => 'publish', 'method' => 'POST'],
 *       ['label' => 'Delete', 'action' => 'delete', 'confirm' => true, 'class' => 'text-danger'],
 *   ],
 */
```

**Output:**
```html
<div class="aui-row-actions">
  <a href="/crm/edit?id=1" title="Edit">
    <i data-feather="edit-2"></i>
  </a>
  <a href="/page-slug" target="_blank" title="View">
    <i data-feather="external-link"></i>
  </a>
  <button class="aui-action" data-action="publish" data-confirm="true" title="Publish">
    <i data-feather="check-circle"></i>
  </button>
  <button class="aui-action text-danger" data-action="delete" data-confirm="true" title="Delete">
    <i data-feather="trash-2"></i>
  </button>
</div>
```

### 9. Component: Alert

```php
/**
 * Render alert/notification
 */
```

**Output:**
```html
<div class="alert alert-success alert-dismissible fade show">
  <button type="button" class="close" data-dismiss="alert">×</button>
  <strong>Success!</strong> Page published successfully.
</div>
```

### 10. Component: Breadcrumbs

```php
/**
 * Render breadcrumb navigation
 */
```

**Output:**
```html
<nav aria-label="breadcrumb" class="aui-breadcrumbs">
  <ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="/crm/">Admin</a></li>
    <li class="breadcrumb-item"><a href="/crm/cms-pages">Pages</a></li>
    <li class="breadcrumb-item active">Edit Homepage</li>
  </ol>
</nav>
```

---

## CSS Strategy

**File: `/crm/css/admin-ui-components.css`** (300 lines)

```css
/* Admin UI Kit Component Styles */
/* Extends AppStack without replacing */

/* Tables */
.aui-table {
  width: 100%;
  border-collapse: collapse;
  background: white;
}

.aui-table thead {
  background-color: #f8f9fa;
  border-bottom: 2px solid #dee2e6;
}

.aui-table th, .aui-table td {
  padding: 0.75rem;
  border-bottom: 1px solid #dee2e6;
}

.aui-table tbody tr:hover {
  background-color: #f8f9fa;
}

/* Filters */
.aui-filter-bar {
  background: #f8f9fa;
  padding: 1rem;
  border-radius: 4px;
  margin-bottom: 1rem;
  border: 1px solid #dee2e6;
}

.aui-filter-bar .form-group {
  margin-right: 1rem;
  margin-bottom: 0;
}

/* Empty state */
.aui-empty-state {
  padding: 3rem 1rem;
  color: #999;
  text-align: center;
}

.aui-empty-state i {
  font-size: 3rem;
  color: #ddd;
  margin-bottom: 1rem;
}

/* Badges */
.badge {
  padding: 0.35rem 0.65rem;
  font-size: 0.85rem;
  font-weight: 500;
}

/* Row actions */
.aui-row-actions {
  display: flex;
  gap: 0.5rem;
}

.aui-row-actions a,
.aui-row-actions button {
  background: none;
  border: none;
  color: #007bff;
  cursor: pointer;
  padding: 0.25rem;
  transition: color 0.2s;
}

.aui-row-actions a:hover,
.aui-row-actions button:hover {
  color: #0056b3;
}

.aui-row-actions .text-danger:hover {
  color: #dc3545 !important;
}

/* Modal customizations */
.modal-header {
  background-color: #f8f9fa;
  border-bottom: 1px solid #dee2e6;
}

.modal-footer {
  background-color: #f8f9fa;
  border-top: 1px solid #dee2e6;
}
```

**Key Principle:**
- Never override AppStack CSS classes
- Only add new `.aui-*` prefixed classes
- Use AppStack classes where possible (`.table`, `.badge`, `.btn`, etc.)
- Minimal additional styling

---

## JavaScript Strategy

**File: `/crm/js/admin-ui-components.js`** (400 lines)

```javascript
/**
 * Admin UI Kit JavaScript Components
 *
 * Vanilla JS (no jQuery required, but works with it)
 * Adds interactivity to UI kit components
 */

// ============================================================================
// TABLE ENHANCEMENTS
// ============================================================================

class AdminTable {
  constructor(element) {
    this.table = element;
    this.selectAll = element.querySelector('[data-select-all]');
    this.rowCheckboxes = element.querySelectorAll('[data-row-select]');

    this.init();
  }

  init() {
    // Select all checkbox
    if (this.selectAll) {
      this.selectAll.addEventListener('change', (e) => {
        this.rowCheckboxes.forEach(cb => cb.checked = e.target.checked);
        this.updateBatchActions();
      });
    }

    // Individual row checkboxes
    this.rowCheckboxes.forEach(cb => {
      cb.addEventListener('change', () => this.updateBatchActions());
    });
  }

  updateBatchActions() {
    const selected = Array.from(this.rowCheckboxes).filter(cb => cb.checked);
    const batchActions = this.table.parentElement.querySelector('[data-batch-actions]');
    if (batchActions) {
      batchActions.classList.toggle('hidden', selected.length === 0);
    }
  }

  getSelectedRows() {
    return Array.from(this.rowCheckboxes)
      .filter(cb => cb.checked)
      .map(cb => cb.closest('tr').dataset.id);
  }
}

// ============================================================================
// FILTER INTERACTIONS
// ============================================================================

class AdminFilter {
  constructor(element) {
    this.form = element;
    this.clearBtn = element.querySelector('[data-filter-clear]');

    this.init();
  }

  init() {
    if (this.clearBtn) {
      this.clearBtn.addEventListener('click', (e) => {
        e.preventDefault();
        this.form.reset();
        this.form.submit();
      });
    }
  }
}

// ============================================================================
// ROW ACTIONS
// ============================================================================

class AdminRowAction {
  constructor(element) {
    this.button = element;
    this.action = element.dataset.action;
    this.confirm = element.dataset.confirm === 'true';

    this.init();
  }

  init() {
    this.button.addEventListener('click', (e) => {
      e.preventDefault();

      if (this.confirm) {
        if (!window.confirm('Are you sure?')) return;
      }

      this.execute();
    });
  }

  execute() {
    // Action handled by backend
    // This just triggers the API call
    const row = this.button.closest('tr');
    const id = row.dataset.id;

    fetch(`/crm/api/action`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': document.querySelector('[name="csrf_token"]').value,
      },
      body: JSON.stringify({
        action: this.action,
        id: id,
      }),
    })
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        location.reload();
      } else {
        alert('Error: ' + data.error);
      }
    });
  }
}

// ============================================================================
// INITIALIZATION
// ============================================================================

document.addEventListener('DOMContentLoaded', () => {
  // Initialize all tables
  document.querySelectorAll('[data-ui-table]').forEach(el => {
    new AdminTable(el);
  });

  // Initialize all filters
  document.querySelectorAll('[data-ui-filter]').forEach(el => {
    new AdminFilter(el);
  });

  // Initialize all row actions
  document.querySelectorAll('[data-action]').forEach(el => {
    new AdminRowAction(el);
  });
});
```

---

## Integration Pattern

### How to Use the UI Kit

**Example: New CMS Pages Admin Page**

```php
<?php
// /crm/cms-pages_appstack.php

require_once dirname(__DIR__) . '/includes/cms-functions.php';
require_once dirname(__DIR__) . '/includes/admin-ui-kit.php';

requireLogin();
$user = getCurrentUser();

$pageTitle = 'Pages';
$activePage = 'cms';

// Get pages
$pages = cms_getPublishedPages();

// Add custom actions to URL templates
foreach ($pages as &$p) {
    $p['edit_url'] = "/crm/cms-page-editor?id={$p['id']}";
    $p['delete_url'] = "/crm/api/delete-page?id={$p['id']}";
}
?>

<?php include 'includes/appstack_head.php'; ?>

<div class="container-fluid p-4">
  <h1>CMS Pages</h1>

  <!-- Filters -->
  <?php echo admin_filter([
      'status' => [
          'label' => 'Status',
          'type' => 'select',
          'options' => [
              '' => 'All',
              'draft' => 'Draft',
              'published' => 'Published',
          ],
      ],
      'page_type' => [
          'label' => 'Type',
          'type' => 'select',
          'options' => [
              '' => 'All',
              'home' => 'Homepage',
              'service_landing' => 'Service Landing',
          ],
      ],
  ]) ?>

  <!-- Create button -->
  <div class="mb-3">
    <a href="/crm/cms-page-editor?action=create" class="btn btn-primary">
      <i data-feather="plus"></i> New Page
    </a>
  </div>

  <!-- Table -->
  <?php echo admin_table($pages, [
      'title' => [
          'label' => 'Title',
          'sortable' => true,
          'width' => '40%',
      ],
      'page_type' => [
          'label' => 'Type',
          'badge' => true,
      ],
      'status' => [
          'label' => 'Status',
          'badge' => true,
      ],
      'view_count' => [
          'label' => 'Views',
          'align' => 'right',
      ],
  ], [
      'row_actions' => [
          ['label' => 'Edit', 'href' => '/crm/cms-page-editor?id={{id}}', 'icon' => 'edit-2'],
          ['label' => 'View', 'href' => '/{{slug}}', 'target' => '_blank'],
          ['label' => 'Delete', 'action' => 'delete', 'confirm' => true],
      ],
  ]) ?>
</div>

<?php include 'includes/appstack_footer.php'; ?>
```

**That's it!** The UI Kit handles:
- Table rendering
- Sorting (client-side or via query param)
- Row selection
- Batch actions
- Responsive design
- Accessibility

---

## Rollout Strategy

### Week 1: Foundation
- ✅ Create admin-ui-kit.php (components)
- ✅ Create admin-ui-components.css (styles)
- ✅ Create admin-ui-components.js (interactions)

### Week 2: First Usage
- ⏳ Build /crm/cms-pages_appstack.php using UI kit
- ⏳ Build /crm/cms-templates_appstack.php using UI kit
- ⏳ Test compatibility with AppStack

### Week 3: Gradual Migration
- ⏳ Refactor /crm/quotes_appstack.php to use UI kit (optional)
- ⏳ Refactor /crm/jobs_appstack.php to use UI kit (optional)
- ⏳ Refactor /crm/portfolio/index.php to use UI kit (optional)

### Week 4: Stabilization
- ⏳ Document best practices
- ⏳ Add new components as needed
- ⏳ Monitor performance

**Key:** Each refactoring is independent and deployable.

---

## Benefits

✅ **Consistency** — All admin pages look cohesive
✅ **Speed** — Reduce code duplication
✅ **Maintainability** — Update one component, all pages benefit
✅ **Low risk** — Gradual rollout, no big-bang changes
✅ **Non-breaking** — Works alongside existing pages
✅ **Extensible** — Add new components as needed
✅ **Accessible** — Built on AppStack (already accessible)
✅ **Responsive** — Bootstrap 4 responsive design

---

## Component Quick Reference

```php
// Load the kit
require 'admin-ui-kit.php';

// Render components
echo admin_table($data, $columns, $options);
echo admin_filter($filters, $active);
echo admin_badge($text, $variant);
echo admin_card($title, $content, $footer);
echo admin_modal($id, $title, $content);
echo admin_alert($message, $type);
echo admin_empty_state($title, $desc, $cta);
echo admin_breadcrumbs($items);
echo admin_stats($items);
```

---

## Next Steps

1. Create admin-ui-kit.php with all 10 components
2. Create admin-ui-components.css with minimal styles
3. Create admin-ui-components.js with interactivity
4. Use UI Kit for new CMS admin pages
5. Gradually refactor existing admin pages (optional)
6. Document component usage in this guide

Result: Professional, consistent, maintainable admin interface built incrementally on top of AppStack.
