# Upload to Live Server Checklist

**Safe to upload:** Everything in `/public/` **EXCEPT** the files listed below.

---

## ❌ NEVER Upload These Files

These are automatically excluded by `.gitignore` and should **never be on the live server**:

| File | Reason |
|------|--------|
| `public/loginAuth/local-bypass.php` | Local dev tool (security bypass) |
| `public/DEBUG_UTILITY.php` | Local database inspection tool |
| `LOCAL_DEV_SETUP.md` | Local development guide |
| `public/app_config/secrets.php` | Contains database credentials & API keys |

**Git will remind you:** These are in `.gitignore` so git won't track them. They'll stay on your machine locally.

---

## ✅ Safe to Upload

Everything else in `/public/`:
- `public/crm/` — All CRM pages
- `public/app_config/config.php` — Production config (NOT secrets.php)
- `public/loginAuth/login.php` — Standard login (NOT local-bypass.php)
- `public/*.php` — Public website pages
- `public/assets/` — CSS, JS, images
- `public/includes/` — HTML templates
- All other subdirectories

---

## Upload Steps

### Before Uploading

```bash
# Make sure local dev files are NOT in your upload directory
cd /Projects/mowology_crm/public

# Check that these files do NOT exist:
# - loginAuth/local-bypass.php  ❌
# - DEBUG_UTILITY.php            ❌
```

### Upload Process

1. **Delete dev files locally if they're in your upload staging area:**
   ```
   rm loginAuth/local-bypass.php
   rm DEBUG_UTILITY.php
   ```

2. **Upload everything else from `/public/` to live server**

3. **After upload, restore dev files locally** (if you deleted them):
   ```bash
   # They're safe to restore — they're in .gitignore and won't get committed
   git restore public/loginAuth/local-bypass.php
   git restore public/DEBUG_UTILITY.php
   ```

---

## What Gets Auto-Excluded

Git automatically excludes:
- `public/app_config/secrets.php` — Credentials (NEVER commit)
- `*.log` — Log files
- `/storage/pdfs/*.pdf` — Generated PDFs
- `.DS_Store`, `Thumbs.db` — OS files
- `.vscode/`, `.idea/` — IDE files

---

## Verification

To see what Git would upload (vs. what's ignored):

```bash
# Show all files Git is tracking (safe to upload)
git ls-files | grep "^public/"

# Show all ignored files (NOT safe to upload)
git check-ignore -v public/loginAuth/local-bypass.php public/DEBUG_UTILITY.php
```

---

## Quick Reference

| Action | Command |
|--------|---------|
| See what would be uploaded | `git ls-files \| grep "^public/"` |
| Check if file is ignored | `git check-ignore -v FILE` |
| Restore dev files after upload | `git restore public/loginAuth/local-bypass.php` |
| View upload history | `git log --oneline` |

---

## Questions?

- **"Did I upload secrets.php by accident?"** → Check live server. It shouldn't be there. If it is, delete it immediately.
- **"Can I safely delete the dev tools?"** → Yes, they're in `.gitignore` and will be ignored by git. Delete them if they're in your upload folder.
- **"What about the live server's secrets.php?"** → It should exist on the live server (in `/public/app_config/secrets.php`) with production credentials. It was manually uploaded by the hosting provider and stays on the server.
