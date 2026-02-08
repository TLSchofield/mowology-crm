# GSC Data Sync - Cron Setup Guide

## Overview

The Google Search Console (GSC) data sync can be configured in two ways:

1. **Manual Sync** - Click the "Sync Now" button in the Portfolio → GSC Insights tab
2. **Automated Cron Job** - Data syncs automatically daily at 2:00 AM

## Manual Sync

Users with admin access can manually trigger a GSC data sync anytime from the GSC Insights tab by clicking the "Sync Now" button. This pulls the latest data from Google Search Console and updates all insights immediately.

## Automated Daily Cron Job (Recommended)

### Setup Instructions

1. **Connect to your cPanel/hosting control panel**
   - Log in with your account credentials
   - Navigate to the **Cron Jobs** section (under Advanced)

2. **Add a new cron job with these settings:**

   | Setting | Value |
   |---------|-------|
   | Common Settings | `Once Daily` |
   | Time | `2:00 AM` |
   | Command | `php /home/mowology/public_html/crm/gsc/sync-cron.php` |

   Or specify the exact time:
   - **Minute:** 0
   - **Hour:** 2
   - **Day:** * (daily)
   - **Month:** * (every month)
   - **Day of Week:** * (every day)

3. **Alternative: Direct Cron Command**

   If entering a custom cron expression:
   ```
   0 2 * * * php /home/mowology/public_html/crm/gsc/sync-cron.php
   ```

4. **Save and verify**
   - The cron job is now scheduled
   - It will run every day at 2:00 AM
   - Logs are typically stored in `/tmp/cron_log.txt` or visible in your hosting control panel

### Important Notes

- **Time Zone:** Verify the time zone in your cPanel settings. The 2:00 AM time is based on your server's time zone.
- **Path:** Adjust `/home/mowology/public_html/` to match your actual hosting path. Contact your hosting provider if unsure.
- **Errors:** If the cron job fails, check:
  - The PHP path is correct (`which php` to find it)
  - Google OAuth credentials are configured in `/app_config/secrets.php`
  - The database has `gsc_properties` table populated with connection info

## Cron Job Verification

After setting up the cron job:

1. Check your **Portfolio → GSC Insights** tab
2. Look for "Last pulled" timestamp
3. It should update to the current time after 2:00 AM

## Troubleshooting

### "No GSC data available yet"

- Ensure Google Search Console is connected via "Manage Connection"
- Wait for the cron job to run (first sync is at 2:00 AM)
- Or manually click "Sync Now" to pull data immediately

### Sync failures in logs

- Check error logs: `tail -f /path/to/crm/logs/error.log`
- Verify OAuth tokens are still valid (they may need refresh)
- Check database connectivity from cron context

### Manual sync works but cron doesn't

- The PHP path in the cron command may be incorrect
- Try running manually to test: `php /home/mowology/public_html/crm/gsc/sync-cron.php`
- Add to crontab entry: `>> /tmp/gsc_cron.log 2>&1` to capture output

## Data Refresh Frequency

- **Manual Sync:** Immediate (within seconds)
- **Automated Cron:** Daily at 2:00 AM
- **Google's Data:** GSC data is typically 2-3 days behind real-time

For real-time monitoring, use the "Sync Now" button in the CRM.
