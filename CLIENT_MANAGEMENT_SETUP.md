# Client Management & PDF Preferences Setup

## Overview

Complete client management system with configurable PDF email attachments.

**Features:**
- ✅ Create, read, update, delete clients
- ✅ Client type classification (individual, business, strata, property manager)
- ✅ Billing information management
- ✅ Account status tracking (active, inactive, suspended)
- ✅ **PDF attachment preference per client** — Control if PDFs auto-attach to quote emails

---

## Quick Start

### Step 1: Run Database Migration

1. Visit: `https://mowology.ca/crm/migrate_add_pdf_preference.php`
2. Log in as admin
3. The script adds `pref_attach_pdf` column to companies table
4. Default: **1** (attach PDF by default)

### Step 2: Go to Client Management

1. Visit: `https://mowology.ca/crm/clients_appstack.php`
2. Click "Add New Client" to create your first client
3. Fill in name, email, address, etc.
4. **Check or uncheck** "Attach PDF to quote emails"
5. Save

### Step 3: Send Quote with PDF

1. Create a quote for the client
2. Click "Send Quote"
3. If PDF attachment is enabled for that client:
   - Quote PDF is generated
   - Attached to the email
   - Customer receives PDF directly

---

## How PDF Attachment Works

### Flow

```
Send Quote Button
    ↓
Check Client Settings (pref_attach_pdf = 1?)
    ↓
  YES: Generate/fetch PDF
  ↓
  Attach to email
  ↓
  Send with attachment

  NO: Skip PDF
  ↓
  Send email without attachment
```

### Code Reference

**Email Helper:** `/crm/includes/email_helper.php:83`
```php
function companyPrefersAttachment(int $companyId): bool
{
    // Returns 1 (true) by default
    // Checks companies.pref_attach_pdf column
}
```

**Quote View:** `/crm/quotes/view.php:115`
```php
if (companyPrefersAttachment($companyId)) {
    // Generate or fetch PDF
    // Attach to email
}
```

---

## Client Management Page

### URL
```
https://mowology.ca/crm/clients_appstack.php
```

### Features

#### List View
- **All Clients table** with:
  - Company name
  - Type (Individual, Business, Strata, Property Manager)
  - Billing email
  - Account status (Active, Inactive, Suspended)
  - Creation date
  - Edit button

#### Create Client
- Company name (required)
- Client type (individual/business/strata/property_manager)
- Billing email
- Billing phone
- Billing address
- City, province, postal code
- Account status (active/inactive/suspended)
- Payment terms (default: "Net 30")
- Internal notes
- **Email Preferences section:**
  - ☑️ Attach PDF to quote emails (checkbox)

#### Edit Client
- Same form as create
- Pre-filled with existing data
- **Delete button** (bottom right, with confirmation)

---

## Database Schema Changes

### New Column

```sql
ALTER TABLE companies
ADD COLUMN pref_attach_pdf TINYINT(1) DEFAULT 1
AFTER account_status;
```

### Column Details

| Field | Type | Default | Purpose |
|-------|------|---------|---------|
| `pref_attach_pdf` | TINYINT(1) | 1 | 1 = attach, 0 = don't attach |

### Full Updated Schema

```sql
CREATE TABLE companies (
    id INT PRIMARY KEY,
    company_name VARCHAR(200),
    company_type ENUM('individual','business','strata','property_manager'),
    primary_contact_id INT,
    billing_contact_id INT,
    billing_address VARCHAR(255),
    billing_city VARCHAR(100),
    billing_province VARCHAR(50),
    billing_postal_code VARCHAR(10),
    billing_email VARCHAR(255),
    billing_phone VARCHAR(50),
    account_status ENUM('active','inactive','suspended'),
    payment_terms VARCHAR(50),
    pref_attach_pdf TINYINT(1) DEFAULT 1,  -- NEW COLUMN
    notes TEXT,
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
```

---

## Usage Scenarios

### Scenario 1: Client Wants PDF Attached
1. Edit the client
2. **Check** "Attach PDF to quote emails"
3. Save
4. Next quote sent will include PDF

### Scenario 2: Client Doesn't Want Attachment
1. Edit the client
2. **Uncheck** "Attach PDF to quote emails"
3. Save
4. Quote emails will be text/HTML only

### Scenario 3: Bulk Change (Manual)
To enable PDF for all clients:
```sql
UPDATE companies SET pref_attach_pdf = 1;
```

To disable PDF for all clients:
```sql
UPDATE companies SET pref_attach_pdf = 0;
```

---

## Email Customization

### Default Behavior
- **With PDF:** "Quote attached - please review and let us know if you have questions"
- **Without PDF:** "Quote details below - please let us know if you have questions"

### Email Template Location
`/crm/quotes/view.php:98-109`

Current HTML body:
```php
<h2>Your Quote is Ready</h2>
<p>Hi [Customer Name],</p>
<p>Thank you for your interest in Mowology's services...</p>
```

---

## API/Database Queries

### Get Client with PDF Preference
```sql
SELECT id, company_name, billing_email, pref_attach_pdf
FROM companies
WHERE id = ?
```

### Check if PDF Should Attach
```php
// In code:
if (companyPrefersAttachment($companyId)) {
    $attachPath = generatePDF($quoteId);
}
```

### List Clients Without PDF Attachment
```sql
SELECT * FROM companies
WHERE pref_attach_pdf = 0
```

### List Clients With PDF Attachment
```sql
SELECT * FROM companies
WHERE pref_attach_pdf = 1
```

---

## Testing

### Test Sending Quote with PDF Attached

1. Create a client with "Attach PDF" checked
2. Create a quote for that client
3. Send the quote
4. Email should include PDF

**Expected:**
- Email contains PDF attachment
- Activity log shows "(with PDF attached)"
- Customer can download PDF directly

### Test Sending Quote Without PDF

1. Edit client and uncheck "Attach PDF"
2. Create and send a quote
3. Email should NOT include PDF

**Expected:**
- Email is text/HTML only
- Activity log shows regular "(sent)" message
- No attachment

---

## Troubleshooting

### Migration Fails: "Unknown column"
**Issue:** Migration script can't find column
**Solution:** Column already exists, safe to ignore

### PDF Not Attaching Even When Checked
**Reason:**
- PDF generation failed
- Invalid PDF path
- Email function not working

**Fix:**
- Check `/crm/includes/PdfGenerator.php` for errors
- Check email logs
- Verify PDF storage permissions

### Checkbox Always Unchecked
**Reason:** New clients have default value of 1 (checked)
**Note:** This is correct behavior - default is to attach

---

## Related Files

- `/crm/clients_appstack.php` — Client management UI
- `/crm/migrate_add_pdf_preference.php` — Database migration
- `/crm/quotes/view.php` — Send quote logic (uses preference)
- `/crm/includes/email_helper.php` — PDF attachment check function
- `/crm/includes/PdfGenerator.php` — PDF generation

---

## Security Notes

- All user input escaped with `h()` function
- All database queries use prepared statements
- CSRF token required on all forms
- Admin-only migration script
- PDF files stored outside web root (in storage/)

---

## Future Enhancements

1. **Email Format Options** — Choose PDF or text format per client
2. **Default Branding** — Toggle company branding on/off per quote
3. **Auto-remind** — Send reminders to clients with unchecked PDFs
4. **Bulk Actions** — Select multiple clients to update preference
5. **Audit Log** — Track when PDF preferences changed

---

## Changelog

**v1.0 (Feb 6, 2026)**
- ✨ Complete client management system
- ✨ Add/Edit/Delete clients
- ✨ PDF attachment preference per client
- ✨ Client type classification
- ✨ Billing information management
- 🔧 Database migration script
- 🧪 Ready for production

---

## Support

For issues or questions:
1. Check `MIGRATION SETUP` section above
2. Review database column with: `DESCRIBE companies;`
3. Test with test-schema-fixes.php
4. Check email logs for delivery issues
