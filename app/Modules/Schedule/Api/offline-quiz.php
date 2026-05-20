<?php
/**
 * Offline Quiz API
 *
 * Returns all active quiz questions (with options + correct answer flag)
 * for local storage in IndexedDB. Also returns pre-shift settings so the
 * offline quiz flow knows how many questions to use.
 *
 * Auth: JWT Bearer or PHP session.
 * GET /crm/api/offline-quiz.php
 *
 * The correct option (is_correct) IS included — this data lives on the
 * user's device and is needed to evaluate answers without a server call.
 *
 * Response:
 *   {
 *     ok: true,
 *     settings: { preshift_enabled: bool, session_length: int },
 *     questions: [{ id, text, category_name, category_colour, difficulty,
 *                   image_path, options: [{ id, text, is_correct }] }]
 *   }
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

try {
    require_once PUBLIC_ROOT . '/loginAuth/auth.php';

    $user = requireLoginOrJwt();
    session_write_close();

    $db = getDB();

    // ── Pre-shift settings ────────────────────────────────────────────────────
    $preshiftEnabled = false;
    $sessionLength   = 3;

    $row = $db->query(
        "SELECT setting_value FROM ops_settings WHERE setting_key='quiz_preshift_enabled'"
    )->fetch(PDO::FETCH_ASSOC);
    if ($row && $row['setting_value'] == '1') {
        $preshiftEnabled = true;
    }

    $lenRow = $db->query(
        "SELECT setting_value FROM ops_settings WHERE setting_key='quiz_preshift_session_length'"
    )->fetch(PDO::FETCH_ASSOC);
    if ($lenRow) {
        $sessionLength = max(3, (int)$lenRow['setting_value']);
    }

    // ── Questions + options ───────────────────────────────────────────────────
    $qStmt = $db->query(
        "SELECT q.id, q.question_text AS text, q.difficulty, q.image_path,
                c.name AS category_name, c.colour AS category_colour
         FROM quiz_questions q
         JOIN quiz_categories c ON c.id = q.category_id
         WHERE q.is_active = 1
         ORDER BY q.category_id, q.id"
    );
    $rawQuestions = $qStmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($rawQuestions)) {
        echo json_encode([
            'ok'       => true,
            'settings' => ['preshift_enabled' => $preshiftEnabled, 'session_length' => $sessionLength],
            'questions' => [],
        ]);
        exit;
    }

    // Batch-load all options in one query
    $qids = array_column($rawQuestions, 'id');
    $ph   = implode(',', array_fill(0, count($qids), '?'));
    $oStmt = $db->prepare(
        "SELECT question_id, id, option_text AS text, is_correct
         FROM quiz_options
         WHERE question_id IN ({$ph})
         ORDER BY question_id, sort_order"
    );
    $oStmt->execute($qids);

    $optsByQ = [];
    foreach ($oStmt->fetchAll(PDO::FETCH_ASSOC) as $opt) {
        $optsByQ[(int)$opt['question_id']][] = [
            'id'        => (int)$opt['id'],
            'text'      => $opt['text'],
            'is_correct' => (bool)$opt['is_correct'],
        ];
    }

    $questions = array_map(function ($q) use ($optsByQ) {
        return [
            'id'              => (int)$q['id'],
            'text'            => $q['text'],
            'category_name'   => $q['category_name'],
            'category_colour' => $q['category_colour'],
            'difficulty'      => $q['difficulty'],
            'image_path'      => $q['image_path'] ?: null,
            'options'         => $optsByQ[(int)$q['id']] ?? [],
        ];
    }, $rawQuestions);

    echo json_encode([
        'ok'       => true,
        'settings' => ['preshift_enabled' => $preshiftEnabled, 'session_length' => $sessionLength],
        'questions' => $questions,
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error']);
}
