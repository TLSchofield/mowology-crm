/**
 * mw-quiz-offline.js — Offline pre-shift quiz interceptor
 *
 * When the device is offline, patches window.fetch to intercept quiz API
 * calls (/crm/api/quiz.php) and serve synthetic responses from IndexedDB.
 * This lets the pre-shift quiz in schedule.php run without a server connection.
 *
 * Also syncs pending quiz completions back to the server when online.
 *
 * Requires: mw-offline-db.js (loaded first), mw-auth.js (loaded first).
 * Loads synchronously — must be in <head> before schedule.php's inline quiz JS.
 */
(function () {
    'use strict';

    // ── Local session state ───────────────────────────────────────────────────
    // One in-memory session lives for the duration of an offline quiz round.
    var _session = null;
    /*
     * _session shape:
     * {
     *   questions: [ { id, text, category_name, category_colour, difficulty,
     *                  image_path, options: [{id, text, is_correct}] } ],
     *   answers:   { questionId: { selectedOptionId, isCorrect, timeTaken } },
     *   correct:   0,
     *   length:    N,    // total questions this session
     * }
     */

    // ── Helpers ───────────────────────────────────────────────────────────────

    function todayStr() {
        var d = new Date();
        return d.getFullYear() + '-' +
            String(d.getMonth() + 1).padStart(2, '0') + '-' +
            String(d.getDate()).padStart(2, '0');
    }

    /** Wrap a plain object as a resolved Response (Content-Type: JSON). */
    function jsonResponse(obj, status) {
        var body = JSON.stringify(obj);
        try {
            return Promise.resolve(new Response(body, {
                status:  status || 200,
                headers: { 'Content-Type': 'application/json' },
            }));
        } catch (e) {
            // Fallback: create a minimal Response-like object the quiz JS can use
            return Promise.resolve({
                ok:   (status || 200) < 400,
                json: function () { return Promise.resolve(obj); },
            });
        }
    }

    /** Shuffle an array in-place (Fisher-Yates). */
    function shuffle(arr) {
        for (var i = arr.length - 1; i > 0; i--) {
            var j = Math.floor(Math.random() * (i + 1));
            var tmp = arr[i]; arr[i] = arr[j]; arr[j] = tmp;
        }
        return arr;
    }

    // ── Offline quiz handlers ─────────────────────────────────────────────────

    /**
     * action=start — pick N random questions from IDB, create local session.
     * Returns { success: true, session_id: -1, total: N }
     */
    function handleStart(sessionLength) {
        return window.MwOfflineDB.getQuizQuestions().then(function (all) {
            if (!all || !all.length) {
                return jsonResponse({ success: false, error: 'No cached questions. Go online to load questions.' });
            }
            var pool = shuffle(all.slice());
            var picked = pool.slice(0, Math.min(sessionLength || 3, pool.length));
            _session = {
                questions: picked,
                answers:   {},
                correct:   0,
                length:    picked.length,
            };
            return jsonResponse({ success: true, session_id: -1, total: picked.length });
        });
    }

    /**
     * action=question — serve question N from local session.
     * Returns same shape as the server's GET quiz endpoint.
     */
    function handleQuestion(qNum) {
        if (!_session) {
            return jsonResponse({ success: false, error: 'No active offline session' }, 400);
        }
        var idx = qNum - 1;
        if (idx < 0 || idx >= _session.questions.length) {
            return jsonResponse({ success: false, error: 'Question index out of range' }, 400);
        }
        var q = _session.questions[idx];
        var opts = shuffle(q.options.slice());
        return jsonResponse({
            success:      true,
            question_num: qNum,
            total:        _session.length,
            question: {
                id:               q.id,
                text:             q.text,
                difficulty:       q.difficulty || 'medium',
                category_name:    q.category_name || '',
                category_colour:  q.category_colour || '#2D8659',
                mastery_level:    0,
                mastery_name:     'Offline',
                image_path:       q.image_path || null,
            },
            options: opts.map(function (o) { return { id: o.id, option_text: o.text }; }),
        });
    }

    /**
     * action=answer — evaluate answer locally, return correct/wrong feedback.
     */
    function handleAnswer(sessionId, questionId, selectedOptionId, timeTaken) {
        if (!_session) {
            return jsonResponse({ success: false, error: 'No active offline session' }, 400);
        }
        var q = null;
        for (var i = 0; i < _session.questions.length; i++) {
            if (_session.questions[i].id === questionId) { q = _session.questions[i]; break; }
        }
        if (!q) {
            return jsonResponse({ success: false, error: 'Question not in session' }, 404);
        }

        var correctOption = null;
        for (var j = 0; j < q.options.length; j++) {
            if (q.options[j].is_correct) { correctOption = q.options[j]; break; }
        }
        var correctId = correctOption ? correctOption.id : null;
        var isCorrect = (selectedOptionId !== null && selectedOptionId === correctId);
        if (isCorrect) _session.correct++;

        _session.answers[questionId] = {
            selectedOptionId: selectedOptionId,
            isCorrect: isCorrect,
            timeTaken: timeTaken,
        };

        return jsonResponse({
            success:          true,
            is_correct:       isCorrect,
            correct_option_id: correctId,
            points_earned:    isCorrect ? (timeTaken <= 10 ? 15 : timeTaken <= 20 ? 13 : 10) : 0,
            mastery: { old_level: 0, new_level: 0, delta: 0, level_name: 'Offline', streak: 0 },
        });
    }

    /**
     * action=preshift_complete — save completion to IDB, queue for sync.
     */
    function handlePreshiftComplete(input) {
        var correct = parseInt(input.questions_correct || 0, 10);
        var asked   = parseInt(input.questions_asked   || 3, 10);
        var date    = todayStr();

        return window.MwOfflineDB.saveQuizPreshift(date, {
            correct:      correct,
            asked:        asked,
            completed_at: Date.now(),
            synced:       false,
        }).then(function () {
            return jsonResponse({ success: true, already_done: false, offline: true });
        });
    }

    // ── Parse body helper ─────────────────────────────────────────────────────

    function parseBody(init) {
        if (!init || !init.body) return {};
        var body = init.body;
        if (typeof body === 'string') {
            try { return JSON.parse(body); } catch (e) { return {}; }
        }
        return {};
    }

    // ── Main fetch interceptor ────────────────────────────────────────────────

    var _origFetch = window.fetch; // already wrapped by mw-auth.js

    window.fetch = function (input, init) {
        var url = (typeof input === 'string') ? input : (input && input.url ? input.url : '');

        // Only intercept quiz API when offline
        if (!navigator.onLine && url.indexOf('/crm/api/quiz.php') !== -1) {
            var params = {};
            try {
                var qstr = url.split('?')[1] || '';
                qstr.split('&').forEach(function (pair) {
                    var kv = pair.split('=');
                    if (kv[0]) params[decodeURIComponent(kv[0])] = decodeURIComponent(kv[1] || '');
                });
            } catch (e) {}

            var action = params.action || '';
            var method = (init && init.method) ? init.method.toUpperCase() : 'GET';

            // GET: action=question
            if (method === 'GET' && params.session_id && params.q) {
                return handleQuestion(parseInt(params.q, 10));
            }

            if (method === 'POST') {
                var body = parseBody(init);
                action = body.action || action;

                if (action === 'start') {
                    return handleStart(body.session_length || 3);
                }
                if (action === 'answer') {
                    return handleAnswer(
                        parseInt(body.session_id, 10),
                        parseInt(body.question_id, 10),
                        body.selected_option_id !== undefined ? parseInt(body.selected_option_id, 10) : null,
                        parseInt(body.time_taken_seconds || 30, 10)
                    );
                }
                if (action === 'preshift_complete') {
                    return handlePreshiftComplete(body);
                }
            }
        }

        return _origFetch.call(this, input, init);
    };

    // ── Sync pending quiz results when back online ─────────────────────────────

    function syncPendingQuiz() {
        if (!window.MwOfflineDB) return;
        window.MwOfflineDB.getPendingQuizSyncs().then(function (pending) {
            if (!pending || !pending.length) return;

            var token = window.MwAuth ? window.MwAuth.getToken() : null;
            var headers = { 'Content-Type': 'application/json' };
            if (token) headers['Authorization'] = 'Bearer ' + token;

            // Fetch CSRF token first (needed for preshift_complete)
            _origFetch.call(window, '/crm/api/get-csrf.php')
                .then(function (r) { return r.json(); })
                .then(function (csrfData) {
                    var csrf = csrfData && csrfData.token ? csrfData.token : '';

                    pending.forEach(function (record) {
                        _origFetch.call(window, '/crm/api/quiz.php?action=preshift_complete', {
                            method: 'POST',
                            headers: headers,
                            body: JSON.stringify({
                                session_id:        null,
                                questions_correct: record.correct,
                                questions_asked:   record.asked,
                                csrf_token:        csrf,
                            }),
                        })
                        .then(function (r) { return r.json(); })
                        .then(function (d) {
                            if (d && d.success !== false) {
                                // Mark as synced in IDB
                                window.MwOfflineDB.saveQuizPreshift(record.date, Object.assign({}, record, { synced: true }));
                            }
                        })
                        .catch(function () { /* retry on next online event */ });
                    });
                })
                .catch(function () { /* no CSRF — will retry next time */ });
        }).catch(function () {});
    }

    window.addEventListener('online', function () {
        setTimeout(syncPendingQuiz, 2000);
    });

    // Attempt sync on page load too (in case previous offline session left pending records)
    if (navigator.onLine) {
        setTimeout(syncPendingQuiz, 5000);
    }

})();
