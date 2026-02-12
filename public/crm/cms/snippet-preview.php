<?php
/**
 * AJAX endpoint: renders a snippet's block as HTML preview.
 * GET ?id=<snippet_id> — renders the snippet by ID with default tokens resolved
 * Returns rendered block HTML wrapped in a minimal container.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/cms-functions.php';
require_once dirname(__DIR__) . '/includes/cms-token-engine.php';

requireLogin();

$snippetId = (int)($_GET['id'] ?? 0);
if ($snippetId <= 0) {
    http_response_code(400);
    echo '<div class="text-muted text-center py-4">No snippet selected.</div>';
    exit;
}

$db = getDB();
$stmt = $db->prepare("SELECT id, snippet_key, variant, block_type, content_json, notes, is_active FROM content_snippets WHERE id = ?");
$stmt->execute([$snippetId]);
$snippet = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$snippet) {
    http_response_code(404);
    echo '<div class="text-muted text-center py-4">Snippet not found.</div>';
    exit;
}

$blockType = $snippet['block_type'];
$config = json_decode($snippet['content_json'], true) ?: [];

// Resolve tokens using defaults (no page context for snippets)
$tokens = cms_getTokenDefaults();
$config = cms_processBlockConfig($config, $tokens, false);

// Find the block renderer
$rendererPath = dirname(__DIR__) . '/includes/blocks/' . $blockType . '.php';
if (!file_exists($rendererPath)) {
    echo '<div class="alert alert-warning">No renderer found for block type: <strong>' . h($blockType) . '</strong></div>';
    exit;
}

// Render the block
ob_start();
include $rendererPath;
$html = ob_get_clean();

echo $html;
