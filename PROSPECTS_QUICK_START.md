# Prospects - Quick Start Guide

## The Problem (Solved)

**Before:** Quote requests from website were scattered across the "Territory Map" and "Quote Requests" pages. Hard to track, manage, or convert to actual clients.

**Now:** All quote requests (prospects) show directly in the **Clients Dashboard** for easy management.

---

## Three Quick Steps

### 1️⃣ View Prospects
```
Go to: https://mowology.ca/crm/clients_appstack.php
       ↓
       Scroll to "New Quote Requests" section
       ↓
       See all incoming leads
```

### 2️⃣ Convert to Prospect Client
```
Find request you want
       ↓
Click ➕ Convert button
       ↓
Confirm in dialog
       ↓
Done! It's now a prospect client
```

### 3️⃣ Edit & Send Quote
```
Find prospect in main clients table (yellow highlight)
       ↓
Click Edit
       ↓
Update info if needed
       ↓
Create quote
       ↓
Send to prospect
```

---

## Visual Overview

### Clients Dashboard Now Has Two Sections

**Section A: Clients & Prospects (Main Table)**
```
┌─────────────────────────────────────────────────┐
│ Name          │ Type  │ Email   │ Status        │
├─────────────────────────────────────────────────┤
│ Acme Corp     │ Biz   │ ...     │ Active        │  ← Regular client
│ 🔵 John Doe   │ Indiv │ john@.. │ Prospect-new  │  ← Yellow = Prospect
│ Smith Lawn    │ Biz   │ ...     │ Active        │  ← Regular client
└─────────────────────────────────────────────────┘
```

**Section B: New Quote Requests (New Section)**
```
┌────────────────────────────────────────────────┐
│ Contact    │ Services  │ Urgency  │ Actions     │
├────────────────────────────────────────────────┤
│ Jane Roe   │ Mowing    │ 🔴 ASAP  │ 👁️ ➕      │
│ Bob Miller │ Cleanup   │ 🟠 SOON  │ 👁️ ➕      │
└────────────────────────────────────────────────┘
```

---

## Button Reference

| Button | What It Does |
|--------|-------------|
| **Edit** | Open prospect to view/edit details |
| **👁️ (View)** | Open original quote request details |
| **➕ (Convert)** | Convert quote request to prospect client |
| **Delete** | Delete prospect from database |

---

## Prospect Status Indicators

| Indicator | Meaning |
|-----------|---------|
| **Yellow background** | This is a prospect (not a regular client) |
| **🔵 Prospect** | Prospect label |
| **Prospect - new** | Just submitted, awaiting review |
| **Prospect - reviewing** | Being reviewed |
| **Prospect - quoted** | Quote has been sent |
| **Prospect - converted** | Converted to job |
| **Prospect - declined** | Declined/no longer interested |

---

## Workflow

```
Customer fills quote form on website
            ↓
Quote request created
            ↓
Shows in "New Quote Requests" section (yellow table)
            ↓
YOU: Click ➕ Convert
            ↓
System creates "prospect client"
            ↓
Now appears in main clients table (yellow)
            ↓
YOU: Click Edit to review/update info
            ↓
YOU: Create quote for prospect
            ↓
YOU: Send quote
            ↓
YOU: If accepted → Convert to regular job
```

---

## Common Tasks

### Task 1: View All New Leads
```
1. Go to Clients dashboard
2. Scroll to "New Quote Requests"
3. See all incoming leads sorted by urgency
```

### Task 2: Convert a Lead to Prospect
```
1. Find lead in "New Quote Requests"
2. Click ➕ Convert
3. Confirm dialog
4. It moves to main clients table
```

### Task 3: Send Quote to Prospect
```
1. Go to Clients dashboard
2. Find prospect (yellow row)
3. Click Edit
4. Note the email address
5. Go to Quotes section
6. Create new quote for that prospect
7. Send quote
```

### Task 4: Mark Prospect as "Declined"
```
1. Find prospect in main table
2. Click Edit
3. Change "Account Status" to "Inactive"
4. Save
5. Still visible but marked as declined
```

### Task 5: Update Prospect Information
```
1. Find prospect (yellow row)
2. Click Edit
3. Update company name, email, address, etc.
4. Toggle "Attach PDF" if needed
5. Save
```

---

## Key Differences: Prospect vs Client

| Aspect | Prospect | Client |
|--------|----------|--------|
| **Source** | Website form | Manual entry or converted prospect |
| **Status Badge** | "Prospect - [status]" | "Active/Inactive/Suspended" |
| **Visual** | Yellow background | White background |
| **Lifecycle** | Inquiry → Quote → Job | Service ongoing |
| **Label** | 🔵 Prospect | None |
| **How Created** | Website form | You click "Add New" or Convert |

---

## FAQ

**Q: Can I edit a prospect?**
A: Yes! Click Edit like any client. Update name, email, address, PDF preference, etc.

**Q: What happens to the original quote request after I convert it?**
A: It stays linked to the new prospect client record. You can view the original request by clicking the 👁️ button in the "New Quote Requests" section.

**Q: Can I delete a prospect?**
A: Yes, click Edit and then Delete at the bottom. This also removes the quote request link.

**Q: What if I want to mark a prospect as "no longer interested"?**
A: Click Edit, change Account Status to "Inactive", and save. It stays in the list but won't appear in active filters.

**Q: Do prospects automatically get PDFs when I send quotes?**
A: Yes, by default. Each prospect has "Attach PDF" checked. You can uncheck it individually.

**Q: Can I convert multiple quote requests at once?**
A: Not yet—one at a time. Each request has its own Convert button.

---

## Next Steps

1. ✅ Go to https://mowology.ca/crm/clients_appstack.php
2. ✅ Look for "New Quote Requests" section
3. ✅ Click ➕ Convert on first request
4. ✅ Now manage as prospect in main clients table
5. ✅ Create and send quotes to prospects
6. ✅ Convert interested prospects to jobs

---

## Need More Detail?

See `PROSPECTS_INTEGRATION.md` for:
- Technical implementation details
- Database schema
- API endpoints
- Advanced workflows
