# Mowology CRM — Local Development Setup Complete ✅

**Last Updated:** February 7, 2026
**Setup Status:** All tools created and configured for localhost development

---

## Quick Start

### 1. Start MAMP

```bash
# Start MySQL and Apache in MAMP
# Access via: http://localhost:8888
```

### 2. Initialize Database (First Time)

Visit: **http://localhost:8888/DATABASE_SETUP.php**

This tool will:
- ✅ Create all required tables automatically
- ✅ Create default admin user (if missing)
- ✅ Show database status and readiness
- ✅ Provide links to other dev tools

### 3. Login Without Password (Dev Only)

Visit: **http://localhost:8888/loginAuth/local-bypass.php**

This tool:
- ✅ Logs in as admin user instantly on localhost
- ✅ Sets session variables for full CRM access
- ✅ Redirects to dashboard
- **Security:** Only works on localhost (127.0.0.1)

### 4. View Kanban Board

Visit: **http://localhost:8888/crm/quotes_view_dev.php**

This tool:
- ✅ Shows all quotes in kanban columns (draft → sent → accepted → declined → expired)
- ✅ Each card is clickable (links to quote detail view)
- ✅ Requires no authentication on localhost
- ✅ Uses color-coded borders for status (gray/blue/green/red)

### 5. Populate Test Data

Visit: **http://localhost:8888/POPULATE_TEST_DATA.php**

This tool:
- ✅ Creates 7 sample quotes across different workflow stages
- ✅ All marked with TEST-2026-XXXX prefix for easy identification
- ✅ Includes sample companies and properties
- ✅ Safe to run multiple times (duplicates ignored)

### 6. Database Inspector

Visit: **http://localhost:8888/DEBUG_UTILITY.php**

This tool:
- ✅ Shows database connection status
- ✅ Lists all tables and their structure
- ✅ Allows SELECT-only queries for inspection
- ✅ Displays database version and info

---

## File Structure

### Local Dev Tools (Created)

| File | Purpose | Access |
|------|---------|--------|
| `DATABASE_SETUP.php` | Create/initialize database tables | http://localhost:8888/DATABASE_SETUP.php |
| `POPULATE_TEST_DATA.php` | Generate test quotes | http://localhost:8888/POPULATE_TEST_DATA.php |
| `DEBUG_UTILITY.php` | Database inspection tool | http://localhost:8888/DEBUG_UTILITY.php |
| `loginAuth/local-bypass.php` | Passwordless admin login | http://localhost:8888/loginAuth/local-bypass.php |
| `crm/quotes_view_dev.php` | Dev kanban board view | http://localhost:8888/crm/quotes_view_dev.php |

### Production Database Files (Created)

| File | Purpose |
|------|---------|
| `database/INIT_DATABASE.sql` | Complete schema for production setup |
| `database/DATABASE_SETUP_GUIDE.md` | Deployment guide (cPanel, SSH, phpMyAdmin) |

### Configuration (Modified for Local Dev)

| File | Change |
|------|--------|
| `app_config/secrets.php` | DB credentials: `root:root` for local MAMP |
| `app_config/session_config.php` | Session path: `/tmp/mowology_sessions` for MAMP |

---

## Database Schema

### Tables Created

1. **users** — CRM user accounts
   - Default admin: `mowology@icloud.com` / `Sunwukong2026#`
   - Roles: admin, manager, user

2. **companies** — Client organizations
   - Stores contact info, address, status

3. **properties** — Customer locations/sites
   - Links to companies
   - Property type tracking

4. **quotes** — Quote workflow management
   - Status: draft → sent → accepted → declined → expired
   - Line items, totals, expiry dates
   - Timestamps for sent/accepted events

5. **jobs** — Work scheduling & tracking
   - Status: scheduled → in_progress → completed → cancelled
   - Links quotes to completed work
   - Amount tracking

6. **activity_log** — Audit trail
   - Tracks all user actions
   - IP address logging
   - Timestamps for compliance

---

## Test Data

### Sample Quotes Created

When you run `POPULATE_TEST_DATA.php`, it creates:

| Quote # | Status | Amount | Purpose |
|---------|--------|--------|---------|
| TEST-2026-0001 | draft | $450.00 | New inquiry |
| TEST-2026-0002 | draft | $650.00 | Follow-up quote |
| TEST-2026-0003 | sent | $1,200.00 | Sent to client |
| TEST-2026-0004 | sent | $800.00 | Awaiting response |
| TEST-2026-0005 | sent (viewed) | $950.00 | Client viewed it |
| TEST-2026-0006 | accepted | $2,100.00 | Won work! |
| TEST-2026-0007 | accepted | $1,500.00 | Another win |

All test data uses:
- Test company: "Demo Landscaping Services"
- Test property: "123 Test Street, Vancouver"
- Service types: lawn maintenance, landscape design, garden installation

---

## Troubleshooting

### "Connection refused" when visiting tools

**Problem:** MySQL not running
**Solution:** Start MAMP → Apache & MySQL need to be running

### "403 Forbidden" message

**Problem:** Accessing dev tools from outside localhost
**Solution:** Dev tools only work on `127.0.0.1` or `localhost` for security

### Session keeps redirecting to login

**Problem:** Session not persisting between pages
**Solution:**
- Clear browser cookies for localhost
- Restart MAMP MySQL
- Check `/tmp/mowology_sessions/` directory exists

### Duplicate test data warnings

**Problem:** Running POPULATE_TEST_DATA.php multiple times
**Solution:** This is safe — duplicates are ignored. You can delete old test quotes manually.

### "Table already exists" messages

**Problem:** Running DATABASE_SETUP.php multiple times
**Solution:** Perfectly normal — the script uses `IF NOT EXISTS` for safety

---

## Production Deployment

### On Live Server (cPanel)

1. Copy `database/INIT_DATABASE.sql` to your cPanel account
2. Use cPanel → phpMyAdmin → Import to load the SQL file
3. Or via SSH: `mysql -u cpanel_user -p database_name < INIT_DATABASE.sql`

**Important:**
- Dev tools (DATABASE_SETUP.php, local-bypass.php, etc.) are in `.gitignore`
- They will NOT be deployed to live server
- `app_config/secrets.php` with production credentials is also in `.gitignore`
- Only production files sync via FTP to live server

---

## Git Configuration

### Files NOT in Repository (Protected)

```
public/loginAuth/local-bypass.php      ← Dev tool
public/DEBUG_UTILITY.php                ← Dev tool
public/POPULATE_TEST_DATA.php           ← Dev tool
public/DATABASE_SETUP.php               ← Dev tool
public/app_config/secrets.php           ← Credentials (production or local)
LOCAL_DEV_SETUP.md                      ← Local notes
```

These files are excluded via `.gitignore` so they're never committed or pushed to GitHub.

---

## Session Architecture

### Local Development (MAMP)

Sessions stored in: `/tmp/mowology_sessions/`

Key configuration in `app_config/session_config.php`:
- Session name: `MOWOSESS`
- Cookie flags: `httponly=true`, `samesite=Lax`
- Lifetime: Session (browser close)

### Production (cPanel)

Sessions stored in: `/home/mowology/tmp/`

Same security configuration applied.

---

## User Credentials

### Default Admin (Auto-Created)

- **Email:** `mowology@icloud.com`
- **Password:** `Sunwukong2026#`
- **Role:** Admin
- **Status:** Active

Created in database by `DATABASE_SETUP.php` and `INIT_DATABASE.sql`

---

## Next Steps (Optional)

1. **Add more companies/properties** — Via CRM interface after login
2. **Create additional users** — Via Settings/Users in CRM
3. **Test quote workflow** — Create/send/accept quotes in kanban board
4. **Generate invoices** — Convert accepted quotes to jobs/invoices
5. **Customize brand colors** — Edit `/crm/css/mowology-brand.css`

---

## Support Reference

All tools are **localhost-only** for security. They will not work on production or from external IPs.

**Database Setup Guide:** See `database/DATABASE_SETUP_GUIDE.md` for production deployment steps.

**Architecture Reference:** See `ARCHITECTURE.md` for full system map.
