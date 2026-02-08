# Deployment Instructions

## For Live Server (cPanel)

### Current Fix to Deploy: Duplicate Path Issue (Commit de32198)

**File Changed:**
- `public/crm/includes/functions.php`

**Steps to Deploy via FTP:**

1. **Connect to FTP**
   - Host: `mowology.ca`
   - Use your cPanel FTP credentials
   - Navigate to: `/public_html/crm/includes/`

2. **Upload the Fixed File**
   - Replace: `functions.php`
   - Source: `/Users/timschofield/Projects/mowology-crm/public/crm/includes/functions.php`
   - Upload via your FTP client (Transmit, Cyberduck, FileZilla, etc.)

3. **Verify on Live Server**
   - Visit: `https://mowology.ca/crm/`
   - Try creating a job from an accepted quote
   - Check for any PHP errors or warnings

---

## General FTP Upload Pattern

### Files to Upload (Always from `/public/` directory)

```
/public/
  ├── *.php (main site files)
  ├── /assets/ (CSS, JS, images)
  ├── /crm/ (CRM pages and includes) ← Upload this folder
  ├── /crinum/ (vendor AppStack)
  ├── /jobFlow/ (job workflow pages)
  ├── /customer/ (customer portal)
  ├── /loginAuth/
  │   ├── auth.php
  │   ├── login.php
  │   ├── login_secure.php
  │   └── ⚠️ NOT: local-bypass.php (dev-only)
  ├── /app_config/
  │   ├── config.php
  │   ├── session_config.php
  │   └── ⚠️ NOT: secrets.php (local credentials)
  └── ⚠️ NOT: DATABASE_SETUP.php, DEBUG_UTILITY.php, POPULATE_TEST_DATA.php (dev-only)
```

### Files NEVER to Upload

```
⚠️ Local development tools (in .gitignore):
  - public/loginAuth/local-bypass.php
  - public/DATABASE_SETUP.php
  - public/DEBUG_UTILITY.php
  - public/POPULATE_TEST_DATA.php
  - LOCAL_DEV_SETUP.md
  - KANBAN_TESTING.md
  - UPLOAD_CHECKLIST.md

⚠️ Configuration files (in .gitignore):
  - public/app_config/secrets.php (use production credentials)
  - crm/config.php (if exists)
  - .env (if exists)

⚠️ Database files (in .gitignore):
  - database/*.sql (except keep migrations/)
```

---

## Production Checklist Before Upload

- [ ] Review changes in Git: `git log --oneline -3`
- [ ] Verify no credentials are in the commit
- [ ] Test locally that the fix works
- [ ] Check file permissions (should be 644 for PHP files)
- [ ] Have backup of current production version

---

## Quick Upload via Command Line (Optional)

If you have SFTP access or can use command-line tools:

```bash
# From your local project directory
sftp user@mowology.ca << EOF
cd public_html/crm/includes/
put public/crm/includes/functions.php
quit
EOF
```

Or with rsync (if SSH available):

```bash
rsync -avz --delete /Users/timschofield/Projects/mowology-crm/public/ \
  user@mowology.ca:public_html/ \
  --exclude 'app_config/secrets.php' \
  --exclude 'loginAuth/local-bypass.php' \
  --exclude 'DATABASE_SETUP.php' \
  --exclude 'DEBUG_UTILITY.php' \
  --exclude 'POPULATE_TEST_DATA.php'
```

---

## Post-Deployment Verification

### Check Live Server Logs

Via cPanel:
1. Log in to cPanel
2. File Manager → `/public_html/`
3. Check error logs in `/app_config/logs/` (if exists)

### Test the Fix

1. Visit: `https://mowology.ca/crm/`
2. Log in as admin
3. Navigate to Quotes
4. Accept a test quote
5. Create a job from that quote
6. Verify no PHP errors appear

### Monitor for Issues

- Check browser console for JavaScript errors
- Check PHP error logs for warnings
- Verify database updates complete successfully

---

## Rollback Instructions

If deployment causes issues:

1. **Via FTP:**
   - Restore `public/crm/includes/functions.php` from backup
   - Clear browser cache and test again

2. **Via cPanel File Manager:**
   - Right-click file → Revert (if available)
   - Or manually upload backup version

3. **Contact Hosting Support:**
   - If file permissions are the issue
   - If database connection fails

---

## Current Deployable Commits

| Commit | Date | Description |
|--------|------|-------------|
| `de32198` | Feb 7, 2026 | Fix duplicate crm/ directory in include paths |
| `0a17913` | Feb 7, 2026 | Initial commit: Mowology CRM foundation |

All commits are safe to deploy (no dev tools or credentials included).

---

## Notes for Tim

- **Local dev tools are safely excluded** via `.gitignore`
- **Production credentials are in a local-only file** (`secrets.php`)
- **All database migrations are tracked** in `/database/migrations/`
- **Next deployment should use:** FTP upload of `public/` directory contents

---

## Questions?

For detailed setup info, see:
- `DEV_SETUP_COMPLETE.md` — Local development guide
- `database/DATABASE_SETUP_GUIDE.md` — Database initialization
- `CLAUDE.md` — Project architecture and conventions
