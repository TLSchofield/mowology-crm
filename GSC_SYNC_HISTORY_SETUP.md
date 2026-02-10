# GSC Sync History - Quick Setup Guide

## ⚡ Quick Start (5 minutes)

### Step 1: Run Database Migration
```bash
# Option A: Use migration manager via browser
# Go to /crm/api/migrations-manager.php and execute migration 110

# Option B: Manual SQL import
# Open phpMyAdmin, select your database, and import:
# /database/migrations/110_gsc_sync_history.sql
```

### Step 2: Verify Installation
1. Go to **CRM → Portfolio → GSC Insights** tab
2. Scroll down to "Data Pull History" section
3. Should show:
   - ✓ Summary stat cards (Total, Successful, Failed, Partial)
   - ✓ History table with column headers
   - ✓ Message "No sync history available" if first time

### Step 3: Test It Works
1. Click **"Sync Now"** button
2. Wait for sync to complete
3. Scroll down to Data Pull History table
4. Should show your sync with:
   - Date/time
   - Type: "manual"
   - Status: ✓ Success
   - Row counts
   - Your name

**Done!** 🎉 The feature is now working.

---

## 📊 What You'll See

### Summary Statistics (Top)
```
┌─────────────────┬─────────────┬─────────┬──────────┐
│  Total Syncs    │  Successful │ Failed  │ Partial  │
│      28         │     27      │    0    │    1     │
└─────────────────┴─────────────┴─────────┴──────────┘
```

### History Table (Main)
Shows every data pull with:
- **When** - Date & time of sync (start and end)
- **Type** - Manual or automatic (cron)
- **Status** - Success ✓, Failed ✗, or Partial ⚠
- **Duration** - How long it took in seconds
- **Data** - Rows processed, inserted, updated
- **Notes** - Error details or who triggered it

---

## 🔄 How It Works

### Automatic Tracking
Every time GSC data syncs (manual or automatic):
1. ✓ Sync starts → History record created
2. ✓ Data fetched → Rows logged
3. ✓ Data stored → Row counts recorded
4. ✓ Sync ends → Status updated

### What Gets Recorded
- **When:** Start date, end date, duration
- **What:** Property synced, rows processed/inserted/updated
- **Who:** User name (for manual syncs)
- **How:** Sync type (manual or cron)
- **Status:** Success, failed, or partial

### Where It's Logged
All history stored in new table: `gsc_sync_history`
- Keeps 30 days of history
- Automatically oldest records can be archived
- Search and filter capable

---

## 📱 Key Features

### 1. **Track Manual Syncs**
Every time you click "Sync Now":
- Recorded as manual sync
- Your name attached
- Exact time logged
- Data changes tracked

### 2. **Monitor Automatic Syncs**
Daily cron syncs logged automatically:
- Type: "cron"
- No user attached (automatic)
- All data changes recorded
- Errors captured

### 3. **See Data Volume**
Understand what's happening with your GSC data:
- Rows processed - total evaluated
- Rows inserted - new data added
- Rows updated - existing data modified
- Can spot anomalies (too many/few rows)

### 4. **Error Visibility**
When syncs fail:
- Status shows ✗ Failed or ⚠ Partial
- Click error badge to see details
- Full error message in tooltip
- Helps diagnose issues

### 5. **Audit Trail**
Know exactly what happened with your data:
- Who synced (for manual)
- When it happened
- How long it took
- Whether it succeeded
- Any problems

---

## 🎯 Common Use Cases

### "How often is my data updated?"
Look at the history table. You'll see:
- Automatic cron syncs daily at 2 AM
- Manual syncs whenever you click the button
- Exact timestamps for each

### "Is my data current?"
Check the latest entry:
- Recent ✓ Success = Data is current
- Failed or error = Data may be stale
- Check timestamp in summary box

### "Are there sync errors?"
Look for:
- ✗ Failed status = Complete failure
- ⚠ Partial status = Some data pulled
- Click to see error details

### "How much data do I have?"
Look at row counts in latest sync:
- Processed = Total keywords evaluated
- Inserted = New opportunities found
- Updated = Existing data refreshed

### "When did my GSC data last sync?"
Check Data Pull History table:
- Top row = Most recent sync
- Timestamp = Exact date/time
- Status = Whether it succeeded

---

## 🔧 Troubleshooting

### No history showing after sync
**Problem:** I synced but don't see it in history
**Solution:**
1. Refresh the page (F5)
2. Make sure you're on GSC Insights tab
3. Scroll down to "Data Pull History" section
4. Check that sync actually completed successfully

### History table is empty
**Problem:** No syncs have happened yet
**Solution:**
1. This is normal for new setup
2. Click "Sync Now" to trigger first sync
3. After sync completes, history will show

### Wrong data in history
**Problem:** Row counts or info don't look right
**Solution:**
1. Check actual database: `SELECT * FROM gsc_query_page_stats`
2. Verify GSC connection is active
3. Check error log: `/var/log/php-errors.log`
4. Try manual sync again

### History not loading
**Problem:** Table shows error or doesn't load
**Solution:**
1. Check database migration ran: `SHOW TABLES LIKE 'gsc_sync%'`
2. Verify table columns exist
3. Check user has admin role
4. Check browser console for JavaScript errors (F12)

---

## 📋 Verification Checklist

After setup, verify:

- [ ] Database migration executed successfully
- [ ] `gsc_sync_history` table exists in database
- [ ] GSC Insights tab loads without errors
- [ ] Summary stats cards visible
- [ ] History table visible (even if empty)
- [ ] Can perform manual sync
- [ ] New sync appears in history after completion
- [ ] Row counts populated
- [ ] Duration shows
- [ ] User name shows for manual syncs

---

## 🚀 Next Steps

### For Better Insights
1. **Set up daily cron** - Automatic syncs at 2 AM
   ```crontab
   0 2 * * * php /home/mowology/public_html/crm/gsc/sync-cron.php
   ```

2. **Monitor trends** - Watch history table over time
   - Are syncs always successful?
   - Is data volume consistent?
   - Any patterns in failures?

3. **Set alerts** - Know when syncs fail
   - Check log daily
   - Set calendar reminder
   - Or ask for email alert feature

### For Advanced Use
- Export sync history for reporting
- Correlate with website changes
- Analyze sync performance trends
- Audit trail compliance

---

## 📞 Support

If you encounter issues:

1. **Check migration** - Run 110_gsc_sync_history.sql
2. **Verify permissions** - User must be admin
3. **Check database** - Verify tables exist
4. **Review logs** - Check PHP error log
5. **Browser console** - Press F12, check for errors

---

**Status:** ✓ Ready to use
**Setup Time:** ~5 minutes
**Data Retention:** 30 days
**Admin Only:** Yes
