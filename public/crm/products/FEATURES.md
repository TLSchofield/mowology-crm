# Products Manager - New Features

## Overview

The Products Manager now includes **category management** and **product archiving** features to help you organize and maintain your product catalog.

## Features

### 1. Category Management

Manage product categories directly from the Products Manager UI.

#### Adding a New Category

1. Click **"Manage Categories"** button in the filter bar
2. In the modal, enter:
   - **Category Name** (required) — e.g., "Design Services"
   - **Description** (optional) — e.g., "Custom landscape design"
3. Click **"Add Category"**

The new category will immediately appear in:
- The "Add/Edit Product" dropdown
- The category filter dropdown

#### Deleting a Category

1. Click **"Manage Categories"**
2. Find the category in the "Existing Categories" list
3. Click the **trash icon** to delete

**Note:** You can only delete categories that have no active products. If a category has active products, you must archive those products first.

#### Why Use Categories?

- Organize products by type (labor, materials, services)
- Filter the product grid by category
- Pre-fill category selection when creating products
- Generate reports by category

---

### 2. Product Archiving

Instead of permanently deleting products, **archive them** to preserve quote and invoice history.

#### Why Archive Instead of Delete?

When you delete a product that's referenced in existing quotes/invoices:
- Historical records break (missing line item details)
- Reports become inaccurate
- You lose cost/pricing information

**Archiving solves this:** Archived products:
- Don't appear in new quote/product selection dropdowns
- Remain available for viewing existing quotes
- Keep their historical data intact
- Can be restored if needed

#### Archiving a Product

1. Find the product in the grid
2. Click **"Archive"** button
3. Confirm when prompted

The product will:
- Show an **"ARCHIVED"** badge
- Become semi-transparent in the grid
- No longer appear in quote/job creation

#### Restoring an Archived Product

1. Filter by status: **"Archived"** (in the status dropdown)
2. Find the product
3. Click **"Restore"** button

The product will be active again and available for new quotes.

---

## Database Changes

### Migration Required

If this is an existing installation, run the migration:

1. Go to: `/crm/products/migrate_add_archive.php`
2. The script will add the required columns:
   - `is_archived` (0 or 1)
   - `archived_at` (timestamp)
   - `archived_by` (user ID)

**Note:** Back up your database before running any migrations.

### Schema Updates

```sql
-- New columns added to products table
ALTER TABLE products ADD COLUMN is_archived TINYINT(1) DEFAULT 0;
ALTER TABLE products ADD COLUMN archived_at TIMESTAMP NULL;
ALTER TABLE products ADD COLUMN archived_by INT;
ALTER TABLE products ADD INDEX idx_archived (is_archived);
```

---

## API Endpoints

All category and product operations use `/crm/products/api-products.php`:

### Category Operations

#### Get all active categories
```
GET /api-products.php?action=get-categories
```

#### Add a category
```
POST /api-products.php?action=add-category
Body: { "name": "...", "description": "..." }
```

#### Update a category
```
POST /api-products.php?action=update-category
Body: { "id": 123, "name": "...", "description": "..." }
```

#### Delete a category
```
POST /api-products.php?action=delete-category
Body: { "id": 123 }
```

### Product Operations

#### List products
```
GET /api-products.php?action=list-products[&category=123&search=...&archived=1]
```

#### Save product (create or update)
```
POST /api-products.php?action=save-product
Body: { ...product data... }
```

#### Archive a product
```
POST /api-products.php?action=archive-product
Body: { "id": 123 }
```

#### Restore a product
```
POST /api-products.php?action=restore-product
Body: { "id": 123 }
```

---

## Filtering

### By Category
Click the **Category** dropdown to show only products in a specific category.

### By Status
- **All Status** — Show all products (archived + active)
- **Active** — Only active products
- **Inactive** — Products marked as inactive but not archived
- **Archived** — Only archived products

### Search
Type in the **Search** box to find products by name, description, or SKU.

---

## Best Practices

1. **Archive, don't delete** — Preserve quote history
2. **Use categories** — Keep products organized for easier filtering
3. **Name categories clearly** — "Premium Services" vs "Svc 1"
4. **Review archives quarterly** — Delete truly obsolete products after 1-2 years
5. **Restore carefully** — Make sure old pricing still makes sense

---

## Troubleshooting

### Can't delete a category
**Reason:** It still has active products.
**Solution:** Archive those products first, then try again.

### Product doesn't appear in quote creation
**Reason:** It might be archived or marked as inactive.
**Solution:** Check the product status and restore it if needed.

### Can't find a product
**Reason:** It might be archived.
**Solution:** Change the status filter to "Archived" to see it.

---

## Implementation Details

- **Frontend:** JavaScript in `products-manager.php` handles all UI interactions
- **Backend:** `api-products.php` provides RESTful API for all operations
- **Database:** `migrate_add_archive.php` handles schema updates
- **Styling:** `mowology-brand.css` contains all UI styles
