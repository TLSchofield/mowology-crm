# CMS Phase 3+ Roadmap: Automation & Intelligence

## Overview

Phase 1 & 2 have established a solid, correct, and usable CMS editor. Phase 3 and beyond add intelligent automation to transform the CMS into a true "marketing machine" that reduces staff effort and multiplies content output.

---

## PHASE 3: Automation Layer — Media Optimization & SEO

### 3.1 Media Optimization Pipeline

**Goal**: Automatically generate optimized variants of uploaded images (WebP, responsive sizes).

**Scope**:
1. On upload: Auto-generate WebP variant + 3–5 responsive sizes (640w, 1024w, 1440w, etc.)
2. Store paths and metadata (width, height) in `cms_media`
3. Block renderers use responsive `<picture>` tags with srcset

**Database Changes** (requires migration):
```sql
ALTER TABLE cms_media ADD COLUMN (
  webp_path VARCHAR(255) COMMENT 'Path to WebP variant',
  source_width INT COMMENT 'Original image width (px)',
  source_height INT COMMENT 'Original image height (px)',
  sizes_json JSON COMMENT 'Responsive sizes: {"640":"/path/640.jpg","1024":"/path/1024.jpg"}'
);

CREATE TABLE cms_media_variants (
  id INT PRIMARY KEY AUTO_INCREMENT,
  media_id INT NOT NULL,
  variant_type VARCHAR(50) COMMENT 'webp, size_640, size_1024, thumb',
  file_path VARCHAR(255),
  width INT,
  height INT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (media_id) REFERENCES cms_media(id) ON DELETE CASCADE
);
```

**Implementation**:
1. Create `/crm/includes/media-processor.php`:
   - `processUploadedImage(string $filePath): array` — uses GD/ImageMagick to generate variants
   - `generateWebP()`, `generateResponsiveSize()`, `generateThumbnail()`

2. Hook into `/crm/api/upload-media.php`:
   - After file save, call `processUploadedImage()`
   - Store paths in DB

3. Update block renderers to use responsive images:
   - Hero block: `<picture>` with srcset
   - Gallery block: same treatment

**Timeline**: 2–3 days

---

### 3.2 Alt Text Workflow & Suggestions

**Goal**: Encourage/enforce descriptive alt text on all images; suggest defaults.

**Features**:
1. **Smart defaults** in media picker:
   - If alt_text is empty, suggest: `"{service} in {neighbourhood}, Vancouver — {aspect}"`
   - Example: "Lawn Maintenance in Burnaby, Vancouver — before and after"

2. **Alt text audit** in media library:
   - Show warning badge for missing alt text
   - Sort by "missing alt" to find gaps

3. **Page-level audit**:
   - Dashboard widget: "{X} images missing alt text"
   - Link to audit tool showing problematic images

**Implementation**:
1. Create `/crm/api/cms_suggest_alt.php`:
   - Takes `media_id` and optional context (service, neighbourhood)
   - Returns suggestion

2. Update media picker modal (cms-block-editor.php):
   - After media select, offer suggestion
   - Admin can accept or edit

3. Add media audit page (`/cms/cms-media-audit.php`)

**Timeline**: 1 day

---

### 3.3 SEO Automation Per Page

**Goal**: Auto-generate SEO fields with smart defaults; enforce best practices.

**Features**:
1. **Meta title default**: If empty, use `"{Title} in Vancouver | Mowology"`
2. **Meta description default**: If empty, generate from first paragraph or block
3. **Canonical URL**: Auto-set to `{SITE_URL}/{slug}`
4. **OpenGraph tags**: Auto-populate from page fields + featured image
5. **Twitter Card**: Same as OG tags
6. **JSON-LD schema**:
   - LocalBusiness (site-wide)
   - Service (service landing pages)
   - FAQ (when FAQ block exists)
   - ImageObject (gallery pages)

**Database Changes** (optional, for enforcement):
```sql
ALTER TABLE cms_pages ADD COLUMN (
  auto_seo_enabled BOOLEAN DEFAULT TRUE COMMENT 'Auto-populate SEO fields',
  canonical_override VARCHAR(500) COMMENT 'Override canonical URL',
  robots_override VARCHAR(50) COMMENT 'Override robots meta (noindex, nofollow, etc)'
);
```

**Implementation**:
1. Create `/crm/includes/seo-functions.php`:
   ```php
   function cms_autoGenerateMetaTitle(array $page): string
   function cms_autoGenerateMetaDescription(array $page, array $blocks): string
   function cms_generateSchemaMarkup(array $page, array $blocks): array
   ```

2. Update page editor (cms-page-editor.php):
   - Show "Auto" suggestions in grey text (placeholder)
   - Allow override
   - Show SEO preview (what appears in search results)

3. Update renderer (`cms-renderer.php`):
   - Call auto-functions if `auto_seo_enabled = true`
   - Inject `<meta>`, `<link rel="canonical">`, `<script type="application/ld+json">`

**Timeline**: 1–2 days

---

### 3.4 Sitemap & Search Console Integration

**Goal**: Auto-generate sitemap.xml; support image sitemaps for galleries.

**Features**:
1. **Sitemap generation** (`/sitemap.xml`):
   - Include all published pages
   - Include `lastmod` from updated_at
   - Exclude noindex pages

2. **Image sitemap** (`/sitemap-images.xml`):
   - Include images from gallery blocks
   - Link to media, caption, title

3. **Robots.txt**:
   - Auto-generated to point to sitemaps

**Implementation**:
1. Create `/public/sitemap.php`:
   - Query published pages from cms_pages
   - Render XML with proper headers
   - Cache for 24h

2. Create `/public/sitemap-images.php`:
   - Similar logic
   - Join cms_blocks + media to find gallery images

3. Update `.htaccess`:
   - Rewrite `/sitemap.xml` → `/sitemap.php`
   - Rewrite `/sitemap-images.xml` → `/sitemap-images.php`

**Timeline**: 1 day

---

## PHASE 4: Template-Driven Landing Page Generator

**Goal**: Wizard to auto-generate landing pages from templates + variable injection.

**UI**: Pages list → "New Page from Template" → Multi-step wizard

**Wizard Steps**:
1. Select service (dropdown: Lawn Care, Snow Removal, etc.)
2. Select area (dropdown/autocomplete: Burnaby, Richmond, etc.)
3. Select CTA type (Get Quote, Download, Contact, etc.)
4. Review draft page

**Generated Page**:
- Hero: `"[Service] in [Area]"`
- Features: Pre-populated from service template
- Testimonials: Auto-linked from portfolio if tagged with service + area
- FAQ: Pre-populated from service template
- CTA: Set to /quote?service=X&area=Y

**Implementation**:
1. Create `/cms/cms-page-generator.php` (wizard UI)

2. Create `cms_generatePageFromTemplate()` in cms-functions.php:
   - Takes (serviceKey, area, ctaType)
   - Looks up service template blocks
   - Injects variables
   - Creates draft page
   - Returns page ID

3. Add service templates to database:
   ```sql
   INSERT INTO cms_page_templates (template_key, page_type, label, blocks_json)
   VALUES ('service_landing_base', 'service_landing', 'Service Landing', '...');
   ```

**Timeline**: 2–3 days

---

## PHASE 5: Portfolio → Marketing Integration

**Goal**: Connect job photos to content. Favorite photos auto-populate proof sections; generate case studies.

**Features**:
1. **Portfolio tagging** in job view:
   - Tag photos: Service + Neighbourhood + ⭐ Favorite

2. **Auto-populate proof blocks**:
   - Service landing page renders gallery with "favorite" photos from that service + area

3. **Case study generation**:
   - Select 5–10 photos from same job
   - Click "Create Case Study"
   - Generates draft page with photos, job details, testimonial prompt

**Database**:
```sql
ALTER TABLE portfolio_photos ADD COLUMN (
  is_featured BOOLEAN DEFAULT FALSE,
  service_key VARCHAR(100),
  neighbourhood_key VARCHAR(100),
  UNIQUE KEY (job_id, is_featured)
);
```

**Implementation**:
1. Update `/crm/portfolio/index.php`:
   - Add "Favorite" star toggle per photo
   - Show tag dropdowns

2. Create `/cms/cms-case-study-generator.php`:
   - Wizard to select job → photos → template
   - Generates draft page

3. Update page renderer:
   - When rendering proof section, query portfolio photos filtered by service + area + is_featured

**Timeline**: 2–3 days

---

## PHASE 6: AI/LLM Enhancement Layer (Optional)

**Note**: This phase is optional and depends on budget/requirements.

**Potential LLM tasks**:
1. **Auto-generate meta descriptions**:
   - Takes page title + first 500 chars of content
   - Returns SEO-friendly description (155–160 chars)

2. **Auto-generate alt text**:
   - Takes image + page context
   - Returns descriptive alt text

3. **Generate testimonials from photos**:
   - Takes before/after photos
   - Suggests testimonial text (admin reviews)

4. **Content generation from templates**:
   - Takes service template + neighbourhood
   - Generates unique landing page copy

**Implementation**:
- Use OpenAI API or Claude API
- Add settings page for API key
- Wrap in `/crm/includes/ai-functions.php`
- All AI-generated content requires admin review before publishing

**Timeline**: 3–5 days (depends on third-party API integration)

---

## Implementation Priority

**Recommended Order**:
1. ✅ Phase 1 & 2 (COMPLETE)
2. → **Phase 3.1**: Media optimization (biggest performance + SEO impact)
3. → Phase 3.2: Alt text workflow (quick win, improves accessibility)
4. → Phase 3.3: SEO automation (automatic best practices)
5. → Phase 3.4: Sitemap generation (search console integration)
6. → Phase 4: Template-driven generator (reduces page creation time by 80%)
7. → Phase 5: Portfolio integration (leverages existing content)
8. → Phase 6: AI enhancement (nice-to-have, requires API)

---

## Database Migrations Summary

### Phase 3 Migration
```sql
-- 110_cms_phase_3_media_optimization.sql
ALTER TABLE cms_media ADD COLUMN (
  webp_path VARCHAR(255),
  source_width INT,
  source_height INT,
  sizes_json JSON
);

CREATE TABLE cms_media_variants (
  id INT PRIMARY KEY AUTO_INCREMENT,
  media_id INT NOT NULL,
  variant_type VARCHAR(50),
  file_path VARCHAR(255),
  width INT,
  height INT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (media_id) REFERENCES cms_media(id) ON DELETE CASCADE
);

ALTER TABLE cms_pages ADD COLUMN (
  auto_seo_enabled BOOLEAN DEFAULT TRUE,
  canonical_override VARCHAR(500),
  robots_override VARCHAR(50)
);
```

### Phase 5 Migration
```sql
-- 111_cms_phase_5_portfolio_integration.sql
ALTER TABLE portfolio_photos ADD COLUMN (
  is_featured BOOLEAN DEFAULT FALSE,
  service_key VARCHAR(100),
  neighbourhood_key VARCHAR(100)
);

CREATE UNIQUE INDEX idx_featured_per_job ON portfolio_photos(job_id, is_featured);
```

---

## Success Metrics

Once all phases are complete, the CMS will enable:

| Metric | Current | Target |
|--------|---------|--------|
| Time to create landing page | 30 min | 3 min (via generator) |
| Landing pages per month | 2–3 | 20+ (automated) |
| Images with alt text | 60% | 95%+ (enforced) |
| Unique service landing pages | 0 | 50+ (service × neighbourhood combos) |
| Organic traffic increase | N/A | 40–60% (via SEO + content) |
| Staff marketing time | 10h/week | 3h/week (automation) |

---

## Notes

1. **No AI required for Phase 1–5**: All automation is rule-based and deterministic.
2. **Performance optimization**: Consider caching for sitemap.xml and generated pages.
3. **Backwards compatibility**: All changes are additive; existing pages continue to work.
4. **Testing**: Each phase should include manual testing on staging before production.
5. **Documentation**: Update `/cms/` README with each phase's user guide.

---

**Next Steps**: Review Phase 3 scope and confirm database schema. Schedule implementation.
