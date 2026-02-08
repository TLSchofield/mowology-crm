# Kanban Board Testing & Demo Data

## Quick Start

### 1. Populate Test Data

Visit: **http://localhost:8888/lcrm/POPULATE_TEST_DATA.php**

Click **"Populate Database"** to add 7 sample quotes across all stages:

| Stage | Count | Examples |
|-------|-------|----------|
| **Draft** | 2 | Quotes just created, not sent to client |
| **Sent** | 2 | Quotes sent but client hasn't viewed |
| **Viewed** | 1 | Client opened the quote, deciding |
| **Accepted** | 2 | Client approved, ready to schedule |

### 2. View the Kanban Board

Visit: **http://localhost:8888/lcrm/crm/quotes_appstack.php**

You'll see:
- **4 columns** representing quote stages
- **Quote cards** showing client name, quote number, amount, and dates
- **View toggle** between kanban and table layouts
- **Quote counts** in column headers

### 3. Test Interactions

- **Click any quote card** → Opens detailed view/edit page
- **Toggle view** → Switch between kanban and table layouts
- **Search** → Filter quotes by client name or quote number

---

## Test Data Details

### Draft Quotes (Column 1: "Quote Enquiry")
- `TEST-2026-0001`: Spring Lawn Maintenance - $450
- `TEST-2026-0002`: Garden Bed Edging - $320

### Sent Quotes (Column 2: "Quote Sent")
- `TEST-2026-0003`: Mulch Installation - $680 (sent 3 days ago)
- `TEST-2026-0004`: Tree Trimming Service - $950 (sent 5 days ago)

### Viewed Quotes (Column 2: "Quote Sent")
- `TEST-2026-0005`: Lawn Aeration - $425 (viewed 3 times, sent 2 days ago)

### Accepted Quotes (Column 3: "Quote Approved")
- `TEST-2026-0006`: Spring Cleanup - $750 (accepted 2 days ago)
- `TEST-2026-0007`: Monthly Maintenance - $400 (accepted 5 days ago)

---

## Kanban Board Structure

### Columns

**Column 1: Quote Enquiry (Draft)**
- Status: `draft`
- Represents quotes in progress, not yet sent to client

**Column 2: Quote Sent (Sent/Viewed)**
- Status: `sent` or `viewed`
- Represents quotes sent but not yet accepted

**Column 3: Quote Approved (Accepted)**
- Status: `accepted`
- Represents client approvals, pending job creation
- Special styling: Golden accent

**Column 4: Approved & Scheduled (Accepted + Job)**
- Status: `accepted` + `job_id` not null
- Represents quotes converted to jobs

---

## Debugging the Kanban Board

### Database Queries

**See all test quotes:**
```sql
SELECT id, quote_number, title, total_amount, status, sent_at, viewed_at, accepted_at
FROM quotes
WHERE quote_number LIKE 'TEST-%'
ORDER BY created_at DESC;
```

**Check quote counts by stage:**
```sql
SELECT status, COUNT(*) as count
FROM quotes
WHERE quote_number LIKE 'TEST-%'
GROUP BY status;
```

**See line items for a quote:**
```sql
SELECT qli.description, qli.quantity, qli.unit_type, qli.unit_price, qli.subtotal
FROM quote_line_items qli
JOIN quotes q ON qli.quote_id = q.id
WHERE q.quote_number = 'TEST-2026-0001';
```

Use these queries in **http://localhost:8888/lcrm/DEBUG_UTILITY.php**

---

## Clearing Test Data

To delete all test quotes (those starting with `TEST-`):

```sql
DELETE FROM quotes WHERE quote_number LIKE 'TEST-%';
```

Or use the data population tool again — it automatically removes old test data before adding new data.

---

## CSS Classes for Kanban

| Class | Purpose |
|-------|---------|
| `.mw-kanban-board` | Main grid container |
| `.mw-kanban-column` | Individual column |
| `.mw-kanban-column-header` | Column title |
| `.mw-kanban-column-count` | Quote count badge |
| `.mw-kanban-card` | Individual quote card |
| `.mw-kanban-card.status-draft` | Draft card styling (gray border) |
| `.mw-kanban-card.status-sent` | Sent card styling (blue border) |
| `.mw-kanban-card.status-accepted` | Accepted card styling (green border) |
| `.mw-kanban-card-header` | Quote number + amount |
| `.mw-kanban-card-client` | Client name |
| `.mw-kanban-card-meta` | Date info |
| `.column-approved` | Special styling for approved column |

See `/crm/css/mowology-brand.css` lines 2896-3081 for full styling.

---

## Notes

- ✅ Test data tool only works on localhost
- ✅ Safe to run multiple times (auto-clears old test data)
- ✅ Test quotes won't be uploaded to live server (ignored via `.gitignore`)
- ❌ Never use test quotes in production
