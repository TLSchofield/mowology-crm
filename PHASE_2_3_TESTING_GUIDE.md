# Phase 2-3 Testing Guide

## Quick Start Test (5 minutes)

### Prerequisite: Set Up Test Data

```sql
-- Create test company (direct client)
INSERT INTO companies (company_name, company_type, billing_email)
VALUES ('John Smith Direct', 'individual', 'john@example.com')
SET @company_id = LAST_INSERT_ID();

-- Create test contact
INSERT INTO contacts (first_name, last_name, email, receive_sms, is_active)
VALUES ('John', 'Smith', 'john@example.com', 1, 1)
SET @contact_id = LAST_INSERT_ID();

-- Update company with contact
UPDATE companies SET primary_contact_id = @contact_id WHERE id = @company_id;

-- Create test property
INSERT INTO properties (address, city, property_type, owner_company_id)
VALUES ('123 Main St', 'Vancouver', 'single_family', @company_id)
SET @property_id = LAST_INSERT_ID();
```

### Test Steps

1. **Navigate**: Invoices → Create Invoice
2. **Select Customer**: "John Smith Direct"
3. **Verify**: Property dropdown shows "123 Main St, Vancouver"
4. **Select Property**: Click the property
5. **Verify**: Recipient table appears showing John Smith
6. **Verify**: Checkbox is pre-checked
7. **Summary**: Shows "Will send invoice to: John Smith"
8. **Fill Form**:
   - Amount: $500
   - Description: "Test invoice"
9. **Submit**: Click "Create Invoice"
10. **Verify**: Invoice created successfully
11. **Check Database**:
    ```sql
    SELECT * FROM invoice_contacts WHERE invoice_id = X;
    -- Should show 1 record with John Smith, contact_role='primary_recipient'
    ```

---

## Complete Test Suite

### Test 1: Direct Client (No Manager)

**Objective**: Create invoice for customer with property (no manager)

**Setup**:
```sql
-- Company
INSERT INTO companies (company_name, company_type)
VALUES ('Direct Client Inc', 'business')
SET @company_id = LAST_INSERT_ID();

-- Contact
INSERT INTO contacts (first_name, last_name, email, receive_sms, is_active)
VALUES ('John', 'Smith', 'john@example.com', 1, 1)
SET @contact_id = LAST_INSERT_ID();

-- Link contact to company
UPDATE companies SET primary_contact_id = @contact_id WHERE id = @company_id;

-- Property
INSERT INTO properties (address, city, owner_company_id)
VALUES ('123 Main St', 'Vancouver', @company_id)
SET @property_id = LAST_INSERT_ID();
```

**Steps**:
1. Create Invoice form
2. Select "Direct Client Inc"
3. Select "123 Main St, Vancouver"
4. Verify recipients: Only "John Smith"
5. Fill amount: $500
6. Submit

**Expected Results**:
- [ ] Invoice created
- [ ] Invoice has service_address = "123 Main St"
- [ ] invoice_contacts table has 1 record: John Smith, primary_recipient
- [ ] Activity log shows: "...created for John Smith (SMS pending for: 1 recipients)"

**SQL to verify**:
```sql
SELECT i.invoice_number, i.service_address, COUNT(ic.id) as recipient_count
FROM invoices i
LEFT JOIN invoice_contacts ic ON i.id = ic.invoice_id
WHERE i.id = X
GROUP BY i.id;
-- Expected: INV-2026-XXXX, 123 Main St, 1

SELECT * FROM invoice_contacts WHERE invoice_id = X;
-- Expected: 1 row for John Smith
```

---

### Test 2: Property with Manager

**Objective**: Create invoice for property with both owner and manager

**Setup**:
```sql
-- Owner company
INSERT INTO companies (company_name, company_type)
VALUES ('Smith Family', 'individual')
SET @owner_id = LAST_INSERT_ID();

-- Owner contact
INSERT INTO contacts (first_name, last_name, email, receive_sms, is_active)
VALUES ('John', 'Smith', 'john@example.com', 1, 1)
SET @owner_contact = LAST_INSERT_ID();

UPDATE companies SET primary_contact_id = @owner_contact WHERE id = @owner_id;

-- Manager company
INSERT INTO companies (company_name, company_type, invoice_routing_method)
VALUES ('ABC Property Management', 'property_manager', 'custom_contacts')
SET @manager_id = LAST_INSERT_ID();

-- Manager contact
INSERT INTO contacts (first_name, last_name, email, receive_sms, is_active)
VALUES ('Sarah', 'Jones', 'sarah@abcpm.com', 0, 1)
SET @manager_contact = LAST_INSERT_ID();

UPDATE companies SET primary_contact_id = @manager_contact WHERE id = @manager_id;

-- Property with manager
INSERT INTO properties (address, city, owner_company_id, property_manager_id)
VALUES ('456 Oak Ave', 'Burnaby', @owner_id, @manager_id)
SET @property_id = LAST_INSERT_ID();
```

**Steps**:
1. Create Invoice form
2. Select "Smith Family"
3. Select "456 Oak Ave, Burnaby"
4. Verify recipients load:
   - [ ] Sarah Jones (Property Manager) - sarah@abcpm.com - No SMS
   - [ ] John Smith (Owner) - john@example.com - SMS
5. Both should be pre-checked
6. Fill amount: $750
7. Submit

**Expected Results**:
- [ ] Invoice created
- [ ] invoice_contacts has 2 records
- [ ] Sarah Jones with role = 'property_manager'
- [ ] John Smith with role = 'owner_contact' (from recipient engine)
- [ ] Activity log: "...created for Sarah Jones, John Smith (SMS pending for: 1 recipients)"

**SQL to verify**:
```sql
SELECT ic.contact_role, c.first_name, c.receive_sms
FROM invoice_contacts ic
LEFT JOIN contacts c ON ic.contact_id = c.id
WHERE ic.invoice_id = X
ORDER BY ic.contact_role;

-- Expected:
-- owner_contact | John | 1
-- property_manager | Sarah | 0
```

---

### Test 3: Uncheck Recipients

**Objective**: Verify user can exclude recipients

**Steps** (from Test 2):
1. Load recipients (Sarah and John both show)
2. **Uncheck John**
3. Summary updates: "Will send invoice to: Sarah Jones"
4. Submit

**Expected Results**:
- [ ] Only Sarah in invoice_contacts (1 record)
- [ ] John is NOT included
- [ ] Activity log: "...created for Sarah Jones"

---

### Test 4: Address Difference

**Objective**: Test service vs billing address separation

**Steps**:
1. Create invoice for Direct Client (Test 1)
2. Fill Service Address:
   - Address: "123 Main St"
   - City: "Vancouver"
   - Province: "BC"
   - Postal: "V6B 1A1"
3. **Check**: "Billing address is different"
4. Fill Billing Address:
   - Address: "100 Finance St"
   - City: "Seattle"
   - Province: "WA"
   - Postal: "98101"
5. Submit

**Expected Results**:
- [ ] service_address = "123 Main St"
- [ ] service_city = "Vancouver"
- [ ] billing_address = "100 Finance St"
- [ ] billing_city = "Seattle"
- [ ] address_differs = 1

**SQL to verify**:
```sql
SELECT service_address, service_city, billing_address, billing_city, address_differs
FROM invoices WHERE id = X;

-- Expected:
-- 123 Main St, Vancouver, 100 Finance St, Seattle, 1
```

---

### Test 5: Same Address (checkbox unchecked)

**Objective**: Verify billing address is optional

**Steps**:
1. Create invoice for Direct Client
2. Fill Service Address (all fields)
3. **Leave unchecked**: "Billing address is different"
4. Submit

**Expected Results**:
- [ ] service_address populated
- [ ] billing_address is NULL (or empty)
- [ ] address_differs = 0

**SQL to verify**:
```sql
SELECT service_address, billing_address, address_differs
FROM invoices WHERE id = X;

-- Expected:
-- 123 Main St, [NULL or empty], 0
```

---

### Test 6: Sending Invoice to Recipients

**Objective**: Verify sending logic works with multiple recipients

**Setup**: Create invoice with 2 recipients (Test 2)

**Steps**:
1. Navigate to created invoice
2. **View Recipients Section**: Shows both Sarah and John as "Pending"
3. Click "Send to Customer"
4. Wait for email to send
5. Check success message

**Expected Results**:
- [ ] Success message: "Invoice sent successfully to 2 recipients (SMS pending for: 1 contact)"
- [ ] Recipients table updated:
  - Sarah: Shows timestamp (e.g., "Feb 07, 10:35")
  - John: Shows timestamp (e.g., "Feb 07, 10:35")
- [ ] Emails sent to both john@example.com and sarah@abcpm.com
- [ ] Activity log: "Invoice sent to Sarah Jones, John Smith (SMS pending for: John Smith)"

**SQL to verify**:
```sql
SELECT ic.contact_role, c.first_name, ic.invoice_sent_at
FROM invoice_contacts ic
LEFT JOIN contacts c ON ic.contact_id = c.id
WHERE ic.invoice_id = X;

-- Expected: invoice_sent_at populated for both
-- Example:
-- property_manager | Sarah | 2026-02-07 10:35:22
-- owner_contact | John | 2026-02-07 10:35:22
```

---

### Test 7: Error Handling - No Recipients Selected

**Objective**: Form should reject when no recipients selected

**Steps**:
1. Create invoice for customer with property
2. Recipients load
3. **Uncheck all recipients**
4. Try to submit

**Expected Results**:
- [ ] JavaScript alert: "Please select at least one invoice recipient."
- [ ] Form does not submit
- [ ] No database changes

---

### Test 8: Error Handling - Invalid Amount

**Objective**: Form should reject invalid amounts

**Steps**:
1. Create invoice
2. Select recipients
3. Amount: $0.00 (or negative)
4. Try to submit

**Expected Results**:
- [ ] Error message: "Please enter a valid amount."
- [ ] Form does not submit

---

### Test 9: Resend Invoice

**Objective**: Verify can resend invoice to recipients

**Setup**: Invoice from Test 6 (already sent)

**Steps**:
1. View sent invoice
2. Note: Recipients show "Feb 07, 10:35"
3. Click "Resend"
4. Wait for email

**Expected Results**:
- [ ] Email sent again
- [ ] Success message: "Invoice sent successfully to 2 recipients"
- [ ] Timestamps updated to new time
- [ ] Activity log shows new send event

---

## Browser Console Tests

### Test 10: AJAX Error Handling

**Objective**: Verify AJAX gracefully handles errors

**Setup**:
1. Open Developer Tools (F12)
2. Go to Console tab
3. Create invoice form

**Test - Network error**:
1. Go to Network tab
2. Throttle to "Slow 3G"
3. Select property
4. Watch AJAX request
5. Should still work (just slow)

**Test - Empty recipients**:
1. If property has no recipients
2. Table should show: "No recipients found for this property"

**Expected Results**:
- [ ] No JavaScript errors in console
- [ ] Graceful error messages shown to user
- [ ] Form still functions

---

## Mobile Responsiveness Test

**Objective**: Verify form works on mobile devices

**Steps**:
1. DevTools → Toggle Device Toolbar
2. Set to: iPhone 12
3. Create invoice form
4. Test each section:
   - [ ] Customer dropdown works
   - [ ] Property dropdown works
   - [ ] Recipient checkboxes usable (40px minimum)
   - [ ] Address fields display correctly
   - [ ] Submit button accessible
   - [ ] Form validates properly

**Expected Results**:
- [ ] All elements readable at 375px width
- [ ] No horizontal scrolling needed
- [ ] Touch targets are 44px minimum
- [ ] Buttons are easy to tap

---

## Performance Test

**Objective**: Verify form performs well with many recipients

**Setup**:
```sql
-- Add 20 test recipients
INSERT INTO contacts (first_name, last_name, email, receive_sms, is_active)
SELECT
    CONCAT('Contact', ROW_NUMBER() OVER (ORDER BY id)),
    'Test',
    CONCAT('contact', ROW_NUMBER() OVER (ORDER BY id), '@example.com'),
    IF(ROW_NUMBER() OVER (ORDER BY id) % 2 = 0, 1, 0),
    1
FROM contacts
LIMIT 20;

-- Create invoice_contacts for all
INSERT INTO invoice_contacts (invoice_id, contact_id, contact_role, email_address)
SELECT X, id, 'primary_recipient', email
FROM contacts
WHERE id > 100
LIMIT 20;
```

**Steps**:
1. Create invoice with 20 recipients
2. Check form performance:
   - [ ] Recipients table loads quickly
   - [ ] Checkboxes responsive
   - [ ] Summary updates in real-time
3. Submit with all selected
4. Check database insert time

**Expected Results**:
- [ ] Recipient table renders in <1 second
- [ ] Checkbox interactions smooth
- [ ] Insert completes in <2 seconds
- [ ] No browser warnings/errors

---

## Checklist Summary

### ✅ To Complete All Tests

- [ ] Test 1: Direct Client (no manager) - 5 min
- [ ] Test 2: Property with Manager - 5 min
- [ ] Test 3: Uncheck Recipients - 2 min
- [ ] Test 4: Address Difference - 3 min
- [ ] Test 5: Same Address - 2 min
- [ ] Test 6: Sending to Recipients - 5 min
- [ ] Test 7: No Recipients Error - 2 min
- [ ] Test 8: Invalid Amount Error - 2 min
- [ ] Test 9: Resend Invoice - 3 min
- [ ] Test 10: AJAX Error Handling - 5 min
- [ ] Mobile Responsiveness - 10 min
- [ ] Performance Test - 5 min

**Total Time**: ~50 minutes for complete test suite

---

## Known Issues & Troubleshooting

### Issue: Property dropdown empty
**Solution**:
- Check property has owner_company_id or property_manager_id set
- Verify company exists in database
- Reload page and try again

### Issue: Recipients not loading
**Solution**:
- Check browser console for AJAX error
- Verify api-get-recipients.php endpoint is accessible
- Check that company has primary_contact_id set
- Verify contact is active (is_active=1)

### Issue: Form won't submit
**Solution**:
- Check browser console for JavaScript errors
- Verify at least 1 recipient is selected
- Verify amount > 0
- Check all required fields have values

### Issue: Email not sending
**Solution**:
- Check error logs
- Verify contact email is valid format
- Test email to different address
- Check php.ini mail settings

### Issue: Recipients not saved to database
**Solution**:
- Check invoice was actually created
- Verify transaction wasn't rolled back
- Check error logs for SQL errors
- Try submitting again

---

## Final Verification Checklist

When all tests pass:

- [ ] Form displays correctly
- [ ] Properties load dynamically
- [ ] Recipients load and preview works
- [ ] Addresses can be entered (service + billing)
- [ ] Form validates (no recipients = error)
- [ ] Invoice created with all fields
- [ ] Recipients saved to invoice_contacts
- [ ] SMS recipients tracked in activity log
- [ ] Sending works to multiple recipients
- [ ] Recipient timestamps updated
- [ ] Error messages are clear
- [ ] Mobile layout works
- [ ] Performance acceptable
- [ ] Documentation accurate

🎉 **Phase 2-3 testing complete!**
