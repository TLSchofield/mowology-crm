# Quick Deployment Guide — Media Pipeline Phase 2

**For Live Server Only (No Local Setup Needed)**

---

## What You Need to Do

### 1. Push Code to GitHub
Your code auto-deploys to cPanel. Just commit and push:

```bash
git add .
git commit -m "feat: add media pipeline & portfolio dashboard"
git push origin main
```

**Result:** cPanel auto-syncs the new files in ~1-2 minutes.

---

### 2. Create Upload Directories (SSH or cPanel File Manager)

**Via SSH (if you have it):**
```bash
ssh your-username@mowology.ca
cd public_html
mkdir -p uploads/media/original
mkdir -p uploads/media/web
mkdir -p uploads/media/thumbs
chmod 755 uploads/media/original uploads/media/web uploads/media/thumbs
```

**Via cPanel File Manager:**
1. Log into cPanel → File Manager
2. Navigate to `public_html`
3. Create folder: `uploads`
4. Inside `uploads`, create: `media`
5. Inside `media`, create three folders: `original`, `web`, `thumbs`

---

### 3. Run Database Migration

**Via phpMyAdmin (Easiest):**

1. Log into cPanel → phpMyAdmin
2. Select your database (`mowology_landscape_crm`)
3. Click **SQL** tab
4. Copy-paste the entire contents of:
   `/public/crm/database/migrations/015_portfolio_automation.sql`
5. Click **Go**

**Done!** You should see green ✓ messages for each table created.

---

### 4. Test It

Go to: `https://mowology.ca/crm/portfolio/index.php`

**You should see:**
- 7 tabs at top (Upload, Review, Favorites, Portfolio Items, GSC Insights, Recommendations, ROI)
- 4 stat cards (Total Media, Pending Review, Favorites, Portfolio Items)
- Upload tab active with a drag-drop zone

**Try uploading a photo:**
1. Drag a JPEG/PNG into the drop zone
2. Should see "Photo uploaded successfully"
3. Photo appears in Recent Uploads table

---

## Troubleshooting

### Upload Directory Error
**Problem:** Upload fails with "Cannot create upload directory"

**Solution:**
- Check permissions in cPanel File Manager
- Directories should be 755 (owner: read/write/execute, others: read/execute)
- If stuck, contact cPanel support to check directory ownership

### Database Error
**Problem:** Migration fails in phpMyAdmin

**Solution:**
- Copy-paste the SQL again, more carefully
- Look for error message (usually shows which line failed)
- Try running smaller chunks if needed
- Contact database support if stuck

### Photos Don't Appear After Upload
**Problem:** Upload works but photos don't show in Recent Uploads

**Solution:**
- Check browser console (F12 → Console) for errors
- Check if upload directories exist
- Verify database migration ran (check tables in phpMyAdmin)

---

## What Happens Next

**Phase 2 is complete!** You now have:

✅ Drag-drop photo upload
✅ Admin review & approve system
✅ Favorite marking for marketing
✅ Portfolio dashboard with 7 tabs

**Ready for Phase 3?**
- Client proof galleries (before/after with feedback)
- Google Search Console integration (OAuth + daily pulls)
- Recommendations engine (AI suggestions)
- ROI dashboard (lead→revenue tracking)

Let me know when you're ready!

