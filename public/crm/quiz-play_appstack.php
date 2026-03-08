<?php
/**
 * /public/crm/quiz-play_appstack.php
 * Knowledge Quiz — live game interface.
 * URL: /crm/quiz-play_appstack.php?session_id=X
 */
declare(strict_types=1);
require_once __DIR__ . '/../loginAuth/auth.php';
require_once __DIR__ . '/includes/functions.php';
requireLogin();
$user = getCurrentUser();

$sessionId = (int)($_GET['session_id'] ?? 0);
if ($sessionId <= 0) {
    header('Location: /crm/quiz_appstack.php');
    exit;
}

$csrfToken  = function_exists('generateCSRFToken') ? generateCSRFToken() : '';
$pageTitle  = 'Quiz';
$activePage = 'quiz';
?>
<?php include __DIR__ . '/includes/appstack_head.php'; ?>

<div class="mw-quiz-play-wrap" id="quizWrap">

    <!-- ── Progress & Timer Bar ────────────────────────────────────────────── -->
    <div class="mw-quiz-topbar">
        <div class="mw-quiz-progress-track">
            <div class="mw-quiz-progress-fill" id="progressFill"></div>
        </div>
        <div class="mw-quiz-topbar-inner">
            <div class="mw-quiz-qnum" id="qNum">Q 1 / ?</div>
            <div class="mw-quiz-timer-ring" id="timerRing">
                <svg viewBox="0 0 36 36" class="mw-quiz-timer-svg">
                    <circle class="mw-quiz-timer-bg"   cx="18" cy="18" r="15.9"/>
                    <circle class="mw-quiz-timer-track" cx="18" cy="18" r="15.9" id="timerArc"/>
                </svg>
                <span class="mw-quiz-timer-num" id="timerNum">30</span>
            </div>
            <div class="mw-quiz-score-live">
                <span id="livePts">0</span> <span class="mw-quiz-score-label">pts</span>
            </div>
        </div>
    </div>

    <!-- ── Question Area ────────────────────────────────────────────────────── -->
    <div class="mw-quiz-question-area" id="questionArea">
        <div class="text-center py-5 text-muted">
            <div class="spinner-border mb-3" role="status"></div>
            <div>Loading question…</div>
        </div>
    </div>

    <!-- ── Options Area ─────────────────────────────────────────────────────── -->
    <div class="mw-quiz-options-area" id="optionsArea"></div>

    <!-- ── Results Screen (hidden until quiz complete) ───────────────────────── -->
    <div class="mw-quiz-results" id="resultsScreen" style="display:none;">
        <div class="mw-quiz-results-inner">
            <div class="mw-quiz-results-emoji" id="resultsEmoji">🎉</div>
            <h2 class="mw-quiz-results-title" id="resultsTitle">Quiz Complete!</h2>
            <div class="mw-quiz-results-score" id="resultsScore">—</div>
            <div class="mw-quiz-results-sub" id="resultsSub">—</div>
            <div class="mw-quiz-results-rank" id="resultsRank"></div>
            <div class="d-flex gap-3 justify-content-center flex-wrap mt-4">
                <a href="/crm/quiz_appstack.php" class="btn mw-btn-green btn-lg">
                    <i data-feather="home" style="width:18px;height:18px;"></i> Quiz Hub
                </a>
                <button class="btn btn-outline-secondary btn-lg" onclick="location.reload()">
                    <i data-feather="refresh-cw" style="width:18px;height:18px;"></i> Play Again
                </button>
            </div>
        </div>
    </div>

</div>

<script>
const SESSION_ID = <?php echo $sessionId; ?>;
const CSRF       = <?php echo json_encode($csrfToken); ?>;

let currentQ     = 1;
let totalQ       = 0;
let sessionPts   = 0;
let timerSecs    = 30;
let timerHandle  = null;
let stackTimer   = null; // image rotation timer
let answered     = false;
let startTime    = null;
const TIMER_MAX  = 30;
const ARC_LEN    = 100; // stroke-dasharray reference

// ── Fetch and render a question ─────────────────────────────────────────────
async function loadQuestion(qNum) {
    answered  = false;
    startTime = Date.now();

    updateProgress(qNum, totalQ);
    updateQNum(qNum, totalQ);
    clearTimer();

    document.getElementById('questionArea').innerHTML =
        '<div class="text-center py-4 text-muted"><div class="spinner-border spinner-border-sm"></div></div>';
    document.getElementById('optionsArea').innerHTML = '';

    try {
        const r = await fetch(`/crm/api/quiz.php?action=question&session_id=${SESSION_ID}&q=${qNum}`);
        const d = await r.json();
        if (!d.success) {
            showError(d.error || 'Failed to load question');
            return;
        }

        totalQ = d.total;
        updateProgress(qNum, totalQ);
        updateQNum(qNum, totalQ);

        renderQuestion(d.question);
        renderOptions(d.options, d.question.id);

        if (!d.already_answered) startTimer(d.question.id);

    } catch(e) {
        showError('Connection error. Please refresh.');
    }
}

// ── Image stack helpers ───────────────────────────────────────────────────────

function buildImageStackHtml(images) {
    if (!images || !images.length) return '';
    if (images.length === 1) {
        return `<div class="mw-quiz-img-wrap">
            <img src="${escHtml(images[0].image_path)}" alt="Question image" class="mw-quiz-img">
        </div>`;
    }
    const items = images.map((img, i) =>
        `<div class="mw-img-stack-item${i === 0 ? ' active' : ''}" data-idx="${i}">
            <img src="${escHtml(img.image_path)}" alt="Image ${i+1}" class="mw-img-stack-img">
        </div>`
    ).join('');
    const dots = images.map((_, i) =>
        `<button class="mw-img-stack-dot${i === 0 ? ' active' : ''}" data-idx="${i}" onclick="stackGoTo(this.closest('.mw-img-stack'),${i});event.stopPropagation()"></button>`
    ).join('');
    const shadows = images.length >= 3
        ? '<div class="mw-img-stack-shadow mw-img-stack-shadow-2"></div><div class="mw-img-stack-shadow mw-img-stack-shadow-1"></div>'
        : images.length === 2
        ? '<div class="mw-img-stack-shadow mw-img-stack-shadow-1"></div>'
        : '';
    return `<div class="mw-img-stack" data-count="${images.length}" data-current="0">
        ${shadows}
        <div class="mw-img-stack-frame">${items}</div>
        <div class="mw-img-stack-footer">
            <div class="mw-img-stack-dots">${dots}</div>
            <div class="mw-img-stack-counter">1 / ${images.length}</div>
        </div>
    </div>`;
}

function stackGoTo(stack, idx) {
    if (!stack) return;
    const count   = parseInt(stack.dataset.count);
    const items   = stack.querySelectorAll('.mw-img-stack-item');
    const dots    = stack.querySelectorAll('.mw-img-stack-dot');
    const counter = stack.querySelector('.mw-img-stack-counter');
    items.forEach((el, i) => el.classList.toggle('active', i === idx));
    dots.forEach((el, i)  => el.classList.toggle('active', i === idx));
    if (counter) counter.textContent = `${idx + 1} / ${count}`;
    stack.dataset.current = idx;
}

function startStackRotation() {
    stopStackRotation();
    const stack = document.querySelector('.mw-img-stack');
    if (!stack || parseInt(stack.dataset.count) <= 1) return;
    stackTimer = setInterval(() => {
        const count = parseInt(stack.dataset.count);
        const next  = (parseInt(stack.dataset.current) + 1) % count;
        stackGoTo(stack, next);
    }, 2500);
}

function stopStackRotation() {
    if (stackTimer) { clearInterval(stackTimer); stackTimer = null; }
}

function renderQuestion(q) {
    // Build images list — use q.images if available, fall back to single image_path
    const images = q.images && q.images.length
        ? q.images
        : (q.image_path ? [{ image_path: q.image_path, caption: '' }] : []);

    const imgHtml = buildImageStackHtml(images);

    const diffBadge = q.difficulty === 'easy'   ? '<span class="badge mw-quiz-badge-easy">Easy</span>' :
                      q.difficulty === 'hard'   ? '<span class="badge mw-quiz-badge-hard">Hard</span>' :
                                                  '<span class="badge mw-quiz-badge-medium">Medium</span>';

    document.getElementById('questionArea').innerHTML = `
        <div class="mw-quiz-cat-tag" style="color:${escHtml(q.category_colour)}">${escHtml(q.category_name)}</div>
        ${imgHtml}
        <div class="mw-quiz-q-text">${escHtml(q.text)}</div>
        <div class="mw-quiz-q-meta">${diffBadge}</div>
    `;

    if (images.length > 1) startStackRotation();
}

function renderOptions(options, questionId) {
    const wrap = document.getElementById('optionsArea');
    wrap.innerHTML = options.map(opt =>
        `<button class="mw-quiz-option-btn" data-opt="${opt.id}" onclick="submitAnswer(${opt.id}, ${questionId})">
            ${escHtml(opt.option_text)}
        </button>`
    ).join('');
}

// ── Submit answer ───────────────────────────────────────────────────────────
async function submitAnswer(selectedOptionId, questionId) {
    if (answered) return;
    answered = true;
    clearTimer();

    const timeTaken = Math.min(TIMER_MAX, Math.round((Date.now() - startTime) / 1000));

    // Disable all buttons
    document.querySelectorAll('.mw-quiz-option-btn').forEach(b => b.disabled = true);

    try {
        const r = await fetch('/crm/api/quiz.php?action=answer', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                session_id:         SESSION_ID,
                question_id:        questionId,
                selected_option_id: selectedOptionId,
                time_taken_seconds: timeTaken,
                csrf_token:         CSRF,
            }),
        });
        const d = await r.json();
        if (!d.success) { showError(d.error); return; }

        // Highlight correct / wrong
        document.querySelectorAll('.mw-quiz-option-btn').forEach(btn => {
            const optId = parseInt(btn.dataset.opt);
            if (optId === d.correct_option_id) {
                btn.classList.add('mw-quiz-opt-correct');
            } else if (optId === parseInt(selectedOptionId) && !d.is_correct) {
                btn.classList.add('mw-quiz-opt-wrong');
            }
        });

        // Show points
        if (d.points_earned > 0) {
            sessionPts += d.points_earned;
            document.getElementById('livePts').textContent = sessionPts;
            showPointsBubble(d.points_earned);
        }

        // Show mastery delta
        if (d.mastery) showMasteryDelta(d.mastery);

        // Advance after 1.8s
        setTimeout(() => {
            stopStackRotation();
            if (currentQ < totalQ) {
                currentQ++;
                loadQuestion(currentQ);
            } else {
                finishQuiz();
            }
        }, 1500);

    } catch(e) {
        showError('Connection error');
    }
}

// ── Timer ───────────────────────────────────────────────────────────────────
function startTimer(questionId) {
    timerSecs = TIMER_MAX;
    updateTimerDisplay(timerSecs);

    timerHandle = setInterval(() => {
        timerSecs--;
        updateTimerDisplay(timerSecs);
        if (timerSecs <= 0) {
            clearTimer();
            submitAnswer(null, questionId); // time expired — no answer
        }
    }, 1000);
}

function clearTimer() {
    if (timerHandle) { clearInterval(timerHandle); timerHandle = null; }
}

function updateTimerDisplay(secs) {
    const arc  = document.getElementById('timerArc');
    const num  = document.getElementById('timerNum');
    const pct  = secs / TIMER_MAX;
    const dash = (pct * ARC_LEN).toFixed(1);

    if (arc) {
        arc.style.strokeDasharray = `${dash} ${ARC_LEN}`;
        arc.style.stroke = secs <= 5  ? 'var(--mw-orange)'
                         : secs <= 10 ? '#f0ad4e'
                         : 'var(--mw-lime)';
    }
    if (num) {
        num.textContent = secs;
        num.style.color = secs <= 5 ? 'var(--mw-orange)' : '';
    }
}

// ── Finish quiz ─────────────────────────────────────────────────────────────
async function finishQuiz() {
    document.getElementById('questionArea').innerHTML = '';
    document.getElementById('optionsArea').innerHTML  = '';

    try {
        const r = await fetch('/crm/api/quiz.php?action=finish', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ session_id: SESSION_ID, csrf_token: CSRF }),
        });
        const d = await r.json();
        if (!d.success) { showError(d.error); return; }

        const pct   = d.total > 0 ? Math.round((d.correct / d.total) * 100) : 0;
        const emoji = pct >= 90 ? '🏆' : pct >= 70 ? '🎉' : pct >= 50 ? '👍' : '💪';
        const msg   = pct >= 90 ? 'Outstanding!' : pct >= 70 ? 'Great job!' : pct >= 50 ? 'Good effort!' : 'Keep practicing!';

        document.getElementById('resultsEmoji').textContent  = emoji;
        document.getElementById('resultsTitle').textContent  = msg;
        document.getElementById('resultsScore').textContent  = `${d.correct} / ${d.total} correct`;
        document.getElementById('resultsSub').textContent    = `${d.points} points earned`;
        document.getElementById('resultsRank').innerHTML     = d.monthly_rank
            ? `Your monthly rank: <strong>#${d.monthly_rank}</strong> (${d.monthly_total} pts total)`
            : '';

        document.getElementById('quizWrap').classList.add('mw-quiz-done');
        document.getElementById('resultsScreen').style.display = '';

        if (typeof feather !== 'undefined') feather.replace();

    } catch(e) {
        showError('Failed to record results. Please refresh.');
    }
}

// ── UI helpers ───────────────────────────────────────────────────────────────
function updateProgress(q, total) {
    if (!total) return;
    document.getElementById('progressFill').style.width = ((q / total) * 100) + '%';
}
function updateQNum(q, total) {
    document.getElementById('qNum').textContent = total ? `Q ${q} / ${total}` : `Q ${q}`;
}
function showError(msg) {
    document.getElementById('questionArea').innerHTML =
        `<div class="alert alert-danger m-3">${escHtml(msg)}<br><a href="/crm/quiz_appstack.php">Return to Quiz Hub</a></div>`;
}
function showPointsBubble(pts) {
    const el = document.createElement('div');
    el.className = 'mw-quiz-pts-bubble';
    el.textContent = '+' + pts;
    document.body.appendChild(el);
    requestAnimationFrame(() => el.classList.add('mw-quiz-pts-bubble-pop'));
    setTimeout(() => el.remove(), 1200);
}
function showMasteryDelta(m) {
    // Remove any existing indicator
    document.querySelector('.mw-mastery-delta')?.remove();
    if (m.delta === 0) return;
    const el   = document.createElement('div');
    const up   = m.delta > 0;
    el.className = 'mw-mastery-delta ' + (up ? 'mw-mastery-delta-up' : 'mw-mastery-delta-dn');
    el.innerHTML = `${up ? '▲' : '▼'} ${up ? '+' : ''}${m.delta} → ${escHtml(m.level_name)} <span class="mw-mastery-badge mw-mastery-badge-${m.new_level}">Lv ${m.new_level}</span>`;
    document.getElementById('optionsArea').after(el);
    setTimeout(() => el.remove(), 1700);
}
function escHtml(s) {
    if (!s) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Init ────────────────────────────────────────────────────────────────────
updateTimerDisplay(TIMER_MAX);
loadQuestion(1);
</script>

<?php include __DIR__ . '/includes/appstack_footer.php'; ?>
