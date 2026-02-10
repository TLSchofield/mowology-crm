# CMS Phase 5 Implementation Guide: Portfolio → Marketing Integration

## Overview

Phase 5 connects your job portfolio to the CMS marketing system. Staff can tag job photos by service + neighbourhood, mark favorites (⭐), and automatically populate proof sections or generate case studies.

---

## Architecture

### User Workflow

```
Portfolio Module (Job Photos)
    ↓
1. Tag Each Photo
   • Service: Lawn Care, Snow Removal, etc.
   • Neighbourhood: Burnaby, Richmond, etc.
   • Mark as ⭐ Featured

2. Featured Photos Auto-Populate CMS
   • Service Landing Pages → Proof sections use featured photos
   • Gallery blocks → Auto-filled with best shots
   • Testimonials → Linked to job context

3. Generate Case Studies
   • Select 5-10 photos from same job
   • Click "Generate Case Study Page"
   • System creates draft page with:
     - Hero: Before/After images
     - Photos gallery
     - Job details
     - CTA to request quote
   ↓
   Staff reviews & publishes
```

---

## Database Schema

Created in migration 114:

```sql
portfolio_photos:
  • service_key VARCHAR(100)          -- Service type
  • neighbourhood_key VARCHAR(100)    -- Location
  • is_featured BOOLEAN               -- Mark as featured
  • featured_order INT                -- Display order
  • created_at_photo TIMESTAMP        -- Photo date
  • updated_at_photo TIMESTAMP        -- Last modified

cms_case_studies_generated:
  • page_id INT → cms_pages
  • job_id INT → jobs
  • service_key, neighbourhood_key
  • photo_count, generated_at, published

v_portfolio_featured_photos:
  • View for querying featured photos by service/neighbourhood
```

---

## Implementation Steps

### Step 1: Update Portfolio Photo Editor

File: `/public/crm/portfolio/index.php` (or photo editor)

Add fields to photo editing form:
```php
<!-- Service Dropdown -->
<select name="service_key" class="form-control">
    <option value="">-- Not Tagged --</option>
    <option value="lawn-care">Lawn Care</option>
    <option value="snow-removal">Snow Removal</option>
    <option value="landscaping">Landscaping Design</option>
</select>

<!-- Neighbourhood Dropdown (with autocomplete) -->
<input type="text" name="neighbourhood_key" class="form-control neighbourhood-autocomplete"
       placeholder="Search neighbourhoods...">

<!-- Featured Toggle -->
<label>
    <input type="checkbox" name="is_featured" value="1">
    ⭐ Featured Photo (use in proof sections)
</label>

<!-- Featured Order (if featured) -->
<input type="number" name="featured_order" class="form-control" placeholder="Order (1, 2, 3, ...)">
```

### Step 2: Create Portfolio API Endpoint

File: `/public/crm/api/get-featured-photos.php`

```php
/**
 * Get featured photos by service + neighbourhood
 *
 * Query params:
 *   - service: Service key (required)
 *   - neighbourhood: Neighbourhood key (required)
 *   - limit: Max photos to return (default 12)
 */
```

Endpoint: `GET /crm/api/get-featured-photos.php?service=lawn-care&neighbourhood=burnaby&limit=6`

Response:
```json
{
  "success": true,
  "count": 5,
  "photos": [
    {
      "id": 1,
      "photo_path": "/uploads/portfolio/photo-1.jpg",
      "thumb_path": "/uploads/portfolio/thumb-1.jpg",
      "alt_text": "Lawn care in Burnaby - before and after",
      "featured_order": 1,
      "job_title": "Residential Lawn Maintenance"
    }
  ]
}
```

### Step 3: Update Block Renderers

File: `/public/crm/includes/blocks/gallery.php` or similar

Add support for auto-populated images:

```php
// If config['auto_populate_service'] is set
if (!empty($config['auto_populate_service'])) {
    $featuredPhotos = getPortfolioFeaturedPhotos(
        $config['auto_populate_service'],
        $config['auto_populate_neighbourhood'] ?? null
    );

    if (!empty($featuredPhotos)) {
        // Use featured photos instead of manual images
        foreach ($featuredPhotos as $photo) {
            renderResponsiveImage($photo['id'], $photo);
        }
    }
}
```

### Step 4: Create Case Study Generator

File: `/public/crm/portfolio/case-study-generator.php`

Wizard UI:
```
Step 1: Select Job
  • Dropdown of completed jobs
  • Show thumbnail preview

Step 2: Select Photos
  • Checkbox list of photos from job
  • Show preview grid
  • Require minimum 3 photos

Step 3: Configure Page
  • Title: "Case Study: {Job Title}"
  • Service + Neighbourhood auto-detect from job
  • Optional: Edit headline, description

Step 4: Review & Generate
  • Show page preview
  • Click "Generate" button
```

### Step 5: Create Case Study API

File: `/public/crm/api/generate-case-study.php`

```php
/**
 * Generate case study page from job photos
 *
 * POST /crm/api/generate-case-study.php
 *
 * Request:
 * {
 *   "job_id": 42,
 *   "photo_ids": [1, 2, 3, 4, 5],
 *   "title": "Custom Title (optional)",
 *   "csrf_token": "..."
 * }
 *
 * Response:
 * {
 *   "success": true,
 *   "page_id": 99,
 *   "page_url": "/crm/cms-page-editor.php?id=99",
 *   "message": "Case study created with 5 photos. Ready to edit and publish."
 * }
 */
```

---

## Generated Page Structure

### Case Study Page Template

```
Hero Block:
  • Image: First photo from set
  • Headline: "Before & After: {Job Title}"
  • CTA: "Request Similar Service"

Gallery Block:
  • All selected photos in grid
  • Captions: Auto-extracted alt text
  • Layout: 3-column grid

Details Block:
  • Service: {Auto-detected}
  • Location: {Auto-detected}
  • Date: {Job completion date}
  • Duration: {Job duration if tracked}

Testimonial Block (if exists):
  • From job record if customer provided feedback
  • Or: "Ask us for references"

CTA Block:
  • Headline: "Ready for Your Project?"
  • Primary CTA: "Request Free Quote"
  • Secondary: "View Our Portfolio"
```

---

## Integration Points

### Portfolio Module (`/crm/portfolio/`)

Changes needed:
```php
// Photo editing form
- Add service_key, neighbourhood_key fields
- Add is_featured checkbox
- Add featured_order input

// Photo list
- Show service + neighbourhood tags
- Show ⭐ badge for featured photos
- Add "Generate Case Study" button (multi-select)

// Photo upload
- Suggest service + neighbourhood based on job
- Allow bulk tagging of photos from same job
```

### CMS Module

Changes needed:
```php
// Page editor (cms-page-editor.php)
- Add "auto_populate_service" & "auto_populate_neighbourhood" config
- When set, block renders featured photos automatically

// Block editor (cms-block-editor.php)
- Add "Use Featured Photos" toggle on gallery blocks
- Show available services + neighbourhoods
- Preview featured photo count
```

### Block Renderers

Changes needed:
```php
// gallery.php, testimonials.php, etc.
- Support auto-population from portfolio
- Query featured photos by service/neighbourhood
- Fall back to manual photos if no featured photos available
```

---

## Database Queries

### Get Featured Photos by Service + Neighbourhood

```sql
SELECT * FROM v_portfolio_featured_photos
WHERE service_key = 'lawn-care'
  AND neighbourhood_key = 'burnaby'
ORDER BY featured_order ASC
LIMIT 6;
```

### Get All Services with Featured Photos

```sql
SELECT DISTINCT service_key, COUNT(*) as count
FROM portfolio_photos
WHERE is_featured = TRUE
GROUP BY service_key
ORDER BY count DESC;
```

### Get All Neighbourhoods with Featured Photos

```sql
SELECT DISTINCT neighbourhood_key, COUNT(*) as count
FROM portfolio_photos
WHERE is_featured = TRUE
GROUP BY neighbourhood_key
ORDER BY count DESC;
```

---

## Phase 5 Implementation Roadmap

### Week 1: Foundation
- [ ] Apply migration 114
- [ ] Add service_key, neighbourhood_key fields to portfolio UI
- [ ] Create /crm/api/get-featured-photos.php endpoint
- [ ] Test endpoint with sample data

### Week 2: Integration
- [ ] Update block renderers to support auto-population
- [ ] Test gallery blocks with featured photos
- [ ] Update testimonials to link to portfolio
- [ ] Test end-to-end flow

### Week 3: Case Studies
- [ ] Create case study wizard UI
- [ ] Create /crm/api/generate-case-study.php endpoint
- [ ] Test case study generation
- [ ] User testing with staff

### Week 4: Polish & Deploy
- [ ] Performance optimization
- [ ] Error handling & edge cases
- [ ] Documentation & training
- [ ] Production deployment

---

## Testing Checklist

**Portfolio Tagging:**
- [ ] Tag photos with service + neighbourhood
- [ ] Mark photos as featured
- [ ] Set featured order
- [ ] Save and reload photo → data persists

**API Endpoint:**
- [ ] Query /crm/api/get-featured-photos.php with valid params
- [ ] Verify photos returned in correct order
- [ ] Test pagination (if implemented)
- [ ] Test with no results

**Block Rendering:**
- [ ] Create gallery block with auto-population
- [ ] Publish page with gallery
- [ ] Visit public page → featured photos display
- [ ] Test responsive image rendering
- [ ] Test fallback to manual photos

**Case Study Generation:**
- [ ] Generate case study from 5 photos
- [ ] Verify page created with correct structure
- [ ] Verify photos appear in gallery
- [ ] Verify service/neighbourhood auto-detected
- [ ] Verify CTA links are correct
- [ ] Edit and publish case study
- [ ] View published case study on site

---

## Success Metrics

- **Time to tag job photos**: <5 minutes (batch tagging available)
- **Time to generate case study**: <2 minutes (automated)
- **Reuse of portfolio content**: 80%+ of featured photos used in marketing pages
- **Case studies generated per month**: 10+ (vs. 0 manual before)
- **Organic traffic improvement**: 20-30% increase to case study pages

---

## Next Steps: Phase 6 - AI Enhancement

After Phase 5, optional AI enhancements:
- Auto-generate meta descriptions using Claude API
- Auto-generate alt text from portfolio images
- Generate testimonial text from job details
- Generate service copy from portfolio patterns

---

## Implementation Files Summary

**To Create:**
- `/public/crm/portfolio/case-study-generator.php` — Wizard UI
- `/public/crm/api/get-featured-photos.php` — Featured photos endpoint
- `/public/crm/api/generate-case-study.php` — Case study generation API

**To Update:**
- `/public/crm/portfolio/index.php` — Add photo tagging UI
- `/public/crm/includes/blocks/gallery.php` — Auto-population support
- `/public/crm/includes/blocks/testimonials.php` — Portfolio linking
- `/public/cms/cms-page-editor.php` — Auto-population config UI
- `/public/cms/cms-block-editor.php` — Service/neighbourhood selectors

**Database:**
- `database/migrations/114_cms_phase5_portfolio_integration.sql` — Already created

---

## Configuration

### Services List

Store in database or config:
```php
$SERVICES = [
    'lawn-care' => 'Lawn Care',
    'snow-removal' => 'Snow Removal',
    'landscaping' => 'Landscaping Design',
    'maintenance' => 'Property Maintenance',
];
```

### Neighbourhoods List

Query from jobs or store in config:
```php
// Get from existing data
SELECT DISTINCT neighbourhood FROM jobs WHERE status = 'completed';

// Or hardcode:
$NEIGHBOURHOODS = [
    'burnaby' => 'Burnaby',
    'richmond' => 'Richmond',
    'vancouver' => 'Vancouver',
    'coquitlam' => 'Coquitlam',
    'surrey' => 'Surrey',
];
```

---

## Troubleshooting

| Issue | Solution |
|-------|----------|
| Featured photos not appearing | Check is_featured = 1, featured_order is set |
| Photos in wrong order | Verify featured_order values, update them |
| Case study not generating | Check photo_ids are valid, job_id exists |
| Auto-population not working | Verify service_key matches exactly, check UI implementation |

---

**Phase 5 Status**: Ready to build (schema complete)
**Estimated Implementation Time**: 3-4 weeks
**Dependencies**: Phase 1, 2, 3, 4 complete
