# Implementation Phase 1 Complete: Core Invoice Routing Logic

## ✅ What Was Built

### 1. Invoice Routing Engine (`/crm/includes/invoice-routing.php`)

**Core Function**: `determineInvoiceRecipients($propertyId, $companyId)`

This is the intelligent heart of the system. It analyzes property management relationships and returns the correct invoice recipients based on scenarios:

#### Scenario 1: Direct Client (No Manager)
**Property**: owner_company_id set, property_manager_id = NULL
**Result**:
- Primary Recipient: Owner's primary contact
- Optional CC: Owner's billing contact (if different)

Example:
```
John Smith's house
→ Invoice to: john@example.com (primary_recipient)
```

#### Scenario 2: Residential with Property Manager
**Property**: Both owner_company_id AND property_manager_id set
**Result**:
- Primary Recipient: Property manager's primary contact
- CC: Owner's primary contact

Example:
```
John Smith's house, managed by ABC Property Management
→ Invoice to: sarah@abcpm.com (property_manager)
→ CC: john@example.com (owner_contact)
```

#### Scenario 3: Strata Building
**Company**: company_type = 'strata'
**Property**: property_type = 'strata'
**Result**:
- Primary: Strata manager's contact
- Secondary: Property manager's contact

Example:
```
The Towers Unit 201 (strata corp owner, managed by ABC PM)
→ Invoice to: strata@towers.ca (strata_manager)
→ CC: sarah@abcpm.com (property_manager)
```

### Helper Functions Included

1. **`getPropertyWithCompanies($propertyId)`**
   - Fetches property with all linked company relationships
   - Returns enriched property object with owner and manager data

2. **`getRecipientListDirect($property, $company, $db)`**
   - Builds recipient list for direct clients

3. **`getRecipientListWithManager($property, $company, $db)`**
   - Builds recipient list when property has a manager

4. **`getRecipientListStrata($property, $company, $db)`**
   - Builds recipient list for strata corporations

5. **`validateInvoiceRecipient($contactId, $email)`**
   - Validates that recipient has valid email and is active

6. **`logInvoiceRoutingDecision($invoiceId, $propertyId, $companyId, $recipients, $notes)`**
   - Records routing logic decisions to activity_log for audit trail

### 2. AJAX Endpoint (`/crm/invoices/api-get-recipients.php`)

**Purpose**: Provides real-time recipient preview in invoice creation form

**Usage**: POST JSON with property_id and company_id, returns recipients array

**Example Request**:
```javascript
fetch('/crm/invoices/api-get-recipients.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
        property_id: 42,
        company_id: 15
    })
})
.then(r => r.json())
.then(data => {
    if (data.success) {
        // Populate recipient table with data.recipients
        displayRecipients(data.recipients);
    }
})
```

**Example Response**:
```json
{
  "success": true,
  "recipients": [
    {
      "contact_id": 5,
      "contact_role": "property_manager",
      "email_address": "sarah@abcpm.com",
      "first_name": "Sarah",
      "last_name": "Jones",
      "receive_sms": 1
    },
    {
      "contact_id": 3,
      "contact_role": "owner_contact",
      "email_address": "john@example.com",
      "first_name": "John",
      "last_name": "Smith",
      "receive_sms": 0
    }
  ],
  "count": 2
}
```

---

## 🚀 How to Test Phase 1

### Test Case 1: Direct Client
```
Setup:
- Company: "John Smith", type=individual
- Property: 123 Main St, owner_company_id=1, property_manager_id=NULL
- Contact: John, john@example.com

Test:
curl -X POST http://mowology.ca/crm/invoices/api-get-recipients.php \
  -H "Content-Type: application/json" \
  -d '{"property_id": 1, "company_id": 1}'

Expected:
{
  "success": true,
  "recipients": [
    {"contact_role": "primary_recipient", "email_address": "john@example.com"}
  ]
}
```

### Test Case 2: With Property Manager
```
Setup:
- Company A: "Smith Family", type=individual, primary_contact_id=3 (John)
- Company B: "ABC Property Management", type=property_manager, primary_contact_id=5 (Sarah)
- Property: 123 Main St, owner_company_id=1, property_manager_id=2

Test:
curl -X POST http://mowology.ca/crm/invoices/api-get-recipients.php \
  -H "Content-Type: application/json" \
  -d '{"property_id": 1, "company_id": 1}'

Expected:
{
  "success": true,
  "recipients": [
    {"contact_role": "property_manager", "email_address": "sarah@abcpm.com"},
    {"contact_role": "owner_contact", "email_address": "john@example.com"}
  ]
}
```

### Test Case 3: Strata Building
```
Setup:
- Company A: "The Towers Strata #1234", type=strata, primary_contact_id=7 (Mike)
- Company B: "ABC Property Management", type=property_manager, primary_contact_id=5 (Sarah)
- Property: Unit 201, type=strata, owner_company_id=1, property_manager_id=2

Test:
curl -X POST http://mowology.ca/crm/invoices/api-get-recipients.php \
  -H "Content-Type: application/json" \
  -d '{"property_id": 101, "company_id": 1}'

Expected:
{
  "success": true,
  "recipients": [
    {"contact_role": "strata_manager", "email_address": "mike@towers.ca"},
    {"contact_role": "property_manager", "email_address": "sarah@abcpm.com"}
  ]
}
```

---

## 📋 Next Steps: Phase 2-3 Implementation

### Phase 2: Enhance Invoice Creation Form
**Goal**: Add UI for recipient selection and address separation

**Files to Modify**:
- `/crm/invoices/create.php` - Add recipient table, address fields, AJAX integration

**Key Features**:
- Property selector triggers AJAX recipient preview
- Table showing suggested recipients with checkboxes to include/exclude
- Ability to override email addresses
- Service vs. Billing address section
- Summary before submit showing who gets invoice

**Estimated Time**: 3-4 hours

### Phase 3: Update Database Insert Logic
**Goal**: Store recipients in invoice_contacts table and SMS recipients

**Changes to create.php POST handler**:
1. Parse recipients from form (JSON array of selected recipients)
2. Create invoice record with service_address and billing_address fields
3. Insert recipients into invoice_contacts table
4. Build list of SMS recipients (where contact.receive_sms = 1)
5. Log routing decision

**Database Impact**:
- invoices table: service_address, service_city, service_province, service_postal_code, billing_address, billing_city, billing_province, billing_postal_code, address_differs
- invoice_contacts table: contact_id, contact_role, email_address, (future: invoice_sent_at, bounced)

**Estimated Time**: 2-3 hours

---

## 📊 Database Readiness

✅ **All required tables exist** (created by migration 015):
- `properties.owner_company_id`
- `properties.property_manager_id`
- `companies.company_type`
- `companies.invoice_routing_method`
- `invoices` (address fields already added)
- `invoice_contacts` (empty, ready for data)
- `property_contacts` (empty, for future phases)

**No migrations needed** - Database schema is complete.

---

## 🔄 Data Flow: From Property to Invoice

```
1. Invoice Creation Form
   ↓ User selects property
   ↓
2. AJAX Endpoint
   ├─ GET property details with relationships
   ├─ Determine scenario (direct/manager/strata)
   ├─ Get recipient contacts for each role
   ├─ Validate emails and active status
   ↓
3. Form Displays
   ├─ Suggested recipients (checkboxes)
   ├─ Service address (from property)
   ├─ Billing address (from company)
   ├─ Summary: "Will send to: John Smith, Sarah Jones"
   ↓
4. User Confirms
   ├─ Checks/unchecks recipients as needed
   ├─ Enters invoice line items
   ├─ Confirms final summary
   ↓
5. Save Invoice
   ├─ Create invoice record with addresses
   ├─ Insert selected recipients into invoice_contacts
   ├─ Log routing decision
   ├─ Prepare SMS list (contacts with receive_sms=1)
```

---

## 💡 SMS Integration Point

**Current Design**: All contacts with `receive_sms = 1` will receive SMS

**Integration Approach**:
1. Query `invoice_contacts` table for invoice
2. Filter for contacts with `receive_sms = 1`
3. Use SMS provider (currently logged, awaiting provider integration)
4. Track SMS sends in `invoice_contacts.invoice_sent_at`

**Free SMS Assumption**:
- Send to ALL consenting customers (no preference filtering)
- Each contact on invoice_contacts who has receive_sms=1 gets SMS with link
- SMS includes: Customer name, quote/invoice number, link to access

---

## 🛠️ Implementation Checklist

### Phase 1 (✅ COMPLETE)
- [x] Create invoice-routing.php with core logic
- [x] Implement all routing scenarios
- [x] Create helper functions
- [x] Create AJAX endpoint
- [x] Document implementation

### Phase 2 (TODO)
- [ ] Modify /crm/invoices/create.php to display property selector
- [ ] Add AJAX call to get-recipients endpoint
- [ ] Build recipient table UI with checkboxes
- [ ] Add service/billing address fields
- [ ] Add JavaScript for form interactivity
- [ ] Add summary section before submit

### Phase 3 (TODO)
- [ ] Update POST handler to parse recipient selections
- [ ] Insert invoice with service/billing addresses
- [ ] Insert records into invoice_contacts table
- [ ] Build SMS recipient list
- [ ] Log routing decision to activity_log
- [ ] Handle error cases gracefully

### Phase 4-6 (TODO - Future)
- [ ] Quote-to-invoice conversion
- [ ] Prospect-to-client conversion
- [ ] Dashboard reporting

---

## 📝 Code Quality Notes

**Error Handling**:
- All functions include try-catch with logging
- Invalid recipients are filtered out with warnings
- Empty recipient lists are logged but won't break form

**Database Safety**:
- All queries use prepared statements
- Recipient validation happens before insert
- Active status checked for contacts

**Audit Trail**:
- Every invoice routing decision logged to activity_log
- Includes user ID, company ID, property ID, recipient list
- Can trace why specific recipients were chosen

---

## 🎯 Success Criteria

Phase 1-3 will be complete when:
1. ✅ Invoice creation form shows property selector
2. ✅ AJAX endpoint returns correct recipients for each scenario
3. ✅ UI displays suggested recipients with checkboxes
4. ✅ Service and billing addresses displayed correctly
5. ✅ Recipient selections saved to invoice_contacts table
6. ✅ Activity log shows routing decisions for audit
7. ✅ SMS recipient list generated from receive_sms=1 contacts

---

## 📞 Support & Questions

If during Phase 2-3 implementation you need:
- Help with form HTML/CSS → Check mowology-brand.css patterns
- Help with JavaScript → Look at existing AJAX patterns in CRM
- Database questions → Refer to DATABASE_RELATIONSHIPS.md
- Testing questions → Use test cases provided above

Ready to proceed with Phase 2 when you are! 🚀
