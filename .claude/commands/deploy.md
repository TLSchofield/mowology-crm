Deploy the specified file(s) to production via FTP.

Files to deploy: $ARGUMENTS

Steps:
1. Minified-asset check: for each file in the list under `public/crm/js/` or `public/crm/css/` that is a source file (not already `.min.js`/`.min.css`), check whether a `.min` sibling exists and whether the source's mtime is newer than the manifest's recorded mtime for it (`scripts/.minify-manifest.json`). If any are stale or missing, run `php scripts/minify-assets.php --verbose` before deploying anything, then add every regenerated `.min.js`/`.min.css` sibling to the deploy list (and stage/commit them with the same commit as the source change, per the repo's commit-before-deploy workflow) so the stale-minified-asset drift documented in the Obsidian vault's Known-Failure-Patterns can't recur silently.
2. Read the FTP password from git config: `git config git-ftp.password`
3. For each file path provided, determine the correct FTP destination path:
   - Files under `public/` → strip `public/` prefix, upload to `/dirname/`
   - Files under `app/` → upload to `/app/dirname/` (app/ lives inside public_html on server)
   - Example: `public/crm/invoices/view.php` → FTP path `/crm/invoices/`
   - Example: `app/Modules/Team/Api/employees.php` → FTP path `/app/Modules/Team/Api/`
4. Run lftp for each file (one connection per file):
   ```bash
   FTP_PASS=$(git config git-ftp.password)
   lftp -u "claude@mowology.ca,$FTP_PASS" -e "set ssl:verify-certificate no; set ftp:ssl-force true; put -O /DEST_DIR/ FILE_PATH; quit" ftp://ftp.mowology.ca
   ```
5. After all files are deployed, update the remote .git-ftp.log:
   ```bash
   echo $(git rev-parse HEAD) > /tmp/git-ftp-log.txt
   lftp -u "claude@mowology.ca,$FTP_PASS" -e "set ssl:verify-certificate no; set ftp:ssl-force true; put -O / /tmp/git-ftp-log.txt -o .git-ftp.log; quit" ftp://ftp.mowology.ca
   ```
6. Report which files were deployed and confirm success (including which `.min` siblings, if any, were auto-regenerated and included).

If no files are specified, run `git ftp push --dry-run` to list changed files since last deploy, then ask which ones to deploy.
