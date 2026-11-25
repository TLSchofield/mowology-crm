#!/usr/bin/env bash
# recipient-parity.sh — read-only proof that InvoiceRouting::resolveInvoiceRecipients() answers exactly
# what the old inline cascade in api-get-recipients.php did, for every property on PRODUCTION (2026-11-24).
# Needs InvoiceRouting.php (with the new method) on the server. Never --commit: prod-run rolls everything back.
set -euo pipefail
cd "$(git rev-parse --show-toplevel)"
TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT
{ grep -v -e '^<?php' -e '^declare(strict_types' scripts/lib/legacy-recipient-cascade.php; cat scripts/recipient-parity.snippet.php; } > "$TMP/parity.php"
scripts/prod-run.sh "$TMP/parity.php"
