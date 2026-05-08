<?php
/**
 * /public/crm/api/quiz-image-search.php
 * Proxy for Wikimedia Commons image search — used by the quiz admin
 * "Find Image" tool to attach open-licence images to quiz questions.
 *
 * GET  ?action=search&q=dandelion[&limit=9]
 *      Returns up to 9 Wikimedia Commons image results with thumbnail URLs.
 *
 * GET  ?action=wikipedia&q=Dandelion
 *      Returns the single main Wikipedia article image for a species name.
 *      Best for bulk seeding (one canonical image per plant/weed).
 *
 * Images served are from Wikimedia Commons under open licences
 * (CC BY-SA, CC BY, or public domain). The attribution string is
 * included in every result — display it next to any image used.
 */
declare(strict_types=1);
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

if (!defined('APP_ROOT')) {
    $__dir = __DIR__;
    for ($__i = 0; $__i < 5; $__i++) {
        $__dir = dirname($__dir);
        if (is_file($__dir . '/app/Core/paths.php')) {
            require_once $__dir . '/app/Core/paths.php';
            break;
        }
    }
    unset($__dir, $__i);
}

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
requireLogin();
$user    = getCurrentUser();
$isAdmin = ($user['role'] ?? '') === 'admin';
if (!$isAdmin) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Admin only']);
    exit;
}
session_write_close();

// ── Helpers ───────────────────────────────────────────────────────────────────

function wikiGet(string $url): ?array
{
    $ctx = stream_context_create([
        'http' => [
            'timeout'      => 8,
            'user_agent'   => 'MowologyCRM/1.0 (https://mowology.ca; admin@mowology.ca)',
            'ignore_errors' => true,
        ],
        'ssl'  => ['verify_peer' => true],
    ]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) return null;
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

function safeStr(mixed $v): string
{
    return is_string($v) ? trim($v) : '';
}

// ── Router ────────────────────────────────────────────────────────────────────

$action = safeStr($_GET['action'] ?? 'search');
$q      = trim(safeStr($_GET['q'] ?? ''));
$limit  = min(12, max(1, (int)($_GET['limit'] ?? 9)));

if ($q === '') {
    echo json_encode(['success' => false, 'error' => 'Missing query']);
    exit;
}

// ══════════════════════════════════════════════════════════════════════════════
// ACTION: search — Wikimedia Commons generator search (returns grid of images)
// ══════════════════════════════════════════════════════════════════════════════
if ($action === 'search') {
    $url = 'https://commons.wikimedia.org/w/api.php?' . http_build_query([
        'action'       => 'query',
        'generator'    => 'search',
        'gsrsearch'    => $q,
        'gsrnamespace' => 6,        // File: namespace
        'gsrlimit'     => $limit,
        'prop'         => 'imageinfo',
        'iiprop'       => 'url|extmetadata',
        'iiurlwidth'   => 350,
        'format'       => 'json',
        'formatversion'=> 2,
    ]);

    $data = wikiGet($url);
    if (!$data) {
        echo json_encode(['success' => false, 'error' => 'Wikimedia API unavailable']);
        exit;
    }

    $pages  = $data['query']['pages'] ?? [];
    $images = [];

    foreach ($pages as $page) {
        $info = $page['imageinfo'][0] ?? null;
        if (!$info) continue;

        $thumbUrl = safeStr($info['thumburl'] ?? '');
        $fullUrl  = safeStr($info['url'] ?? '');
        if ($thumbUrl === '' || $fullUrl === '') continue;

        // Skip non-image files
        if (!preg_match('/\.(jpe?g|png|gif|webp|svg)$/i', $fullUrl)) continue;

        $meta    = $info['extmetadata'] ?? [];
        $license = safeStr($meta['LicenseShortName']['value'] ?? ($meta['License']['value'] ?? 'Unknown'));
        $author  = strip_tags(safeStr($meta['Artist']['value'] ?? ''));
        $descUrl = 'https://commons.wikimedia.org/wiki/' . rawurlencode($page['title'] ?? '');

        $images[] = [
            'title'       => safeStr($page['title'] ?? ''),
            'thumb_url'   => $thumbUrl,
            'full_url'    => $fullUrl,
            'desc_url'    => $descUrl,
            'license'     => $license,
            'attribution' => $author !== '' ? "© {$author} ({$license})" : $license,
        ];
    }

    echo json_encode(['success' => true, 'images' => $images, 'query' => $q]);
    exit;
}

// ══════════════════════════════════════════════════════════════════════════════
// ACTION: wikipedia — single canonical article image (best for seeding)
// ══════════════════════════════════════════════════════════════════════════════
if ($action === 'wikipedia') {
    $url = 'https://en.wikipedia.org/w/api.php?' . http_build_query([
        'action'        => 'query',
        'titles'        => $q,
        'prop'          => 'pageimages|revisions',
        'pithumbsize'   => 600,
        'pilimit'       => 1,
        'redirects'     => 1,
        'format'        => 'json',
        'formatversion' => 2,
    ]);

    $data = wikiGet($url);
    if (!$data) {
        echo json_encode(['success' => false, 'error' => 'Wikipedia API unavailable']);
        exit;
    }

    $pages = $data['query']['pages'] ?? [];
    $page  = reset($pages);

    if (!$page || isset($page['missing'])) {
        echo json_encode(['success' => false, 'error' => "No Wikipedia article found for: {$q}"]);
        exit;
    }

    $thumb = $page['thumbnail'] ?? null;
    if (!$thumb) {
        echo json_encode(['success' => false, 'error' => "No image on Wikipedia article for: {$q}"]);
        exit;
    }

    $thumbUrl = safeStr($thumb['source'] ?? '');
    // Build the full-size URL by removing the width suffix from the thumbnail URL
    $fullUrl = preg_replace('/\/\d+px-[^\/]+$/', '', $thumbUrl);
    $fullUrl = $fullUrl ?: $thumbUrl;

    echo json_encode([
        'success'     => true,
        'thumb_url'   => $thumbUrl,
        'full_url'    => $thumbUrl,  // use thumb as display URL — full-size may be huge
        'attribution' => 'Wikimedia Commons (CC BY-SA)',
        'article'     => 'https://en.wikipedia.org/wiki/' . rawurlencode(safeStr($page['title'] ?? $q)),
        'query'       => $q,
    ]);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Unknown action']);
