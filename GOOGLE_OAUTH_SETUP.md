# Google OAuth 2.0 Web Client Setup Guide

**Purpose:** Enable Google Search Console integration with Mowology CRM
**Setup Time:** 10-15 minutes
**Requirements:** Google Cloud Project access

---

## Step 1: Create a Google Cloud Project

1. Go to **[Google Cloud Console](https://console.cloud.google.com/)**
2. Sign in with your Google account
3. Click the **project dropdown** at the top
4. Click **NEW PROJECT**
5. Name it: `Mowology CRM`
6. Click **CREATE**
7. Wait for project to be created (1-2 minutes)

---

## Step 2: Enable Google Search Console API

1. In the Cloud Console, make sure you're in the **Mowology CRM** project
2. Go to **APIs & Services** → **Library**
3. Search for: `Google Search Console API`
4. Click on **Google Search Console API**
5. Click **ENABLE**
6. Wait for it to enable (few seconds)

---

## Step 3: Create OAuth 2.0 Credentials

### 3.1: Configure OAuth Consent Screen (First Time Only)

1. Go to **APIs & Services** → **OAuth consent screen**
2. Choose **User Type: External** (unless you're in a Google Workspace)
3. Click **CREATE**
4. Fill in the form:
   - **App name:** `Mowology CRM`
   - **User support email:** `info@mowology.ca`
   - **Developer contact:** Your email address
5. Click **SAVE AND CONTINUE**

### 3.2: Add Scopes

1. Click **ADD OR REMOVE SCOPES**
2. Search for: `webmasters`
3. Select: **`https://www.googleapis.com/auth/webmasters.readonly`**
   - This allows read-only access to Search Console
4. Click **UPDATE**
5. Click **SAVE AND CONTINUE**

### 3.3: Add Test Users (Optional for Development)

1. Skip if not needed, or add your email
2. Click **SAVE AND CONTINUE**

---

## Step 4: Create OAuth 2.0 Client ID

1. Go to **APIs & Services** → **Credentials**
2. Click **+ CREATE CREDENTIALS** (top-left)
3. Choose **OAuth client ID**
4. If prompted: "To create an OAuth client ID, you must first set your OAuth consent screen"
   - You should have done this in Step 3
5. Select **Application type: Web application**
6. Name it: `Mowology GSC Connection`
7. Under **Authorized redirect URIs**, add:
   ```
   https://mowology.ca/crm/gsc/connect.php?step=callback
   ```
8. If testing locally, also add:
   ```
   http://localhost:8888/crm/gsc/connect.php?step=callback
   ```
9. Click **CREATE**

---

## Step 5: Get Your Credentials

A dialog will appear with your credentials:

- **Client ID:** `XXXX-XXXX.apps.googleusercontent.com`
- **Client Secret:** `GOCSPX-XXXXXXXXXX`

⚠️ **IMPORTANT:** Copy these values - you'll need them in the next step!

---

## Step 6: Add to secrets.php

Edit `/public/app_config/secrets.php` and update these constants:

```php
define('GOOGLE_CLIENT_ID', 'YOUR_CLIENT_ID_HERE');
define('GOOGLE_CLIENT_SECRET', 'YOUR_CLIENT_SECRET_HERE');
```

### Example:
```php
define('GOOGLE_CLIENT_ID', 'YOUR_CLIENT_ID.apps.googleusercontent.com');
define('GOOGLE_CLIENT_SECRET', 'GOCSPX-YOUR_CLIENT_SECRET_HERE');
```

---

## Step 7: Test the Connection

### Local Testing (http://localhost:8888)

1. **Start MAMP** (Apache + MySQL)
2. Log in to: `http://localhost:8888/loginAuth/local-bypass.php`
3. Navigate to: `http://localhost:8888/crm/portfolio/index.php?tab=insights`
4. Or directly to: `http://localhost:8888/crm/gsc/connect.php`
5. Click **"Connect Google Search Console"**
6. Follow Google's login flow
7. Grant permission
8. You should be redirected back with connection confirmed

### Live Server Testing (https://mowology.ca)

1. Log in to CRM: `https://mowology.ca/crm/`
2. Navigate to: **Portfolio → Insights**
3. Or directly to: `https://mowology.ca/crm/gsc/connect.php`
4. Click **"Connect Google Search Console"**
5. Follow Google's login flow
6. Grant permission
7. Connection confirmed

---

## Step 8: Verify the Connection

After successful OAuth flow:

- ✅ You should see connection status displayed
- ✅ GSC data should start syncing
- ✅ Insights should display in the Portfolio section

---

## Troubleshooting

### "Redirect URI mismatch" Error

**Problem:** Google says your redirect URL doesn't match

**Solution:**
1. Go to **APIs & Services** → **Credentials**
2. Find your OAuth 2.0 Client ID
3. Click **EDIT** (pencil icon)
4. Under **Authorized redirect URIs**, verify it exactly matches:
   - Local: `http://localhost:8888/crm/gsc/connect.php?step=callback`
   - Production: `https://mowology.ca/crm/gsc/connect.php?step=callback`
5. Save changes
6. Try connecting again

### "OAuth credentials not configured" Error

**Problem:** Constants not defined in secrets.php

**Solution:**
1. Verify `secrets.php` has both constants defined:
   ```php
   define('GOOGLE_CLIENT_ID', '...');
   define('GOOGLE_CLIENT_SECRET', '...');
   ```
2. File is at: `/public/app_config/secrets.php`
3. Restart your web server
4. Try again

### "The redirect_uri MUST match" on Production

**Problem:** URL doesn't match what's registered in Google Cloud

**Solution:**
1. Make sure production URL in Google Cloud is exactly:
   ```
   https://mowology.ca/crm/gsc/connect.php?step=callback
   ```
2. Verify you're accessing via **https://** (not http://)
3. Check cPanel domain settings match `mowology.ca`

### Connection Works But No Data Shows

**Problem:** Connected but GSC data not displaying

**Solution:**
1. Google Search Console has limited data for new sites
2. Data usually shows after 24-48 hours
3. Make sure your site is already added to GSC
4. Try opening GSC directly: https://search.google.com/search-console

---

## File Locations

| File | Purpose |
|------|---------|
| `/public/app_config/secrets.php` | Store your OAuth credentials (not in git) |
| `/public/crm/gsc/connect.php` | OAuth connection flow handler |
| `/public/crm/includes/gsc-functions.php` | GSC API functions |
| `/public/crm/portfolio/index.php` | Portfolio page (displays insights) |

---

## Security Notes

- ✅ Client ID is **public** - ok to be exposed
- ✅ Client Secret is **private** - keep in `secrets.php` only
- ✅ `secrets.php` is in `.gitignore` - won't be committed
- ✅ OAuth tokens are encrypted in database
- ✅ Only admins can connect GSC

---

## What Happens Behind the Scenes

```
1. User clicks "Connect Google Search Console"
   ↓
2. System generates OAuth state token (CSRF protection)
3. Redirects to Google login page
   ↓
4. User logs in and grants permission
   ↓
5. Google redirects back with authorization code
   ↓
6. System exchanges code for access token
7. Token encrypted and stored in database
   ↓
8. GSC connection is now active
   ↓
9. System can now fetch GSC data
```

---

## Next Steps

1. ✅ Create Google Cloud Project
2. ✅ Enable Google Search Console API
3. ✅ Create OAuth 2.0 Web Client
4. ✅ Add credentials to `secrets.php`
5. ✅ Test the connection
6. ✅ Monitor for data sync

---

## Support

If you encounter issues:

1. Check **Google Cloud Console** → **APIs & Services** → **OAuth consent screen** (verify setup)
2. Check **Google Cloud Console** → **Credentials** (verify Client ID/Secret)
3. Check browser console (F12 → Console tab) for JavaScript errors
4. Check `secrets.php` for correct formatting
5. Check redirect URI matches exactly (including http vs https)

---

## Quick Reference

**OAuth Endpoints Used:**
- Google Auth: `https://accounts.google.com/o/oauth2/v2/auth`
- Token Exchange: `https://www.googleapis.com/oauth2/v4/token`
- Search Console API: `https://www.googleapis.com/webmasters/v3/sites`

**Scopes Requested:**
- `https://www.googleapis.com/auth/webmasters.readonly` (Read-only access to Search Console)

**Credentials Storage:**
- Location: `gsc_properties` table in database
- Access tokens: Encrypted with `APP_ENCRYPTION_KEY`
- Visible only to: Admin users

---

## Additional Resources

- [Google Cloud Console](https://console.cloud.google.com/)
- [Google OAuth 2.0 Documentation](https://developers.google.com/identity/protocols/oauth2)
- [Google Search Console API](https://developers.google.com/webmasters/search-console/guides/dropins)
