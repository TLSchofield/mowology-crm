# Landing Page Quick Start Guide

## In 5 Minutes

### 1. View the Live Page
```
https://mowology.ca/services/professional-lawn-mowing-care
```

You should see:
- ✅ Professional hero section with headline and subheadline
- ✅ Green and gold color scheme
- ✅ "Get a Free Quote →" button
- ✅ Benefits section
- ✅ Services checklist
- ✅ FAQ section (collapsible)
- ✅ Final CTA section

### 2. Test the CTA
1. Click "Get a Free Quote →"
2. You'll be redirected to `/quote?service=maintenance&src=professional-lawn-mowing-care`
3. The quote form should have "Lawn Mowing" pre-selected

### 3. Check the Data File
```
/public/includes/service-data/professional-lawn-mowing-care.php
```

This file contains everything displayed on the page:
- Headlines and subheadings
- Service descriptions
- FAQ questions and answers
- Marketing configuration

### 4. Understand the System
```
Data File                              Page File                            Display
professional-lawn-mowing-care.php  →  professional-lawn-mowing-care.php → renders at /services/...
(contains copy & config)               (loads data & starts automation)    (beautiful HTML)
```

---

## How to Edit

### Change the Headline

**File:** `/public/includes/service-data/professional-lawn-mowing-care.php`

**Find:**
```php
'hero' => [
    'headline'    => 'Professional <em>Lawn Mowing</em> & Lawn Care in Vancouver',
```

**Change to:**
```php
'hero' => [
    'headline'    => 'Your new headline here',
```

**Save and refresh the page** — it updates immediately.

### Add a New FAQ Question

**File:** Same as above

**Find:**
```php
'faq' => [
    ['q' => 'How much does lawn mowing cost in Vancouver?', 'a' => '...'],
    // ... existing questions ...
],
```

**Add:**
```php
    ['q' => 'Your new question here?', 'a' => 'Your answer here.'],
```

**Save and refresh** — new question appears in FAQ.

### Change the CTA Button URL

**File:** Same as above

**Find:**
```php
'cta_url' => '/quote?service=maintenance&src=professional-lawn-mowing-care',
```

**Change to:**
```php
'cta_url' => 'https://calendly.com/mowology/consultation',
```

**Save and refresh** — CTA now links to new URL.

---

## How to Monitor Performance

### Google Analytics
1. Go to Google Analytics dashboard
2. Filter for: `/services/professional-lawn-mowing-care`
3. Check: Bounce rate, Avg. session duration, CTA clicks

### Quote Form
1. Go to CRM dashboard
2. Look for quotes with `source = 'professional-lawn-mowing-care'`
3. Track conversion rate (quotes → customers)

### Email Tracking
1. Check email service dashboard (if using Mailchimp, ConvertKit, etc.)
2. Monitor: Open rate, click rate, unsubscribe rate

---

## How to Create Similar Pages

### For Another Service (e.g., Snow Removal)

1. **Copy the data file:**
   ```bash
   cp /public/includes/service-data/professional-lawn-mowing-care.php \
      /public/includes/service-data/snow-removal-vancouver.php
   ```

2. **Create the page file:**
   ```bash
   cat > /public/services/snow-removal-vancouver.php << 'EOF'
   <?php
   declare(strict_types=1);
   require_once dirname(__DIR__) . '/includes/bootstrap.php';

   $service = require dirname(__DIR__) . '/includes/service-data/snow-removal-vancouver.php';
   require dirname(__DIR__) . '/includes/service-template.php';
   EOF
   ```

3. **Edit the data file** to update:
   - `slug`, `title`, `meta_title`, `meta_description`
   - `hero.headline`, `hero.subheadline`
   - `proof_sections` (benefits, services, process)
   - `faq` questions and answers
   - `form_presets['service']` = 'snow-removal' (or whatever)
   - `marketing.campaign_id`, `source_tag`, etc.

4. **New page appears at:**
   ```
   https://mowology.ca/services/snow-removal-vancouver
   ```

---

## Email Automation (Optional Setup)

If you want automatic emails when someone requests a quote:

### What Happens
```
Visitor requests quote
         ↓
Lead stored in database
         ↓
2 hours later: Auto-send "Quote Followup" email
         ↓
7 days later: Auto-send "Benefits" email
         ↓
14 days later: Auto-send "Pricing" email
```

### To Enable
1. **Create email templates** (these files need to exist):
   - `/public/crm/email-templates/quote-followup-lawn-mowing.html`
   - `/public/crm/email-templates/nurture-lawn-care-benefits.html`
   - `/public/crm/email-templates/nurture-lawn-care-pricing.html`

2. **Enable in config** (add to `/public/app_config/config.php`):
   ```php
   define('MARKETING_AUTOMATION_ENABLED', true);
   define('EMAIL_AUTOMATION_ENABLED', true);
   ```

3. **Set up cron job** (ask hosting support to add):
   ```bash
   0 * * * * /usr/bin/php /home/mowology/public/crm/api/email-automation-cron.php
   ```

4. **Monitor** (check logs):
   ```bash
   tail -50 /public/crm/logs/email-automation.log
   ```

---

## Remarketing (Optional Setup)

If you want Google Ads and Facebook to show ads to visitors who didn't convert:

### What Happens
```
Visitor lands on page
         ↓
Tracking pixel fires
         ↓
Visitor added to "gsc-lawn-mowing-vancouver" audience
         ↓
Display ads shown on Google Display Network
Display ads shown on Facebook/Instagram
         ↓
If visitor returns and converts: Audience updated
```

### To Enable
1. **Google Ads pixel:** Already installed if GA4 configured
2. **Facebook pixel:** Add to `/public/includes/head.php`
3. **Create audience in Google Ads:**
   - Go to Audiences → New Audience
   - Name: "gsc-lawn-mowing-vancouver"
   - Source: Website
   - Add condition: Pages visited contains "/services/professional-lawn-mowing-care"
   - Size: Should grow to 50+ over 30 days

4. **Create audience in Facebook:**
   - Go to Audiences → Create Audience
   - Type: Custom Audience
   - Source: Website traffic
   - Add condition: Pages contain "/services/professional-lawn-mowing-care"

---

## Troubleshooting

### Page shows 404
- Verify file exists: `/public/services/professional-lawn-mowing-care.php`
- Check `.htaccess` rewrite rule is correct
- Test direct PHP: Visit `/services/professional-lawn-mowing-care.php` directly

### Quote form doesn't pre-fill service
- Verify CTA URL has `?service=maintenance`
- Check quote form reads the query parameter
- Verify hidden field in form: `<input type="hidden" name="service" value="...">`

### Emails not sending
- Create template files (they need to exist)
- Check email logs: `/public/crm/logs/`
- Test manually: Run cron job directly
- Verify SMTP credentials in config

### Google Analytics not tracking
- Verify GA4 property ID is correct
- Check events in GA4 Real-time
- Wait 24 hours for data to populate

---

## Key Files Reference

| File | What | Edit to... |
|------|------|-----------|
| `/public/includes/service-data/professional-lawn-mowing-care.php` | All content | Change headlines, add FAQ, edit copy |
| `/public/services/professional-lawn-mowing-care.php` | Page loader | Usually don't edit |
| `/public/includes/service-template.php` | Renders data to HTML | Usually don't edit |
| `/public/.htaccess` | URL rewriting | Usually don't edit |

---

## Performance Benchmarks (First Month)

| Metric | Expected |
|--------|----------|
| Page views | 50-150 |
| Bounce rate | < 40% |
| Avg. session duration | 1-2 minutes |
| CTA click-through | 5-10% |
| Quote form conversion | 20-30% of CTA clicks |
| Email open rate | 20-30% |

---

## Next Steps

1. **Today:** View the page and click the CTA
2. **Tomorrow:** Edit a headline or FAQ question
3. **This week:** Set up email templates (optional)
4. **Next week:** Create a similar page for another service
5. **Within 30 days:** Monitor traffic and leads

---

## Support & Resources

- **Full reference guide:** `/public/PROFESSIONAL_LAWN_MOWING_LANDING_PAGE_GUIDE.md`
- **Email automation guide:** `/public/LANDING_PAGE_MARKETING_INTEGRATION.md`
- **Template explanation:** `/TEMPLATE_TO_LANDING_PAGE_MAPPING.md`
- **CRM architecture:** `/CLAUDE.md` (search "Service Landing Pages")

---

## One-Liner Commands

**View the page:**
```bash
open https://mowology.ca/services/professional-lawn-mowing-care
```

**Edit the copy:**
```bash
nano /Users/timschofield/Projects/mowology-crm/public/includes/service-data/professional-lawn-mowing-care.php
```

**View data structure:**
```bash
cat /Users/timschofield/Projects/mowology-crm/public/includes/service-data/professional-lawn-mowing-care.php | head -50
```

**Check if page file exists:**
```bash
ls -l /Users/timschofield/Projects/mowology-crm/public/services/professional-lawn-mowing-care.php
```

---

**Status:** ✅ Ready to Use
**Last Updated:** February 10, 2026
**Need Help?** See the full guides in `/public/` directory
