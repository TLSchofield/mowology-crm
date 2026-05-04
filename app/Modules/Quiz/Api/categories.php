<?php
declare(strict_types=1);

/**
 * app/Modules/Quiz/Api/categories.php
 *
 * GET /api/quiz/categories
 * Authorization: Bearer <jwt>
 *
 * Returns all active quiz categories with per-user mastery breakdown.
 *
 * Response 200:
 * {
 *   "success": true,
 *   "categories": [
 *     { id, name, description, icon, colour, sort_order,
 *       question_count, mastered_count, learning_count, unseen_count, due_count }
 *   ],
 *   "season": { season_label, season_icon, season_tagline }
 * }
 */

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

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once APP_ROOT . '/Core/config.php';
require_once APP_ROOT . '/Core/Auth/JwtAuth.php';
require_once APP_ROOT . '/Modules/Quiz/QuizHelpers.php';

$jwtUser = requireJwt();
$userId  = (int)$jwtUser['id'];

try {
    $db = Database::pdo();

    $stmt = $db->prepare(
        "SELECT c.id, c.name, c.description, c.icon, c.colour, c.sort_order,
                COUNT(q.id) AS question_count,
                SUM(CASE WHEN m.mastery_level >= 4 THEN 1 ELSE 0 END) AS mastered_count,
                SUM(CASE WHEN m.mastery_level BETWEEN 1 AND 3 THEN 1 ELSE 0 END) AS learning_count,
                SUM(CASE WHEN m.mastery_level IS NULL OR m.mastery_level = 0 THEN 1 ELSE 0 END) AS unseen_count,
                SUM(CASE WHEN m.next_review_at <= NOW() AND m.mastery_level > 0 THEN 1 ELSE 0 END) AS due_count
         FROM quiz_categories c
         LEFT JOIN quiz_questions q ON q.category_id = c.id AND q.is_active = 1
         LEFT JOIN quiz_user_mastery m ON m.question_id = q.id AND m.user_id = ?
         WHERE c.is_active = 1
         GROUP BY c.id
         ORDER BY c.sort_order, c.name"
    );
    $stmt->execute([$userId]);
    $cats = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($cats as &$c) {
        $c['question_count'] = (int)$c['question_count'];
        $c['mastered_count'] = (int)$c['mastered_count'];
        $c['learning_count'] = (int)$c['learning_count'];
        $c['unseen_count']   = (int)$c['unseen_count'];
        $c['due_count']      = (int)$c['due_count'];
    }
    unset($c);

    $ctx = getSeasonContext();

    http_response_code(200);
    echo json_encode([
        'success'    => true,
        'categories' => $cats,
        'season'     => [
            'season_label'   => $ctx['season_label'],
            'season_icon'    => $ctx['season_icon'],
            'season_tagline' => $ctx['season_tagline'],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    error_log('[quiz/categories] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to load categories.']);
}
