<?php
/**
 * App Launch — Mobile-first opening flow
 *
 * Screen 1: Splash (Mowology logo, loads data behind the scenes)
 * Screen 2: Pre-shift Quiz (if enabled & not done today — 3 questions, nothing else)
 * Screen 3: Homebase (jobs/revenue card, weather cards, big clock-in)
 *
 * On clock-in → redirect to schedule (day view)
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
requireLogin();
$user = getCurrentUser();
$csrfToken = generateCSRFToken();
$firstName = $user['first_name'] ?? explode(' ', $user['full_name'] ?? 'Team')[0];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover, user-scalable=no">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="theme-color" content="#0D3B2E">
    <title>Mowology</title>
    <link rel="icon" href="/assets/favicon/favicon.ico">
    <link rel="apple-touch-icon" href="/assets/favicon/apple-touch-icon.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --al-forest: #0D3B2E;
            --al-green: #2D8659;
            --al-dark: #1A5F4A;
            --al-lime: #7FD858;
            --al-light: #E8F3F0;
            --al-orange: #e85d04;
            --al-cream: #F6F4EF;
            --al-text: #1a1a2e;
            --al-muted: #6b7280;
            --al-radius: 18px;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--al-cream);
            color: var(--al-text);
            min-height: 100dvh;
            overflow-x: hidden;
            -webkit-font-smoothing: antialiased;
        }

        /* ── Screens ───────────────────────────────────────── */
        .al-screen {
            position: fixed;
            inset: 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            transition: opacity 0.45s ease, transform 0.45s ease;
            z-index: 10;
        }
        .al-screen.hidden {
            opacity: 0;
            pointer-events: none;
            transform: translateY(24px);
        }
        .al-screen.exit {
            opacity: 0;
            pointer-events: none;
            transform: scale(0.96);
        }

        /* ── Screen 1: Splash ─────────────────────────────── */
        .al-splash {
            background: var(--al-forest);
            z-index: 30;
        }
        .al-splash-logo {
            width: 140px;
            height: 140px;
            border-radius: 32px;
            object-fit: cover;
            animation: al-breathe 2s ease-in-out infinite;
        }
        @keyframes al-breathe {
            0%, 100% { transform: scale(1); opacity: 1; }
            50% { transform: scale(1.04); opacity: 0.9; }
        }
        .al-splash-name {
            margin-top: 20px;
            font-size: 28px;
            font-weight: 700;
            color: #fff;
            letter-spacing: -0.5px;
        }
        .al-splash-dots {
            margin-top: 32px;
            display: flex;
            gap: 8px;
        }
        .al-splash-dots span {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: rgba(255,255,255,0.3);
            animation: al-dot-pulse 1.4s ease-in-out infinite;
        }
        .al-splash-dots span:nth-child(2) { animation-delay: 0.2s; }
        .al-splash-dots span:nth-child(3) { animation-delay: 0.4s; }
        @keyframes al-dot-pulse {
            0%, 80%, 100% { background: rgba(255,255,255,0.3); transform: scale(1); }
            40% { background: rgba(255,255,255,0.8); transform: scale(1.3); }
        }

        /* ── Screen 2: Quiz ───────────────────────────────── */
        .al-quiz {
            background: var(--al-forest);
            padding: 0 24px;
            z-index: 20;
        }
        .al-quiz-progress {
            display: flex;
            gap: 8px;
            justify-content: center;
            margin-bottom: 36px;
        }
        .al-quiz-progress .dot {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: rgba(255,255,255,0.25);
            transition: background 0.3s, transform 0.3s;
        }
        .al-quiz-progress .dot.active {
            background: var(--al-lime);
            transform: scale(1.25);
        }
        .al-quiz-progress .dot.done {
            background: rgba(127,216,88,0.55);
        }

        .al-quiz-card {
            background: #fff;
            border-radius: var(--al-radius);
            padding: 28px 24px 24px;
            width: 100%;
            max-width: 420px;
            box-shadow: 0 8px 40px rgba(0,0,0,0.3);
        }
        .al-quiz-category {
            display: inline-block;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            padding: 4px 10px;
            border-radius: 6px;
            background: var(--al-light);
            color: var(--al-green);
            margin-bottom: 16px;
        }
        .al-quiz-img-wrap {
            margin-bottom: 16px;
            border-radius: 12px;
            overflow: hidden;
        }
        .al-quiz-img {
            width: 100%;
            height: auto;
            max-height: 200px;
            object-fit: cover;
            display: block;
            border-radius: 12px;
        }
        .al-quiz-question {
            font-size: 18px;
            font-weight: 700;
            line-height: 1.45;
            color: var(--al-text);
            margin-bottom: 22px;
        }
        .al-quiz-options {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .al-quiz-option {
            display: block;
            width: 100%;
            padding: 14px 18px;
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            background: #fff;
            font-size: 15px;
            font-weight: 500;
            color: var(--al-text);
            text-align: left;
            cursor: pointer;
            transition: all 0.18s;
        }
        .al-quiz-option:active {
            transform: scale(0.98);
        }
        .al-quiz-option.selected {
            border-color: var(--al-green);
            background: var(--al-light);
        }
        .al-quiz-option.correct {
            border-color: #22c55e;
            background: #f0fdf4;
            color: #15803d;
            font-weight: 600;
        }
        .al-quiz-option.wrong {
            border-color: #ef4444;
            background: #fef2f2;
            color: #991b1b;
        }
        .al-quiz-option:disabled {
            cursor: default;
        }
        .al-quiz-feedback {
            text-align: center;
            margin-top: 16px;
            font-size: 14px;
            font-weight: 700;
            min-height: 20px;
            letter-spacing: 0.2px;
        }

        /* Quiz complete overlay */
        .al-quiz-complete {
            text-align: center;
            padding: 12px 0;
        }
        .al-quiz-complete .score-circle {
            width: 96px;
            height: 96px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--al-green), var(--al-lime));
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 16px;
            color: #fff;
            font-size: 28px;
            font-weight: 800;
        }
        .al-quiz-complete h2 {
            font-size: 22px;
            font-weight: 700;
            color: var(--al-forest);
        }
        .al-quiz-complete p {
            font-size: 14px;
            color: var(--al-muted);
            margin-top: 6px;
        }

        /* ── Screen 3: Homebase ───────────────────────────── */
        .al-home {
            background: var(--al-cream);
            padding: 0 20px;
            padding-top: max(52px, env(safe-area-inset-top, 20px));
            padding-bottom: max(24px, env(safe-area-inset-bottom, 16px));
            justify-content: flex-start;
            overflow-y: auto;
            z-index: 10;
        }

        .al-home-greeting {
            text-align: center;
            margin-bottom: 28px;
        }
        .al-home-greeting h1 {
            font-size: 26px;
            font-weight: 800;
            color: var(--al-forest);
            letter-spacing: -0.5px;
        }
        .al-home-greeting p {
            font-size: 14px;
            color: var(--al-muted);
            margin-top: 4px;
        }

        .al-home-cards {
            width: 100%;
            max-width: 440px;
            display: flex;
            flex-direction: column;
            gap: 14px;
        }

        .al-card {
            background: #fff;
            border-radius: var(--al-radius);
            padding: 22px 22px 20px;
            box-shadow: 0 2px 14px rgba(0,0,0,0.05);
        }

        /* ── Jobs / Revenue card ─────────────────────────── */
        .al-jobs-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 14px;
        }
        .al-jobs-label {
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--al-muted);
        }
        .al-jobs-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: var(--al-light);
            color: var(--al-green);
            font-size: 12px;
            font-weight: 700;
            padding: 3px 9px;
            border-radius: 20px;
        }
        .al-jobs-main {
            display: flex;
            align-items: baseline;
            gap: 6px;
            margin-bottom: 16px;
        }
        .al-jobs-count {
            font-size: 52px;
            font-weight: 800;
            line-height: 1;
            color: var(--al-forest);
            letter-spacing: -2px;
        }
        .al-jobs-unit {
            font-size: 16px;
            font-weight: 600;
            color: var(--al-muted);
        }
        .al-jobs-revenue {
            font-size: 22px;
            font-weight: 700;
            color: var(--al-green);
            margin-bottom: 14px;
        }
        .al-jobs-revenue span {
            font-size: 14px;
            font-weight: 500;
            color: var(--al-muted);
            margin-left: 4px;
        }
        .al-revenue-bar {
            height: 6px;
            border-radius: 3px;
            background: #e5e7eb;
            overflow: hidden;
            margin-bottom: 10px;
        }
        .al-revenue-bar-fill {
            height: 100%;
            border-radius: 3px;
            background: linear-gradient(90deg, var(--al-green), var(--al-lime));
            transition: width 1.2s cubic-bezier(0.25, 1, 0.5, 1);
            width: 0%;
        }
        .al-jobs-meta {
            display: flex;
            gap: 16px;
            font-size: 13px;
            color: var(--al-muted);
        }
        .al-jobs-meta span {
            display: flex;
            align-items: center;
            gap: 4px;
        }

        /* ── Weather card ────────────────────────────────── */
        .al-weather-card-header {
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--al-muted);
            margin-bottom: 14px;
        }
        .al-weather-row {
            display: flex;
            align-items: center;
            gap: 16px;
        }
        .al-weather-icon {
            font-size: 52px;
            line-height: 1;
            flex-shrink: 0;
        }
        .al-weather-info {
            flex: 1;
        }
        .al-weather-condition {
            font-size: 18px;
            font-weight: 700;
            color: var(--al-text);
        }
        .al-weather-temps {
            font-size: 15px;
            font-weight: 600;
            color: var(--al-forest);
            margin-top: 3px;
        }
        .al-weather-detail {
            font-size: 12px;
            color: var(--al-muted);
            margin-top: 3px;
        }

        /* ── Clock-in card ───────────────────────────────── */
        .al-clock-card {
            background: linear-gradient(145deg, var(--al-forest), var(--al-dark));
            border-radius: var(--al-radius);
            padding: 28px 22px 24px;
            box-shadow: 0 8px 32px rgba(13,59,46,0.35);
        }
        .al-clock-card-label {
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: rgba(255,255,255,0.55);
            text-align: center;
            margin-bottom: 20px;
        }
        .al-clock-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            width: 100%;
            padding: 22px;
            border: none;
            border-radius: 14px;
            font-size: 20px;
            font-weight: 800;
            color: var(--al-forest);
            background: linear-gradient(135deg, #a8e063, var(--al-lime));
            cursor: pointer;
            transition: transform 0.15s, box-shadow 0.15s, opacity 0.15s;
            box-shadow: 0 4px 20px rgba(127,216,88,0.4);
            letter-spacing: -0.3px;
        }
        .al-clock-btn:active {
            transform: scale(0.97);
            box-shadow: 0 2px 10px rgba(127,216,88,0.3);
        }
        .al-clock-btn:disabled {
            opacity: 0.7;
        }
        .al-clock-btn .btn-icon {
            font-size: 26px;
        }

        .al-skip-link {
            display: block;
            text-align: center;
            margin-top: 14px;
            font-size: 13px;
            color: rgba(255,255,255,0.45);
            text-decoration: none;
            padding: 4px;
        }
        .al-skip-link:hover {
            color: rgba(255,255,255,0.7);
        }

        /* Spinner */
        .al-spinner {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(13,59,46,0.3);
            border-top-color: var(--al-forest);
            border-radius: 50%;
            animation: al-spin 0.6s linear infinite;
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

<!-- ════════ Screen 2: Quiz ════════ -->
<div class="al-screen al-quiz hidden" id="quizScreen">
    <div class="al-quiz-progress" id="quizProgress"></div>
    <div class="al-quiz-card" id="quizCard">
        <!-- Populated by JS -->
    </div>
</div>

<!-- ════════ Screen 3: Homebase ════════ -->
<div class="al-screen al-home hidden" id="homeScreen">
    <div class="al-home-greeting">
        <h1 id="homeGreeting">Good morning</h1>
        <p id="homeDate"></p>
    </div>
    <div class="al-home-cards">

        <!-- Card 1: Today's Jobs + Revenue -->
        <div class="al-card">
            <div class="al-jobs-header">
                <div class="al-jobs-label">Today's Jobs</div>
                <div class="al-jobs-badge" id="completedBadge" style="display:none;">
                    <svg width="10" height="10" viewBox="0 0 10 10" fill="none"><circle cx="5" cy="5" r="5" fill="#2D8659"/><path d="M3 5l1.5 1.5L7 3.5" stroke="#fff" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    <span id="completedCount">0 done</span>
                </div>
            </div>
            <div class="al-jobs-main">
                <span class="al-jobs-count" id="homeStops">0</span>
                <span class="al-jobs-unit">stops</span>
            </div>
            <div class="al-jobs-revenue">
                <span id="homeRevenue">$0</span>
                <span id="homeTarget">expected today</span>
            </div>
            <div class="al-revenue-bar">
                <div class="al-revenue-bar-fill" id="homeRevenueBar"></div>
            </div>
            <div class="al-jobs-meta">
                <span>🎯 <em id="homeTargetAmt">$1,200</em> target</span>
                <span>⏱ <em id="homeDuration">0 hrs</em></span>
            </div>
        </div>

        <!-- Card 2: Weather -->
        <div class="al-card" id="weatherCard" style="display:none;">
            <div class="al-weather-card-header">Today's Weather</div>
            <div class="al-weather-row">
                <span class="al-weather-icon" id="weatherIcon">🌤</span>
                <div class="al-weather-info">
                    <div class="al-weather-condition" id="weatherCondition"></div>
                    <div class="al-weather-temps" id="weatherTemps"></div>
                    <div class="al-weather-detail" id="weatherDetail"></div>
                </div>
            </div>
        </div>

        <!-- Card 3: Clock In -->
        <div class="al-clock-card">
            <div class="al-clock-card-label">Ready to start your shift?</div>
            <button class="al-clock-btn" id="clockBtn" onclick="handleClockIn()">
                <span class="btn-icon">⏱</span>
                <span id="clockBtnText">Clock In & Start</span>
            </button>
            <a href="/crm/jobs/schedule.php?view=day" class="al-skip-link" id="skipLink">View schedule without clocking in</a>
        </div>

    </div>
</div>

<script>
(function() {
    'use strict';

    const CSRF   = <?= json_encode($csrfToken) ?>;
    const API    = '/crm/api/';
    let homeData = null;
    let quizSession = null;
    let quizQuestionNum = 0;
    let quizTotal = 0;
    let quizCorrect = 0;
    let currentQuestionId = 0;

    // ── Helpers ──────────────────────────────────────────────
    function $(id) { return document.getElementById(id); }

    function show(screenId) {
        document.querySelectorAll('.al-screen').forEach(s => {
            if (s.id === screenId) {
                s.classList.remove('hidden', 'exit');
            } else {
                s.classList.add('exit');
                setTimeout(() => s.classList.add('hidden'), 450);
            }
        });
    }

    async function api(endpoint, opts) {
        const res = await fetch(API + endpoint, opts || {});
        return res.json();
    }

    function formatMoney(n) {
        return '$' + Math.round(n).toLocaleString('en-CA');
    }

    function formatDate(dateStr) {
        const d = new Date(dateStr + 'T12:00:00');
        return d.toLocaleDateString('en-CA', { weekday: 'long', month: 'long', day: 'numeric' });
    }

    function esc(str) {
        const el = document.createElement('span');
        el.textContent = str || '';
        return el.innerHTML;
    }

    // ── 1. Splash: load data ─────────────────────────────────
    async function init() {
        try {
            homeData = await api('app-home.php?action=data');
        } catch (e) {
            window.location.href = '/crm/jobs/schedule.php?view=day';
            return;
        }

        // Minimum 1.5s splash
        await new Promise(r => setTimeout(r, 1500));

        if (!homeData || !homeData.success) {
            window.location.href = '/crm/jobs/schedule.php?view=day';
            return;
        }

        // Already clocked in? Skip straight to schedule
        if (homeData.clock && homeData.clock.clocked_in) {
            window.location.href = '/crm/jobs/schedule.php?view=day';
            return;
        }

        // Quiz needed?
        if (homeData.quiz && homeData.quiz.enabled && !homeData.quiz.done) {
            startQuiz(homeData.quiz.session_length || 3);
        } else {
            showHome();
        }
    }

    // ── 2. Quiz flow — one question at a time, nothing else ──
    async function startQuiz(sessionLength) {
        try {
            const res = await api('quiz.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'start',
                    mode: 'seasonal',
                    session_length: sessionLength,
                    csrf_token: CSRF
                })
            });

            if (!res.success) {
                // No questions available — skip to home
                showHome();
                return;
            }

            quizSession    = res.session_id;
            quizTotal      = res.total;
            quizQuestionNum = 0;
            quizCorrect    = 0;

            // Build progress dots
            const prog = $('quizProgress');
            prog.innerHTML = '';
            for (let i = 0; i < quizTotal; i++) {
                const d = document.createElement('span');
                d.className = 'dot' + (i === 0 ? ' active' : '');
                prog.appendChild(d);
            }

            show('quizScreen');
            loadQuestion(1);
        } catch (e) {
            showHome();
        }
    }

    async function loadQuestion(num) {
        quizQuestionNum = num;

        // Fade card out
        const card = $('quizCard');
        card.style.opacity = '0';
        card.style.transform = 'translateY(8px)';
        card.style.transition = 'opacity 0.2s, transform 0.2s';

        const res = await api('quiz.php?action=question&session_id=' + quizSession + '&q=' + num);

        if (!res.success) { finishQuiz(); return; }

        const q   = res.question;
        const opts = res.options;
        currentQuestionId = q.id;

        // Update progress dots
        document.querySelectorAll('.al-quiz-progress .dot').forEach((d, i) => {
            d.className = 'dot'
                + (i < num - 1 ? ' done' : '')
                + (i === num - 1 ? ' active' : '');
        });

        // Build image html
        const images = (q.images && q.images.length)
            ? q.images
            : (q.image_path ? [{ image_path: q.image_path }] : []);
        const imgHtml = images.length
            ? `<div class="al-quiz-img-wrap"><img src="${esc(images[0].image_path)}" alt="" class="al-quiz-img" loading="eager" decoding="async"></div>`
            : '';

        card.innerHTML = `
            <div class="al-quiz-category">${esc(q.category_name)}</div>
            ${imgHtml}
            <div class="al-quiz-question">${esc(q.text)}</div>
            <div class="al-quiz-options">
                ${opts.map(o => `
                    <button class="al-quiz-option" data-id="${o.id}" onclick="window._answerQuiz(${o.id})">
                        ${esc(o.option_text)}
                    </button>
                `).join('')}
            </div>
            <div class="al-quiz-feedback" id="quizFeedback"></div>
        `;

        // Fade in
        requestAnimationFrame(() => {
            card.style.opacity = '1';
            card.style.transform = 'translateY(0)';
        });
    }

    window._answerQuiz = async function(optionId) {
        // Disable all options immediately
        document.querySelectorAll('.al-quiz-option').forEach(b => b.disabled = true);

        const res = await api('quiz.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action:             'answer',
                session_id:         quizSession,
                question_id:        currentQuestionId,
                selected_option_id: optionId,
                time_taken_seconds: 10,
                csrf_token:         CSRF
            })
        });

        if (res.is_correct) quizCorrect++;

        // Colour correct / wrong
        document.querySelectorAll('.al-quiz-option').forEach(b => {
            const id = parseInt(b.dataset.id);
            if (id === res.correct_option_id)         b.classList.add('correct');
            else if (id === optionId && !res.is_correct) b.classList.add('wrong');
        });

        const fb = $('quizFeedback');
        fb.textContent  = res.is_correct ? '✓ Correct!' : '✗ Not quite';
        fb.style.color  = res.is_correct ? 'var(--al-green)' : '#ef4444';

        // Advance after short pause
        setTimeout(() => {
            if (quizQuestionNum < quizTotal) {
                loadQuestion(quizQuestionNum + 1);
            } else {
                finishQuiz();
            }
        }, 1200);
    };

    async function finishQuiz() {
        // Fire finish + preshift_complete in parallel
        await Promise.all([
            api('quiz.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'finish', session_id: quizSession, csrf_token: CSRF })
            }),
            api('quiz.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action:             'preshift_complete',
                    session_id:         quizSession,
                    questions_asked:    quizTotal,
                    questions_correct:  quizCorrect,
                    csrf_token:         CSRF
                })
            })
        ]);

        // Show completion score briefly
        const pct = quizTotal > 0 ? Math.round((quizCorrect / quizTotal) * 100) : 0;
        $('quizProgress').style.visibility = 'hidden';
        $('quizCard').innerHTML = `
            <div class="al-quiz-complete">
                <div class="score-circle">${pct}%</div>
                <h2>${quizCorrect}/${quizTotal} correct</h2>
                <p>Nice — let's get to work</p>
            </div>
        `;
        $('quizCard').style.opacity = '1';
        $('quizCard').style.transform = 'translateY(0)';

        setTimeout(() => showHome(), 1800);
    }

    // ── 3. Homebase screen ───────────────────────────────────
    function showHome() {
        const today = homeData.today || {};
        const weather = homeData.weather || {};
        const user = homeData.user || {};

        // Greeting
        const name = user.first_name || '<?= htmlspecialchars($firstName) ?>';
        $('homeGreeting').textContent = (user.greeting || 'Good morning') + ', ' + name;
        $('homeDate').textContent = formatDate(today.date || new Date().toISOString().slice(0, 10));

        // Jobs card
        const stops = today.stops || 0;
        const completed = today.completed_stops || 0;
        const revenue = today.revenue || 0;
        const target = today.target || 1200;
        const targetPct = today.target_pct || 0;
        const durationMin = today.duration_min || 0;

        $('homeStops').textContent = stops;
        $('homeRevenue').textContent = formatMoney(revenue);
        $('homeTargetAmt').textContent = formatMoney(target);

        // Duration
        const hrs = durationMin >= 60
            ? (Math.round(durationMin / 6) / 10).toFixed(1) + ' hrs'
            : durationMin + ' min';
        $('homeDuration').textContent = hrs;

        // Completed badge
        if (completed > 0) {
            $('completedBadge').style.display = 'inline-flex';
            $('completedCount').textContent = completed + ' done';
        }

        // Revenue target text
        $('homeTarget').textContent = '— ' + formatMoney(target) + ' expected';

        // Animate bar after paint
        setTimeout(() => {
            $('homeRevenueBar').style.width = Math.min(100, targetPct) + '%';
        }, 300);

        // Weather
        if (weather.condition) {
            $('weatherIcon').textContent = weather.icon || '🌤';
            $('weatherCondition').textContent = weather.condition;
            $('weatherTemps').textContent = weather.high + '°C / ' + weather.low + '°C';
            const details = [];
            if (weather.precipitation) details.push(weather.precipitation + '% precip');
            if (weather.wind) details.push(weather.wind + ' km/h wind');
            $('weatherDetail').textContent = details.join(' · ');
            $('weatherCard').style.display = '';
        }

        show('homeScreen');
    }

    // ── Clock In ─────────────────────────────────────────────
    window.handleClockIn = async function() {
        const btn = $('clockBtn');
        const txt = $('clockBtnText');

        btn.disabled = true;
        txt.innerHTML = '<span class="al-spinner"></span>';

        try {
            // Try to get location (best-effort)
            let lat = null, lng = null;
            try {
                const pos = await new Promise((resolve, reject) => {
                    navigator.geolocation.getCurrentPosition(resolve, reject, {
                        enableHighAccuracy: true, timeout: 5000
                    });
                });
                lat = pos.coords.latitude;
                lng = pos.coords.longitude;
            } catch (e) { /* proceed without GPS */ }

            const res = await api('time-clock.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'clock_in', lat, lng })
            });

            if (res.success || res.entry_id) {
                txt.textContent = 'Clocked in ✓';
                setTimeout(() => {
                    window.location.href = '/crm/jobs/schedule.php?view=day';
                }, 700);
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

    // ── Boot ─────────────────────────────────────────────────
    init();
})();
</script>
</body>
</html>
