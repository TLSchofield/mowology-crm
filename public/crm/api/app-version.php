<?php
/**
 * API: /crm/api/app-version.php
 *
 * Returns the current Mowology Crew app version info for both platforms.
 * Public GET — no auth required (pre-login version check).
 *
 * Response (nested format):
 * {
 *   "android": {
 *     "version": "1.1.0",       -- current release
 *     "build": 5,
 *     "min_version": "1.1.0",   -- oldest version allowed (bump to force update)
 *     "min_build": 5,
 *     "apk_url": "/crm/downloads/mowology-crew.apk",
 *     "released": "2026-03-27",
 *     "release_notes": "...",
 *     "force_update": false      -- set true to block ALL versions immediately
 *   },
 *   "ios": {
 *     "version": "1.0.0",
 *     "min_version": "1.0.0",
 *     "store_url": "",           -- App Store URL when published
 *     "released": "2026-03-03",
 *     "release_notes": "...",
 *     "force_update": false
 *   }
 * }
 *
 * To release an Android update:
 *   1. Upload new APK to /crm/downloads/mowology-crew.apk
 *   2. Bump "version", "build", "min_version", "min_build" in app-version.json
 *   3. Set "force_update": true for mandatory updates
 *   4. Deploy app-version.json via lftp
 *   All devices will see the overlay on next app launch.
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

if (!$json) {
    http_response_code(500);
    echo json_encode(['error' => 'Invalid version file']);
    exit;
}

// Handle legacy flat format { "version": "1.0", ... } — wrap in android key
if (isset($json['version']) && !isset($json['android'])) {
    $json = ['android' => $json, 'ios' => ['version' => '1.0.0', 'min_version' => '1.0.0', 'force_update' => false]];
}

echo json_encode($json);
