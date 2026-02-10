# Next-Generation Job Creation System
## Mowology CRM — Field Service Excellence

**Date:** February 8, 2026
**Status:** Design Phase (Ready for Implementation)
**Scope:** Complete job creation redesign for speed, intelligence, and profitability

---

## Executive Summary

The current job creation system requires manual form-filling and lacks intelligent defaults. This design creates a **confirmation-based workflow** that:

- ✅ Reduces job creation from 2–3 minutes to **under 30 seconds** for repeat clients/properties
- ✅ Prevents scheduling conflicts, billing errors, and crew mistakes via guardrails
- ✅ Auto-fills 70–90% of required fields through service packages and context
- ✅ Ties job creation directly to crew execution, proof of work, and invoicing
- ✅ Exceeds Jobber's UX through keyboard-first workflow and intelligent defaults

---

## Part 1: Core Data Model

### 1.1 Service Packages Table

**Purpose:** Service packages are the primary job creation driver. Selecting a package auto-configures job duration, crew size, billing template, and checklist requirements.

**Migration:** `025_create_service_packages.sql`

```sql
CREATE TABLE service_packages (
  id INT AUTO_INCREMENT PRIMARY KEY,

  -- Identity
  package_name VARCHAR(100) NOT NULL,
  slug VARCHAR(100) UNIQUE NOT NULL,              -- lawn-mowing-standard, hedge-trim-large, etc.
  description TEXT,
  icon_name VARCHAR(50),                          -- Feather icon: leaf, scissors, wand-2, etc.
  category VARCHAR(50),                           -- mowing, trimming, cleanup, seasonal, treatment, rental

  -- Defaults for job creation
  default_duration_minutes INT DEFAULT 60,        -- Affects crew ETA, scheduling
  default_crew_size INT DEFAULT 1,                -- Affects crew suggestions
  default_visit_frequency VARCHAR(30),            -- weekly, biweekly, monthly, seasonal, one_time

  -- Billing & Pricing
  base_price DECIMAL(10,2) NOT NULL,              -- Price per visit
  unit_type VARCHAR(20) DEFAULT 'visit',          -- visit, hour, sqft, month, season
  billing_template_id INT,                        -- Links to billing_templates table
  default_billing_interval VARCHAR(30),           -- per_visit, monthly_grouped, monthly_flat, prepay
  margin_target_percent INT DEFAULT 35,           -- Affects pricing suggestions

  -- Proof of Work
  checklist_items JSON,                           -- Required items (before checklist, after, etc.)
  photo_types_required JSON,                      -- before, during, after, issue (maps to job_photos.type)
  gps_enforcement VARCHAR(20) DEFAULT 'optional', -- required, optional, not_required
  photos_block_completion BOOLEAN DEFAULT FALSE,  -- If true, crew can't complete without photos
  checklist_blocks_completion BOOLEAN DEFAULT FALSE,

  -- Seasonal behavior
  seasonal_available VARCHAR(100),                -- months available: "4-10" (Apr-Oct), "11-2" (Nov-Feb)
  estimated_seasonal_recurrence VARCHAR(30),     -- If package is seasonal: frequency per year

  -- Modifiers (inline price adjustments)
  modifiers JSON,  -- [{ id: "green-waste", name: "+$30 Green Waste Removal", cost_modifier: 30 }, ...]

  -- Service types (legacy — for filtering/reporting)
  service_type VARCHAR(50),  -- mowing, trimming, etc. (ENUM from jobs table)

  -- State
  is_active BOOLEAN DEFAULT TRUE,
  is_premium BOOLEAN DEFAULT FALSE,               -- Affects visibility/pricing tiers
  sort_order INT DEFAULT 0,

  -- Timestamps
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_by INT,

  KEY idx_active (is_active),
  KEY idx_category (category),
  KEY idx_sort (sort_order),
  FOREIGN KEY (billing_template_id) REFERENCES billing_templates(id),
  FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**Sample Data (Pre-seed):**

| Package | Duration | Crew | Base Price | Billing | Checklist | Photos | Modifiers |
|---------|----------|------|-----------|---------|-----------|--------|-----------|
| Lawn Mowing Standard | 45 min | 1 | $65 | per_visit | lines_present, trim_edges | before, after | green_waste (+$25) |
| Lawn Mowing Large | 90 min | 2 | $120 | per_visit | lines_present, trim_edges | before, after | green_waste (+$35) |
| Hedge Trim (Light) | 60 min | 1 | $75 | per_visit | branches_cleared, debris_removed | before, after | None |
| Hedge Trim (Heavy) | 120 min | 2 | $150 | per_visit | branches_cleared, debris_removed, hauled | before, after, issue | None |
| Spring Cleanup | 120 min | 2 | $200 | per_visit | all_debris_removed, edges_clean | before, after | None |
| Snow Removal (Per Visit) | 30 min | 1 | $75 | per_visit | driveway_clear, entrance_clear | before, after | None |
| Snow Removal (Monthly Plan) | N/A | 1 | $450 | monthly_flat | per_visit_tracked | per_visit | None |
| Garden Maintenance | 60 min | 1 | $65 | per_visit | weeds_removed, mulch_refreshed | before, after | None |

---

### 1.2 Billing Templates Table

**Purpose:** Defines how a job translates into invoices. Smart defaults prevent billing mistakes.

**Migration:** `026_create_billing_templates.sql`

```sql
CREATE TABLE billing_templates (
  id INT AUTO_INCREMENT PRIMARY KEY,

  -- Identity
  template_name VARCHAR(100) NOT NULL,            -- Per Visit, Monthly Grouped, Prepay, etc.
  slug VARCHAR(50) UNIQUE NOT NULL,
  description TEXT,

  -- Billing behavior
  invoicing_mode ENUM(
    'per_visit',          -- One invoice per job completion
    'monthly_grouped',    -- Combine multiple jobs into one monthly invoice
    'monthly_flat',       -- Flat fee per month regardless of visit count
    'prepay'              -- Client pays before jobs begin
  ) DEFAULT 'per_visit',

  -- Invoice timing
  invoice_when VARCHAR(30) DEFAULT 'on_completion',  -- on_completion, end_of_month, weekly
  days_until_due INT DEFAULT 30,                 -- Invoice due date offset from issue date

  -- Auto-grouping rules (for monthly_grouped)
  group_by_property BOOLEAN DEFAULT TRUE,        -- Group invoices by property or across all?
  group_by_crew BOOLEAN DEFAULT FALSE,           -- Separate invoices per crew?
  include_notes BOOLEAN DEFAULT TRUE,            -- Include job notes on invoice?

  -- Recurring behavior
  applies_to_recurring VARCHAR(30),              -- all_jobs, recurring_only, one_time_only

  -- Tax & discounts
  tax_rate DECIMAL(5,2) DEFAULT 5.00,           -- Percent
  apply_discount_after_tax BOOLEAN DEFAULT FALSE,

  -- Client communication
  send_invoice_immediately BOOLEAN DEFAULT TRUE, -- vs. batch at month-end
  payment_terms TEXT,                            -- "Net 30", "Due upon receipt", etc.

  -- Service address handling
  show_service_address BOOLEAN DEFAULT TRUE,     -- Include where work was done?
  require_proof_before_invoice BOOLEAN DEFAULT FALSE, -- Block invoice until photos uploaded?

  -- State
  is_active BOOLEAN DEFAULT TRUE,
  is_default BOOLEAN DEFAULT FALSE,              -- Used as fallback if package doesn't specify
  sort_order INT DEFAULT 0,

  -- Timestamps
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  KEY idx_active (is_active),
  KEY idx_default (is_default)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**Default Templates:**

| Template | Mode | When | Notes |
|----------|------|------|-------|
| Per Visit | per_visit | on_completion | Default for most packages |
| Monthly Grouped | monthly_grouped | end_of_month | Good for recurring services on same property |
| Monthly Flat | monthly_flat | end_of_month | Good for strata/condo contracts |
| Seasonal Prepay | prepay | upfront | For spring cleanup, snow removal packages |

---

### 1.3 Job Proof of Work Table

**Purpose:** Tracks what must be completed before a job can be marked done and invoiced.

**Migration:** `027_create_job_proof_of_work.sql`

```sql
CREATE TABLE job_proof_of_work (
  id INT AUTO_INCREMENT PRIMARY KEY,
  job_id INT NOT NULL,

  -- Requirements (copied from service_package at job creation)
  required_checklist_items JSON,        -- ["lines_present", "trim_edges", "debris_removed"]
  required_photo_types JSON,            -- ["before", "after", "issue"]
  gps_enforcement VARCHAR(20),          -- required, optional, not_required

  -- What blocks job completion
  checklist_blocks_completion BOOLEAN DEFAULT FALSE,
  photos_block_completion BOOLEAN DEFAULT FALSE,
  gps_blocks_completion BOOLEAN DEFAULT FALSE,

  -- Actual completion status
  checklist_items_completed JSON,       -- { "lines_present": true, "trim_edges": false, ... }
  checklist_completed_at TIMESTAMP NULL,
  checklist_completed_by INT NULL,

  photos_uploaded JSON,                 -- { before: [photo_ids], after: [photo_ids], ... }
  photos_completed_at TIMESTAMP NULL,

  gps_arrival_lat DECIMAL(10, 8) NULL,
  gps_arrival_lng DECIMAL(11, 8) NULL,
  gps_departure_lat DECIMAL(10, 8) NULL,
  gps_departure_lng DECIMAL(11, 8) NULL,
  gps_confirmed_at TIMESTAMP NULL,

  -- Completion status
  is_complete BOOLEAN DEFAULT FALSE,
  completed_at TIMESTAMP NULL,
  completion_notes TEXT,

  -- Timestamps
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_job (job_id),
  FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE CASCADE,
  FOREIGN KEY (checklist_completed_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

### 1.4 Updates to `jobs` Table

**New Columns (Migration):**

```sql
ALTER TABLE jobs ADD COLUMN service_package_id INT AFTER service_type;
ALTER TABLE jobs ADD COLUMN billing_template_id INT AFTER estimated_amount;
ALTER TABLE jobs ADD COLUMN crew_size_required INT DEFAULT 1;
ALTER TABLE jobs ADD COLUMN actual_crew_count INT NULL;
ALTER TABLE jobs ADD COLUMN route_sequence INT NULL;  -- Position in day's route
ALTER TABLE jobs ADD CONSTRAINT fk_jobs_service_package
  FOREIGN KEY (service_package_id) REFERENCES service_packages(id);
ALTER TABLE jobs ADD CONSTRAINT fk_jobs_billing_template
  FOREIGN KEY (billing_template_id) REFERENCES billing_templates(id);
```

---

## Part 2: Job Creation UX Flow

### 2.1 Single-Screen Layout (Tabs instead of stepper)

Three logical zones, minimal scrolling, keyboard-first navigation.

```
┌─────────────────────────────────────────────────────────────┐
│  📋 CREATE JOB                      [← Back]  [? Help]       │
├─────────────────────────────────────────────────────────────┤
│                                                              │
│  Tabs: [BASICS] [SCHEDULE] [PROOF & BILLING] [REVIEW]      │
│                                                              │
├─────────────────────────────────────────────────────────────┤
│                                                              │
│  BASICS TAB                                                  │
│  ───────────────────────────────────────────────────────    │
│                                                              │
│  Client *                    Property *                      │
│  [Search or Quick Create ▼]  [Auto-selected]                │
│  • Search by name/phone                                     │
│  • +New Client                                              │
│                                                              │
│  Property Intelligence                                      │
│  Address: 123 Main St, Vancouver, BC  |  📍 Map             │
│  Sq Ft: 3,500  |  Known Issues: Steep slope, fence gate    │
│  Recent Services: Lawn mowing (Jan 12), Hedge trim (Dec 5)  │
│                                                              │
│  Service Package *                                           │
│  ⭕ Lawn Mowing Standard (45 min | 1 crew | $65)            │
│  ⭕ Lawn Mowing Large (90 min | 2 crew | $120)              │
│  ⭕ Hedge Trim Light (60 min | 1 crew | $75)                │
│  ⭕ Hedge Trim Heavy (120 min | 2 crew | $150)              │
│  ⭕ Spring Cleanup (120 min | 2 crew | $200)                │
│                                                              │
│  [Selected Package: Lawn Mowing Standard]                   │
│                                                              │
│  Modifiers (optional)                                       │
│  ☐ +$25 Green Waste Removal                                 │
│  ☐ +$15 Edging Touch-up                                     │
│  ☐ +$40 Aeration                                            │
│                                                              │
│                                                              │
│  [Previous] [Next: SCHEDULE →]                              │
│                                                              │
└─────────────────────────────────────────────────────────────┘
```

---

### 2.2 SCHEDULE Tab (Smart Defaults)

```
┌─────────────────────────────────────────────────────────────┐
│  SCHEDULE TAB                                                │
│  ───────────────────────────────────────────────────────    │
│                                                              │
│  Job Type *                                                  │
│  ◉ One-time (single visit)                                  │
│  ○ Recurring (multiple visits)                              │
│                                                              │
│  [If One-time]:                                              │
│  ─────────────                                              │
│  Date *           [Feb 15, 2026 ▼]                           │
│  Time Window *    [9:00 AM – 1:00 PM ▼]                      │
│                   ⓘ Based on route optimization             │
│                   ⓘ Service package duration: 45 min        │
│  Crew Assignment  [Sarah Johnson (Lead) ▼]                  │
│                   ⓘ 2 crew suggested (add 1 more)            │
│  Route Sequence   [Position 2 of 4 in region 1A]            │
│                                                              │
│  [If Recurring]:                                             │
│  ─────────────────                                          │
│  Frequency *      ◉ Weekly                                   │
│                   ○ Bi-weekly                                │
│                   ○ Monthly (1st or 3rd Thursday)            │
│                   ○ Custom                                   │
│  Days *           ☐ Mon ☑ Wed ☐ Fri                          │
│  Until            [Ongoing ▼] or [June 30, 2026]            │
│                   ⓘ Auto-generated job list shown below      │
│                                                              │
│  Generated Jobs (preview):                                   │
│  ├─ Wed, Feb 10 at 9–10 AM  (Sarah J.)                      │
│  ├─ Wed, Feb 17 at 9–10 AM  (Sarah J.)                      │
│  ├─ Wed, Feb 24 at 9–10 AM  (Sarah J.)                      │
│  └─ (continuing monthly...)                                 │
│                                                              │
│  ⚠️ Conflict Check:                                          │
│  "Crew unavailable Feb 10-12 (requested time off)"          │
│  [Suggest alternate date] [Adjust assignment]               │
│                                                              │
│  [← BASICS] [PROOF & BILLING →]                             │
│                                                              │
└─────────────────────────────────────────────────────────────┘
```

---

### 2.3 PROOF & BILLING Tab

```
┌─────────────────────────────────────────────────────────────┐
│  PROOF & BILLING TAB                                         │
│  ───────────────────────────────────────────────────────    │
│                                                              │
│  Billing Template *                                          │
│  ◉ Per Visit ($65 each)                                      │
│  ○ Monthly Grouped (sum across jobs)                         │
│  ○ Monthly Flat ($250/month for this property)              │
│                                                              │
│  Invoice Timing                                              │
│  Send invoice [on_completion ▼]                             │
│  Due date: [30 days after invoice]                           │
│                                                              │
│  Proof of Work Requirements                                  │
│  (from package: Lawn Mowing Standard)                       │
│                                                              │
│  Checklist Items (crew must complete before finish):        │
│  ☐ Lines present (lawn stripes)                              │
│  ☐ Trim edges                                                │
│  ☐ Debris removed                                            │
│  ⓘ Checklist blocks job completion: YES                      │
│                                                              │
│  Photos Required:                                            │
│  📷 Before (1 required)                                      │
│  📷 After (1 required)                                       │
│  ⓘ Photos block job completion: YES                          │
│                                                              │
│  GPS Tracking                                                │
│  [Optional] — crew can log arrival/departure (for tracking) │
│                                                              │
│  Price Preview                                               │
│  ├─ Base Service: $65                                        │
│  ├─ Modifiers: $0 (none selected)                            │
│  ├─ Tax (5%): $3.25                                          │
│  └─ Per-visit total: $68.25                                  │
│                                                              │
│  For recurring: $68.25 × 4 weeks = $273/month (est.)        │
│  Margin: 35% (based on package defaults)                    │
│                                                              │
│  [← SCHEDULE] [REVIEW →]                                    │
│                                                              │
└─────────────────────────────────────────────────────────────┘
```

---

### 2.4 REVIEW Tab (Final Confirmation)

```
┌─────────────────────────────────────────────────────────────┐
│  REVIEW TAB                                                  │
│  ───────────────────────────────────────────────────────    │
│                                                              │
│  ✓ Client: ABC Landscaping (Strata Manager Contact)         │
│  ✓ Property: 123 Main St, Vancouver, BC (3,500 sq ft)       │
│  ✓ Service: Lawn Mowing Standard (45 min, 1 crew)           │
│  ✓ Schedule: Wednesday, Feb 10, 2026 @ 9:00 AM              │
│  ✓ Billing: Per Visit ($68.25 incl. tax)                    │
│  ✓ Proof: Checklist + 2 photos required                     │
│                                                              │
│  Crew Assignment                                             │
│  ├─ Lead: Sarah Johnson (assigned now)                      │
│  └─ Current load: 6 jobs scheduled (capacity OK)            │
│                                                              │
│  Conflicts Check                                             │
│  ✓ No scheduling conflicts detected                          │
│  ✓ Crew availability confirmed                              │
│  ✓ Property access verified                                 │
│  ✓ Billing template compatible                              │
│                                                              │
│  Next Steps                                                  │
│  1. Job created (JOB-2026-0157)                              │
│  2. Crew notified via app                                    │
│  3. Client emailed job confirmation                          │
│  4. Calendar updated                                         │
│                                                              │
│  [← PROOF] [CREATE JOB] [+ Create Another]                  │
│                                                              │
└─────────────────────────────────────────────────────────────┘
```

---

## Part 3: Smart Defaults & Guardrails

### 3.1 Baseline Smart Defaults

| Input | Default | Logic | Guardrail |
|-------|---------|-------|-----------|
| **Property** | Auto-select if 1 | Client has only 1 property → select it | If >1, show list (prevent wrong property) |
| **Service Package** | Most recent | Last service done at this property | Show 3 most common alternatives |
| **Modifiers** | None, optional | User selects | Show "last used" suggestions |
| **Frequency** | Per service type | Mowing=weekly, Cleanup=seasonal | Cannot be changed if recurring already exists |
| **Day of Week** | Based on crew location | Crew currently in same zone | Show crew route optimization |
| **Time Window** | Based on package duration | 45 min service → 1-hour slot | Show crew's next available slot |
| **Crew Size** | Per package | Lawn mowing = 1, Cleanup = 2 | Can override if crew exceeds capacity |
| **Crew Assignment** | Crew nearest property | Haversine distance to property | Show all crew with travel time |
| **Billing Template** | Per package default | Lawn mowing = per_visit | Show estimated monthly cost |
| **Checklist** | Service package defines | "lines" + "edges" for mowing | Cannot be changed (package-locked) |

---

### 3.2 Guardrails (Not Warnings)

Guardrails **prevent mistakes automatically** rather than showing warnings.

| Scenario | Guardrail | Action |
|----------|-----------|--------|
| **Scheduling crew on unavailable date** | Disable that date picker option | Show only available dates |
| **Assigning crew with no capacity** | Show crew already at capacity | Suggest next available crew or date |
| **Selecting incompatible billing template** | Disable if recurring + per-visit | Auto-switch to monthly_grouped |
| **Strata client + per-visit billing** | Suggest monthly_flat instead | Auto-select monthly templates for companies |
| **Choosing time that conflicts with route** | Adjust time window to fit route | Show "optimal time: 2–3 PM" |
| **Missing required checklist item** | Block job completion in app | Show incomplete items in red (crew app) |
| **No proof photos for billing** | Block invoice generation | Show "2 photos needed before invoice" |
| **Crew exceeds 12-hour day** | Disable new job scheduling | Show "crew limit reached, pick another day" |

---

### 3.3 Smart Decisions

**Strata/Company Clients:**
- Default to monthly billing templates
- Show multiple properties in dropdown
- Suggest recurring contracts
- Include property manager email on invoices

**Residential Clients:**
- Default to per-visit billing
- Single property assumed
- Show optional recurring setup
- Include seasonal service upsells

**Crew Routing:**
- If property is <5km from crew's last job, suggest same-day cluster
- If property is >15km away, adjust ETA and mark as "travel time included"
- Show crew's current zone (Route A, B, C) and warn if different zone

**Pricing & Margin:**
- If selected package margins below 25%, highlight yellow
- If below 15%, show warning + suggest upsell modifiers
- If property >5000 sq ft, suggest "Large" package instead of "Standard"

---

## Part 4: Implementation Architecture

### 4.1 PHP Backend Functions

**Core Functions (in `functions.php`):**

```php
/**
 * Get all service packages, optionally filtered
 * @param string $category (optional) mowing, trimming, cleanup, seasonal
 * @param bool $activeOnly (default: true)
 * @return array service packages with merged billing template info
 */
function getServicePackages($category = null, $activeOnly = true) { ... }

/**
 * Get single service package with full details
 * @param int $packageId
 * @return array package, billing template, modifiers, checklist, photos
 */
function getServicePackageDetails($packageId) { ... }

/**
 * Auto-select best crew for job based on location, capacity, skills
 * @param int $propertyId
 * @param DateTime $scheduledDate
 * @param int $durationMinutes
 * @param int $crewSizeRequired
 * @return array crew suggestion with ETA, current load, conflicts
 */
function suggestCrewForJob($propertyId, $scheduledDate, $durationMinutes, $crewSizeRequired) { ... }

/**
 * Check if date/time slot is available for crew
 * @param int $crewId
 * @param DateTime $startTime
 * @param int $durationMinutes
 * @return array [is_available: bool, conflicts: [], suggested_times: []]
 */
function checkCrewAvailability($crewId, $startTime, $durationMinutes) { ... }

/**
 * Calculate smart time window based on crew location & route
 * @param int $propertyId
 * @param int $crewId
 * @param int $durationMinutes (service duration)
 * @return array [earliest: time, latest: time, optimal: time, reason: string]
 */
function calculateOptimalTimeWindow($propertyId, $crewId, $durationMinutes) { ... }

/**
 * Create job with smart defaults applied
 * @param array $jobData [client_id, property_id, service_package_id, job_type, ...]
 * @param int $userId (who created the job)
 * @return array [job: {...}, created_successfully: bool, errors: []]
 */
function createJobWithDefaults($jobData, $userId) { ... }

/**
 * Create recurring job series with auto-generated instances
 * @param array $jobData + recurrence_pattern, recurrence_day_of_week, recurrence_end_date
 * @param int $userId
 * @return array [parent_job_id: int, instances_created: int, first_jobs: []]
 */
function createRecurringJobSeries($jobData, $userId) { ... }

/**
 * Generate individual job instances for a recurring parent
 * @param int $parentJobId
 * @param DateTime $startDate
 * @param DateTime $endDate
 * @return int count of jobs created
 */
function generateRecurringJobInstances($parentJobId, $startDate, $endDate) { ... }

/**
 * Get recent jobs on property for "last used" suggestions
 * @param int $propertyId
 * @param int $limit
 * @return array [job_id, service_package_id, service_name, price, date]
 */
function getRecentJobsOnProperty($propertyId, $limit = 3) { ... }

/**
 * Calculate crew available hours for date range
 * @param int $crewId
 * @param DateTime $date
 * @return int available minutes on that day
 */
function getCrewAvailableCapacity($crewId, $date) { ... }

/**
 * Detect scheduling conflicts for crew + date + time
 * @param int $crewId
 * @param DateTime $startTime
 * @param int $durationMinutes
 * @return array conflicts, overlaps, tight clusters
 */
function detectSchedulingConflicts($crewId, $startTime, $durationMinutes) { ... }

/**
 * Apply smart guardrails before job save
 * @param array $jobData
 * @param int $userId
 * @return array [is_valid: bool, errors: [], warnings: [], suggestions: {}]
 */
function validateJobCreationGuardrails($jobData, $userId) { ... }

/**
 * Suggest modifiers based on property characteristics
 * @param int $propertyId
 * @param int $servicePackageId
 * @return array modifiers with reason (large_property, recent_growth, etc.)
 */
function suggestModifiers($propertyId, $servicePackageId) { ... }

/**
 * Create proof of work requirements for job
 * @param int $jobId
 * @param int $servicePackageId
 * @return bool success
 */
function createJobProofOfWork($jobId, $servicePackageId) { ... }

/**
 * Check if job is eligible for invoicing (proof of work complete)
 * @param int $jobId
 * @return array [can_invoice: bool, missing_requirements: [], photos_count: int]
 */
function canInvoiceJob($jobId) { ... }
```

**API Endpoints (in `api/job-creation.php`):**

```php
// POST /api/job-creation.php?action=get_service_packages
// GET list of all packages, optionally filtered by category

// POST /api/job-creation.php?action=get_package_details
// GET single package with all details (pricing, checklist, etc.)

// POST /api/job-creation.php?action=suggest_crew
// POST { property_id, date, duration }
// GET crew suggestion with ETA, conflicts, alternatives

// POST /api/job-creation.php?action=check_availability
// POST { crew_id, start_time, duration }
// GET availability status, conflicts, alternatives

// POST /api/job-creation.php?action=calculate_time_window
// POST { property_id, crew_id, duration }
// GET optimal time, earliest, latest, reason

// POST /api/job-creation.php?action=validate_job
// POST { full job data }
// GET validation results, guardrails, suggestions

// POST /api/job-creation.php?action=create_job
// POST { full job data }
// GET created job_id, next steps, crew notification

// POST /api/job-creation.php?action=recent_jobs
// POST { property_id }
// GET last 3 services at property

// POST /api/job-creation.php?action=suggest_modifiers
// POST { property_id, service_package_id }
// GET suggested add-ons with reasons
```

---

### 4.2 Frontend State Model (JavaScript/Vue)

```javascript
// Vuedata model for job creation
{
  // BASICS
  client: {
    id: null,
    name: '',
    phone: '',
    isStrata: false,  // Affects defaults
    properties: [],   // Available properties
  },
  property: {
    id: null,
    address: '',
    sqft: 0,
    knownIssues: [],
    recentServices: [],
    accessNotes: '',
  },
  servicePackage: {
    id: null,
    name: '',
    duration: 0,       // minutes
    basePrice: 0,
    crewSize: 1,
    icon: '',
    modifiers: [],     // Available for this package
    selectedModifiers: [],
  },

  // SCHEDULE
  jobType: 'one_time',  // or 'recurring'
  scheduledDate: null,
  timeWindow: {
    start: null,
    end: null,
    optimal: null,
    reason: '',
    alternatives: [],
  },
  recurrence: {
    frequency: 'weekly',  // weekly, biweekly, monthly
    daysOfWeek: ['Wednesday'],
    until: null,         // Date or 'ongoing'
    generatedInstances: [],  // Preview of jobs that will be created
  },
  crew: {
    leadId: null,
    leadName: '',
    assistants: [],
    suggestedBasedOn: 'location',  // reason for suggestion
    currentLoad: 0,     // jobs already assigned
    conflicts: [],      // time conflicts on that date
  },

  // PROOF & BILLING
  billingTemplate: {
    id: null,
    name: '',
    invoicingMode: 'per_visit',  // per_visit, monthly_grouped, monthly_flat, prepay
    invoiceWhen: 'on_completion',
  },
  proofOfWork: {
    requiredChecklist: [],
    requiredPhotos: [],
    gpsRequired: false,
    checklistBlocksCompletion: false,
    photosBlockCompletion: false,
  },
  pricing: {
    basePrice: 0,
    modifiersTotal: 0,
    subtotal: 0,
    taxRate: 0.05,
    taxAmount: 0,
    total: 0,
    monthlyEstimate: 0,  // For recurring
    marginPercent: 35,
  },

  // VALIDATION & GUARDRAILS
  validation: {
    isValid: false,
    errors: [],
    warnings: [],
    suggestions: {},
  },

  // UI STATE
  activeTab: 'basics',  // basics, schedule, proof, review
  isLoading: false,
  isSaving: false,
  showConflictResolution: false,
}
```

---

### 4.3 Keyboard Shortcuts (Speed Feature)

```
Ctrl+Enter          Save job (any tab)
Ctrl+Shift+J        New job (from anywhere)
Tab                 Next field / Next tab
Shift+Tab           Previous field / Previous tab
Escape              Cancel, go back to jobs list
/                   Open quick search (client or property)
c                   New client (inline quick create)
p                   Select property (show dropdown)
+                   Add modifier
Enter (on date)     Confirm selection
↑↓ (date picker)    Previous/next day (week view)
Alt+S               Toggle between one-time and recurring
```

---

## Part 5: Example Scenarios

### Scenario 1: Repeat Lawn Mowing (30 seconds)

**Starting state:** Admin viewing job list
**Goal:** Schedule lawn mowing for existing property (same as last month)

```
Step 1: Press Ctrl+Shift+J to open new job
Step 2: Type "ABC Landscaping" (autocomplete shows)
Step 3: Press Enter (only property selected automatically)
Step 4: Press Down arrow (Lawn Mowing Standard pre-selected, same as last time)
Step 5: Press Tab → "Recurring" selected
Step 6: Confirm date (next Wednesday auto-filled based on crew location)
Step 7: Confirm crew (Sarah Johnson, same as before)
Step 8: Press Ctrl+Enter → Job created, crew notified

Timeline: ~25 seconds
Result: 4 recurring jobs scheduled for Feb/March, billing template locked to per-visit
```

---

### Scenario 2: New Strata Job with Complications (2 minutes)

**Starting state:** New quote accepted from strata manager
**Goal:** Create monthly maintenance contract with smart defaults

```
Step 1: Open new job dialog
Step 2: Search for "Granview Strata" (autocomplete)
Step 3: Shows 3 properties in complex (select "Building A - 456 Oak St")
Step 4: Package dropdown shows suggested ("Garden Maintenance" or "Monthly Cleanup")
Step 5: Select "Garden Maintenance + Aeration" modifier
Step 6: Press Tab → Recurring selected
Step 7: Frequency auto-set to "Monthly" (strata client default)
Step 8: Billing template auto-switched to "Monthly Grouped" (from "Per Visit")
Step 9: Crew suggestion shows "Michael T." with note "works property Fridays"
Step 10: Check availability → Suggests "1st Friday of month" pattern
Step 11: Generate preview shows 12 jobs through Dec 2026
Step 12: Guardrail warning: "Crew at capacity on 1st Friday. Use 2nd Friday instead"
Step 13: Accept auto-correction
Step 14: Review tab shows all details
Step 15: Create job

Timeline: ~90 seconds (2 decisions: modifier + crew availability)
Result: Parent job created + 12 recurring instances, invoice batched monthly, proof requirements set
```

---

### Scenario 3: Same-Day Crew Cluster (1 minute)

**Starting state:** Crew in field, manager gets call for emergency hedge trim 2km away
**Goal:** Add job to crew's route for today

```
Step 1: New job → Client: "Smith Residence"
Step 2: Property auto-populates (only one on file)
Step 3: Service: "Hedge Trim Light" (manager selects)
Step 4: Schedule: Today
Step 5: Time window shows: "Crew (Sarah) is 2km away, can do 2–3 PM"
Step 6: Accept 2 PM slot (3-hour buffer after current job)
Step 7: Billing: Defaults to "Per Visit" (good for one-time)
Step 8: Checklist preview: "branches_cleared, debris_removed"
Step 9: Photos: "before + after"
Step 10: Create job
Step 11: Push notification sent to Sarah's phone with map & checklist

Timeline: ~60 seconds
Result: Job added to Sarah's current route, ETA updated, client SMS sent with time window
```

---

## Part 6: Database Migrations (Ready to Deploy)

### Migration 025: Service Packages

```sql
-- 025_create_service_packages.sql
CREATE TABLE service_packages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  package_name VARCHAR(100) NOT NULL,
  slug VARCHAR(100) UNIQUE NOT NULL,
  description TEXT,
  icon_name VARCHAR(50),
  category VARCHAR(50),
  default_duration_minutes INT DEFAULT 60,
  default_crew_size INT DEFAULT 1,
  default_visit_frequency VARCHAR(30),
  base_price DECIMAL(10,2) NOT NULL,
  unit_type VARCHAR(20) DEFAULT 'visit',
  billing_template_id INT,
  default_billing_interval VARCHAR(30),
  margin_target_percent INT DEFAULT 35,
  checklist_items JSON,
  photo_types_required JSON,
  gps_enforcement VARCHAR(20) DEFAULT 'optional',
  photos_block_completion BOOLEAN DEFAULT FALSE,
  checklist_blocks_completion BOOLEAN DEFAULT FALSE,
  seasonal_available VARCHAR(100),
  estimated_seasonal_recurrence VARCHAR(30),
  modifiers JSON,
  service_type VARCHAR(50),
  is_active BOOLEAN DEFAULT TRUE,
  is_premium BOOLEAN DEFAULT FALSE,
  sort_order INT DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_by INT,
  KEY idx_active (is_active),
  KEY idx_category (category),
  KEY idx_sort (sort_order),
  FOREIGN KEY (billing_template_id) REFERENCES billing_templates(id),
  FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed default packages
INSERT INTO service_packages (package_name, slug, description, icon_name, category, default_duration_minutes, default_crew_size, base_price, unit_type, service_type, checklist_items, photo_types_required, is_active, sort_order) VALUES
('Lawn Mowing Standard', 'lawn-mowing-standard', '45-minute residential lawn mowing', 'leaf', 'mowing', 45, 1, 65.00, 'visit', 'lawn_care', '["lines_present", "trim_edges", "debris_removed"]', '["before", "after"]', TRUE, 1),
('Lawn Mowing Large', 'lawn-mowing-large', '90-minute large property mowing', 'leaf', 'mowing', 90, 2, 120.00, 'visit', 'lawn_care', '["lines_present", "trim_edges", "debris_removed"]', '["before", "after"]', TRUE, 2),
('Hedge Trim Light', 'hedge-trim-light', 'Light hedge trimming (up to 1 hour)', 'scissors', 'trimming', 60, 1, 75.00, 'visit', 'hedge_trimming', '["branches_cleared", "debris_removed"]', '["before", "after"]', TRUE, 3),
('Hedge Trim Heavy', 'hedge-trim-heavy', 'Heavy hedge trimming with hauling', 'scissors', 'trimming', 120, 2, 150.00, 'visit', 'hedge_trimming', '["branches_cleared", "debris_removed", "hauled"]', '["before", "after", "issue"]', TRUE, 4),
('Spring Cleanup', 'spring-cleanup', 'Seasonal spring property cleanup', 'wand-2', 'cleanup', 120, 2, 200.00, 'visit', 'seasonal_cleanup', '["all_debris_removed", "edges_clean", "beds_prepared"]', '["before", "after"]', TRUE, 5),
('Garden Maintenance', 'garden-maintenance', 'Weekly garden weeding and mulch refresh', 'clover', 'maintenance', 60, 1, 65.00, 'visit', 'garden_maintenance', '["weeds_removed", "mulch_refreshed", "edges_clean"]', '["before", "after"]', TRUE, 6),
('Snow Removal Per Visit', 'snow-removal-per-visit', 'Single snow removal visit', 'cloud-snow', 'seasonal', 30, 1, 75.00, 'visit', 'snow_removal', '["driveway_clear", "entrance_clear"]', '["before", "after"]', TRUE, 7),
('Snow Removal Seasonal', 'snow-removal-seasonal', 'Monthly snow removal plan (Nov-Feb)', 'cloud-snow', 'seasonal', 0, 1, 450.00, 'month', 'snow_removal', '[]', '[]', TRUE, 8);
```

### Migration 026: Billing Templates

```sql
-- 026_create_billing_templates.sql
CREATE TABLE billing_templates (
  id INT AUTO_INCREMENT PRIMARY KEY,
  template_name VARCHAR(100) NOT NULL,
  slug VARCHAR(50) UNIQUE NOT NULL,
  description TEXT,
  invoicing_mode ENUM('per_visit', 'monthly_grouped', 'monthly_flat', 'prepay') DEFAULT 'per_visit',
  invoice_when VARCHAR(30) DEFAULT 'on_completion',
  days_until_due INT DEFAULT 30,
  group_by_property BOOLEAN DEFAULT TRUE,
  group_by_crew BOOLEAN DEFAULT FALSE,
  include_notes BOOLEAN DEFAULT TRUE,
  applies_to_recurring VARCHAR(30),
  tax_rate DECIMAL(5,2) DEFAULT 5.00,
  apply_discount_after_tax BOOLEAN DEFAULT FALSE,
  send_invoice_immediately BOOLEAN DEFAULT TRUE,
  payment_terms TEXT,
  show_service_address BOOLEAN DEFAULT TRUE,
  require_proof_before_invoice BOOLEAN DEFAULT FALSE,
  is_active BOOLEAN DEFAULT TRUE,
  is_default BOOLEAN DEFAULT FALSE,
  sort_order INT DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_active (is_active),
  KEY idx_default (is_default)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed default templates
INSERT INTO billing_templates (template_name, slug, description, invoicing_mode, invoice_when, payment_terms, is_active, sort_order) VALUES
('Per Visit', 'per-visit', 'One invoice per completed job', 'per_visit', 'on_completion', 'Due upon receipt', TRUE, 1),
('Monthly Grouped', 'monthly-grouped', 'Combine multiple jobs into monthly invoices', 'monthly_grouped', 'end_of_month', 'Net 30', TRUE, 2),
('Monthly Flat', 'monthly-flat', 'Flat monthly fee for service', 'monthly_flat', 'end_of_month', 'Net 30', TRUE, 3),
('Seasonal Prepay', 'seasonal-prepay', 'Prepay for seasonal service (snow, spring cleanup)', 'prepay', 'upfront', 'Due before service', TRUE, 4);
```

### Migration 027: Job Proof of Work

```sql
-- 027_create_job_proof_of_work.sql
CREATE TABLE job_proof_of_work (
  id INT AUTO_INCREMENT PRIMARY KEY,
  job_id INT NOT NULL,
  required_checklist_items JSON,
  required_photo_types JSON,
  gps_enforcement VARCHAR(20),
  checklist_blocks_completion BOOLEAN DEFAULT FALSE,
  photos_block_completion BOOLEAN DEFAULT FALSE,
  gps_blocks_completion BOOLEAN DEFAULT FALSE,
  checklist_items_completed JSON,
  checklist_completed_at TIMESTAMP NULL,
  checklist_completed_by INT NULL,
  photos_uploaded JSON,
  photos_completed_at TIMESTAMP NULL,
  gps_arrival_lat DECIMAL(10, 8) NULL,
  gps_arrival_lng DECIMAL(11, 8) NULL,
  gps_departure_lat DECIMAL(10, 8) NULL,
  gps_departure_lng DECIMAL(11, 8) NULL,
  gps_confirmed_at TIMESTAMP NULL,
  is_complete BOOLEAN DEFAULT FALSE,
  completed_at TIMESTAMP NULL,
  completion_notes TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_job (job_id),
  FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE CASCADE,
  FOREIGN KEY (checklist_completed_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### Migration 028: Update Jobs Table

```sql
-- 028_update_jobs_for_packages.sql
ALTER TABLE jobs ADD COLUMN service_package_id INT AFTER service_type;
ALTER TABLE jobs ADD COLUMN billing_template_id INT AFTER estimated_amount;
ALTER TABLE jobs ADD COLUMN crew_size_required INT DEFAULT 1 AFTER billing_template_id;
ALTER TABLE jobs ADD COLUMN actual_crew_count INT NULL;
ALTER TABLE jobs ADD COLUMN route_sequence INT NULL AFTER actual_crew_count;
ALTER TABLE jobs ADD CONSTRAINT fk_jobs_service_package FOREIGN KEY (service_package_id) REFERENCES service_packages(id);
ALTER TABLE jobs ADD CONSTRAINT fk_jobs_billing_template FOREIGN KEY (billing_template_id) REFERENCES billing_templates(id);
ALTER TABLE jobs ADD KEY idx_service_package (service_package_id);
ALTER TABLE jobs ADD KEY idx_billing_template (billing_template_id);
```

---

## Part 7: Rollout Plan

### Phase 1 (Week 1): Foundation
- ✅ Create all database tables (migrations 025-028)
- ✅ Seed service packages & billing templates
- ✅ Implement core PHP functions
- ✅ Create API endpoints

### Phase 2 (Week 2): UI & Workflow
- Build single-screen job creation UI (tabs)
- Implement smart defaults logic
- Implement guardrails validation
- Create crew suggestion algorithm

### Phase 3 (Week 3): Proof of Work
- Build proof of work tracking (checklist + photos)
- Integrate with job completion flow
- Block invoice generation until proof complete

### Phase 4 (Week 4): Advanced Features
- Recurring job automation
- Crew routing optimization
- Keyboard shortcuts and speed features
- Mobile-optimized crew app integration

### Phase 5 (Post): Analytics & Optimization
- Track job creation time metrics
- Analyze crew utilization
- Optimize default suggestions based on real usage
- A/B test smart defaults

---

## Part 8: Success Metrics

| Metric | Target | Current | Improvement |
|--------|--------|---------|------------|
| Job creation time | <30 sec (repeat) | 2–3 min | 4–6x faster |
| Service package selection | 1 click | 3–4 dropdowns | Instant with defaults |
| Billing errors | 0% | ~5% (manual entry) | 100% prevented |
| Schedule conflicts | 0 detected | 10–15% of jobs | Guardrails prevent |
| Crew capacity overload | 0 jobs assigned | 2–3 per week | All validated |
| Job completion time (crew) | <90 min overhead | 120+ min | Checklist clarity |
| Invoice generation | Automatic | Manual | 100% automated |

---

## Conclusion

This system transforms job creation from a form-filling exercise into a **confirmation workflow**. By making smart defaults ubiquitous and guardrails automatic, we enable:

- **Admins:** 30-second job creation for repeat clients
- **Crews:** Clear expectations (checklist + photos) before leaving site
- **Finance:** Zero manual invoicing errors, automatic billing
- **Customers:** Predictable service, proof of work, immediate invoicing

The design prioritizes **operational reality over UI polish** — it's built for busy field-service teams, not form enthusiasts.

---

**Status:** Ready for implementation
**Owner:** [Your team]
**Next Step:** Create database migrations and seed data

