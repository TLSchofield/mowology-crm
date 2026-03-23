<?php
require_once __DIR__ . '/../loginAuth/auth.php';
requireLogin();
$user   = getCurrentUser();
$userId = (int)$user['id'];
$isAdmin = ($user['role'] ?? '') === 'admin';

$pageTitle  = 'Certification — Mowology CRM';
$activePage = 'certification';
$extraHead  = '';

// Load service for server-side rendering of initial profile
$__dir = __DIR__;
for ($__i = 0; $__i < 5; $__i++) {
    $__dir = dirname($__dir);
    if (is_file($__dir . '/app/Core/paths.php')) {
        require_once $__dir . '/app/Core/paths.php';
        break;
    }
}
unset($__dir, $__i);
require_once APP_ROOT . '/Modules/Quiz/Services/CertificationService.php';

$db      = getDB();
$certSvc = new CertificationService($db);

try {
    // For admin viewing another user
    $viewUserId = $isAdmin && isset($_GET['user_id']) ? (int)$_GET['user_id'] : $userId;
    $profile    = $certSvc->getUserCertProfile($viewUserId);
    $tiers      = $certSvc->listTiers();
} catch (\Throwable $e) {
    $profile = [];
    $tiers   = [];
}

$csrfToken = generateCSRFToken();
?>
<?php include 'includes/appstack_head.php'; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-1" style="color:var(--mw-dark);">Crew Certification</h1>
        <p class="text-muted mb-0 small">Earn tier certifications to unlock job types and demonstrate expertise.</p>
    </div>
    <div class="d-flex gap-2">
        <a href="/crm/quiz-learn_appstack.php" class="btn btn-outline-secondary btn-sm">
            <i data-feather="book-open" class="align-middle mr-1" style="width:14px;height:14px;"></i> Study Mode
        </a>
        <?php if ($isAdmin): ?>
        <a href="/crm/certification-admin_appstack.php" class="btn btn-sm" style="background:var(--mw-green);color:#fff;">
            <i data-feather="settings" class="align-middle mr-1" style="width:14px;height:14px;"></i> Admin
        </a>
        <?php endif; ?>
    </div>
</div>

<?php if ($isAdmin && isset($_GET['user_id']) && (int)$_GET['user_id'] !== $userId):
    $viewUser = $db->prepare("SELECT full_name FROM users WHERE id = ? LIMIT 1");
    $viewUser->execute([$viewUserId]);
    $viewName = $viewUser->fetchColumn() ?: 'User #' . $viewUserId;
?>
<div class="alert alert-info mb-4 small">
    <i data-feather="eye" class="align-middle mr-1" style="width:14px;height:14px;"></i>
    Viewing certification profile for <strong><?= htmlspecialchars($viewName) ?></strong>.
    <a href="/crm/certification_appstack.php">View my own</a>
</div>
<?php endif; ?>

<!-- Tier Overview Banner -->
<div class="row mb-4" id="tierBanner">
<?php foreach ($tiers as $tier):
    $level   = (int)$tier['tier_level'];
    $tierRow = $profile[$level] ?? null;
    $achieved = $tierRow['achieved'] ?? false;
    $courses  = $tierRow['courses'] ?? [];
    $reqCount = count(array_filter($courses, fn($c) => (int)$c['course']['is_required']));
    $passedReq = count(array_filter($courses, fn($c) => (int)$c['course']['is_required'] && $c['status'] === 'active'));
?>
<div class="col-md-4 mb-3">
    <div class="card h-100 mw-cert-tier-card <?= $achieved ? 'mw-cert-tier-active' : '' ?>" style="border-left: 4px solid <?= htmlspecialchars($tier['colour']) ?>;">
        <div class="card-body">
            <div class="d-flex align-items-center mb-2">
                <i data-feather="<?= htmlspecialchars($tier['icon']) ?>" class="align-middle mr-2" style="color:<?= htmlspecialchars($tier['colour']) ?>;width:22px;height:22px;"></i>
                <div>
                    <div class="font-weight-700 mb-0" style="font-size:.95rem;"><?= htmlspecialchars($tier['name']) ?></div>
                    <?php if ($achieved): ?>
                    <span class="badge badge-success badge-pill" style="font-size:.7rem;">Achieved</span>
                    <?php else: ?>
                    <span class="badge badge-secondary badge-pill" style="font-size:.7rem;"><?= $passedReq ?>/<?= $reqCount ?> required</span>
                    <?php endif; ?>
                </div>
            </div>
            <p class="text-muted small mb-2"><?= htmlspecialchars($tier['description'] ?? '') ?></p>
            <div class="progress" style="height:6px;">
                <div class="progress-bar" role="progressbar"
                     style="width:<?= $reqCount > 0 ? round(($passedReq / $reqCount) * 100) : 0 ?>%;background:<?= htmlspecialchars($tier['colour']) ?>;"
                     aria-valuenow="<?= $passedReq ?>" aria-valuemin="0" aria-valuemax="<?= $reqCount ?>"></div>
            </div>
        </div>
    </div>
</div>
<?php endforeach; ?>
</div>

<!-- Course Cards by Tier -->
<?php foreach ($profile as $tierLevel => $tierData):
    $tier    = $tierData['tier'];
    $courses = $tierData['courses'];
    if (empty($courses)) continue;
?>
<div class="card mb-4">
    <div class="card-header d-flex align-items-center" style="background:var(--mw-light);border-bottom:1px solid var(--mw-light);">
        <i data-feather="<?= htmlspecialchars($tier['icon']) ?>" class="align-middle mr-2" style="color:<?= htmlspecialchars($tier['colour']) ?>;width:16px;height:16px;"></i>
        <strong><?= htmlspecialchars($tier['name']) ?></strong>
        <?php if ($tierData['achieved']): ?>
        <span class="badge badge-success ml-2">Tier Achieved</span>
        <?php endif; ?>
    </div>
    <div class="card-body p-0">
        <?php foreach ($courses as $i => $entry):
            $c           = $entry['course'];
            $status      = $entry['status'];
            $bestScore   = $entry['best_score_pct'];
            $attempts    = $entry['attempts'];
            $readiness   = $entry['readiness_pct'];
            $cert        = $entry['cert_record'];
            $passPct     = (int)$c['pass_score_pct'];
            $isRequired  = (bool)$c['is_required'];

            $statusColor = match($status) {
                'active'  => '#1a7a4a',
                'expired' => '#856404',
                'revoked' => '#721c24',
                default   => '#6b7280',
            };
            $statusLabel = match($status) {
                'active'  => 'Certified',
                'expired' => 'Expired',
                'revoked' => 'Revoked',
                default   => 'Not started',
            };
        ?>
        <div class="p-4 <?= $i > 0 ? 'border-top' : '' ?> mw-cert-course-row">
            <div class="row align-items-center">
                <div class="col-md-5">
                    <div class="d-flex align-items-start">
                        <i data-feather="<?= htmlspecialchars($c['icon']) ?>"
                           class="align-middle mr-2 mt-1 flex-shrink-0"
                           style="width:18px;height:18px;color:<?= htmlspecialchars($c['colour']) ?>;"></i>
                        <div>
                            <div class="font-weight-600"><?= htmlspecialchars($c['name']) ?>
                                <?php if ($isRequired): ?>
                                <span class="badge badge-light" style="font-size:.65rem;">Required</span>
                                <?php else: ?>
                                <span class="badge badge-light" style="font-size:.65rem;color:#6b7280;">Optional</span>
                                <?php endif; ?>
                            </div>
                            <div class="text-muted small"><?= htmlspecialchars($c['description'] ?? '') ?></div>
                            <div class="mt-1 small">
                                <span style="color:<?= $statusColor ?>;font-weight:600;"><?= $statusLabel ?></span>
                                <?php if ($cert && $cert['expires_at']): ?>
                                <span class="text-muted ml-2">Expires <?= htmlspecialchars(substr($cert['expires_at'], 0, 10)) ?></span>
                                <?php endif; ?>
                                <?php if ($attempts > 0): ?>
                                <span class="text-muted ml-2"><?= $attempts ?> attempt<?= $attempts !== 1 ? 's' : '' ?></span>
                                <?php if ($bestScore > 0): ?>
                                <span class="text-muted">, best: <strong><?= $bestScore ?>%</strong></span>
                                <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="small text-muted mb-1">Study Readiness</div>
                    <div class="d-flex align-items-center">
                        <div class="progress flex-grow-1 mr-2" style="height:8px;">
                            <div class="progress-bar" role="progressbar"
                                 style="width:<?= $readiness ?>%;background:<?= $readiness >= 75 ? 'var(--mw-green)' : ($readiness >= 40 ? '#f0ad4e' : '#dc3545') ?>;"
                                 aria-valuenow="<?= $readiness ?>" aria-valuemin="0" aria-valuemax="100"></div>
                        </div>
                        <span class="small font-weight-600"><?= $readiness ?>%</span>
                    </div>
                    <div class="small text-muted"><?= $readiness >= 75 ? 'Ready to test' : 'Keep studying' ?></div>
                </div>

                <div class="col-md-4 text-right">
                    <?php if ($status === 'active'): ?>
                    <span class="btn btn-sm btn-success" style="cursor:default;">
                        <i data-feather="check-circle" class="align-middle mr-1" style="width:13px;height:13px;"></i>
                        Certified
                    </span>
                    <a href="/crm/quiz-learn_appstack.php?course_id=<?= $c['id'] ?>"
                       class="btn btn-sm btn-outline-secondary ml-1">Study Again</a>
                    <?php elseif ($readiness >= 75): ?>
                    <button class="btn btn-sm btn-warning font-weight-700"
                            onclick="startExam(<?= (int)$c['id'] ?>, '<?= htmlspecialchars($c['name'], ENT_QUOTES) ?>')">
                        <i data-feather="award" class="align-middle mr-1" style="width:13px;height:13px;"></i>
                        Take Exam
                    </button>
                    <a href="/crm/quiz-learn_appstack.php?course_id=<?= $c['id'] ?>"
                       class="btn btn-sm btn-outline-secondary ml-1">Study</a>
                    <?php else: ?>
                    <a href="/crm/quiz-learn_appstack.php?course_id=<?= $c['id'] ?>"
                       class="btn btn-sm ml-1" style="background:var(--mw-green);color:#fff;">
                        <i data-feather="book-open" class="align-middle mr-1" style="width:13px;height:13px;"></i>
                        Study First
                    </a>
                    <button class="btn btn-sm btn-outline-secondary ml-1"
                            onclick="startExam(<?= (int)$c['id'] ?>, '<?= htmlspecialchars($c['name'], ENT_QUOTES) ?>')"
                            title="Not recommended — readiness is low">
                        Try Exam Anyway
                    </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endforeach; ?>

<?php if (empty($profile)): ?>
<div class="card">
    <div class="card-body text-center py-5">
        <i data-feather="award" class="align-middle mb-3" style="width:48px;height:48px;color:var(--mw-green);"></i>
        <h5>Certification system is being set up</h5>
        <p class="text-muted">Run migration 990 to activate courses and tiers.</p>
        <?php if ($isAdmin): ?>
        <a href="/crm/api/run-migration-quiz-certification.php" class="btn btn-sm btn-outline-primary">Run Migration 990</a>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- Exam Start Modal -->
<div class="modal fade" id="examModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header" style="background:var(--mw-dark);color:#fff;">
                <h5 class="modal-title">
                    <i data-feather="award" class="align-middle mr-2" style="width:18px;height:18px;"></i>
                    <span id="examModalTitle">Start Certification Exam</span>
                </h5>
                <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                <div id="examModalAlert" class="alert" style="display:none;"></div>
                <div id="examModalBody">
                    <p class="mb-2">You are about to start a timed certification exam for:</p>
                    <p class="font-weight-700 mb-3" id="examModalCourseName"></p>
                    <div id="examEligibilityInfo" class="alert alert-info small mb-3" style="display:none;"></div>
                    <ul class="small text-muted mb-3">
                        <li>Questions are drawn from the course question pool.</li>
                        <li>You must reach the pass score to earn the certification.</li>
                        <li>Your mastery data updates after each exam (affects study mode).</li>
                        <li>Failed exams have a cooldown period before retrying.</li>
                    </ul>
                </div>
                <div id="examModalLoading" class="text-center py-3" style="display:none;">
                    <div class="spinner-border text-success" role="status"></div>
                    <div class="mt-2 small text-muted">Starting exam…</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                <button type="button" class="btn font-weight-700" id="examConfirmBtn"
                        style="background:var(--mw-dark);color:#fff;" onclick="confirmStartExam()">
                    Begin Exam
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Exam Play Modal (full-screen) -->
<div class="modal fade" id="examPlayModal" tabindex="-1" data-backdrop="static" data-keyboard="false">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header" style="background:var(--mw-forest);color:#fff;">
                <div class="d-flex w-100 align-items-center justify-content-between">
                    <div>
                        <strong id="examPlayTitle">Certification Exam</strong>
                        <span class="ml-3 small" id="examPlayProgress"></span>
                    </div>
                    <div id="examPlayTimer" class="font-weight-700" style="font-size:1.1rem;font-variant-numeric:tabular-nums;"></div>
                </div>
            </div>
            <div class="modal-body p-4" id="examPlayBody">
                <div id="examPlayLoading" class="text-center py-5">
                    <div class="spinner-border text-success" role="status"></div>
                </div>
                <div id="examPlayQuestion" style="display:none;">
                    <p class="text-muted small mb-1" id="examPlayQuestionNum"></p>
                    <p class="font-weight-600 mb-3" style="font-size:1.05rem;" id="examPlayQuestionText"></p>
                    <div id="examPlayImages" class="mb-3"></div>
                    <div id="examPlayOptions" class="d-flex flex-column" style="gap:10px;"></div>
                    <div id="examPlayFeedback" class="mt-3 p-3 rounded small" style="display:none;"></div>
                </div>
                <div id="examPlayResult" style="display:none;text-align:center;padding:24px 0;">
                    <div id="examResultIcon" style="font-size:3rem;margin-bottom:16px;"></div>
                    <h4 id="examResultTitle"></h4>
                    <div id="examResultScore" class="font-weight-700 mb-2" style="font-size:1.5rem;"></div>
                    <p id="examResultMsg" class="text-muted"></p>
                    <div id="examResultTier" class="alert alert-success mt-3" style="display:none;"></div>
                </div>
            </div>
            <div class="modal-footer" id="examPlayFooter">
                <button type="button" class="btn btn-outline-secondary btn-sm" id="examSkipBtn"
                        onclick="submitAnswer(null)" style="display:none;">Skip</button>
                <button type="button" class="btn btn-sm font-weight-700" id="examNextBtn"
                        style="background:var(--mw-green);color:#fff;display:none;" onclick="loadNextQuestion()">
                    Next Question
                </button>
                <button type="button" class="btn btn-sm font-weight-700" id="examFinishBtn"
                        style="background:var(--mw-dark);color:#fff;display:none;" onclick="finishExam()">
                    Submit Exam
                </button>
                <button type="button" class="btn btn-sm btn-success font-weight-700" id="examDoneBtn"
                        style="display:none;" onclick="location.reload()">
                    View My Certifications
                </button>
            </div>
        </div>
    </div>
</div>

<style>
.mw-cert-tier-card { transition: box-shadow .2s; }
.mw-cert-tier-card:hover { box-shadow: 0 4px 16px rgba(0,0,0,.1); }
.mw-cert-tier-active { background: #f0faf5; }
.mw-cert-course-row:hover { background: #fafafa; }
.mw-cert-option-btn {
    display: block; width: 100%;
    text-align: left; padding: 12px 16px;
    border: 2px solid #d1d5db; border-radius: 8px;
    background: #fff; cursor: pointer;
    font-size: .9rem; transition: border-color .15s, background .15s;
}
.mw-cert-option-btn:hover { border-color: var(--mw-green); background: #f0faf5; }
.mw-cert-option-btn.correct { border-color: #1a7a4a; background: #d4edda; font-weight: 700; }
.mw-cert-option-btn.wrong   { border-color: #dc3545; background: #f8d7da; }
</style>

<script>
(function () {
    var CSRF    = <?= json_encode($csrfToken) ?>;
    var API     = '/crm/api/certification.php';
    var examSessionId  = null;
    var pendingCourseId = null;
    var answeredCount  = 0;
    var totalQuestions = 0;
    var lastWasCorrect = false;

    // ── Start exam flow ───────────────────────────────────────────────────────

    window.startExam = function (courseId, courseName) {
        pendingCourseId = courseId;
        document.getElementById('examModalTitle').textContent = 'Start Certification Exam';
        document.getElementById('examModalCourseName').textContent = courseName;
        document.getElementById('examModalAlert').style.display = 'none';
        document.getElementById('examEligibilityInfo').style.display = 'none';
        document.getElementById('examModalBody').style.display = 'block';
        document.getElementById('examModalLoading').style.display = 'none';
        document.getElementById('examConfirmBtn').disabled = false;

        // Check eligibility
        fetch(API + '?action=eligibility_check&course_id=' + courseId, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d.eligible) {
                    var a = document.getElementById('examModalAlert');
                    a.className = 'alert alert-warning';
                    a.textContent = d.reason || 'Not eligible for this exam.';
                    a.style.display = 'block';
                    document.getElementById('examConfirmBtn').disabled = true;
                    if (d.cooldown_ends_at) {
                        a.textContent += ' Cooldown ends: ' + d.cooldown_ends_at;
                    }
                } else {
                    var info = document.getElementById('examEligibilityInfo');
                    info.textContent = 'Attempt ' + d.attempt_number +
                        (d.attempts_remaining < 999 ? ' of ' + (d.attempt_number - 1 + d.attempts_remaining) : '') +
                        '. Good luck!';
                    info.style.display = 'block';
                }
            });

        $('#examModal').modal('show');
    };

    window.confirmStartExam = function () {
        if (!pendingCourseId) return;
        document.getElementById('examModalBody').style.display = 'none';
        document.getElementById('examModalLoading').style.display = 'block';
        document.getElementById('examConfirmBtn').disabled = true;

        fetch(API, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'start_exam', course_id: pendingCourseId, csrf_token: CSRF }),
        })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            $('#examModal').modal('hide');
            if (!d.success) {
                alert('Error: ' + (d.error || 'Cannot start exam'));
                return;
            }
            examSessionId  = d.exam_session_id;
            totalQuestions = d.questions_count;
            answeredCount  = 0;
            document.getElementById('examPlayTitle').textContent = d.course_name + ' — Exam';
            openExamPlay();
        })
        .catch(function () {
            alert('Network error. Please try again.');
            $('#examModal').modal('hide');
        });
    };

    function openExamPlay() {
        document.getElementById('examPlayLoading').style.display = 'block';
        document.getElementById('examPlayQuestion').style.display = 'none';
        document.getElementById('examPlayResult').style.display = 'none';
        document.getElementById('examNextBtn').style.display = 'none';
        document.getElementById('examFinishBtn').style.display = 'none';
        document.getElementById('examDoneBtn').style.display = 'none';
        document.getElementById('examSkipBtn').style.display = 'none';
        $('#examPlayModal').modal('show');
        loadNextQuestion();
    }

    window.loadNextQuestion = function () {
        document.getElementById('examPlayQuestion').style.display = 'none';
        document.getElementById('examPlayLoading').style.display = 'block';
        document.getElementById('examNextBtn').style.display = 'none';
        document.getElementById('examFinishBtn').style.display = 'none';
        document.getElementById('examFeedback') && (document.getElementById('examPlayFeedback').style.display = 'none');

        fetch(API + '?action=exam_questions&exam_session_id=' + examSessionId, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                document.getElementById('examPlayLoading').style.display = 'none';
                if (!d.success) { alert(d.error); return; }

                if (d.done) {
                    finishExam();
                    return;
                }

                answeredCount = d.answered_count;
                renderQuestion(d.question, d.answered_count, d.questions_count);
            })
            .catch(function () { alert('Network error loading question.'); });
    };

    function renderQuestion(q, answered, total) {
        document.getElementById('examPlayProgress').textContent =
            'Question ' + (answered + 1) + ' of ' + total;
        document.getElementById('examPlayQuestionNum').textContent =
            'Q' + (answered + 1);
        document.getElementById('examPlayQuestionText').textContent = q.question_text;
        document.getElementById('examPlayFeedback').style.display = 'none';
        document.getElementById('examSkipBtn').style.display = 'inline-block';

        // Images
        var imgDiv = document.getElementById('examPlayImages');
        imgDiv.innerHTML = '';
        if (q.images && q.images.length) {
            q.images.forEach(function (img) {
                var el = document.createElement('img');
                el.src = img.image_path;
                el.alt = img.caption || '';
                el.style.cssText = 'max-width:100%;border-radius:8px;margin-bottom:8px;';
                imgDiv.appendChild(el);
            });
        }

        // Options
        var opts = document.getElementById('examPlayOptions');
        opts.innerHTML = '';
        q.options.forEach(function (opt) {
            var btn = document.createElement('button');
            btn.className = 'mw-cert-option-btn';
            btn.textContent = opt.option_text;
            btn.dataset.optionId = opt.id;
            btn.dataset.questionId = q.id;
            btn.onclick = function () { submitAnswer(opt.id, q.id); };
            opts.appendChild(btn);
        });

        document.getElementById('examPlayQuestion').style.display = 'block';
    }

    window.submitAnswer = function (optionId, questionId) {
        // Disable all option buttons
        document.querySelectorAll('.mw-cert-option-btn').forEach(function (b) { b.disabled = true; });
        document.getElementById('examSkipBtn').style.display = 'none';

        var start = window._examQuestionStart || Date.now();
        var timeTaken = Math.round((Date.now() - start) / 1000);

        fetch(API, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'submit_answer', csrf_token: CSRF,
                exam_session_id: examSessionId,
                question_id: questionId || parseInt(document.querySelector('.mw-cert-option-btn')?.dataset.questionId || '0'),
                selected_option_id: optionId,
                time_taken_seconds: timeTaken,
            }),
        })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (!d.success) { alert(d.error); return; }
            lastWasCorrect = d.is_correct;
            answeredCount  = d.answered_count;

            // Highlight correct/wrong
            document.querySelectorAll('.mw-cert-option-btn').forEach(function (b) {
                var bid = parseInt(b.dataset.optionId);
                if (bid === d.correct_option_id) b.classList.add('correct');
                else if (optionId && bid === optionId && !d.is_correct) b.classList.add('wrong');
            });

            // Feedback
            var fb = document.getElementById('examPlayFeedback');
            fb.style.display = 'block';
            fb.style.background = d.is_correct ? '#d4edda' : '#f8d7da';
            fb.style.color      = d.is_correct ? '#155724' : '#721c24';
            fb.textContent      = d.is_correct
                ? '✓ Correct! ' + (d.correct_option_text ? '' : '')
                : '✗ Incorrect. The correct answer is: ' + d.correct_option_text;

            // Show next or finish
            if (d.answered_count >= totalQuestions) {
                document.getElementById('examFinishBtn').style.display = 'inline-block';
            } else {
                document.getElementById('examNextBtn').style.display = 'inline-block';
            }
        })
        .catch(function () { alert('Network error submitting answer.'); });
    };

    window.finishExam = function () {
        document.getElementById('examPlayQuestion').style.display = 'none';
        document.getElementById('examPlayLoading').style.display = 'block';
        document.getElementById('examNextBtn').style.display = 'none';
        document.getElementById('examFinishBtn').style.display = 'none';

        fetch(API, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'finish_exam', csrf_token: CSRF, exam_session_id: examSessionId }),
        })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            document.getElementById('examPlayLoading').style.display = 'none';
            if (!d.success) { alert(d.error); return; }
            showExamResult(d);
        })
        .catch(function () { alert('Network error finishing exam.'); });
    };

    function showExamResult(d) {
        document.getElementById('examPlayResult').style.display = 'block';
        document.getElementById('examResultIcon').textContent  = d.passed ? '🎉' : '📚';
        document.getElementById('examResultTitle').textContent = d.passed ? 'Certification Earned!' : 'Not Passed';
        document.getElementById('examResultScore').textContent = d.score_pct + '%';
        document.getElementById('examResultScore').style.color = d.passed ? '#1a7a4a' : '#dc3545';
        document.getElementById('examResultMsg').textContent   = d.passed
            ? 'You scored ' + d.score_pct + '% and passed. Your certification is now active.'
            : 'You scored ' + d.score_pct + '% (required ' + d.pass_score_pct + '%). Review the material and try again after the cooldown period.';

        if (d.newly_unlocked_tier) {
            var tierEl = document.getElementById('examResultTier');
            tierEl.style.display = 'block';
            tierEl.innerHTML = '🏆 <strong>' + esc(d.newly_unlocked_tier.name) + ' Achieved!</strong> New job types are now available to you.';
        }

        document.getElementById('examPlayProgress').textContent = d.correct_count + '/' + d.questions_count + ' correct';
        document.getElementById('examDoneBtn').style.display = 'inline-block';
    }

    // Helper
    function esc(s) {
        var d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML;
    }

    // Track question start time
    document.addEventListener('DOMContentLoaded', function () {
        document.getElementById('examPlayModal').addEventListener('show.bs.modal', function () {
            window._examQuestionStart = Date.now();
        });
        document.getElementById('examPlayQuestion').addEventListener('DOMSubtreeModified', function () {
            window._examQuestionStart = Date.now();
        }, { once: false });
    });
}());
</script>

<?php include 'includes/appstack_footer.php'; ?>
