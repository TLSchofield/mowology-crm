# Quote SMS Send Testing Guide

## Problem
Quote sending is not delivering SMS even though the test form works.

## Changes Made
1. **Simplified SMS message** - removed long URL that may have caused issues
2. **Fixed From address** - changed back to office@mowology.ca (authenticated email)
3. **Added comprehensive debug logging** - logs every step of the send process
4. **Created debug-quote-send.php** - diagnostic tool to test SMS/email individually

## How to Test

### Step 1: Use the Debug Tool
Navigate to:
```
https://mowology.ca/crm/debug-quote-send.php?id=19
```
(Replace 19 with your quote ID)

This page shows:
- All contact information for the quote
- SMS consent status from consent_log
- Fallback consent status from contacts table
- **Test Email Send** button
- **Test SMS Send** button

### Step 2: Run Individual Tests
1. Click **"Send Test Email"** - verify you receive an email
2. Click **"Send Test SMS"** - verify you receive an SMS
3. If either fails, note the error message

### Step 3: If Tests Pass, Test Full Quote Send
1. Go back to the quote view: https://mowology.ca/crm/quotes/view.php?id=19
2. Click **"Send to Customer"** button
3. Check your email and SMS

### Step 4: Check Server Logs
If tests fail, check the PHP error log for debug messages starting with "QUOTE SEND DEBUG:"

Typical log output should look like:
```
QUOTE SEND DEBUG: Email=customer@example.com, Phone=7788469273, QR Contact ID=123
QUOTE SEND DEBUG: SMS consent ALLOWED via consent_log
QUOTE SEND DEBUG: Proceeding with send...
QUOTE SEND DEBUG: Starting email send to customer@example.com
QUOTE SEND DEBUG: sendCrmEmail returned=true
QUOTE SEND DEBUG: Starting SMS send to 7788469273
QUOTE SEND DEBUG: SMS result={"success":true,"delivered_carriers":["Bell",...]}
QUOTE SEND DEBUG: Final status update. EmailSent=YES, SMSSent=YES
```

## Expected Behavior

### If SMS Consent = YES:
- Both email and SMS should be sent
- Success message: "Quote sent successfully via email & SMS (Bell, Rogers, Telus...)"

### If SMS Consent = NO:
- Only email should be sent
- Success message: "Quote sent successfully via email"

## Troubleshooting

### "No contact information found"
- Check that the quote_requests table has a record linked to this quote
- Verify the contact has an email OR phone number

### "SMS consent NOT ALLOWED"
- The customer didn't check the SMS checkbox in the quote request form
- Check the consent_log table to see if there's a consent record

### "SMS shows as sent but doesn't arrive"
- The test SMS (sms-test-form.php) works but quote SMS doesn't
- Check the debug log - are the carriers showing as "Queued"?
- If yes, the mail server accepted it - delivery issue may be on carrier side
- Try running the test SMS to confirm carriers still work

## Files Modified

1. `/crm/quotes/view.php` - Added debug logging, simplified SMS message
2. `/crm/includes/sms_gateway.php` - Fixed From address back to office@mowology.ca
3. `/crm/debug-quote-send.php` - NEW - Debug tool
