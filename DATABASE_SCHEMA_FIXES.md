# Database Schema Fixes

## Issue

Several files were referencing a non-existent `company_id` column on the `properties` table, causing PDOException errors:

```
Unknown column 'p.company_id' in 'on clause'
```

## Root Cause

The relationship between properties and companies uses a **junction table** (`company_properties`), not a direct foreign key on `properties`.

### Correct Schema

```sql
-- Properties table (NO company_id column)
CREATE TABLE properties (
    id INT PRIMARY KEY,
    address VARCHAR(255),
    ...
);

-- Quotes table (HAS company_id column)
CREATE TABLE quotes (
    id INT PRIMARY KEY,
    company_id INT,          -- Direct link to companies
    property_id INT,         -- Link to properties
    ...
);

-- Junction table for property-company relationships
CREATE TABLE company_properties (
    id INT PRIMARY KEY,
    company_id INT,
    property_id INT,
    is_primary TINYINT(1),
    ...
);
```

## Files Fixed

### 1. `/crm/quotes/view.php` (Line 42)

**Before:**
```php
LEFT JOIN companies c ON p.company_id = c.id
```

**After:**
```php
LEFT JOIN companies c ON q.company_id = c.id
```

**Reason:** Company ID comes from the `quotes` table, not `properties`.

---

### 2. `/crm/quotes/create.php` (Line 36)

**Before:**
```php
SELECT p.id, p.address, p.city, p.property_type, c.company_name, c.id as company_id
FROM properties p
LEFT JOIN companies c ON p.company_id = c.id
```

**After:**
```php
SELECT DISTINCT p.id, p.address, p.city, p.property_type, c.company_name, c.id as company_id
FROM properties p
LEFT JOIN company_properties cp ON p.id = cp.property_id
LEFT JOIN companies c ON cp.company_id = c.id
```

**Reason:** Use the junction table to find companies associated with each property.

---

### 3. `/crm/includes/PdfGenerator.php` (Line 84)

**Before:**
```php
LEFT JOIN companies c ON p.company_id = c.id
```

**After:**
```php
LEFT JOIN companies c ON q.company_id = c.id
```

**Reason:** For PDF generation of quotes, use company ID from the quote, not property.

---

### 4. `/crm/jobs/create.php` (Lines 23 and 46)

**Line 23 - Before:**
```php
SELECT q.*, p.company_id, p.address, p.city, c.company_name
FROM quotes q
JOIN properties p ON q.property_id = p.id
JOIN companies c ON p.company_id = c.id
```

**Line 23 - After:**
```php
SELECT q.*, q.company_id, p.address, p.city, c.company_name
FROM quotes q
JOIN properties p ON q.property_id = p.id
LEFT JOIN companies c ON q.company_id = c.id
```

**Line 46 - Before:**
```php
SELECT p.id, p.address, p.city, p.property_type, c.company_name, c.id as company_id
FROM properties p
LEFT JOIN companies c ON p.company_id = c.id
```

**Line 46 - After:**
```php
SELECT DISTINCT p.id, p.address, p.city, p.property_type, c.company_name, c.id as company_id
FROM properties p
LEFT JOIN company_properties cp ON p.id = cp.property_id
LEFT JOIN companies c ON cp.company_id = c.id
```

**Reason:** When creating from quote, get company from quote. When listing properties, use junction table.

---

### 5. `/crm/customer/quote.php` (Line 37)

**Before:**
```php
LEFT JOIN companies c ON p.company_id = c.id
```

**After:**
```php
LEFT JOIN companies c ON q.company_id = c.id
```

**Reason:** Customer-facing quote view should use company from quote, not property.

---

## Key Pattern

**Two distinct scenarios:**

### Scenario A: Getting Company for a Quote
```php
SELECT * FROM quotes q
LEFT JOIN companies c ON q.company_id = c.id
WHERE q.id = ?
```
✅ Direct join on `quotes.company_id`

### Scenario B: Getting Companies for All Properties
```php
SELECT DISTINCT p.id, c.id FROM properties p
LEFT JOIN company_properties cp ON p.id = cp.property_id
LEFT JOIN companies c ON cp.company_id = c.id
```
✅ Use junction table to find related companies

---

## Testing

After fixes, verify:

1. **View Quote** - `https://mowology.ca/crm/quotes/view.php?id=1`
2. **Create Quote** - `https://mowology.ca/crm/quotes/create.php`
3. **Create Job** - `https://mowology.ca/crm/jobs/create.php`
4. **Generate PDF** - Button on quote view
5. **Customer Quote** - Share token link to customer

All should work without database errors.

---

## Related Tables

```sql
companies (id, company_name, primary_contact_id, ...)
contacts (id, first_name, last_name, ...)
properties (id, address, city, ...)
company_properties (id, company_id, property_id, is_primary)
quotes (id, company_id, property_id, ...)
jobs (id, property_id, ...)
```

---

## Summary

✅ Fixed 5 files with incorrect schema references
✅ All company lookups now use correct tables/columns
✅ No data migration required (only query fixes)
✅ All existing data intact

**Status:** Ready for production
