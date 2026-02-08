# Quotes List — Client Name Display Fix

## Problem

Client names were not displaying on the quotes list page at `/crm/quotes_appstack.php`

**Expected:** Client name shown in table  
**Actual:** Empty or "N/A" for most quotes

## Root Cause

The SQL query was missing a critical data relationship:

**Quote Data Model Issue:**
1. Quotes link to `companies` table via `company_id`
2. But many quotes (from incoming requests) DON'T have a company yet
3. Quotes link to `properties` via `property_id`
4. Properties link to `contacts` via `contact_id`
5. The original query only joined company contacts, ignoring property contacts

**Original Query (lines 63-84):**
```sql
SELECT q.*, c.company_name, ct.first_name, ct.last_name
FROM quotes q
LEFT JOIN companies c ON q.company_id = c.id
LEFT JOIN contacts ct ON c.primary_contact_id = ct.id  ← ONLY gets company contact
```

If `q.company_id` is NULL, the entire JOIN chain fails and `ct.*` columns are NULL.

**Result:** Client name always shows as "N/A" for quotes without a company.

## Solution

Added a SECOND contact join to the properties table:

**Updated Query (lines 63-90):**
```sql
SELECT q.*,
    c.company_name,
    ct.first_name as contact_first,
    ct.last_name as contact_last,
    pct.first_name as property_contact_first,     ← NEW
    pct.last_name as property_contact_last,       ← NEW
    pct.email as property_contact_email           ← NEW
FROM quotes q
LEFT JOIN companies c ON q.company_id = c.id
LEFT JOIN contacts ct ON c.primary_contact_id = ct.id
LEFT JOIN contacts pct ON p.contact_id = pct.id  ← NEW JOIN
```

**Updated Display Logic (lines 227-245):**

Now uses a fallback priority:
1. Company name (if quote has a company)
2. Company contact name (if quote linked to company contact)
3. **Property contact name** (if quote from incoming request)
4. "N/A" (if none available)

```php
// Priority: company name > company contact > property contact > N/A
$clientName = '';

// 1. Try company name
if (!empty($quote['company_name'])) {
    $clientName = $quote['company_name'];
}
// 2. Try company contact
elseif (!empty($quote['contact_first']) || !empty($quote['contact_last'])) {
    $clientName = trim(($quote['contact_first'] ?? '') . ' ' . ($quote['contact_last'] ?? ''));
}
// 3. Try property contact
elseif (!empty($quote['property_contact_first']) || !empty($quote['property_contact_last'])) {
    $clientName = trim(($quote['property_contact_first'] ?? '') . ' ' . ($quote['property_contact_last'] ?? ''));
}

if (empty($clientName)) $clientName = 'N/A';
```

## Files Modified

- `/public/crm/quotes_appstack.php`
  - Lines 62-90: SQL query (added property contact join)
  - Lines 226-248: Display logic (added fallback priority)

## Verification

Navigate to: `https://www.mowology.ca/crm/quotes_appstack.php`

Expected results:
- ✓ All quotes now show client names in "Client" column
- ✓ Quotes from incoming requests show contact name
- ✓ Quotes linked to companies show company name
- ✓ No "N/A" values unless truly missing data

## Related Database Schema

### quotes table
```
id, company_id, property_id, quote_number, ...
```

### properties table
```
id, contact_id, ...
```

### companies table
```
id, primary_contact_id, company_name, ...
```

### contacts table
```
id, first_name, last_name, email, ...
```

**Relationship:**
- Quote → Company (optional, via company_id)
- Quote → Property (required, via property_id)  
- Property → Contact (contact_id for residential property owner/manager)
- Company → Contact (primary_contact_id)

---

**Last Updated:** 2026-02-06  
**Fixed By:** Claude Code
