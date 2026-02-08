# Quote Submission Troubleshooting Guide

## Problem: Quote submissions not appearing in database or email/SMS not received

### Steps to Diagnose:

#### 1. Run the Diagnostic Test
Visit: https://www.mowology.ca/jobFlow/test-submission.php

This will show:
- ✓/✗ Database connection status
- ✓/✗ Required tables exist
- Email and SMS gateway configuration
- Recent quote requests in the database
- Mail function availability
- Any errors in the error log

#### 2. Check Error Logs
On cPanel or SSH:
```bash
tail -f /home/mowology/logs/error_log
# or
tail -f /var/log/php-errors.log
```

Look for lines containing "jobFlow" or "Quote" with error messages.

#### 3. Test Form Submission Flow

The quote form flow works like this:

1. **jobFlow-getQuote.php** (Step 1: Initial form)
   - User fills form and submits
   - If new contact → redirects to `jobFlow-confirm.php`
   - If existing contact → shows address options → posts `address_confirmed=1` → redirects to `jobFlow-confirm.php`

2. **jobFlow-confirm.php** (Step 2: Review & confirm)
   - Displays confirmation page
   - User clicks "Confirm Quote Request"
   - Data is INSERT INTO:
     - `contacts` table
     - `properties` table
     - `quote_requests` table
     - `consent_log` table
     - `activity_log` table
   - Sends email to `mowology@icloud.com`
   - Sends SMS to `7788469273@msg.telus.com` (Telus email-to-SMS gateway)
   - Redirects to `jobFlow-success.php`

3. **jobFlow-success.php** (Step 3: Thank you page)
   - Just shows success message

#### 4. Common Issues & Solutions

**Issue: User sees "No quote_data in session" error**
- Root Cause: User didn't complete Step 1 properly
- Solution: Check that the initial form submission works, especially reCAPTCHA

**Issue: Quote doesn't appear in database**
- Root Cause: Database insert failed silently (exception caught but user still sees success page)
- Solution: Check error logs for "Quote submission error"
- Check that all required fields are filled
- Verify `quote_requests` table exists: `SHOW TABLES LIKE 'quote_requests';`

**Issue: Email not received**
- Root Cause: PHP mail() function not working or email filtered by spam
- Solution:
  - Check if notification was sent: See error log for "Quote notification sent"
  - If failed: Check that `mail()` function is enabled on cPanel
  - If sent but not received: Check spam folder or email filtering rules
  - Test recipient email configuration (should be `mowology@icloud.com`)

**Issue: SMS not received**
- Root Cause: Telus SMS gateway not working or message not formatted correctly
- Solution:
  - SMS uses Telus gateway: `7788469273@msg.telus.com`
  - Message must be short (160 chars max)
  - Check error log for "Quote notification sent"
  - Verify phone number is valid format

#### 5. Manual Testing

To test without using the web form, use MySQL directly:

```sql
-- Check if recent quote requests exist
SELECT id, email, phone, address, created_at
FROM quote_requests
ORDER BY created_at DESC
LIMIT 5;

-- Test contact creation
INSERT INTO contacts (first_name, last_name, email, phone, created_at)
VALUES ('Test', 'User', 'test@example.com', '7788469273', NOW());

-- Check quote request details
SELECT * FROM quote_requests WHERE id = 1 \G
```

#### 6. Enable Debug Mode

Edit `/jobFlow/jobFlow-confirm.php` and change:
```php
error_reporting(E_ALL);
ini_set('display_errors', '1');
```

This will show errors directly on the page (use only for testing!).

#### 7. Contact Information
- **Email recipient:** mowology@icloud.com
- **SMS gateway:** 7788469273@msg.telus.com
- **Phone:** (778) 846-9273

---

**Last Updated:** Feb 6, 2026
