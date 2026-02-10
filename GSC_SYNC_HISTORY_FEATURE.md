# GSC Data Pull History Feature

## Overview
A new data pull history feature has been added to the GSC Insights tab to track and display all Google Search Console data synchronizations.

## What's New

### 1. Sync History Database Table
**File:** `/database/migrations/110_gsc_sync_history.sql`

Created new table `gsc_sync_history` with columns:
- `id` - Unique record ID
- `property_id` - Which GSC property was synced
- `sync_type` - Type of sync (manual, cron, api)
- `status` - Result status (pending, success, failed, partial)
- `rows_processed` - How many rows were processed
- `rows_inserted` - How many new rows added
- `rows_updated` - How many rows updated
- `error_message` - Any error details
- `started_at` - When sync started
- `completed_at` - When sync finished
- `duration_seconds` - How long the sync took (calculated field)
- `initiated_by_user_id` - Which user triggered manual sync (if applicable)
- `notes` - Additional information

### 2. Sync History Data Provider
**File:** `/crm/gsc/sync-history.php`

New PHP file that:
- Queries last 30 days of sync history
- Returns list of sync records with all details
- Calculates summary statistics (total, successful, failed, partial)
- Handles database errors gracefully

### 3. GSC Insights Tab Display
**File:** `/crm/portfolio/index.php` (modified)

Added to the GSC Insights tab:
- **Summary Stats Cards** showing:
  - Total syncs in last 30 days
  - Successful syncs (green)
  - Failed syncs (red)
  - Partial syncs (orange)
- **Detailed Sync History Table** showing:
  - Date & time of sync (start and end)
  - Sync type (manual/cron/api)
  - Status badge (success/failed/partial/pending)
  - Duration in seconds
  - Rows: processed, inserted, updated
  - Notes/error details
  - Who initiated it (if manual)

### 4. Automatic Logging
**File:** `/crm/gsc/sync-cron.php` (modified)

The sync process now automatically:
- Creates a sync history record when starting
- Logs all properties synced
- Records rows processed, inserted, updated
- Tracks any errors that occur
- Marks completion status (success/failed/partial)
- Records who triggered manual syncs
- Timestamps everything

## Features

✅ **Track All Data Pulls**
- See every GSC sync attempt (manual and automatic)
- Know when data was pulled
- How long each sync took

✅ **Monitor Sync Success**
- Green = Successful sync
- Red = Failed sync
- Orange = Partial success (some properties failed)
- Blue = Pending/in-progress

✅ **See Data Volume**
- Rows processed - total records evaluated
- Rows inserted - new records added
- Rows updated - existing records modified

✅ **Error Details**
- Click on error badge to see details
- Hover for full error messages
- Helps troubleshoot sync issues

✅ **Manual vs Automatic**
- Know if sync was automatic (cron) or manual (user-triggered)
- See which user triggered manual syncs
- Audit trail of GSC data management

✅ **30-Day History**
- Last 30 days of sync records
- Latest syncs shown first
- Summary stats for trend tracking

## How Sync Tracking Works

### When a Manual Sync Happens
1. User clicks "Sync Now" on GSC Insights tab
2. JavaScript sends POST request to `/crm/gsc/sync-cron.php`
3. Sync process creates history record (pending status)
4. Fetches GSC data
5. Processes and stores data
6. Updates history record with results (success/failed/partial)
7. User sees results

### When Automatic Sync Happens (Cron)
1. Scheduled cron job runs: `php /crm/gsc/sync-cron.php`
2. Same logging happens as manual sync
3. Creates history record with type='cron'
4. No user ID recorded (automatic)

### Error Handling
- If sync fails partway through, status = 'partial'
- If all properties fail, status = 'failed'
- If all succeed, status = 'success'
- Error messages stored for debugging

## Display Examples

### Summary Stats (Top of Sync History Table)
```
Total Syncs (30d): 28    Successful: 27    Failed: 0    Partial: 1
```

### History Table Rows
```
Date & Time          | Type   | Status    | Duration | Processed | Inserted | Updated | Notes
Feb 09, 14:32        | manual | ✓ Success | 23s      | 1250      | 45       | 1205    | Manual sync by John
Feb 09, 02:00        | cron   | ✓ Success | 18s      | 1248      | 48       | 1200    | Automatic daily sync
Feb 08, 14:15        | manual | ⚠ Partial | 12s      | 800       | 30       | 770     | (Error icon)
Feb 08, 02:00        | cron   | ✓ Success | 20s      | 1240      | 50       | 1190    | Automatic daily sync
```

## Database Setup

To enable this feature, run the migration:
```sql
php /crm/api/migrations-manager.php execute 110_gsc_sync_history.sql
```

Or manually in phpMyAdmin:
```sql
-- Import /database/migrations/110_gsc_sync_history.sql
```

## Integration Points

### 1. Sync Process
The `/crm/gsc/sync-cron.php` script now:
- Creates history entry at start (pending)
- Updates per-property row counts
- Marks final status (success/failed/partial)
- Logs errors for debugging

### 2. UI Display
The portfolio index.php now:
- Loads sync history data
- Shows summary stats
- Displays detailed history table
- Links to error details

### 3. Data Access
Admin users can view:
- Personal manual sync history
- All automatic (cron) sync history
- Organization-wide sync trends

## Testing the Feature

### Manual Sync Test
1. Go to CRM → Portfolio → GSC Insights
2. Click "Sync Now" button
3. Wait for sync to complete
4. Scroll down to "Data Pull History" table
5. Should see new entry with:
   - Status: ✓ Success
   - Your name as initiator
   - Row counts populated
   - Duration showing

### Verify Cron Logging
1. Wait for automatic cron run (2 AM daily) or manually trigger:
   ```bash
   php /home/mowology/public_html/crm/gsc/sync-cron.php
   ```
2. New history entry should appear with:
   - Status: ✓ Success
   - Type: cron
   - No user initiator
   - Row counts and duration

### Error Handling Test
1. Temporarily disable GSC connection
2. Try manual sync
3. Should see entry with:
   - Status: ✗ Failed
   - Error message displayed
   - Duration shows time before failure

## Files Modified

| File | Change |
|------|--------|
| `/database/migrations/110_gsc_sync_history.sql` | Created sync history table |
| `/crm/gsc/sync-history.php` | New data provider for history display |
| `/crm/portfolio/index.php` | Added sync history stats and table to insights tab |
| `/crm/gsc/sync-cron.php` | Added automatic logging to sync process |

## Future Enhancements

Possible additions:
- Email alerts for failed syncs
- Slack notifications
- Detailed error reports
- Sync performance analytics
- Auto-retry on failure
- Rate limiting protection
- Bulk sync operations

## Troubleshooting

### No history showing
- Check migration ran successfully
- Verify `gsc_sync_history` table exists in database
- Check user has admin role
- Perform a sync and check if history is created

### History not updating
- Check `/crm/gsc/sync-cron.php` has latest code
- Verify `gsc_sync_history` table has proper permissions
- Check error log for database errors
- Run sync manually and check logs

### Errors not displaying
- Ensure error_message column populated
- Check JavaScript console for errors
- Verify HTML rendering of error tooltip

## Related Files

- `/crm/gsc/connect.php` - GSC connection setup
- `/crm/gsc/snapshots.php` - Current GSC data display
- `/crm/portfolio/index.php` - Main portfolio dashboard
- `/crm/gsc/sync-cron.php` - Sync engine
- `/app_config/config.php` - App configuration

## Security

- Only admin users can view sync history
- Sync is authenticated (CSRF token required for manual)
- Error messages don't expose sensitive data
- User audit trail for manual syncs
- No personally identifiable information stored

---

**Status:** ✓ Feature complete and ready to use
**Visibility:** Admin users only
**Data Retention:** Last 30 days (configurable)
**Performance:** Minimal impact on sync time
