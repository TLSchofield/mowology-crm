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
 *
 * PRODUCTION SAFETY — READ BEFORE EDITING
 * ────────────────────────────────────────
 * This is a read-only endpoint — it writes nothing. Safe to retry.
 *
 * Mastery thresholds in the CASE expressions (≥4 = mastered, 1–3 = learning,
 * 0 = unseen) MUST stay in sync with masteryMeta() in QuizHelpers.php.
 * If you change the mastery scale (0–5), update both here and in masteryMeta().
 * The iOS hub uses mastered_count / question_count to draw progress bars —
 * an off-by-one in the threshold will silently show wrong mastery fractions.
 *
 * Both quiz_questions and quiz_user_mastery are LEFT JOINed intentionally:
 *   - LEFT JOIN quiz_questions: categories with zero active questions still appear
 *   - LEFT JOIN quiz_user_mastery: questions with no mastery row get COALESCE(level,0) = unseen
 * Changing either to INNER JOIN removes unseen questions from the count, making
 * the category appear fully mastered prematurely.
 *
 * due_count uses next_review_at <= NOW() AND mastery_level > 0 — the mastery > 0
 * guard prevents a never-seen question from appearing as "due" just because its
 * next_review_at defaults to NULL (NULL <= NOW() is false, so this is safe regardless,
 * but the guard makes the intent explicit).
 *
 * Results are ordered by sort_order then name — iOS renders them in this order.
 * Do not add a user-specific sort without coordinating with the iOS QuizHubView.
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
