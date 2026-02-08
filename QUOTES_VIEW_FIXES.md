# Quotes View Fixes

## Issues Fixed

### 1. Deprecated htmlspecialchars() Warning
**Location:** `/crm/quotes/view.php`, Line 101

**Error Message:**
```
Deprecated: htmlspecialchars(): Passing null to parameter #1 ($string) of type string is deprecated
```

**Root Cause:**
The `$customerName` variable could be null or empty when `htmlspecialchars()` was called.

**Original Code:**
```php
$customerName = trim(($quote['contact_first'] ?? '') . ' ' . ($quote['contact_last'] ?? '')) ?: $quote['company_name'];
// ... later in email body:
<p>Hi " . htmlspecialchars($customerName) . ",</p>
```

**Problem:** If both contact name and company name are missing, `$customerName` could be null, and `htmlspecialchars()` in PHP 8.1+ doesn't accept null.

**Fixed Code:**
```php
$customerName = trim(($quote['contact_first'] ?? '') . ' ' . ($quote['contact_last'] ?? '')) ?: ($quote['company_name'] ?? 'Valued Customer');
// ... later in email body:
<p>Hi " . htmlspecialchars($customerName ?: 'Valued Customer') . ",</p>
```

**What Changed:**
- Added fallback to `'Valued Customer'` if company name is also missing
- Added secondary fallback in `htmlspecialchars()` call for defense in depth

---

### 2. Undefined Variable Warning
**Location:** `/crm/quotes/view.php`, Line 132

**Error Message:**
```
Warning: Undefined variable $attachPath
```

**Root Cause:**
The `$attachPath` variable was initialized inside an if-block, making it undefined when accessed outside that block.

**Original Code:**
```php
if ($customerEmail) {
    // Check if company prefers PDF attachment
    $attachPath = null;  // <-- initialized here
    $companyId = (int)($quote['company_id'] ?? 0);
    if (companyPrefersAttachment($companyId)) {
        // ... set $attachPath
    }
    sendCrmEmail($customerEmail, $emailSubject, $emailBody, $attachPath);
}

$attachNote = $attachPath ? ' (with PDF attached)' : '';  // <-- used here, but undefined if $customerEmail was falsy
```

**Problem:** If `$customerEmail` is empty/falsy, the if-block doesn't execute, so `$attachPath` is never defined.

**Fixed Code:**
```php
$attachPath = null;  // <-- initialize BEFORE the if-block
if ($customerEmail) {
    $companyId = (int)($quote['company_id'] ?? 0);
    if (companyPrefersAttachment($companyId)) {
        // ... set $attachPath
    }
    sendCrmEmail($customerEmail, $emailSubject, $emailBody, $attachPath);
}

$attachNote = ($attachPath !== null) ? ' (with PDF attached)' : '';  // <-- now always defined
```

**What Changed:**
- Moved `$attachPath = null;` outside and before the if-block
- Changed condition from `$attachPath ?` to `($attachPath !== null) ?` for clarity (though both work)

---

## Testing

### How to Test These Fixes

1. **Test sending a quote without contact info:**
   - Create a quote with no contact name or company name assigned
   - Send the quote
   - Should not show deprecated warning

2. **Test sending a quote without email:**
   - Create a quote with no customer email
   - Send the quote
   - Should not show undefined variable warning

3. **Test sending a quote with PDF attachment:**
   - Configure company to prefer PDF attachments
   - Send a quote
   - Should show "(with PDF attached)" in activity log

---

## Code Quality Improvements

### Best Practices Applied

1. **Null Coalescing Operator (??):**
   - Used for safe variable fallbacks
   - Example: `$quote['company_name'] ?? 'Valued Customer'`

2. **Ternary Fallbacks:**
   - Double fallback for maximum safety
   - Example: `$customerName ?: 'Valued Customer'`

3. **Early Initialization:**
   - Variables initialized before conditional blocks
   - Prevents undefined variable warnings
   - Makes code flow clearer

4. **Explicit Type Checking:**
   - Using `!== null` instead of truthiness check
   - More precise than `if ($attachPath)`

---

## Files Modified

- `/crm/quotes/view.php`

---

## Related Functions

- `htmlspecialchars()` — Escapes HTML special characters (requires string input)
- `sendCrmEmail()` — Sends email with optional attachment
- `companyPrefersAttachment()` — Checks company PDF preference
- `PdfGenerator::generateQuotePdf()` — Generates PDF file

---

## Changelog

**v1.0 (Feb 6, 2026)**
- ✅ Fixed deprecated htmlspecialchars() warning
- ✅ Fixed undefined $attachPath variable warning
- 🔧 Improved variable initialization
- 🔒 Better null handling with fallbacks
