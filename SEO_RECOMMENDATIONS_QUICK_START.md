# 🚀 SEO Recommendations — Quick Start Guide

**What is it?** An automated system that analyzes Google Search Console (GSC) data and recommends high-value SEO opportunities (landing pages, content improvements, etc.) for the Mowology business.

**Status:** Production-ready, fully deployed.

---

## 🎯 For Users (Admins)

### Where to Access It
1. Log in to the CRM as admin
2. Go to **Portfolio → Recommendations** tab
3. You'll see:
   - **Stats** showing Total, New, Accepted, Applied recommendations
   - **Filter panel** to narrow down by Status, Type, Target, Season
   - **Recommendations table** with actionable recommendations
   - **Targeting Settings** panel to see active geographic targets

### What You Can Do

#### Generate Recommendations (Manual Trigger)
1. Click **"Generate Recommendations"** button
2. Wait for spinner to finish (usually 5-10 seconds)
3. Alert shows how many recommendations were analyzed and created
4. Page reloads automatically

#### View Recommendation Details
- **Query**: What search term triggered this recommendation
- **Score**: Priority (0-100) — higher = more valuable
- **Volume**: Monthly search volume (impressions)
- **CTR**: Click-through rate (how many searchers clicked)
- **Position**: Current ranking position
- **Target**: Geographic location this applies to
- **Type**: Action type (create page, improve page, add title/meta, etc.)
- **Status**: New, Accepted, Applied, or Done

#### Accept → Apply → Done Workflow

**Step 1: Accept (Status = New)**
- Click ✓ button on a 'New' recommendation
- Confirm the dialog
- Status changes to "Accepted"

**Step 2: Apply (Status = Accepted)**
- Click "Apply" button
- Modal pops up showing:
  - SEO Title that will be auto-generated
  - Meta Description
  - H1 Heading
  - Suggested URL slug
- Click "Create Draft" to proceed
- Status changes to "Applied"

**Step 3: Done (Status = Applied)**
- Click "Done" button
- Marks the recommendation as complete
- Status changes to "Done"

#### Ignore a Recommendation
- If you don't want to act on a recommendation, click ✕ on a 'New' item
- Confirm the dialog
- Status changes to "Ignored"

#### Filter Recommendations
- Use the **Status** dropdown to show only New, Accepted, Applied, or Done items
- Use the **Type** dropdown to show only specific action types
- Use the **Target** dropdown to focus on a specific geographic area
- Use the **Season** dropdown to filter by seasonal campaigns
- Filters update the table instantly (no page reload)

#### View Targeting Settings
- Click **"Targeting Settings"** button
- Collapsible panel shows all active geographic targets (cities, postcodes, neighbourhoods)
- These targets boost the scoring of relevant recommendations

### Automatic Generation (Daily Cron)
- Runs every day at **3 AM UTC** (after GSC data syncs)
- Analyzes last 28 days of GSC data
- Creates new recommendations for high-value queries
- Updates scores if they change
- You don't need to do anything — it happens automatically

---

## 🔧 For Developers / DevOps

### Pre-Deployment Checklist

#### 1. Database Migration
```bash
mysql -u mowology_user -p mowology_landscape_crm < database/migrations/100_seo_recommendations.sql
```
This creates:
- `seo_targets` — Geographic targets (cities, postcodes, neighbourhoods)
- `seo_seasons` — Seasonal campaigns (spring cleanup, winter prep, etc.)
- `seo_recommendations` — Scored recommendations from GSC
- `seo_page_drafts` — AI-generated page content (for future CMS integration)
- `seo_recommendations_audit` — Audit log of all actions

#### 2. Cron Job Setup (cPanel)
Add to cPanel Cron Jobs at: `Home → Advanced → Cron Jobs`
```
0 3 * * * php /home/mowology/public_html/crm/cron/seo_recommendations.php >> /home/mowology/logs/seo_recommendations.log 2>&1
```

#### 3. Test Manually
```bash
php /home/mowology/public_html/crm/cron/seo_recommendations.php
```
Expected output:
```
[INFO] Using GSC snapshot from 42 (site: sc-domain:mowology.ca)
[INFO] Loaded 7 active targets
[INFO] Current season: spring-cleanup (ID: 1)
[INFO] Found 150 unique queries to analyze
[GEN] spring cleanup vancouver → spring-cleanup-vancouver (score: 85)
[GEN] strata maintenance → strata-maintenance (score: 62)
...
[DONE] SEO Recommendations: 150 analyzed, 12 generated, 3 updated, 0 errors in 8s
```

#### 4. Verify Deployment
- [ ] Database shows tables: `SHOW TABLES LIKE 'seo_%'`
- [ ] Sample data exists: `SELECT COUNT(*) FROM seo_targets` (should be 7)
- [ ] Cron job runs: Check `/home/mowology/logs/seo_recommendations.log`
- [ ] UI displays: Go to Portfolio → Recommendations tab
- [ ] CSS loads: Check for styled stats cards, buttons, table

### Architecture Overview

**File Structure:**
```
database/
  migrations/
    100_seo_recommendations.sql        ← All 5 tables + sample data

public/crm/
  portfolio/
    index.php                          ← Recommendations tab UI
    recommendations-data.php           ← Data provider (filtering, pagination)

  api/seo/
    generate.php                       ← Trigger cron job (manual)
    apply.php                          ← Create draft page
    apply-preview.php                  ← Preview before applying
    status.php                         ← Update recommendation status
    targets.php                        ← Manage geographic targets
    seasons.php                        ← Manage seasonal campaigns

  cron/
    seo_recommendations.php            ← Daily job (runnable via web with CSRF)

  includes/
    seo-functions.php                  ← Scoring engine, content generation

  css/
    mowology-brand.css                 ← Recommendations styling
```

**Data Flow:**
1. **Daily (3 AM):** `cron/seo_recommendations.php` runs
2. **Fetches:** Last GSC snapshot from `gsc_snapshots` + `gsc_query_page_stats`
3. **Scores:** Each query using `scoreRecommendation()` function (0-100 points)
4. **Stores:** New/updated recommendations in `seo_recommendations` table
5. **Logs:** All actions to `seo_recommendations_audit`
6. **UI:** Admin views in Portfolio → Recommendations tab
7. **Apply:** Admin clicks "Apply" → creates draft in `seo_page_drafts`

### Scoring Algorithm (Quick Reference)

Each recommendation gets a score (0-100) based on:
- **Impressions** (search volume): +20-35 points
- **Position** (current ranking): +5-25 points
- **CTR Signal** (click-through gaps): +10-30 points
- **Local Intent** (keywords like "vancouver", "near me"): +15 points
- **Postcode Pattern** (e.g., V5K): +10 points
- **Neighbourhood** (e.g., "kitsilano"): +10 points
- **Target Match** (city/postcode/neighbourhood): +10-20 points
- **Seasonal Boost** (matching current season): +25 points
- **Business Value** (strata, commercial): +10 points
- **Final:** Capped at 100 points

Queries with <15 impressions are skipped (too niche).

### API Endpoints (For Reference)

#### Generate Recommendations
```
POST /crm/api/seo/generate.php
Data: csrf_token (CSRF token)
Returns: {success, message, stats}
```

#### Apply Recommendation (Create Draft)
```
POST /crm/api/seo/apply.php
Data: recommendation_id, csrf_token
Returns: {success, draft_id, content}
```

#### Preview Before Apply
```
GET /crm/api/seo/apply-preview.php?id=123
Returns: {success, content: {title, meta_description, h1, slug, ...}}
```

#### Update Status
```
POST /crm/api/seo/status.php
Data: recommendation_id, status (new|accepted|applied|done|ignored), csrf_token
Returns: {success, message}
```

#### Manage Targets
```
GET /crm/api/seo/targets.php           ← List all targets
POST /crm/api/seo/targets.php          ← Create/update target
DELETE /crm/api/seo/targets.php        ← Deactivate target
```

#### Manage Seasons
```
GET /crm/api/seo/seasons.php           ← List all seasons
POST /crm/api/seo/seasons.php          ← Create/update season
DELETE /crm/api/seo/seasons.php        ← Deactivate season
```

All endpoints require:
- Admin authentication (`requireLogin()` + role check)
- CSRF token on POST/DELETE
- Proper Content-Type headers

### Security Notes

✅ **CSRF Protection:** All state-changing operations require CSRF token
✅ **SQL Injection:** All queries use prepared statements
✅ **XSS Prevention:** User content escaped before HTML output
✅ **Role-Based Access:** Admin-only enforcement on all endpoints
✅ **Audit Logging:** All actions logged with user_id, IP, timestamp
✅ **No Credentials:** No API keys or secrets hardcoded in files

### Monitoring & Troubleshooting

#### Check Cron Logs
```bash
tail -f /home/mowology/logs/seo_recommendations.log
```

#### Query Database Stats
```sql
-- How many recommendations exist?
SELECT COUNT(*) FROM seo_recommendations;

-- How many were generated today?
SELECT COUNT(*) FROM seo_recommendations WHERE DATE(created_at) = CURDATE();

-- What's the status distribution?
SELECT status, COUNT(*) FROM seo_recommendations GROUP BY status;

-- View audit log
SELECT * FROM seo_recommendations_audit ORDER BY created_at DESC LIMIT 20;
```

#### Common Issues

**No recommendations showing in UI:**
- Check cron logs for errors
- Verify GSC snapshot exists: `SELECT * FROM gsc_snapshots ORDER BY created_at DESC LIMIT 1;`
- Run cron manually: `php /home/mowology/public_html/crm/cron/seo_recommendations.php`

**Cron job not running:**
- Verify cPanel cron is active
- Check email for cron errors (usually sent to root@)
- Test manually to isolate issue

**Recommendations not updating:**
- Cron runs once daily at 3 AM
- To force re-run: Delete old rows or run cron manually
- Check if GSC snapshot is fresh (within last 28 days)

---

## 📚 Documentation Files

For detailed information, see:

| File | Purpose |
|------|---------|
| `PHASE_1_COMPLETE_SUMMARY.md` | What's built in Phase 1 (engine, API, database) |
| `PHASE_2_COMPLETE_SUMMARY.md` | What's built in Phase 2 (UI, modals, styling) |
| `SEO_RECOMMENDATIONS_IMPLEMENTATION.md` | Technical implementation details |
| `GSC_SEO_RECOMMENDATIONS_INTEGRATION_PLAN.md` | Original planning document |
| `SEO_RECOMMENDATIONS_QUICK_START.md` | **This file** |

---

## ✅ Deployment Checklist (TL;DR)

```
☐ Run database migration (100_seo_recommendations.sql)
☐ Add cron job to cPanel
☐ Test cron manually
☐ Verify UI in Portfolio → Recommendations
☐ Test all workflows (accept, apply, done, ignore)
☐ Monitor logs for first week
```

Once deployed, the system runs on its own. Admins just review and apply recommendations daily.

---

**Questions?** See the documentation files or check git history for context.

**Ready to deploy?** Follow the checklist above and you're good to go! 🚀

