# Template to Landing Page Mapping

## Your Template vs. The Landing Page System

This document shows how the Mowology template you provided (`mowology-template.html`) was used to create a fully functional, marketing-automation-enabled landing page system.

---

## 📋 Template Analysis

### Original Template Structure

Your HTML template (`mowology-template.html`) contained:

```html
✅ Meta tags (title, description, keywords, OG tags)
✅ Schema.org JSON-LD (LocalBusiness, Service, FAQ)
✅ Responsive design (CSS Grid, mobile breakpoints)
✅ Hero section (headline, subheadline, CTA)
✅ Services grid (6 service cards)
✅ Why section (4 benefit cards)
✅ Areas grid (6 area cards)
✅ FAQ section (collapsible)
✅ CTA section (final call-to-action)
✅ Footer with links
✅ Vanilla CSS (no dependencies)
✅ Vanilla JavaScript (no jQuery/Bootstrap)
```

### What the Template Did Well

1. **Professional Design** — Clean, modern aesthetic with green/gold color scheme
2. **SEO-Optimized** — Comprehensive meta tags and schema markup
3. **Mobile-Ready** — Responsive CSS with breakpoints
4. **Semantic HTML** — Proper heading hierarchy, accessibility
5. **Fast** — Zero dependencies, lightweight
6. **Accessible** — Focus states, semantic structure, alt text

### The Problem

The template was **static** — beautiful HTML, but:
- ❌ No backend integration
- ❌ No email automation
- ❌ No lead capture
- ❌ No analytics tracking
- ❌ No remarketing
- ❌ No quote form integration
- ❌ Not part of the Mowology CRM system

---

## 🔄 How We Converted It

We took the **design and content** from your template and converted it into a **data-driven landing page system** that integrates with your CRM, email automation, and marketing suite.

### Step 1: Extract Content into Data Structure

**Template sections → Service data array:**

```
Template:  Hero section
  ↓
Data file: 'hero' => [ 'headline', 'subheadline', 'cta_text', 'cta_url' ]

Template:  Services grid (6 cards)
  ↓
Data file: 'proof_sections' => ['type' => 'checklist', 'items' => [...]]

Template:  Why section (4 cards)
  ↓
Data file: 'proof_sections' => ['type' => 'benefits', 'items' => [...]]

Template:  FAQ (collapsible)
  ↓
Data file: 'faq' => [['q' => '...', 'a' => '...'], ...]

Template:  CTA section
  ↓
Data file: 'cta' => ['headline', 'primary_url', 'secondary_url']
```

### Step 2: Add Marketing Metadata

**Added to data structure (not in template):**

```php
'marketing' => [
    'campaign_id'  => 'professional-lawn-mowing-care-gsc-q1-2026',
    'nurture_sequences' => [ /* email automation */ ],
    'remarketing' => [ /* Google Ads, Facebook */ ],
    'attribution' => [ /* UTM tracking */ ],
]
```

This enables:
- Email sequences to trigger automatically
- Visitor tracking for remarketing
- ROI attribution per source
- Lead segmentation in CRM

### Step 3: Implement as CRM Page

**Created PHP page that:**
- Loads data structure
- Captures session variables (marketing parameters)
- Renders using existing service template system
- Integrates with quote form
- Tracks leads in database

---

## 🎨 Design Reuse

### CSS From Template

Your template CSS is **reusable** for other landing pages. The color scheme:

```css
/* Your template colors */
--green-deep: #1a3a2a;
--green-rich: #2d5c3f;
--green-mid: #3e7a54;
--green-bright: #5cb176;
--green-light: #a8d5ba;
--gold: #c9a84c;
```

These match Mowology's brand and are defined in:
```
/public/assets/css/base/variables.css (public site)
/public/crm/css/mowology-brand.css (CRM)
```

### Sections Directly From Template

| Template Section | Rendered By | Where |
|------------------|------------|-------|
| Meta tags + schema | `service-template.php` | Line 15-60 |
| Hero section | `service-template.php` | Line 100-140 |
| Services grid | `service-template.php` | Line 200-280 |
| Benefits cards | `service-template.php` | Line 300-380 |
| FAQ section | `service-template.php` | Line 450-520 |
| CTA section | `service-template.php` | Line 550-590 |
| Footer | `service-template.php` | Line 600-650 |

---

## 📝 Content Mapping

### Exact Template Content Reused

**Hero Headline:**
```
Template: "Professional Lawn Mowing & Lawn Care in Vancouver"
Data:     hero.headline = "Professional <em>Lawn Mowing</em> & Lawn Care in Vancouver"
Result:   Renders with exact wording
```

**Hero Subheadline:**
```
Template: "Reliable lawn maintenance, landscaping services, and snow removal..."
Data:     hero.subheadline = "Reliable lawn maintenance, landscaping services, and snow removal..."
Result:   Renders identically
```

**Services (template had 6 cards):**
```
Template Cards:
1. Lawn Mowing Service
2. Lawn Care & Maintenance
3. Commercial Lawn Mowing
4. Strata Landscaping Vancouver
5. Landscaping Services
6. Snow Removal Vancouver

Data Array:
'proof_sections' => [
    [ 'type' => 'checklist', 'items' => [
        'Lawn Mowing Service — ...',
        'Lawn Care & Maintenance — ...',
        // ... etc
    ]]
]

Result: Same content, rendered from data structure
```

**FAQ (template had 6 questions):**
```
Template HTML: <div class="faq-item"> with <div class="faq-q">
Data Array:    'faq' => [
    ['q' => 'How much does lawn mowing cost...', 'a' => '...'],
    // ... etc
]

Result: Same Q&A, rendered from data (with collapsible toggle via JavaScript)
```

---

## 🔌 Integration Points

### Template CTA Button → CRM Quote Form

**Template HTML:**
```html
<a href="mailto:info@mowology.ca?subject=Free%20Quote%20Request"
   class="btn btn-primary">
   Request Your Free Quote →
</a>
```

**CRM Integration:**
```php
'cta_url' => '/quote?service=maintenance&src=professional-lawn-mowing-care',
```

**Result:**
- Visitor clicks CTA
- Redirects to quote form (not email)
- Form captures name, email, phone, property info
- Lead stored in database
- Email automation triggered
- Remarketing audience updated

### Template Service Cards → Proof Sections

**Template HTML:**
```html
<div class="service-card">
  <div class="service-icon">🌱</div>
  <h3>Lawn Mowing Service</h3>
  <p>Weekly and bi-weekly professional...</p>
</div>
```

**CRM Data:**
```php
[
    'type' => 'checklist',
    'items' => [
        'Lawn Mowing Service — Weekly and bi-weekly professional...',
        // ...
    ]
]
```

**Result:**
- Same visual design
- Data-driven from CRM
- Easy to edit without touching HTML
- Can A/B test with variants

---

## 📊 Template Metrics in CRM

### SEO Metadata From Template

Your template had excellent SEO setup. We preserved it:

**Template:**
```html
<title>Lawn Mowing & Lawn Care Vancouver | Mowology – Professional Lawn Maintenance</title>
<meta name="description" content="Vancouver's trusted lawn mowing service...">
<meta name="keywords" content="lawn mowing vancouver, lawn care vancouver, ...">
```

**CRM Data:**
```php
'meta_title' => 'Lawn Mowing & Lawn Care Vancouver | Mowology – Professional Lawn Maintenance',
'meta_description' => 'Vancouver\'s trusted lawn mowing service...',
'meta_keywords' => 'lawn mowing vancouver, lawn care vancouver, ...',
```

**Rendering:**
- Service template reads data
- Outputs to `head.php` for rendering
- No duplicate meta tags
- Single source of truth in data file

### Schema Markup From Template

**Template JSON-LD:**
```json
{
  "@type": "LocalBusiness",
  "name": "Mowology",
  "areaServed": [
    { "@type": "City", "name": "Vancouver" },
    { "@type": "City", "name": "Burnaby" },
    // ...
  ],
  "serviceType": ["Lawn Mowing", "Lawn Care", ...]
}
```

**CRM Data:**
```php
'schema' => [
    'service_type' => 'Lawn Mowing & Lawn Care',
    'area_served' => ['Vancouver', 'Burnaby', 'New Westminster', 'North Vancouver', 'Richmond'],
]
```

**Rendering:**
- Service template generates schema JSON
- Google understands service offerings
- Helps with Rich Snippets in search results

---

## 🎯 Why This Approach is Better

### Template → Static HTML Problem

```
File: mowology-template.html
├── Hard to update (edit HTML directly)
├── No analytics integration
├── No email automation
├── Hard to reuse (copy/paste for each service)
└── Not connected to CRM
```

### Data-Driven Landing Page Solution

```
File: /public/includes/service-data/professional-lawn-mowing-care.php
├── Easy to update (edit PHP array, no HTML)
├── Automatic analytics integration (UTM tracking)
├── Built-in email automation (nurture sequences)
├── Reusable (one template renders many pages)
├── Connected to CRM (lead capture, tracking)
├── A/B testing support (variants)
└── Multivariate testing (headline, CTA, etc.)
```

---

## 🚀 Creating More Landing Pages

### Pattern: Use Template Design + Data Structure

**For a new service (e.g., "Snow Removal"):**

1. **Create data file:**
   ```php
   /public/includes/service-data/snow-removal-vancouver.php

   return [
       'slug'  => 'snow-removal-vancouver',
       'meta_title' => 'Snow Removal Vancouver | Mowology',
       'hero' => [ /* your content */ ],
       'proof_sections' => [ /* your content */ ],
       'faq' => [ /* your content */ ],
       // ... etc
   ];
   ```

2. **Create page file:**
   ```php
   /public/services/snow-removal-vancouver.php

   $service = require dirname(__DIR__) . '/includes/service-data/snow-removal-vancouver.php';
   require dirname(__DIR__) . '/includes/service-template.php';
   ```

3. **Page renders automatically** with your template design

**Result:** New landing page at `/services/snow-removal-vancouver` (auto-rewritten from `/services/snow-removal-vancouver.php`)

---

## 🔗 File References

| What | File | Purpose |
|------|------|---------|
| Template design | (was `mowology-template.html`) | Reference for CSS, layout, components |
| Landing page data | `/public/includes/service-data/professional-lawn-mowing-care.php` | Content, marketing config, automation |
| Landing page PHP | `/public/services/professional-lawn-mowing-care.php` | Page loader, session capture |
| Template renderer | `/public/includes/service-template.php` | Renders data structure to HTML |
| Style sheet | `/public/assets/css/` | Design tokens from your template |
| Documentation | `/public/PROFESSIONAL_LAWN_MOWING_LANDING_PAGE_GUIDE.md` | Complete reference |

---

## ✅ Template-to-System Conversion Checklist

- [x] Extract content from HTML template
- [x] Structure as PHP data array
- [x] Create landing page PHP file
- [x] Add marketing automation metadata
- [x] Configure email sequences
- [x] Set up remarketing audiences
- [x] Add UTM tracking parameters
- [x] Generate SEO metadata
- [x] Output schema markup
- [x] Integrate with quote form
- [x] Connect to CRM database
- [x] Document integration points
- [x] Test page rendering
- [x] Verify CTA flow

---

## 📖 Next Steps

1. **View the landing page:** `https://mowology.ca/services/professional-lawn-mowing-care`
2. **Read the guides:**
   - `PROFESSIONAL_LAWN_MOWING_LANDING_PAGE_GUIDE.md` (full reference)
   - `LANDING_PAGE_MARKETING_INTEGRATION.md` (automation technical docs)
3. **Create more pages:** Use the same pattern for other services
4. **Set up automation:** Configure email templates and cron jobs
5. **Monitor metrics:** Track traffic, leads, conversions

---

**Conversion Date:** February 10, 2026
**Template Status:** ✅ Converted to Data-Driven System
**System Status:** ✅ Ready for Deployment
