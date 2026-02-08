# Mowology CRM - Database Relationships Guide

## Overview

The Mowology CRM supports complex property management scenarios involving:
- **Property Owners** (individuals or companies)
- **Property Management Companies** (manage properties on behalf of owners)
- **Strata Corporations** (manage multi-unit buildings)
- **Multiple Invoice Recipients** (owner, manager, accounting, etc.)
- **Separate Service & Billing Addresses**

---

## Database Schema

### Core Tables

#### `companies`
Represents any business entity: property owners, management companies, strata corps, or individual clients.

```
company_type ENUM:
  - 'individual'        ← Single homeowner
  - 'business'          ← Regular business client
  - 'property_manager'  ← Professional property management company
  - 'strata'            ← Strata corporation managing multi-unit building

invoice_routing_method ENUM:
  - 'primary_contact'   ← Send to primary_contact_id only
  - 'billing_contact'   ← Send to billing_contact_id only
  - 'both_contacts'     ← Send to both primary and billing
  - 'custom_contacts'   ← Use invoice_contacts table for routing
  - 'email_address'     ← Send to billing_email field

payment_method ENUM:
  - 'invoice'           ← Standard invoice
  - 'credit_card'       ← Credit card on file
  - 'bank_transfer'     ← Bank transfer
  - 'cheque'            ← Check payment
```

#### `properties`
Represents a physical location being serviced.

```
property_type ENUM:
  - 'single_family'
  - 'townhouse'
  - 'condo'
  - 'commercial'
  - 'strata'            ← Unit in a strata building
  - 'multi_unit'

Relationships:
  - site_contact_id (INT NULL)      ← Individual at property (tenant, occupant)
  - property_manager_id (INT NULL)  ← Company managing this property
  - owner_company_id (INT NULL)     ← Company that owns the property

address, city, province, postal_code ← Service address (where work happens)
```

#### `contacts`
Represents individual people.

```
Individual contact information:
  - first_name, last_name
  - email, phone
  - preferred_contact_method
  - consent fields (for GDPR/privacy)
```

#### `company_properties` (Many-to-Many)
Links companies to their properties with relationship type.

```
relationship_type ENUM:
  - 'owner'    ← Company owns the property
  - 'manager'  ← Company manages the property
  - 'tenant'   ← Company occupies the property
  - 'billing'  ← Company receives billing

is_primary TINYINT(1) ← Set for the primary property of a company
```

#### `property_contacts` (Many-to-Many)
Maps contacts to properties with specific roles.

```
contact_role ENUM:
  - 'owner'           ← Property owner
  - 'tenant'          ← Current occupant
  - 'site_supervisor' ← On-site supervisor
  - 'manager'         ← Property manager contact
  - 'billing'         ← Billing contact for property
  - 'emergency'       ← Emergency contact
  - 'other'

is_primary TINYINT(1) ← Primary contact for this role
```

#### `invoice_contacts` (Many-to-Many)
Maps contacts to invoices for routing and tracking.

```
contact_role ENUM:
  - 'primary_recipient'    ← Main invoice recipient
  - 'billing_contact'      ← Alternate billing contact
  - 'accounting'           ← Accounting/finance team
  - 'property_manager'     ← Property manager contact
  - 'strata_manager'       ← Strata manager
  - 'owner_contact'        ← Property owner (for managed properties)
  - 'cc'                   ← CC recipient
  - 'bcc'                  ← BCC recipient

email_address VARCHAR(255)   ← Specific email to send to (may differ from contact.email)

Tracking:
  - invoice_sent_at TIMESTAMP      ← When sent to this contact
  - invoice_opened_at TIMESTAMP    ← When recipient opened (if tracking)
  - bounced TINYINT(1)             ← Email bounced
  - bounce_reason VARCHAR(500)     ← Why it bounced
```

#### `invoices`
Enhanced with address fields.

```
Service Address (where work was done):
  - service_address, service_city, service_province, service_postal_code

Billing Address (where invoice is sent):
  - billing_address, billing_city, billing_province, billing_postal_code
  - address_differs TINYINT(1)  ← Flag if billing ≠ service address
```

---

## Common Scenarios

### Scenario 1: Residential Home (Direct Client)

**Setup:**
```
companies
├── id: 1
├── company_name: "John Smith"
├── company_type: "individual"
├── primary_contact_id: 1
└── billing_address: 123 Main St, Vancouver

contacts
├── id: 1
├── first_name: "John"
├── last_name: "Smith"
├── email: "john@example.com"

properties
├── id: 1
├── address: "123 Main St"
├── city: "Vancouver"
├── property_type: "single_family"
├── site_contact_id: 1           ← John is at the property
├── owner_company_id: 1          ← John owns the property
└── property_manager_id: NULL    ← No property manager

company_properties
├── company_id: 1
├── property_id: 1
├── relationship_type: "owner"
└── is_primary: 1
```

**Query to get full details:**
```sql
SELECT
    p.*,
    owner.company_name as owner_name,
    mgr.company_name as manager_name,
    site.first_name, site.last_name
FROM properties p
LEFT JOIN companies owner ON p.owner_company_id = owner.id
LEFT JOIN companies mgr ON p.property_manager_id = mgr.id
LEFT JOIN contacts site ON p.site_contact_id = site.id
WHERE p.id = 1;
```

---

### Scenario 2: Residential Home with Property Manager

**Setup:**
```
companies
├── id: 1 (Property owner)
│   ├── company_name: "Smith Family Trust"
│   ├── company_type: "individual"
│   ├── primary_contact_id: 1 (John)
│
└── id: 2 (Property manager)
    ├── company_name: "ABC Property Management"
    ├── company_type: "property_manager"
    ├── primary_contact_id: 3 (Manager contact)
    ├── invoice_routing_method: "custom_contacts"

contacts
├── id: 1 (Owner)
│   ├── first_name: "John"
│   ├── last_name: "Smith"
│   ├── email: "john@example.com"
│
└── id: 3 (Manager contact)
    ├── first_name: "Sarah"
    ├── last_name: "Jones"
    ├── email: "sarah@abcpm.com"

properties
├── id: 1
├── address: "123 Main St, Vancouver"
├── property_type: "single_family"
├── site_contact_id: 1           ← Owner (may not be present)
├── owner_company_id: 1          ← The owner company
└── property_manager_id: 2       ← ABC Property Management

company_properties
├── [1] company_id: 1, property_id: 1, relationship_type: "owner"
└── [2] company_id: 2, property_id: 1, relationship_type: "manager"
```

**Invoice Routing:**
- Create invoice for property 1
- Add to `invoice_contacts`:
  - Sarah Jones (property_manager) → sarah@abcpm.com
  - John Smith (owner_contact) → john@example.com (CC)

**Query to get manager and owner:**
```sql
SELECT
    p.*,
    owner.company_name as owner_name,
    owner_contact.first_name as owner_first,
    owner_contact.last_name as owner_last,
    owner_contact.email as owner_email,
    mgr.company_name as manager_name,
    mgr_contact.first_name as manager_first,
    mgr_contact.last_name as manager_last,
    mgr_contact.email as manager_email
FROM properties p
LEFT JOIN companies owner ON p.owner_company_id = owner.id
LEFT JOIN contacts owner_contact ON owner.primary_contact_id = owner_contact.id
LEFT JOIN companies mgr ON p.property_manager_id = mgr.id
LEFT JOIN contacts mgr_contact ON mgr.primary_contact_id = mgr_contact.id
WHERE p.id = 1;
```

---

### Scenario 3: Strata Building

**Setup:**
```
companies
├── id: 1 (Strata Corporation)
│   ├── company_name: "The Towers Strata #1234"
│   ├── company_type: "strata"
│   ├── primary_contact_id: 5 (Strata manager)
│   ├── billing_address: Strata office address
│
└── id: 2 (Property manager hired by strata)
    ├── company_name: "ABC Property Management"
    ├── company_type: "property_manager"
    ├── primary_contact_id: 3 (Manager contact)

contacts
├── id: 3 (Property manager contact)
├── id: 5 (Strata manager contact)

properties
├── id: 101 (Unit 201)
│   ├── address: "The Towers, 500 Main St, Unit 201"
│   ├── city: "Vancouver"
│   ├── property_type: "strata"        ← It's a strata unit
│   ├── site_contact_id: NULL          ← No individual contact
│   ├── owner_company_id: 1            ← Strata corp owns it
│   └── property_manager_id: 2         ← ABC Property Management manages it
│
└── id: 102 (Unit 202)
    ├── ... same as above ...

company_properties
├── [1] company_id: 1, property_id: 101, relationship_type: "owner"
├── [2] company_id: 1, property_id: 102, relationship_type: "owner"
├── [3] company_id: 2, property_id: 101, relationship_type: "manager"
└── [4] company_id: 2, property_id: 102, relationship_type: "manager"
```

**Invoice Routing for Strata Unit:**
- Create invoice for property (Unit 201)
- Add to `invoice_contacts`:
  - Strata manager → strata_manager role
  - Property manager → property_manager role
  - Maybe accounting department → accounting role

---

### Scenario 4: Commercial Building with Different Billing Address

**Setup:**
```
invoices (for work done at property)
├── id: 1
├── job_id: 5
├── property_id: 50
├── company_id: 10
│
├── Service Address (where work was done):
│   ├── service_address: "500 Commercial Blvd, Unit 300"
│   ├── service_city: "Vancouver"
│
├── Billing Address (where invoice is sent):
│   ├── billing_address: "Corporate HQ, 100 Finance St"
│   ├── billing_city: "Seattle"
│   ├── address_differs: 1
│
├── invoice_contacts:
│   ├── [1] contact_role: "primary_recipient"
│   │        email: "accounting@hq.com"
│   │
│   ├── [2] contact_role: "property_manager"
│   │        email: "property@abcpm.com"
│   │
│   └── [3] contact_role: "billing_contact"
│            email: "billing@hq.com"
```

---

## PHP Usage Examples

### Get Property with All Company Information

```php
$stmt = $db->prepare("
    SELECT
        p.*,
        owner.id as owner_company_id,
        owner.company_name as owner_name,
        owner.billing_address as owner_billing_address,
        owner_contact.first_name as owner_first,
        owner_contact.last_name as owner_last,
        owner_contact.email as owner_email,
        mgr.id as manager_company_id,
        mgr.company_name as manager_name,
        mgr_contact.first_name as manager_first,
        mgr_contact.last_name as manager_last,
        mgr_contact.email as manager_email,
        site_contact.first_name as site_first,
        site_contact.last_name as site_last,
        site_contact.email as site_email
    FROM properties p
    LEFT JOIN companies owner ON p.owner_company_id = owner.id
    LEFT JOIN contacts owner_contact ON owner.primary_contact_id = owner_contact.id
    LEFT JOIN companies mgr ON p.property_manager_id = mgr.id
    LEFT JOIN contacts mgr_contact ON mgr.primary_contact_id = mgr_contact.id
    LEFT JOIN contacts site_contact ON p.site_contact_id = site_contact.id
    WHERE p.id = ?
");
$stmt->execute([$propertyId]);
$property = $stmt->fetch(PDO::FETCH_ASSOC);
```

### Get Invoice Recipients

```php
$stmt = $db->prepare("
    SELECT
        ic.id,
        ic.contact_role,
        ic.email_address,
        c.first_name,
        c.last_name,
        c.email as contact_email
    FROM invoice_contacts ic
    LEFT JOIN contacts c ON ic.contact_id = c.id
    WHERE ic.invoice_id = ?
    ORDER BY
        CASE ic.contact_role
            WHEN 'primary_recipient' THEN 1
            WHEN 'billing_contact' THEN 2
            WHEN 'accounting' THEN 3
            WHEN 'property_manager' THEN 4
            WHEN 'strata_manager' THEN 5
            WHEN 'owner_contact' THEN 6
            ELSE 7
        END,
        ic.created_at
");
$stmt->execute([$invoiceId]);
$recipients = $stmt->fetchAll(PDO::FETCH_ASSOC);
```

### Create Invoice with Multiple Recipients

```php
// 1. Create invoice
$stmt = $db->prepare("
    INSERT INTO invoices (
        job_id, property_id, company_id,
        service_address, service_city, service_province,
        billing_address, billing_city, billing_province,
        address_differs
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
");
$stmt->execute([
    $jobId, $propertyId, $companyId,
    $property['address'], $property['city'], $property['province'],
    $company['billing_address'], $company['billing_city'], $company['billing_province'],
    ($property['address'] !== $company['billing_address']) ? 1 : 0
]);

$invoiceId = $db->lastInsertId();

// 2. Add recipients based on invoice_routing_method
if ($company['invoice_routing_method'] === 'custom_contacts') {
    // Add each recipient from custom mapping
    $recipients = [
        [
            'contact_id' => $property['manager_contact_id'],
            'role' => 'property_manager',
            'email' => $propertyManagerEmail
        ],
        [
            'contact_id' => $company['billing_contact_id'],
            'role' => 'billing_contact',
            'email' => $company['billing_email']
        ]
    ];

    $stmt = $db->prepare("
        INSERT INTO invoice_contacts (
            invoice_id, contact_id, contact_role, email_address
        ) VALUES (?, ?, ?, ?)
    ");

    foreach ($recipients as $recipient) {
        $stmt->execute([
            $invoiceId,
            $recipient['contact_id'],
            $recipient['role'],
            $recipient['email']
        ]);
    }
}
```

---

## Migration Instructions

1. **Backup your database:**
   ```
   mysqldump -u username -p database_name > backup.sql
   ```

2. **Run the migration:**
   ```
   mysql -u username -p database_name < 015_property_management_relationships.sql
   ```

3. **Or in phpMyAdmin:**
   - Go to your database
   - Click "SQL" tab
   - Copy & paste the migration file content
   - Click "Go"

---

## Configuration in Code

### Example: Setting up a property with a manager

```php
// 1. Create/get the property owner company
$stmt = $db->prepare("
    INSERT INTO companies (company_name, company_type, primary_contact_id)
    VALUES (?, ?, ?)
");
$stmt->execute(["Smith Family", "individual", $ownerContactId]);
$ownerCompanyId = $db->lastInsertId();

// 2. Create/get the property manager company
$stmt = $db->prepare("
    INSERT INTO companies (
        company_name,
        company_type,
        primary_contact_id,
        invoice_routing_method
    ) VALUES (?, ?, ?, ?)
");
$stmt->execute(["ABC Property Management", "property_manager", $managerContactId, "custom_contacts"]);
$managerCompanyId = $db->lastInsertId();

// 3. Create the property with both links
$stmt = $db->prepare("
    INSERT INTO properties (
        address, city, property_type,
        site_contact_id,
        owner_company_id,
        property_manager_id
    ) VALUES (?, ?, ?, ?, ?, ?)
");
$stmt->execute([
    "123 Main St",
    "Vancouver",
    "single_family",
    $ownerContactId,
    $ownerCompanyId,
    $managerCompanyId
]);
$propertyId = $db->lastInsertId();

// 4. Create company_properties links
$stmt = $db->prepare("
    INSERT INTO company_properties (company_id, property_id, relationship_type, is_primary)
    VALUES (?, ?, ?, ?)
");
// Owner relationship
$stmt->execute([$ownerCompanyId, $propertyId, "owner", 1]);
// Manager relationship
$stmt->execute([$managerCompanyId, $propertyId, "manager", 0]);
```

---

## Summary

This schema supports:
- ✅ Direct client relationships
- ✅ Property management companies managing properties
- ✅ Strata corporations with multiple units
- ✅ Multiple contacts per property/invoice
- ✅ Different billing addresses
- ✅ Complex routing rules for invoices
- ✅ Payment method tracking
- ✅ Invoice delivery tracking (bounces, opens)

All relationships are flexible, maintainable, and scalable for future features.
