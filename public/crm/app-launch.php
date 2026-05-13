<?php
/**
 * App Launch — Mobile-first opening flow
 *
 * Screen 1: Splash (Mowology logo, loads data behind the scenes)
 * Screen 2: Pre-shift Quiz (if enabled & not done today)
 * Screen 3: Home (revenue target, weather, clock-in)
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

// Drivers start at the portal where the vehicle check is surfaced
if (!empty($user['is_driver']) && $user['role'] !== 'admin') {
    header('Location: /crm/driver-portal.php');
    exit;
}

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
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet">
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
            --al-radius: 16px;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Outfit', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--al-cream);
            color: var(--al-text);
            min-height: 100dvh;
            overflow-x: hidden;
            -webkit-font-smoothing: antialiased;
        }

        /* ── Screens ─────────────────────────────────────── */
        .al-screen {
            position: fixed;
            inset: 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            transition: opacity 0.5s ease, transform 0.5s ease;
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
            transform: scale(0.95);
        }

        /* ── Screen 1: Splash ────────────────────────────── */
        .al-splash {
            background: radial-gradient(ellipse 70% 65% at 50% 44%, #1f6347 0%, #0a2e22 100%);
            z-index: 30;
            overflow: hidden;
        }
        /* Subtle noise texture for depth */
        .al-splash::before {
            content: '';
            position: absolute;
            inset: 0;
            background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.85' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)'/%3E%3C/svg%3E");
            opacity: 0.04;
            pointer-events: none;
        }
        /* Subtle lime glow radiating from below logo */
        .al-splash::after {
            content: '';
            position: absolute;
            top: 38%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 320px;
            height: 320px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(127,216,88,0.08) 0%, transparent 70%);
            pointer-events: none;
        }
        .al-splash-logo {
            width: 140px;
            height: 140px;
            border-radius: 32px;
            object-fit: cover;
            position: relative;
            z-index: 1;
            animation: al-breathe 2.4s ease-in-out infinite, al-logo-glow 3s ease-in-out infinite;
            box-shadow: 0 20px 60px rgba(0,0,0,0.45);
        }
        @keyframes al-breathe {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }
        @keyframes al-logo-glow {
            0%, 100% { box-shadow: 0 20px 60px rgba(0,0,0,0.45), 0 0 0 0 rgba(127,216,88,0); }
            50% { box-shadow: 0 24px 70px rgba(0,0,0,0.40), 0 0 0 14px rgba(127,216,88,0.1); }
        }
        .al-splash-name {
            margin-top: 22px;
            font-size: 30px;
            font-weight: 700;
            color: #fff;
            letter-spacing: -0.5px;
            font-family: 'Outfit', sans-serif;
            position: relative;
            z-index: 1;
        }
        .al-splash-tagline {
            margin-top: 5px;
            font-size: 11px;
            font-weight: 500;
            color: rgba(255,255,255,0.38);
            letter-spacing: 2.5px;
            text-transform: uppercase;
            font-family: 'Outfit', sans-serif;
            position: relative;
            z-index: 1;
        }
        /* Waveform loading bars instead of dots */
        .al-splash-dots {
            margin-top: 36px;
            display: flex;
            align-items: center;
            gap: 5px;
            position: relative;
            z-index: 1;
        }
        .al-splash-dots span {
            width: 3px;
            height: 16px;
            border-radius: 2px;
            background: rgba(255,255,255,0.35);
            animation: al-bar-wave 1.2s ease-in-out infinite;
            transform-origin: center bottom;
        }
        .al-splash-dots span:nth-child(1) { animation-delay: -0.4s; height: 12px; }
        .al-splash-dots span:nth-child(2) { animation-delay: -0.2s; height: 20px; }
        .al-splash-dots span:nth-child(3) { animation-delay:  0.0s; height: 16px; }
        @keyframes al-bar-wave {
            0%, 100% { transform: scaleY(0.5); background: rgba(255,255,255,0.25); }
            50%       { transform: scaleY(1.4); background: rgba(127,216,88,0.8); }
        }

        /* ── Screen 2: Quiz ──────────────────────────────── */
        .al-quiz {
            background: var(--al-cream);
            padding: max(env(safe-area-inset-top), 32px) 24px 32px;
            z-index: 20;
            justify-content: flex-start;
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
        }
        .al-quiz-header {
            text-align: center;
            margin-bottom: 24px;
        }
        .al-quiz-header h1 {
            font-size: 26px;
            font-weight: 700;
            color: var(--al-forest);
            letter-spacing: -0.5px;
        }
        .al-quiz-header p {
            margin-top: 8px;
            font-size: 15px;
            color: var(--al-muted);
        }
        .al-quiz-progress {
            display: flex;
            gap: 6px;
            justify-content: center;
            margin-bottom: 20px;
        }
        .al-quiz-progress .dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: #d1d5db;
            transition: background 0.3s, transform 0.3s;
        }
        .al-quiz-progress .dot.active {
            background: var(--al-green);
            transform: scale(1.2);
        }
        .al-quiz-progress .dot.done {
            background: var(--al-lime);
        }

        .al-quiz-card {
            background: #fff;
            border-radius: var(--al-radius);
            padding: 28px 24px;
            width: 100%;
            max-width: 420px;
            box-shadow: 0 4px 24px rgba(0,0,0,0.06);
        }
        .al-quiz-category {
            display: inline-block;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
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
            font-weight: 600;
            line-height: 1.5;
            color: var(--al-text);
            margin-bottom: 24px;
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
            transition: all 0.2s;
        }
        .al-quiz-option:active {
            transform: scale(0.98);
        }
        .al-quiz-option.selected {
            border-color: var(--al-green);
            background: var(--al-light);
        }
        .al-quiz-option.correct {
            border-color: var(--al-lime);
            background: #ecfdf5;
            color: #065f46;
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
            margin-top: 20px;
            font-size: 14px;
            font-weight: 600;
            min-height: 20px;
        }

        /* ── Screen 3: Home ──────────────────────────────── */
        .al-home {
            background: var(--al-cream);
            padding: 0 20px;
            padding-top: env(safe-area-inset-top, 20px);
            justify-content: flex-start;
            padding-top: max(60px, env(safe-area-inset-top, 20px));
            z-index: 10;
        }
        .al-home-greeting {
            text-align: center;
            margin-bottom: 36px;
        }
        .al-home-greeting h1 {
            font-size: 32px;
            font-weight: 800;
            color: var(--al-forest);
            letter-spacing: -0.8px;
            font-family: 'Outfit', sans-serif;
        }
        .al-home-greeting p {
            font-size: 15px;
            color: var(--al-muted);
            margin-top: 6px;
            font-style: italic;
            font-weight: 400;
        }

        .al-home-cards {
            width: 100%;
            max-width: 420px;
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .al-card {
            background: #fff;
            border-radius: var(--al-radius);
            padding: 24px;
            box-shadow: 0 2px 16px rgba(0,0,0,0.05);
        }

        /* Revenue card */
        .al-revenue-label {
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--al-muted);
            margin-bottom: 8px;
        }
        .al-revenue-row {
            display: flex;
            align-items: baseline;
            gap: 8px;
        }
        .al-revenue-amount {
            font-size: 36px;
            font-weight: 700;
            color: var(--al-forest);
            letter-spacing: -1px;
        }
        .al-revenue-target {
            font-size: 14px;
            color: var(--al-muted);
        }
        .al-revenue-bar {
            margin-top: 14px;
            height: 8px;
            border-radius: 4px;
            background: #e5e7eb;
            overflow: hidden;
        }
        .al-revenue-bar-fill {
            height: 100%;
            border-radius: 4px;
            background: linear-gradient(90deg, var(--al-green), var(--al-lime));
            transition: width 1s ease;
            width: 0%;
            box-shadow: 2px 0 8px rgba(127,216,88,0.5);
        }
        .al-revenue-meta {
            display: flex;
            justify-content: space-between;
            margin-top: 10px;
            font-size: 13px;
            color: var(--al-muted);
        }

        /* Weather card */
        .al-weather-row {
            display: flex;
            align-items: center;
            gap: 16px;
        }
        .al-weather-icon {
            font-size: 48px;
            line-height: 1;
        }
        .al-weather-info {
            flex: 1;
        }
        .al-weather-condition {
            font-size: 17px;
            font-weight: 600;
            color: var(--al-text);
        }
        .al-weather-temps {
            font-size: 14px;
            color: var(--al-muted);
            margin-top: 2px;
        }
        .al-weather-detail {
            font-size: 13px;
            color: var(--al-muted);
            margin-top: 2px;
        }

        /* Clock-in button */
        .al-clock-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            width: 100%;
            padding: 20px;
            border: none;
            border-radius: var(--al-radius);
            font-size: 18px;
            font-weight: 700;
            letter-spacing: -0.2px;
            color: #fff;
            cursor: pointer;
            transition: transform 0.15s, box-shadow 0.15s;
            margin-top: 8px;
            font-family: 'Outfit', sans-serif;
        }
        .al-clock-btn:active {
            transform: scale(0.97);
        }
        .al-clock-btn.clock-in {
            background: linear-gradient(135deg, var(--al-green) 0%, var(--al-dark) 100%);
            box-shadow: 0 8px 28px rgba(45,134,89,0.45), 0 2px 6px rgba(45,134,89,0.2);
        }
        .al-clock-btn.clock-in:active {
            box-shadow: 0 2px 12px rgba(45,134,89,0.25);
        }
        .al-clock-btn.go-to-schedule {
            background: linear-gradient(135deg, var(--al-forest), var(--al-dark));
            box-shadow: 0 6px 24px rgba(13,59,46,0.35);
        }
        .al-clock-btn .btn-icon {
            font-size: 22px;
        }

        .al-skip-link {
            display: block;
            text-align: center;
            margin-top: 16px;
            font-size: 14px;
            color: var(--al-muted);
            text-decoration: none;
        }
        .al-skip-link:hover {
            color: var(--al-green);
        }

        /* Loading spinner for clock-in */
        .al-spinner {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255,255,255,0.3);
            border-top-color: #fff;
            border-radius: 50%;
            animation: al-spin 0.6s linear infinite;
        }
        @keyframes al-spin {
            to { transform: rotate(360deg); }
        }

        /* Transition: quiz complete celebration */
        .al-quiz-complete {
            text-align: center;
        }
        .al-quiz-complete .score-circle {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--al-green), var(--al-lime));
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 16px;
            color: #fff;
            font-size: 32px;
            font-weight: 700;
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
    </style>
</head>
<body>

<!-- ════════ Screen 1: Splash ════════ -->
<div class="al-screen al-splash" id="splashScreen">
    <img src="/assets/img/logo/mowology-logo.jpg" alt="Mowology" class="al-splash-logo">
    <div class="al-splash-name">Mowology</div>
    <div class="al-splash-tagline">Field Operations</div>
    <div class="al-splash-dots">
        <span></span><span></span><span></span>
    </div>
</div>

<!-- ════════ Screen 2: Quiz ════════ -->
<div class="al-screen al-quiz hidden" id="quizScreen">
    <div class="al-quiz-header">
        <h1>Let's get started</h1>
        <p>Quick knowledge check before your shift</p>
    </div>
    <div class="al-quiz-progress" id="quizProgress"></div>
    <div class="al-quiz-card" id="quizCard">
        <!-- Populated by JS -->
    </div>
</div>

<!-- ════════ Screen 3: Home ════════ -->
<div class="al-screen al-home hidden" id="homeScreen">
    <div class="al-home-greeting">
        <h1 id="homeGreeting">Good morning</h1>
        <p id="homeDate"></p>
    </div>
    <div class="al-home-cards">
        <!-- Revenue -->
        <div class="al-card">
            <div class="al-revenue-label">Today's Target</div>
            <div class="al-revenue-row">
                <span class="al-revenue-amount" id="homeRevenue">$0</span>
                <span class="al-revenue-target" id="homeTarget">/ $1,200</span>
            </div>
            <div class="al-revenue-bar">
                <div class="al-revenue-bar-fill" id="homeRevenueBar"></div>
            </div>
            <div class="al-revenue-meta">
                <span id="homeStops">0 stops</span>
                <span id="homeDuration">0 hrs</span>
            </div>
        </div>

        <!-- Weather -->
        <div class="al-card" id="weatherCard" style="display:none;">
            <div class="al-weather-row">
                <span class="al-weather-icon" id="weatherIcon"></span>
                <div class="al-weather-info">
                    <div class="al-weather-condition" id="weatherCondition"></div>
                    <div class="al-weather-temps" id="weatherTemps"></div>
                    <div class="al-weather-detail" id="weatherDetail"></div>
                </div>
            </div>
        </div>

        <!-- Clock In / Go to Schedule -->
        <button class="al-clock-btn clock-in" id="clockBtn" onclick="handleClockIn()">
            <span class="btn-icon">&#9201;</span>
            <span id="clockBtnText">Clock In & Start</span>
        </button>
        <a href="/crm/jobs/schedule.php?view=day" class="al-skip-link" id="skipLink">View schedule</a>
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
    let quizAnswered = 0;
    let currentQuestionId = 0;

    // ── Helpers ──────────────────────────────────────────────
    function $(id) { return document.getElementById(id); }

    function show(screen) {
        document.querySelectorAll('.al-screen').forEach(s => {
            if (s.id === screen) {
                s.classList.remove('hidden', 'exit');
            } else {
                s.classList.add('exit');
                setTimeout(() => s.classList.add('hidden'), 500);
            }
        });
    }

    async function api(endpoint, opts = {}) {
        const url = API + endpoint;
        const res = await fetch(url, opts);
        return res.json();
    }

    function formatMoney(n) {
        return '$' + Math.round(n).toLocaleString();
    }

    // ── 1. Splash: load data ─────────────────────────────────
    async function init() {
        try {
            homeData = await api('app-home.php?action=data');
        } catch (e) {
            // If API fails, go straight to schedule
            window.location.href = '/crm/jobs/schedule.php?view=day';
            return;
        }

        // Minimum 1.5s splash
        await new Promise(r => setTimeout(r, 1500));

        if (!homeData.success) {
            window.location.href = '/crm/jobs/schedule.php?view=day';
            return;
        }

        // Already clocked in? Skip to schedule
        if (homeData.clock.clocked_in) {
            window.location.href = '/crm/jobs/schedule.php?view=day';
            return;
        }

        // Quiz needed?
        if (homeData.quiz.enabled && !homeData.quiz.done) {
            startQuiz(homeData.quiz.session_length);
        } else {
            showHome();
        }
    }

    // ── 2. Quiz flow ─────────────────────────────────────────
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
                // No questions? Skip quiz
                showHome();
                return;
            }

            quizSession = res.session_id;
            quizTotal = res.total;
            quizQuestionNum = 0;
            quizCorrect = 0;
            quizAnswered = 0;

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
        const res = await api('quiz.php?action=question&session_id=' + quizSession + '&q=' + num);
        if (!res.success) { finishQuiz(); return; }

        const q = res.question;
        const opts = res.options;
        currentQuestionId = q.id;

        // Update progress dots
        document.querySelectorAll('.al-quiz-progress .dot').forEach((d, i) => {
            d.className = 'dot' + (i < num - 1 ? ' done' : '') + (i === num - 1 ? ' active' : '');
        });

        // Build image HTML — use images array if available, fall back to single image_path
        const images = (q.images && q.images.length)
            ? q.images
            : (q.image_path ? [{ image_path: q.image_path }] : []);
        const imgHtml = images.length
            ? `<div class="al-quiz-img-wrap"><img src="${esc(images[0].image_path)}" alt="Question image" class="al-quiz-img"></div>`
            : '';

        $('quizCard').innerHTML = `
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
    }

    window._answerQuiz = async function(optionId) {
        // Disable all buttons
        document.querySelectorAll('.al-quiz-option').forEach(b => b.disabled = true);

        const res = await api('quiz.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'answer',
                session_id: quizSession,
                question_id: currentQuestionId,
                selected_option_id: optionId,
                time_taken_seconds: 10,
                csrf_token: CSRF
            })
        });

        quizAnswered++;
        if (res.is_correct) quizCorrect++;

        // Highlight correct/wrong
        document.querySelectorAll('.al-quiz-option').forEach(b => {
            const id = parseInt(b.dataset.id);
            if (id === res.correct_option_id) b.classList.add('correct');
            else if (id === optionId && !res.is_correct) b.classList.add('wrong');
        });

        const fb = $('quizFeedback');
        fb.textContent = res.is_correct ? 'Correct!' : 'Not quite';
        fb.style.color = res.is_correct ? 'var(--al-green)' : '#ef4444';

        // Next question after brief pause
        setTimeout(() => {
            if (quizQuestionNum < quizTotal) {
                loadQuestion(quizQuestionNum + 1);
            } else {
                finishQuiz();
            }
        }, 1200);
    };

    async function finishQuiz() {
        // Finish session
        await api('quiz.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'finish',
                session_id: quizSession,
                csrf_token: CSRF
            })
        });

        // Mark pre-shift complete
        await api('quiz.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'preshift_complete',
                session_id: quizSession,
                questions_asked: quizTotal,
                questions_correct: quizCorrect,
                csrf_token: CSRF
            })
        });

        // Show completion briefly
        const pct = quizTotal > 0 ? Math.round((quizCorrect / quizTotal) * 100) : 0;
        $('quizProgress').style.display = 'none';
        $('quizCard').innerHTML = `
            <div class="al-quiz-complete">
                <div class="score-circle">${pct}%</div>
                <h2>${quizCorrect}/${quizTotal} correct</h2>
                <p>Nice work — let's get to it</p>
            </div>
        `;
        document.querySelector('.al-quiz-header h1').textContent = 'Quiz complete';
        document.querySelector('.al-quiz-header p').textContent = '';

        setTimeout(() => showHome(), 1800);
    }

    // ── 3. Home → always go to schedule ─────────────────────
    function showHome() {
        window.location.href = '/crm/jobs/schedule.php';
    }

    function formatDate(dateStr) {
        const d = new Date(dateStr + 'T12:00:00');
        return d.toLocaleDateString('en-CA', { month: 'long', day: 'numeric' });
    }

    function esc(str) {
        const el = document.createElement('span');
        el.textContent = str || '';
        return el.innerHTML;
    }

    // ── Clock In ─────────────────────────────────────────────
    window.handleClockIn = async function() {
        const btn = $('clockBtn');
        const txt = $('clockBtnText');

        btn.disabled = true;
        txt.innerHTML = '<span class="al-spinner"></span>';

        try {
            // Get location
            let lat = null, lng = null;
            try {
                const pos = await new Promise((resolve, reject) => {
                    navigator.geolocation.getCurrentPosition(resolve, reject, {
                        enableHighAccuracy: true, timeout: 5000
                    });
                });
                lat = pos.coords.latitude;
                lng = pos.coords.longitude;
            } catch (e) {
                // Location not available — proceed without it
            }

            const res = await api('time-clock.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'clock_in',
                    lat: lat,
                    lng: lng
                })
            });

            if (res.success || res.entry_id) {
                txt.textContent = 'Clocked in!';
                setTimeout(() => {
                    window.location.href = '/crm/jobs/schedule.php?view=day';
                }, 600);
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
