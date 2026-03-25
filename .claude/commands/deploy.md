Deploy the specified file(s) to production via FTP.

Files to deploy: $ARGUMENTS

Steps:
1. Read the FTP password from git config: `git config git-ftp.password`
2. For each file path provided, determine the correct FTP destination path:
   - Files under `public/` → strip `public/` prefix, upload to `/dirname/`
   - Files under `app/` → upload to `/app/dirname/` (app/ lives inside public_html on server)
   - Example: `public/crm/invoices/view.php` → FTP path `/crm/invoices/`
   - Example: `app/Modules/Team/Api/employees.php` → FTP path `/app/Modules/Team/Api/`
3. Run lftp for each file (one connection per file):
   ```bash
   FTP_PASS=$(git config git-ftp.password)
   lftp -u "claude@mowology.ca,$FTP_PASS" -e "set ssl:verify-certificate no; set ftp:ssl-force true; put -O /DEST_DIR/ FILE_PATH; quit" ftp://ftp.mowology.ca
   ```
4. After all files are deployed, update the remote .git-ftp.log:
   ```bash
   echo $(git rev-parse HEAD) > /tmp/git-ftp-log.txt
   lftp -u "claude@mowology.ca,$FTP_PASS" -e "set ssl:verify-certificate no; set ftp:ssl-force true; put -O / /tmp/git-ftp-log.txt -o .git-ftp.log; quit" ftp://ftp.mowology.ca
   ```
5. Report which files were deployed and confirm success.

If no files are specified, run `git ftp push --dry-run` to list changed files since last deploy, then ask which ones to deploy.
