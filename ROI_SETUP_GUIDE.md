# ROI Attribution & Funnel Tracking — Setup Guide

## What You Get

✅ **Conversion Funnel Dashboard**
- Leads → Quote Requests → Jobs → Revenue
- See conversion rates at each stage
- Filter by date range and source

✅ **Revenue by Source**
- See which marketing channels drive revenue
- Break down by campaign (utm_campaign)
- Total conversions, jobs, and revenue per source

✅ **Lead Journey Tracking**
- See individual leads and their path to revenue
- Track which pages they landed on
- See all conversion events for each lead

---

## How It Works

### 1. **Lead Entry Point**
When someone visits your quote form or lands on a page from a marketing link, a `lead_event` is recorded automatically:
- UTM parameters (utm_source, utm_medium, utm_campaign, utm_content)
- Landing page
- Session ID

### 2. **Conversion Events**
As leads move through your funnel, events are logged:
- **Quote Request** — submitted form
- **Quote Sent** — sent to customer
- **Quote Accepted** — customer approved
- **Job Created** — scheduled for work
- **Job Completed** — work finished
- **Invoice Paid** — money received

### 3. **Revenue Attribution**
Jobs are linked to lead events, so you can see:
- Which source brought this customer
- How much revenue they generated
- Conversion rate for that source

---

## Setup: Adding UTM Tracking

### Step 1: Add UTM Parameters to Marketing Links

When you create marketing links (social media, ads, email), add UTM parameters:

**Example:**
```
https://mowology.ca/quote?utm_source=facebook&utm_campaign=spring-2026&utm_medium=social
```

**All three parameters:**
- `utm_source` — Where from? (facebook, google, email, newsletter, etc.)
- `utm_medium` — Type of medium (social, cpc, email, organic, etc.)
- `utm_campaign` — Campaign name (spring-cleanup, spring-2026, discount-20off, etc.)
- `utm_content` — Optional: which ad variant? (ad1, ad2, etc.)

### Step 2: Marketing Links Template

Use these formats:

**Facebook Posts:**
```
https://mowology.ca/quote?utm_source=facebook&utm_medium=social&utm_campaign=spring-2026
```

**Google Ads:**
```
https://mowology.ca/quote?utm_source=google&utm_medium=cpc&utm_campaign=spring-cleanup
```

**Email Newsletters:**
```
https://mowology.ca/quote?utm_source=email&utm_medium=newsletter&utm_campaign=march-2026
```

**LinkedIn:**
```
https://mowology.ca/quote?utm_source=linkedin&utm_medium=social&utm_campaign=b2b-strata
```

### Step 3: Dashboard Tracking

Go to **Portfolio** → **ROI Dashboard** tab to see:
1. **Funnel Chart** — Visualization of lead → job → revenue
2. **Revenue by Source** — Which channels actually make money
3. **Lead Journey** — Individual leads and their path

---

## Example: Real ROI Data

Let's say you run a Facebook campaign for spring cleanup:

**Marketing Setup:**
- Ad spend: $500/month
- Links use: `utm_source=facebook&utm_campaign=spring-cleanup`

**Dashboard Shows:**
- 45 leads (people clicked)
- 18 quote requests (40% conversion)
- 9 jobs won (50% of quotes)
- $4,500 revenue
- **ROI: 800%** ($4,500 revenue - $500 spend = $4,000 profit / $500 spend = 8x return)

---

## Data Collection Points

### Automatic (No Setup Needed)
- ✅ UTM parameters captured from URLs
- ✅ Landing page recorded
- ✅ Session ID tracked

### Manual (You Must Add)
- Quote request form submission → logs conversion_event
- Job completion → create roi_attribution record
- Invoice payment → calculates revenue

### Semi-Automatic (Code Integration)
- When job is created from quote, link to lead event
- When invoice is paid, update roi_attribution.actual_value

---

## Implementation Checklist

- [ ] Update your quote form to call `logLeadEvent()` on submission
- [ ] Update job creation to call `createROIAttribution()` with lead_event_id
- [ ] Update invoice completion to update roi_attribution.actual_value
- [ ] Test with sample UTM links
- [ ] Create a few test campaigns in your marketing channels

---

## Code Integration Points

### Add to Quote Form (jobFlow or quote request page)

```php
<?php
require_once __DIR__ . '/../app_config/config.php';
require_once __DIR__ . '/../crm/includes/roi-functions.php';

// When form is submitted:
$leadEventId = logLeadEvent(
    $_SERVER['HTTP_REFERER'],  // landing page
    [
        'source' => $_GET['utm_source'] ?? null,
        'medium' => $_GET['utm_medium'] ?? null,
        'campaign' => $_GET['utm_campaign'] ?? null,
        'content' => $_GET['utm_content'] ?? null,
    ]
);

// Log the quote request
logConversionEvent($leadEventId, 'quote_request', $quoteId);

// Store lead_event_id in session or database for later
$_SESSION['lead_event_id'] = $leadEventId;
?>
```

### When Job is Created

```php
<?php
// Link job to the lead that started it
$leadEventId = $_SESSION['lead_event_id'] ?? null;
$estimatedValue = $quote['amount'] ?? null;

if ($leadEventId) {
    createROIAttribution($jobId, $leadEventId, null, $estimatedValue);
    logConversionEvent($leadEventId, 'job_created', $jobId);
}
?>
```

### When Invoice is Paid

```php
<?php
// Update actual revenue
$stmt = $db->prepare("
    UPDATE roi_attribution
    SET actual_value = ?
    WHERE job_id = ?
");
$stmt->execute([$invoiceTotal, $jobId]);

// Log conversion
logConversionEvent($leadEventId, 'invoice_paid', $invoiceId);
?>
```

---

## Dashboard Features

### Funnel Chart
Visual representation of:
1. **Leads** — All page visits from marketing links
2. **Quote Requests** — Form submissions
3. **Quotes Sent** — Proposals sent to customers
4. **Jobs Won** — Accepted quotes converted to jobs
5. **Revenue** — Total dollars earned

### Revenue by Source
Table showing for each utm_source:
- Number of leads
- Quote requests
- Jobs completed
- Total revenue
- Conversion rate

### Recent Lead Journey
Shows last 100 leads with:
- Source/campaign they came from
- Which page they landed on
- What events happened (quote_request, job_created, etc.)
- Job number if created
- Revenue if paid
- Date of lead entry

---

## Tips for Success

1. **Use consistent source names** — "Facebook" vs "facebook" creates separate rows
2. **Name campaigns descriptively** — "spring-cleanup" vs "spring" helps identify winners
3. **Use utm_source for main channel** — facebook, google, email, linkedin, etc.
4. **Use utm_campaign for promotion** — what you're promoting (service, season, discount)
5. **Test a sample link** — verify parameters are passed through your quote form

---

## Next Steps

Once ROI tracking is live, you can:

1. **Analyze which channels work** — Kill low-ROI campaigns
2. **Optimize best performers** — Double down on high-ROI sources
3. **Calculate customer acquisition cost** — $500 spend / 45 leads = $11 per lead
4. **Predict revenue** — 2% lead→job rate × $1,500 avg job = revenue potential

---

## FAQ

**Q: What if I have organic traffic (no utm_source)?**
A: These are logged as `(direct)` and can still be tracked if they convert to jobs.

**Q: When do leads show up in the dashboard?**
A: Immediately when they visit a link with UTM parameters.

**Q: Do I need to add tracking code to my website?**
A: The system captures UTM params automatically. Just integrate `logLeadEvent()` into your quote form.

**Q: Can I see individual customer journeys?**
A: Yes, the "Recent Lead Journey" table shows the last 100 leads with their path.

**Q: How far back does data go?**
A: Dashboard defaults to last 30 days, but you can change the date range.

