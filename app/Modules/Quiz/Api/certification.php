<?php
/**
 * Certification API — /crm/api/certification.php
 *
 * GET actions:  tiers, courses, course_detail, my_certs, user_certs,
 *               all_cert_summary, eligibility_check, service_type_check,
 *               exam_questions, variants, variant_draft
 *
 * POST actions: start_exam, submit_answer, finish_exam, revoke_cert,
 *               assign_question, remove_question, save_variant, delete_variant
 */
declare(strict_types=1);
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

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

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
requireLogin();
$user   = getCurrentUser();
$userId = (int)$user['id'];
$isAdmin = ($user['role'] ?? '') === 'admin';

session_write_close();

$db = getDB();

require_once APP_ROOT . '/Modules/Quiz/Services/CertificationService.php';
require_once APP_ROOT . '/Modules/Quiz/Services/VariantQuestionService.php';

$certSvc    = new CertificationService($db);
$variantSvc = new VariantQuestionService($db);

$action = $_GET['action'] ?? '';

function certOk(array $data): void
{
    echo json_encode(array_merge(['success' => true], $data));
    exit;
}
function certErr(string $msg, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}
function certInput(): array
{
    return (array)(json_decode(file_get_contents('php://input'), true) ?? []);
}
function certRequireAdmin(bool $isAdmin): void
{
    if (!$isAdmin) certErr('Admin only', 403);
}
function certCsrf(array $input): void
{
    if (!function_exists('verifyCSRFToken') || !verifyCSRFToken($input['csrf_token'] ?? '')) {
        certErr('Invalid CSRF token', 403);
    }
}

// ── GET routes ────────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    switch ($action) {

        case 'tiers':
            certOk(['tiers' => $certSvc->listTiers()]);

        case 'courses':
            $tierId = isset($_GET['tier_id']) ? (int)$_GET['tier_id'] : null;
            certOk(['courses' => $certSvc->listCourses($tierId)]);

        case 'course_detail': {
            $courseId = (int)($_GET['course_id'] ?? 0);
            if (!$courseId) certErr('course_id required');
            $elig = $certSvc->checkEligibility($userId, $courseId);

            // Question count for this course
            $stmt = $db->prepare(
                "SELECT COUNT(*) FROM cert_course_questions ccq
                 JOIN quiz_questions qq ON qq.id = ccq.question_id
                 WHERE ccq.course_id = ? AND qq.is_active = 1"
            );
            $stmt->execute([$courseId]);
            $qCount = (int)$stmt->fetchColumn();

            // Attempt history
            $stmt = $db->prepare(
                "SELECT attempt_number, score_pct, passed, started_at, completed_at
                 FROM cert_exam_sessions
                 WHERE user_id = ? AND course_id = ?
                 ORDER BY started_at DESC LIMIT 10"
            );
            $stmt->execute([$userId, $courseId]);
            $attempts = $stmt->fetchAll(PDO::FETCH_ASSOC);

            certOk([
                'eligibility'     => $elig,
                'question_count'  => $qCount,
                'attempt_history' => $attempts,
            ]);
        }

        case 'my_certs':
            certOk(['profile' => $certSvc->getUserCertProfile($userId)]);

        case 'user_certs': {
            certRequireAdmin($isAdmin);
            $targetUserId = (int)($_GET['user_id'] ?? 0);
            if (!$targetUserId) certErr('user_id required');
            certOk(['profile' => $certSvc->getUserCertProfile($targetUserId)]);
        }

        case 'all_cert_summary':
            certRequireAdmin($isAdmin);
            certOk(['summary' => $certSvc->getAllUserTierSummary()]);

        case 'eligibility_check': {
            $courseId = (int)($_GET['course_id'] ?? 0);
            if (!$courseId) certErr('course_id required');
            certOk($certSvc->checkEligibility($userId, $courseId));
        }

        case 'service_type_check': {
            $slug = $_GET['service_type_slug'] ?? '';
            if (!$slug) certErr('service_type_slug required');
            certOk($certSvc->canWorkServiceType($userId, $slug));
        }

        case 'exam_questions': {
            $sessionId = (int)($_GET['exam_session_id'] ?? 0);
            if (!$sessionId) certErr('exam_session_id required');
            $stmt = $db->prepare("SELECT * FROM cert_exam_sessions WHERE id = ? LIMIT 1");
            $stmt->execute([$sessionId]);
            $session = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$session || (int)$session['user_id'] !== $userId) certErr('Session not found', 404);
            if ($session['passed'] !== null) certErr('Exam already completed');

            $questionIds = array_filter(array_map('intval', explode(',', $session['question_ids'])));

            // Fetch already-answered question IDs for this session
            $stmt = $db->prepare(
                "SELECT question_id FROM cert_exam_answers WHERE exam_session_id = ?"
            );
            $stmt->execute([$sessionId]);
            $answered = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
            $answeredSet = array_flip($answered);

            // Find next unanswered question
            $nextId = null;
            foreach ($questionIds as $qid) {
                if (!isset($answeredSet[$qid])) { $nextId = $qid; break; }
            }
            if ($nextId === null) certOk(['done' => true, 'answered_count' => count($answered), 'questions_count' => count($questionIds)]);

            // Fetch question + options
            $stmt = $db->prepare(
                "SELECT q.id, q.question_text, q.question_type, q.image_path, q.learn_notes
                 FROM quiz_questions q WHERE q.id = ? LIMIT 1"
            );
            $stmt->execute([$nextId]);
            $q = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$q) certErr('Question not found', 404);

            $stmt = $db->prepare(
                "SELECT id, option_text FROM quiz_options WHERE question_id = ? ORDER BY RAND()"
            );
            $stmt->execute([$nextId]);
            $q['options'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Images
            $stmt = $db->prepare(
                "SELECT image_path, caption FROM quiz_question_images WHERE question_id = ? ORDER BY sort_order ASC"
            );
            $stmt->execute([$nextId]);
            $images = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $q['images'] = $images ?: ($q['image_path'] ? [['image_path' => $q['image_path'], 'caption' => '']] : []);

            certOk([
                'question'       => $q,
                'answered_count' => count($answered),
                'questions_count'=> count($questionIds),
                'done'           => false,
            ]);
        }

        case 'variants': {
            certRequireAdmin($isAdmin);
            $parentId = (int)($_GET['parent_id'] ?? 0);
            if (!$parentId) certErr('parent_id required');
            certOk(['variants' => $variantSvc->listVariants($parentId)]);
        }

        case 'variant_draft': {
            certRequireAdmin($isAdmin);
            $parentId = (int)($_GET['parent_id'] ?? 0);
            if (!$parentId) certErr('parent_id required');
            certOk(['draft' => $variantSvc->generateReverseVariantDraft($parentId)]);
        }

        default:
            certErr('Unknown action');
    }
}

// ── POST routes ────────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = certInput();
    certCsrf($input);
    $postAction = $input['action'] ?? '';

    switch ($postAction) {

        case 'start_exam': {
            $courseId = (int)($input['course_id'] ?? 0);
            if (!$courseId) certErr('course_id required');
            try {
                $result = $certSvc->startExam($userId, $courseId);
                certOk($result);
            } catch (\RuntimeException $e) {
                certErr($e->getMessage());
            }
        }

        case 'submit_answer': {
            $sessionId  = (int)($input['exam_session_id'] ?? 0);
            $questionId = (int)($input['question_id'] ?? 0);
            $optionId   = isset($input['selected_option_id']) ? (int)$input['selected_option_id'] : null;
            $timeTaken  = (int)($input['time_taken_seconds'] ?? 0);
            if (!$sessionId || !$questionId) certErr('exam_session_id and question_id required');
            try {
                $result = $certSvc->submitAnswer($sessionId, $questionId, $optionId, $timeTaken);
                certOk($result);
            } catch (\RuntimeException $e) {
                certErr($e->getMessage());
            }
        }

        case 'finish_exam': {
            $sessionId = (int)($input['exam_session_id'] ?? 0);
            if (!$sessionId) certErr('exam_session_id required');
            try {
                $result = $certSvc->finishExam($sessionId, $userId);
                certOk($result);
            } catch (\RuntimeException $e) {
                certErr($e->getMessage());
            }
        }

        case 'revoke_cert': {
            certRequireAdmin($isAdmin);
            $certRecordId = (int)($input['cert_record_id'] ?? 0);
            $reason       = trim($input['reason'] ?? '');
            if (!$certRecordId) certErr('cert_record_id required');
            if (!$reason)       certErr('reason required');
            $certSvc->revokeCert($certRecordId, $userId, $reason);
            certOk(['message' => 'Certification revoked.']);
        }

        case 'assign_question': {
            certRequireAdmin($isAdmin);
            $courseId   = (int)($input['course_id'] ?? 0);
            $questionId = (int)($input['question_id'] ?? 0);
            $required   = !empty($input['is_required']);
            $weight     = max(1, min(5, (int)($input['weight'] ?? 1)));
            if (!$courseId || !$questionId) certErr('course_id and question_id required');
            $certSvc->assignQuestionToCourse($courseId, $questionId, $required, $weight);
            certOk(['message' => 'Question assigned.']);
        }

        case 'remove_question': {
            certRequireAdmin($isAdmin);
            $courseId   = (int)($input['course_id'] ?? 0);
            $questionId = (int)($input['question_id'] ?? 0);
            if (!$courseId || !$questionId) certErr('course_id and question_id required');
            $certSvc->removeQuestionFromCourse($courseId, $questionId);
            certOk(['message' => 'Question removed.']);
        }

        case 'save_variant': {
            certRequireAdmin($isAdmin);
            $parentId    = (int)($input['parent_id'] ?? 0);
            $variantType = $input['variant_type'] ?? 'rephrase';
            $qText       = trim($input['question_text'] ?? '');
            $learnNotes  = trim($input['learn_notes'] ?? '') ?: null;
            $options     = $input['options'] ?? [];
            $variantId   = isset($input['variant_id']) ? (int)$input['variant_id'] : null;
            if (!$parentId || !$qText || count($options) < 2) certErr('parent_id, question_text, and at least 2 options required');
            try {
                $vid = $variantSvc->saveVariant($parentId, $variantType, $qText, $learnNotes, $options, $userId, $variantId);
                certOk(['variant_id' => $vid]);
            } catch (\Throwable $e) {
                certErr($e->getMessage());
            }
        }

        case 'delete_variant': {
            certRequireAdmin($isAdmin);
            $variantId = (int)($input['variant_id'] ?? 0);
            if (!$variantId) certErr('variant_id required');
            $variantSvc->deleteVariant($variantId);
            certOk(['message' => 'Variant deleted.']);
        }

        default:
            certErr('Unknown action');
    }
}

certErr('Method not allowed', 405);
