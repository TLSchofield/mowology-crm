# Landing Page to Marketing Automation Suite Integration

## Quick Reference

This document explains how the new GSC-optimized landing page integrates with Mowology's automated marketing suite.

---

## 🔄 Lead Flow: Landing Page → Email Automation → Conversion

```
┌─────────────────────────────────────────┐
│  1. VISITOR LANDS ON                    │
│     /services/professional-lawn-mowing-care
│     (from Google organic search)        │
└──────────────────┬──────────────────────┘
                   │
        ┌──────────▼──────────┐
        │  Session variables │
        │  captured:         │
        │ • lead_source      │
        │ • utm_source       │
        │ • utm_campaign     │
        │ • gsc_keywords     │
        └──────────┬──────────┘
                   │
        ┌──────────▼──────────────────────┐
        │ 2. VISITOR CLICKS CTA           │
        │    "Get a Free Quote →"         │
        │    /quote?service=maintenance   │
        │    &src=professional-mowing...  │
        └──────────┬──────────────────────┘
                   │
        ┌──────────▼──────────────────────┐
        │ 3. QUOTE FORM SUBMITS           │
        │    Data sent to                 │
        │    /crm/api/job-creation.php    │
        └──────────┬──────────────────────┘
                   │
        ┌──────────▼──────────────────────┐
        │ 4. LEAD CREATED IN DATABASE     │
        │ • quote_requests table          │
        │ • source = "prof-lawn-mowing"   │
        │ • utm_source = "organic"        │
        │ • utm_campaign = "gsc-..."      │
        └──────────┬──────────────────────┘
                   │
        ┌──────────▼──────────────────────┐
        │ 5. EMAIL AUTOMATION TRIGGERS    │
        │ • Immediate (2h): Quote followup│
        │ • Day 7: Benefits email         │
        │ • Day 14: Pricing email         │
        │ • Day 1 (abandoned): SMS remind │
        └──────────┬──────────────────────┘
                   │
        ┌──────────▼──────────────────────┐
        │ 6. REMARKETING ACTIVATED        │
        │ • Google Ads audience updated   │
        │ • Facebook pixel fires          │
        │ • Retargeting display ads       │
        └─────────────────────────────────┘
```

---

## 📊 Data Capture Points

### Session Variables (Captured on Page Load)

When a visitor lands on the landing page, these PHP session variables are set:

```php
$_SESSION['lead_source'] = 'professional-lawn-mowing-care';
$_SESSION['utm_source'] = 'organic';
$_SESSION['utm_medium'] = 'search';
$_SESSION['utm_campaign'] = 'gsc-lawn-mowing-care';
$_SESSION['gsc_keywords'] = [
    'lawn mowing vancouver',
    'lawn care vancouver',
    'lawn maintenance service',
    // ... etc
];
```

**These are used to:**
- Pre-fill hidden form fields on quote form
- Tag lead in CRM with source
- Segment email automation sequences
- Track remarketing audience

### Quote Request Table (Capture on Form Submit)

When quote form submits, new row in `quote_requests`:

```sql
INSERT INTO quote_requests (
    name, email, phone,
    service, property_type,
    source,
    utm_source, utm_medium, utm_campaign,
    gsc_keywords,
    created_at
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW());
```

These fields feed directly into:
- **Sales team dashboard** (filtered by source)
- **Email automation system** (uses source to select template)
- **Google Ads conversion tracking** (tracks revenue by source)
- **Analytics** (ROI calculation)

---

## 📧 Email Automation Integration

### Sequence 1: Immediate Quote Followup (2 hours)

**Trigger:** `quote_requests.source = 'professional-lawn-mowing-care'` AND created < 2 hours ago

**Template:** `quote-followup-lawn-mowing.html`

**Subject:** "Your Free Lawn Mowing Quote – Mowology Vancouver"

**Content:**
- Confirms their request was received
- Sets expectation for follow-up timing
- Provides link to contact us if urgent
- Includes service details from their quote

**Template variables available:**
```php
{{ visitor_name }}           // "John Smith"
{{ service_selected }}       // "Lawn Mowing"
{{ property_address }}       // "123 Main St, Vancouver"
{{ request_date }}           // "February 10, 2026"
{{ contact_phone }}          // "778-846-9273"
{{ quote_link }}             // "https://mowology.ca/quote?id=QUO-2026-..."
```

---

### Sequence 2: No-Conversion Nurture — Day 7 (Benefits Email)

**Trigger:** `quote_requests.source = 'professional-lawn-mowing-care'` AND created > 7 days ago AND no conversion

**Template:** `nurture-lawn-care-benefits.html`

**Subject:** "7 Benefits of Professional Lawn Care in Vancouver"

**Content:**
- Educational: why professional lawn care matters
- Social proof: customer testimonials
- Comparison: DIY vs. professional
- CTA: "Let's get started"

**Design Goal:** Re-engage with value, not hard sell

---

### Sequence 3: No-Conversion Nurture — Day 14 (Pricing Email)

**Trigger:** `quote_requests.source = 'professional-lawn-mowing-care'` AND created > 14 days ago AND no conversion

**Template:** `nurture-lawn-care-pricing.html`

**Subject:** "Transparent Lawn Mowing Pricing – Starting at $45/Visit"

**Content:**
- Transparent pricing model
- Pricing breakdown by frequency
- No-surprise guarantee
- Limited-time incentive (if applicable)
- CTA: "Get your custom quote"

**Design Goal:** Remove pricing objection, drive conversion

---

### Sequence 4: Abandoned Quote Reminder — Day 1 (SMS)

**Trigger:** Quote form submitted but payment/booking not completed, created > 24 hours ago

**Template:** `quote-reminder-lawn-mowing.txt` (SMS)

**Message:**
```
Hi {first_name}! We noticed you started a lawn mowing quote
with Mowology. Finish it now for a guaranteed 24-hour response.
{quote_link}
```

**Method:** Twilio API or SMS gateway integration

**Design Goal:** High-intent mobile push for time-sensitive leads

---

## 🎯 Remarketing Integration

### Google Ads Configuration

**Audience Name:** `gsc-lawn-mowing-vancouver`

**Trigger:** Pixel fires when visitor lands on `/services/professional-lawn-mowing-care`

**Audience Segments:**
1. **All visitors** (cookied for 30 days)
2. **High-intent visitors** (spent > 2 min, scrolled > 60%)
3. **Non-converters** (visited but didn't click CTA)
4. **CTA clickers** (clicked "Get a Free Quote" but didn't submit)

**Ads to Show:**
```
Headline 1: "Professional Lawn Mowing in Vancouver"
Headline 2: "Starting at $45 Per Visit"
Description: "Free quote. No obligation. Get back to enjoying your lawn."
Display URL: mowology.ca/services/professional-lawn-mowing-care
```

**Landing Page:** `/services/professional-lawn-mowing-care?utm_source=remarketing&utm_medium=display&utm_campaign=gsc-lawn-mowing-care`

---

### Facebook Pixel Integration

**Event:** `ViewContent`

**Parameters Passed:**
```json
{
  "content_name": "Professional Lawn Mowing Service",
  "content_category": "lawn-mowing",
  "content_ids": ["professional-lawn-mowing-care"],
  "value": 0,
  "currency": "CAD"
}
```

**Audience Segment:** "People who viewed lawn mowing landing page"

**Facebook Ads:**
```
Primary Text: "We didn't give you a quote yet."
Headline: "Professional Lawn Mowing in Vancouver"
Body: "Get one in 2 minutes. We'll follow up within 24 hours."
CTA: "Get a Quote"
Landing URL: /quote?service=maintenance&src=professional-mowing-care
```

---

## 🔗 URL Parameter Tracking

### Organic Search Landing
```
User arrives from Google: /services/professional-lawn-mowing-care
Session sets: utm_source=organic, utm_medium=search, utm_campaign=gsc-lawn-mowing-care
Quote saved with: source='professional-lawn-mowing-care'
```

### Email Campaign Click
```
Email URL: /services/professional-lawn-mowing-care?utm_source=email&utm_medium=email&utm_campaign=nurture-day7
Session sets: utm_source=email, utm_medium=email, utm_campaign=nurture-day7
Quote saved with: source='professional-lawn-mowing-care' (original), utm_source=email
```

### Remarketing Ad Click
```
Google Ads URL: /services/professional-lawn-mowing-care?utm_source=remarketing&utm_medium=display&utm_campaign=gsc-lawn-mowing-care
Session sets: utm_source=remarketing, utm_medium=display
Quote saved with: source='professional-lawn-mowing-care', utm_source=remarketing
```

### Social Media Share
```
LinkedIn URL: /services/professional-lawn-mowing-care?utm_source=linkedin&utm_medium=social&utm_campaign=brand-awareness
Session sets: utm_source=linkedin, utm_medium=social
Quote saved with: source='professional-lawn-mowing-care', utm_source=linkedin
```

---

## 💾 Database Integration

### Tables Affected

#### `quote_requests`
```sql
-- Columns populated from landing page:
source                  -- 'professional-lawn-mowing-care'
utm_source              -- 'organic'
utm_medium              -- 'search'
utm_campaign            -- 'gsc-lawn-mowing-care'
gsc_keywords            -- JSON array of triggering keywords
service                 -- Pre-selected from form preset
property_type           -- From form
created_at              -- Timestamp
```

#### `email_sent` (if logging enabled)
```sql
-- Tracks which sequences were triggered:
quote_id                -- Links to quote_requests
template_name           -- 'quote-followup-lawn-mowing'
sent_at                 -- Timestamp
opened_at               -- When email opened (if tracked)
clicked_at              -- When link clicked (if tracked)
```

#### `remarketing_audience`
```sql
-- Tracks pixel fires for audience segmentation:
visitor_id              -- Anonymous ID
landing_page            -- '/services/professional-lawn-mowing-care'
first_visit             -- Timestamp
last_visit              -- Timestamp
actions                 -- 'view', 'scroll', 'cta_click', 'convert'
```

---

## 🚀 Setting Up the Integration

### Step 1: Verify Email Templates Exist

```bash
/public/crm/email-templates/
├── quote-followup-lawn-mowing.html
├── nurture-lawn-care-benefits.html
├── nurture-lawn-care-pricing.html
└── quote-reminder-lawn-mowing.txt
```

If missing, create them or use existing templates as base.

### Step 2: Enable Marketing Automation in Config

File: `/public/app_config/secrets.php` or `/public/app_config/config.php`

```php
define('MARKETING_AUTOMATION_ENABLED', true);
define('EMAIL_AUTOMATION_ENABLED', true);
define('SMS_AUTOMATION_ENABLED', true);
define('REMARKETING_PIXEL_ENABLED', true);
```

### Step 3: Verify Cron Jobs

Set up cron job to trigger email automation sequences (runs hourly):

```bash
# /etc/cron.d/mowology
0 * * * * /usr/bin/php /home/mowology/public/crm/api/email-automation-cron.php >> /home/mowology/logs/email-automation.log 2>&1
```

### Step 4: Configure Pixel Codes

Add Google Ads conversion pixel to `/public/includes/head.php` (already done if GA4 installed)

Add Facebook pixel to `/public/includes/head.php`:
```html
<!-- Facebook Pixel Code -->
<script>
!function(f,b,e,v,n,t,s)
{if(f.fbq)return;n=f.fbq=function(){n.callMethod?
n.callMethod.apply(n,arguments):n.queue.push(arguments)};
/* ... pixel setup ... */
</script>
```

### Step 5: Monitor First 48 Hours

- [ ] Check email logs: `/public/crm/logs/email-*.log`
- [ ] Verify quotes show `source='professional-lawn-mowing-care'`
- [ ] Check Google Ads audience population
- [ ] Verify Facebook pixel fires (check Events Manager)
- [ ] Monitor email delivery rates (Gmail, Outlook, etc.)

---

## 📈 Reporting & ROI Tracking

### Key Metrics to Monitor

| Metric | Where to Track | Target |
|--------|---|---|
| **Landing page traffic** | Google Analytics | 200-400 visitors/month |
| **CTA click-through rate** | GA Goals | > 5% |
| **Quote form conversion** | Quote form analytics | > 20% |
| **Email open rate** | Email service | > 25% |
| **Email click rate** | Email service | > 5% |
| **Cost per qualified lead** | Google Ads → Quote | < $25 |
| **Customer acquisition cost** | Revenue / leads closed | < $100 |

### Custom Dashboard Query

```sql
-- Monthly ROI by source
SELECT
    source,
    COUNT(*) as total_quotes,
    COUNT(CASE WHEN status='converted' THEN 1 END) as conversions,
    SUM(CASE WHEN status='converted' THEN revenue ELSE 0 END) as total_revenue,
    ROUND(SUM(CASE WHEN status='converted' THEN revenue ELSE 0 END) / COUNT(*), 2) as revenue_per_quote
FROM quote_requests
WHERE source = 'professional-lawn-mowing-care'
  AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
GROUP BY source
ORDER BY total_revenue DESC;
```

---

## 🔧 Troubleshooting

### Email Not Sending
**Problem:** Quote submitted but email not received

**Checklist:**
- [ ] Check email template file exists
- [ ] Verify SMTP credentials in config
- [ ] Check email log for errors: `tail -100 /public/crm/logs/email-automation.log`
- [ ] Verify cron job is running: `crontab -l`
- [ ] Check spambox for email

**Fix:**
```bash
# Manually trigger email automation (one-time test)
php /public/crm/api/email-automation-cron.php

# Check logs
tail -50 /public/crm/logs/email-automation.log
```

---

### Quotes Not Tagged with Source
**Problem:** Quote submitted but `source` column is NULL

**Checklist:**
- [ ] Verify session variable is set: Add `echo $_SESSION['lead_source'];` to quote form
- [ ] Verify CTA URL includes `src=` parameter
- [ ] Verify quote form captures hidden field: `<input type="hidden" name="source" value="<?= htmlspecialchars($_SESSION['lead_source'] ?? '') ?>">`

**Fix:** Edit `/public/quote.php` to ensure hidden form field is populated

---

### Remarketing Audience Not Growing
**Problem:** Pixel fires but audience doesn't populate

**Checklist:**
- [ ] Verify pixel code is on page: Check page source (Ctrl+U)
- [ ] Verify Google Ads account is linked to website
- [ ] Wait 24-48 hours for audience to populate
- [ ] Check Google Ads conversion pixel settings

**Fix:**
1. Verify pixel in GA4 admin
2. Check "Linked properties" in Google Ads
3. Test pixel fires: Use Google Tag Assistant browser extension

---

## 📞 Integration Support

- **Landing page issues:** See `PROFESSIONAL_LAWN_MOWING_LANDING_PAGE_GUIDE.md`
- **Email template editing:** See CRM email template docs
- **Quote form issues:** Check `/public/quote.php`
- **Database schema:** Check `/database/schema.sql`
- **Cron jobs:** Check cPanel cron management or `/etc/cron.d/`

---

**Document Version:** 1.0
**Last Updated:** February 10, 2026
**Author:** Claude Code — Mowology Marketing Automation
