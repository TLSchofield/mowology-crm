<?php
/**
 * API Endpoint: Upload Company Logo
 *
 * POST /crm/api/upload-logo.php
 * Handles logo file upload, validation, and storage.
 * Returns the web-accessible path to the uploaded logo.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

header('Content-Type: application/json');

requireLogin();
$user = getCurrentUser();
session_write_close();
requirePermission('settings.edit');

// Verify CSRF token
if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'CSRF token invalid']);
    exit;
}

// Validate file upload
if (empty($_FILES['file'])) {
    echo json_encode(['success' => false, 'error' => 'No file uploaded']);
    exit;
}

$file = $_FILES['file'];

// Check for upload errors
if ($file['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'error' => 'Upload error code: ' . $file['error']]);
    exit;
}

// Validate MIME type
$allowedMimes = ['image/png', 'image/jpeg', 'image/svg+xml'];
$mimeType = mime_content_type($file['tmp_name']);
if (!in_array($mimeType, $allowedMimes)) {
    echo json_encode(['success' => false, 'error' => 'Only PNG, JPG, and SVG files are allowed.']);
    exit;
}

// Validate file size (2 MB max)
$maxSize = 2 * 1024 * 1024;
if ($file['size'] > $maxSize) {
    echo json_encode(['success' => false, 'error' => 'File is too large. Maximum size is 2 MB.']);
    exit;
}

// Determine upload directory
$uploadDir = dirname(__DIR__) . '/../uploads/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

// Generate filename
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if (!in_array($ext, ['png', 'jpg', 'jpeg', 'svg'])) {
    $ext = 'png'; // fallback
}
$storedName = 'logo-' . time() . '.' . $ext;
$filePath = $uploadDir . $storedName;
$webPath = '/uploads/' . $storedName;

// SVGs can embed <script>/event-handler attributes that would execute in the
// mowology.ca origin if the file is ever opened directly — strip those before
// writing to disk rather than trusting the upload as-is.
if ($mimeType === 'image/svg+xml') {
    $svgContent = file_get_contents($file['tmp_name']);
    $svgContent = sanitizeSvgContent($svgContent);
    if ($svgContent === null) {
        echo json_encode(['success' => false, 'error' => 'Invalid or unsafe SVG file.']);
        exit;
    }
    if (file_put_contents($filePath, $svgContent) === false) {
        echo json_encode(['success' => false, 'error' => 'Failed to save file.']);
        exit;
    }
} elseif (!move_uploaded_file($file['tmp_name'], $filePath)) {
    echo json_encode(['success' => false, 'error' => 'Failed to save file.']);
    exit;
}

/**
 * Strip <script> elements, on*="" event-handler attributes, and javascript:
 * URLs from an SVG document. Returns null if the file doesn't parse as valid XML.
 */
function sanitizeSvgContent(string $svg): ?string
{
    $prevErrors = libxml_use_internal_errors(true);

    $dom = new DOMDocument();
    // LIBXML_NONET blocks external network fetches during parsing (XXE/SSRF guard).
    // Modern libxml2 (bundled with PHP 8+) does not expand external entities by
    // default, so no separate entity-loader toggle is needed.
    $ok = $dom->loadXML($svg, LIBXML_NONET);

    libxml_use_internal_errors($prevErrors);

    if (!$ok) {
        return null;
    }

    $xpath = new DOMXPath($dom);

    foreach (iterator_to_array($xpath->query('//*[local-name()="script"]')) as $node) {
        $node->parentNode->removeChild($node);
    }

    foreach (iterator_to_array($xpath->query('//@*')) as $attr) {
        $name = strtolower($attr->nodeName);
        $value = trim($attr->nodeValue ?? '');
        if (strpos($name, 'on') === 0) {
            $attr->ownerElement->removeAttributeNode($attr);
            continue;
        }
        if (stripos($value, 'javascript:') === 0) {
            $attr->ownerElement->removeAttributeNode($attr);
        }
    }

    return $dom->saveXML();
}

echo json_encode([
    'success' => true,
    'path' => $webPath,
    'filename' => $storedName
]);
