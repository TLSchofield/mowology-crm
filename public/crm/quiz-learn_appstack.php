<?php
/**
 * /public/crm/quiz-learn_appstack.php
 * Flashcard Study Mode — flip cards to learn before being tested.
 * URL: /crm/quiz-learn_appstack.php?category_id=X
 */
declare(strict_types=1);
require_once __DIR__ . '/../loginAuth/auth.php';
require_once __DIR__ . '/includes/functions.php';
requireLogin();
$user = getCurrentUser();

$categoryId = (int)($_GET['category_id'] ?? 0);
if ($categoryId <= 0) {
    header('Location: /crm/quiz_appstack.php');
    exit;
}

$csrfToken  = function_exists('generateCSRFToken') ? generateCSRFToken() : '';
$pageTitle  = 'Study';
$activePage = 'quiz';
?>
<?php include __DIR__ . '/includes/appstack_head.php'; ?>

<div class="mw-fc-wrap" id="learnWrap">

    <!-- ── Top bar ──────────────────────────────────────────────────────────── -->
    <div class="mw-fc-topbar">
        <a href="/crm/quiz_appstack.php" class="mw-fc-back-link">
            <i data-feather="arrow-left" style="width:16px;height:16px;"></i>
        </a>
        <div class="mw-fc-progress-track">
            <div class="mw-fc-progress-fill" id="fcProgress"></div>
        </div>
        <div class="mw-fc-counter" id="fcCounter">0 / 0</div>
    </div>

    <!-- ── Loading state ────────────────────────────────────────────────────── -->
    <div id="loadingState" class="text-center py-5 text-muted">
        <div class="spinner-border mb-3" role="status"></div>
        <div>Loading cards…</div>
    </div>

    <!-- ── No cards state ───────────────────────────────────────────────────── -->
    <div id="noCardsState" style="display:none;" class="text-center py-5">
        <div style="font-size:3rem;">✅</div>
        <h4 class="mt-3 fw-bold">All caught up!</h4>
        <p class="text-muted">No new cards to study right now.<br>Check back later for review sessions.</p>
        <a href="/crm/quiz_appstack.php" class="btn mw-btn-green mt-2">Back to Quiz Hub</a>
    </div>

    <!-- ── Flashcard ─────────────────────────────────────────────────────────── -->
    <div id="cardArea" style="display:none;">

        <div class="mw-fc-hint" id="fcHint">Tap card to reveal answer</div>

        <div class="mw-fc-scene" onclick="flipCard()">
            <div class="mw-fc-card" id="fcCard">

                <!-- FRONT -->
                <div class="mw-fc-face mw-fc-front">
                    <div class="mw-fc-cat-tag" id="fcCatTag"></div>
                    <div class="mw-fc-img-wrap" id="fcImgFront"></div>
                    <div class="mw-fc-q-text" id="fcQText"></div>
                    <div class="mw-fc-tap-hint">
                        <i data-feather="rotate-cw" style="width:15px;height:15px;"></i> Tap to reveal
                    </div>
                </div>

                <!-- BACK -->
                <div class="mw-fc-face mw-fc-back">
                    <div class="mw-fc-img-wrap" id="fcImgBack"></div>
                    <div class="mw-fc-answer" id="fcAnswer"></div>
                    <div class="mw-fc-notes" id="fcNotes"></div>
                </div>

            </div>
        </div>

        <!-- Action buttons (shown after flip) -->
        <div class="mw-fc-actions" id="fcActions" style="display:none;">
            <button class="mw-fc-still-learning" onclick="markCard('still_learning')">
                <i data-feather="rotate-ccw" style="width:18px;height:18px;"></i>
                Still learning
            </button>
            <button class="mw-fc-got-it" onclick="markCard('got_it')">
                <i data-feather="check" style="width:18px;height:18px;"></i>
                Got it!
            </button>
        </div>

        <!-- Mastery level indicator -->
        <div class="mw-fc-level" id="fcLevel"></div>

    </div>

    <!-- ── Completion screen ─────────────────────────────────────────────────── -->
    <div id="doneState" style="display:none;" class="mw-fc-done">
        <div class="mw-fc-done-emoji">🎓</div>
        <h3 class="fw-bold">Study session complete!</h3>
        <div class="mw-fc-done-stats" id="doneStats"></div>
        <p class="text-muted small mt-2">Now test yourself to earn points and build mastery.</p>
        <div class="d-flex gap-3 justify-content-center flex-wrap mt-4">
            <button class="btn mw-btn-green btn-lg" onclick="startTest()">
                <i data-feather="play" style="width:18px;height:18px;"></i> Test Myself
            </button>
            <a href="/crm/quiz_appstack.php" class="btn btn-outline-secondary btn-lg">
                Back to Hub
            </a>
        </div>
    </div>

</div>

<script>
const CATEGORY_ID = <?php echo $categoryId; ?>;
const CSRF        = <?php echo json_encode($csrfToken); ?>;

let deck        = [];       // all cards
let remaining   = [];       // cards still in rotation
let currentIdx  = 0;
let gotItCount  = 0;
let totalSeen   = 0;
let flipped     = false;
let stackTimer  = null;     // image rotation timer

// ── Image stack helpers (shared with quiz-play) ──────────────────────────────

function buildImageStackHtml(images) {
    if (!images || !images.length) return '';
    if (images.length === 1) {
        return `<img src="${escHtml(images[0].image_path)}" alt="Question image" class="mw-fc-img">`;
    }
    const items = images.map((img, i) =>
        `<div class="mw-img-stack-item${i === 0 ? ' active' : ''}" data-idx="${i}">
            <img src="${escHtml(img.image_path)}" alt="Image ${i+1}" class="mw-img-stack-img mw-fc-img">
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
    const stack = document.querySelector('#fcImgFront .mw-img-stack');
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

// ── Load cards ───────────────────────────────────────────────────────────────
async function loadCards() {
    try {
        const r = await fetch(`/crm/api/quiz.php?action=learn_cards&category_id=${CATEGORY_ID}`);
        const d = await r.json();
        if (!d.success) { showError(d.error); return; }

        document.getElementById('loadingState').style.display = 'none';

        if (!d.cards.length) {
            document.getElementById('noCardsState').style.display = '';
            return;
        }

        deck      = d.cards;
        remaining = [...deck];
        document.getElementById('cardArea').style.display = '';
        showCard(0);
    } catch(e) {
        showError('Connection error. Please refresh.');
    }
}

function showCard(idx) {
    if (idx >= remaining.length) {
        showDone();
        return;
    }

    flipped = false;
    const card = remaining[idx];
    currentIdx = idx;
    totalSeen++;

    updateProgress();

    // Reset flip
    const fcCard = document.getElementById('fcCard');
    fcCard.classList.remove('mw-fc-flipped');
    document.getElementById('fcActions').style.display = 'none';
    document.getElementById('fcHint').style.display = '';

    // Category tag
    document.getElementById('fcCatTag').innerHTML =
        `<span style="color:${escHtml(card.category_colour)}">${escHtml(card.category_name)}</span>`;

    // Images — rotating stack on front, static first image on back
    const images = card.images && card.images.length
        ? card.images
        : (card.image_path ? [{ image_path: card.image_path, caption: '' }] : []);
    stopStackRotation();
    document.getElementById('fcImgFront').innerHTML = buildImageStackHtml(images);
    document.getElementById('fcImgBack').innerHTML  = images.length
        ? `<img src="${escHtml(images[0].image_path)}" alt="" class="mw-fc-img">`
        : '';
    if (images.length > 1) startStackRotation();

    // Question text (front)
    document.getElementById('fcQText').textContent = card.question_text;

    // Answer = the correct option text — fetch it
    fetchCorrectAnswer(card.id).then(answer => {
        document.getElementById('fcAnswer').textContent = answer;
    });

    // Learn notes (back)
    document.getElementById('fcNotes').textContent = card.learn_notes || '';

    // Mastery level badge
    const lvl = card.mastery_level || 0;
    const lvlNames = ['Unseen','Learning','Familiar','Good','Mastered','Expert'];
    document.getElementById('fcLevel').innerHTML =
        `<span class="mw-mastery-badge mw-mastery-badge-${lvl}">${lvlNames[lvl] || ''}</span>`;

    if (typeof feather !== 'undefined') feather.replace();
}

async function fetchCorrectAnswer(questionId) {
    try {
        const r = await fetch(`/crm/api/quiz.php?action=get_question&id=${questionId}`);
        const d = await r.json();
        if (!d.success) return '–';
        const correct = (d.question.options || []).find(o => parseInt(o.is_correct));
        return correct ? correct.option_text : '–';
    } catch(e) { return '–'; }
}

// ── Flip card ────────────────────────────────────────────────────────────────
function flipCard() {
    if (flipped) return;
    flipped = true;
    stopStackRotation();
    document.getElementById('fcCard').classList.add('mw-fc-flipped');
    document.getElementById('fcActions').style.display = 'flex';
    document.getElementById('fcHint').style.display = 'none';
    if (typeof feather !== 'undefined') feather.replace();
}

// ── Mark card ────────────────────────────────────────────────────────────────
async function markCard(result) {
    const card = remaining[currentIdx];

    try {
        await fetch('/crm/api/quiz.php?action=mark_learned', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ question_id: card.id, result, csrf_token: CSRF }),
        });
    } catch(e) { /* silent — proceed anyway */ }

    if (result === 'got_it') {
        gotItCount++;
        // Remove from remaining
        remaining.splice(currentIdx, 1);
        showCard(Math.min(currentIdx, remaining.length - 1));
    } else {
        // "Still learning" → move to end of deck
        remaining.splice(currentIdx, 1);
        remaining.push(card);
        showCard(currentIdx < remaining.length ? currentIdx : 0);
    }

    updateProgress();
}

// ── Progress ──────────────────────────────────────────────────────────────────
function updateProgress() {
    const total   = deck.length;
    const done    = gotItCount;
    const pct     = total > 0 ? Math.round((done / total) * 100) : 0;
    document.getElementById('fcProgress').style.width = pct + '%';
    document.getElementById('fcCounter').textContent  = `${done} / ${total}`;
}

// ── Done ──────────────────────────────────────────────────────────────────────
function showDone() {
    document.getElementById('cardArea').style.display = 'none';
    document.getElementById('doneState').style.display = '';
    document.getElementById('doneStats').innerHTML =
        `<strong>${gotItCount}</strong> of <strong>${deck.length}</strong> cards learned`;
    if (typeof feather !== 'undefined') feather.replace();
}

async function startTest() {
    try {
        const r = await fetch('/crm/api/quiz.php?action=start', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ category_id: CATEGORY_ID, mode: 'test', csrf_token: CSRF }),
        });
        const d = await r.json();
        if (!d.success) { alert(d.error || 'Could not start test'); return; }
        window.location.href = `/crm/quiz-play_appstack.php?session_id=${d.session_id}`;
    } catch(e) {
        alert('Connection error');
    }
}

function showError(msg) {
    document.getElementById('loadingState').innerHTML =
        `<div class="alert alert-danger">${escHtml(msg)}<br><a href="/crm/quiz_appstack.php">Back to Hub</a></div>`;
}

function escHtml(s) {
    if (!s) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Init ──────────────────────────────────────────────────────────────────────
loadCards();
</script>

<?php include __DIR__ . '/includes/appstack_footer.php'; ?>
