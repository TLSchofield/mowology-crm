# SMS Gateway Troubleshooting Guide

## Issue: Email Sent, But SMS Not Received

You've confirmed that email is being sent successfully, but the SMS is not arriving. Here are the most common reasons and solutions:

### 1. ❌ Customer Did Not Consent to SMS

**Root Cause:** During the quote request form submission, the customer was not asked about SMS, or they said "No" to receiving SMS.

**How to Check:**
1. Go to the quote details page
2. Look at the customer's contact record
3. Check if `receive_sms` or `consent_sms` is enabled (1) in the contacts table

**Solution:**
- The SMS consent check is only queried from the `contacts` table during quote sending
- If the customer never opted in during the form, SMS won't be sent
- You can manually enable SMS consent on the contact record if the customer verbally approved

### 2. ❌ Phone Number Format Issue

**Root Cause:** The phone number is not in a valid North American format, or includes invalid characters that aren't being parsed correctly.

**Valid Formats:**
- ✅ `2025551234` (10 digits)
- ✅ `(202) 555-1234` (with parentheses and dashes)
- ✅ `202-555-1234` (with dashes)
- ✅ `+1-202-555-1234` (with country code)
- ❌ `1-202-555-1234 ext. 5` (extensions not supported)
- ❌ Empty or invalid numbers

**How to Check:**
1. Go to CRM > Test SMS (test-sms.php)
2. Enter the phone number from the quote request
3. If it fails with "Invalid phone number format", the number needs to be corrected

**Solution:**
- Check the quote request contact information
- Correct any malformed phone numbers in the contacts table
- Ensure all phone numbers are valid 10-digit North American format

### 3. ❌ Mail Server Not Routing to SMS Gateway Domains

**Root Cause:** The server's mail() function is not accepting emails to SMS gateway domains (txt.bellmobility.ca, sms.rogers.com, etc.)

**How to Check:**
1. Navigate to `https://your-domain/crm/test-sms.php` (requires login as admin)
2. Enter a test phone number (your phone)
3. Enter a test message
4. Click "Send Test SMS"
5. Check the result for which carriers accepted/rejected the message

**Possible Results:**
- ✅ **All carriers succeeded** → SMS should arrive within 1-2 minutes
- ⚠️ **Some carriers succeeded** → SMS may arrive via that carrier
- ❌ **All carriers failed** → Server mail configuration issue

**Solution if All Carriers Failed:**
1. Check with your hosting provider (cPanel admin)
2. The mail server may have restrictions on:
   - Outbound email to .ca or .com domains
   - Rate limiting on email volume
   - Firewall rules blocking carrier domains
3. Ask your host to:
   - Whitelist the Canadian carrier SMS domains
   - Check mail server logs for bounce messages
   - Increase email rate limits if needed

---

## Testing the SMS System

### Create a Test Quote
1. Create a test quote request with SMS consent enabled
2. Create a draft quote from the request
3. Press "Send to Customer"
4. Check if SMS is sent

### Manual SMS Test
1. Login as admin
2. Go to `/crm/test-sms.php`
3. Enter your phone number and a test message
4. Click "Send Test SMS"
5. Check your phone for the SMS within 1-2 minutes

### Check Error Logs
The SMS gateway logs all attempts to `/var/log/error_log` or PHP error log:
- Look for lines with "SMS Gateway:"
- Check which carriers accepted/rejected
- Look for mail() function errors

---

## Canadian Carrier SMS Gateway Domains

The system tries to send to all of these in parallel. At least one should succeed:

| Carrier | Domain | Coverage |
|---------|--------|----------|
| Bell Mobility | txt.bellmobility.ca | Nationwide |
| Rogers Wireless | sms.rogers.com | Nationwide |
| Telus Mobility | msg.telus.com | Nationwide |
| Koodo (TELUS) | msg.koodomobile.com | Nationwide |
| Virgin Mobile | sms.virginmobile.ca | Nationwide |
| Fido (Rogers) | sms.fido.ca | Nationwide |
| Freedom Mobile | sms.freedommobile.ca | Nationwide |
| PC Mobile | sms.pcmobilecanada.com | Nationwide |
| Eastlink | sms.eastlinktelecom.com | Atlantic Canada |
| SaskTel | sms.sasktel.com | Saskatchewan |

---

## How Email-to-SMS Gateway Works

Instead of using an expensive SMS API (Twilio, etc.), we use the carrier's free email-to-SMS gateway:

1. Convert phone to email format: `2025551234@txt.bellmobility.ca`
2. Send via native PHP `mail()` function
3. Carrier receives email to SMS gateway
4. Carrier converts email to SMS on customer's phone
5. Message arrives as normal SMS

This is completely free and reliable for Canadian numbers.

---

## Debugging Tips

1. **Check error_log:**
   ```
   tail -50 /var/log/error_log | grep "SMS Gateway"
   ```

2. **Enable verbose logging in sms_gateway.php:**
   - All attempts are already logged
   - Check phone number format in logs
   - Check which carriers accepted

3. **Test with known good number:**
   - Use the system admin's phone number
   - Verify that at least one SMS test succeeds

4. **Verify database SMS consent:**
   ```sql
   SELECT id, first_name, phone, receive_sms, consent_sms 
   FROM contacts 
   WHERE id = ?;
   ```
   - At least one of `receive_sms` or `consent_sms` must be 1

