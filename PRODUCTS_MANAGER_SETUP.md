# Products Manager Setup Guide

## Quick Start

### 1. Run the Migration (First Time Only)

The new features require database changes. Run the migration script once:

1. Visit: `https://mowology.ca/crm/products/migrate_add_archive.php`
2. You must be logged in as an admin
3. The script will add the required columns:
   - `products.is_archived` (archive status)
   - `products.archived_at` (when it was archived)
   - `products.archived_by` (who archived it)

**After migration, you can use all new features immediately.**

---

## What Was Added

### Files Created

| File | Purpose |
|------|---------|
| `/crm/products/api-products.php` | Backend API for category/product CRUD operations |
| `/crm/products/migrate_add_archive.php` | Database migration script (run once) |
| `/crm/products/FEATURES.md` | Detailed feature documentation |
| `/crm/products/database_schema.sql` | Updated schema with archive columns |

### Files Modified

| File | Changes |
|------|---------|
| `/crm/products/products-manager.php` | Added category management UI and archive functionality |
| `/crm/css/mowology-brand.css` | Added styles for category manager and archived products |

---

## New Features

### 1. Category Management

**Access:** Click "Manage Categories" button in Products Manager

**What you can do:**
- ✅ Add new categories on-the-fly
- ✅ View all existing categories
- ✅ Delete unused categories (no active products required)

**Categories appear in:**
- Product creation dropdown (pre-fill category)
- Filter dropdown (search by category)
- Product grid display

### 2. Product Archiving

**Why archiving instead of deletion?**
- Preserves quote/invoice history
- Keeps line item data intact
- Allows products to be hidden without breaking records
- Can be restored if needed

**What you can do:**
- ✅ Archive active products (they stop appearing in new quotes)
- ✅ View archived products (filter by status)
- ✅ Restore archived products to active status
- ✅ Archived products show "ARCHIVED" badge

---

## How to Use

### Adding a Category

1. Go to **Products Manager**
2. Click **"Manage Categories"** button
3. Enter category name and description
4. Click **"Add Category"**
5. New category appears in product dropdown immediately

### Archiving a Product

1. Find the product in the grid
2. Click **"Archive"** button
3. Confirm when prompted
4. Product becomes semi-transparent with "ARCHIVED" badge

### Viewing Archived Products

1. Use the **Status** filter dropdown
2. Select **"Archived"**
3. Grid shows only archived products
4. Click **"Restore"** to make active again

### Filtering Products

| Filter | Use |
|--------|-----|
| **Search box** | Find by name, description, or SKU |
| **Category dropdown** | Show products in a specific category |
| **Status dropdown** | Active / Inactive / Archived |

---

## Database Schema Changes

### New Columns (Added by Migration)

```sql
ALTER TABLE products ADD COLUMN is_archived TINYINT(1) DEFAULT 0;
ALTER TABLE products ADD COLUMN archived_at TIMESTAMP NULL;
ALTER TABLE products ADD COLUMN archived_by INT;
ALTER TABLE products ADD INDEX idx_archived (is_archived);
```

### What Each Column Does

| Column | Purpose |
|--------|---------|
| `is_archived` | 1 = archived, 0 = active |
| `archived_at` | Timestamp when archived (NULL if active) |
| `archived_by` | User ID who archived it |

### Existing Data

- All existing products will have `is_archived = 0` (active) after migration
- No data is lost or modified
- All historical quotes/invoices remain unchanged

---

## API Reference

All endpoints are at `/crm/products/api-products.php`

### Category Endpoints

```javascript
// Get all active categories
GET ?action=get-categories

// Add category
POST ?action=add-category
{ "name": "...", "description": "..." }

// Update category
POST ?action=update-category
{ "id": 123, "name": "...", "description": "..." }

// Delete category
POST ?action=delete-category
{ "id": 123 }
```

### Product Endpoints

```javascript
// List products
GET ?action=list-products&category=123&search=cedar&archived=0

// Save product (create/update)
POST ?action=save-product
{ "name": "...", "category_id": 1, ... }

// Archive product
POST ?action=archive-product
{ "id": 123 }

// Restore product
POST ?action=restore-product
{ "id": 123 }
```

---

## Important Notes

### Security
- Only logged-in users can manage products/categories
- Admin role required for migrations
- All input is sanitized and prepared statements used
- CSRF protection recommended for forms

### Performance
- Categories are cached on page load
- Product filtering is done via API (respects permissions)
- Archived products don't appear in normal filters by default
- Indexes added for fast queries

### Backup Recommendation
- Back up your database before running the migration
- Test on a staging environment first if possible

---

## Troubleshooting

### Migration script shows error
- Make sure you're logged in as admin
- Check database permissions
- See the error message for details

### Can't delete a category
- Category still has active products
- Archive those products first

### Archived products still showing in quotes
- Quotes created before archiving will still use archived products
- This is intentional (preserves history)
- Only new quotes are affected

### Product doesn't appear after creation
- Check if it's inactive (Status filter)
- Verify category is selected correctly
- Try clearing search/filters

---

## Support

For detailed feature documentation, see: `/crm/products/FEATURES.md`

For database schema details, see: `/crm/products/database_schema.sql`

---

## Changelog

**v1.0 (Feb 6, 2026)**
- ✨ Category management (add, delete, organize)
- ✨ Product archiving (soft delete with history preservation)
- 🎨 Updated UI with category manager modal
- 🗄️ Database migration for archive columns
- 📖 Full documentation and API reference
