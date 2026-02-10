# CMS Phase 4 Implementation Guide: Template-Driven Landing Page Generator

## Overview

Phase 4 enables staff to generate complete landing pages in minutes by combining service + neighbourhood + CTA type. The system creates draft pages with pre-filled blocks from templates.

---

## Architecture

### User Workflow

```
CMS → Pages → "Generate from Template" Button
        ↓
    Wizard: Step 1
    • Select Service (dropdown)
    • Select Neighbourhood (autocomplete)
    ↓
    Wizard: Step 2
    • Select CTA Type (Get Quote, Download, Call, etc.)
    • Review template blocks
    ↓
    Click "Generate"
        ↓
    System Creates Draft Page:
    • Hero: "{Service} in {Neighbourhood}, Vancouver"
    • Features: From service template
    • Testimonials: From favorites (if available)
    • FAQ: From service template
    • CTA: Links to /quote?service=X&neighbourhood=Y
    ↓
    Staff Reviews & Publishes
```

### Database Schema

Already created in migration 113:

```sql
cms_pages:
  • is_template_generated BOOLEAN
  • template_source_key VARCHAR(100)
  • generated_variables JSON

cms_page_generator_config:
  • config_key (e.g., "service_landing_base")
  • config_label
  • config_data JSON (template blocks + copy templates)
  • enabled BOOLEAN

cms_page_generations_log:
  • page_id
  • generator_config_id
  • variables JSON
  • generated_at
  • generated_by
```

---

## Implementation Steps

### Step 1: Create Generator Configuration Manager

File: `/public/crm/cms-page-generator-manager.php`

Function:
```php
function cms_getGeneratorConfig(string $configKey): ?array
```

Features:
- Load generator config from DB
- Template variable substitution
- Block template expansion
- Default copy injection

### Step 2: Create Page Generator Engine

File: `/public/crm/includes/page-generator.php`

Functions:
```php
// Generate page from template
function pg_generatePageFromTemplate(
    string $configKey,
    array $variables,  // ['service' => 'lawn-care', 'neighbourhood' => 'burnaby']
    int $userId
): int  // Returns page ID

// Substitute variables in copy
function pg_substituteVariables(string $text, array $variables): string

// Create blocks from templates
function pg_createBlocksFromTemplate(int $pageId, array $blocks): void

// Validate generation inputs
function pg_validateGenerationInputs(array $variables): array  // Returns errors
```

### Step 3: Create Wizard UI

File: `/public/cms/cms-page-generator-wizard.php`

Features:
- Step 1: Service + Neighbourhood selection
- Step 2: CTA type selection
- Step 3: Review generated page content
- Generate button → calls backend API

### Step 4: Create Generation API

File: `/public/crm/api/generate-page.php`

Endpoint: `POST /crm/api/generate-page.php`

Request:
```json
{
  "config_key": "service_landing_base",
  "variables": {
    "service": "lawn-care",
    "service_display": "Lawn Care",
    "neighbourhood": "burnaby",
    "neighbourhood_display": "Burnaby"
  },
  "csrf_token": "..."
}
```

Response:
```json
{
  "success": true,
  "page_id": 42,
  "page_url": "/crm/cms-page-editor.php?id=42",
  "page_title": "Lawn Care in Burnaby, Vancouver",
  "message": "Page generated successfully. 5 blocks created. Ready to edit and publish."
}
```

---

## Template Configuration Format

Store in `cms_page_generator_config.config_data`:

```json
{
  "template_key": "service_landing_base",
  "label": "Service Landing Page",
  "description": "Generate service landing pages for any service + neighbourhood",
  "variables": {
    "service": {
      "type": "select",
      "label": "Service",
      "options": [
        {"key": "lawn-care", "label": "Lawn Care"},
        {"key": "snow-removal", "label": "Snow Removal"},
        {"key": "landscaping", "label": "Landscaping Design"}
      ]
    },
    "neighbourhood": {
      "type": "autocomplete",
      "label": "Neighbourhood",
      "source": "/crm/api/get-neighbourhoods.php"
    },
    "cta_type": {
      "type": "select",
      "label": "Call to Action",
      "options": [
        {"key": "quote", "label": "Get Quote"},
        {"key": "call", "label": "Call Now"},
        {"key": "contact", "label": "Contact Form"}
      ]
    }
  },
  "blocks": [
    {
      "type": "hero",
      "position": 0,
      "config": {
        "headline": "{service_display} in {neighbourhood_display}, Vancouver",
        "subheadline": "Professional {service} services. Free quote available.",
        "cta_text": "Get Your Free Quote",
        "cta_url": "/quote?service={service}&neighbourhood={neighbourhood}",
        "media_id": null  // Use featured image from service template
      }
    },
    {
      "type": "feature_grid",
      "position": 1,
      "config": {
        "title": "Why Choose Our {service_display} Service?",
        "description": "Professional, reliable, affordable.",
        "layout": "3",
        "features": [
          {"title": "Expert Technicians", "description": "Trained and certified professionals"},
          {"title": "Competitive Pricing", "description": "Best value for quality service"},
          {"title": "Guaranteed Results", "description": "100% satisfaction guarantee"}
        ]
      }
    },
    {
      "type": "testimonials",
      "position": 2,
      "config": {
        "title": "What {neighbourhood_display} Homeowners Say",
        "layout": "carousel",
        "testimonials": "{{FETCH:portfolio_testimonials:service={service}&neighbourhood={neighbourhood}&limit=5}}"
      }
    },
    {
      "type": "faq",
      "position": 3,
      "config": {
        "title": "{service_display} FAQs",
        "description": "Common questions about our service",
        "faqs": "{{TEMPLATE:service_faqs:{service}}}"
      }
    },
    {
      "type": "cta",
      "position": 4,
      "config": {
        "headline": "Ready to Get Started?",
        "subheadline": "Contact us today for a free quote",
        "primary_text": "Get Quote",
        "primary_url": "/quote?service={service}&neighbourhood={neighbourhood}",
        "secondary_text": "Call Now",
        "secondary_url": "tel:{{CONSTANT:SITE_PHONE}}",
        "style": "gradient"
      }
    }
  ]
}
```

### Template Substitution Syntax

- `{variable_name}` → Simple variable substitution
- `{{FETCH:table:filter=value&limit=10}}` → Query database for testimonials, etc.
- `{{TEMPLATE:template_key:filter}}` → Load copy from another template
- `{{CONSTANT:CONSTANT_NAME}}` → Inject PHP constants (SITE_PHONE, etc.)

---

## Services Configuration

For each service, create a template entry:

```sql
INSERT INTO cms_page_generator_config (
  config_key, config_label, config_data, enabled
) VALUES (
  'service_template_lawn_care',
  'Lawn Care Service Template',
  '{JSON_CONFIG_HERE}',
  TRUE
);
```

---

## Integration Points

### Portfolio Module

Update `/crm/portfolio/index.php`:
- Add "Feature" star button per photo
- Favorites (⭐) automatically populate testimonials + proof blocks
- Tag photos: Service + Neighbourhood

### Block Renderers

Update `/crm/includes/blocks/testimonials.php`:
- Support `{{FETCH:...}}` syntax
- Query portfolio_testimonials for service + neighbourhood match
- If available, populate block automatically

### Media Selection

When generating pages:
- Auto-select featured image from service template
- Or use newest "featured" photo from portfolio

---

## Phase 4 Implementation Roadmap

### Week 1
- [ ] Create Page Generator Manager (cms-page-generator-manager.php)
- [ ] Create Page Generator Engine (page-generator.php)
- [ ] Seed generator configs for each service

### Week 2
- [ ] Create Wizard UI (cms-page-generator-wizard.php)
- [ ] Create Generation API (/crm/api/generate-page.php)
- [ ] Wire up wizard to button in page list

### Week 3
- [ ] Test generation with all services + neighbourhoods
- [ ] Test template variable substitution
- [ ] Test block creation and default values
- [ ] User testing with staff

### Week 4
- [ ] Performance optimization
- [ ] Error handling & validation
- [ ] Documentation & training

---

## Testing Checklist

- [ ] Service dropdown populated from configs
- [ ] Neighbourhood autocomplete returns results
- [ ] CTA type selection works
- [ ] Generate button creates page with correct title
- [ ] Hero block has correct headline & CTA URL
- [ ] Features block populated from template
- [ ] Testimonials block queries portfolio (if available)
- [ ] FAQ block populated from template
- [ ] Generated page shows "Draft" status
- [ ] Staff can edit and publish generated page
- [ ] Generated page appears in sitemap when published

---

## Success Metrics

- Time to generate page: <10 seconds
- Time for staff to review & publish: <5 minutes
- Total: <15 minutes to create + publish a landing page
- (vs. current: 1-2 hours manual creation)

---

## Next: Phase 5 - Portfolio Integration

After Phase 4, implement:
- Portfolio photo tagging (Service + Neighbourhood)
- Favorites (⭐) auto-populate proof sections
- Case study generation from photo sets

---

**Phase 4 Status**: Schema Ready (migration 113 applied)
**Implementation Target**: 2-3 weeks
**Dependencies**: Phase 1, 2, 3 complete
