# 🎯 SEO Recommendations Engine — Complete System Documentation

**Project:** Mowology Landscaping CRM
**Feature:** Automated SEO Opportunity Discovery & Draft Page Generation
**Status:** ✅ **PRODUCTION READY** (Phase 1 + Phase 2 Complete)
**Date Completed:** February 8, 2026

---

## 📖 Table of Contents

1. [What Is This?](#what-is-this) — Feature overview
2. [Quick Start](#quick-start) — Get deployed in 10 minutes
3. [Documentation Map](#documentation-map) — Find what you need
4. [Architecture](#architecture) — How it works
5. [Files Created](#files-created) — Complete file list
6. [Deployment](#deployment) — Step-by-step instructions
7. [Workflow](#workflow) — What admins do daily

---

## 🎯 What Is This?

The **SEO Recommendations Engine** is an automated system that:

1. **Pulls GSC data** daily (28-day window)
2. **Analyzes queries** using a 10-signal scoring algorithm
3. **Generates recommendations** for high-value opportunities
4. **Creates draft pages** with AI-generated content
5. **Provides a UI** for admins to accept/apply recommendations

**Real-world example:**
- GSC shows "spring cleanup vancouver" gets 150 impressions, ranks #15, 0.8% CTR
- Scoring algorithm gives it a score of 85/100 (high priority)
- System recommends: "Create/improve page: spring cleanup vancouver"
- Admin clicks "Apply" → draft page created with:
  - Title: "Expert Spring Cleanup in Vancouver"
  - Meta: "Professional spring cleanup services in Vancouver. Free quotes available."
  - H1: "Expert spring cleanup in Vancouver"
  - Content sections: Hero + Features + Portfolio images
  - Internal links & JSON-LD schema
- Admin reviews and publishes (manual copy/paste for now, auto-publish in future)

---

## 🚀 Quick Start

### For Users (Admins)
1. **Pre-requirement:** GSC is already connected and syncing (done in Phase 0)
2. **Deploy:**
   - Database migration (run once): See [Deployment](#deployment)
   - Add cron job to cPanel (run once): See [Deployment](#deployment)
   - Deploy code (git push): Automatic
3. **Use:**
   - Portfolio → Recommendations tab
   - Click "Generate Recommendations" (or wait for daily 3 AM cron)
   - Accept → Apply → Done workflow

### For DevOps
See [Quick Start Guide](./SEO_RECOMMENDATIONS_QUICK_START.md) for 5-minute deployment.

---

## 📚 Documentation Map

**Choose your starting point:**

| Document | Purpose | For Whom |
|----------|---------|----------|
| **[Quick Start](./SEO_RECOMMENDATIONS_QUICK_START.md)** | Deploy in 10 min | DevOps, Admins |
| **[Phase 1 Summary](./PHASE_1_COMPLETE_SUMMARY.md)** | What's built (engine, API, DB) | Technical leads |
| **[Phase 2 Summary](./PHASE_2_COMPLETE_SUMMARY.md)** | What's built (UI, modals, JS) | Frontend devs |
| **[Implementation Guide](./SEO_RECOMMENDATIONS_IMPLEMENTATION.md)** | Technical details & API reference | Developers |
| **[Planning Doc](./GSC_SEO_RECOMMENDATIONS_INTEGRATION_PLAN.md)** | Original specs & design (563 lines) | Architects |

**Git Commits (in order):**
```
7bf3298 - Integration plan
0bda0ee - Engine, API, migrations
dd7eb1b - Implementation guide
0c7973a - Phase 1 summary
d1048b7 - UI integration (Phase 2)
e2272c6 - Phase 2 summary
55823e6 - Quick start guide
```

---

## 🏗️ Architecture

### System Overview
```
GSC Data (daily 2 AM)
    ↓
gsc_snapshots + gsc_query_page_stats
    ↓
seo_recommendations.php (cron, 3 AM)
    ↓
scoreRecommendation() — 10-signal algorithm → 0-100 score
    ↓
seo_recommendations table (INSERT OR UPDATE)
    ↓
Portfolio UI → Admin reviews
    ↓
Admin clicks "Apply"
    ↓
seo_page_drafts table (AI-generated content)
    ↓
Ready for publish (manual copy/paste, auto-publish in future)
```

### Scoring Signals (0-100 Points)

| Signal | Value | Points | Example |
|--------|-------|--------|---------|
| Impressions | 100+ | +35 | "spring cleanup vancouver" has 150 impressions |
| Position | 15-20 | +15 | Ranking #15 (near first page) = "near win" |
| CTR Gap | 0 clicks, 50+ impr | +30 | People see but don't click = critical gap |
| Local Intent | "vancouver", "near me" | +15 | Keywords indicate local search |
| Postcode | V5K, V6B, etc | +10 | Postcode patterns = local focus |
| Neighbourhood | "kitsilano" | +10 | Specific neighbourhood keyword |
| Target Match | City/postcode/hood | +10-20 | With weight boost applied |
| Seasonal | Spring cleanup window | +25 | Timing boost for current season |
| Business Value | "strata", "commercial" | +10 | High-value B2B keywords |
| **Final** | Capped | **100** | All signals combined |

### Database Tables (5 total)

1. **seo_targets** — Geographic targeting zones
   - Cities (Vancouver, Burnaby, Richmond)
   - Postcodes (V5K, V6B)
   - Neighbourhoods (Kitsilano, Mount Pleasant)
   - Pre-loaded with 7 defaults

2. **seo_seasons** — Seasonal campaigns
   - Spring Cleanup (Mar 15 - May 31)
   - Summer Maintenance (Jun 1 - Aug 31)
   - Fall Cleanup (Sep 1 - Nov 15)
   - Winter Storm Prep (Nov 15 - Feb 28)
   - Strata & Commercial (Year-round)
   - Pre-loaded with 5 defaults

3. **seo_recommendations** — Main recommendations table
   - Unique constraint: (query_text, rec_type, suggested_slug)
   - Prevents duplicates on re-run
   - Tracks: score, status, target, season, reason
   - Statuses: new, accepted, applied, done, ignored

4. **seo_page_drafts** — AI-generated page content
   - Stores: title, meta, H1, sections JSON, images JSON, schema JSON
   - Status: draft, review, scheduled, published (for future phases)
   - Links back to recommendation

5. **seo_recommendations_audit** — Compliance logging
   - Every action logged: user_id, action, old/new status, timestamp, IP
   - For audit trail and troubleshooting

---

## 📂 Files Created

### Backend (Engine & APIs)

| File | Lines | Purpose |
|------|-------|---------|
| `database/migrations/100_seo_recommendations.sql` | 150 | 5 tables + sample data (idempotent) |
| `public/crm/includes/seo-functions.php` | 500+ | Scoring engine, content generation helpers |
| `public/crm/cron/seo_recommendations.php` | 350+ | Daily job, runnable via web |
| `public/crm/api/seo/generate.php` | 50 | Manual trigger endpoint |
| `public/crm/api/seo/apply.php` | 200+ | Create draft page |
| `public/crm/api/seo/apply-preview.php` | 60 | Preview before applying |
| `public/crm/api/seo/status.php` | 85 | Update recommendation status |
| `public/crm/api/seo/targets.php` | 110 | Manage geographic targets |
| `public/crm/api/seo/seasons.php` | 110 | Manage seasonal campaigns |

### Frontend (UI & Styling)

| File | Lines | Purpose |
|------|-------|---------|
| `public/crm/portfolio/recommendations-data.php` | 130 | Data provider (filtering, pagination) |
| `public/crm/portfolio/index.php` | +650 | Recommendations tab UI + JavaScript |
| `public/crm/css/mowology-brand.css` | +250 | Recommendations styling |

### Documentation

| File | Purpose |
|------|---------|
| `PHASE_1_COMPLETE_SUMMARY.md` | Engine, API, DB summary |
| `PHASE_2_COMPLETE_SUMMARY.md` | UI, modals, JavaScript summary |
| `SEO_RECOMMENDATIONS_IMPLEMENTATION.md` | Technical details |
| `GSC_SEO_RECOMMENDATIONS_INTEGRATION_PLAN.md` | Original planning doc |
| `SEO_RECOMMENDATIONS_QUICK_START.md` | **Start here for deployment** |
| `SEO_RECOMMENDATIONS_README.md` | **This file** |

---

## 🔌 Core API Endpoints

### Generate Recommendations
```
POST /crm/api/seo/generate.php
Requires: Admin auth + CSRF token
Response: {success, message, stats: {queries_analyzed, recommendations_generated, ...}}
```

### Create Draft Page
```
POST /crm/api/seo/apply.php
Params: recommendation_id, csrf_token
Response: {success, draft_id, content: {title, meta, h1, slug, sections_count, images_count, seo_score}}
```

### Preview Before Apply
```
GET /crm/api/seo/apply-preview.php?id=123
Response: {success, content: {title, meta_description, h1, slug, sections_count, images_count, seo_score}}
```

### Update Status
```
POST /crm/api/seo/status.php
Params: recommendation_id, status (new|accepted|applied|done|ignored), csrf_token
Response: {success, message}
```

### Manage Targets
```
GET /crm/api/seo/targets.php              ← List
POST /crm/api/seo/targets.php             ← Create/update
DELETE /crm/api/seo/targets.php           ← Deactivate
Requires: Admin auth + CSRF (POST/DELETE)
```

### Manage Seasons
```
GET /crm/api/seo/seasons.php              ← List
POST /crm/api/seo/seasons.php             ← Create/update
DELETE /crm/api/seo/seasons.php           ← Deactivate
Requires: Admin auth + CSRF (POST/DELETE)
```

---

## 📋 Deployment

### Checklist (5 Steps, ~10 minutes)

#### Step 1: Database Migration
```bash
cd /path/to/mowology-crm
mysql -u mowology_user -p mowology_landscape_crm < database/migrations/100_seo_recommendations.sql
```
Verify:
```sql
SHOW TABLES LIKE 'seo_%';  -- Should show 5 tables
SELECT COUNT(*) FROM seo_targets;  -- Should show 7
```

#### Step 2: Add Cron Job
1. Log into cPanel
2. Home → Advanced → Cron Jobs
3. Add new cron:
```
0 3 * * * php /home/mowology/public_html/crm/cron/seo_recommendations.php >> /home/mowology/logs/seo_recommendations.log 2>&1
```
4. Save

#### Step 3: Test Cron Manually
```bash
php /home/mowology/public_html/crm/cron/seo_recommendations.php
```
Expected: `[DONE] SEO Recommendations: X analyzed, Y generated, Z updated, 0 errors`

#### Step 4: Deploy Code
```bash
git push origin main
```
(Auto-deploys to cPanel)

#### Step 5: Verify UI
1. Log into CRM as admin
2. Portfolio → Recommendations tab
3. Should see: Stats cards, Filter panel, (empty or populated) table
4. Try clicking "Generate Recommendations" button

✅ **Done!** System is live.

---

## 📊 Daily Workflow (For Admins)

### Automatic (Every Day, 3 AM)
- Cron job runs automatically
- Analyzes GSC data (last 28 days)
- Creates new recommendations
- Updates scores if changed
- Logs all actions

### Manual (Admin Workflow)
1. **Review** → Portfolio → Recommendations tab
2. **Filter** → Use Status, Type, Target, Season dropdowns
3. **Accept** → Click ✓ on promising recommendations
4. **Preview** → Click "Apply" to see preview
5. **Create Draft** → Click "Create Draft" in modal
6. **Publish** → (Manual for now) Copy content to your CMS

### Status Flow
```
new → Accept → accepted → Apply → applied → Done → done
  ↓
  Ignore → ignored
```

---

## 🔒 Security Features

✅ **CSRF Protection** — All POST/DELETE require CSRF tokens
✅ **Admin-Only** — Role enforcement on all endpoints
✅ **SQL Injection Prevention** — Prepared statements everywhere
✅ **XSS Prevention** — HTML escaping on all user output
✅ **Audit Logging** — Every action logged for compliance
✅ **No Hardcoded Secrets** — Credentials in `/app_config/secrets.php` only

---

## 🧪 Testing

### Quick Test (5 minutes)
```bash
1. Log in to CRM as admin
2. Go to Portfolio → Recommendations
3. Click "Generate Recommendations"
4. Wait for success alert
5. See new recommendations in table
6. Click Accept on one
7. Status should change to "Accepted"
8. Click "Apply"
9. Modal should show preview
10. Click "Create Draft"
```

### Full Testing
See [Phase 2 Testing Checklist](./PHASE_2_COMPLETE_SUMMARY.md#-testing-checklist-end-to-end)

---

## 🚨 Troubleshooting

| Issue | Solution |
|-------|----------|
| No recommendations showing | Check cron logs: `/home/mowology/logs/seo_recommendations.log` |
| Cron not running | Verify cPanel cron is active; test manually: `php /home/.../seo_recommendations.php` |
| UI not loading | Clear browser cache; verify CSS imports |
| "Admin access required" error | Verify user role is 'admin' in database |
| CSRF token errors | Verify CSRF token is being passed in POST/DELETE requests |

---

## 📈 Future Phases

### Phase 3: CMS Integration
- Direct publish to CMS (not just copy/paste)
- Auto-schedule published pages
- Tracking published page performance

### Phase 4: Advanced Analytics
- Measure impact of applied recommendations
- ROI calculation per recommendation
- A/B testing UI

### Phase 5: Machine Learning
- Predictive scoring (estimate traffic from recommendation)
- Auto-accept high-confidence recommendations
- Anomaly detection in scoring patterns

---

## 📞 Support

**For questions or issues:**
1. Check [Phase 2 Summary](./PHASE_2_COMPLETE_SUMMARY.md) for technical details
2. Check [Quick Start](./SEO_RECOMMENDATIONS_QUICK_START.md) for deployment help
3. Review [Implementation Guide](./SEO_RECOMMENDATIONS_IMPLEMENTATION.md) for API details
4. Check git logs for context: `git log --oneline | grep -i "seo\|recommendation"`

---

## ✅ Completion Status

| Phase | Component | Status |
|-------|-----------|--------|
| **1** | Database Tables | ✅ Complete |
| **1** | Scoring Engine | ✅ Complete |
| **1** | Daily Cron Job | ✅ Complete |
| **1** | API Endpoints | ✅ Complete |
| **1** | Content Generation | ✅ Complete |
| **2** | Recommendations UI | ✅ Complete |
| **2** | Filter & Sort | ✅ Complete |
| **2** | Accept/Apply/Done Workflow | ✅ Complete |
| **2** | Modals & Previews | ✅ Complete |
| **2** | CSS Styling | ✅ Complete |
| **3** | CMS Direct Publish | 🔄 Future |
| **4** | Analytics Dashboard | 🔄 Future |
| **5** | Machine Learning | 🔄 Future |

---

**🎉 System is production-ready and live!**

Start with the [Quick Start Guide](./SEO_RECOMMENDATIONS_QUICK_START.md) or choose a documentation file from the [map](#-documentation-map) above.

