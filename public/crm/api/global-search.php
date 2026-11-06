<?php
/**
 * Global Search API — Spotlight/Command Palette
 *
 * GET ?q=<term>  (min 2 chars)
 * Returns results grouped by entity type for the global search bar.
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
requireLogin();
session_write_close(); // release session lock — search fires on every keypress

$db = getDB();
require_once APP_ROOT . '/Modules/Search/Services/UniversalSearchService.php';
header('Content-Type: application/json');

$q = trim($_GET['q'] ?? '');

if (strlen($q) < 2) {
    echo json_encode(['success' => true, 'results' => []]);
    exit;
}

// Each category only for those who may open its pages (Fable review 2026-10-12): crew were
// listed staff emails/roles and invoice amounts without billing.view / team.view.
$can = static fn(string $perm): bool => function_exists('userHasPermission') && userHasPermission($perm);

// One search for web and phone (UniversalSearchService, 2026-11-06): every word must match, phone numbers match in any
// format, and receipts, suppliers and client emails are found too. Each group still needs its permission, as before.
$userId  = (int)(getCurrentUser()['id'] ?? 0);
$groups  = (new UniversalSearchService($db))->search($q, $can, $userId, 5)['groups'];
$results = UniversalSearchService::flatForWeb($groups);

echo json_encode(['success' => true, 'results' => $results]);
