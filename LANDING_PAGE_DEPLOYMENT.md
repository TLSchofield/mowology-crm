# Landing Page Deployment Guide

## Status

The landing page system has been created locally but needs to be deployed to production.

---

## What Was Created

### Code Files (Need Deployment)

**File 1:** `/public/includes/service-data/professional-lawn-mowing-care.php`
- **Size:** 12 KB
- **Status:** ✅ Created locally
- **Status on Production:** ❌ Not yet deployed
- **Purpose:** Contains landing page content, marketing config, email sequences

**File 2:** `/public/services/professional-lawn-mowing-care.php`
- **Size:** 1.5 KB
- **Status:** ✅ Created locally
- **Status on Production:** ⚠️ Deployed but file missing (with graceful error handling)
- **Purpose:** Page loader with session capture and template rendering

### Documentation Files

- `LANDING_PAGE_QUICK_START.md` — Quick reference
- `LANDING_PAGE_IMPLEMENTATION_SUMMARY.md` — Overview
- `TEMPLATE_TO_LANDING_PAGE_MAPPING.md` — Technical explanation
- `/public/PROFESSIONAL_LAWN_MOWING_LANDING_PAGE_GUIDE.md` — Complete reference
- `/public/LANDING_PAGE_MARKETING_INTEGRATION.md` — Email & remarketing docs
- `/public/LANDING_PAGE_DOCS_INDEX.md` — Navigation guide

---

## Deployment Steps

### Step 1: Push to GitHub

Commit the new files:

```bash
cd /Users/timschofield/Projects/mowology-crm

git add public/includes/service-data/professional-lawn-mowing-care.php
git add public/services/professional-lawn-mowing-care.php
git add *.md

git commit -m "Add: GSC-optimized landing page for professional lawn mowing/care"

git push origin main
```

### Step 2: Trigger GitHub Auto-Deploy

cPanel is configured to auto-deploy from GitHub:

1. Commit is pushed to GitHub
2. GitHub webhook triggers cPanel deployment
3. Files are automatically pulled to `/home/mowology/public_html/`
4. Changes are live on `https://mowology.ca`

**Timeline:** Usually 1-5 minutes

### Step 3: Verify Deployment

After deployment completes:

```bash
# Check files exist on production
ls -la /home/mowology/public_html/public/includes/service-data/professional-lawn-mowing-care.php
ls -la /home/mowology/public_html/public/services/professional-lawn-mowing-care.php

# Visit the page
https://mowology.ca/services/professional-lawn-mowing-care
```

---

## File Paths (Local vs Production)

### Local (Development)

```
/Users/timschofield/Projects/mowology-crm/
├── public/
│   ├── includes/
│   │   ├── service-data/
│   │   │   └── professional-lawn-mowing-care.php ✅
│   │   └── service-template.php
│   ├── services/
│   │   └── professional-lawn-mowing-care.php ✅
│   └── (other public files)
├── LANDING_PAGE_QUICK_START.md
├── LANDING_PAGE_IMPLEMENTATION_SUMMARY.md
└── (other docs)
```

### Production (After Deployment)

```
/home/mowology/public_html/
├── public/
│   ├── includes/
│   │   ├── service-data/
│   │   │   └── professional-lawn-mowing-care.php ← Will be here after push
│   │   └── service-template.php
│   ├── services/
│   │   └── professional-lawn-mowing-care.php ← Already here
│   └── (other files)
```

---

## Current Status on Production

### ✅ Page File Exists
- `/home/mowology/public_html/services/professional-lawn-mowing-care.php`
- Shows graceful error message if data file missing

### ❌ Data File Missing
- `/home/mowology/public_html/includes/service-data/professional-lawn-mowing-care.php`
- Will be deployed when GitHub push is made

### Result
- Visiting page shows: "Landing Page Not Yet Deployed"
- Will work automatically after deployment

---

## What Happens When You Push

### Before Push
```
URL: https://mowology.ca/services/professional-lawn-mowing-care

Result: "Landing Page Not Yet Deployed"
        (graceful error message)
```

### After Push (Automatic)
```
URL: https://mowology.ca/services/professional-lawn-mowing-care

Result: Full landing page with:
        • Hero section
        • Benefits cards
        • Services checklist
        • FAQ section
        • CTA button → Quote form
```

---

## Manual Deployment (If Auto-Deploy Fails)

If GitHub auto-deploy doesn't work, deploy manually via SSH:

```bash
# SSH to server
ssh user@mowology.ca

# Navigate to project
cd /home/mowology/public_html

# Pull latest code
git pull origin main

# Verify files
ls -la public/includes/service-data/professional-lawn-mowing-care.php
ls -la public/services/professional-lawn-mowing-care.php
```

---

## Files Included in Deployment

### Landing Page System (2 files)
- ✅ `public/includes/service-data/professional-lawn-mowing-care.php`
- ✅ `public/services/professional-lawn-mowing-care.php`

### Supporting Files (Already Exist)
- ✅ `public/includes/service-template.php` (renders landing pages)
- ✅ `public/includes/bootstrap.php` (loads config)
- ✅ `.htaccess` (URL rewriting for clean URLs)

### Documentation (6 files)
- ✅ `LANDING_PAGE_QUICK_START.md`
- ✅ `LANDING_PAGE_IMPLEMENTATION_SUMMARY.md`
- ✅ `TEMPLATE_TO_LANDING_PAGE_MAPPING.md`
- ✅ `LANDING_PAGE_DOCS_INDEX.md`
- ✅ `public/PROFESSIONAL_LAWN_MOWING_LANDING_PAGE_GUIDE.md`
- ✅ `public/LANDING_PAGE_MARKETING_INTEGRATION.md`

### Database Fixes (Already Deployed Earlier)
- ✅ `public/crm/quote-workflow.php` (fixed with COALESCE + fallback)
- ✅ `public/crm/api/fix-properties-columns.php` (auto-fix script)

---

## Testing Checklist After Deployment

- [ ] Page loads at `/services/professional-lawn-mowing-care`
- [ ] No 404 errors
- [ ] Hero section displays
- [ ] Benefits cards visible
- [ ] Services checklist shows all 6 services
- [ ] FAQ section functional (collapsible questions)
- [ ] "Get a Free Quote →" button works
- [ ] CTA links to `/quote?service=maintenance&src=professional-lawn-mowing-care`
- [ ] Quote form pre-fills with service type
- [ ] No console errors in browser
- [ ] Mobile responsive design works

---

## Rollback (If Needed)

If deployment causes issues:

```bash
# Revert the commits
git reset --hard origin/main~1

# Force push to GitHub
git push origin main --force

# Changes auto-deploy (removes landing page files)
```

---

## Documentation After Deployment

All documentation is in the repository:

**Quick Start (5 min):**
- `LANDING_PAGE_QUICK_START.md`

**Full Reference (30 min):**
- `public/PROFESSIONAL_LAWN_MOWING_LANDING_PAGE_GUIDE.md`

**Email & Automation (30 min):**
- `public/LANDING_PAGE_MARKETING_INTEGRATION.md`

**Navigation:**
- `LANDING_PAGE_DOCS_INDEX.md`

---

## Summary

| Step | Status | Action |
|------|--------|--------|
| Files created locally | ✅ Done | None needed |
| Files ready for deployment | ✅ Done | None needed |
| Push to GitHub | ⏳ Pending | Run: `git push origin main` |
| Auto-deploy to production | ⏳ Pending | Automatic after push |
| Verify on production | ⏳ Pending | Visit: `/services/professional-lawn-mowing-care` |

---

## Next Action

**1. Commit & Push to GitHub:**

```bash
git add public/includes/service-data/professional-lawn-mowing-care.php
git add public/services/professional-lawn-mowing-care.php
git commit -m "Add: GSC-optimized landing page for professional lawn mowing/care"
git push origin main
```

**2. Wait for auto-deployment (1-5 minutes)**

**3. Verify page works at:**
```
https://mowology.ca/services/professional-lawn-mowing-care
```

---

**Status:** ✅ Ready for Deployment
**Last Updated:** February 10, 2026
