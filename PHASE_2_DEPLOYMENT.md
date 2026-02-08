# Portfolio & Marketing Automation — Phase 2 Deployment Guide

**Status:** ✅ Phase 1 & 2 Complete (Media Pipeline + Dashboard UI)

## What Was Built

### 1. Database Schema (15_portfolio_automation.sql)
- **13 new tables** for complete media pipeline, galleries, GSC integration, and ROI tracking
- Location: `/public/crm/database/migrations/015_portfolio_automation.sql`
- Includes: media_files, media_metadata, visit_photo_sets, gsc_properties, lead_events, roi_attribution, etc.

### 2. Media Pipeline
**Files Created:**
- `/crm/media/upload.php` — AJAX file upload handler (staff)
- `/crm/media/process.php` — Image optimization processor (resize, WebP, compress, EXIF extract)
- `/crm/media/approve.php` — Admin approval handler
- `/crm/media/favorite.php` — AJAX favorite toggle

**Features:**
- Staff uploads photos via drag-drop or file select
- Automatically extracts EXIF timestamp (taken_at)
- Validates MIME type, extension, file size (10MB max)
- Stores original file + queues for optimization
- Admin review queue (approve/reject with reason)
- One-click favorite for marketing shortlist
- Audit logging of all actions

### 3. Helper Library
**File:** `/crm/includes/portfolio-functions.php`
- **ImageProcessor class** — Hybrid GD-based image optimization
  - Resize to max 2000px width (aspect ratio preserved)
  - JPEG compression at 85% quality
  - WebP generation (where supported)
  - 300x300 thumbnail generation
  - Graceful fallback if GD unavailable
- **Media functions** — CRUD + metadata + activity logging
- **Before/After pairing** — Group related photos
- **Statistics aggregation** — Portal dashboard stats

### 4. Portfolio Dashboard (Tabbed Interface)
**Updated File:** `/crm/portfolio/index.php`

**7 Tabs (All Functional):**
1. **Upload** — Drag-drop zone, recent uploads list
2. **Review** (Admin) — Pending photos, inline approve/reject buttons
3. **Favorites** — Marketing shortlist grid view
4. **Portfolio Items** — Existing projects table (with tab-specific filters)
5. **GSC Insights** (Admin) — Placeholder for Phase 3
6. **Recommendations** (Admin) — Placeholder for Phase 3
7. **ROI Dashboard** (Admin) — Placeholder for Phase 3

**Stats Row:**
- Total Media, Pending Review, Favorites, Published Items

### 5. Styling
**Updated File:** `/crm/css/mowology-brand.css`
- `.mw-portfolio-tabs` — Tab navigation styling
- `.mw-upload-drop` — Drag-drop zone with hover states
- `.mw-media-grid` — Responsive grid layout (auto-fill minmax)
- `.mw-media-item` — Individual media card with hover effects
- `.mw-media-favorite` — Heart button for toggling favorites
- All colors using CSS custom properties (`--mw-*`)

---

## Deployment Steps

### Step 1: Create Upload Directories

```bash
mkdir -p /public/uploads/media/original
mkdir -p /public/uploads/media/web
mkdir -p /public/uploads/media/thumbs
chmod 755 /public/uploads/media/original
chmod 755 /public/uploads/media/web
chmod 755 /public/uploads/media/thumbs
```

### Step 2: Run Database Migration

```sql
-- In phpMyAdmin or via SSH:
-- Execute the full contents of:
/public/crm/database/migrations/015_portfolio_automation.sql
```

Or via CLI:
```bash
mysql -u [user] -p [database] < /public/crm/database/migrations/015_portfolio_automation.sql
```

### Step 3: Verify Files Exist

```bash
# Check media module files
ls -la /public/crm/media/
# Expected: upload.php, process.php, approve.php, favorite.php

# Check helper library
ls -la /public/crm/includes/portfolio-functions.php

# Check updated dashboard
ls -la /public/crm/portfolio/index.php
```

### Step 4: Test Upload Directory Permissions

```bash
# Verify write permissions
touch /public/uploads/media/original/test.txt
rm /public/uploads/media/original/test.txt
echo "✓ Upload directory is writable"
```

### Step 5: Verify GD Library (Optional)

```bash
# SSH into your server and run:
php -i | grep -i gd
```

If GD is available, you'll see: `GD Support => enabled`
If not available, image optimization gracefully falls back to storing originals.

---

## Testing the Media Pipeline

### Test 1: Upload a Photo (Staff)

1. Go to `/crm/portfolio/index.php`
2. Click **Upload** tab
3. Drag a JPEG/PNG/WebP image into the drop zone
4. Expected:
   - File uploaded message
   - New photo appears in "Recent Uploads" list with "uploaded" status
   - Database record created in `media_files` table

### Test 2: Review & Approve (Admin)

1. Go to `/crm/portfolio/index.php` → **Review** tab
2. See pending photos with approve/reject buttons
3. Click approve button on a photo
4. Expected:
   - Photo status changes to "ready"
   - Photo disappears from Review tab
   - Activity log records the approval
   - `media_files.status` updated to 'ready'

### Test 3: Mark as Favorite

1. Go to `/crm/portfolio/index.php` → **Favorites** tab
2. Click heart button on a photo
3. Expected:
   - Heart button fills with orange color
   - Photo moves to Favorites tab
   - Activity log records the favorite action
   - `media_files.is_favorite` set to 1

### Test 4: Dashboard Stats

1. Go to `/crm/portfolio/index.php`
2. Check stat cards at top:
   - **Total Media** — should increase after upload
   - **Pending Review** — should decrease after approval
   - **Favorites** — should increase after favorite toggle
   - **Portfolio Items** — should show count from existing projects

### Test 5: Image Processing (Optional)

1. Upload a large photo (> 2MB)
2. Run image processor:
   ```bash
   php /public/crm/media/process.php
   ```
   Or access via browser: `/crm/media/process.php` (if admin)
3. Expected:
   - Original stored in `/uploads/media/original/`
   - Web-optimized JPEG in `/uploads/media/web/`
   - Thumbnail in `/uploads/media/thumbs/`
   - Media record updated with paths
   - Status changed to 'ready'

---

## Database Verification

### Check Tables Created

```sql
SHOW TABLES LIKE 'media%';
SHOW TABLES LIKE 'visit%';
SHOW TABLES LIKE 'gsc%';
SHOW TABLES LIKE 'lead%';
SHOW TABLES LIKE 'conversion%';
SHOW TABLES LIKE 'roi%';
SHOW TABLES LIKE 'client%';
SHOW TABLES LIKE 'portfolio%';
SHOW TABLES LIKE 'content%';
```

### Check Sample Data

```sql
-- After test upload:
SELECT id, owner_user_id, status, is_favorite, uploaded_at FROM media_files LIMIT 5;

-- Check metadata:
SELECT * FROM media_metadata LIMIT 5;

-- Check activity log:
SELECT * FROM media_activity_log ORDER BY created_at DESC LIMIT 5;
```

---

## File Structure Summary

```
/public/crm/
├── portfolio/
│   ├── index.php                 ✅ Updated (tabbed dashboard)
│   ├── create.php                (existing - unchanged)
│   ├── view.php                  (existing - unchanged)
│   └── ...
│
├── media/                        ✅ NEW
│   ├── upload.php                ✅ NEW
│   ├── process.php               ✅ NEW
│   ├── approve.php               ✅ NEW
│   └── favorite.php              ✅ NEW
│
├── includes/
│   ├── portfolio-functions.php   ✅ NEW
│   └── ...
│
└── css/
    └── mowology-brand.css        ✅ Updated
```

---

## Known Limitations & Fallbacks

1. **GD Library Not Available:** Image optimization skipped, originals stored as-is
2. **WebP Generation:** Only on PHP 7.1+, JPEG fallback used otherwise
3. **EXIF Data:** Not all cameras embed timestamps; falls back to upload time
4. **File Size:** Limited to 10MB per file (shared hosting safety)
5. **Concurrent Uploads:** Single file upload at a time (queue via process.php cron)

---

## Next Steps (Phase 3)

1. **GSC Integration** — OAuth 2.0 connect, daily snapshot pulls, insights dashboard
2. **Recommendations Engine** — Analyze GSC data, suggest landing pages + portfolio items
3. **ROI Attribution** — Track lead source → quote → job → revenue
4. **Client Galleries** — Before/after proof galleries + feedback capture

---

## Troubleshooting

### Upload Fails With "Cannot Create Upload Directory"

**Solution:** Ensure permissions:
```bash
chmod 755 /public/uploads
chmod 755 /public/uploads/media
chmod 755 /public/uploads/media/original
```

### CSRF Token Error

**Solution:** Ensure `generateCSRFToken()` is called in portfolio/index.php before the form
```php
$csrfToken = generateCSRFToken();
```

### Image Not Optimizing

**Solution:** Check if process.php is being called
```bash
# Manual trigger:
php /public/crm/media/process.php
```

Or set up cron:
```bash
0 * * * * php /path/to/public/crm/media/process.php >> /var/log/mowology-media.log 2>&1
```

### Photos Not Appearing in Review Tab

**Solution:** Check database:
```sql
SELECT * FROM media_files WHERE status = 'uploaded';
```

If empty, verify upload handler is being called:
- Check browser console for network errors
- Verify CSRF token is being sent
- Check PHP error logs

---

## Support & Documentation

- **Plan Document:** `/Users/timschofield/.claude/plans/virtual-crunching-glacier.md`
- **Database Schema:** `/public/crm/database/migrations/015_portfolio_automation.sql`
- **Helper Functions:** `/public/crm/includes/portfolio-functions.php`
- **Dashboard UI:** `/public/crm/portfolio/index.php`

