# Consent Log Fix - Complete Two-Tier Implementation

## The Complete Issue

You were seeing two problems:
1. **SMS not being sent** when customer authorized it in the form
2. **Communication Preferences card showing "✗ Not Allowed"** even though customer authorized SMS

Both symptoms point to the same root cause: the system was not reading customer authorization from the correct database table.

## Where Customer Authorization Lives

When a customer submits the quote request form and checks "Yes, text me SMS updates":

```
consent_log table:
┌─────────────────────────────────────────┐
│ contact_id: 7                           │
│ consent_type: 'sms'                     │
│ consent_given: 1                        │
│ consent_source: 'website_form'          │
│ created_at: 2026-02-07 03:54:29         │
└─────────────────────────────────────────┘
       ↓
    This is the SOURCE OF TRUTH
    But the old code wasn't checking here!
```

The `contacts` table fields (`receive_sms`, `consent_sms`) might NOT be synced from form submissions, so they remain NULL/0.

## What I Fixed

### Two Places Needed Fixing

**1. Quote Send Logic** (lines 104-127)
- When pressing "Send" or "Resend", check consent_log for actual authorization
- Only check contacts table as fallback

**2. Communication Preferences Card** (lines 428-473)
- When displaying the preferences, also check consent_log  
- Shows the SAME consent status that determines whether SMS is sent
- Card and actual sending are now in sync

## The Two-Tier Approach

Both places now use this logic:

```
For SMS (and other consent types):
  ↓
  Check: SELECT consent_given FROM consent_log 
         WHERE contact_id = ? AND consent_type = 'sms'
  ↓
  If found → Use that value (form authorization is source of truth)
  If NOT found → Check contacts.receive_sms or contacts.consent_sms
  ↓
  Result: Accurate consent status
```

## What Changed

### File: `/public/crm/quotes/view.php`

#### Change 1: Send Logic (lines 104-127)
**Before:**
```php
// Only checked contacts table
$customerConsentsToSms = !empty($contactPrefs['receive_sms']) || !empty($contactPrefs['consent_sms']);
```

**After:**
```php
// Check consent_log FIRST (form submission = source of truth)
$consentStmt = $db->prepare("
    SELECT consent_given FROM consent_log
    WHERE contact_id = ? AND consent_type = 'sms'
    ORDER BY created_at DESC LIMIT 1
");
$consentStmt->execute([$quote['qr_contact_id']]);
$consentRecord = $consentStmt->fetch(PDO::FETCH_ASSOC);

if ($consentRecord !== false) {
    $customerConsentsToSms = (bool)$consentRecord['consent_given'];
} else {
    // Fallback to contacts table
    $customerConsentsToSms = !empty($contactPrefs['receive_sms']) || !empty($contactPrefs['consent_sms']);
}
```

#### Change 2: Communication Preferences Card (lines 428-473)
**Before:**
```php
// Only checked contacts table
$allowSms = (bool)($quote['qr_receive_sms'] ?? false) || (bool)($quote['qr_consent_sms'] ?? false);
$allowQuoteFollowup = (bool)($quote['consent_quote_followup'] ?? false);
$allowMarketingEmail = (bool)($quote['consent_marketing_email'] ?? false);
```

**After:**
```php
// Check consent_log FIRST for SMS
$consentStmt = $db->prepare("
    SELECT consent_given FROM consent_log
    WHERE contact_id = ? AND consent_type = 'sms'
    ORDER BY created_at DESC LIMIT 1
");
$consentStmt->execute([$commContactId]);
$consentRecord = $consentStmt->fetch(PDO::FETCH_ASSOC);

if ($consentRecord !== false) {
    $allowSms = (bool)$consentRecord['consent_given'];
} else {
    $allowSms = (bool)($quote['qr_receive_sms'] ?? false) || (bool)($quote['qr_consent_sms'] ?? false);
}

// Same approach for quote_followup and marketing_email
// [Similar consent_log checks]
```

## Why This Matters

The `consent_log` table is the **audit trail** of customer consent decisions:
- Created when form is submitted
- Immutable (append-only)
- Source of truth for what customer actually authorized
- Never overwritten by other processes

The `contacts` table fields are optional/secondary:
- May or may not be synced from forms
- Can be manually edited
- Useful for custom tracking but shouldn't override form consent

## Result

✅ **SMS sends when customer authorized it in form**
✅ **Communication Preferences card accurately shows "✓ Allowed"**
✅ **Quote send/resend sends same channels as card displays**
✅ **Form authorization is respected (not second-guessed)**
✅ **Backward compatible** (falls back if no consent_log record)

## Testing

1. Go to quote 19: `https://mowology.ca/crm/quotes/view.php?id=19`
2. Look at **Communication Preferences** card
3. **Text/SMS** should now show **"✓ Allowed"** (if customer authorized in form)
4. Press **"Resend"**
5. **SMS should now be sent** (you'll see "Quote sent via SMS (Bell, Rogers, ...)" in Activity)
6. Check your phone - SMS should arrive within 1-2 minutes

## Files Modified

- `/public/crm/quotes/view.php`
  - Lines 104-127: Send logic (consent_log check)
  - Lines 428-473: Communication Preferences card (consent_log checks)

No database schema changes needed - uses existing `consent_log` table.
