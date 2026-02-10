# Quote Workflow Error Fix

## Problem

**Error:** `SQLSTATE[42S22]: Column not found: 1054 Unknown column 'p.total_lawn_sqft'`

**File:** `/crm/quote-workflow.php:201`

**Root Cause:** Production database missing columns `total_lawn_sqft` and `total_driveway_sqft` from the properties table.

---

## Solution

### Quick Fix (Recommended)

Run this CLI command:

```bash
php /home/mowology/public_html/crm/api/fix-properties-columns.php
```

This script will:
- Add the missing columns to the properties table
- Verify they were added successfully
- Output status report

### Alternative: Manual SQL

```sql
ALTER TABLE `properties`
ADD COLUMN IF NOT EXISTS `total_lawn_sqft` DECIMAL(12,2) DEFAULT NULL,
ADD COLUMN IF NOT EXISTS `total_driveway_sqft` DECIMAL(12,2) DEFAULT NULL,
ADD COLUMN IF NOT EXISTS `measurements_updated_at` TIMESTAMP NULL DEFAULT NULL;
```

---

## What Was Fixed

### 1. quote-workflow.php (Lines 200-230)

**Changed:** SQL query now uses COALESCE() for safe column access

**Why:** Gracefully handles databases with or without the measurement columns

**Code:**
```php
COALESCE(p.total_lawn_sqft, 0) AS total_lawn_sqft,
COALESCE(p.total_driveway_sqft, 0) AS total_driveway_sqft
```

Added fallback query if columns don't exist yet.

### 2. New: fix-properties-columns.php

**Location:** `/crm/api/fix-properties-columns.php`

**Purpose:** Automatically add missing columns

**Usage:**
```bash
# CLI
php /home/mowology/public_html/crm/api/fix-properties-columns.php

# Web (requires admin)
https://mowology.ca/crm/api/fix-properties-columns.php
```

---

## Verification

### Check Columns Exist

```sql
SHOW COLUMNS FROM properties
WHERE Field IN ('total_lawn_sqft', 'total_driveway_sqft', 'measurements_updated_at');
```

Should return 3 rows.

### Test Quote Workflow

1. Go to CRM Dashboard
2. Open any quote request  
3. Quote workflow page should load
4. No error should appear

---

## Files Changed

- ✅ `/public/crm/quote-workflow.php` — FIXED
- ✅ `/public/crm/api/fix-properties-columns.php` — NEW

---

## Prevention

To prevent future issues, run all migrations on deployment:

```bash
php /home/mowology/public_html/apply-migrations-cli.php
```

---

**Status:** ✅ Fixed
**Date:** February 10, 2026
