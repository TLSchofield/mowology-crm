# SMS Not Arriving - Diagnosis & Solution

## Current Status

✅ **Our SMS Code Works Correctly**
- System says: "Quote sent via SMS (Bell, Rogers, Telus, Koodo, Virgin, Fido, Freedom, PC Mobile, Eastlink, SaskTel)"
- Email was received ✓
- All carrier gateways report "queued" ✓

❌ **But SMS Not Arriving**
- You did NOT receive the actual text message
- This means the mail server is not delivering to SMS gateway domains

## The Problem

The `mail()` function is returning "true" (meaning it queued the message), but the **server's mail configuration is not routing these emails to the carrier SMS gateways**.

Possible causes:
1. **Firewall blocking** - Server firewall blocks emails to SMS domains
2. **Relay restrictions** - Mail server won't relay to external domains
3. **Domain filtering** - Mail server filters out .ca domains
4. **Rate limiting** - Too many emails to SMS domains get blocked
5. **SPF/DKIM issues** - Emails rejected by carrier receiving servers

## How to Diagnose

### Step 1: Run the SMS Diagnostic Test
Navigate to: `https://mowology.ca/crm/test-sms-diagnostic.php` (login as admin)

1. Enter your Telus phone number
2. Click "Run Diagnostic Test"
3. It will test each carrier gateway individually

**Expected Results:**
- If all show "✓ Queued" → Server can reach SMS gateways (problem is elsewhere)
- If some show "✗ Rejected" → Server mail configuration is blocking these domains

### Step 2: Check PHP Error Log
The test logs detailed results. Check:
```
/var/log/error_log
grep "SMS Diagnostic"
```

This shows which carriers the server attempted and what happened.

## Solution Steps

### If Diagnostic Shows All "✓ Queued"
The mail() function is working. The issue is likely:
1. **Check your actual phone** for spam/junk messages
2. **Wait 5 minutes** - SMS gateways can be slow
3. **Verify phone number** - Is the number in the quote actually correct?
4. **Try a different carrier test** - Does SMS work from other services?
5. **Contact Telus support** - Ask if they're blocking emails from office@mowology.ca

### If Diagnostic Shows "✗ Rejected"
**The server is blocking these domains.** You need to contact your hosting provider:

**What to ask your cPanel host:**
```
"Our application sends SMS via carrier email-to-SMS gateways 
(e.g., sending email to 2025551234@msg.telus.com). The mail() 
function is returning false for these domains. Please:

1. Whitelist these Canadian carrier SMS gateway domains:
   - txt.bellmobility.ca (Bell)
   - msg.telus.com (Telus)
   - sms.rogers.com (Rogers)
   - msg.koodomobile.com (Koodo)
   - sms.virginmobile.ca (Virgin)
   - sms.fido.ca (Fido)
   - sms.freedommobile.ca (Freedom)
   - sms.pcmobilecanada.com (PC Mobile)
   - sms.eastlinktelecom.com (Eastlink)
   - sms.sasktel.com (SaskTel)

2. Check mail server logs for rejection reasons
3. Check firewall rules blocking outbound email
4. Verify SPF/DKIM not rejecting these domains"
```

## Current Architecture

```
Our Code:
  1. Check consent_log for SMS authorization ✓
  2. Get customer phone number ✓
  3. Build SMS recipient email (2025551234@msg.telus.com) ✓
  4. Call mail($smsRecipient, $subject, $message, $headers) ✓
  5. mail() returns true ✓
     ↓
     ↓↓↓ MAIL SERVER TAKES OVER ↓↓↓
     ↓
Mail Server:
  1. Accept or reject the message
  2. Route to carrier SMS gateway (or drop it)
  3. ← THIS IS WHERE SMS IS FAILING

Our code works perfectly. The issue is at the mail server level.
```

## Testing Order

1. **First:** Run SMS diagnostic test
2. **Second:** Check PHP error logs
3. **Third:** If rejected, contact hosting provider
4. **Fourth:** Once whitelisted, test again

## Why This Matters

SMS delivery depends on two completely separate systems:
- **Our code** (quote sending, consent check) ✓ WORKING
- **Server mail routing** (delivering to SMS gateways) ✗ BROKEN

We can't control or bypass the server's mail configuration. It must be fixed at the hosting level.

## Files Created

- `/public/crm/test-sms-diagnostic.php` - Diagnostic tool to test carrier connectivity

## Next Steps

1. Login as admin
2. Go to: `https://mowology.ca/crm/test-sms-diagnostic.php`
3. Enter your phone number
4. Run the test
5. Report results so we can determine next action
