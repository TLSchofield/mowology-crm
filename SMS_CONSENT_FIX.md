# SMS Consent Fix - Form Authorization Now Honored

## The Issue
When you pressed "resend" on a quote, the system was sending **email only** instead of **SMS**, even though the customer had authorized SMS when submitting their quote request form.

## Root Cause
The SMS consent check was only looking at the `contacts` table fields (`receive_sms`, `consent_sms`), but the actual customer authorization from the form submission is logged in the `consent_log` table.

```
Customer submits quote request form:
  "Do you want SMS updates?" → Customer selects: "Yes"
  
This creates:
  - consent_log record with consent_type='sms', consent_given=1
  - (NOT necessarily synced to contacts.receive_sms)

Old code was checking:
  - contacts.receive_sms (might be empty/NULL)
  - contacts.consent_sms (might be empty/NULL)
  
Result:
  - SMS consent appeared as "Not Allowed" in Communication Preferences
  - SMS was NOT sent during quote send/resend
```

## The Fix

Changed the SMS consent check to use a **two-tier approach**:

### 1. Check consent_log first (Form Submission - Source of Truth)
```sql
SELECT consent_given FROM consent_log
WHERE contact_id = ?
AND consent_type = 'sms'
ORDER BY created_at DESC
LIMIT 1
```

This checks the actual form submission record. If the customer said "yes" to SMS during the form, this will find it.

### 2. Fallback to contacts table (Backward Compatibility)
If no consent_log record exists, check the contacts table fields:
```sql
SELECT receive_sms, consent_sms FROM contacts WHERE id = ?
```

This provides compatibility with any SMS authorization set outside of form submissions.

## Impact

✅ **SMS is now sent correctly** when customers authorize it in the quote request form  
✅ **Communication Preferences card shows accurate status** (reflects form authorization)  
✅ **Form submission consent is honored** (source of truth)  
✅ **Backward compatible** (falls back to contacts table if needed)  
✅ **No data loss** (consent_log is append-only, captures all form submissions)  

## What Changed

File: `/public/crm/quotes/view.php` (lines 111-137)

### Before
```php
// Only checked contacts table
if (!empty($quote['qr_contact_id'])) {
    $smsStmt = $db->prepare("SELECT receive_sms, consent_sms FROM contacts WHERE id = ?");
    $smsStmt->execute([$quote['qr_contact_id']]);
    $contactPrefs = $smsStmt->fetch(PDO::FETCH_ASSOC);
    $customerConsentsToSms = !empty($contactPrefs['receive_sms']) || !empty($contactPrefs['consent_sms']);
}
```

### After
```php
// Now checks consent_log FIRST, then contacts table as fallback
if (!empty($quote['qr_contact_id'])) {
    // Check consent_log for form submission consent
    $consentStmt = $db->prepare("
        SELECT consent_given
        FROM consent_log
        WHERE contact_id = ?
        AND consent_type = 'sms'
        ORDER BY created_at DESC
        LIMIT 1
    ");
    $consentStmt->execute([$quote['qr_contact_id']]);
    $consentRecord = $consentStmt->fetch(PDO::FETCH_ASSOC);

    if ($consentRecord !== false) {
        // Use consent_log record (true source of authorization)
        $customerConsentsToSms = (bool)$consentRecord['consent_given'];
    } else {
        // Fallback to contacts table if no consent_log record
        $smsStmt = $db->prepare("SELECT receive_sms, consent_sms FROM contacts WHERE id = ?");
        $smsStmt->execute([$quote['qr_contact_id']]);
        $contactPrefs = $smsStmt->fetch(PDO::FETCH_ASSOC);
        $customerConsentsToSms = !empty($contactPrefs['receive_sms']) || !empty($contactPrefs['consent_sms']);
    }
}
```

## Testing

1. Go to the quote that wasn't sending SMS (e.g., QUO-2026-0011)
2. Look at "Communication Preferences" card → "Text/SMS"
3. It should now show "✓ Allowed" (if customer authorized in form)
4. Press "Resend"
5. **SMS should now be sent** (along with email, or just SMS if that's all they authorized)
6. Check the activity log - should show "Quote sent via SMS (Bell, Rogers, ...)"

## Database Tables Involved

| Table | Field | Purpose |
|-------|-------|---------|
| `consent_log` | `consent_given` | **PRIMARY** - Form submission authorization |
| `consent_log` | `contact_id` | Links consent to contact |
| `consent_log` | `consent_type` | Set to 'sms' for SMS authorization |
| `contacts` | `receive_sms` | Secondary - manual contact field |
| `contacts` | `consent_sms` | Secondary - alternative consent field |

The system now respects **form submission consent as the source of truth**, which is what users naturally expect.
