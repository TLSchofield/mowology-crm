<?php
/**
 * Vendor Directory Diagnostic Tool
 * Run this on cPanel to identify issues with Composer autoloader
 *
 * URL: https://www.mowology.ca/crm/diagnostics/vendor_check.php
 */

// Simple output
header('Content-Type: text/plain; charset=utf-8');

echo "=== Mowology Vendor Directory Diagnostics ===\n\n";

// Check PHP version
echo "PHP Version: " . PHP_VERSION . "\n";
echo "PHP SAPI: " . php_sapi_name() . "\n";
echo "PHP Executable: " . PHP_EXECUTABLE . "\n\n";

// Check vendor path
$vendorPath = dirname(__DIR__, 2) . '/vendor';
echo "Expected Vendor Path: " . $vendorPath . "\n";
echo "Vendor Directory Exists: " . (is_dir($vendorPath) ? 'YES' : 'NO') . "\n";
echo "Vendor Directory Readable: " . (is_readable($vendorPath) ? 'YES' : 'NO') . "\n\n";

// Check critical files
$criticalFiles = [
    'autoload.php',
    'composer/autoload_real.php',
    'composer/ClassLoader.php',
    'composer/autoload_static.php',
    'mpdf/mpdf/src/Mpdf.php',
];

echo "Critical Files Status:\n";
foreach ($criticalFiles as $file) {
    $path = $vendorPath . '/' . $file;
    $exists = file_exists($path);
    $readable = $exists && is_readable($path);
    $size = $exists ? filesize($path) : 0;

    $status = $exists ? ($readable ? 'OK' : 'NOT READABLE') : 'MISSING';
    echo "  • " . $file . ": " . $status;
    if ($exists) {
        echo " (" . number_format($size) . " bytes)";
    }
    echo "\n";
}

echo "\n";

// Try to load the autoloader
echo "Attempting to load Composer autoloader...\n";
$autoloadPath = $vendorPath . '/autoload.php';

if (!file_exists($autoloadPath)) {
    echo "ERROR: autoload.php not found at " . $autoloadPath . "\n";
    exit(1);
}

try {
    ob_start();
    require_once $autoloadPath;
    ob_end_clean();

    echo "SUCCESS: Composer autoloader loaded successfully!\n";
    echo "Classes available:\n";

    // Check if key classes are available
    if (class_exists('mPDF\\Mpdf')) {
        echo "  ✓ mPDF\\Mpdf available\n";
    } else {
        echo "  ✗ mPDF\\Mpdf NOT available\n";
    }

    if (class_exists('Composer\\Autoload\\ClassLoader')) {
        echo "  ✓ Composer\\Autoload\\ClassLoader available\n";
    } else {
        echo "  ✗ Composer\\Autoload\\ClassLoader NOT available\n";
    }

} catch (Throwable $e) {
    echo "ERROR: Failed to load autoloader\n";
    echo "Exception: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . "\n";
    echo "Line: " . $e->getLine() . "\n";
    echo "\nStack trace:\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}

echo "\n=== All checks passed! ===\n";
