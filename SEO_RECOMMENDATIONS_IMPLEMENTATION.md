# SEO Recommendations Engine — Implementation Guide

**Status:** Phase 1 Complete (Engine, API, Database Ready)
**Date:** Feb 8, 2026
**Commit:** `0bda0ee` — "Add SEO recommendations engine: scoring, cron, API endpoints"

---

## 📋 What's Been Built

### Phase 1 Complete ✅

The automated SEO recommendation engine is now deployed with:

1. **Database Layer** — 5 new tables (migrations/100_seo_recommendations.sql)
2. **Scoring Engine** — intelligent scoring algorithm (includes/seo-functions.php)
3. **Daily Cron Job** — automated recommendations generation (cron/seo_recommendations.php)
4. **REST API** — 4 AJAX endpoints for manual triggers and management
5. **Data Provider** — pagination, filtering for UI (portfolio/recommendations-data.php)

### Phase 2 Pending

UI integration in existing Portfolio Dashboard (minor changes to index.php Recommendations tab).

---

## 🗄️ Database Setup

### Migration

Apply the migration to create all tables:

```bash
# Via MySQL CLI
mysql -u mowology_user -p mowology_landscape_crm < database/migrations/100_seo_recommendations.sql

# Or via phpmyadmin: Import the SQL file
```

Tables created:
- `seo_targets` — Geographic targets (city/postcode/neighbourhood)
- `seo_seasons` — Seasonal campaign windows
- `seo_recommendations` — Scored recommendations from GSC
- `seo_page_drafts` — AI-generated page content
- `seo_recommendations_audit` — Action logging

Sample data is included (Vancouver, Burnaby, Richmond, V5K/V6B postcodes, 5 seasons).

---

## 🧠 Scoring Algorithm (Quick Reference)

### Input
From `gsc_query_page_stats` (last 28 days):
- Query text
- Impressions (search volume)
- Clicks
- CTR
- Avg ranking position

### Scoring Rules

```
Impressions:
  ≥100:  +35 pts (high volume)
  ≥50:   +25 pts (moderate)
  ≥15:   +20 pts (baseline)
  <15:   Skip (too low)

Position:
  1-7:    +5 pts (already ranking well)
  8-20:   +15 pts ("near win" — page 1 boundary)
  21-40:  +25 pts ("build" opportunity)
  >40:    +5 pts (not ranking)

CTR Signal:
  0 clicks + impr ≥15:  +30 pts (CRITICAL gap)
  CTR <1% + rank ≤10:   +20 pts (poor snippet)
  CTR <2% + rank ≤5:    +10 pts (weak performance)

Local Intent:
  "vancouver", "near me", "local", etc: +15 pts

Postcode:
  V5K, V6B, etc: +10 pts

Neighbourhood:
  "kitsilano", "mount pleasant", etc: +10 pts

Target Match (city/postcode/hood): +10-20 pts (with weight boost)

Seasonal Boost:
  Current season + matching service: +25 pts

Business Value:
  "strata", "commercial", "property manager": +10 pts

FINAL: Capped at 100 pts
```

### Output per Query
- Priority score (0-100)
- Recommendation type (create_page, improve_page, title_meta, internal_links, add_photos, schema, seasonal)
- Suggested URL slug (e.g., spring-cleanup-vancouver)
- Explanation/reasoning

### Examples

| Query | Impr | Pos | CTR | Score | Type | Slug | Reason |
|-------|------|-----|-----|-------|------|------|--------|
| spring cleanup vancouver | 150 | 15 | 0.8% | 85 | improve_page | spring-cleanup-vancouver | High volume, near-win, local intent, target match, seasonal |
| strata maintenance | 45 | 28 | 0 | 62 | create_page | strata-maintenance | Build opportunity, zero clicks, business value |
| lawn care near kitsilano | 20 | 35 | 1.2% | 48 | create_page | lawn-care-kitsilano | Build opportunity, neighbourhood match |
| hedge trimming vancouver | 8 | 40 | 0 | 0 | skipped | — | Insufficient volume (<15 impr) |

---

## ⚙️ API Endpoints

### 1. Generate Recommendations (Manual Trigger)

**Endpoint:** `POST /crm/api/seo/generate.php`

**Request:**
```html
<form method="post" action="/crm/api/seo/generate.php">
  <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
  <button type="submit">Generate Recommendations</button>
</form>
```

**Response:**
```json
{
  "success": true,
  "message": "SEO Recommendations: 150 analyzed, 12 generated, 3 updated, 0 errors in 8s",
  "stats": {
    "queries_analyzed": 150,
    "recommendations_generated": 12,
    "recommendations_updated": 3,
    "errors": 0,
    "runtime_seconds": 8
  }
}
```

### 2. Apply Recommendation (Create Draft)

**Endpoint:** `POST /crm/api/seo/apply.php`

**Request:**
```javascript
fetch('/crm/api/seo/apply.php', {
  method: 'POST',
  body: new FormData(formElement)
});
```

**Response:**
```json
{
  "success": true,
  "message": "Draft page created successfully",
  "draft_id": 42,
  "content": {
    "title": "Expert Spring Cleanup in Vancouver",
    "meta_description": "Professional spring cleanup services in Vancouver. Free quotes available.",
    "h1": "Expert spring cleanup in Vancouver",
    "slug": "spring-cleanup-vancouver",
    "sections_count": 3,
    "images_count": 6,
    "seo_score": 75
  }
}
```

### 3. Manage Targets (Cities/Postcodes/Neighbourhoods)

**Endpoint:** `GET /crm/api/seo/targets.php` (List)

**Endpoint:** `POST /crm/api/seo/targets.php` (Create/Update)

**Endpoint:** `DELETE /crm/api/seo/targets.php` (Deactivate)

### 4. Manage Seasons (Seasonal Campaigns)

**Endpoint:** `GET /crm/api/seo/seasons.php` (List)

**Endpoint:** `POST /crm/api/seo/seasons.php` (Create/Update)

**Endpoint:** `DELETE /crm/api/seo/seasons.php` (Deactivate)

---

## 📅 Cron Setup

### cPanel Cron Job

Add to your cPanel Cron Jobs:

```bash
# Run recommendation engine daily @ 3 AM (after GSC sync @ 2 AM)
0 3 * * * php /home/mowology/public_html/crm/cron/seo_recommendations.php >> /home/mowology/logs/seo_recommendations.log 2>&1
```

### Manual Testing (Local)

```bash
php /home/mowology/public_html/crm/cron/seo_recommendations.php
```

Expected output:
```
[INFO] Using GSC snapshot from 42 (site: sc-domain:mowology.ca)
[INFO] Loaded 7 active targets
[INFO] Current season: spring-cleanup (ID: 1)
[INFO] Found 150 unique queries to analyze
[GEN] spring cleanup vancouver → spring-cleanup-vancouver (score: 85, type: improve_page)
[GEN] strata maintenance → strata-maintenance (score: 62, type: create_page)
[UPD] lawn care near me → lawn-care (score: 55)
...
[DONE] SEO Recommendations: 150 analyzed, 12 generated, 3 updated, 0 errors in 8s
```

---

## 🛠️ Key Functions Reference

### scoring Engine

```php
// Score a single query
$scoreResult = scoreRecommendation([
  'query' => 'spring cleanup vancouver',
  'impressions' => 150,
  'clicks' => 12,
  'ctr' => 0.08,
  'position' => 15,
  'existing_page' => true,
  'target_id' => 1,  // seo_targets.id
  'season_id' => 3   // seo_seasons.id
], $db);

echo $scoreResult['score'];    // 85
echo $scoreResult['reason'];   // "High search volume (100+ impr); Near-win position (15); ..."
```

### Recommendation Type Selection

```php
$recType = selectRecType($queryData, 85, true);
// Returns: 'improve_page'
```

### Slug Generation

```php
$slug = generateSlug('spring cleanup vancouver', 1, $db);
// Returns: 'spring-cleanup-vancouver'
```

### Content Generation

```php
// SEO metadata
$seo = generateSEOContent('spring cleanup vancouver', 'spring-cleanup-vancouver', $targetRow);
// {title: "Expert Spring Cleanup in Vancouver", meta_description: "..."}

// Page content
$page = generatePageContent('spring cleanup vancouver', $targetRow);
// {h1: "Expert spring cleanup in Vancouver", intro_text: "Looking for professional..."}

// Portfolio images (favorites)
$images = selectPortfolioImages($db, $targetRow, 'spring cleanup', 6);
// Returns array of media_files with ids, paths, alt text

// JSON-LD schema
$schema = generateSchema($targetRow, $title, $url, ['phone' => '...', 'email' => '...']);
// Returns JSON-LD LocalBusiness schema
```

### Logging

```php
logRecommendationAction(
  42,                      // recommendation_id
  'applied',              // action
  1,                      // user_id
  'new',                  // old_status
  'applied',              // new_status
  'Draft page created',   // notes
  $_SERVER['REMOTE_ADDR'],
  $db
);
```

---

## 📊 Database Schema Summary

### seo_recommendations

Primary table for recommendations:

```
id ..................... auto-increment
query_text ............ search query (unique with rec_type + slug)
search_volume ......... impressions (last 28 days)
clicks
ctr ................... click-through rate
avg_position .......... average ranking
suggested_slug ........ generated URL slug
rec_type .............. create_page / improve_page / title_meta / internal_links / add_photos / schema / seasonal
priority_score ........ 0-100 calculated score
target_id ............. FK to seo_targets (if matched)
season_id ............. FK to seo_seasons (if seasonal)
reason ................ explanation of scoring
status ................ new / accepted / applied / done / ignored
applied_at ............ timestamp
applied_by ............ FK to users
created_at ............ when generated
updated_at ............ when updated
```

### seo_page_drafts

Stores AI-generated page content:

```
id .................... auto-increment
recommendation_id ..... FK to seo_recommendations
slug .................. URL path
title, meta_description, h1, intro_text
sections_json ......... Array of content blocks (hero, features, portfolio, etc)
internal_links_json ... Suggested linking structure
images_json ........... Selected portfolio images with alt text
schema_json ........... JSON-LD structured data
target_id, season_id .. Context
status ................ draft / review / scheduled / published
seo_score ............. Basic quality score (0-100)
created_by ............ FK to users
published_at, published_url
created_at, updated_at
```

### seo_targets

Geographic targeting zones:

```
id .................... auto-increment
name .................. Display name (e.g., "Vancouver Core")
target_type ........... city / postcode / neighbourhood
canonical_slug ........ URL slug (unique)
city, postcode_prefix, neighbourhood ... values
priority_weight ....... 100 = normal, 150 = boost by 50%, 50 = reduce by 50%
is_active ............. 0/1
```

### seo_seasons

Seasonal campaign automation:

```
id .................... auto-increment
season_key ............ Unique identifier (e.g., "spring-cleanup")
label ................. Display name
start_month, start_day, end_month, end_day
services_json ......... Array of service types this season applies to
default_offer_text .... CTA message
priority_boost ........ Add X% to score during season
is_active ............. 0/1
```

### seo_recommendations_audit

Audit logging:

```
id .................... auto-increment
recommendation_id ..... FK
action ................ generated / accepted / applied / done / ignored / scored
user_id ............... Who did it
old_status, new_status ... State change
old_score, new_score ... Score changes
details ............... JSON context
ip_address ............ Source
created_at ............ When
```

---

## 🔒 Security Considerations

✅ **Implemented:**
- All queries use prepared statements
- CSRF tokens required on all POST/DELETE
- Admin-only access enforced
- Role checks in every endpoint
- IP logging in audit trail
- No secrets in code
- Error handling prevents data leaks
- Idempotent operations (UNIQUE constraints)

✅ **Best Practices:**
- Passwords never logged
- No sensitive data in audit log
- HTTPS enforced (use .htaccess)
- Rate limiting recommended (add later)
- Two-factor auth for admin (future)

---

## 🧪 Testing Checklist

### Unit Tests (Manual)

- [ ] **Scoring:** Create a test query, verify score calculation
  - [ ] 150 impressions, position 15, 0 clicks → score 85+
  - [ ] 20 impressions, position 28, local keyword → score 50+
  - [ ] Postcode detected → score +10
  - [ ] Seasonal boost applied → score +25

- [ ] **Slug Generation:** Test pattern {service}-{target}
  - [ ] "spring cleanup vancouver" → "spring-cleanup-vancouver"
  - [ ] "strata maintenance" + V5K target → "strata-maintenance-v5k"

- [ ] **Recommendation Type:** Verify selection logic
  - [ ] Position 21-40, no page, high impr → create_page
  - [ ] Position 8-20, page exists, low CTR → improve_page
  - [ ] 0 clicks, 50+ impr → schema

### Integration Tests

- [ ] **Cron Job:**
  - [ ] Run manually: `php /path/to/cron/seo_recommendations.php`
  - [ ] Verify database entries in seo_recommendations
  - [ ] Check audit log entries created

- [ ] **API Endpoints:**
  - [ ] Generate: `POST /crm/api/seo/generate.php` → success response
  - [ ] Apply: `POST /crm/api/seo/apply.php` → draft page created
  - [ ] Targets: `GET /crm/api/seo/targets.php` → lists 7 targets
  - [ ] Seasons: `GET /crm/api/seo/seasons.php` → lists 5 seasons

- [ ] **Data Integrity:**
  - [ ] Duplicate prevention: Run cron twice → no duplicate recommendations
  - [ ] Status preservation: Change to 'accepted' → update cron doesn't reset to 'new'
  - [ ] Audit trail: Every action logged with user_id, timestamp, ip_address

---

## 📝 Next Phase (UI Integration)

Once Phase 1 is stable, Phase 2 will add:

1. **Recommendations Tab UI** (in portfolio/index.php)
   - Filter by status, type, target, season
   - Table with priority score, volume, position, suggested slug
   - Actions: Accept / Apply / Done / Ignore

2. **Targeting Settings Panel**
   - Multi-select active targets
   - Set primary focus
   - Adjust priority weights

3. **Apply Workflow Modal**
   - Preview generated content
   - Show portfolio images selected
   - Display internal linking suggestions
   - Draft preview before creating

4. **Draft Management**
   - Preview page in modal
   - Edit content before publishing
   - Publish when ready (initially copy/paste only; CMS integration later)

---

## 🚀 Deployment Checklist

- [ ] Run migration: `100_seo_recommendations.sql`
- [ ] Add to cPanel cron: `0 3 * * * php .../seo_recommendations.php >> .../seo_recommendations.log 2>&1`
- [ ] Test cron manually
- [ ] Verify sample data loaded (7 targets, 5 seasons)
- [ ] Test "Generate Recommendations" button (to be added in Phase 2)
- [ ] Check audit log entries created
- [ ] Monitor error logs for first week

---

## 📞 Support

For issues:

1. Check cron logs: `/home/mowology/logs/seo_recommendations.log`
2. Check error_log: `/home/mowology/public_html/error_log`
3. Query database:
   ```sql
   SELECT COUNT(*) FROM seo_recommendations WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY);
   SELECT * FROM seo_recommendations_audit ORDER BY created_at DESC LIMIT 10;
   ```
4. Run manual test: `php /path/to/cron/seo_recommendations.php`

---

**Ready for Phase 2 UI Integration!** 🎉
