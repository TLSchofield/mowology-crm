# Google Search Console Integration — Setup Guide

## What You Can Do Now

✅ **Upload & manage staff photos**
✅ **Admin review & approve photos**
✅ **Mark favorites for marketing**
✅ **See portfolio on public site**
✅ **Connect Google Search Console** (NEW!)
✅ **View top search queries & pages**
✅ **Identify optimization opportunities**

---

## Setup: 3 Steps

### Step 1: Get Google OAuth Credentials

1. Go to [Google Cloud Console](https://console.cloud.google.com/)
2. Create a new project (or use existing)
3. Enable APIs:
   - Go to **APIs & Services** → **Library**
   - Search for "Google Search Console API"
   - Click "Enable"
4. Create OAuth 2.0 credentials:
   - Go to **APIs & Services** → **Credentials**
   - Click **Create Credentials** → **OAuth client ID**
   - Choose **Web application**
   - Under "Authorized redirect URIs" add:
     ```
     https://mowology.ca/crm/gsc/connect.php?step=callback
     ```
   - Copy the **Client ID** and **Client Secret**

### Step 2: Add Credentials to secrets.php

Add these lines to `/public/app_config/secrets.php`:

```php
// Google OAuth (for GSC integration)
define('GOOGLE_CLIENT_ID', 'your-client-id.apps.googleusercontent.com');
define('GOOGLE_CLIENT_SECRET', 'your-client-secret');

// Encryption key (for storing tokens)
define('APP_ENCRYPTION_KEY', 'generate-a-random-32-char-string');
```

**Generate a random encryption key:**
```bash
# SSH into your server and run:
php -r "echo bin2hex(random_bytes(32));"
```

### Step 3: Connect Your Google Account

1. Go to **Portfolio** → **GSC Insights** tab
2. Click **"Connect Google Account"**
3. Sign in with your Google account
4. Grant permission to "Google Search Console"
5. You'll be redirected back with confirmation

---

## How It Works

**Daily Sync (Automated):**
1. Every day at 2 AM, a cron job pulls your last 28 days of GSC data
2. Data stored in database: `gsc_query_page_stats`
3. Dashboard automatically displays latest data

**Manual Sync (If Needed):**
- Visit `/crm/gsc/sync-cron.php` as admin to pull data immediately

---

## Dashboard Data

### Top Search Queries
- Which keywords bring visitors to your site
- Click-through rate (CTR)
- Average ranking position
- **Action:** Use this to write content targeting high-impression keywords

### Top Pages
- Which pages get the most clicks
- Impressions (how often shown in results)
- Click-through rate
- **Action:** Keep these pages updated and linking to quotes form

### Optimization Opportunities
- High impressions but low CTR (less than 3%)
- These pages rank well but don't get clicks
- **Action:** Rewrite titles/meta descriptions to improve CTR

---

## Cron Setup (Auto Daily Sync)

**Via cPanel:**
1. Go to **cPanel** → **Cron Jobs**
2. Click **Add New Cron Job**
3. Set up:
   - **Hour:** 2
   - **Minute:** 0
   - **Day:** * (every day)
   - **Month:** * (every month)
   - **Day of Week:** * (every day)
   - **Command:** `php /home/mowology/public_html/crm/gsc/sync-cron.php`
4. Click **Add Cron Job**

**Via SSH:**
```bash
crontab -e
# Add this line:
0 2 * * * php /home/mowology/public_html/crm/gsc/sync-cron.php >> /home/mowology/logs/gsc-sync.log 2>&1
```

---

## What Gets Stored

**Database Tables:**
- `gsc_properties` — Your OAuth connection & tokens
- `gsc_snapshots` — Daily snapshots of raw GSC data
- `gsc_query_page_stats` — Parsed queries, pages, CTR, position

**Security:**
- OAuth tokens encrypted at rest
- Tokens auto-refresh before expiry
- No credentials stored in code

---

## Troubleshooting

### "Google OAuth credentials not configured"
**Problem:** You didn't add credentials to `secrets.php`
**Solution:** Follow Step 2 above

### Connection fails after "Sign in with Google"
**Problem:** Redirect URI mismatch
**Solution:** Verify in Google Cloud Console that the redirect URI matches:
```
https://mowology.ca/crm/gsc/connect.php?step=callback
```

### No data showing in dashboard
**Problem:** Cron hasn't run yet, or connection is stale
**Solution:**
1. Check that cron is set up (see above)
2. Or manually trigger: `/crm/gsc/sync-cron.php` as admin
3. Wait up to 24 hours for first pull

### "Token expired" error
**Problem:** OAuth token needs refresh
**Solution:** Automatic (handled by system), but you can manually re-connect via `/crm/gsc/connect.php`

---

## Next Phase Options

After GSC is live, you can:

**A) Client Proof Galleries** — Before/after galleries per job with feedback
**B) ROI Attribution** — Track leads through to revenue
**C) Recommendations Engine** — Auto-suggest content ideas based on search data

---

## Files Created

- `/crm/gsc/connect.php` — OAuth connection flow
- `/crm/gsc/sync-cron.php` — Daily data pull
- `/crm/gsc/snapshots.php` — Data aggregation
- Updated `/crm/portfolio/index.php` — GSC Insights tab

