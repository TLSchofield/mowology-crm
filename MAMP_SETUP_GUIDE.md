# MAMP Setup Guide for Mowology CRM Development

## Quick Start

### 1. Install MAMP
- Download MAMP from: https://www.mamp.info/en/downloads/
- Install to `/Applications/MAMP/`
- Start MAMP (launches Apache + MySQL)

### 2. Configure MAMP

#### Point MAMP to Project Directory
1. Open MAMP → Preferences
2. Go to "Web Server" tab
3. Set **Document Root** to: `/Users/timschofield/Projects/mowology-crm/public`
4. Click "OK"

#### Restart Servers
- Click "Stop Servers" then "Start Servers"

### 3. Access the Application

**Website:** `http://localhost:8888/`
**CRM:** `http://localhost:8888/crm/` (requires login)
**PHPMyAdmin:** `http://localhost:8888/phpmyadmin/`

### 4. Configure Database

#### Get Database Details
1. Edit `public/app_config/secrets.php` and note the database credentials:
   ```php
   define('DB_HOST', 'localhost');
   define('DB_NAME', 'mowology_landscape_crm');
   define('DB_USER', 'mowology_user');
   define('DB_PASS', '...');
   ```

#### Import Production Database
1. Go to PHPMyAdmin: `http://localhost:8888/phpmyadmin/`
2. Create a new database called `mowology_landscape_crm`
3. Export database from cPanel (or ask for a dump)
4. Import into local database
5. **Alternative:** Create test data with sample quotes

#### Test Database Connection
- Visit: `http://localhost:8888/crm/DEBUG_QUOTES.php`
- Should show quote counts and sample data
- **If blank:** Check credentials in `secrets.php`

### 5. Debugging Quotes Issue

#### Step 1: Run Diagnostics
Visit: `http://localhost:8888/crm/DEBUG_QUOTES.php`

This shows:
- ✅ Quotes table exists?
- ✅ Status distribution (how many draft/sent/accepted)
- ✅ Simple query results (no JOINs)
- ✅ Complex query results (with JOINs)

**Expected output for production data:**
```
Quotes table exists. Total rows: 9
Status Distribution:
  draft: 0
  sent: 7
  accepted: 2
```

#### Step 2: Check Kanban Page
Visit: `http://localhost:8888/crm/quotes_appstack.php`

**Expected:**
- All 4 columns show quote counts
- Quotes appear as cards in columns
- Toggle buttons work (Kanban ↔ Table)

#### Step 3: Check Logs
If quotes still don't show:
1. Check `/Applications/MAMP/logs/php_error.log`
2. Check `/Applications/MAMP/logs/apache_error.log`
3. Look for "Quotes page" error messages

### 6. File Locations (Local)

```
/Users/timschofield/Projects/mowology-crm/public/
├── crm/
│   ├── quotes_appstack.php          ← Main quotes page (Kanban)
│   ├── quotes_appstack.php.backup.* ← Backup version
│   ├── DEBUG_QUOTES.php             ← Diagnostic script
│   ├── css/
│   │   └── mowology-brand.css       ← Kanban styling
│   └── includes/
│       ├── appstack_head.php        ← Layout includes
│       └── functions.php            ← Helper functions
├── app_config/
│   ├── secrets.php                  ← Database credentials
│   └── config.php                   ← Database setup
└── loginAuth/
    └── auth.php                     ← Authentication
```

### 7. Making Changes

**To edit code:**
1. Edit files in `/Users/timschofield/Projects/mowology-crm/`
2. Changes take effect immediately in browser
3. Refresh page with `Cmd+Shift+R` (hard reload)

**To test CSS changes:**
- Edit `/public/crm/css/mowology-brand.css`
- Changes appear instantly

**To test PHP changes:**
- Edit `/public/crm/quotes_appstack.php`
- Refresh browser

### 8. Current Issue: Quotes Not Populating

**Status:** Kanban board UI is complete, but data isn't showing.

**File:** `/Users/timschofield/Projects/mowology-crm/public/crm/quotes_appstack.php`

**What was changed:**
- Lines 49-145: Complete rewrite of quote fetching logic
- Now uses simpler, more reliable sequential queries instead of complex JOINs
- Should return all quotes from database

**What to test:**
1. Run `DEBUG_QUOTES.php` - should show quote counts
2. Check error logs if quotes don't appear
3. If basic query works but kanban shows nothing, there may be a data organization issue

### 9. Reverting if Needed

If you need to revert to the previous version:
```bash
cp /Users/timschofield/Projects/mowology-crm/public/crm/quotes_appstack.php.backup.20260207 \
   /Users/timschofield/Projects/mowology-crm/public/crm/quotes_appstack.php
```

Then refresh the browser.

---

## Next Steps

1. **Install MAMP and get it running**
2. **Run the DEBUG_QUOTES.php script** - this will tell us if the database queries work
3. **Share the DEBUG output** - this will help identify the exact issue
4. **Fix based on diagnostics** - once we know what's failing, we can fix it

Good luck! Let me know when MAMP is ready.
