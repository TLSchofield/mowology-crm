# Next-Gen Job Creation — Quick Start Guide

**For:** Admin users and developers
**Date:** February 8, 2026
**Time to read:** 5 minutes

---

## What This System Does

**Old way:** 2–3 minutes per job (manual form-filling, errors common)

**New way:** <30 seconds per job (smart defaults, guardrails prevent mistakes)

---

## How to Deploy Migrations

### Step 1: Access Settings > Database / Migrations

```
1. Login to CRM as admin
2. Click Settings (⚙️) in sidebar
3. Click "Database / Migrations" tab
4. You'll see pending migrations listed
```

### Step 2: Execute Migrations in Order

**DO NOT skip steps. Run in this exact order:**

1. **Migration 025** — Service Packages table
   - Creates reusable service definitions (mowing, trimming, etc.)
   - Pre-seeds 8 default packages
   - Takes 2–3 seconds

2. **Migration 026** — Billing Templates table
   - Creates invoice template definitions
   - Pre-seeds 4 default templates
   - Takes 1–2 seconds

3. **Migration 027** — Job Proof of Work table
   - Tracks checklist/photos/GPS requirements per job
   - Takes 1–2 seconds

4. **Migration 028** — Update Jobs table
   - Adds service_package_id, billing_template_id columns
   - Links jobs to the new tables
   - Takes 2–3 seconds

### Step 3: Verify Success

```
Settings > Database / Migrations > View History

You should see:
✓ 025_create_service_packages.sql — Success
✓ 026_create_billing_templates.sql — Success
✓ 027_create_job_proof_of_work.sql — Success
✓ 028_update_jobs_for_service_packages.sql — Success
```

---

## What Gets Created

### Service Packages (8 defaults included)

| Package | Duration | Price | Best For |
|---------|----------|-------|----------|
| Lawn Mowing Standard | 45 min | $65 | Small residential lawns |
| Lawn Mowing Large | 90 min | $120 | Large properties (2 crew) |
| Hedge Trim Light | 60 min | $75 | Light trimming |
| Hedge Trim Heavy | 120 min | $150 | Heavy trimming + hauling (2 crew) |
| Spring Cleanup | 120 min | $200 | Seasonal spring cleaning (2 crew) |
| Garden Maintenance | 60 min | $65 | Weekly weeding & mulch |
| Snow Removal (Per Visit) | 30 min | $75 | Single driveway clearing |
| Snow Removal (Seasonal) | — | $450/month | Monthly winter plan |

### Billing Templates (4 defaults included)

| Template | Mode | When | Best For |
|----------|------|------|----------|
| Per Visit | One invoice per job | On completion | Mowing, one-time services |
| Monthly Grouped | Combine jobs | End of month | Multiple services same property |
| Monthly Flat | Flat fee | End of month | Strata/condo contracts |
| Seasonal Prepay | Prepay before | Upfront | Snow removal, spring cleanup |

---

## How to Use the New Job Creation

### Keyboard Shortcut (Speed)

Press **Ctrl+Shift+J** from any page to open new job dialog.

### 4-Tab Workflow

#### Tab 1: BASICS
- **Client** → Type name, auto-search appears
- **Property** → Auto-selected if client has only 1
- **Service Package** → Click one (all defaults apply automatically)
- **Modifiers** (optional) → Add extras like "green waste removal"

#### Tab 2: SCHEDULE
- **Date** → Calendar shows available dates (crew-based)
- **Time** → Auto-suggested based on crew location & duration
- **Crew** → Pre-assigned, shows conflicts if any
- **Recurrence** (if recurring) → Weekly, bi-weekly, monthly

#### Tab 3: PROOF & BILLING
- **Billing Template** → Locked to service package, but visible
- **Checklist Items** → Shows what crew must check (read-only, from package)
- **Photos Required** → Shows what photos needed (read-only)
- **Price Preview** → Shows what customer will be invoiced

#### Tab 4: REVIEW
- Everything summarized
- Click "CREATE JOB" to confirm
- Crew gets notified instantly
- Job appears on calendar

---

## Common Workflows

### Scenario 1: Weekly Lawn Mowing (30 seconds)

```
Press Ctrl+Shift+J
Type "Smith Residence"
(Property auto-selects if only 1)
Click "Lawn Mowing Standard"
(Defaults: 45 min, 1 crew, $65)
Press Tab → Next Wednesday auto-filled
(Based on crew location & route)
Tab to Crew → "Sarah Johnson" pre-selected
(Nearest available crew)
Click Review → CREATE JOB
```

**Result:** Job scheduled, crew notified, proof requirements set

---

### Scenario 2: New Strata Contract (2 minutes)

```
Press Ctrl+Shift+J
Type "Granview Towers Strata"
(Shows 3 buildings in complex)
Select "Building A — 456 Oak St"
Click "Garden Maintenance"
Press Tab → Shows modifier options
  Check "Aeration" (+$40)
Click "Recurring"
Change frequency to "Monthly"
(Billing template auto-switches to "Monthly Grouped")
Select day: "1st Friday of month"
Crew: "Michael T." suggested
(Works this property Fridays)
Check for conflicts
→ All green ✓
Click Review → CREATE JOB
```

**Result:** 12-job series created, invoiced monthly, crew assigned

---

### Scenario 3: Same-Day Emergency (1 minute)

```
Press Ctrl+Shift+J
Type "Quick Hedge Trim"
Client: "Jones Residence"
Service: "Hedge Trim Light"
Schedule: TODAY
Time: Shows "2–3 PM" (crew is 2km away)
Crew: "Sarah Johnson" (crew in zone)
Create → Done
```

**Result:** Added to Sarah's route, GPS updated, customer notified

---

## What Happens Next (Automatically)

### When Job is Created
1. ✓ Job number assigned (JOB-2026-XXXX)
2. ✓ Crew receives notification in app
3. ✓ Calendar updated with crew route
4. ✓ Proof of work requirements set
5. ✓ Customer sent confirmation email (optional)

### When Crew Completes Job
1. ✓ Crew marks job "Complete"
2. ✓ Crew submits checklist (before/trim_edges/etc.)
3. ✓ Crew uploads required photos (before, after)
4. ✓ Job proof of work locked (can't delete photos)

### When Proof is Verified
1. ✓ Job eligible for invoicing
2. ✓ Invoice generated automatically (per billing template)
3. ✓ Invoice sent to customer (per template settings)
4. ✓ Payment tracking begins

---

## What the Numbers Mean

### Duration
**45 minutes** = Time crew should allocate for job (includes setup/cleanup)

### Crew Size
**2 crew** = Recommended team for this service (can override)

### Base Price
**$65** = Per-visit cost before tax (can add modifiers for +$25, etc.)

### Margin Target
**35%** = Profit margin goal (shown on review tab)

---

## If Something Goes Wrong

### Error: "Crew has no capacity on that date"
**What to do:** Try a different date or select a different crew

### Error: "Billing template not compatible"
**What to do:** System automatically switches to compatible template

### Error: "Required field missing"
**What to do:** Fill in the red-highlighted field on current tab

### Error: "Checklist not complete (crew app)"
**What to do:** Invoice will be blocked until crew finishes checklist

---

## Admin: Adding Custom Service Packages

### Via Database (Advanced)

Add to `service_packages` table:

```sql
INSERT INTO service_packages
(package_name, slug, description, base_price, default_duration_minutes, default_crew_size, service_type, checklist_items, photo_types_required)
VALUES
('Custom Package Name', 'custom-slug', 'Description', 99.99, 60, 1, 'service_type', '["check1", "check2"]', '["before", "after"]');
```

### Via Settings UI (Future)

Settings > Service Packages > Add New → Fill form

---

## Support / Questions

### For Crew
- Training: Show them the proof checklist in the job details
- Photos: Explain which photos are required before marking complete
- Questions: "Why do I need a checklist?" → Invoice won't send without it

### For Admins
- Service packages locked during job creation (can't change defaults mid-workflow)
- Modifiers are optional add-ons (crew doesn't see them, just affects price)
- Recurring jobs auto-generate instances (no manual cloning needed)

### For Customers
- They see the service name and scheduled time (not internal package info)
- They see the proof photos in their portal after job completion
- Invoice includes what was done (from job description)

---

## Key Takeaways

✅ **Job creation is now 4–6x faster** (smart defaults)
✅ **Mistakes prevented automatically** (guardrails, not warnings)
✅ **Crew knows expectations upfront** (checklist + photos defined at creation)
✅ **Invoices generate automatically** (once proof verified)
✅ **Scheduling conflicts impossible** (validated before save)

---

**Status:** Ready to use
**Questions?** See `NEXTGEN_JOB_CREATION_DESIGN.md` for full details

