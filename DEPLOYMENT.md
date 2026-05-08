# Mowology — Deployment Reference

Deployment is **NOT automatic** on push. Files must be uploaded via FTP after every `git push`.

---

## FTP Credentials

- URL: `ftp://ftp.mowology.ca`
- User: `claude@mowology.ca`
- Password: stored in `.git/config` under `[git-ftp] → password`
- SSL: required (server forces AUTH TLS)
- Syncroot: `public/` maps to server's `public_html/`

---

## Server Directory Map

| Local path | Server FTP path |
|---|---|
| `public/crm/foo.php` | `/crm/foo.php` |
| `public/customer/bar.php` | `/customer/bar.php` |
| `app/Modules/X/Y.php` | `/app/Modules/X/Y.php` |
| `public/api/stripe/webhook.php` | `/api/stripe/webhook.php` |

**FTP root = `/home/mowology/public_html/`** (confirmed Feb 2026 — the FTP root IS the web root, not its parent)

---

## Deploy a File

```bash
FTP_PASS=$(git config git-ftp.password)

# Single file
lftp -u "claude@mowology.ca,$FTP_PASS" \
  -e "set ssl:verify-certificate no; set ftp:ssl-force true; \
      put -O /DEST_DIR/ LOCAL_FILE; quit" \
  ftp://ftp.mowology.ca

# Multiple files (one connection per file — avoids disconnects)
for file in crm/file1.php crm/file2.php; do
  lftp -u "claude@mowology.ca,$FTP_PASS" \
    -e "set ssl:verify-certificate no; set ftp:ssl-force true; \
        put -O /$(dirname $file)/ public/$file; quit" \
    ftp://ftp.mowology.ca
done
```

Use `lftp` — NOT `curl` or `git ftp push`. The server's ImunifyAV/ModSecurity rejects files >~15KB via curl (451 error). `lftp` handles the FTP data connection differently and avoids this.

---

## Update Deploy Marker

After deploying, update `.git-ftp.log` on the server so future dry-runs show correct changed files:

```bash
echo $(git rev-parse HEAD) > /tmp/git-ftp-log.txt
lftp -u "claude@mowology.ca,$(git config git-ftp.password)" \
  -e "set ssl:verify-certificate no; set ftp:ssl-force true; \
      put -O / /tmp/git-ftp-log.txt -o .git-ftp.log; quit" \
  ftp://ftp.mowology.ca
```

---

## Find Changed Files

```bash
git ftp push --dry-run   # lists files changed since last .git-ftp.log SHA
```

Use for discovery only — the actual upload must be done via `lftp`.

---

## PHP Binary on Server

```
/usr/local/bin/php
```

Do NOT use `ea-php84` — it doesn't exist on CanadianWebHosting.

---

## Other Notes

- Hosting: cPanel shared hosting at canadianwebhosting.com
- Session save path: `/home/mowology/tmp` (cPanel workaround, set in session_config.php)
- Database: `mowology_landscape_crm` (cPanel prefix `mowology_`)
- `.htaccess` blocks direct access to `/app_config/`, `/loginAuth/auth.php`, etc.
- `/app_config/` and `secrets.php` are in `.gitignore` — never commit them
- `/app/.htaccess` denies all web access (403) — confirmed on production

---

## Use `/deploy` Slash Command

Instead of typing the lftp commands manually, use the slash command:

```
/deploy public/crm/invoices/view.php
/deploy public/crm/invoices/view.php public/customer/invoice.php
```
