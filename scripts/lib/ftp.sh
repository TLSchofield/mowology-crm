# scripts/lib/ftp.sh — the ONE place that says how we talk to the production FTP server.
# Sourced by deploy.sh and every scripts/*.sh that uses lftp (scripts/deploy-merged.py
# mirrors the same values). Security audit 2026-10-12 #2.
#
# Why the host is NOT ftp.mowology.ca: the shared cPanel server presents a certificate for
# *.canadianwebhosting.com only, so with verification ON ftp.mowology.ca fails with
# "subjectAltName does not match". Connecting to the server's own name verifies cleanly
# (RapidSSL / DigiCert chain, checked 2026-10-12). If the host moves servers, find the new
# name with `dig +short -x $(dig +short ftp.mowology.ca)` and set MW_FTP_HOST or edit below.
#
# Certificate verification is never switched off here. tests/Unit/Docs/DeployScriptTlsTest.php
# fails the suite if any script switches certificate verification off.
#
# Password: macOS Keychain first (service "mowology-ftp", account = the FTP user), then the
# legacy `git config git-ftp.password`. It is handed to lftp through LFTP_PASSWORD +
# --env-password, so it never appears in `ps` output.

MW_FTP_HOST="${MW_FTP_HOST:-cwhtor13.canadianwebhosting.com}"
MW_FTP_CA_FILE="${MW_FTP_CA_FILE:-/etc/ssl/cert.pem}"
MW_LFTP_TLS="set ssl:verify-certificate yes; set ssl:check-hostname yes; set ssl:ca-file $MW_FTP_CA_FILE; set ftp:ssl-force true; set ftp:ssl-protect-data true;"

mw_ftp_user() {
  git config git-ftp.user 2>/dev/null || echo "claude@mowology.ca"
}

mw_ftp_password() {
  security find-generic-password -s mowology-ftp -a "$(mw_ftp_user)" -w 2>/dev/null \
    || git config git-ftp.password 2>/dev/null
}

# mw_ftp_check — exit early with a clear message when no password can be found.
mw_ftp_check() {
  if [ -z "$(mw_ftp_password)" ]; then
    echo "No FTP password: add it to Keychain (service mowology-ftp) or git config git-ftp.password" >&2
    return 1
  fi
}

# mw_lftp "<lftp commands>" — runs one lftp session with TLS verification on.
mw_lftp() {
  LFTP_PASSWORD="$(mw_ftp_password)" lftp --user "$(mw_ftp_user)" --env-password \
    -e "$MW_LFTP_TLS $1" "ftp://$MW_FTP_HOST"
}
