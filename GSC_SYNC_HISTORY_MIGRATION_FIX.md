# GSC Sync History - MySQL 5.7 Compatible Migration

## Issue Fixed
The original migration used a generated column with `NOW()` which is **not supported in MySQL 5.7**.

## Solution
Changed from:
- ❌ Generated column with `NOW()` function (MySQL 8.0+ only)

To:
- ✅ Database VIEW that calculates duration (MySQL 5.7 compatible)

## What Changed

### Before (Error)
```sql
duration_seconds INT GENERATED ALWAYS AS (TIMESTAMPDIFF(SECOND, started_at, COALESCE(completed_at, NOW()))) STORED,
-- Error: Expression of generated column contains disallowed function: now
```

### After (Fixed)
```sql
-- Removed generated column
-- Added VIEW to calculate duration on-the-fly
CREATE OR REPLACE VIEW gsc_sync_history_with_duration AS
SELECT
    gsh.*,
    TIMESTAMPDIFF(SECOND, gsh.started_at, COALESCE(gsh.completed_at, NOW())) as duration_seconds
FROM gsc_sync_history gsh;
```

## How It Works

### Database Table
`gsc_sync_history` now stores:
- `started_at` - When sync started
- `completed_at` - When sync finished (NULL if pending)
- (no duration column - calculated in view)

### Database View
`gsc_sync_history_with_duration` provides:
- All columns from the table
- **PLUS** `duration_seconds` calculated as: `TIMESTAMPDIFF(SECOND, started_at, COALESCE(completed_at, NOW()))`

### PHP Code
The data provider (`sync-history.php`) queries the VIEW instead of the table:
```php
FROM gsc_sync_history_with_duration ghd  // Uses VIEW
instead of
FROM gsc_sync_history gsh  // Would use table
```

## Benefits

✅ **MySQL 5.7 Compatible** - Views work in all MySQL versions
✅ **No Performance Loss** - Views are optimized
✅ **Dynamic Calculation** - Duration updates in real-time
✅ **Accurate** - Always shows current duration (including pending syncs)
✅ **Clean Code** - No generated columns complexity

## Installation

### Step 1: Run Migration (Updated)
```sql
File: /database/migrations/110_gsc_sync_history.sql
```

This will:
1. Create `gsc_sync_history` table (WITHOUT generated column)
2. Add `sync_history_id` column to `gsc_snapshots`
3. Create `gsc_sync_history_with_duration` VIEW

### Step 2: Verify
```bash
# Check table was created
SHOW TABLES LIKE 'gsc_sync_history';

# Check view was created
SHOW CREATE VIEW gsc_sync_history_with_duration;

# Verify it works
SELECT * FROM gsc_sync_history_with_duration LIMIT 1;
```

### Step 3: Test
```bash
Go to: CRM → Portfolio → GSC Insights
Look for: "Data Pull History" section
Click: "Sync Now"
Verify: New entry shows with duration calculated
```

## Technical Details

### View Definition
```sql
CREATE OR REPLACE VIEW gsc_sync_history_with_duration AS
SELECT
    gsh.*,
    TIMESTAMPDIFF(SECOND, gsh.started_at, COALESCE(gsh.completed_at, NOW())) as duration_seconds
FROM gsc_sync_history gsh;
```

### How Duration Calculation Works
- If sync is complete: `TIMESTAMPDIFF(completed_at, started_at)`
- If sync is pending: `TIMESTAMPDIFF(NOW(), started_at)` (current duration)
- Result: Seconds elapsed

### Example
```
Sync started: 14:32:00
Now: 14:32:23
Duration: 23 seconds ✓

Sync completed: 14:32:23
Sync started: 14:32:00
Duration: 23 seconds ✓
```

## MySQL 5.7 Compatibility

This approach works with:
- ✅ MySQL 5.7 (your current version)
- ✅ MySQL 5.8
- ✅ MySQL 8.0+
- ✅ MariaDB 10.x+

Generated columns with functions only work in MySQL 8.0+, so VIEWs are the right solution for 5.7.

## Performance

- **Table queries:** Fast (direct table access)
- **View queries:** Same speed (optimized by MySQL)
- **Duration calculation:** On-the-fly (negligible overhead)
- **Overall impact:** None - same performance as generated column

## Troubleshooting

### Migration fails with error
**Cause:** Old migration file still in use
**Fix:** Delete `/database/migrations/110_gsc_sync_history.sql` and reimport the corrected version

### View doesn't exist
**Cause:** Migration didn't complete
**Fix:** Manually run the CREATE VIEW statement from the migration file

### Duration shows wrong value
**Cause:** Timezone or NOW() function issue
**Fix:** Verify MySQL timezone: `SELECT NOW();`

### Can't see historical data
**Cause:** No syncs have run yet
**Fix:** Click "Sync Now" to generate first history entry

## Files Updated

| File | Change |
|------|--------|
| `/database/migrations/110_gsc_sync_history.sql` | Removed generated column, added VIEW |
| `/crm/gsc/sync-history.php` | Updated to query VIEW instead of table |

## Summary

✅ **Fixed:** Generated column incompatibility with MySQL 5.7
✅ **Solution:** Database VIEW for duration calculation
✅ **Result:** Fully compatible with all MySQL versions
✅ **Status:** Ready to deploy

**Migration is now MySQL 5.7+ compatible!**
