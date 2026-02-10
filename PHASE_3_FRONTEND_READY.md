# Phase 3: Frontend UI — Ready to Begin

**Status:** Backend Complete ✅ — Ready for Frontend Development
**Estimated Duration:** 1 week
**Technology:** Vue.js 2.6 + Bootstrap 4 + vanilla JavaScript

---

## What Phase 3 Will Deliver

### 1. New Job Creation Page (`/crm/jobs/create-nextgen.php`)

A single, comprehensive job creation interface with 4 tabs:

```
┌─────────────────────────────────────────────┐
│  📋 CREATE JOB                              │
├─────────────────────────────────────────────┤
│  [BASICS] [SCHEDULE] [PROOF] [REVIEW]      │
├─────────────────────────────────────────────┤
│                                             │
│  (Tab content here)                         │
│                                             │
└─────────────────────────────────────────────┘
```

### 2. Complete Keyboard Navigation

- **Ctrl+Shift+J** — Open new job (from anywhere)
- **Tab** — Next field
- **Shift+Tab** — Previous field
- **Enter** — Confirm selection (date, crew, etc.)
- **Escape** — Cancel & close
- **Alt+S** — Toggle one-time/recurring

### 3. Real-Time Smart Defaults

Fields auto-populate as user makes selections:
- Select package → Duration, price, crew size auto-fill
- Select property → Address, sqft, recent jobs shown
- Select crew → Check availability, show conflicts
- Select date → Suggest optimal time window

### 4. Live Validation & Guardrails

- ✅ Red highlight on errors (can't submit)
- ⚠️ Yellow highlight on warnings (can submit with note)
- 💡 Suggestions shown in blue (optional improvements)
- 🟢 Green checkmarks for valid fields

### 5. Mobile-Responsive Design

- Works on desktop (full width)
- Works on tablet (adjusted spacing)
- Works on mobile (stacked layout, touch-friendly buttons)

---

## Architecture

### Vue Component Structure

```
create-nextgen.php
├── <template>
│   ├── Tab Navigation (4 buttons)
│   ├── Tab Content (4 panels)
│   │   ├── Basics Tab
│   │   │   ├── Client Selector
│   │   │   ├── Property Selector
│   │   │   ├── Service Package Selector
│   │   │   └── Modifiers Checkboxes
│   │   ├── Schedule Tab
│   │   │   ├── Job Type (One-time / Recurring)
│   │   │   ├── Date Picker
│   │   │   ├── Time Selector
│   │   │   ├── Crew Selector
│   │   │   └── Recurrence Options
│   │   ├── Proof Tab
│   │   │   ├── Billing Template Display
│   │   │   ├── Checklist Items List
│   │   │   ├── Photo Types List
│   │   │   └── GPS Requirements
│   │   └── Review Tab
│   │       ├── Summary of all selections
│   │       ├── Conflicts Check
│   │       └── Create Job Button
│   └── Footer (Previous / Next buttons)
├── <script>
│   ├── Data: job state object
│   ├── Computed: calculated fields
│   ├── Methods: API calls, validation
│   └── Watch: auto-save, smart defaults
└── <style>
    └── Tab styling, form layouts, error colors
```

### State Management (Vue Data)

```javascript
data: {
  // BASICS
  client: { id, name, phone, properties },
  property: { id, address, sqft },
  servicePackage: { id, name, duration, price, modifiers },
  selectedModifiers: [],

  // SCHEDULE
  jobType: 'one_time',
  scheduledDate: null,
  timeWindow: { start, end, optimal },
  crew: { id, name, distance },
  recurrence: { frequency, days, until },

  // PROOF & BILLING
  billingTemplate: { id, name, mode },
  proofRequirements: { checklist, photos, gps },
  pricing: { base, modifiers, subtotal, tax, total },

  // UI STATE
  activeTab: 'basics',
  isLoading: false,
  errors: [],
  warnings: [],
  suggestions: {},
}
```

### API Integration

Phase 3 uses all Phase 2 APIs:

```javascript
// Get service packages
GET /api/job-creation.php?action=get_service_packages

// Suggest crew when date selected
POST /api/job-creation.php?action=suggest_crew
  { property_id, scheduled_date, duration_minutes, crew_size_required }

// Check crew availability when time selected
POST /api/job-creation.php?action=check_availability
  { crew_id, start_time, duration_minutes }

// Calculate optimal time window
POST /api/job-creation.php?action=calculate_time_window
  { property_id, crew_id, duration_minutes }

// Validate entire job before creating
POST /api/job-creation.php?action=validate_job
  { ... full job data ... }

// Create job (final submission)
POST /api/job-creation.php?action=create_job
  { ... full job data ... }

// Get recent jobs for "last used" suggestions
GET /api/job-creation.php?action=recent_jobs?property_id=X

// Suggest modifiers
POST /api/job-creation.php?action=suggest_modifiers
  { property_id, service_package_id }
```

---

## Tab Specifications

### Tab 1: BASICS (Initial Data Selection)

**Fields:**
- **Client** [Text input with autocomplete dropdown]
  - Shows: name, phone, is_strata flag
  - Quick action: "+ New Client"
  - Event: On select → Load client's properties

- **Property** [Dropdown, auto-selected if only 1]
  - Shows: address, sqft, recent jobs
  - Event: On select → Get recent jobs, suggest modifiers

- **Service Package** [Radio button group or dropdown]
  - Shows: name, duration, crew, price, icon
  - Sub-section: Modifiers [Checkboxes]
    - Each modifier shows cost (+$25, +$35, etc.)
  - Event: On select → Apply smart defaults (price, crew size, duration)

**Behavior:**
- Client selection is required
- Property auto-selected if only 1, required if >1
- Service package required
- Modifiers optional

**Smart Defaults Applied:**
- Package duration → Schedule tab
- Package crew size → Schedule tab
- Package base price → Proof tab
- Package billing template → Proof tab

---

### Tab 2: SCHEDULE (Date, Time, Crew, Recurrence)

**Job Type Selection:**
- **One-Time Job** [Radio button]
  - Shows: Date + Time fields
  - Time shown as window (9 AM - 1 PM)

- **Recurring Job** [Radio button]
  - Shows: Frequency + Day + End Date
  - Preview: Shows first 4 instances

**One-Time Fields:**
- **Date** [Date picker]
  - Only shows available dates (crew-aware)
  - Event: On select → Suggest crew

- **Time Window** [Read-only display]
  - Shows: "9:00 AM - 1:00 PM"
  - Shows reason: "Based on crew location"
  - Can override with dropdown

- **Crew** [Dropdown showing suggestions]
  - Shows: Name, distance, current load, conflicts
  - Can select different crew
  - Event: On select → Check availability, show conflicts

**Recurring Fields:**
- **Frequency** [Dropdown: Weekly, Bi-weekly, Monthly, Custom]
- **Day of Week** [Checkboxes: Mon-Sun]
- **Until** [Date picker or "Ongoing"]
- **Preview** [Read-only list of generated instances]

**Validation:**
- Date must be in future
- Crew must have capacity
- Time window must be clear (no conflicts)
- Recurring must have valid pattern

---

### Tab 3: PROOF & BILLING (Requirements & Invoicing)

**Billing Template** [Read-only display]
- Shows: Template name, mode, payment terms
- Locked to service package
- Cannot change

**Proof of Work Requirements** [Read-only display]
- **Checklist Items:**
  - Shows: "✓ Lines present", "✓ Trim edges", etc.
  - Note: "Crew must complete before marking job done"

- **Photos Required:**
  - Shows: "Before (1 required)", "After (1 required)"
  - Note: "Photos required before invoice"

- **GPS Tracking:**
  - Shows: "Optional" or "Required"

**Price Preview** [Summary section]
- Base Service: $65
- Modifiers: +$25 (Green Waste)
- Subtotal: $90
- Tax (5%): $4.50
- **Total: $94.50**
- Monthly Estimate (if recurring): $378/month

---

### Tab 4: REVIEW (Final Confirmation)

**Full Summary:**
```
✓ Client: ABC Landscaping (Strata)
✓ Property: 123 Main St, Vancouver, BC (3,500 sqft)
✓ Service: Lawn Mowing Standard (45 min, 1 crew)
✓ Schedule: Wed, Feb 15, 2026 @ 9:00 AM
✓ Billing: Per-visit ($94.50 including tax)
✓ Proof: Checklist + 2 photos required
```

**Conflicts Check:**
- ✓ No scheduling conflicts detected
- ✓ Crew availability confirmed
- ✓ Property access verified
- ✓ Billing compatible

**Create Job Button:**
- Large, prominent button
- Text: "CREATE JOB" or "CREATE SERIES (12 instances)"
- Disabled until all validations pass

**Success State:**
```
✅ Job Created Successfully!
   JOB-2026-0157 — Lawn Mowing Standard

Next Steps:
1. Crew notification sent
2. Calendar updated
3. Proof requirements set
4. Customer email sent

[View Job] [Create Another]
```

---

## CSS Changes

Add to `/public/crm/css/mowology-brand.css`:

```css
/* Job Creation Form Styles */
.mw-job-create-container { ... }
.mw-job-tabs { ... }
.mw-job-tab-panel { ... }
.mw-job-field { ... }
.mw-job-field-error { ... }
.mw-job-field-warning { ... }
.mw-job-field-success { ... }

/* Package Selector Grid */
.mw-package-grid { ... }
.mw-package-card { ... }
.mw-package-card.active { ... }

/* Time Window Display */
.mw-time-window { ... }
.mw-time-window-reason { ... }

/* Conflicts Warning */
.mw-conflicts-warning { ... }
.mw-conflict-item { ... }

/* Price Preview */
.mw-price-preview { ... }
.mw-price-row { ... }
.mw-price-total { ... }

/* Review Summary */
.mw-review-summary { ... }
.mw-review-item { ... }
```

---

## JavaScript Features

### Auto-Complete on Client Input

```javascript
// When user types in client field
onClientInput(text) {
  // Debounce (300ms)
  // GET /crm/api/search?type=company&q=text
  // Show dropdown with matches
  // On select: Load client properties
}
```

### Real-Time Smart Defaults

```javascript
// When service package selected
onPackageSelect(packageId) {
  // Get package details
  // Apply defaults:
  this.job.estimated_duration_minutes = package.default_duration_minutes
  this.job.crew_size_required = package.default_crew_size
  this.pricing.basePrice = package.base_price
  this.billingTemplate = package.billing_template_id
  // Show modifiers
  this.availableModifiers = package.modifiers
}

// When date selected
onDateSelect(date) {
  // POST /api/job-creation.php?action=suggest_crew
  // Get crew suggestion
  // POST /api/job-creation.php?action=calculate_time_window
  // Get optimal time
  // Apply defaults:
  this.job.assigned_to = suggestion.crew_id
  this.job.scheduled_time_start = optimal.optimal_time
}

// When crew selected
onCrewSelect(crewId) {
  // POST /api/job-creation.php?action=check_availability
  // Validate no conflicts
  // Show conflicts if any
}
```

### Live Validation

```javascript
watch: {
  'job.client_id': () => this.validateJob(),
  'job.property_id': () => this.validateJob(),
  'job.service_package_id': () => this.validateJob(),
  'job.scheduled_date': () => this.validateJob(),
  'job.assigned_to': () => this.validateJob(),
}

validateJob() {
  // POST /api/job-creation.php?action=validate_job
  // Get validation results
  this.validation = result
  // Update UI with errors/warnings/suggestions
}
```

### Tab Navigation

```javascript
methods: {
  // Tab switching
  selectTab(tabName) {
    this.activeTab = tabName
    // Scroll to top of form
    // Validate current tab before allowing next
  }

  // Previous/Next buttons
  previousTab() {
    const tabs = ['basics', 'schedule', 'proof', 'review']
    const currentIndex = tabs.indexOf(this.activeTab)
    if (currentIndex > 0) {
      this.selectTab(tabs[currentIndex - 1])
    }
  }

  nextTab() {
    // Validate current tab
    if (this.validation.is_valid) {
      const tabs = ['basics', 'schedule', 'proof', 'review']
      const currentIndex = tabs.indexOf(this.activeTab)
      if (currentIndex < tabs.length - 1) {
        this.selectTab(tabs[currentIndex + 1])
      }
    }
  }

  // Submit
  createJob() {
    // POST /api/job-creation.php?action=create_job
    this.isLoading = true
    // ...
    // Show success message
    // Redirect to job detail or list
  }
}
```

---

## Keyboard Shortcuts

### Global Shortcuts
- **Ctrl+Shift+J** — Open new job dialog (from any page)

### In Job Creation Form
- **Tab** — Move to next field
- **Shift+Tab** — Move to previous field
- **Enter** — Confirm date selection, crew dropdown, etc.
- **Escape** — Close form / cancel
- **Alt+S** — Toggle one-time ↔ recurring
- **Ctrl+Enter** — Submit form (from any tab)

### In Dropdowns
- **↑↓** — Navigate options
- **Enter** — Select option
- **Escape** — Close dropdown

---

## Mobile Responsiveness

### Desktop (>768px)
- Full-width form
- 2-column layout (when needed)
- Normal font sizes
- Hover effects on buttons

### Tablet (768px-1024px)
- Full-width form
- Adjusted spacing
- Touch-friendly buttons (44px minimum)
- Slightly larger form elements

### Mobile (<768px)
- Full-width form
- Single-column layout
- Stacked buttons
- Date/time pickers optimized for touch
- Larger touch targets (48px minimum)

---

## Testing Checklist (Phase 3)

Before launch, verify:

- [ ] Form loads without errors
- [ ] All 4 tabs display correctly
- [ ] Client autocomplete works
- [ ] Service package selection applies defaults
- [ ] Date picker shows available dates
- [ ] Crew suggestion appears and is accurate
- [ ] Time window calculates correctly
- [ ] Recurring pattern generates instances
- [ ] Validation errors highlight correctly
- [ ] Can submit valid job
- [ ] Success message displays
- [ ] Keyboard shortcuts work
- [ ] Mobile layout responsive
- [ ] Tab order correct (keyboard navigation)
- [ ] Form submission creates job in database
- [ ] Proof of work requirements are set
- [ ] Crew notification is sent (or logged)

---

## Success Criteria

Phase 3 is complete when:

✅ Job creation page loads
✅ All 4 tabs functional
✅ Smart defaults working
✅ Real-time validation active
✅ API calls returning correct data
✅ Job creation successful
✅ Keyboard navigation works
✅ Mobile responsive
✅ No JavaScript console errors
✅ No 404 errors
✅ Database records created correctly

---

## Integration Points

Phase 3 will integrate with:

- **Dashboard** — "New Job" button → Click opens create-nextgen.php
- **Jobs List** — "Create Job" action → Opens create-nextgen.php
- **Quotes** — "Convert to Job" → Pre-fills create-nextgen.php
- **Navigation** — Sidebar link if needed
- **Crew App** — Receives notifications when job created

---

## Estimated Timeline

| Task | Hours | Status |
|------|-------|--------|
| Page structure & HTML | 6 | Ready |
| Vue.js component setup | 4 | Ready |
| Tab navigation logic | 2 | Ready |
| API integration | 6 | Ready |
| Smart defaults logic | 6 | Ready |
| Validation & errors | 4 | Ready |
| Styling & CSS | 8 | Ready |
| Mobile responsiveness | 4 | Ready |
| Testing & bug fixes | 8 | Ready |
| Documentation | 2 | Ready |
| **TOTAL** | **50 hours** | Ready |

---

## Next Steps

1. ✅ Run all Phase 2 tests (verify backend works)
2. ⏳ Create `/public/crm/jobs/create-nextgen.php` file
3. ⏳ Build HTML structure (form, tabs, fields)
4. ⏳ Add Vue.js component with data model
5. ⏳ Implement API integration
6. ⏳ Add CSS styling
7. ⏳ Test all workflows
8. ⏳ Deploy to staging
9. ⏳ User acceptance testing
10. ⏳ Deploy to production

---

## Status

**Phase 1 (Design):** ✅ Complete
**Phase 2 (Backend):** ✅ Complete
**Phase 3 (Frontend):** 🚀 **READY TO BEGIN**
**Phase 4 (Proof & Invoicing):** ⏳ Planned
**Phase 5 (Analytics):** ⏳ Planned

---

**Ready to start Phase 3?** → Create the job creation page file and begin building the UI! 🎉

