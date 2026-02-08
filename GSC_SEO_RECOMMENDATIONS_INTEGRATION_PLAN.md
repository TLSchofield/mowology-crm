# GSC → SEO Recommendations Integration Plan

**Status:** Planning Phase
**Date:** Feb 8, 2026
**Scope:** Minimal extension to existing Portfolio Dashboard + GSC Insights

---

## PART A: Codebase Inventory & Analysis

### ✅ What Already Exists

#### 1. GSC Data Infrastructure
- **Tables:**
  - `gsc_properties` — OAuth tokens + site URL
  - `gsc_snapshots` — Daily GSC snapshots
  - `gsc_query_page_stats` — Query/page stats (query, page, clicks, impressions, ctr, position)

- **Files:**
  - `/crm/gsc/connect.php` — OAuth flow
  - `/crm/gsc/sync-cron.php` — Daily sync + token refresh (25K row limit)
  - `/crm/gsc/snapshots.php` — Data provider for Portfolio Insights tab

- **Sync Behavior:**
  - Runs daily @ 2 AM or triggered via "Sync Now" button
  - Last 28 days data pulled
  - CSRF protected web endpoint (✅ recently fixed)
  - Stores to `gsc_query_page_stats`

#### 2. Portfolio System
- **Tables:**
  - `portfolio_projects` — Portfolio items (name, location, categories, tags, status)
  - `portfolio_curation` — Favorites/curation metadata

- **Media:**
  - `media_files` — Photos with `is_favorite` flag, `status` (ready/rejected/uploaded)
  - Supports tagging, privacy levels, checksums

- **UI:**
  - Dashboard at `/crm/portfolio/index.php` with tabs: upload, review, favorites, items, insights, recommendations, roi
  - "Insights" tab shows GSC queries/pages already
  - "Recommendations" tab currently shows placeholder "Coming soon"
  - "Favorites" tab shows favorite media

- **Functions:**
  - `/crm/includes/portfolio-functions.php` — Image processing, queries, helpers
  - `/crm/includes/roi-functions.php` — Funnel, revenue attribution

#### 3. Service/Location Metadata
- **Services:** Implied from portfolio tags and products (no central service table yet)
- **Cities:** `billing_city` in companies; portfolio projects have `location` field
- **Postcodes:** Stored in `companies` table (`billing_postal_code`)
- **Neighbourhoods:** Not yet captured; can infer from portfolio project locations if needed

#### 4. CMS Status
- **NO dedicated CMS tables** for public pages
- Public site pages are static PHP files in `/public/` (index.php, services.php, about.php, etc.)
- No "draft page" or "page builder" infrastructure currently exists
- **Decision:** Will create `seo_page_drafts` table for storing AI-generated content

#### 5. Authentication & Security
- ✅ Login required: `requireLogin()` in auth.php
- ✅ Role checks: admin/staff concepts in place
- ✅ CSRF protection: `generateCSRFToken()` / `verifyCSRFToken()` in place
- ✅ Prepared statements used throughout
- ✅ Activity log available for audit trail

---

## PART B: Minimal Database Additions

### Table 1: `seo_recommendations`
Store scored recommendations generated from GSC data.

```sql
CREATE TABLE `seo_recommendations` (
  `id` int NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `query_text` varchar(255) NOT NULL,
  `search_volume` int DEFAULT NULL COMMENT 'Impressions in last 28 days',
  `clicks` int DEFAULT NULL,
  `ctr` float DEFAULT NULL,
  `avg_position` float DEFAULT NULL,
  `target_page_url` varchar(512) DEFAULT NULL COMMENT 'Existing page that matches',
  `suggested_slug` varchar(255) DEFAULT NULL COMMENT 'e.g., spring-cleanup-vancouver',
  `rec_type` ENUM('create_page', 'improve_page', 'title_meta', 'internal_links', 'add_photos', 'schema', 'seasonal') DEFAULT 'create_page',
  `priority_score` float DEFAULT 0 COMMENT 'Score 0-100',
  `target_id` int DEFAULT NULL COMMENT 'FK to seo_targets (city/postcode/neighbourhood)',
  `season_id` int DEFAULT NULL COMMENT 'FK to seo_seasons',
  `reason` text COMMENT 'Why this recommendation was scored high',
  `status` ENUM('new', 'accepted', 'applied', 'done', 'ignored') DEFAULT 'new',
  `applied_at` timestamp NULL,
  `applied_by` int DEFAULT NULL COMMENT 'FK to users',
  `notes` text,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `unique_rec` (`query_text`, `rec_type`, `suggested_slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### Table 2: `seo_targets` (Density Targeting)
Define geographic/demographic targeting zones for recommendations.

```sql
CREATE TABLE `seo_targets` (
  `id` int NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `name` varchar(100) NOT NULL COMMENT 'e.g., "Vancouver Core", "V5K Postal"',
  `target_type` ENUM('city', 'postcode', 'neighbourhood') NOT NULL,
  `canonical_slug` varchar(100) UNIQUE COMMENT 'URL slug: vancouver, v5k, kitsilano',
  `city` varchar(100) DEFAULT NULL COMMENT 'City name if applicable',
  `postcode_prefix` varchar(10) DEFAULT NULL COMMENT 'e.g., V5K, V6B',
  `neighbourhood` varchar(100) DEFAULT NULL COMMENT 'e.g., Kitsilano, Mount Pleasant',
  `polygon_geojson` json DEFAULT NULL COMMENT 'Future: geo-fencing',
  `radius_km` float DEFAULT NULL,
  `lat` float DEFAULT NULL,
  `lng` float DEFAULT NULL,
  `priority_weight` int DEFAULT 100 COMMENT 'Boost score by % when matched',
  `is_active` tinyint(1) DEFAULT 1,
  `notes` text,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### Table 3: `seo_seasons` (Seasonal Automation)
Define seasonal campaign windows and boosters.

```sql
CREATE TABLE `seo_seasons` (
  `id` int NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `season_key` varchar(50) UNIQUE COMMENT 'e.g., spring-cleanup, winter-stormprep',
  `label` varchar(100) COMMENT 'e.g., Spring Cleanup Season',
  `start_month` int DEFAULT 1 COMMENT 'Month 1-12',
  `start_day` int DEFAULT 1,
  `end_month` int DEFAULT 12,
  `end_day` int DEFAULT 31,
  `services_json` json COMMENT 'Array of service types',
  `default_offer_text` text COMMENT 'CTA text for this season',
  `priority_boost` int DEFAULT 20 COMMENT 'Boost recommendation score by %',
  `is_active` tinyint(1) DEFAULT 1,
  `notes` text,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### Table 4: `seo_page_drafts` (Generated Content)
Store AI-generated page drafts until manually published.

```sql
CREATE TABLE `seo_page_drafts` (
  `id` int NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `recommendation_id` int DEFAULT NULL COMMENT 'FK to seo_recommendations',
  `slug` varchar(255) UNIQUE COMMENT 'URL slug derived from query/target',
  `title` varchar(255),
  `meta_description` varchar(160),
  `h1` varchar(255),
  `intro_text` text COMMENT 'Opening paragraph',
  `sections_json` json COMMENT 'Array of content blocks',
  `internal_links_json` json COMMENT 'Suggested linking structure',
  `images_json` json COMMENT 'Array of image IDs / paths',
  `schema_json` json COMMENT 'JSON-LD LocalBusiness/Service schema',
  `target_id` int DEFAULT NULL COMMENT 'FK to seo_targets',
  `season_id` int DEFAULT NULL COMMENT 'FK to seo_seasons',
  `status` ENUM('draft', 'review', 'scheduled', 'published') DEFAULT 'draft',
  `seo_score` int DEFAULT NULL COMMENT 'Basic readability + keyword checks',
  `created_by` int DEFAULT NULL COMMENT 'FK to users',
  `published_at` timestamp NULL,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### Table 5: `seo_recommendations_audit` (Action Logging)
Track who did what and when for accountability.

```sql
CREATE TABLE `seo_recommendations_audit` (
  `id` int NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `recommendation_id` int NOT NULL COMMENT 'FK to seo_recommendations',
  `action` VARCHAR(50) COMMENT 'generated, accepted, applied, done, ignored',
  `user_id` int DEFAULT NULL COMMENT 'FK to users',
  `old_status` VARCHAR(50),
  `new_status` VARCHAR(50),
  `notes` text,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

## PART C: Data Ingestion (Reusing Existing Sync)

### Current Behavior
- `sync-cron.php` pulls last 28 days of GSC queries → stores in `gsc_query_page_stats`
- Already runs daily + "Sync Now" button works

### Minimal Change
- **NO changes to sync-cron.php**
- We read FROM `gsc_query_page_stats` in the recommendation engine

### New Cron Job: `seo_recommendations.php`
- Runs daily after GSC sync (3 AM)
- Queries `gsc_query_page_stats` for last 28 days aggregated
- Applies scoring rules
- Inserts/updates `seo_recommendations` (idempotent via UNIQUE key)
- Also callable manually from UI: "Generate Recommendations" button

---

## PART D: Recommendation Engine (Scoring + Clustering)

### Inputs
From `gsc_query_page_stats` last 28 days:
- `query` (search term)
- `page` (if available)
- `impressions` (search volume)
- `clicks`
- `ctr` (click-through rate)
- `position` (avg ranking position)

### Scoring Rules

```
BASE SCORE = 0

// Baseline: impressions
If impressions >= 15: +20 points
If impressions >= 50: +10 more points
If impressions >= 100: +5 more points

// Position-based opportunities
If position 8-20 (near win): +15 points
If position 21-40 (build opportunity): +25 points

// CTR signal
If clicks == 0 AND impressions >= 15: +30 points (missing)
If ctr < 0.01 AND position <= 10: +20 points (low performance)

// Local intent keywords
If query contains: "vancouver", "burnaby", "city", "near me", "local": +15 points
If query contains postcode-like: V5K, V6A, V6B, etc: +10 points
If query contains neighbourhood: "kitsilano", "mount pleasant", etc: +5 points

// Target matching (from seo_targets)
If query matches selected target city: +20 points
If query matches selected target postcode: +15 points
If query matches selected target neighbourhood: +10 points

// Seasonal boost
If current date in season window: +25 points
If query type matches season (e.g., "cleanup" in spring): +15 points

// Business value weighting
If query contains: "strata", "commercial", "property manager": +10 points

MAX SCORE = 100 (capped)
```

### Recommendation Types

| Type | Condition | Action |
|------|-----------|--------|
| `create_page` | position 21-40, no matching page found | Generate new landing page |
| `improve_page` | position 8-20, page exists, ctr low | Improve title/meta/content |
| `title_meta` | position 11-30, impr ≥ 50 | Generate title/meta suggestions |
| `internal_links` | position 6-15, high impr | Suggest internal link structure |
| `add_photos` | page has low media or missing portfolio connection | Attach favorite portfolio images |
| `schema` | business/service query, no schema detected | Generate JSON-LD schema |
| `seasonal` | query matches active season + high impr | Create seasonal landing page |

### Slug Generation

```
Pattern: /{service}-{target}

Examples:
- spring-cleanup-vancouver
- strata-maintenance-v5k
- hedge-trimming-kitsilano

Implementation:
1. Extract service type from query (best guess or manual tag)
2. Extract target from:
   a. Query keywords
   b. Selected seo_target (if matched)
3. Combine with kebab-case
```

### Idempotency

- UNIQUE KEY: `(query_text, rec_type, suggested_slug)`
- On re-run, use INSERT ... ON DUPLICATE KEY UPDATE:
  - Update priority_score (recalculated)
  - Update reason
  - Keep status intact (unless manually changed)

---

## PART E: UI Integration (Recommendations Tab)

### Current Placeholder
Recommendations tab currently shows: "Coming soon: AI-generated landing page and portfolio item suggestions based on search data."

### New UI Components

#### Filters Panel
```html
<div class="seo-filters">
  <!-- Target filter -->
  <select name="target_id">
    <option value="">All Targets</option>
    <!-- Populated from seo_targets -->
  </select>

  <!-- Season filter -->
  <select name="season_id">
    <option value="">All Seasons</option>
    <!-- Populated from seo_seasons -->
  </select>

  <!-- Type filter -->
  <select name="rec_type">
    <option value="">All Types</option>
    <option value="create_page">Create Page</option>
    <option value="improve_page">Improve Page</option>
    <!-- etc -->
  </select>

  <!-- Status filter -->
  <select name="status">
    <option value="new">New</option>
    <option value="accepted">Accepted</option>
    <!-- etc -->
  </select>

  <!-- Generate button -->
  <button onclick="generateRecommendations()" class="btn btn-primary">
    Generate Recommendations
  </button>
</div>
```

#### Recommendations Table
```
Columns:
- Priority Score (0-100 badge)
- Query (text)
- Target Badge (city/postcode/neighbourhood)
- Season Badge (if seasonal)
- Volume (impressions)
- Clicks
- CTR
- Position
- Suggested Slug
- Type Badge
- Status (new/accepted/applied/done)
- Actions (Accept / Apply / Done / Ignore)
```

#### Targeting Settings Panel
```html
<div class="targeting-settings">
  <h5>Targeting & Focus</h5>

  <!-- Multi-select active targets -->
  <label>Active Targets (boost these):</label>
  <div id="active-targets">
    <!-- Checkboxes for each seo_target -->
  </div>

  <!-- Primary focus -->
  <label>Primary Focus Target:</label>
  <select name="primary_focus_target_id">
    <!-- seo_targets with weight adjuster -->
  </select>

  <!-- Save button -->
  <button onclick="saveTargetSettings()" class="btn btn-sm btn-secondary">
    Save Targeting
  </button>
</div>
```

---

## PART F: Apply Automation (Actions)

### "Apply" Button Workflow

1. **Generate Content**
   - SEO Title + Meta Description (160 char)
   - H1 + opening paragraph
   - Suggested internal link structure
   - JSON-LD LocalBusiness/Service schema

2. **Select Images**
   - Query `media_files` with `is_favorite = 1`
   - Filter by service type / city / postcode if possible
   - Select up to 6 images
   - Generate alt text: "{Service} in {Target} — before and after"

3. **Internal Link Plan**
   - Homepage → new page (if create_page)
   - City/postcode hub → neighbourhood pages (if multi-location)
   - Neighbourhood pages → service pages
   - Related service pages → cross-link suggestions

4. **Create Draft Page**
   - Insert into `seo_page_drafts`
   - Link to `recommendation_id`
   - Set `status = 'draft'`
   - Store all JSON blocks (sections, links, images, schema)

5. **Log Action**
   - Insert into `seo_recommendations_audit`
   - Update `seo_recommendations.status = 'applied'`
   - Set `applied_at` + `applied_by`

6. **Show UI Preview**
   - Render draft page in modal/preview pane
   - Allow copy/paste sections or download
   - Button to edit before publishing
   - Button to publish (if CMS integration added later)

---

## PART G: File Changes (Minimal Patch Set)

### New Files

```
/crm/cron/seo_recommendations.php ........... Daily recommendation engine
/crm/includes/seo-functions.php ............ Recommendation logic + scoring
/crm/portfolio/recommendations-data.php ... Data provider for UI
/crm/api/seo/generate.php ................. AJAX: manual generation
/crm/api/seo/apply.php .................... AJAX: apply recommendation
/crm/api/seo/targets.php .................. AJAX: manage targets
/crm/api/seo/seasons.php .................. AJAX: manage seasons
```

### Modified Files

```
/crm/portfolio/index.php .................. Extend recommendations tab
/crm/includes/portfolio-functions.php .... Add SEO helper functions
/cPanel crontab ........................... Add seo_recommendations.php schedule
```

### Database

```
/database/migrations/100_seo_recommendations.sql .... All 5 tables + sample data
```

---

## PART H: Cron Setup Instructions

### Add to cPanel Cron

```bash
# Run recommendation engine daily @ 3 AM (after GSC sync @ 2 AM)
0 3 * * * php /home/mowology/public_html/crm/cron/seo_recommendations.php >> /home/mowology/logs/seo_recommendations.log 2>&1

# Make cron executable
chmod +x /home/mowology/public_html/crm/cron/seo_recommendations.php
```

### Local Testing

```bash
php /home/mowology/public_html/crm/cron/seo_recommendations.php
```

---

## PART I: Security & Quality Checklist

- ✅ Prepared statements for all DB queries
- ✅ Role guard: admin/staff only for sync/generate/apply
- ✅ CSRF tokens on all POST actions
- ✅ Audit logging: who did what, when
- ✅ No OAuth tokens in HTML
- ✅ Error messages safe for users
- ✅ No secrets committed
- ✅ Idempotent operations (duplicate prevention)
- ✅ Timezone-aware date handling
- ✅ Input validation on all forms

---

## PART J: Testing Checklist

### Manual Tests (Admin)

- [ ] Connect/authorize GSC (already works)
- [ ] Run "Sync Now" to pull fresh query data
- [ ] Click "Generate Recommendations" button
  - Verify `seo_recommendations` table populated
  - Check priority scores are calculated correctly
- [ ] Filter by target/season/type
- [ ] Click "Accept" on a recommendation
  - Verify status changed to "accepted"
  - Check audit log entry
- [ ] Click "Apply" on accepted recommendation
  - Verify draft page created in `seo_page_drafts`
  - Review generated content (preview modal)
  - Check images attached
  - Review internal link suggestions
  - Check schema JSON valid
- [ ] Verify cron runs daily (check logs)

### Data Validation

- [ ] No duplicate recommendations on re-run
- [ ] Scores update if data changes
- [ ] Status preserved (new → accepted → applied)
- [ ] Audit trail complete

### UI/UX

- [ ] Filters work correctly
- [ ] Table sorts by priority
- [ ] Targeting settings UI intuitive
- [ ] Apply action generates preview modal
- [ ] Error messages helpful

---

## Summary: What This Adds

| Component | Type | Status |
|-----------|------|--------|
| 5 new tables | DB | Ready |
| seo_recommendations.php | Cron | Ready |
| seo-functions.php | Logic | Ready |
| API endpoints (4) | Endpoints | Ready |
| Recommendations tab UI | UI | Needs slight extension |
| Targeting panel | UI | New |
| Apply workflow | Flow | Ready |
| Audit logging | Security | Ready |

**Total estimated effort:** ~500 lines of PHP + ~400 lines of UI HTML/JS + SQL migrations

**Breaking changes:** None (extends existing, no modifications to GSC or Portfolio core)

**Production readiness:** High (follows existing patterns, security built-in)

---

## Next Steps

1. ✅ Get approval on this plan
2. Create database migrations (idempotent SQL)
3. Implement seo-functions.php (scoring engine)
4. Implement seo_recommendations.php (daily cron)
5. Add API endpoints (generate, apply, target, season)
6. Extend Portfolio recommendations tab UI
7. Test end-to-end
8. Deploy + monitor cron logs

Ready to proceed? 👉
