<?php
/**
 * App Launch — Mobile-first pre-shift flow
 *
 * Screen 1: Splash  (data loads in background)
 * Screen 2: Learn   (one plant-care flashcard from quiz bank — ALL users)
 * Screen 3: Quiz    (3 multiple-choice questions — if quiz enabled & not done today)
 * Screen 4: Clock In (full-screen, no navbar — GPS ping + tracker on submit)
 *
 * Already clocked in → skip straight to schedule.
 */
declare(strict_types=1);

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
require_once APP_ROOT . '/Modules/Team/Services/TimeclockFunctions.php';
requireLogin();
$user = getCurrentUser();
$csrfToken = generateCSRFToken();
session_write_close();

// Resolve any stale active clock entry from a prior day before the JS checks state.
resolveStaleClockEntry((int)$user['id']);
$firstName = $user['first_name'] ?? explode(' ', $user['full_name'] ?? 'Team')[0];
?>
<!-- app-launch v20260313b -->
<!DOCTYPE html>
<html lang="en">
<head>
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover, user-scalable=no">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="theme-color" content="#0D3B2E">
    <title>Mowology</title>
    <script>
        // Industry-standard client-based routing: only the native Capacitor app
        // runs through the pre-shift flow. Any browser (desktop or mobile) goes
        // to the full CRM. Runs sync before any resources load — no splash flash.
        (function () {
            var isNative = !!(window.Capacitor && window.Capacitor.isNativePlatform && window.Capacitor.isNativePlatform());
            if (!isNative) {
                window.location.replace('/crm/jobs/schedule.php');
            }
        })();
    </script>
    <link rel="manifest" href="/assets/favicon/site.webmanifest">
    <link rel="icon" href="/assets/favicon/favicon.ico">
    <link rel="apple-touch-icon" href="/assets/favicon/apple-touch-icon.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="/crm/css/tokens.css?v=20260410a" rel="stylesheet">
    <link href="/crm/css/mw-sync-status.css?v=20260410a" rel="stylesheet">
    <script src="/crm/js/sw-register.js?v=20260410a" defer></script>
    <script src="/crm/js/mw-sync-status.js?v=20260410a" defer></script>
    <script src="/crm/js/mw-haptics.js?v=20260410a" defer></script>
    <script src="/crm/js/capacitor-bridge.js" defer></script>
    <style>
        :root {
            /* Brand tokens come from /crm/css/tokens.css loaded above.
               Only the page-specific border radius stays local. */
            --al-radius: 18px;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        /* Accessibility — visible focus rings */
        :where(a, button, input, textarea, select, [role="button"], [tabindex]):focus-visible {
            outline: 2px solid var(--al-green);
            outline-offset: 2px;
            border-radius: 4px;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--al-forest);
            color: var(--al-text);
            min-height: 100dvh;
            overflow-x: hidden;
            -webkit-font-smoothing: antialiased;
        }

        /* ── Screens ─────────────────────────────────────────── */
        .al-screen {
            position: fixed;
            inset: 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            transition: opacity 0.4s ease, transform 0.4s ease;
            z-index: 10;
        }
        .al-screen.hidden {
            opacity: 0;
            pointer-events: none;
            transform: translateY(20px);
        }
        .al-screen.exit {
            opacity: 0;
            pointer-events: none;
            transform: scale(0.97);
        }

        /* ── Screen 1: Splash ───────────────────────────────── */
        .al-splash { background: var(--al-forest); z-index: 30; }
        .al-splash-logo {
            width: 140px; height: 140px;
            border-radius: 32px; object-fit: cover;
            animation: al-breathe 2s ease-in-out infinite;
        }
        @keyframes al-breathe {
            0%,100% { transform: scale(1); opacity: 1; }
            50%      { transform: scale(1.04); opacity: 0.9; }
        }
        .al-splash-name {
            margin-top: 20px; font-size: 28px; font-weight: 700;
            color: #fff; letter-spacing: -0.5px;
        }
        .al-splash-dots { margin-top: 32px; display: flex; gap: 8px; }
        .al-splash-dots span {
            width: 8px; height: 8px; border-radius: 50%;
            background: rgba(255,255,255,0.3);
            animation: al-dot-pulse 1.4s ease-in-out infinite;
        }
        .al-splash-dots span:nth-child(2) { animation-delay: 0.2s; }
        .al-splash-dots span:nth-child(3) { animation-delay: 0.4s; }
        @keyframes al-dot-pulse {
            0%,80%,100% { background: rgba(255,255,255,0.3); transform: scale(1); }
            40%          { background: rgba(255,255,255,0.8); transform: scale(1.3); }
        }

        /* ── Screens 2 & 3: Quiz + Learn (shared dark bg) ───── */
        .al-quiz {
            background: var(--al-forest);
            padding: 0 24px;
            padding-top: max(20px, env(safe-area-inset-top, 20px));
            z-index: 20;
        }

        /* Progress dots (quiz only, hidden during learn card) */
        .al-quiz-progress {
            display: flex; gap: 8px; justify-content: center;
            margin-bottom: 28px;
            transition: opacity 0.3s;
        }
        .al-quiz-progress.hidden-progress { opacity: 0; pointer-events: none; }
        .al-quiz-progress .dot {
            width: 12px; height: 12px; border-radius: 50%;
            background: rgba(255,255,255,0.25);
            transition: background 0.3s, transform 0.3s;
        }
        .al-quiz-progress .dot.active  { background: var(--al-lime); transform: scale(1.25); }
        .al-quiz-progress .dot.done    { background: rgba(127,216,88,0.55); }

        /* Learn card step indicator (shown instead of dots) */
        .al-learn-step {
            text-align: center;
            margin-bottom: 28px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: rgba(255,255,255,0.55);
        }
        .al-learn-step span { color: var(--al-lime); }

        /* Shared white card */
        .al-quiz-card {
            background: #fff;
            border-radius: var(--al-radius);
            width: 100%; max-width: 420px;
            box-shadow: 0 8px 40px rgba(0,0,0,0.35);
            overflow: hidden;
            max-height: calc(100dvh - 120px);
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
        }
        .al-quiz-card-inner { padding: 24px 22px 22px; }

        /* Category badge */
        .al-quiz-category {
            display: inline-block;
            font-size: 11px; font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.6px;
            padding: 4px 10px; border-radius: 6px;
            background: var(--al-light); color: var(--al-green);
            margin-bottom: 14px;
        }

        /* Image */
        .al-quiz-img-wrap { margin-bottom: 14px; border-radius: 10px; overflow: hidden; }
        .al-quiz-img {
            width: 100%; height: auto; max-height: 190px;
            object-fit: cover; display: block;
        }

        /* Question text */
        .al-quiz-question {
            font-size: 17px; font-weight: 700;
            line-height: 1.45; color: var(--al-text);
            margin-bottom: 18px;
        }

        /* Multiple choice */
        .al-quiz-options { display: flex; flex-direction: column; gap: 10px; }
        .al-quiz-option {
            display: block; width: 100%;
            padding: 13px 16px;
            border: 2px solid #e5e7eb; border-radius: 12px;
            background: #fff; font-size: 15px; font-weight: 500;
            color: var(--al-text); text-align: left;
            cursor: pointer; transition: all 0.15s;
            font-family: inherit;
        }
        .al-quiz-option:active  { transform: scale(0.98); }
        .al-quiz-option.correct { border-color: var(--mw-color-success); background: var(--mw-color-success-bg); color: #15803d; font-weight: 600; }
        .al-quiz-option.wrong   { border-color: var(--mw-color-danger); background: var(--mw-color-danger-bg); color: #991b1b; }
        .al-quiz-option:disabled { cursor: default; }
        .al-quiz-feedback {
            text-align: center; margin-top: 14px;
            font-size: 14px; font-weight: 700; min-height: 20px;
        }

        /* ── Learn card specifics ────────────────────────────── */
        .al-learn-answer-block {
            background: var(--al-light);
            border-radius: 10px;
            padding: 12px 14px;
            margin-bottom: 10px;
        }
        .al-learn-answer-label {
            font-size: 10px; font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.5px;
            color: var(--al-green); margin-bottom: 5px;
        }
        .al-learn-answer-text {
            font-size: 15px; font-weight: 700;
            color: var(--al-dark); line-height: 1.35;
        }
        .al-learn-notes {
            font-size: 13px; color: var(--al-muted);
            line-height: 1.55; margin-bottom: 18px;
            border-left: 3px solid #e5e7eb;
            padding-left: 12px;
        }
        .al-learn-got-it {
            display: flex; align-items: center; justify-content: center;
            gap: 8px; width: 100%;
            padding: 15px 20px;
            border: none; border-radius: 12px;
            background: var(--al-forest);
            color: #fff;
            font-size: 16px; font-weight: 700;
            cursor: pointer; font-family: inherit;
            transition: opacity 0.15s, transform 0.15s;
        }
        .al-learn-got-it:active { opacity: 0.85; transform: scale(0.98); }

        /* Quiz complete overlay */
        .al-quiz-complete { text-align: center; padding: 16px 0; }
        .al-quiz-complete .score-circle {
            width: 96px; height: 96px; border-radius: 50%;
            background: linear-gradient(135deg, var(--al-green), var(--al-lime));
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 16px;
            color: #fff; font-size: 28px; font-weight: 800;
        }
        .al-quiz-complete h2 { font-size: 22px; font-weight: 700; color: var(--al-forest); }
        .al-quiz-complete p  { font-size: 14px; color: var(--al-muted); margin-top: 6px; }

        /* ── Screen 4: Clock In (full screen, dark) ─────────── */
        .al-clockin {
            background: var(--al-forest);
            padding: 0 28px;
            padding-top:    max(56px, env(safe-area-inset-top, 20px));
            padding-bottom: max(40px, env(safe-area-inset-bottom, 24px));
            justify-content: space-between;
            z-index: 10;
        }
        .al-clockin-top {
            text-align: center;
            flex: 1;
            display: flex; flex-direction: column;
            align-items: center; justify-content: center;
            gap: 8px;
        }
        .al-clockin-emoji { font-size: 52px; line-height: 1; margin-bottom: 4px; }
        .al-clockin-greeting {
            font-size: 28px; font-weight: 800;
            color: #fff; letter-spacing: -0.5px;
        }
        .al-clockin-date {
            font-size: 14px; color: rgba(255,255,255,0.5);
            font-weight: 500;
        }
        .al-clockin-tagline {
            font-size: 13px; color: rgba(255,255,255,0.35);
            margin-top: 4px;
        }
        .al-clockin-btn-wrap { width: 100%; max-width: 400px; }
        .al-clock-btn {
            display: flex; align-items: center; justify-content: center;
            gap: 12px; width: 100%;
            padding: 22px; border: none; border-radius: 16px;
            font-size: 20px; font-weight: 800;
            color: var(--al-forest);
            background: linear-gradient(135deg, #a8e063, var(--al-lime));
            cursor: pointer;
            transition: transform 0.15s, box-shadow 0.15s, opacity 0.15s;
            box-shadow: 0 4px 24px rgba(127,216,88,0.45);
            letter-spacing: -0.3px; font-family: inherit;
        }
        .al-clock-btn:active  { transform: scale(0.97); box-shadow: 0 2px 12px rgba(127,216,88,0.3); }
        .al-clock-btn:disabled { opacity: 0.7; }
        .al-clock-btn .btn-icon { font-size: 26px; }
        .al-skip-link {
            display: block; text-align: center;
            margin-top: 16px; font-size: 13px;
            color: rgba(255,255,255,0.35); text-decoration: none; padding: 6px;
        }
        .al-skip-link:hover { color: rgba(255,255,255,0.6); }

        /* Spinner */
        .al-spinner {
            display: inline-block; width: 20px; height: 20px;
            border: 3px solid rgba(13,59,46,0.3); border-top-color: var(--al-forest);
            border-radius: 50%; animation: al-spin 0.6s linear infinite;
        }
        .al-spinner-white {
            border: 3px solid rgba(255,255,255,0.2); border-top-color: #fff;
        }
        @keyframes al-spin { to { transform: rotate(360deg); } }
    </style>
</head>
<body>

<!-- ════════ Screen 1: Splash ════════ -->
<div class="al-screen al-splash" id="splashScreen">
    <img src="/assets/img/logo/mowology-logo.jpg" alt="Mowology" class="al-splash-logo">
    <div class="al-splash-name">Mowology</div>
    <div class="al-splash-dots">
        <span></span><span></span><span></span>
    </div>
</div>

<!-- ════════ Screen 2 + 3: Quiz / Learn (shared screen) ════════ -->
<div class="al-screen al-quiz hidden" id="quizScreen">
    <div class="al-learn-step hidden-progress" id="learnStep">📚 <span>Today's Learn</span></div>
    <div class="al-quiz-progress" id="quizProgress"></div>
    <div class="al-quiz-card" id="quizCard">
        <div class="al-quiz-card-inner" id="quizCardInner">
            <!-- Populated by JS -->
        </div>
    </div>
</div>

<!-- ════════ Screen 4: Clock In ════════ -->
<div class="al-screen al-clockin hidden" id="clockScreen">
    <div class="al-clockin-top">
        <div class="al-clockin-emoji">⏱</div>
        <div class="al-clockin-greeting" id="clockGreeting">Good morning</div>
        <div class="al-clockin-date" id="clockDate"></div>
        <div class="al-clockin-tagline">Ready to start your shift?</div>
    </div>
    <div class="al-clockin-btn-wrap">
        <button class="al-clock-btn" id="clockBtn" onclick="handleClockIn()">
            <span class="btn-icon">▶</span>
            <span id="clockBtnText">Clock In & Start</span>
        </button>
        <a href="/crm/jobs/schedule.php?view=day" class="al-skip-link">Skip — view schedule</a>
    </div>
</div>

<script>
(function() {
    'use strict';

    const CSRF      = <?= json_encode($csrfToken) ?>;
    const API       = '/crm/api/';
    const IS_DRIVER = <?= !empty($user['is_driver']) ? 'true' : 'false' ?>;
    let homeData        = null;
    let quizSession     = null;
    let quizQuestionNum = 0;
    let quizTotal       = 0;
    let quizCorrect     = 0;
    let currentQuestionId = 0;

    // ── Helpers ──────────────────────────────────────────────
    function $(id) { return document.getElementById(id); }

    function show(screenId) {
        document.querySelectorAll('.al-screen').forEach(s => {
            if (s.id === screenId) {
                s.classList.remove('hidden', 'exit');
            } else {
                s.classList.add('exit');
                setTimeout(() => s.classList.add('hidden'), 420);
            }
        });
    }

    async function api(endpoint, opts) {
        const r = await fetch(API + endpoint, opts || {});
        return r.json();
    }

    function esc(str) {
        const el = document.createElement('span');
        el.textContent = str || '';
        return el.innerHTML;
    }

    function fadeCard(inner) {
        inner.style.transition = 'opacity 0.18s, transform 0.18s';
        inner.style.opacity    = '0';
        inner.style.transform  = 'translateY(6px)';
    }

    function revealCard(inner) {
        requestAnimationFrame(() => requestAnimationFrame(() => {
            inner.style.opacity   = '1';
            inner.style.transform = 'translateY(0)';
        }));
    }

    // ── 1. Splash: load data in parallel ─────────────────────
    async function init() {
        let fetchFailed = false;
        try {
            const [data] = await Promise.all([
                api('app-home.php?action=data&t=' + Date.now()).catch(() => { fetchFailed = true; return null; }),
                new Promise(r => setTimeout(r, 1500))
            ]);
            homeData = data;
        } catch (e) { fetchFailed = true; }

        console.log('[app-launch] homeData:', JSON.stringify(homeData));

        if (fetchFailed || !homeData || !homeData.success) {
            console.error('[app-launch] API failed, fetchFailed=', fetchFailed, 'homeData=', homeData);
            document.getElementById('splashScreen').innerHTML =
                '<div style="padding:40px;color:#fff;font-size:14px;word-break:break-all">'
                + '<b>API ERROR — tap to continue</b><br><br>'
                + 'fetchFailed=' + fetchFailed + '<br>'
                + 'homeData=' + JSON.stringify(homeData)
                + '</div>';
            document.getElementById('splashScreen').onclick = () => {
                window.location.href = '/crm/jobs/schedule.php?view=day';
            };
            return;
        }

        // Already clocked in → go straight to schedule
        if (homeData.clock && homeData.clock.clocked_in) {
            console.log('[app-launch] already clocked in, redirecting to schedule');
            window.location.href = '/crm/jobs/schedule.php?view=day';
            return;
        }

        // Always show learn card first, then quiz (if needed), then clock-in
        const quizEnabled = homeData.quiz && homeData.quiz.enabled && !homeData.quiz.done;
        const sessionLen  = (homeData.quiz && homeData.quiz.session_length) || 3;

        showLearnCard(quizEnabled, sessionLen);
    }

    // ── 2. Learn card — shown to ALL users before quiz/clock-in ──
    function showLearnCard(quizEnabled, sessionLen) {
        const mem = homeData.memory || {};

        // If no memory data, skip learn card
        if (!mem.question || !mem.answer) {
            if (quizEnabled) startQuiz(sessionLen);
            else             showClockScreen();
            return;
        }

        const inner = $('quizCardInner');

        // Hide progress dots, show learn step indicator
        $('learnStep').classList.remove('hidden-progress');
        $('quizProgress').classList.add('hidden-progress');

        const imgHtml = mem.image_path
            ? `<div class="al-quiz-img-wrap">
                   <img src="${esc(mem.image_path)}" alt="" class="al-quiz-img" decoding="async">
               </div>`
            : '';

        const notesHtml = mem.learn_notes
            ? `<p class="al-learn-notes">${esc(mem.learn_notes)}</p>`
            : '';

        const catStyle = mem.category_colour
            ? `background:${esc(mem.category_colour)};color:#fff;`
            : '';

        inner.innerHTML = `
            <div class="al-quiz-category" style="${catStyle}">${esc(mem.category || 'Plant Care')}</div>
            ${imgHtml}
            <div class="al-quiz-question">${esc(mem.question)}</div>
            <div class="al-learn-answer-block">
                <div class="al-learn-answer-label">Answer</div>
                <div class="al-learn-answer-text">${esc(mem.answer)}</div>
            </div>
            ${notesHtml}
            <button class="al-learn-got-it" onclick="window._learnGotIt()">
                Got it &nbsp;→
            </button>
        `;

        window._learnGotIt = function() {
            if (quizEnabled) startQuiz(sessionLen);
            else             showClockScreen();
        };

        show('quizScreen');
        revealCard(inner);
    }

    // ── 3. Quiz flow ─────────────────────────────────────────
    async function startQuiz(sessionLen) {
        try {
            const startRes = await api('quiz.php?action=start', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ mode: 'seasonal', session_length: sessionLen, csrf_token: CSRF })
            });

            if (!startRes.success) { showClockScreen(); return; }

            quizSession     = startRes.session_id;
            quizTotal       = startRes.total;
            quizQuestionNum = 0;
            quizCorrect     = 0;

            // Swap learn indicator for progress dots
            $('learnStep').classList.add('hidden-progress');
            const prog = $('quizProgress');
            prog.classList.remove('hidden-progress');
            prog.innerHTML = '';
            for (let i = 0; i < quizTotal; i++) {
                const d = document.createElement('span');
                d.className = 'dot' + (i === 0 ? ' active' : '');
                prog.appendChild(d);
            }

            // Pre-fetch Q1 so card is never blank
            const q1 = await api('quiz.php?action=question&session_id=' + quizSession + '&q=1');
            // quizScreen is already visible (came from learn card) — just render
            renderQuestion(1, q1);

        } catch (e) { showClockScreen(); }
    }

    async function loadQuestion(num) {
        const inner = $('quizCardInner');
        fadeCard(inner);
        const res = await api('quiz.php?action=question&session_id=' + quizSession + '&q=' + num);
        if (!res.success) { finishQuiz(); return; }
        renderQuestion(num, res);
    }

    function renderQuestion(num, res) {
        quizQuestionNum = num;
        if (!res || !res.success) { finishQuiz(); return; }

        const q    = res.question;
        const opts = res.options;
        currentQuestionId = q.id;

        // Update dots
        document.querySelectorAll('.al-quiz-progress .dot').forEach((d, i) => {
            d.className = 'dot'
                + (i < num - 1 ? ' done'   : '')
                + (i === num - 1 ? ' active' : '');
        });

        const images = (q.images && q.images.length)
            ? q.images : (q.image_path ? [{ image_path: q.image_path }] : []);
        const imgHtml = images.length
            ? `<div class="al-quiz-img-wrap">
                   <img src="${esc(images[0].image_path)}" alt="" class="al-quiz-img" loading="eager" decoding="async">
               </div>`
            : '';

        const cat = (q.category_name || '').toLowerCase();
        let questionText = (q.text || '').trim();
        if (!questionText) {
            if (images.length) {
                questionText = cat.includes('weed')                              ? 'Identify the weed shown:'
                             : (cat.includes('pest') || cat.includes('disease')) ? 'Identify the pest or disease shown:'
                             : 'Identify the plant shown:';
            } else {
                questionText = cat.includes('weed')  ? 'Which season is this weed most problematic?'
                             : cat.includes('turf')  ? 'Which answer best describes this turf condition?'
                             : cat.includes('pest')  ? 'When is this pest most active?'
                             : 'Answer the following:';
            }
        }

        const inner = $('quizCardInner');
        inner.innerHTML = `
            <div class="al-quiz-category">${esc(q.category_name)}</div>
            ${imgHtml}
            <div class="al-quiz-question">${esc(questionText)}</div>
            <div class="al-quiz-options">
                ${opts.map(o => `
                    <button class="al-quiz-option" data-id="${o.id}" onclick="window._answerQuiz(${o.id})">
                        ${esc(o.option_text)}
                    </button>`).join('')}
            </div>
            <div class="al-quiz-feedback" id="quizFeedback"></div>
        `;
        revealCard(inner);
    }

    window._answerQuiz = async function(optionId) {
        document.querySelectorAll('.al-quiz-option').forEach(b => b.disabled = true);

        const res = await api('quiz.php?action=answer', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                session_id: quizSession,
                question_id: currentQuestionId,
                selected_option_id: optionId,
                time_taken_seconds: 10, csrf_token: CSRF
            })
        });

        if (res.is_correct) quizCorrect++;

        document.querySelectorAll('.al-quiz-option').forEach(b => {
            const id = parseInt(b.dataset.id);
            if (id === res.correct_option_id)              b.classList.add('correct');
            else if (id === optionId && !res.is_correct)   b.classList.add('wrong');
        });

        const fb = $('quizFeedback');
        fb.textContent = res.is_correct ? '✓ Correct!' : '✗ Not quite';
        fb.style.color = res.is_correct ? 'var(--al-green)' : '#ef4444';

        setTimeout(() => {
            if (quizQuestionNum < quizTotal) loadQuestion(quizQuestionNum + 1);
            else finishQuiz();
        }, 1200);
    };

    async function finishQuiz() {
        try {
            await Promise.all([
                api('quiz.php?action=finish', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ session_id: quizSession, csrf_token: CSRF })
                }),
                api('quiz.php?action=preshift_complete', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        session_id: quizSession,
                        questions_asked: quizTotal, questions_correct: quizCorrect, csrf_token: CSRF
                    })
                })
            ]);
        } catch (e) { /* proceed regardless — score screen must always show */ }

        const pct = quizTotal > 0 ? Math.round((quizCorrect / quizTotal) * 100) : 0;
        $('quizProgress').style.visibility = 'hidden';
        $('quizCardInner').innerHTML = `
            <div class="al-quiz-complete">
                <div class="score-circle">${pct}%</div>
                <h2>${quizCorrect}/${quizTotal} correct</h2>
                <p>Nice — let's start the day</p>
            </div>
        `;
        $('quizCardInner').style.opacity   = '1';
        $('quizCardInner').style.transform = 'translateY(0)';

        setTimeout(() => showClockScreen(), 1600);
    }

    // ── 4. Clock-in screen ───────────────────────────────────
    function showClockScreen() {
        const u    = homeData.user || {};
        const name = u.first_name || '<?= htmlspecialchars($firstName) ?>';
        $('clockGreeting').textContent = (u.greeting || 'Good morning') + ', ' + name + '!';

        const now = new Date();
        $('clockDate').textContent = now.toLocaleDateString('en-CA', {
            weekday: 'long', month: 'long', day: 'numeric'
        });

        show('clockScreen');
    }

    // ── 5. Clock In + GPS ────────────────────────────────────
    window.handleClockIn = async function() {
        const btn = $('clockBtn');
        const txt = $('clockBtnText');
        btn.disabled = true;
        txt.innerHTML = '<span class="al-spinner al-spinner-white"></span>';

        let lat = null, lng = null;

        // One-shot GPS ping for clock-in record
        try {
            if (window.MwNative && window.MwNative.geo && window.MwNative.geo.getPosition) {
                await new Promise(resolve => {
                    window.MwNative.geo.getPosition(
                        pos => { lat = pos.latitude; lng = pos.longitude; resolve(); },
                        ()  => resolve(),
                        { enableHighAccuracy: true, timeout: 5000 }
                    );
                });
            } else {
                const pos = await new Promise((resolve, reject) =>
                    navigator.geolocation.getCurrentPosition(resolve, reject, {
                        enableHighAccuracy: true, timeout: 5000
                    })
                );
                lat = pos.coords.latitude;
                lng = pos.coords.longitude;
            }
        } catch (e) { /* proceed without GPS */ }

        try {
            const res = await api('time-clock.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
                body: JSON.stringify({ action: 'clock_in', lat, lng })
            });

            if (res.success || res.entry_id) {
                txt.textContent = 'Clocked in ✓';
                // Start continuous GPS tracker after successful clock-in
                startGpsTracker(lat, lng);
                // Start native WorkManager sync service so positions are synced while
                // the driver is on standalone pages (driver-portal, homebase) that don't
                // load the topbar widget.
                if (window.MwNative && window.MwNative.tracking) {
                    window.MwNative.tracking.startSession(
                        <?= (int)$user['id'] ?>,
                        String(res.entry_id || Date.now())
                    );
                }
                // Drivers: pre-trip log form first. Crew: straight to homebase.
                const dest = IS_DRIVER ? '/crm/driver-log.php' : '/crm/homebase.php';
                setTimeout(() => { window.location.href = dest; }, 700);
            } else {
                txt.textContent = 'Clock In & Start';
                btn.disabled = false;
                alert(res.error || 'Clock-in failed. Please try again.');
            }
        } catch (e) {
            txt.textContent = 'Clock In & Start';
            btn.disabled = false;
            alert('Network error. Please try again.');
        }
    };

    /**
     * Start continuous GPS tracking after clock-in.
     * Capacitor: uses native background tracking (keeps running when app is backgrounded).
     * PWA: uses watchPosition, pings /crm/api/crew-location.php every 30s.
     */
    function startGpsTracker(initialLat, initialLng) {
        if (window.MwNative && window.MwNative.geo && window.MwNative.geo.startBackgroundTracking) {
            // Capacitor — hand off to native background GPS
            window.MwNative.geo.startBackgroundTracking(function(pos, err) {
                if (pos && window.MwNative.pow) {
                    // POW system handles location streaming; nothing else needed here
                }
            });
            return;
        }

        // PWA — watchPosition + periodic ping to crew-location
        if (!navigator.geolocation) return;

        let lastPingTime = 0;
        const PING_INTERVAL_MS = 30000; // 30s

        function pingLocation(lat, lng) {
            fetch(API + 'crew-location.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ lat, lng, source: 'preshift_tracker' })
            }).catch(() => {}); // fire-and-forget, non-critical
        }

        // Send initial position immediately
        if (initialLat !== null) pingLocation(initialLat, initialLng);

        navigator.geolocation.watchPosition(
            function(pos) {
                const now = Date.now();
                if (now - lastPingTime >= PING_INTERVAL_MS) {
                    lastPingTime = now;
                    pingLocation(pos.coords.latitude, pos.coords.longitude);
                }
            },
            function() { /* GPS unavailable — non-critical */ },
            { enableHighAccuracy: true, maximumAge: 15000, timeout: 10000 }
        );
    }

    // ── Boot ─────────────────────────────────────────────────
    init();
})();
</script>
</body>
</html>
