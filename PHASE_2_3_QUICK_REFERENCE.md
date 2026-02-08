# Phase 2-3 Implementation Quick Reference

## What Changed - Files Modified

### 1. `/public/crm/invoices/create.php` (UPDATED)
- **Added**: Property selector dropdown with AJAX
- **Added**: Recipient preview table with checkboxes
- **Added**: Service & billing address fields
- **Added**: JavaScript for form interactivity
- **Added**: Hidden input to store selected recipient IDs
- **Updated**: POST handler to:
  - Parse recipient selections
  - Insert invoice with address fields
  - Insert recipients into invoice_contacts table
  - Build SMS recipient list
  - Log activity with full details

### 2. `/public/crm/invoices/view.php` (UPDATED)
- **Added**: Query to fetch invoice_contacts for display
- **Added**: Recipients table section showing:
  - Contact name and role
  - Email address
  - SMS consent status
  - Send timestamp
- **Updated**: Send action to:
  - Fetch all recipients from invoice_contacts
  - Send to each recipient
  - Update invoice_contacts.invoice_sent_at
  - Log with full recipient details

### 3. `/public/crm/invoices/api-get-properties.php` (NEW)
- **Purpose**: AJAX endpoint to get properties for a customer
- **Returns**: JSON list of properties where customer is owner or manager

### 4. `/public/crm/css/mowology-brand.css` (UPDATED)
- **Added**: Styles for recipient table, form rows, address sections
- **Uses**: Existing `--mw-*` CSS variables

---

## How It Works - User Experience

### Creating an Invoice

1. **Click "Create Invoice"** → Form loads
2. **Select Customer** → Property dropdown populates
3. **Select Property** → Recipients load automatically via AJAX
4. **See Recipients Table** → Checkboxes pre-selected for all recipients
5. **Fill Details** (amount, description, addresses)
6. **Submit** → Invoice created with all recipients stored

### Sending an Invoice

1. **View Invoice** → Recipients table shows "Pending" status
2. **Click "Send to Customer"** →
   - Gets all recipients from database
   - Generates PDF once
   - Sends to each recipient
   - Updates timestamps
3. **See Success** → "Invoice sent to 2 recipients"

---

## Key Database Operations

### When Invoice Created (via create.php POST)

**Insert into invoices**:
```sql
service_address, service_city, service_province, service_postal_code,
billing_address, billing_city, billing_province, billing_postal_code,
address_differs
```

**Insert into invoice_contacts** (one per recipient):
```sql
invoice_id, contact_id, contact_role='primary_recipient', email_address
```

**Insert into activity_log**:
```sql
details: "Invoice INV-2026-0001 created for John Smith, Sarah Jones (SMS pending for: 1 recipients)"
```

### When Invoice Sent (via view.php POST)

**Update invoice_contacts** (for each recipient):
```sql
UPDATE invoice_contacts SET invoice_sent_at = NOW() WHERE id = ?
```

**Update activity_log**:
```sql
details: "Invoice sent to John Smith, Sarah Jones (SMS pending for: 1 recipients) (with PDF attached)"
```

---

## Testing Checklist

### ✅ Basic Test
- [ ] Create invoice as Direct Client (no manager)
- [ ] Verify recipient loads automatically
- [ ] Submit invoice
- [ ] Check invoice_contacts table has 1 record
- [ ] Send invoice
- [ ] Verify email sent
- [ ] Check invoice_contacts.invoice_sent_at updated

### ✅ Manager Test
- [ ] Create invoice for property with manager
- [ ] Verify BOTH manager and owner load
- [ ] Submit with both selected
- [ ] Check invoice_contacts has 2 records
- [ ] Send invoice
- [ ] Verify both received emails

### ✅ Address Test
- [ ] Check "Billing address different"
- [ ] Enter different billing address
- [ ] Submit
- [ ] Check database: address_differs = 1
- [ ] Verify PDF shows correct addresses

### ✅ SMS Test
- [ ] Contact with receive_sms=1 loads with SMS indicator
- [ ] Contact with receive_sms=0 shows no SMS indicator
- [ ] Activity log mentions SMS pending
- [ ] (Phase 4+) SMS actually sends

---

## Common Issues & Solutions

### Q: Property dropdown empty after selecting customer
**A**: Customer might not have any properties assigned. Check:
```sql
SELECT * FROM properties WHERE owner_company_id = ? OR property_manager_id = ?
```

### Q: Recipients table shows "Error loading recipients"
**A**: AJAX call to api-get-recipients.php failed. Check:
- Browser console for errors
- Server error logs
- Verify property_id and company_id passed correctly

### Q: Recipients checkboxes not working
**A**: JavaScript issue. Check:
- Browser console for JS errors
- Verify jQuery/Bootstrap loaded
- Check form element IDs match JavaScript

### Q: Invoice created but no recipients in invoice_contacts
**A**: Form submission succeeded but recipient insert failed. Check:
- Contact exists and is active
- Email field populated
- Database transaction rolled back on error

### Q: Email sent but invoice_contacts.invoice_sent_at not updated
**A**: Email succeeded but database update failed. Check:
- invoice_contacts record exists
- Database connection still active

---

## Files Not Changed (But Related)

These files weren't modified but work with Phase 2-3:

- `/crm/includes/invoice-routing.php` - Determines recipients (Phase 1)
- `/crm/invoices/api-get-recipients.php` - Returns recipients (Phase 1)
- `/crm/includes/functions.php` - Helper functions
- `/crm/includes/email_helper.php` - Email sending
- `/crm/includes/PdfGenerator.php` - PDF generation

---

## Database Schema References

### New Fields in invoices table
```
service_address VARCHAR(255)
service_city VARCHAR(100)
service_province VARCHAR(50)
service_postal_code VARCHAR(10)
billing_address VARCHAR(255)
billing_city VARCHAR(100)
billing_province VARCHAR(50)
billing_postal_code VARCHAR(10)
address_differs TINYINT(1) DEFAULT 0
```

### invoice_contacts table (Phase 1 created, Phase 2-3 uses)
```
id INT PRIMARY KEY
invoice_id INT FK
contact_id INT FK
contact_role ENUM (primary_recipient, property_manager, owner_contact, etc)
email_address VARCHAR(255)
invoice_sent_at TIMESTAMP NULL
created_at TIMESTAMP
```

---

## Next Steps

After Phase 2-3, consider:

**Phase 4**: Quote → Invoice Conversion
- Button on quote view: "Convert to Invoice"
- Pre-populate recipients, addresses, amounts
- Single-click invoice creation

**Phase 5**: Prospect → Client Conversion
- Button on accepted quote: "Convert to Client"
- Create company + contacts from quote data
- Link to property

**Phase 6**: Dashboard Reporting
- Invoice delivery metrics
- SMS tracking
- Conversion funnels
- Recipient engagement

---

## Questions or Issues?

Check these files for detailed info:
- `IMPLEMENTATION_PHASE_2_3_COMPLETE.md` - Full documentation
- `DATABASE_RELATIONSHIPS.md` - Schema guide
- `USEFUL_QUERIES.sql` - Troubleshooting queries

All files checked into GitHub. Latest version always available.
