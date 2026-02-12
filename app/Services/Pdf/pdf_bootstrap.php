<?php
/**
 * PDF Generation Bootstrap
 * Loads Composer autoloader for mPDF.
 * Only require this file when PDF generation is needed.
 *
 * vendor/ lives inside public/ (public_html/ on cPanel).
 * Uses PUBLIC_ROOT constant to locate the autoloader reliably.
 *
 * Migrated from: public/crm/includes/pdf_bootstrap.php
 */

if (!defined('APP_ROOT')) {
    require_once dirname(__DIR__, 2) . '/Core/paths.php';
}

$vendorAutoload = PUBLIC_ROOT . '/vendor/autoload.php';

if (!file_exists($vendorAutoload)) {
    throw new RuntimeException(
        'Composer autoloader not found at ' . $vendorAutoload . '. '
        . 'Ensure the vendor/ directory is uploaded to the server. '
        . 'Run: composer install --no-dev'
    );
}

try {
    require_once $vendorAutoload;
} catch (Error $e) {
    throw new RuntimeException(
        'Failed to load Composer autoloader. The vendor/ directory may be incomplete. '
        . 'Error: ' . $e->getMessage(),
        0,
        $e
    );
}
