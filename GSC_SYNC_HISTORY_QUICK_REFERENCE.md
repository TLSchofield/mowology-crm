# GSC Sync History - Quick Reference Card

## 🚀 Installation (3 Steps)

### 1. Run Migration
```bash
Navigate to: /crm/portfolio/index.php?tab=insights
Run migration: 110_gsc_sync_history.sql
```

### 2. Verify
```bash
Go to: CRM → Portfolio → GSC Insights
Look for: "Data Pull History" section (new!)
```

### 3. Test
```bash
Click: "Sync Now" button
Wait: For sync to complete
Check: History table shows new entry
```

---

## 📊 What You'll See

### Summary Cards (Top)
```
Total Syncs: 28 | Successful: 27 | Failed: 0 | Partial: 1
```

### History Table (Main)
| Date | Type | Status | Duration | Processed | Notes |
|------|------|--------|----------|-----------|-------|
| Feb 9, 14:32 | manual | ✓ Success | 23s | 1250 | John |
| Feb 9, 02:00 | cron | ✓ Success | 18s | 1248 | Auto |

---

## ✨ Key Features

| Feature | What It Shows | Where |
|---------|---------------|-------|
| **Status** | Success ✓ / Failed ✗ / Partial ⚠ | Badge column |
| **Duration** | How long sync took | Duration column |
| **Type** | Manual or automatic | Type column |
| **User** | Who triggered manual sync | Notes column |
| **Rows** | Data volume processed | Processed/Inserted/Updated |
| **Errors** | What went wrong | Error badge/tooltip |

---

## 🎯 Common Questions

### "Is my data current?"
**Check:** Latest entry → Status ✓ Success → Timestamp recent
**Yes if:** Last successful sync was today

### "When was data last pulled?"
**Look at:** Top row (latest sync) → Timestamp
**Example:** "Feb 9, 14:32" = 2:32 PM today

### "Did my sync work?"
**Check:** Status badge
- ✓ Success = Yes, worked
- ✗ Failed = No, error
- ⚠ Partial = Mostly worked

### "How much data?"
**Look at:** "Processed" column
**This shows:** Total keywords from GSC API

### "What's wrong?"
**Click:** Error badge → See error details
**Fix:** Address the specific error shown

---

## 📈 How It Works

```
GSC API Data
    ↓
You click "Sync Now"
    ↓
Sync starts → History record created (pending)
    ↓
Data fetches → Rows processed logged
    ↓
Data stored → Rows inserted/updated logged
    ↓
Sync completes → Status updated (success/failed)
    ↓
History table shows new entry
```

---

## 🔍 What's Tracked

✅ **When** - Date, time, duration
✅ **What** - Rows processed, inserted, updated
✅ **Who** - User name (manual only)
✅ **How** - Sync type (manual/cron)
✅ **Status** - Success/failed/partial
✅ **Errors** - Error messages (if failed)

---

## 🛠️ Troubleshooting

| Problem | Solution |
|---------|----------|
| No history showing | Refresh page, click Sync Now |
| Table doesn't show | Run migration 110 |
| Error details hidden | Hover on error badge |
| Row counts missing | Check GSC connection active |
| Sync not logging | Verify DB migration ran |

---

## 📋 Database Info

**Table:** `gsc_sync_history`
**Data kept:** Last 30 days
**Accessed:** When viewing GSC Insights tab
**Updated:** After every sync (manual or cron)

---

## ⚙️ System Info

**Automatic Syncs:** Daily at 2:00 AM (cron)
**Manual Syncs:** When you click "Sync Now"
**History Kept:** 30 days
**Access:** Admin users only
**Performance:** No impact on speed

---

## 📞 Support Checklist

- [ ] Migration ran successfully
- [ ] `gsc_sync_history` table exists
- [ ] GSC Insights tab loads
- [ ] Summary cards visible
- [ ] History table visible
- [ ] Manual sync creates entry
- [ ] Row counts shown
- [ ] User name appears

---

## 🎓 Viewing History

### Step 1: Navigate
```
CRM → Portfolio → GSC Insights tab
```

### Step 2: Scroll Down
```
Find: "Data Pull History (Last 30 Days)"
See: Summary stats and table
```

### Step 3: Read the Data
```
Date/Time    = When sync ran
Type         = Manual or automatic
Status       = Success/failed/partial
Duration     = How long (seconds)
Processed    = Total records
Inserted     = New records added
Updated      = Existing updated
Notes        = Error or user info
```

### Step 4: Check for Issues
```
Red status = Sync failed
Orange = Partial success
Green = All good
Blue = Still pending
```

---

## 💡 Pro Tips

1. **Check daily** - Verify automatic syncs happen
2. **Track failures** - Note any red status entries
3. **Monitor volume** - Watch row count trends
4. **Watch duration** - Slower syncs might indicate issues
5. **Archive old** - Keep records for audit trail

---

## 🔗 Related Files

- GSC Insights: `/crm/portfolio/index.php`
- Sync Engine: `/crm/gsc/sync-cron.php`
- Snapshots: `/crm/gsc/snapshots.php`
- History Data: `/crm/gsc/sync-history.php`

---

## 📝 Notes

**Created:** GSC Sync History Tracking System
**Status:** ✓ Ready to use
**Setup Time:** ~5 minutes
**Requires:** Database migration 110
**Access:** Admin users only
**Data:** 30 days retained

---

## ✅ Quick Checklist

After installing, confirm:
- [ ] Migration succeeded
- [ ] Table created
- [ ] History section visible
- [ ] Summary cards show
- [ ] Manual sync records
- [ ] Table updates

**All checked? You're good to go!** 🚀
