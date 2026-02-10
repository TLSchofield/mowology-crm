# Professional Lawn Mowing & Care Landing Page — GSC Integration Guide

## Overview

This landing page is built from your Google Search Console keywords and optimized to convert organic search traffic into quote requests and customer leads. It integrates seamlessly with Mowology's automated marketing suite for email nurture, SMS follow-up, and remarketing.

---

## 📍 Page Location & Access

- **File:** `/public/includes/service-data/professional-lawn-mowing-care.php`
- **Page File:** `/public/services/professional-lawn-mowing-care.php`
- **Live URL:** `/services/professional-lawn-mowing-care`
- **Full URL:** `https://mowology.ca/services/professional-lawn-mowing-care`

The page is automatically rewritten by `.htaccess` from `/services/professional-lawn-mowing-care` to `/services/professional-lawn-mowing-care.php`.

---

## 🎯 GSC Keywords This Page Targets

The landing page is optimized for these search terms from your Google Search Console:

```
lawn mowing vancouver
lawn care vancouver
lawn maintenance service
lawn mowing burnaby
strata landscaping vancouver
snow removal vancouver
landscaping services vancouver
```

Each keyword appears naturally in:
- Page title
- Meta description
- Hero headline and subheadline
- Service descriptions
- FAQ section
- Body content

---

## 📊 Content Structure

### Hero Section
```
Headline: "Professional Lawn Mowing & Lawn Care in Vancouver"
Subheadline: "Reliable lawn maintenance, landscaping services, and snow removal
             for residential and commercial properties across Vancouver, Burnaby,
             and the Lower Mainland."
CTA: "Get a Free Quote →"
Image: Professional lawn care crew at work
```

This hits the primary GSC keywords immediately and sets expectation for what the visitor will find.

### Proof Sections

#### 1. Benefits (Why Choose Mowology)
- Local Vancouver Expertise
- Consistent & Reliable
- Residential & Commercial
- Year-Round Property Care
- Photo Verification
- Expert Support

Each benefit directly addresses objections from search intent: "Will they show up? Are they local? Do they understand Vancouver?"

#### 2. Services Checklist
Lists all six main services with descriptions:
- Lawn Mowing Service
- Lawn Care & Maintenance
- Commercial Lawn Mowing
- Strata Landscaping Vancouver
- Landscaping Services
- Snow Removal Vancouver

#### 3. Process (Getting Started)
4-step process reduces friction:
1. Request a Quote
2. Free Property Assessment
3. Flexible Service Plans
4. Reliable Maintenance

### FAQ Section
8 questions addressing common search intent:
- Pricing ("How much does lawn mowing cost?")
- Services ("Do you offer strata landscaping?")
- Coverage ("What areas do you serve?")
- Seasonal ("Do you provide snow removal?")
- Frequency ("How often should I mow?")
- Inclusions ("What's included in maintenance?")
- Recurring ("Can I get on a schedule?")
- Commercial ("Do you service commercial properties?")

---

## 🤖 Marketing Automation Integration

### Campaign Metadata

```php
'campaign_id'  => 'professional-lawn-mowing-care-gsc-q1-2026'
'source_tag'   => 'professional-lawn-mowing-care'
'utm_source'   => 'organic'
'utm_medium'   => 'search'
'utm_campaign' => 'gsc-lawn-mowing-care'
```

When a visitor lands on this page and later converts (requests a quote), these parameters are captured and stored in the `quote_requests` table and CRM system.

### Nurture Sequences

When a visitor **requests a quote** from this page, they automatically enter the nurture funnel:

#### Immediate (2 hours)
- **Email:** "Your Free Lawn Mowing Quote – Mowology Vancouver"
- **Template:** `quote-followup-lawn-mowing`
- **Purpose:** Confirm receipt, set expectations for follow-up

#### Day 7 (If no conversion)
- **Email:** "7 Benefits of Professional Lawn Care in Vancouver"
- **Template:** `nurture-lawn-care-benefits`
- **Purpose:** Re-engage with detailed benefits messaging

#### Day 14 (If no conversion)
- **Email:** "Transparent Lawn Mowing Pricing – Starting at $45/Visit"
- **Template:** `nurture-lawn-care-pricing`
- **Purpose:** Address pricing objection with concrete numbers

#### Day 1 (If abandoned quote)
- **SMS:** Quote reminder with link to complete
- **Template:** `quote-reminder-lawn-mowing`
- **Purpose:** Mobile push for time-sensitive leads

### Remarketing Configuration

Visitors to this page are automatically added to remarketing audiences:

| Platform | Audience Tag | Event | Message |
|----------|-------------|-------|---------|
| Google Ads | `gsc-lawn-mowing-vancouver` | ViewContent | "Professional Lawn Mowing in Vancouver – Starting at $45/Visit" |
| Facebook | Pixel event | ViewContent | "We didn't give you a quote yet. Get one in 2 minutes." |

---

## 🔗 Call-to-Action Flow

### Primary CTA: "Get a Free Quote →"

Links to: `/quote?service=maintenance&src=professional-lawn-mowing-care`

**Query Parameters:**
- `service=maintenance` — Pre-selects "Lawn Mowing" in quote form
- `src=professional-lawn-mowing-care` — Tracks source in `quote_requests.source`

When the quote form is submitted:
1. A `quote_request` is created in the database
2. An auto-reply email is sent immediately
3. The visitor is tagged with `professional-lawn-mowing-care` in the CRM
4. Email nurture sequences begin
5. Remarketing audience is updated with conversion status

### Secondary CTA: "Call 778-846-9273"

Direct phone number for high-intent visitors who prefer calling.

---

## 📧 Email Integration Points

### Where Email Templates Are Stored

Email templates are managed in the CRM email automation system. Each template referenced in the data file (`quote-followup-lawn-mowing`, `nurture-lawn-care-benefits`, etc.) should exist in your email template directory:

```
/public/crm/email-templates/
├── quote-followup-lawn-mowing.html
├── nurture-lawn-care-benefits.html
├── nurture-lawn-care-pricing.html
└── quote-reminder-lawn-mowing.html (SMS template)
```

### Dynamic Content in Templates

Templates can reference:
- Visitor's name and email (captured from quote form)
- Service selected (`maintenance`)
- Area served (from form)
- Property type (from form)
- Inquiry date

Example template variable: `{{visitor_name}}` → "Hi John!"

---

## 📱 Quote Form Integration

When a visitor clicks the CTA and reaches `/quote?service=maintenance&src=professional-lawn-mowing-care`:

1. The quote form loads with `service=maintenance` pre-selected
2. Visitor fills in their details (name, email, phone, property info)
3. Form submits to `/crm/api/job-creation.php` or equivalent handler
4. `quote_requests` table is updated with:
   - `source`: "professional-lawn-mowing-care"
   - `utm_source`: "organic"
   - `utm_medium`: "search"
   - `utm_campaign`: "gsc-lawn-mowing-care"
   - `service`: "maintenance"
5. Sales team is notified
6. Auto-reply email begins nurture sequence

---

## 🔍 SEO Implementation

### Schema.org Markup

The service template automatically outputs LocalBusiness and Service schema:

```json
{
  "@type": "LocalBusiness",
  "name": "Mowology",
  "serviceType": "Lawn Mowing & Lawn Care",
  "areaServed": [
    { "@type": "City", "name": "Vancouver" },
    { "@type": "City", "name": "Burnaby" },
    { "@type": "City", "name": "New Westminster" },
    { "@type": "City", "name": "North Vancouver" },
    { "@type": "City", "name": "Richmond" }
  ]
}
```

This schema helps Google understand:
- Service type
- Service areas (cities served)
- Business location

### Meta Tags

| Tag | Value |
|-----|-------|
| Title | "Lawn Mowing & Lawn Care Vancouver \| Mowology – Professional Lawn Maintenance" |
| Description | "Vancouver's trusted lawn mowing service. Professional lawn care, maintenance, strata landscaping & snow removal in Vancouver, Burnaby & surrounding areas. Free quotes." |
| Keywords | "lawn mowing vancouver, lawn care vancouver, lawn maintenance service, lawn mowing burnaby, strata landscaping vancouver, snow removal vancouver, landscaping services vancouver" |

---

## 🚀 How to Use This Landing Page

### For New Visitors
1. Visitor searches for "lawn mowing vancouver" on Google
2. Mowology appears in organic search results
3. Visitor clicks and lands on `/services/professional-lawn-mowing-care`
4. Visitor scrolls through benefits, services, FAQ
5. Visitor clicks "Get a Free Quote"
6. Visitor fills quote form
7. Visitor enters email nurture funnel

### For Existing Leads
1. Use this URL in email campaigns: "Let's talk about lawn mowing in Vancouver"
2. Use this URL in social media: "Need lawn care in Vancouver? Start here."
3. Use this URL in Google Ads: "Professional Lawn Mowing — Start with a free quote"
4. Use this URL in local directories: "Learn about our lawn mowing services"

### For Remarketing
1. Visitors to this page are cookied by Google Ads and Facebook Pixel
2. After 7 days with no conversion, remarketing ads appear
3. Ads link back to this page with parameter: `?utm_source=remarketing&utm_medium=display&utm_campaign=gsc-lawn-mowing-care`

---

## 📈 Tracking & Analytics

### Google Analytics Integration

The page automatically tracks:
- **Page views** → Which GSC keywords drive traffic
- **Scroll depth** → Which sections engage visitors (benefits? FAQ?)
- **CTA clicks** → How many "Get a Free Quote" clicks per session
- **Conversion** → Quote form submissions

Set up a goal in Google Analytics:
- Goal: "Quote Request"
- Trigger: URL contains `/quote`
- Name: "Quote Request from GSC Landing Page"

### UTM Parameters

When sharing this page externally, append UTM parameters:

| Channel | UTM String |
|---------|-----------|
| Email | `?utm_source=email&utm_medium=newsletter&utm_campaign=q1-2026-lawn-mowing` |
| Facebook | `?utm_source=facebook&utm_medium=paid&utm_campaign=q1-2026-lawn-mowing` |
| Google Ads | `?utm_source=google&utm_medium=cpc&utm_campaign=q1-lawn-mowing` |
| LinkedIn | `?utm_source=linkedin&utm_medium=social&utm_campaign=q1-2026` |

---

## 🔧 Customization & Updates

### Updating Service Copy

Edit the data file: `/public/includes/service-data/professional-lawn-mowing-care.php`

Change any of these sections:
- `hero.headline` — Main headline
- `hero.subheadline` — Subheadline with value prop
- `proof_sections[0].items` — Benefit copy
- `proof_sections[1].items` — Service descriptions
- `faq` — Frequently asked questions

The page re-renders automatically on save.

### Adding a New Email Template

1. Create template file: `/public/crm/email-templates/my-new-template.html`
2. Add to nurture sequences in data file:
   ```php
   'nurture_sequences' => [
       'new_trigger' => [
           'email_template' => 'my-new-template',
           'delay_hours'    => 72,
           'subject'        => 'Email Subject Line',
       ],
   ],
   ```
3. Email system reads template name and sends at specified delay

### A/B Testing Headlines

Create a copy of the data file with variant headline:
- `professional-lawn-mowing-care-variant-a.php`
- Create corresponding page: `/services/professional-lawn-mowing-care-variant-a.php`
- Split traffic 50/50 between variants in Google Ads
- Track which variant converts better

---

## 🎯 Performance Goals & Benchmarks

### Target Metrics

| Metric | Target | Notes |
|--------|--------|-------|
| **Bounce Rate** | < 40% | Indicates engagement |
| **Pages/Session** | > 2 | Visitors viewing multiple sections |
| **Avg. Session Duration** | > 2 min | Enough time to read benefits + FAQ |
| **CTA Click Rate** | > 5% | At least 1 in 20 visitors engage |
| **Quote Conversion Rate** | > 20% | Of CTA clicks that reach form |

### Expected Traffic

- **Monthly visitors from GSC keywords:** 150-300 (depends on current rankings)
- **Monthly quote requests:** 15-30 (assuming 5-10% CTA rate, 20% conversion)
- **Monthly conversions:** 3-10 customers (assuming 20% close rate)

---

## 🛠 Troubleshooting

### Page Not Displaying
- ✅ Check URL rewrite in `/public/.htaccess` line 26
- ✅ Verify data file exists: `/public/includes/service-data/professional-lawn-mowing-care.php`
- ✅ Verify page file exists: `/public/services/professional-lawn-mowing-care.php`
- ✅ Check template file: `/public/includes/service-template.php`

### Quote Form Not Pre-Filling Service
- ✅ Verify CTA URL includes `?service=maintenance`
- ✅ Check `form_presets` in data file
- ✅ Verify quote form reads query parameter: `$_GET['service']`

### Emails Not Sending
- ✅ Verify email templates exist in `/public/crm/email-templates/`
- ✅ Check email automation cron job is running
- ✅ Verify `quote_requests` table has correct `source` value
- ✅ Check email log: `/public/crm/logs/email-*.log`

### Remarketing Not Tracking
- ✅ Verify Google Ads pixel is installed on site
- ✅ Verify Facebook pixel is installed on site
- ✅ Check remarketing audience in Google Ads console
- ✅ Allow 24-48 hours for audience population

---

## 📝 Related Files

| File | Purpose |
|------|---------|
| `/public/includes/service-template.php` | Renders all service landing pages |
| `/public/includes/service-data/` | Data files for all services |
| `/public/services/` | Service page PHP files |
| `/public/.htaccess` | URL rewriting rules |
| `/public/includes/head.php` | Meta tags, schema markup |
| `/public/quote.php` | Quote form handler |
| `/public/crm/api/job-creation.php` | Quote submission backend |

---

## 🚀 Launch Checklist

- [ ] Verify page loads at `/services/professional-lawn-mowing-care`
- [ ] Test "Get a Free Quote" CTA
- [ ] Verify quote form pre-fills with `service=maintenance`
- [ ] Check email templates are configured
- [ ] Set up Google Analytics goal for quote conversion
- [ ] Set up Google Search Console notifications
- [ ] Configure remarketing audiences
- [ ] Test SMS notifications (if enabled)
- [ ] Review copy for accuracy and tone
- [ ] Monitor bounce rate and CTA click rate
- [ ] A/B test headline after 2 weeks if needed

---

## 📞 Support

For questions about:
- **Landing page copy:** Edit data file directly
- **Email automation:** Check CRM email template system
- **Quote form integration:** See `/public/quote.php`
- **URL rewriting:** See `/public/.htaccess`
- **Analytics:** Set up goals in Google Analytics console

---

**Created:** Q1 2026
**Last Updated:** February 10, 2026
**Version:** 1.0 (GSC Integration)
