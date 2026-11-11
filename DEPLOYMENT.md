# Mowology — Deployment Reference

Deployment is **NOT automatic** on push. Files must be uploaded via FTP after every `git push`.

---

## FTP Credentials

- Host: `cwhtor13.canadianwebhosting.com` (the server `ftp.mowology.ca` points at). Its certificate
  covers `*.canadianwebhosting.com` only, so `ftp.mowology.ca` fails verification — use the server name.
- User: `claude@mowology.ca`
- Password: macOS Keychain, service `mowology-ftp` (legacy fallback: `git config git-ftp.password`)
- SSL: required and the certificate is VERIFIED. Never `set ssl:verify-certificate no`
  (security audit 2026-10-12 #2; `tests/Unit/Docs/DeployScriptTlsTest.php` fails if any script does).
- All of the above lives in `scripts/lib/ftp.sh`; every script sources it and calls `mw_lftp`.
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
. scripts/lib/ftp.sh     # host, verified TLS, password via LFTP_PASSWORD (never on the command line)

# Single file
mw_lftp "put -O /DEST_DIR/ LOCAL_FILE; quit"

# Multiple files (one connection per file — avoids disconnects)
for file in crm/file1.php crm/file2.php; do
  mw_lftp "put -O /$(dirname $file)/ public/$file; quit"
done
```

Use `lftp` — NOT `curl` or `git ftp push`. The server's ImunifyAV/ModSecurity rejects files >~15KB via curl (451 error). `lftp` handles the FTP data connection differently and avoids this.

---

## Update Deploy Marker

After deploying, update `.git-ftp.log` on the server so future dry-runs show correct changed files:

```bash
echo $(git rev-parse HEAD) > /tmp/git-ftp-log.txt
. scripts/lib/ftp.sh
mw_lftp "put -O / /tmp/git-ftp-log.txt -o .git-ftp.log; quit"
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
