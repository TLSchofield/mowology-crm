# Prospects Integration - Quote Requests as Clients

## Overview

Quote requests from the website are now integrated into the Clients dashboard with **Prospect** status. This allows you to manage leads, inquiries, and potential customers all in one place.

**Flow:**
```
Website Quote Request
    ↓
Shows in Clients Dashboard (yellow highlight)
    ↓
One-click "Convert to Prospect" button
    ↓
Creates client record with "Prospect" status
    ↓
Can now edit, send quotes, track as regular client
```

---

## What Changed

### Clients Dashboard Now Shows

**Section 1: Active Clients & Prospects**
- Lists all companies (clients + prospects)
- Prospects highlighted in yellow
- Shows "🔵 Prospect" label
- Status shows: "Prospect - new/reviewing/quoted/converted/declined"

**Section 2: Unconverted Quote Requests** (NEW)
- New inquiries that haven't been converted yet
- Shows contact name, property, services, urgency, date
- **Two action buttons:**
  - 👁️ View — Open in quote-requests.php to review
  - ➕ Convert — Create prospect client record

---

## How to Use

### View All Prospects
1. Go to `https://mowology.ca/crm/clients_appstack.php`
2. **Clients & Prospects table** shows all
3. Prospects have yellow background + "🔵 Prospect" label
4. View status: "Prospect - new/reviewing/etc"

### Convert Quote Request to Prospect
1. Scroll to **"New Quote Requests"** section
2. Find the request you want to convert
3. Click **➕ (Convert)** button
4. Confirm when prompted
5. System creates company record with:
   - Contact name as company name
   - Email, address pre-filled from request
   - Status: Active
   - PDF attachment: Enabled by default
6. Page refreshes, prospect now in main table

### Edit a Prospect
1. Find prospect in main clients table (yellow background)
2. Click **Edit** button
3. Update any information:
   - Company name
   - Billing details
   - Email preferences
   - Account status (change to "inactive" to mark as declined)
4. Save
5. Can now send quotes, create jobs, etc.

### Mark Prospect as Converted/Declined
1. Edit the prospect
2. Change **Account Status** to:
   - **Active** — Still interested
   - **Inactive** — Declined/No longer interested
   - **Suspended** — On hold temporarily
3. Save

---

## Data Model

### Quote Requests Table
```sql
CREATE TABLE quote_requests (
    id INT PRIMARY KEY,
    contact_id INT,              -- Person who submitted
    property_id INT,              -- Property location
    company_id INT,               -- NOW LINKED to companies table
    service_types VARCHAR(255),
    urgency ENUM('inquiring','soon','asap'),
    project_description TEXT,
    status ENUM('new','reviewing','quoted','converted','declined','spam'),
    created_at TIMESTAMP,
    ...
);
```

### Companies Table (Prospects Column)
```sql
CREATE TABLE companies (
    id INT PRIMARY KEY,
    company_name VARCHAR(200),
    company_type ENUM('individual','business','strata','property_manager'),
    billing_email VARCHAR(255),
    account_status ENUM('active','inactive','suspended'),
    pref_attach_pdf TINYINT(1),   -- PDF preference
    created_at TIMESTAMP,
    ...
);
```

### Connection Flow
```
quote_requests.company_id (NULL initially)
    ↓
Click "Convert"
    ↓
Creates new companies record
    ↓
Updates quote_requests.company_id to new company
    ↓
Now appears in clients dashboard
```

---

## Visual Indicators

### Prospect Badge
- **🔵 Prospect** label (in blue) on prospect names
- Yellow background row
- Status: "Prospect - [quote_request_status]"

### Quote Request Status
| Status | Meaning |
|--------|---------|
| new | Just submitted, awaiting review |
| reviewing | Being reviewed by team |
| quoted | Quote has been created |
| converted | Converted to actual client/job |
| declined | Prospect declined offer |
| spam | Invalid/spam request |

### Urgency Indicator
In "New Quote Requests" section:
- 🔴 **ASAP** — Red badge (urgent)
- 🟠 **SOON** — Orange badge (coming up)
- 🔵 **INQUIRING** — Blue badge (general inquiry)

---

## Workflow Examples

### Example 1: Website Lead Becomes Client
```
1. Customer fills quote form on website
2. New quote request created
3. You check Clients dashboard
4. See it in "New Quote Requests" section
5. Click ➕ Convert → Creates prospect
6. Edit prospect to add more info
7. Create quote for them
8. Once quote accepted → can create job
```

### Example 2: Prospect Becomes Active Client
```
1. Prospect client in dashboard (yellow)
2. Edit prospect
3. Change company_type from 'individual' to 'business' (if applicable)
4. Change account_status to 'active' (already is by default)
5. Now treated as regular client
6. Can assign properties, jobs, recurring services
```

### Example 3: Prospect Declines
```
1. Prospect in dashboard (yellow)
2. Edit prospect
3. Change Account Status to 'inactive'
4. Save
5. Still visible but marked as declined
6. Won't appear in active filters
```

---

## Database Queries

### Get All Prospects
```sql
SELECT c.*, qr.status as prospect_status
FROM companies c
LEFT JOIN quote_requests qr ON c.id = qr.company_id
WHERE qr.id IS NOT NULL AND qr.status IN ('new', 'reviewing')
ORDER BY c.created_at DESC;
```

### Get Unconverted Requests
```sql
SELECT *
FROM quote_requests
WHERE company_id IS NULL AND status IN ('new', 'reviewing')
ORDER BY urgency, created_at DESC;
```

### Convert Request to Prospect
```sql
-- Step 1: Create company
INSERT INTO companies (company_name, company_type, billing_email, account_status, pref_attach_pdf)
VALUES ('John Doe', 'individual', 'john@example.com', 'active', 1);

-- Step 2: Link quote request
UPDATE quote_requests SET company_id = [NEW_COMPANY_ID] WHERE id = [REQUEST_ID];
```

---

## API

### AJAX Endpoint: Convert Request to Prospect

**URL:**
```
POST /crm/clients_appstack.php?action=convert_to_prospect
```

**Request:**
```json
{
  "request_id": 123,
  "csrf_token": "..."
}
```

**Response (Success):**
```json
{
  "success": true,
  "company_id": 45
}
```

**Response (Error):**
```json
{
  "success": false,
  "error": "Quote request not found"
}
```

---

## Implementation Details

### New Features in clients_appstack.php

1. **Enhanced client query:**
   - Joins with quote_requests
   - Identifies prospects vs regular clients
   - Shows prospect status

2. **Unconverted requests section:**
   - Separate table for new requests
   - Action buttons: View & Convert
   - Color-coded urgency badges

3. **AJAX handler:**
   - Converts quote request to prospect
   - Creates company record
   - Links the two records

4. **JavaScript function:**
   - `convertToProspect()` - Handles conversion UI flow
   - Confirmation dialog
   - Auto-reload on success

---

## Status Flowchart

```
Quote Request (new)
    ↓
Unconverted Requests section
    ↓
Click ➕ Convert
    ↓
Creates company (prospect)
    ↓
Main Clients table (yellow)
    ↓
Click Edit → Change Status to 'inactive' if declined
         or keep 'active' if progressing
    ↓
Create Quote → Link to prospect
    ↓
Send Quote
    ↓
Quote marked as 'quoted'
    ↓
If Accepted → Create Job
    ↓
Status updates to 'converted'
```

---

## Benefits

✅ **Single Dashboard** — All potential clients in one place
✅ **Efficient** — One-click convert from inquiry to prospect
✅ **Trackable** — See prospect status (new/reviewing/quoted/converted/declined)
✅ **Editable** — Update prospect info before creating quote
✅ **Flexible** — Can convert back to inactive if no longer interested
✅ **Searchable** — All prospects searchable with regular clients

---

## Files Modified

- `/crm/clients_appstack.php` — Main changes:
  - Enhanced client query with prospects
  - Unconverted requests section
  - Convert action handler
  - AJAX conversion function

- `/crm/includes/email_helper.php` — No changes (already supports)
- `/crm/quotes/view.php` — No changes (already works with prospects)

---

## Notes

- Prospects default to:
  - Account Status: **Active**
  - Company Type: **Individual**
  - PDF Attachment: **Enabled**
  - All email fields can be edited later

- Quote requests that are converted:
  - Remain linked to original quote_request record
  - Can track full lifecycle (inquiry → prospect → client → job)
  - Can view original request details

- Unconverted requests stay in separate section until converted
- Converted prospects appear in main clients list (yellow highlight)

---

## Changelog

**v1.0 (Feb 6, 2026)**
- ✨ Quote requests now visible as prospects
- ✨ One-click convert quote request to prospect
- ✨ Prospects show in clients dashboard (yellow highlight)
- ✨ Track prospect status (new/reviewing/quoted/converted/declined)
- ✨ Edit prospect information before creating quote
- 🔄 Unified client and prospect management
