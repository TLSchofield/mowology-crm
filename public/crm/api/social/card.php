<?php
/**
 * Social Card API
 *
 * Handles posting card generation and preview for the social post editor.
 *
 * GET  ?action=preview&post_id=N        → returns card URL (generates if missing)
 * POST { action:'generate', post_id, template_type, csrf_token }  → regenerate card
 * POST { action:'set-template', post_id, template_type, csrf_token } → change type + regenerate
 *
 * Access: authenticated CRM users with marketing.view permission.
 * Write actions (generate, set-template) require marketing.edit or admin.
 *
 * @package Mowology\Social
 */

declare(strict_types=1);
header('Content-Type: application/json');

if (!defined('APP_ROOT')) {
    $__dir = __DIR__;
    for ($__i = 0; $__i < 6; $__i++) {
        $__dir = dirname($__dir);
        if (is_file($__dir . '/app/Core/paths.php')) {
            require_once $__dir . '/app/Core/paths.php';
            break;
        }
    }
    unset($__dir, $__i);
}

if (!defined('APP_ROOT')) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Could not locate app/Core/paths.php']);
    exit;
}

try {
    require_once PUBLIC_ROOT . '/loginAuth/auth.php';
    require_once CRM_INCLUDES . '/functions.php';
    require_once APP_ROOT . '/Modules/Social/Services/SocialHashtagEngine.php';
    require_once APP_ROOT . '/Modules/Social/Services/SocialCardGenerator.php';

    requireLogin();
    $user    = getCurrentUser();
    $isAdmin = ($user['role'] ?? '') === 'admin';
    $canEdit = $isAdmin || userHasPermission('marketing.edit');

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $action = '';
    $postId = 0;

    if ($method === 'GET') {
        $action = $_GET['action'] ?? 'preview';
        $postId = (int)($_GET['post_id'] ?? 0);
    } else {
        session_write_close();
        $raw    = json_decode(file_get_contents('php://input'), true) ?? [];
        $action = $raw['action'] ?? '';
        $postId = (int)($raw['post_id'] ?? 0);
    }

    if ($postId < 1) {
        throw new \InvalidArgumentException('post_id required');
    }

    $db = getDB();

    switch ($action) {

        // ── GET preview ────────────────────────────────────────────────────
        case 'preview': {
            // Return existing card URL, generate on-demand if missing
            $stmt = $db->prepare("
                SELECT md.file_path, md.id AS derivative_id, sp.card_template_type
                FROM social_posts sp
                LEFT JOIN media_derivatives md ON md.id = sp.card_derivative_id
                WHERE sp.id = ?
            ");
            $stmt->execute([$postId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$row) {
                throw new \RuntimeException('Post not found: ' . $postId);
            }

            $filePath = $row['file_path'] ?? null;

            // Generate on-demand if card is missing
            if (!$filePath || !file_exists(PUBLIC_ROOT . $filePath)) {
                $templateType = $row['card_template_type'] ?? 'hero_after';
                $result = SocialCardGenerator::generate($postId, $templateType, $db);
                if ($result['success']) {
                    $filePath = $result['file_path'];
                } else {
                    echo json_encode([
                        'success'   => false,
                        'error'     => $result['error'],
                        'file_path' => null,
                    ]);
                    exit;
                }
            }

            echo json_encode([
                'success'   => true,
                'file_path' => $filePath,
                'url'       => $filePath, // web-root-relative, same value
            ]);
            break;
        }

        // ── POST generate ──────────────────────────────────────────────────
        case 'generate': {
            if (!$canEdit) {
                throw new \RuntimeException('Marketing edit permission required.');
            }
            if (!verifyCSRFToken($raw['csrf_token'] ?? '')) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
                exit;
            }

            $templateType = trim($raw['template_type'] ?? 'hero_after');
            $allowed = ['before_after', 'hero_after', 'multi_grid', 'engagement_showcase'];
            if (!in_array($templateType, $allowed, true)) {
                $templateType = 'hero_after';
            }

            $result = SocialCardGenerator::generate($postId, $templateType, $db);
            echo json_encode($result);
            break;
        }

        // ── POST set-template ──────────────────────────────────────────────
        case 'set-template': {
            if (!$canEdit) {
                throw new \RuntimeException('Marketing edit permission required.');
            }
            if (!verifyCSRFToken($raw['csrf_token'] ?? '')) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
                exit;
            }

            $templateType = trim($raw['template_type'] ?? 'hero_after');
            $allowed = ['before_after', 'hero_after', 'multi_grid', 'engagement_showcase'];
            if (!in_array($templateType, $allowed, true)) {
                throw new \InvalidArgumentException('Invalid template_type: ' . $templateType);
            }

            // Update card_template_type on the post
            $db->prepare("UPDATE social_posts SET card_template_type = ? WHERE id = ?")
               ->execute([$templateType, $postId]);

            // Regenerate card
            $result = SocialCardGenerator::generate($postId, $templateType, $db);
            echo json_encode($result);
            break;
        }

        default:
            throw new \InvalidArgumentException('Unknown action: ' . $action);
    }

} catch (\Throwable $e) {
    error_log("social/card.php error: " . $e->getMessage());
    $code = ($e instanceof \InvalidArgumentException) ? 400 : 500;
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
