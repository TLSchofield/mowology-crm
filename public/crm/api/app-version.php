<?php
/**
 * API: /crm/api/app-version.php
 *
 * Returns the current Mowology Crew Android app version.
 * The native app and login page poll this to detect when an update is available.
 *
 * Public GET — no auth required (allows pre-login version check on the login page).
 *
 * Response:
 * {
 *   "version": "1.0.0",
 *   "build": 1,
 *   "released": "2026-02-19",
 *   "apk_url": "/crm/downloads/mowology-crew.apk",
 *   "release_notes": "..."
 * }
 *
 * To release an update:
 *   1. Upload the new APK to /crm/downloads/mowology-crew.apk
 *   2. Bump "version" and "build" in /crm/downloads/app-version.json
 *   3. Update "released" to today's date
 *   4. Update "release_notes" with what changed
 *   Done. All devices will see the update banner on next app launch.
 */

declare(strict_types=1);
header('Content-Type: application/json');
header('Cache-Control: no-store');

$versionFile = __DIR__ . '/../downloads/app-version.json';

if (!is_file($versionFile)) {
    http_response_code(503);
    echo json_encode(['error' => 'Version file not found']);
    exit;
}

$data = file_get_contents($versionFile);
$json = json_decode($data, true);

if (!$json || !isset($json['version'])) {
    http_response_code(500);
    echo json_encode(['error' => 'Invalid version file']);
    exit;
}

echo json_encode($json);
