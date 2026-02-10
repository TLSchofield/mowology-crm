# CMS Template Library System Guide

## Overview

The CMS Template Library provides a **complete system for storing, managing, and reusing adjustable page and block templates**. This enables:

✅ **Rapid page creation** — Create new pages in minutes from templates
✅ **Brand consistency** — Ensure all pages follow your design system
✅ **Adjustable templates** — Lock certain fields, allow customization of others
✅ **Template versioning** — Track changes and rollback if needed
✅ **Performance tracking** — See which templates are most effective
✅ **Template groups** — Organize templates into logical collections
✅ **Presets** — Save common customizations for quick reuse
✅ **Export/Import** — Share templates between environments

---

## Core Concepts

### 1. Page Templates

A **page template** is a blueprint for creating new pages. It includes:

- **Layout** — Which HTML layout to use (default, service_landing, etc.)
- **Default metadata** — Template title, description, image
- **Block structure** — Which blocks to include (hero, features, FAQ, CTA)
- **Editable fields** — Which block fields users can customize
- **Locked fields** — Which fields are protected from changes

**Example: "Service Landing Page Template"**
```
Layout: service_landing
Blocks:
  1. Hero (editable: headline, CTA) ← admin can change
  2. Features (locked: layout) ← cannot change
  3. Portfolio (editable: media_ids) ← can change images
  4. FAQ (editable: questions) ← can customize FAQ
  5. CTA (editable: primary_url) ← can change link
```

### 2. Block Templates

A **block template** is a reusable block configuration. It includes:

- **Default configuration** — What the block looks like
- **Editable fields** — Which settings users can change
- **Locked fields** — Which settings are protected
- **Performance data** — How well this block performs

**Example: "Hero Banner for Service Pages"**
```
Block type: hero
Default config:
  - headline: "Professional [Service]"
  - subheadline: "Serving Vancouver area"
  - cta_text: "Get Free Quote"
  - cta_url: "/quote"
  - media_id: [default image]

Editable fields:
  - headline
  - subheadline
  - cta_url
  - media_id

Locked fields:
  - colors (must match brand)
  - layout (must be full-width)

Performance:
  - Average CTR: 3.2%
  - Used in: 45 pages
```

### 3. Template Presets

A **preset** is a saved customization of a template. It lets you reuse the same combination of changes.

**Example: "Service Landing - Strata Maintenance (Vancouver)"**
```
Base template: Service Landing Page v1
Customizations:
  - Hero headline: "Professional Strata Landscaping in Vancouver"
  - Hero cta_url: "/quote?service=strata"
  - Portfolio filter: service="strata", location="vancouver"
  - FAQ includes: Top 5 questions about strata maintenance

When user creates page:
  → Select "Service Landing - Strata Maintenance (Vancouver)" preset
  → New page created with all these customizations applied instantly
```

### 4. Template Groups

A **group** is a collection of related templates.

**Example Groups:**
- "Service Landing Suite" — All templates for service pages
- "Location Landing Collection" — All templates for location pages
- "Homepage Bundle" — Homepage + related components
- "Blog Article Templates" — Blog post variations

---

## Database Schema

### Main Tables

```
cms_page_templates
├─ template_key (unique)
├─ label, description
├─ layout_template, page_type
├─ blocks_config_json (array of blocks)
├─ page_metadata_defaults_json
├─ version
├─ usage_count, last_used_at
└─ is_locked (system templates)

cms_page_template_versions
├─ page_template_id (FK)
├─ version
├─ blocks_config_json (snapshot)
└─ change_summary

cms_block_templates
├─ block_template_key (unique)
├─ label, description
├─ block_type (hero, feature_grid, etc.)
├─ default_config_json
├─ editable_fields_json
├─ locked_fields_json
├─ version
├─ usage_count
├─ performance_data_json
└─ is_locked

cms_block_template_versions
├─ block_template_id (FK)
├─ version
├─ default_config_json (snapshot)
└─ change_summary

cms_template_presets
├─ preset_key (unique)
├─ label, description
├─ base_page_template_id
├─ base_block_template_id
├─ customization_config_json (overrides)
└─ applied_to_count

cms_template_groups
├─ group_key (unique)
├─ label, description
├─ group_type (page_templates|block_templates|mixed)
├─ is_featured
└─ documentation_url

cms_template_group_members
├─ group_id (FK)
├─ page_template_id (FK, nullable)
├─ block_template_id (FK, nullable)
└─ position

cms_pages_template_audit
├─ page_id (FK)
├─ page_template_id (FK)
├─ block_template_ids_json
├─ template_version_used
└─ snapshot_of_template_config

cms_template_performance
├─ page_template_id (FK, nullable)
├─ block_template_id (FK, nullable)
├─ date
├─ pages_created, pages_published
├─ avg_page_views, avg_conversion_rate
├─ avg_ctr, avg_engagement
└─ notes
```

---

## Usage Examples

### Create a Service Landing Template

```php
$templateId = cms_savePageTemplate([
    'template_key' => 'service_landing_v2',
    'label' => 'Service Landing Page',
    'description' => 'Full featured service landing page',
    'layout_template' => 'service_landing',
    'page_type' => 'service_landing',
    'category' => 'landing',
    'use_case' => 'Create new service pages',

    // Block structure
    'blocks_config' => [
        [
            'type' => 'hero',
            'position' => 0,
            'label' => 'Hero Banner',
            'template_key' => 'hero_service_landing',
            'editable_fields' => ['headline', 'subheadline', 'cta_url'],
            'locked_fields' => ['media_position', 'height'],
        ],
        [
            'type' => 'feature_grid',
            'position' => 1,
            'label' => 'Benefits',
            'template_key' => 'benefits_features',
            'editable_fields' => ['heading', 'features'],
            'locked_fields' => ['layout'],  // Must be 3 columns
        ],
        [
            'type' => 'gallery',
            'position' => 2,
            'label' => 'Portfolio',
            'template_key' => 'portfolio_grid_6',
            'editable_fields' => ['media_ids'],
            'locked_fields' => [],
        ],
        [
            'type' => 'faq',
            'position' => 3,
            'label' => 'FAQ',
            'template_key' => 'faq_standard',
            'editable_fields' => ['faqs'],
            'locked_fields' => [],
        ],
        [
            'type' => 'cta',
            'position' => 4,
            'label' => 'Final CTA',
            'template_key' => 'cta_final_call',
            'editable_fields' => ['primary_url'],
            'locked_fields' => [],
        ],
    ],

    // Default page metadata
    'page_metadata_defaults' => [
        'meta_title' => '[Service Name] | Mowology Landscaping',
        'meta_description' => 'Professional landscaping services in Vancouver',
        'og_image_path' => '/assets/images/og-service.jpg',
    ],
], $userId);
```

### Create a Block Template

```php
$blockTemplateId = cms_saveBlockTemplate([
    'block_template_key' => 'hero_service_landing',
    'label' => 'Service Hero Banner',
    'description' => 'Large hero banner for service pages',
    'block_type' => 'hero',
    'category' => 'hero',

    // Default configuration
    'default_config' => [
        'headline' => 'Professional [Service Name]',
        'subheadline' => 'Serving the Vancouver area',
        'cta_text' => 'Get Free Quote',
        'cta_url' => '/quote',
        'media_id' => 123,  // Default image
        'media_alt' => 'Service landscape',
    ],

    // Which fields can users customize
    'editable_fields' => [
        'headline',
        'subheadline',
        'cta_text',
        'cta_url',
        'media_id',
        'media_alt',
    ],

    // Which fields are locked (cannot change)
    'locked_fields' => [
        'height',  // Must be 400px
        'text_position',  // Must be center
    ],
], $userId);
```

### Create a Page from Template

```php
$pageId = cms_createPageFromTemplate(
    'service_landing_v2',  // Template key
    [
        // Page overrides
        'slug' => 'strata-landscaping-maintenance',
        'title' => 'Strata Landscaping Maintenance Services',
        'meta_title' => 'Professional Strata Maintenance | Mowology',
        'meta_description' => 'Expert strata landscaping in Vancouver',
    ],
    [
        // Block customizations (index => {field => value})
        0 => [  // Hero block
            'headline' => 'Professional Strata Landscaping Maintenance',
            'subheadline' => 'Expert care for residential buildings in Vancouver',
            'cta_url' => '/quote?service=strata',
        ],
        1 => [  // Benefits block
            'heading' => 'Why Choose Our Strata Services',
            'features' => [
                ['icon' => 'check', 'title' => 'Licensed & Insured', 'description' => '...'],
                ['icon' => 'check', 'title' => 'Expert Team', 'description' => '...'],
            ],
        ],
        2 => [  // Gallery block
            'media_ids' => [50, 51, 52, 53, 54, 55],  // Project photos
        ],
        3 => [  // FAQ block
            'faqs' => [
                ['q' => 'How often should we schedule maintenance?', 'a' => '...'],
                ['q' => 'What services are included?', 'a' => '...'],
            ],
        ],
    ],
    $userId
);
```

### Create a Template Preset

```php
$presetId = cms_createTemplatePreset([
    'preset_key' => 'strata_landing_vancouver',
    'label' => 'Service Landing - Strata (Vancouver)',
    'description' => 'Pre-customized service landing for strata maintenance',

    'base_page_template_id' => 1,  // Template ID

    // Customizations to apply
    'customization_config' => [
        'hero_headline' => 'Professional Strata Landscaping Maintenance',
        'hero_cta_url' => '/quote?service=strata&location=vancouver',
        'portfolio_filters' => ['service' => 'strata', 'location' => 'vancouver'],
        'faq_items_count' => 8,
    ],
], $userId);

// Later: Create page using preset
$pageId = cms_createPageFromTemplate(
    'service_landing_v2',
    ['slug' => 'another-strata-page'],
    // Use preset customizations
    [],
    $userId
);

// Or apply preset to existing page
cms_applyTemplatePreset($pageId, $presetId, $userId);
```

### Create a Template Group

```php
$groupId = cms_createTemplateGroup([
    'group_key' => 'service_landing_collection',
    'label' => 'Service Landing Suite',
    'description' => 'Complete set of templates for service landing pages',
    'group_type' => 'mixed',  // page_templates + block_templates
    'purpose' => 'New service page onboarding',
    'is_featured' => 1,
    'documentation_url' => '/docs/service-landing-templates',
], $userId);

// Add templates to group
cms_addTemplateToGroup($groupId, $pageTemplateId, null, 0);  // Page template at position 0
cms_addTemplateToGroup($groupId, null, $heroBlockTemplateId, 1);
cms_addTemplateToGroup($groupId, null, $benefitsBlockTemplateId, 2);
cms_addTemplateToGroup($groupId, null, $faqBlockTemplateId, 3);

// Get group with all members
$group = cms_getTemplateGroupMembers($groupId);
```

### Record Template Performance

```php
// When tracking page performance
cms_recordTemplatePerformance(
    $pageTemplateId,  // Which template
    null,  // (or block template ID)
    [
        'date' => date('Y-m-d'),
        'pages_created' => 1,
        'pages_published' => 1,
        'avg_page_views' => 150,
        'avg_conversion_rate' => 0.08,  // 8%
        'avg_time_on_page' => 180,  // seconds
    ]
);

// Later: Get performance data
$performance = cms_getTemplatePerformance(
    $pageTemplateId,
    null,
    '2026-01-01',
    '2026-02-09'
);
// Returns: array of daily metrics
```

### Search Templates

```php
// Find templates matching criteria
$results = cms_searchTemplates('hero', [
    'category' => 'landing',
    'usage_min' => 5,  // Used in at least 5 pages
]);

// $results = [
//   'page_templates' => [...],
//   'block_templates' => [...],
// ]
```

---

## Admin UI: Template Manager

### Main Dashboard

**Tab 1: Page Templates**
- List all page templates (featured first)
- Columns: Label, Category, Pages Created, Avg Performance, Actions
- Actions: Create, Edit, Duplicate, View Performance, Delete
- Filters: Category, Usage, Performance

**Tab 2: Block Templates**
- List all block templates by type
- Columns: Label, Block Type, Usage, CTR, Actions
- Actions: Create, Edit, Duplicate, Preview, Delete
- Grouping: By block_type or category

**Tab 3: Template Groups**
- Display featured groups as cards
- Show member count, purpose
- Actions: Manage Members, Edit, Publish/Unpublish

**Tab 4: Presets**
- Saved customizations by category
- Shows: Base template, customizations applied
- Actions: Duplicate Preset, Apply to Page, Delete

**Tab 5: Performance Analytics**
- Charts: Pages created from templates over time
- Top 10 templates by usage
- Top 10 templates by conversion rate
- Block template performance by CTR

### Create/Edit Page Template UI

**Form Sections:**

1. **Basic Info**
   - Template key (alphanumeric + underscore)
   - Label, description
   - Page type, layout template
   - Category, use case

2. **Block Configuration**
   - Drag-and-drop block reordering
   - For each block:
     - Block type selector
     - Default configuration (JSON or form)
     - Editable fields (checkboxes)
     - Locked fields (checkboxes)
   - "Add Block", "Remove Block" buttons

3. **Page Metadata Defaults**
   - Meta title template
   - Meta description template
   - OG image path
   - Variables: [Title], [Service], [Location]

4. **Preview**
   - Show what page would look like
   - Highlight editable vs locked fields

5. **Version History**
   - List previous versions
   - Diff between versions
   - Rollback to previous version

### Quick Create Page from Template UI

**Modal Dialog:**

1. **Select Template**
   - Dropdown or search
   - Show featured templates first
   - OR select from template group

2. **Enter Page Info**
   - Slug (auto-generate from title)
   - Title
   - Customize each block:
     - Show only editable fields
     - Disable locked fields
     - Preview changes in real-time

3. **Select Preset (Optional)**
   - "Apply preset customizations" checkbox
   - Dropdown to choose preset
   - Shows what will be customized

4. **Create Page**
   - Creates page from template
   - Redirects to page editor
   - Show: "Page created from [Template]"

---

## Template Versioning

### How Versions Work

```
Template v1 created
  ↓ Admin updates template
Template v2 created (v1 saved as snapshot)
  ↓ 10 pages created from v2
Pages have: template_id=1, template_version=2
  ↓ Admin updates template
Template v3 created
  ↓ Notification: "Pages using v2, update available"
Admin can: Keep v2, Auto-update to v3, or Update specific pages
```

### Rollback Process

```
cms_getPageTemplateVersions($templateId)  // Get history
  → Shows v1, v2, v3 with change summaries

cms_restorePageTemplateFromVersion($versionId)
  → Reverts template to that version
  → Creates new version record
  → Notifies pages: "Template reverted"
```

---

## Template Customization Fields

### Editable Fields Logic

When user creates page from template:

```
For each block in template:
  For each field in editable_fields:
    → Show field in admin UI
    → Allow user to customize
    → Apply customization to page

For each field in locked_fields:
  → Hide from admin UI
  → Use default value from template
  → Cannot be changed
```

### Example: Service Landing Hero Block

```
Template default:
  - headline: "Professional [Service]"
  - subheadline: "Serving Vancouver"
  - cta_url: "/quote"
  - height: "400px"

Editable: headline, subheadline, cta_url
Locked: height

User experience:
  ✅ Can change headline → "Professional Strata Maintenance"
  ✅ Can change subheadline → "Expert care in Vancouver"
  ✅ Can change cta_url → "/quote?service=strata"
  ❌ Cannot change height (locked)
```

---

## Performance Tracking

### What Gets Tracked

For each template, track daily:

- **Pages created from template** — How many new pages
- **Pages published** — How many actually published
- **Average page views** — Traffic per page
- **Average conversion rate** — Lead generation rate
- **Average time on page** — Engagement metric
- **Average CTR** (blocks) — Click-through rate
- **Average engagement** (blocks) — Engagement score

### Using Performance Data

```
Best performing service landing template:
  - 47 pages created
  - 89% published rate
  - Avg 280 page views per page
  - Avg 12% conversion rate

Underperforming block template:
  - Hero blocks using this template
  - Avg CTR: 1.2% (vs 3.2% industry avg)
  → Recommendation: A/B test new hero template
```

---

## Template Export/Import

### Export Template

```php
$json = cms_exportPageTemplate($templateId);
// Returns: {template_key, label, blocks_config, metadata_defaults, ...}
// File saved: template_service_landing_v2.json
```

### Import Template

```php
$templateId = cms_importPageTemplate($json, $userId);
// Creates new template from exported JSON
// Useful for: Staging → Production, Sharing with team
```

### Backup All Templates

```bash
# Export all page templates
for template in $(get_all_page_templates); do
  export_template $template > backups/template_${template}.json
done

# Restore from backup
cms_importPageTemplate(file_get_contents('backups/template_service_landing_v2.json'), 1);
```

---

## Best Practices

### 1. Template Naming
- Use descriptive keys: `service_landing_v1`, `location_page_v2`
- Include version number
- Avoid generic names like `template_1`

### 2. Field Locking
- Lock fields that must not change (brand colors, layout)
- Lock fields that could break design (column count, height)
- Leave everything else editable for flexibility

### 3. Documentation
- Add clear descriptions for each template
- Document the purpose and use case
- Link to documentation/guide

### 4. Presets vs Templates
- **Use templates** for major layout variations
- **Use presets** for minor customizations of same layout
- Example:
  - Template: "Service Landing" (full structure)
  - Presets: "Service Landing - Strata", "Service Landing - Hedge Trim", etc.

### 5. Performance Monitoring
- Track which templates convert best
- A/B test variations via presets
- Retire underperforming templates

### 6. Versioning
- Only increment version when making breaking changes
- Document why version changed (changelog)
- Notify admins when updates available

---

## API Reference

### Page Templates

```php
cms_getPageTemplates($category, $onlyFeatured)
cms_getPageTemplate($templateKey)
cms_getPageTemplateById($templateId)
cms_savePageTemplate($data, $userId, $templateId)
cms_createPageTemplateVersion($templateId, $previousVersion, $blocksJson, $changeSummary)
cms_getPageTemplateVersions($templateId)
```

### Block Templates

```php
cms_getBlockTemplates($blockType, $category)
cms_getBlockTemplate($templateKey)
cms_getBlockTemplateById($templateId)
cms_saveBlockTemplate($data, $userId, $templateId)
```

### Page Creation from Template

```php
cms_createPageFromTemplate($pageTemplateKey, $pageOverrides, $blockCustomizations, $userId)
```

### Presets

```php
cms_createTemplatePreset($data, $userId)
cms_getTemplatePresets($baseTemplateId)
cms_applyTemplatePreset($pageId, $presetId, $userId)
```

### Groups

```php
cms_createTemplateGroup($data, $userId)
cms_getFeaturedTemplateGroups()
cms_getTemplateGroupMembers($groupId)
cms_addTemplateToGroup($groupId, $pageTemplateId, $blockTemplateId, $position)
```

### Performance

```php
cms_recordTemplatePerformance($pageTemplateId, $blockTemplateId, $metrics)
cms_getTemplatePerformance($pageTemplateId, $blockTemplateId, $dateFrom, $dateTo)
```

### Search & Discovery

```php
cms_searchTemplates($query, $filters)
```

### Export/Import

```php
cms_exportPageTemplate($templateId)
cms_importPageTemplate($json, $userId)
```

---

## Migration Path

### Week 1: Set Up Templates
1. Run migration 502_cms_template_library.sql
2. Create 3-5 core page templates (service, location, blog)
3. Create 10-15 block templates (hero, benefits, CTA, etc.)

### Week 2: Admin UI
1. Build template manager page
2. Implement template creation/editing
3. Implement quick-create from template

### Week 3: Adoption
1. Migrate existing pages to use templates
2. Audit page performance by template
3. Create presets for common variations

### Week 4: Optimization
1. Track performance metrics
2. A/B test templates via presets
3. Retire underperforming templates

---

## Summary

The Template Library system provides:

✅ **Complete template management** — Store, version, and organize templates
✅ **Adjustable templates** — Lock critical fields, allow customization
✅ **Rapid page creation** — Build pages from templates in minutes
✅ **Performance tracking** — See which templates work best
✅ **Team efficiency** — Presets and groups for consistent workflows
✅ **Scalability** — Support hundreds of templates without slowdown

All templates are stored in database and fully auditable.
