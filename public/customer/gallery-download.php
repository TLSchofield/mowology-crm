<?php
/**
 * Client Gallery — ZIP Download
 *
 * Streams all the client's photos as a single zip file.
 * URL: /customer/gallery-download.php?token={portal_token}
 */
declare(strict_types=1);

$__dir = __DIR__;
for ($__i = 0; $__i < 5; $__i++) {
    $__dir = dirname($__dir);
    if (is_file($__dir . '/app/Core/paths.php')) {
        require_once $__dir . '/app/Core/paths.php';
        break;
    }
}
unset($__dir, $__i);

require_once PUBLIC_ROOT . '/app_config/config.php';

// Cap for safety — a zip of 500 photos can be ~300MB
const MAX_PHOTOS = 500;

function sendError(string $msg, int $code = 400): void {
    http_response_code($code);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset=utf-8><title>Mowology</title>'
       . '<body style="font-family:system-ui;padding:40px;text-align:center;">'
       . '<h2>Cannot download photos</h2><p>' . htmlspecialchars($msg) . '</p>'
       . '<p><a href="javascript:history.back()">Go back</a></p></body>';
    exit;
}

$token = trim($_GET['token'] ?? '');
if ($token === '') sendError('No token provided.');

$db = getDB();
$stmt = $db->prepare("SELECT id, first_name, last_name FROM contacts WHERE portal_token = ? LIMIT 1");
$stmt->execute([$token]);
$contact = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$contact) sendError('This download link is invalid.', 404);

// Fetch all client photos
$pStmt = $db->prepare("
    SELECT vp.id, vp.filename, vp.photo_type, vp.view_path, vp.original_filename,
           v.scheduled_date, v.id AS vid,
           pr.address AS property_address
    FROM visit_photos vp
    JOIN job_visits v ON v.id = vp.visit_id
    JOIN job_plans jp ON jp.id = v.plan_id
    JOIN properties pr ON pr.id = jp.property_id
    WHERE pr.site_contact_id = ?
      AND vp.deleted_at IS NULL
      AND v.status = 'completed'
    ORDER BY v.scheduled_date DESC, vp.uploaded_at ASC
    LIMIT " . MAX_PHOTOS
);
$pStmt->execute([(int)$contact['id']]);
$photos = $pStmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($photos)) {
    sendError('You don\'t have any photos yet.');
}

if (!class_exists('ZipArchive')) {
    sendError('Server cannot create zip files. Please contact us at office@mowology.ca.');
}

// Build the zip in a temp file
$tmpZip = tempnam(sys_get_temp_dir(), 'mowology_photos_');
$zip = new ZipArchive();
if ($zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    @unlink($tmpZip);
    sendError('Could not create zip archive. Please try again later.');
}

$addedCount = 0;
foreach ($photos as $ph) {
    // Try view_path first (full-size), then original filename
    $sourcePath = null;
    $candidates = [
        $ph['view_path'] ? PUBLIC_ROOT . $ph['view_path'] : null,
        PUBLIC_ROOT . '/uploads/photos/' . $ph['filename'],
    ];
    foreach ($candidates as $candidate) {
        if ($candidate && is_file($candidate) && is_readable($candidate)) {
            $sourcePath = $candidate;
            break;
        }
    }
    if (!$sourcePath) continue;

    // Arrange inside zip: Date_ServiceType/filename.jpg
    $date = $ph['scheduled_date'] ? date('Y-m-d', strtotime($ph['scheduled_date'])) : 'undated';
    $folder = $date . '_Visit-' . $ph['vid'];
    $ext = strtolower(pathinfo($ph['filename'], PATHINFO_EXTENSION) ?: 'jpg');
    $innerName = $folder . '/' . $ph['photo_type'] . '_' . $ph['id'] . '.' . $ext;
    $zip->addFile($sourcePath, $innerName);
    $addedCount++;
}

// README
$readme = "Mowology Landscaping — Photo Archive\n"
        . "Client: " . trim(($contact['first_name'] ?? '') . ' ' . ($contact['last_name'] ?? '')) . "\n"
        . "Generated: " . date('Y-m-d H:i') . "\n"
        . "Total photos: {$addedCount}\n\n"
        . "Folders are named by visit date. Files inside are labelled with photo type.\n"
        . "Questions? office@mowology.ca · (778) 846-9273\n";
$zip->addFromString('README.txt', $readme);

$zip->close();

if ($addedCount === 0) {
    @unlink($tmpZip);
    sendError('No photo files could be located on disk. Please contact us.');
}

// Stream the zip
$displayName = trim(($contact['first_name'] ?? '') . '_' . ($contact['last_name'] ?? ''));
$displayName = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $displayName) ?: 'mowology';
$zipName = 'mowology_photos_' . $displayName . '_' . date('Ymd') . '.zip';

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $zipName . '"');
header('Content-Length: ' . filesize($tmpZip));
header('Cache-Control: private, max-age=0, must-revalidate');
readfile($tmpZip);
@unlink($tmpZip);
exit;
