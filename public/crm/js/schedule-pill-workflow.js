/**
 * Schedule Pill Workflow — Per-Visit Job Execution State Machine
 *
 * Drives the mobile schedule hero card's interactive service pills.
 * Each pill represents a single visit (job_visit) and tracks its lifecycle:
 *   scheduled → clock-in → before-photo → working → after-photo → clock-out → completed
 *
 * Integrates with:
 *   POST /crm/api/job-timer.php   — start/stop/pause per-visit timers
 *   POST /crm/api/media-upload.php — photo upload (context_type=job_visit)
 *
 * Depends on MW_SCHEDULE_STATE global set by schedule.php:
 *   { csrf, userId, activeTimer: { visit_id, start_time, elapsed_seconds } | null }
 *
 * @package Mowology CRM
 */
(function() {
    'use strict';

    // Only run on mobile-width screens where the card view is visible
    if (window.innerWidth > 991) {
        console.log('[PillWorkflow] Skipped: desktop viewport (' + window.innerWidth + 'px)');
        return;
    }

    // Wait for the state object from schedule.php
    if (typeof MW_SCHEDULE_STATE === 'undefined') {
        console.log('[PillWorkflow] Skipped: MW_SCHEDULE_STATE not defined');
        return;
    }

    console.log('[PillWorkflow] Initializing...', MW_SCHEDULE_STATE);

    var state = MW_SCHEDULE_STATE;
    var visits = {};        // visitId -> { status, pill, serviceLabel, entryId, startTime, timerInterval, beforeThumb, afterThumb, additionalThumbs[] }
    var activeDrawer = null;
    var activeDrawerVisitId = null;
    var cachedGps = null;

    // ═══════════════════════════════════════════════════════
    //  INITIALIZATION
    // ═══════════════════════════════════════════════════════

    function init() {
        // Scan all interactive pills and register them
        var allPills = document.querySelectorAll('.mw-mc-pill-interactive');
        console.log('[PillWorkflow] Found ' + allPills.length + ' interactive pills');

        allPills.forEach(function(pill) {
            var visitId = parseInt(pill.dataset.visitId, 10);
            if (!visitId) {
                console.log('[PillWorkflow] Skipping pill with no visitId:', pill.textContent.trim());
                return;
            }

            var visitStatus = pill.dataset.visitStatus || 'scheduled';
            var serviceLabel = pill.textContent.trim();
            var serviceType = pill.dataset.serviceType || '';

            visits[visitId] = {
                status: visitStatus,
                pill: pill,
                serviceLabel: serviceLabel,
                serviceType: serviceType,
                autoClockIn: pill.dataset.autoClockIn === '1',
                trackingLevel: null,
                requirePhotos: false,
                entryId: null,
                startTime: null,
                timerInterval: null,
                beforeThumb: null,
                afterThumb: null,
                additionalThumbs: []
            };

            // Restore in-progress timer from page load state
            if (state.activeTimer && state.activeTimer.visit_id === visitId) {
                visits[visitId].status = 'in_progress';
                visits[visitId].startTime = new Date(
                    Date.now() - (state.activeTimer.elapsed_seconds * 1000)
                );
                updatePillVisual(visitId, 'in_progress');
                startPillTimer(visitId);
                // Sync per-visit section footer (deferred: DOM may not be ready until initPerVisitFooters runs)
                setTimeout(function() { pvSetTiming(visitId); }, 0);
            } else if (visitStatus === 'in_progress') {
                // Visit is in-progress (maybe another user's timer) — show visual only
                updatePillVisual(visitId, 'in_progress');
                setTimeout(function() { pvSetTiming(visitId); }, 0);
            } else if (visitStatus === 'completed') {
                updatePillVisual(visitId, 'completed');
                setTimeout(function() { pvSetDone(visitId); }, 0);
            }

            // Make pill tappable (stop propagation to prevent card expand/collapse)
            pill.addEventListener('click', function(e) {
                e.stopPropagation();
                e.preventDefault();
                console.log('[PillWorkflow] Pill tapped: visit ' + visitId + ' (' + serviceLabel + '), status: ' + visits[visitId].status);
                handlePillTap(visitId);
            });
        });

        console.log('[PillWorkflow] Registered ' + Object.keys(visits).length + ' visits');

        // Pre-populate existing photo thumbnails from DB data
        if (state.visitPhotos) {
            var photoCount = 0;
            for (var vid in state.visitPhotos) {
                if (!state.visitPhotos.hasOwnProperty(vid)) continue;
                var photos = state.visitPhotos[vid];
                var numVid = parseInt(vid, 10);
                if (visits[numVid]) {
                    if (photos.before) {
                        visits[numVid].beforeThumb = photos.before;
                        photoCount++;
                    }
                    if (photos.after) {
                        visits[numVid].afterThumb = photos.after;
                        photoCount++;
                    }
                    if (photos.additionals && Array.isArray(photos.additionals)) {
                        visits[numVid].additionalThumbs = photos.additionals;
                        photoCount += photos.additionals.length;
                    }
                }
            }
            console.log('[PillWorkflow] Pre-loaded ' + photoCount + ' existing photo thumbnails');
        }

        // Render photo strips for all visits (shows placeholders even before photos taken)
        for (var initVid in visits) {
            if (visits.hasOwnProperty(initVid)) {
                renderPhotoStrip(parseInt(initVid, 10));
            }
        }

        // Pre-fetch GPS for later use
        getGps(function(lat, lng) {
            console.log('[PillWorkflow] GPS ready:', lat, lng);
        });

        // Listen for hero promotion (GPS arrival) — auto-clock-in single-visit cards
        document.addEventListener('mw-hero-promoted', function(e) {
            autoClockInSingleVisit(e.detail.card);
        });

        // Listen for server-side proximity auto-start (from time-clock-widget.js)
        // The server already started the timer — update pill state to match.
        document.addEventListener('mw-proximity-auto-start', function(e) {
            var info = e.detail;
            if (!info || !info.visit_id) return;
            var visitId = info.visit_id;

            if (!visits[visitId]) {
                console.log('[PillWorkflow] Server auto-started visit ' + visitId + ' but not found in pill registry');
                return;
            }

            console.log('[PillWorkflow] Server auto-started visit ' + visitId + ', updating pill state');

            // Update internal state — server already started the timer
            visits[visitId].status = 'in_progress';
            visits[visitId].startTime = new Date();
            visits[visitId].entryId = info.entry_id || null;
            updatePillVisual(visitId, 'in_progress');
            startPillTimer(visitId);

            // Notify GPS widget if not already done
            if (window.MwTimeClock) {
                window.MwTimeClock.notifyJobTimerStarted();
            }

            // Promote the card to hero position
            var card = visits[visitId].pill.closest('.mw-mc-card');
            if (card) {
                card.classList.add('mw-mc-card-hero');
                card.classList.add('mw-mc-proximity-match');
                var scrollArea = document.querySelector('.mw-mc-scroll-area');
                if (scrollArea && scrollArea.firstElementChild !== card) {
                    scrollArea.insertBefore(card, scrollArea.firstElementChild);
                    scrollArea.scrollTop = 0;
                }
            }

            // Sync shared footer and per-visit section footer to timer state
            var stopIdForFooter = card ? parseInt(card.dataset.stopId, 10) : 0;
            if (stopIdForFooter) footerSetTiming(stopIdForFooter, visitId);
            pvSetTiming(visitId);
        });

        // Init card footers after all pills are registered
        initCardFooters();
        initPerVisitFooters();
    }

    /**
     * Auto-start timer when crew arrives at a single-visit stop.
     * Skips if: multiple visits on card, timer already running, visit not scheduled.
     *
     * A visit qualifies for auto-clock-in if EITHER:
     *   1. The visit has autoClockIn=true (per-product/plan flag), OR
     *   2. The global autoArrivalServiceTypes list includes this service type
     */
    function autoClockInSingleVisit(card) {
        if (!card) return;

        // Check if auto-arrival is enabled globally (set by MW_SCHEDULE_STATE)
        if (!state.autoArrivalEnabled) {
            console.log('[PillWorkflow] Auto-clock-in skipped: auto-arrival disabled');
            return;
        }

        // Only auto-start if no other timer is running
        if (getActiveInProgressVisitId() !== null) {
            console.log('[PillWorkflow] Auto-clock-in skipped: another timer is active');
            return;
        }

        // Find all scheduled pills on this card
        var pills = card.querySelectorAll('.mw-mc-pill-interactive');
        var scheduledVisitIds = [];
        pills.forEach(function(p) {
            var vid = parseInt(p.dataset.visitId, 10);
            if (vid && visits[vid] && visits[vid].status === 'scheduled') {
                scheduledVisitIds.push(vid);
            }
        });

        // Only auto-start if exactly one scheduled visit remains
        if (scheduledVisitIds.length !== 1) {
            console.log('[PillWorkflow] Auto-clock-in skipped: ' + scheduledVisitIds.length + ' scheduled visits on card');
            return;
        }

        var visitId = scheduledVisitIds[0];
        var v = visits[visitId];
        var serviceType = v.serviceType || '';

        // Check if this visit qualifies for auto-clock-in:
        // 1. Per-product/plan auto_clock_in flag (set in Edit Product or plan override)
        // 2. Global service type allowlist (legacy Time Clock Settings)
        var hasPerVisitFlag = v.autoClockIn === true;
        var allowedTypes = state.autoArrivalServiceTypes || [];
        var inGlobalList = allowedTypes.length > 0 && allowedTypes.indexOf(serviceType) !== -1;

        if (!hasPerVisitFlag && !inGlobalList) {
            console.log('[PillWorkflow] Auto-clock-in skipped: ' + serviceType + ' not flagged (autoClockIn=' + v.autoClockIn + ', globalList=[' + allowedTypes.join(',') + '])');
            return;
        }

        var reason = hasPerVisitFlag ? 'product/plan flag' : 'global service type list';
        console.log('[PillWorkflow] Auto-clock-in: visit ' + visitId + ' (' + v.serviceLabel + ') via ' + reason);
        clockIn(visitId);
    }

    // ═══════════════════════════════════════════════════════
    //  PILL TAP HANDLER
    // ═══════════════════════════════════════════════════════

    function handlePillTap(visitId) {
        var v = visits[visitId];
        if (!v || v.status === 'completed') return;

        var card = v.pill.closest('.mw-mc-card');
        var drawer = card.querySelector('.mw-mc-pill-drawer');
        if (!drawer) return;

        // Close any other open drawer
        if (activeDrawer && activeDrawer !== drawer) {
            activeDrawer.style.display = 'none';
            activeDrawer.innerHTML = '';
            activeDrawerVisitId = null;
        }

        // Toggle if same drawer already open for same visit
        if (activeDrawerVisitId === visitId && drawer.style.display !== 'none') {
            drawer.style.display = 'none';
            drawer.innerHTML = '';
            activeDrawer = null;
            activeDrawerVisitId = null;
            return;
        }

        // Render drawer content based on current state
        switch (v.status) {
            case 'scheduled':
                // Check if another visit is already in-progress for this user
                if (getActiveInProgressVisitId() !== null) {
                    renderBlockedDrawer(drawer, visitId);
                } else {
                    renderClockInDrawer(drawer, visitId);
                }
                break;
            case 'prompt_before':
                renderPhotoPrompt(drawer, visitId, 'before');
                break;
            case 'in_progress':
                renderWorkingDrawer(drawer, visitId);
                break;
            case 'prompt_after':
                renderPhotoPrompt(drawer, visitId, 'after');
                break;
        }

        drawer.style.display = 'block';
        activeDrawer = drawer;
        activeDrawerVisitId = visitId;

        // Scroll drawer into view
        setTimeout(function() {
            drawer.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }, 50);
    }

    /**
     * Find if the current user has an in-progress visit (one-timer-at-a-time)
     */
    function getActiveInProgressVisitId() {
        for (var vid in visits) {
            if (visits.hasOwnProperty(vid)) {
                var v = visits[vid];
                if (v.status === 'in_progress' && v.startTime !== null) {
                    return parseInt(vid, 10);
                }
            }
        }
        return null;
    }

    // ═══════════════════════════════════════════════════════
    //  DRAWER RENDERERS
    // ═══════════════════════════════════════════════════════

    /**
     * "Clock In" button for a scheduled visit
     */
    function renderClockInDrawer(drawer, visitId) {
        drawer.innerHTML =
            '<div class="mw-mc-drawer-row">' +
            '  <button class="mw-mc-drawer-btn mw-mc-drawer-btn-primary" data-action="clock-in">' +
            '    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="5 3 19 12 5 21 5 3"/></svg>' +
            '    Clock In' +
            '  </button>' +
            '</div>';

        drawer.querySelector('[data-action="clock-in"]')
            .addEventListener('click', function(e) {
                e.stopPropagation();
                clockIn(visitId);
            });
    }

    /**
     * Warning when another visit is in-progress
     */
    function renderBlockedDrawer(drawer, visitId) {
        var activeVid = getActiveInProgressVisitId();
        var activeName = activeVid && visits[activeVid] ? visits[activeVid].serviceLabel : 'another job';

        drawer.innerHTML =
            '<div class="mw-mc-drawer-warning">' +
            '  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>' +
            '  Clock out of <strong>' + escHtml(activeName) + '</strong> first' +
            '</div>';
    }

    /**
     * "Photo" + "Finish" buttons for an in-progress visit
     */
    function renderWorkingDrawer(drawer, visitId) {
        drawer.innerHTML =
            '<div class="mw-mc-drawer-row">' +
            '  <button class="mw-mc-drawer-btn mw-mc-drawer-btn-secondary" data-action="photo">' +
            '    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M23 19a2 2 0 01-2 2H3a2 2 0 01-2-2V8a2 2 0 012-2h4l2-3h6l2 3h4a2 2 0 012 2z"/><circle cx="12" cy="13" r="4"/></svg>' +
            '    Photo' +
            '  </button>' +
            '  <button class="mw-mc-drawer-btn mw-mc-drawer-btn-observe" data-action="observe">' +
            '    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>' +
            '    Observe' +
            '  </button>' +
            '  <button class="mw-mc-drawer-btn mw-mc-drawer-btn-finish" data-action="finish">' +
            '    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="8" y1="12" x2="16" y2="12"/></svg>' +
            '    Finish' +
            '  </button>' +
            '</div>';

        drawer.querySelector('[data-action="photo"]')
            .addEventListener('click', function(e) {
                e.stopPropagation();
                triggerCamera(visitId, 'during');
            });

        drawer.querySelector('[data-action="observe"]')
            .addEventListener('click', function(e) {
                e.stopPropagation();
                openObservationModal(visitId);
            });

        drawer.querySelector('[data-action="finish"]')
            .addEventListener('click', function(e) {
                e.stopPropagation();
                visits[visitId].status = 'prompt_after';
                renderPhotoPrompt(drawer, visitId, 'after');
            });
    }

    /**
     * Camera prompt (before or after photo)
     */
    function renderPhotoPrompt(drawer, visitId, category) {
        var label = category === 'before' ? 'Before Photo' : 'After Photo';
        var icon = '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M23 19a2 2 0 01-2 2H3a2 2 0 01-2-2V8a2 2 0 012-2h4l2-3h6l2 3h4a2 2 0 012 2z"/><circle cx="12" cy="13" r="4"/></svg>';
        var photosRequired = visits[visitId].requirePhotos;

        drawer.innerHTML =
            '<div class="mw-mc-drawer-photo-prompt">' +
            '  <button class="mw-mc-drawer-camera-btn" data-action="take-photo">' +
            '    ' + icon +
            '    <span>' + label + (photosRequired ? ' (Required)' : '') + '</span>' +
            '  </button>' +
            (photosRequired
                ? '<span class="mw-mc-drawer-skip-disabled">Photo required for this job</span>'
                : '<button class="mw-mc-drawer-skip" data-action="skip-photo">Skip</button>') +
            '</div>';

        drawer.querySelector('[data-action="take-photo"]')
            .addEventListener('click', function(e) {
                e.stopPropagation();
                triggerCamera(visitId, category);
            });

        var skipBtn = drawer.querySelector('[data-action="skip-photo"]');
        if (skipBtn) skipBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                if (category === 'before') {
                    // Skip before photo → go to working state
                    visits[visitId].status = 'in_progress';
                    updatePillVisual(visitId, 'in_progress');
                    startPillTimer(visitId);
                    closeDrawer();
                } else {
                    // Skip after photo → clock out
                    clockOut(visitId);
                }
            });
    }

    // ═══════════════════════════════════════════════════════
    //  API CALLS
    // ═══════════════════════════════════════════════════════

    /**
     * Start a visit timer (clock in)
     */
    function clockIn(visitId) {
        console.log('[PillWorkflow] clockIn called for visit ' + visitId);
        var btn = activeDrawer ? activeDrawer.querySelector('[data-action="clock-in"]') : null;
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<span>Starting...</span>';
        }

        getGps(function(lat, lng) {
            console.log('[PillWorkflow] Sending start timer request for visit ' + visitId);
            fetch('/crm/api/job-timer.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'start',
                    visit_id: visitId,
                    lat: lat,
                    lng: lng
                })
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                console.log('[PillWorkflow] Timer start response:', JSON.stringify(data));
                if (data.success) {
                    visits[visitId].entryId = data.entry_id;
                    visits[visitId].startTime = new Date();
                    visits[visitId].status = 'prompt_before';

                    // Store tracking requirements from API response
                    if (data.tracking_level) {
                        visits[visitId].trackingLevel = data.tracking_level;
                    }
                    if (data.require_photos !== undefined) {
                        visits[visitId].requirePhotos = data.require_photos;
                    }

                    // Notify GPS widget: job timer started (activates GPS on personal devices)
                    if (window.MwTimeClock) {
                        window.MwTimeClock.notifyJobTimerStarted();
                        // Adjust GPS interval for heightened tracking
                        if (data.tracking_level === 'heightened') {
                            window.MwTimeClock.setTrackingInterval('heightened');
                        }
                    }

                    // Update pill to show it's been activated
                    updatePillVisual(visitId, 'in_progress');

                    // Sync card footer and per-visit section footer to show running timer
                    var card = visits[visitId].pill.closest('.mw-mc-card');
                    var stopIdForTimer = card ? parseInt(card.dataset.stopId, 10) : 0;
                    if (stopIdForTimer) footerSetTiming(stopIdForTimer, visitId);
                    pvSetTiming(visitId);

                    // Show before photo prompt in drawer
                    var drawer = card ? card.querySelector('.mw-mc-pill-drawer') : null;
                    if (drawer) {
                        renderPhotoPrompt(drawer, visitId, 'before');
                        drawer.style.display = 'block';
                        activeDrawer = drawer;
                        activeDrawerVisitId = visitId;
                    }
                } else {
                    showToast('Could not start timer: ' + (data.error || data.message || 'Unknown error'));
                    if (btn) {
                        btn.disabled = false;
                        btn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="5 3 19 12 5 21 5 3"/></svg> Clock In';
                    }
                }
            })
            .catch(function(err) {
                showToast('Network error. Check your connection.');
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="5 3 19 12 5 21 5 3"/></svg> Clock In';
                }
            });
        });
    }

    /**
     * Stop a visit timer (clock out)
     */
    function clockOut(visitId) {
        // Show loading state on pill
        var v = visits[visitId];
        var originalHtml = v.pill.innerHTML;

        getGps(function(lat, lng) {
            fetch('/crm/api/job-timer.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'stop',
                    visit_id: visitId,
                    lat: lat,
                    lng: lng,
                    complete_visit: true
                })
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    visits[visitId].status = 'completed';
                    stopPillTimer(visitId);
                    updatePillVisual(visitId, 'completed');
                    closeDrawer();

                    // Notify GPS widget: job timer stopped
                    if (window.MwTimeClock) {
                        // Check if any OTHER visit is still in progress
                        var otherActive = getActiveInProgressVisitId();
                        if (!otherActive) {
                            window.MwTimeClock.notifyJobTimerStopped();
                            window.MwTimeClock.setTrackingInterval('standard'); // Reset to standard
                        }
                    }

                    // Check if ALL visits on this stop are now completed
                    var card = visits[visitId].pill.closest('.mw-mc-card');
                    checkStopComplete(card);

                    // Sync footer to completed state
                    var stopIdForFooter = card ? parseInt(card.dataset.stopId, 10) : 0;
                    if (stopIdForFooter) {
                        footerSetIdle(stopIdForFooter);
                        var footerComplete = document.querySelector('[data-footer-complete="' + stopIdForFooter + '"]');
                        if (footerComplete) footerComplete.style.display = 'none';
                    }
                    // Hide per-visit section footer
                    pvSetDone(visitId);

                    // Show completion feedback
                    var duration = data.duration_formatted || (data.duration_minutes + 'm');
                    showToast(v.serviceLabel + ' completed (' + duration + ')');
                } else {
                    showToast('Could not stop timer: ' + (data.error || data.message || 'Unknown error'));
                }
            })
            .catch(function(err) {
                showToast('Network error. Check your connection.');
            });
        });
    }

    // ═══════════════════════════════════════════════════════
    //  CAMERA / PHOTO UPLOAD
    // ═══════════════════════════════════════════════════════

    /**
     * Trigger the device camera / file picker for a visit photo.
     *
     * PLATFORM NOTES:
     *   iOS Safari (PWA + browser): input.click() MUST be called synchronously
     *   within the same JS call stack as the originating user gesture.
     *   Any setTimeout — even 0ms — breaks the "trusted gesture" chain and the
     *   file picker silently refuses to open.
     *
     *   Android Chrome / Capacitor WebView: same rule applies; synchronous click
     *   is safe and correct on both platforms.
     *
     *   overflow:hidden: the input is appended to document.body (not the card)
     *   to avoid any overflow:hidden ancestor interfering with picker activation.
     *   It is styled with opacity:0 + position:fixed (NOT display:none) because
     *   some iOS versions do not honour .click() on display:none file inputs.
     */
    // Track pending camera sessions to prevent double-fire on tablets
    var pendingCamera = null; // { visitId, category, card, input, handler }

    function triggerCamera(visitId, category) {
        console.log('[PillWorkflow] triggerCamera: visit=' + visitId + ' cat=' + category);

        var v = visits[visitId];
        if (!v) { console.warn('[PillWorkflow] triggerCamera: visit ' + visitId + ' not registered'); return; }

        var card = v.pill.closest('.mw-mc-card');
        if (!card) { console.warn('[PillWorkflow] triggerCamera: no card found for visit ' + visitId); return; }

        // Clean up any prior pending session (prevents ghost inputs in body)
        if (pendingCamera) {
            if (pendingCamera.input) {
                pendingCamera.input.removeEventListener('change', pendingCamera.handler);
                if (pendingCamera.input.parentNode) {
                    pendingCamera.input.parentNode.removeChild(pendingCamera.input);
                }
            }
            pendingCamera = null;
        }

        // Create a fresh <input type=file> each time.
        // Append to document.body to avoid overflow:hidden on the card container.
        // Use opacity:0 + position:fixed (not display:none) for reliable iOS activation.
        var input = document.createElement('input');
        input.type = 'file';
        input.accept = 'image/*';
        input.setAttribute('capture', 'environment');
        input.setAttribute('data-cam-visit', String(visitId));
        input.style.cssText = 'position:fixed;top:0;left:0;width:1px;height:1px;opacity:0;pointer-events:none;z-index:-1;';
        document.body.appendChild(input);

        var handler = function() {
            // Remove input from DOM immediately
            input.removeEventListener('change', handler);
            if (input.parentNode) input.parentNode.removeChild(input);
            pendingCamera = null;

            if (MW_PHOTO_DEBUG) {
                console.log('[PillWorkflow:DEBUG] change event fired: files=' +
                    (input.files ? input.files.length : 'null') +
                    ' visit=' + visitId + ' cat=' + category);
            }

            if (!input.files || !input.files.length) {
                // User cancelled — re-render strip so placeholders are still tappable
                renderPhotoStrip(visitId);
                return;
            }

            var file = input.files[0];

            // 1a. Client-side size check — catch oversized files before upload.
            // Mirrors the 15 MB server limit in MediaUploadService.php.
            var MAX_UPLOAD_BYTES = 15 * 1024 * 1024;
            if (file.size > MAX_UPLOAD_BYTES) {
                showToast('Photo too large (max 15 MB). Reduce camera quality in Settings and try again.');
                renderPhotoStrip(visitId);
                return;
            }

            console.log('[PillWorkflow] Photo captured: visit=' + visitId +
                ' cat=' + category + ' size=' + file.size + ' type=' + file.type);

            // Show uploading state immediately on the placeholder
            var strip = card.querySelector('[data-strip-visit="' + visitId + '"]');
            if (strip) {
                var ph = strip.querySelector('[data-category="' + category + '"]');
                if (ph) ph.classList.add('mw-mc-placeholder-uploading');
            }

            uploadPhoto(visitId, file, category, function(success, thumbUrl) {
                if (!success) {
                    // Re-render strip so placeholder returns to tappable state
                    renderPhotoStrip(visitId);
                    return;
                }

                if (category === 'before') {
                    var curStatus = visits[visitId].status;
                    if (curStatus === 'prompt_before') {
                        // Workflow-driven: go to working state
                        visits[visitId].status = 'in_progress';
                        updatePillVisual(visitId, 'in_progress');
                        startPillTimer(visitId);
                        renderPhotoStrip(visitId);
                        if (thumbUrl) {
                            showThumbConfirmation(card, thumbUrl, 'Before', visitId, function() {
                                closeDrawer();
                            });
                        } else {
                            closeDrawer();
                        }
                    } else {
                        // Placeholder tap outside workflow: just save + update strip
                        renderPhotoStrip(visitId);
                        if (thumbUrl) showThumbConfirmation(card, thumbUrl, 'Before', visitId, null);
                    }
                } else if (category === 'after') {
                    var curStatus2 = visits[visitId].status;
                    if (curStatus2 === 'prompt_after') {
                        visits[visitId].status = 'completed';
                        renderPhotoStrip(visitId);
                        if (thumbUrl) {
                            showThumbConfirmation(card, thumbUrl, 'After', visitId, function() {
                                clockOut(visitId);
                            });
                        } else {
                            clockOut(visitId);
                        }
                    } else {
                        renderPhotoStrip(visitId);
                        if (thumbUrl) showThumbConfirmation(card, thumbUrl, 'After', visitId, null);
                    }
                } else if (category === 'during') {
                    if (thumbUrl) showThumbConfirmation(card, thumbUrl, 'Photo', visitId, null);
                } else if (category === 'additional') {
                    // additionalThumbs was already updated in doUploadPhoto() — don't double-push.
                    // Skip showThumbConfirmation: the drawer overlay blocks the "+" button for 1.5s
                    // making it impossible to snap a second additional photo in quick succession.
                    // Just re-render the strip (shows new thumb) and flash brief pill feedback.
                    renderPhotoStrip(visitId);
                }
            });
        };

        pendingCamera = { visitId: visitId, category: category, card: card, input: input, handler: handler };
        input.addEventListener('change', handler);

        // CRITICAL: input.click() must be called synchronously here — in the same JS
        // call stack as the user's tap event. iOS Safari and Capacitor WebView both
        // enforce this; any setTimeout (even 0ms) breaks picker activation silently.
        console.log('[PillWorkflow] Calling input.click() synchronously');
        input.click();
    }

    /**
     * Upload a photo to the media system.
     * Photos use visibility=client_visible so they populate the client portal.
     *
     * Error routing:
     *  - Network error (TypeError) or 30s timeout → offline IDB queue
     *  - HTTP 403 + CSRF message → fetch fresh token, retry once
     *  - HTTP 5xx → error toast, do NOT queue (server errors won't clear on retry)
     *  - HTTP 4xx other → error toast, do NOT queue
     */
    function uploadPhoto(visitId, file, category, callback) {
        doUploadPhoto(visitId, file, category, false, callback);
    }

    function doUploadPhoto(visitId, file, category, isRetry, callback) {
        // 1b. AbortController for 30-second fetch timeout
        var controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
        var timeoutId  = controller
            ? setTimeout(function() { controller.abort(); }, 30000)
            : null;

        var formData = new FormData();
        formData.append('files[]', file);
        formData.append('csrf_token', state.csrf);
        formData.append('context_type', 'job_visit');
        formData.append('context_id', String(visitId));
        formData.append('category', category);
        formData.append('visibility', 'client_visible');

        // Attach GPS if available
        if (cachedGps) {
            formData.append('gps_lat', String(cachedGps.lat));
            formData.append('gps_lng', String(cachedGps.lng));
            if (cachedGps.accuracy) {
                formData.append('gps_accuracy', String(cachedGps.accuracy));
            }
        }

        // Request proof-of-work stamp for before/after photos
        if (category === 'before' || category === 'after') {
            formData.append('pow_stamp', '1');
        }

        // Show uploading feedback on pill
        flashPillFeedback(visitId, 'Uploading...');

        console.log('[PillWorkflow] Uploading photo: visit=' + visitId + ' category=' + category +
            (isRetry ? ' (CSRF retry)' : ''));

        var fetchOpts = { method: 'POST', body: formData };
        if (controller) fetchOpts.signal = controller.signal;

        fetch('/crm/api/media-upload.php', fetchOpts)
        .then(function(response) {
            if (timeoutId) clearTimeout(timeoutId);

            var status = response.status;

            // 1c. CSRF token stale (common in PWA with stale-while-revalidate cache):
            // fetch a fresh token and retry the upload exactly once.
            if (status === 403 && !isRetry) {
                return response.json().then(function(body) {
                    if (body && body.error && body.error.indexOf('CSRF') !== -1) {
                        console.log('[PillWorkflow] CSRF token stale — refreshing and retrying');
                        return fetch('/crm/api/get-csrf.php', { method: 'GET' })
                            .then(function(r) { return r.json(); })
                            .then(function(d) {
                                if (d && d.token) {
                                    state.csrf = d.token;
                                    console.log('[PillWorkflow] CSRF token refreshed');
                                }
                                doUploadPhoto(visitId, file, category, true, callback);
                            })
                            .catch(function() {
                                showToast('Session expired. Reload the page and try again.');
                                callback(false, null);
                            });
                    }
                    // Non-CSRF 403 (e.g. role denied)
                    showToast('Access denied. Check your login and try again.');
                    callback(false, null);
                }).catch(function() {
                    showToast('Access denied. Check your login and try again.');
                    callback(false, null);
                });
            }

            // 1c. Server errors (5xx): do NOT queue — same error will recur on retry
            if (status >= 500) {
                return response.text().then(function(txt) {
                    console.error('[PillWorkflow] Server error ' + status + ':', txt.slice(0, 200));
                    showToast('Server error (' + status + '). Check connection and try again.');
                    callback(false, null);
                });
            }

            // Guard against non-JSON before parsing
            var ct = response.headers.get('content-type') || '';
            if (!ct.includes('application/json') && !ct.includes('text/json')) {
                return response.text().then(function(txt) {
                    console.warn('[PillWorkflow] Non-JSON response status=' + status + ':', txt.slice(0, 120));
                    showToast('Unexpected server response. Try again.');
                    callback(false, null);
                });
            }
            return response.json();
        })
        .then(function(data) {
            if (!data) return; // handled inline above
            if (timeoutId) clearTimeout(timeoutId);

            console.log('[PillWorkflow] Upload response:', JSON.stringify(data));
            if (data.success && data.total_uploaded > 0) {
                var thumbUrl = null;
                if (data.results && data.results[0]) {
                    var r = data.results[0];
                    if (MW_PHOTO_DEBUG) {
                        console.log('[PillWorkflow:DEBUG] Upload result: thumb_url=' + r.thumb_url + ' file_path=' + r.file_path);
                    }
                    // Prefer thumb, fall back to original path, fall back to placeholder sentinel
                    thumbUrl = r.thumb_url || r.file_path || null;

                    // If server gave no URL at all (variant generation failure),
                    // create a sentinel so the strip still shows a ✓ tile
                    if (!thumbUrl && data.results[0].success) {
                        thumbUrl = '__uploaded__';
                    }
                }
                if (visits[visitId]) {
                    if (category === 'before') {
                        visits[visitId].beforeThumb = thumbUrl || '__uploaded__';
                    } else if (category === 'after') {
                        visits[visitId].afterThumb = thumbUrl || '__uploaded__';
                    } else if (category === 'additional') {
                        if (!visits[visitId].additionalThumbs) visits[visitId].additionalThumbs = [];
                        if (thumbUrl && thumbUrl !== '__uploaded__') {
                            visits[visitId].additionalThumbs.push(thumbUrl);
                        }
                    }
                }
                flashPillFeedback(visitId, 'Photo saved');
                callback(true, thumbUrl);
            } else {
                var errMsg = 'Upload failed';
                if (data.results && data.results[0] && data.results[0].errors) {
                    errMsg = data.results[0].errors.join(', ');
                } else if (data.error) {
                    errMsg = data.error;
                }
                console.warn('[PillWorkflow] Upload failed:', errMsg);
                showToast('Photo upload failed: ' + errMsg);
                callback(false, null);
            }
        })
        .catch(function(err) {
            if (timeoutId) clearTimeout(timeoutId);
            var isAbort   = err && err.name === 'AbortError';
            var isNetwork = err && err.name === 'TypeError';

            if (isAbort) {
                console.warn('[PillWorkflow] Upload timed out after 30s');
            } else {
                console.error('[PillWorkflow] Upload error:', err.message || err);
            }

            // Only queue on genuine network/timeout errors — not server errors
            if ((isNetwork || isAbort) && photoQueueDb) {
                saveToPhotoQueue(visitId, file, category, function(queueId) {
                    if (queueId !== null) {
                        showToast(navigator.onLine
                            ? 'Upload failed \u2014 will retry automatically'
                            : 'No signal \u2014 photo saved for later');
                    } else {
                        showToast('Upload failed \u2014 check connection and try again.');
                    }
                    callback(false, null);
                });
            } else {
                showToast(isAbort
                    ? 'Upload timed out. Check your signal and try again.'
                    : 'Upload failed \u2014 check connection and try again.');
                callback(false, null);
            }
        });
    }

    // ═══════════════════════════════════════════════════════
    //  PILL TIMER DISPLAY
    // ═══════════════════════════════════════════════════════

    /**
     * Start the live elapsed timer on a pill
     */
    function startPillTimer(visitId) {
        var v = visits[visitId];
        if (!v || !v.startTime) return;
        if (v.timerInterval) clearInterval(v.timerInterval);

        var serviceLabel = v.serviceLabel;

        function updateDisplay() {
            var elapsed = Math.floor((Date.now() - v.startTime.getTime()) / 1000);
            var m = Math.floor(elapsed / 60);
            var s = elapsed % 60;
            var timeStr = m + ':' + (s < 10 ? '0' : '') + s;
            v.pill.innerHTML =
                '<span class="mw-mc-pill-label">' + escHtml(serviceLabel) + '</span> ' +
                '<span class="mw-mc-pill-timer">' + timeStr + '</span>';
        }

        updateDisplay(); // Show immediately
        v.timerInterval = setInterval(updateDisplay, 1000);
    }

    /**
     * Stop the live timer interval
     */
    function stopPillTimer(visitId) {
        var v = visits[visitId];
        if (v && v.timerInterval) {
            clearInterval(v.timerInterval);
            v.timerInterval = null;
        }
    }

    // ═══════════════════════════════════════════════════════
    //  VISUAL UPDATES
    // ═══════════════════════════════════════════════════════

    /**
     * Update pill CSS class for visual state
     */
    function updatePillVisual(visitId, status) {
        var v = visits[visitId];
        if (!v) return;
        var pill = v.pill;

        // Remove all state classes
        pill.classList.remove('mw-mc-pill-scheduled', 'mw-mc-pill-active', 'mw-mc-pill-done');

        switch (status) {
            case 'in_progress':
            case 'prompt_before':
            case 'prompt_after':
                pill.classList.add('mw-mc-pill-active');
                break;
            case 'completed':
                pill.classList.add('mw-mc-pill-done');
                pill.innerHTML =
                    '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg> ' +
                    escHtml(v.serviceLabel);

                // Show before/after thumbnail strip below pill if photos exist
                renderPhotoStrip(visitId);
                break;
            default:
                pill.classList.add('mw-mc-pill-scheduled');
        }

        // Sync the section-header pill (non-interactive visual label in expand-detail)
        var sectionPill = document.querySelector('[data-section-pill="' + visitId + '"]');
        if (sectionPill) {
            sectionPill.classList.remove('mw-mc-pill-scheduled', 'mw-mc-pill-active', 'mw-mc-pill-done');
            switch (status) {
                case 'in_progress':
                case 'prompt_before':
                case 'prompt_after':
                    sectionPill.classList.add('mw-mc-pill-active');
                    break;
                case 'completed':
                    sectionPill.classList.add('mw-mc-pill-done');
                    sectionPill.innerHTML =
                        '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg> ' +
                        escHtml(v.serviceLabel);
                    break;
                default:
                    sectionPill.classList.add('mw-mc-pill-scheduled');
            }
        }

        // Hide route button once any visit on this card is active (crew is on-site)
        updateRouteButtonVisibility(pill);
    }

    /**
     * Hide route button when work has started on a card (pill active/done).
     * Hero cards auto-hide route via CSS (.mw-mc-card-hero .mw-mc-btn-route).
     */
    function updateRouteButtonVisibility(pill) {
        var card = pill.closest('.mw-mc-card');
        if (!card) return;
        var routeBtn = card.querySelector('.mw-mc-btn-route');
        if (!routeBtn) return;
        var actionsRow = routeBtn.closest('.mw-mc-actions');
        if (!actionsRow) return;

        // Hide if any pill on this card is active or done
        var cardPills = card.querySelectorAll('.mw-mc-pill-interactive');
        var anyActive = false;
        cardPills.forEach(function(p) {
            if (p.classList.contains('mw-mc-pill-active') || p.classList.contains('mw-mc-pill-done')) {
                anyActive = true;
            }
        });

        actionsRow.style.display = anyActive ? 'none' : '';
    }

    /**
     * Camera icon SVG (shared for placeholders)
     */
    var CAMERA_SVG = '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 01-2 2H3a2 2 0 01-2-2V8a2 2 0 012-2h4l2-3h6l2 3h4a2 2 0 012 2z"/><circle cx="12" cy="13" r="4"/></svg>';
    var CAMERA_SMALL_SVG = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 01-2 2H3a2 2 0 01-2-2V8a2 2 0 012-2h4l2-3h6l2 3h4a2 2 0 012 2z"/><circle cx="12" cy="13" r="4"/></svg>';
    var PLUS_SVG = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>';

    /**
     * Render/update a persistent before/after thumbnail strip for a visit.
     * Always shows: Before placeholder, After placeholder, Additionals row.
     * Placeholders are red (alert) if photos are required and not yet taken.
     * Tapping a placeholder triggers the camera for that category.
     * Additionals support multi-snap (tap + button repeatedly).
     */
    function renderPhotoStrip(visitId) {
        var v = visits[visitId];
        if (!v) return;

        var card = v.pill.closest('.mw-mc-card');
        if (!card) return;

        // Prefer visit-specific container (multi-visit per-section layout)
        var container = card.querySelector('.mw-mc-photo-strips[data-visit-strip="' + visitId + '"]') ||
                        card.querySelector('.mw-mc-photo-strips');
        if (!container) return;

        // Compact cards: photo strips are inside .mw-mc-expand-detail (not the main card body).
        // Only render when expanded — the expand-detail is hidden when collapsed so
        // nothing would show anyway, but skip to avoid wasted work.
        var isCompact = card.classList.contains('mw-mc-card-compact');
        var isExpanded = card.classList.contains('mw-mc-expanded');

        var isHero = card.classList.contains('mw-mc-card-hero');
        if (isCompact && !isExpanded && !isHero) {
            // Not expanded yet — renderStripsForCard() will call us again on expand
            // (Hero cards are always expanded via CSS without the mw-mc-expanded class)
            return;
        }

        // Find or create this visit's strip div
        var strip = container.querySelector('[data-strip-visit="' + visitId + '"]');
        if (!strip) {
            strip = document.createElement('div');
            strip.className = 'mw-mc-photo-strip';
            strip.setAttribute('data-strip-visit', visitId);
            container.appendChild(strip);
        }

        var required = v.requirePhotos;

        var CHECK_SVG = '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>';

        // ── Before thumbnail or placeholder ──
        var beforeHtml;
        if (v.beforeThumb && v.beforeThumb !== '__uploaded__') {
            beforeHtml =
                '<div class="mw-mc-photo-thumb" data-thumb-type="before">' +
                '  <img src="' + escHtml(v.beforeThumb) + '" alt="Before" loading="lazy">' +
                '  <span class="mw-mc-photo-thumb-label">Before</span>' +
                '</div>';
        } else if (v.beforeThumb === '__uploaded__') {
            // Upload succeeded but no URL returned — show ✓ tile
            beforeHtml =
                '<div class="mw-mc-photo-placeholder mw-mc-placeholder-done" data-thumb-type="before">' +
                '  ' + CHECK_SVG +
                '  <span>Before</span>' +
                '</div>';
        } else {
            // No before photo yet — show placeholder (tappable)
            beforeHtml =
                '<div class="mw-mc-photo-placeholder' + (required ? ' mw-mc-placeholder-required' : '') + '" data-thumb-type="before" data-visit-id="' + visitId + '" data-category="before">' +
                '  ' + CAMERA_SVG +
                '  <span>Before</span>' +
                '</div>';
        }

        // ── After thumbnail or placeholder ──
        var afterHtml;
        if (v.afterThumb && v.afterThumb !== '__uploaded__') {
            afterHtml =
                '<div class="mw-mc-photo-thumb" data-thumb-type="after">' +
                '  <img src="' + escHtml(v.afterThumb) + '" alt="After" loading="lazy">' +
                '  <span class="mw-mc-photo-thumb-label">After</span>' +
                '</div>';
        } else if (v.afterThumb === '__uploaded__') {
            afterHtml =
                '<div class="mw-mc-photo-placeholder mw-mc-placeholder-done" data-thumb-type="after">' +
                '  ' + CHECK_SVG +
                '  <span>After</span>' +
                '</div>';
        } else {
            // No after photo yet — show placeholder (tappable)
            afterHtml =
                '<div class="mw-mc-photo-placeholder' + (required ? ' mw-mc-placeholder-required' : '') + '" data-thumb-type="after" data-visit-id="' + visitId + '" data-category="after">' +
                '  ' + CAMERA_SVG +
                '  <span>After</span>' +
                '</div>';
        }

        // ── Additionals row (thumbnails + button, always shown) ──
        var addThumbs = v.additionalThumbs || [];
        var addHtml = '<div class="mw-mc-photo-additionals" data-visit-id="' + visitId + '">';
        for (var ai = 0; ai < addThumbs.length; ai++) {
            addHtml +=
                '<div class="mw-mc-photo-thumb mw-mc-photo-thumb-additional">' +
                '  <img src="' + escHtml(addThumbs[ai]) + '" alt="Photo ' + (ai + 1) + '" loading="lazy">' +
                '  <span class="mw-mc-photo-thumb-label">#' + (ai + 1) + '</span>' +
                '</div>';
        }
        addHtml +=
            '<button class="mw-mc-add-photo-btn" data-visit-id="' + visitId + '" data-category="additional" type="button">' +
            '  ' + PLUS_SVG +
            '  <span>' + (addThumbs.length === 0 ? 'Additionals' : '+') + '</span>' +
            '</button>';
        addHtml += '</div>';

        strip.innerHTML = beforeHtml + afterHtml + addHtml;

        // Wire placeholders → camera
        strip.querySelectorAll('.mw-mc-photo-placeholder').forEach(function(ph) {
            ph.addEventListener('click', function(e) {
                e.stopPropagation();
                var cat = ph.dataset.category;
                triggerCamera(visitId, cat);
            });
        });

        // Wire add-photo button → camera (additional)
        var addBtn = strip.querySelector('.mw-mc-add-photo-btn');
        if (addBtn) {
            addBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                triggerCamera(visitId, 'additional');
            });
        }

        // Wire tap-to-expand on real thumbnails
        strip.querySelectorAll('.mw-mc-photo-thumb img').forEach(function(img) {
            img.addEventListener('click', function(e) {
                e.stopPropagation();
                openPhotoLightbox(img.src, img.alt);
            });
        });

        if (MW_PHOTO_DEBUG) {
            console.log('[PillWorkflow:DEBUG] Photo strip rendered for visit ' + visitId +
                ' (before: ' + (v.beforeThumb ? 'yes' : 'no') +
                ', after: ' + (v.afterThumb ? 'yes' : 'no') +
                ', additionals: ' + addThumbs.length + ')');
        }
    }

    /**
     * Show a brief thumbnail confirmation in the drawer, then add to
     * persistent strip and run callback.
     */
    function showThumbConfirmation(card, thumbUrl, label, visitId, callback) {
        var drawer = card.querySelector('.mw-mc-pill-drawer');

        // Always add to persistent strip immediately
        if (visitId) {
            renderPhotoStrip(visitId);
        }

        if (!drawer) {
            if (callback) callback();
            return;
        }

        drawer.innerHTML =
            '<div class="mw-mc-thumb-confirm">' +
            '  <img src="' + escHtml(thumbUrl) + '" alt="' + escHtml(label) + '">' +
            '  <span class="mw-mc-thumb-confirm-label">' + escHtml(label) + ' saved</span>' +
            '</div>';
        drawer.style.display = 'block';

        // Auto-dismiss after 1.5s
        setTimeout(function() {
            if (callback) {
                callback();
            } else {
                // Just hide it for 'during' photos
                drawer.style.display = 'none';
                drawer.innerHTML = '';
            }
        }, 1500);
    }

    /**
     * Check if all visits on a stop are completed → mark card as completed
     */
    function checkStopComplete(card) {
        if (!card) return;
        var pills = card.querySelectorAll('.mw-mc-pill-interactive');
        var allDone = true;

        pills.forEach(function(p) {
            var vid = parseInt(p.dataset.visitId, 10);
            if (visits[vid] && visits[vid].status !== 'completed') {
                allDone = false;
            }
        });

        if (allDone && pills.length > 0) {
            card.classList.add('mw-mc-card-completed');
            // Update progress bar in topbar
            updateProgressBar();
        }
    }

    /**
     * Update the top bar progress bar when a stop completes
     */
    function updateProgressBar() {
        var allCards = document.querySelectorAll('.mw-mc-card');
        var total = allCards.length;
        var completed = document.querySelectorAll('.mw-mc-card-completed').length;

        var fill = document.querySelector('.mw-mc-topbar-progress-fill');
        var text = document.querySelector('.mw-mc-topbar-progress-text');
        if (fill && total > 0) {
            fill.style.width = Math.round((completed / total) * 100) + '%';
        }
        if (text) {
            text.textContent = completed + '/' + total;
        }
    }

    /**
     * Flash feedback text on a pill briefly
     */
    function flashPillFeedback(visitId, msg) {
        var pill = visits[visitId] ? visits[visitId].pill : null;
        if (!pill) return;
        pill.classList.add('mw-mc-pill-feedback');
        setTimeout(function() {
            pill.classList.remove('mw-mc-pill-feedback');
        }, 1500);
    }

    // ═══════════════════════════════════════════════════════
    //  DRAWER HELPERS
    // ═══════════════════════════════════════════════════════

    function closeDrawer() {
        if (activeDrawer) {
            activeDrawer.style.display = 'none';
            activeDrawer.innerHTML = '';
        }
        activeDrawer = null;
        activeDrawerVisitId = null;
    }

    // ═══════════════════════════════════════════════════════
    //  GPS HELPER
    // ═══════════════════════════════════════════════════════

    function getGps(callback) {
        if (cachedGps) {
            callback(cachedGps.lat, cachedGps.lng);
            return;
        }
        if (!navigator.geolocation) {
            callback(null, null);
            return;
        }
        navigator.geolocation.getCurrentPosition(
            function(pos) {
                cachedGps = {
                    lat: pos.coords.latitude,
                    lng: pos.coords.longitude,
                    accuracy: pos.coords.accuracy
                };
                callback(cachedGps.lat, cachedGps.lng);
            },
            function() {
                callback(null, null);
            },
            { enableHighAccuracy: true, timeout: 5000, maximumAge: 60000 }
        );
    }

    // ═══════════════════════════════════════════════════════
    //  OFFLINE PHOTO QUEUE  (IndexedDB)
    //
    //  Photos captured while offline (or when upload fails due to poor
    //  signal / app backgrounding) are stored as ArrayBuffers in IndexedDB.
    //  They are automatically retried when the network comes back online.
    //
    //  Debug: localStorage.setItem('mw_photo_debug','1') then reload.
    // ═══════════════════════════════════════════════════════

    /**
     * Debug flag — enable extra console logging for photo capture + upload.
     * Set: localStorage.setItem('mw_photo_debug', '1') then reload.
     * Clear: localStorage.removeItem('mw_photo_debug') then reload.
     */
    var MW_PHOTO_DEBUG = (function() {
        try { return localStorage.getItem('mw_photo_debug') === '1'; } catch (e) { return false; }
    })();

    var photoQueueDb    = null;
    var queueProcessing = false;

    // Open the IndexedDB photo queue immediately (async, doesn't block rendering)
    (function openPhotoQueueDb() {
        try {
            var req = indexedDB.open('mw_photo_queue_v1', 1);

            req.onupgradeneeded = function(e) {
                var db = e.target.result;
                if (!db.objectStoreNames.contains('queue')) {
                    var store = db.createObjectStore('queue', { keyPath: 'id', autoIncrement: true });
                    store.createIndex('status',  'status',  { unique: false });
                    store.createIndex('visitId', 'visitId', { unique: false });
                }
            };

            req.onsuccess = function(e) {
                photoQueueDb = e.target.result;
                if (MW_PHOTO_DEBUG) console.log('[PillWorkflow] Photo queue IDB ready');
                // Attempt to upload any photos queued during a previous session
                processPhotoQueue();
                // Show queue badges for any pending/failed items on the visible cards
                refreshQueueBadges();
            };

            req.onerror = function() {
                console.warn('[PillWorkflow] Photo queue IDB failed to open — offline queuing disabled');
            };
        } catch (err) {
            console.warn('[PillWorkflow] Photo queue IDB unavailable:', err && err.message);
        }
    })();

    // Retry queued photos as soon as network is restored
    window.addEventListener('online', function() {
        console.log('[PillWorkflow] Network restored — processing offline photo queue');
        processPhotoQueue();
        refreshQueueBadges();
    });

    /**
     * Read IDB queue counts (per visit) and render amber/red badges on each strip.
     * Runs on page load and after any queue change.
     */
    function refreshQueueBadges() {
        if (!photoQueueDb) return;
        try {
            var tx  = photoQueueDb.transaction('queue', 'readonly');
            var req = tx.objectStore('queue').getAll();

            req.onsuccess = function() {
                var all = req.result || [];
                // Aggregate by visitId
                var countsByVisit = {}; // visitId → { pending: N, failed: N }
                all.forEach(function(item) {
                    if (!countsByVisit[item.visitId]) {
                        countsByVisit[item.visitId] = { pending: 0, failed: 0 };
                    }
                    if (item.status === 'pending' || item.status === 'uploading') {
                        countsByVisit[item.visitId].pending++;
                    } else if (item.status === 'failed') {
                        countsByVisit[item.visitId].failed++;
                    }
                });

                // Update badge in each visible strip
                for (var vid in visits) {
                    if (!visits.hasOwnProperty(vid)) continue;
                    var numVid = parseInt(vid, 10);
                    var counts = countsByVisit[numVid] || { pending: 0, failed: 0 };
                    renderQueueBadge(numVid, counts.pending, counts.failed);
                }
            };
        } catch (err) {
            console.warn('[PillWorkflow] refreshQueueBadges error:', err && err.message);
        }
    }

    function renderQueueBadge(visitId, pendingCount, failedCount) {
        var v = visits[visitId];
        if (!v || !v.pill) return;
        var card = v.pill.closest('.mw-mc-card');
        if (!card) return;
        var strip = card.querySelector('[data-strip-visit="' + visitId + '"]');
        if (!strip) return;

        // Remove any existing badge row
        var existing = strip.querySelector('.mw-mc-queue-badge-row');
        if (existing) existing.parentNode.removeChild(existing);

        if (pendingCount === 0 && failedCount === 0) return;

        var row  = document.createElement('div');
        row.className = 'mw-mc-queue-badge-row';

        if (pendingCount > 0) {
            var badge = document.createElement('span');
            badge.className = 'mw-mc-queue-badge';
            badge.textContent = '\u23F3 ' + pendingCount +
                ' photo' + (pendingCount > 1 ? 's' : '') + ' queued';
            row.appendChild(badge);
        }
        if (failedCount > 0) {
            var failBadge = document.createElement('span');
            failBadge.className = 'mw-mc-queue-badge mw-mc-queue-failed';
            failBadge.textContent = '\u26A0 ' + failedCount +
                ' photo' + (failedCount > 1 ? 's' : '') + ' failed to upload';
            row.appendChild(failBadge);
        }

        strip.appendChild(row);
    }

    /**
     * Persist a captured File into the offline queue.
     * Converts to ArrayBuffer (survives page reloads / session restarts).
     * Calls callback(queueId) on success, callback(null) on failure.
     */
    function saveToPhotoQueue(visitId, file, category, callback) {
        if (!photoQueueDb) {
            if (MW_PHOTO_DEBUG) console.log('[PillWorkflow] IDB unavailable — cannot queue photo');
            if (callback) callback(null);
            return;
        }

        // 1e. Check available storage before writing to IDB.
        // If less than 3 MB headroom remains after this file, warn the user.
        var doSave = function() {
            var reader = new FileReader();

            reader.onload = function(ev) {
                var item = {
                    visitId:     visitId,
                    category:    category,
                    blobData:    ev.target.result,        // ArrayBuffer
                    filename:    file.name    || 'photo.jpg',
                    mimeType:    file.type    || 'image/jpeg',
                    createdAt:   Date.now(),
                    status:      'pending',
                    retries:     0,
                    lastAttempt: 0,
                    csrf:        state.csrf,
                    gpsLat:      cachedGps ? cachedGps.lat : null,
                    gpsLng:      cachedGps ? cachedGps.lng : null,
                    powStamp:    (category === 'before' || category === 'after')
                };

                try {
                    var tx  = photoQueueDb.transaction('queue', 'readwrite');
                    var put = tx.objectStore('queue').add(item);

                    put.onsuccess = function() {
                        console.log('[PillWorkflow] Photo queued id=' + put.result +
                            ' visit=' + visitId + ' cat=' + category);
                        if (callback) callback(put.result);
                    };
                    put.onerror = function() {
                        console.warn('[PillWorkflow] Failed to write photo to IDB queue');
                        if (callback) callback(null);
                    };
                } catch (err) {
                    console.warn('[PillWorkflow] IDB write error:', err && err.message);
                    if (callback) callback(null);
                }
            };

            reader.onerror = function() {
                console.warn('[PillWorkflow] FileReader error while queueing photo');
                if (callback) callback(null);
            };

            reader.readAsArrayBuffer(file);
        };

        if (navigator.storage && navigator.storage.estimate) {
            navigator.storage.estimate().then(function(est) {
                var free = (est.quota || 0) - (est.usage || 0);
                var HEADROOM = 3 * 1024 * 1024; // 3 MB buffer
                if (free > 0 && (file.size + HEADROOM) > free) {
                    console.warn('[PillWorkflow] Storage quota near limit — free=' + free +
                        ' needed=' + (file.size + HEADROOM));
                    showToast('Device storage is full. Free space and try again.');
                    if (callback) callback(null);
                } else {
                    doSave();
                }
            }).catch(function() {
                // estimate() failed — proceed anyway
                doSave();
            });
        } else {
            doSave();
        }
    }

    /**
     * Upload all pending items in the offline queue.
     * Only runs when online; prevents concurrent processing batches.
     */
    function processPhotoQueue() {
        if (!photoQueueDb || queueProcessing) return;
        if (!navigator.onLine) {
            if (MW_PHOTO_DEBUG) console.log('[PillWorkflow] Offline — skipping queue processing');
            return;
        }

        queueProcessing = true;

        try {
            var tx  = photoQueueDb.transaction('queue', 'readonly');
            var req = tx.objectStore('queue').index('status').getAll('pending');

            req.onsuccess = function() {
                var items = req.result || [];
                if (!items.length) { queueProcessing = false; return; }
                console.log('[PillWorkflow] Photo queue: processing ' + items.length + ' pending item(s)');
                processQueueItems(items, 0, function() { queueProcessing = false; });
            };

            req.onerror = function() { queueProcessing = false; };
        } catch (err) {
            queueProcessing = false;
            console.warn('[PillWorkflow] Queue read error:', err && err.message);
        }
    }

    /**
     * Recursively process each queued item in order.
     */
    function processQueueItems(items, idx, done) {
        if (idx >= items.length) { done(); return; }

        var item       = items[idx];
        var MAX_RETRY  = 3;

        if (item.retries >= MAX_RETRY) {
            console.warn('[PillWorkflow] Dropping queued photo after ' + MAX_RETRY +
                ' retries: visit=' + item.visitId + ' cat=' + item.category);
            updateQueueItemStatus(item.id, { status: 'failed' }, function() {
                refreshQueueBadges();
                processQueueItems(items, idx + 1, done);
            });
            return;
        }

        updateQueueItemStatus(item.id, { status: 'uploading', lastAttempt: Date.now() }, function() {
            var blob = new Blob([item.blobData], { type: item.mimeType });
            var file = new File([blob], item.filename, { type: item.mimeType });

            var fd = new FormData();
            fd.append('files[]',      file);
            fd.append('csrf_token',   item.csrf || state.csrf);
            fd.append('context_type', 'job_visit');
            fd.append('context_id',   String(item.visitId));
            fd.append('category',     item.category);
            fd.append('visibility',   'client_visible');
            if (item.gpsLat !== null && item.gpsLng !== null) {
                fd.append('gps_lat', String(item.gpsLat));
                fd.append('gps_lng', String(item.gpsLng));
            }
            if (item.powStamp) fd.append('pow_stamp', '1');

            if (MW_PHOTO_DEBUG) {
                console.log('[PillWorkflow:DEBUG] Uploading queued photo id=' + item.id +
                    ' visit=' + item.visitId + ' cat=' + item.category);
            }

            fetch('/crm/api/media-upload.php', { method: 'POST', body: fd })
            .then(function(r) {
                var ct = r.headers.get('content-type') || '';
                if (!ct.includes('application/json') && !ct.includes('text/json')) {
                    return r.text().then(function(t) {
                        throw new Error('Non-JSON: ' + r.status + ' ' + t.slice(0, 80));
                    });
                }
                return r.json();
            })
            .then(function(data) {
                if (data.success && data.total_uploaded > 0) {
                    updateQueueItemStatus(item.id, { status: 'done' }, function() {
                        console.log('[PillWorkflow] Queued photo uploaded: visit=' +
                            item.visitId + ' cat=' + item.category);
                        refreshQueueBadges();

                        // Refresh UI strip so thumbnail appears if visit is still on screen
                        if (visits[item.visitId]) {
                            var res      = data.results && data.results[0];
                            var thumbUrl = (res && (res.thumb_url || res.file_path)) || '__uploaded__';

                            if (item.category === 'before') {
                                visits[item.visitId].beforeThumb = thumbUrl;
                            } else if (item.category === 'after') {
                                visits[item.visitId].afterThumb = thumbUrl;
                            } else if (item.category === 'additional') {
                                if (!visits[item.visitId].additionalThumbs) {
                                    visits[item.visitId].additionalThumbs = [];
                                }
                                if (thumbUrl !== '__uploaded__') {
                                    visits[item.visitId].additionalThumbs.push(thumbUrl);
                                }
                            }
                            renderPhotoStrip(item.visitId);
                        }

                        processQueueItems(items, idx + 1, done);
                    });
                } else {
                    var errMsg = (data.results && data.results[0] && data.results[0].errors)
                        ? data.results[0].errors.join(', ')
                        : (data.error || 'Upload rejected');
                    console.warn('[PillWorkflow] Queue upload rejected:', errMsg);
                    updateQueueItemStatus(item.id, { status: 'pending', retries: item.retries + 1 }, function() {
                        processQueueItems(items, idx + 1, done);
                    });
                }
            })
            .catch(function(err) {
                console.warn('[PillWorkflow] Queue upload network error:', err && err.message);
                updateQueueItemStatus(item.id, { status: 'pending', retries: item.retries + 1 }, function() {
                    processQueueItems(items, idx + 1, done);
                });
            });
        });
    }

    /**
     * Read-modify-write a single queue item in IDB.
     */
    function updateQueueItemStatus(id, updates, callback) {
        if (!photoQueueDb) { if (callback) callback(); return; }

        try {
            var tx    = photoQueueDb.transaction('queue', 'readwrite');
            var store = tx.objectStore('queue');
            var getR  = store.get(id);

            getR.onsuccess = function() {
                var item = getR.result;
                if (!item) { if (callback) callback(); return; }

                var keys = Object.keys(updates);
                for (var ki = 0; ki < keys.length; ki++) {
                    item[keys[ki]] = updates[keys[ki]];
                }

                var putR = store.put(item);
                putR.onsuccess = function() { if (callback) callback(); };
                putR.onerror   = function() { if (callback) callback(); };
            };

            getR.onerror = function() { if (callback) callback(); };
        } catch (err) {
            if (callback) callback();
        }
    }

    // ═══════════════════════════════════════════════════════
    //  UTILITY
    // ═══════════════════════════════════════════════════════

    function escHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    /**
     * Simple toast notification (top of scroll area)
     */
    function showToast(msg) {
        var existing = document.querySelector('.mw-mc-toast');
        if (existing) existing.remove();

        var toast = document.createElement('div');
        toast.className = 'mw-mc-toast';
        toast.textContent = msg;
        toast.style.cssText =
            'position: fixed; top: 80px; left: 50%; transform: translateX(-50%); ' +
            'background: #333; color: #fff; padding: 10px 20px; border-radius: 8px; ' +
            'font-size: 0.82rem; font-weight: 600; z-index: 9999; ' +
            'box-shadow: 0 4px 12px rgba(0,0,0,0.2); pointer-events: none; ' +
            'opacity: 0; transition: opacity 0.3s ease;';

        document.body.appendChild(toast);

        // Fade in
        requestAnimationFrame(function() {
            toast.style.opacity = '1';
        });

        // Fade out and remove after 2.5s
        setTimeout(function() {
            toast.style.opacity = '0';
            setTimeout(function() {
                if (toast.parentNode) toast.parentNode.removeChild(toast);
            }, 300);
        }, 2500);
    }

    // ═══════════════════════════════════════════════════════
    //  FIELD OBSERVATION MODAL
    // ═══════════════════════════════════════════════════════

    var observationTypes = [
        { value: 'ph_test',           label: 'pH Test' },
        { value: 'soil_condition',    label: 'Soil Condition' },
        { value: 'pest_damage',       label: 'Pest Damage' },
        { value: 'weed_growth',       label: 'Weed Growth' },
        { value: 'drainage_issue',    label: 'Drainage Issue' },
        { value: 'lawn_disease',      label: 'Lawn Disease' },
        { value: 'tree_hazard',       label: 'Tree Hazard' },
        { value: 'hedge_overgrowth',  label: 'Hedge Overgrowth' },
        { value: 'hardscape_damage',  label: 'Hardscape Damage' },
        { value: 'other',             label: 'Other' }
    ];

    /**
     * Open a full-screen observation form for the given visit
     */
    function openObservationModal(visitId) {
        // Remove any existing modal
        var existing = document.getElementById('mw-obs-modal');
        if (existing) existing.remove();

        var optionsHtml = '<option value="">Select type...</option>';
        observationTypes.forEach(function(t) {
            optionsHtml += '<option value="' + t.value + '">' + escHtml(t.label) + '</option>';
        });

        var modal = document.createElement('div');
        modal.id = 'mw-obs-modal';
        modal.className = 'mw-obs-modal';
        modal.innerHTML =
            '<div class="mw-obs-modal-content">' +
            '  <div class="mw-obs-modal-header">' +
            '    <h3>Log Observation</h3>' +
            '    <button class="mw-obs-modal-close" data-action="close">&times;</button>' +
            '  </div>' +
            '  <div class="mw-obs-modal-body">' +
            '    <div class="mw-obs-field">' +
            '      <label>Type *</label>' +
            '      <select id="mw-obs-type">' + optionsHtml + '</select>' +
            '    </div>' +
            '    <div class="mw-obs-field">' +
            '      <label>Value / Reading</label>' +
            '      <input type="text" id="mw-obs-value" placeholder="e.g., pH 5.2, severity: high">' +
            '    </div>' +
            '    <div class="mw-obs-field">' +
            '      <label>Notes</label>' +
            '      <textarea id="mw-obs-notes" rows="3" placeholder="Describe what you observed..."></textarea>' +
            '    </div>' +
            '    <div class="mw-obs-field">' +
            '      <label>Photo (optional)</label>' +
            '      <input type="file" id="mw-obs-photo" accept="image/*" capture="environment">' +
            '    </div>' +
            '    <div id="mw-obs-suggestion" class="mw-obs-suggestion" style="display:none;"></div>' +
            '    <div id="mw-obs-error" class="mw-obs-error" style="display:none;"></div>' +
            '  </div>' +
            '  <div class="mw-obs-modal-footer">' +
            '    <button class="mw-obs-btn mw-obs-btn-cancel" data-action="close">Cancel</button>' +
            '    <button class="mw-obs-btn mw-obs-btn-submit" data-action="submit">Save Observation</button>' +
            '  </div>' +
            '</div>';

        document.body.appendChild(modal);

        // Prevent body scroll
        document.body.style.overflow = 'hidden';

        // Close handlers
        modal.querySelectorAll('[data-action="close"]').forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.stopPropagation();
                closeObservationModal();
            });
        });

        // Auto-suggest product when type changes
        var typeSelect = document.getElementById('mw-obs-type');
        typeSelect.addEventListener('change', function() {
            loadObservationSuggestion(typeSelect.value);
        });

        // Submit handler
        modal.querySelector('[data-action="submit"]').addEventListener('click', function(e) {
            e.stopPropagation();
            submitObservation(visitId);
        });

        // Focus the type select
        setTimeout(function() { typeSelect.focus(); }, 100);
    }

    function closeObservationModal() {
        var modal = document.getElementById('mw-obs-modal');
        if (modal) modal.remove();
        document.body.style.overflow = '';
    }

    /**
     * Load the product suggestion for a given observation type from rules API
     */
    function loadObservationSuggestion(obsType) {
        var sugBox = document.getElementById('mw-obs-suggestion');
        if (!sugBox) return;
        if (!obsType) {
            sugBox.style.display = 'none';
            return;
        }

        fetch('/crm/api/field-observations.php?action=get-rules')
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data.success || !data.rules || !data.rules.length) {
                    sugBox.style.display = 'none';
                    return;
                }
                var match = null;
                data.rules.forEach(function(rule) {
                    if (rule.observation_type === obsType && !match) {
                        match = rule;
                    }
                });
                if (match) {
                    sugBox.innerHTML =
                        '<strong>Suggested product:</strong> ' +
                        escHtml(match.product_name || 'Unknown') +
                        (match.product_price ? ' ($' + parseFloat(match.product_price).toFixed(2) + ')' : '') +
                        '<br><small>' + (match.auto_send === '1' || match.auto_send === 1
                            ? 'Will auto-send recommendation email'
                            : 'Will queue for admin review') + '</small>';
                    sugBox.style.display = 'block';
                } else {
                    sugBox.style.display = 'none';
                }
            })
            .catch(function() {
                sugBox.style.display = 'none';
            });
    }

    /**
     * Submit the observation form
     */
    function submitObservation(visitId) {
        var obsType  = document.getElementById('mw-obs-type').value;
        var obsValue = document.getElementById('mw-obs-value').value.trim();
        var notes    = document.getElementById('mw-obs-notes').value.trim();
        var photoInput = document.getElementById('mw-obs-photo');
        var errBox   = document.getElementById('mw-obs-error');

        if (!obsType) {
            errBox.textContent = 'Please select an observation type.';
            errBox.style.display = 'block';
            return;
        }

        var submitBtn = document.querySelector('.mw-obs-btn-submit');
        submitBtn.disabled = true;
        submitBtn.textContent = 'Saving...';
        errBox.style.display = 'none';

        // If there's a photo, upload it first
        var photoFile = photoInput && photoInput.files && photoInput.files[0];
        if (photoFile) {
            uploadObservationPhoto(photoFile, visitId, function(mediaId) {
                sendObservation(visitId, obsType, obsValue, notes, mediaId, submitBtn, errBox);
            }, function(err) {
                submitBtn.disabled = false;
                submitBtn.textContent = 'Save Observation';
                errBox.textContent = 'Photo upload failed: ' + err;
                errBox.style.display = 'block';
            });
        } else {
            sendObservation(visitId, obsType, obsValue, notes, null, submitBtn, errBox);
        }
    }

    /**
     * Upload observation photo via existing media-upload endpoint
     */
    function uploadObservationPhoto(file, visitId, onSuccess, onError) {
        var formData = new FormData();
        formData.append('files[]', file);   // must match the field name expected by upload.php
        formData.append('context_type', 'field_observation');
        formData.append('context_id', visitId);
        formData.append('category', 'observation');
        formData.append('csrf_token', state.csrf);

        fetch('/crm/api/media-upload.php', {
            method: 'POST',
            body: formData
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success && data.results && data.results[0] && data.results[0].media_id) {
                onSuccess(data.results[0].media_id);
            } else {
                onError(data.error || 'Upload failed');
            }
        })
        .catch(function(err) {
            onError('Network error');
        });
    }

    /**
     * POST the observation to the API
     */
    function sendObservation(visitId, obsType, obsValue, notes, photoMediaId, submitBtn, errBox) {
        fetch('/crm/api/field-observations.php?action=create', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                visit_id: visitId,
                observation_type: obsType,
                observation_value: obsValue || null,
                notes: notes || null,
                photo_media_id: photoMediaId,
                csrf_token: state.csrf
            })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                closeObservationModal();
                var typeLabel = '';
                observationTypes.forEach(function(t) {
                    if (t.value === obsType) typeLabel = t.label;
                });
                showToast(typeLabel + ' observation logged');

                // Update the pill to show observation badge
                if (visits[visitId]) {
                    visits[visitId].observationCount = (visits[visitId].observationCount || 0) + 1;
                }
            } else {
                submitBtn.disabled = false;
                submitBtn.textContent = 'Save Observation';
                errBox.textContent = data.error || 'Failed to save observation';
                errBox.style.display = 'block';
            }
        })
        .catch(function(err) {
            submitBtn.disabled = false;
            submitBtn.textContent = 'Save Observation';
            errBox.textContent = 'Network error. Check your connection.';
            errBox.style.display = 'block';
        });
    }

    // ═══════════════════════════════════════════════════════
    //  PHOTO LIGHTBOX
    // ═══════════════════════════════════════════════════════

    /**
     * Open a full-screen lightbox showing the tapped photo.
     * Tap anywhere to dismiss. Swipe-down also closes on mobile.
     */
    function openPhotoLightbox(src, altText) {
        // Reuse or create the overlay element
        var lb = document.getElementById('mwPhotoLightbox');
        if (!lb) {
            lb = document.createElement('div');
            lb.id = 'mwPhotoLightbox';
            lb.className = 'mw-photo-lightbox';
            lb.innerHTML =
                '<div class="mw-photo-lightbox-scrim"></div>' +
                '<div class="mw-photo-lightbox-inner">' +
                '  <button class="mw-photo-lightbox-close" aria-label="Close">' +
                '    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>' +
                '  </button>' +
                '  <img class="mw-photo-lightbox-img" src="" alt="">' +
                '  <span class="mw-photo-lightbox-label"></span>' +
                '</div>';
            document.body.appendChild(lb);

            // Close on scrim click, close button, or Escape
            lb.querySelector('.mw-photo-lightbox-scrim').addEventListener('click', closePhotoLightbox);
            lb.querySelector('.mw-photo-lightbox-close').addEventListener('click', closePhotoLightbox);
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') closePhotoLightbox();
            });

            // Swipe-down to close
            var touchStartY = 0;
            lb.addEventListener('touchstart', function(e) { touchStartY = e.touches[0].clientY; }, { passive: true });
            lb.addEventListener('touchend', function(e) {
                if (e.changedTouches[0].clientY - touchStartY > 60) closePhotoLightbox();
            }, { passive: true });
        }

        lb.querySelector('.mw-photo-lightbox-img').src  = src;
        lb.querySelector('.mw-photo-lightbox-img').alt  = altText || '';
        lb.querySelector('.mw-photo-lightbox-label').textContent = altText || '';
        lb.classList.add('mw-lightbox-open');
        document.body.style.overflow = 'hidden';
    }

    function closePhotoLightbox() {
        var lb = document.getElementById('mwPhotoLightbox');
        if (lb) lb.classList.remove('mw-lightbox-open');
        document.body.style.overflow = '';
    }

    // ═══════════════════════════════════════════════════════
    //  CARD ACTION FOOTER (Clock In / Timer + Complete Job)
    // ═══════════════════════════════════════════════════════

    /**
     * Per-visit section footer helpers.
     * These footers live inside .mw-mc-expand-detail and become visible when a
     * compact card is expanded. Each footer is scoped to a single visit.
     */

    /** Show timer, hide clock-in for a specific visit's section footer */
    function pvSetTiming(visitId) {
        var timerEl    = document.querySelector('[data-pv-timer="' + visitId + '"]');
        var clockInBtn = document.querySelector('[data-pv-clockin="' + visitId + '"]');
        if (timerEl)    timerEl.style.display    = 'flex';
        if (clockInBtn) clockInBtn.style.display = 'none';
    }

    /** Show clock-in, hide timer for a specific visit's section footer */
    function pvSetIdle(visitId) {
        var timerEl    = document.querySelector('[data-pv-timer="' + visitId + '"]');
        var clockInBtn = document.querySelector('[data-pv-clockin="' + visitId + '"]');
        if (timerEl)    timerEl.style.display    = 'none';
        if (clockInBtn) clockInBtn.style.display = 'flex';
    }

    /** Hide the per-visit section footer entirely (visit completed) */
    function pvSetDone(visitId) {
        var footer = document.querySelector('[data-pv-footer="' + visitId + '"]');
        if (footer) footer.style.display = 'none';
    }

    /**
     * Initialise per-visit section footers in expand-detail.
     * Wires Clock In and Complete Job buttons for each visit section.
     */
    function initPerVisitFooters() {
        document.querySelectorAll('[data-pv-footer]').forEach(function(footer) {
            var visitId = parseInt(footer.dataset.pvFooter, 10);
            if (!visitId) return;

            var clockInBtn  = footer.querySelector('[data-pv-clockin]');
            var completeBtn = footer.querySelector('[data-pv-complete]');
            var timerEl     = footer.querySelector('[data-pv-timer]');
            var v           = visits[visitId];

            // Set initial state
            if (v && v.status === 'in_progress' && v.startTime) {
                if (timerEl)    timerEl.style.display    = 'flex';
                if (clockInBtn) clockInBtn.style.display = 'none';
            } else if (v && v.status === 'scheduled') {
                if (timerEl)    timerEl.style.display    = 'none';
                if (clockInBtn) clockInBtn.style.display = 'flex';
            }

            // Clock In button — clocks in for this specific visit
            if (clockInBtn) {
                clockInBtn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    clockInBtn.disabled = true;
                    clockInBtn.innerHTML = '<span>Starting…</span>';
                    getGps(function(lat, lng) {
                        fetch('/crm/api/job-timer.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ action: 'start', visit_id: visitId, lat: lat, lng: lng })
                        })
                        .then(function(r) { return r.json(); })
                        .then(function(data) {
                            if (data.success) {
                                if (visits[visitId]) {
                                    visits[visitId].entryId    = data.entry_id;
                                    visits[visitId].startTime  = new Date();
                                    visits[visitId].status     = 'in_progress';
                                    if (data.tracking_level) visits[visitId].trackingLevel = data.tracking_level;
                                    if (data.require_photos !== undefined) visits[visitId].requirePhotos = data.require_photos;
                                }
                                if (window.MwTimeClock) {
                                    window.MwTimeClock.notifyJobTimerStarted();
                                    if (data.tracking_level === 'heightened') window.MwTimeClock.setTrackingInterval('heightened');
                                }
                                updatePillVisual(visitId, 'in_progress');
                                if (visits[visitId]) startPillTimer(visitId);
                                // Sync both shared footer and per-visit section footer
                                var card = footer.closest('.mw-mc-card');
                                var stopId = card ? parseInt(card.dataset.stopId, 10) : 0;
                                if (stopId) footerSetTiming(stopId, visitId);
                                pvSetTiming(visitId);
                            } else {
                                showToast('Could not start timer: ' + (data.error || data.message || 'Unknown error'));
                                clockInBtn.disabled = false;
                                clockInBtn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="5 3 19 12 5 21 5 3"/></svg> Clock In';
                            }
                        })
                        .catch(function() {
                            showToast('Network error. Check your connection.');
                            clockInBtn.disabled = false;
                            clockInBtn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="5 3 19 12 5 21 5 3"/></svg> Clock In';
                        });
                    });
                });
            }

            // Complete Job button — completes this specific visit
            if (completeBtn) {
                completeBtn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    completeBtn.disabled = true;
                    completeBtn.innerHTML = '<span>Completing…</span>';

                    var v = visits[visitId];
                    var isRunning = v && v.status === 'in_progress' && v.startTime;

                    if (isRunning) {
                        // Stop the timer → automatically marks visit complete
                        clockOut(visitId);
                        setTimeout(function() { pvSetDone(visitId); }, 600);
                    } else {
                        // No active timer → direct complete via end_visit
                        getGps(function(lat, lng) {
                            fetch('/crm/api/pow-actions.php', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({
                                    action: 'end_visit',
                                    visit_id: visitId,
                                    lat: lat, lng: lng,
                                    csrf_token: state.csrf
                                })
                            })
                            .then(function(r) { return r.json(); })
                            .then(function(data) {
                                if (data.success) {
                                    if (visits[visitId]) {
                                        visits[visitId].status = 'completed';
                                        updatePillVisual(visitId, 'completed');
                                    }
                                    pvSetDone(visitId);
                                    var card = footer.closest('.mw-mc-card');
                                    if (card) checkStopComplete(card);
                                    showToast('Job completed');
                                } else {
                                    showToast('Could not complete: ' + (data.error || 'Unknown error'));
                                    completeBtn.disabled = false;
                                    completeBtn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> Complete Job';
                                }
                            })
                            .catch(function() {
                                showToast('Network error. Check your connection.');
                                completeBtn.disabled = false;
                                completeBtn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> Complete Job';
                            });
                        });
                    }
                });
            }
        });
    }

    /**
     * Initialise all card footer elements after pill registration is complete.
     * Each footer shows either:
     *   - A running timer display + styled "Complete Job" button (is-timing state)
     *   - A "Clock In" button + default "Complete Job" button (no active timer)
     */
    function initCardFooters() {
        var footers = document.querySelectorAll('.mw-mc-card-footer');
        footers.forEach(function(footer) {
            var stopId = parseInt(footer.dataset.footerStop, 10);
            var primaryVisitId = parseInt(footer.dataset.footerVisit, 10);
            if (!stopId) return;

            var clockInBtn  = footer.querySelector('[data-footer-clockin]');
            var completeBtn = footer.querySelector('[data-footer-complete]');
            var timerEl     = footer.querySelector('[data-footer-timer]');

            // Determine the schedulable visit to use for clock-in
            // (the primary visit from the card, or the first scheduled one)
            var clockInVisitId = getSchedulableVisitForStop(stopId, primaryVisitId);

            // Determine if a timer is running for any visit on this stop's card
            var card = footer.closest('.mw-mc-card');
            var runningVisitId = getRunningVisitForCard(card);

            if (runningVisitId) {
                // A timer is active: show the timer, hide clock-in
                footer.classList.add('is-timing');
                if (timerEl) timerEl.style.display = 'flex';
                if (clockInBtn) clockInBtn.style.display = 'none';
                startFooterTimer(stopId, runningVisitId);
            } else if (clockInVisitId) {
                // No timer: show clock-in button
                footer.classList.remove('is-timing');
                if (timerEl) timerEl.style.display = 'none';
                if (clockInBtn) {
                    clockInBtn.style.display = 'flex';
                    clockInBtn.addEventListener('click', function(e) {
                        e.stopPropagation();
                        handleFooterClockIn(stopId, clockInVisitId, footer);
                    });
                }
            }

            // Complete Job button — always wired
            if (completeBtn) {
                completeBtn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    handleFooterComplete(stopId, footer);
                });
            }
        });
    }

    /**
     * Find the best visit ID to use for clock-in from a stop's card.
     * Returns the first scheduled visit, or the primary visit if none scheduled.
     */
    function getSchedulableVisitForStop(stopId, primaryVisitId) {
        // Scan all pills on cards with this stop ID
        var cards = document.querySelectorAll('.mw-mc-card[data-stop-id="' + stopId + '"]');
        var found = null;
        cards.forEach(function(card) {
            if (found) return;
            var pills = card.querySelectorAll('.mw-mc-pill-interactive');
            pills.forEach(function(p) {
                if (found) return;
                var vid = parseInt(p.dataset.visitId, 10);
                if (vid && visits[vid] && visits[vid].status === 'scheduled') {
                    found = vid;
                }
            });
        });
        // Fall back to primaryVisitId even if not in the visits registry
        // (card may have no pills in admin/compact view)
        return found || primaryVisitId || null;
    }

    /**
     * Find if any visit on a card has a running timer.
     * Returns the visit ID with a running timer, or null.
     */
    function getRunningVisitForCard(card) {
        if (!card) return null;
        var pills = card.querySelectorAll('.mw-mc-pill-interactive');
        var result = null;
        pills.forEach(function(p) {
            if (result) return;
            var vid = parseInt(p.dataset.visitId, 10);
            if (vid && visits[vid] && visits[vid].status === 'in_progress' && visits[vid].startTime) {
                result = vid;
            }
        });
        return result;
    }

    /**
     * Keep the footer elapsed time display updated every second.
     */
    var footerTimerIntervals = {}; // stopId -> intervalId

    function startFooterTimer(stopId, visitId) {
        if (footerTimerIntervals[stopId]) clearInterval(footerTimerIntervals[stopId]);
        function tick() {
            var v = visits[visitId];
            if (!v || !v.startTime) return;
            var elapsed = Math.floor((Date.now() - v.startTime.getTime()) / 1000);
            var m = Math.floor(elapsed / 60);
            var s = elapsed % 60;
            var timeStr = m + ':' + (s < 10 ? '0' : '') + s;
            var els = document.querySelectorAll('[data-footer-elapsed="' + stopId + '"]');
            els.forEach(function(el) { el.textContent = timeStr; });
            // Also tick per-visit elapsed display in the section footer
            document.querySelectorAll('[data-pv-elapsed="' + visitId + '"]').forEach(function(el) {
                el.textContent = timeStr;
            });
        }
        tick();
        footerTimerIntervals[stopId] = setInterval(tick, 1000);
    }

    function stopFooterTimer(stopId) {
        if (footerTimerIntervals[stopId]) {
            clearInterval(footerTimerIntervals[stopId]);
            delete footerTimerIntervals[stopId];
        }
    }

    /**
     * Switch footer to "timer running" mode after clock-in.
     */
    function footerSetTiming(stopId, visitId) {
        var footers = document.querySelectorAll('[data-footer-stop="' + stopId + '"]');
        footers.forEach(function(footer) {
            var timerEl    = footer.querySelector('[data-footer-timer]');
            var clockInBtn = footer.querySelector('[data-footer-clockin]');
            footer.classList.add('is-timing');
            if (timerEl)    timerEl.style.display    = 'flex';
            if (clockInBtn) clockInBtn.style.display = 'none';
        });
        startFooterTimer(stopId, visitId);
    }

    /**
     * Switch footer back to idle (no timer) mode after completion.
     */
    function footerSetIdle(stopId) {
        stopFooterTimer(stopId);
        var footers = document.querySelectorAll('[data-footer-stop="' + stopId + '"]');
        footers.forEach(function(footer) {
            footer.classList.remove('is-timing');
            // Hide everything — stop is done, JS will handle card completion
        });
    }

    /**
     * Clock-in via footer button.
     * Delegates to the existing clockIn() and syncs footer state on success.
     */
    function handleFooterClockIn(stopId, visitId, footer) {
        var clockInBtn = footer.querySelector('[data-footer-clockin]');
        if (clockInBtn) {
            clockInBtn.disabled = true;
            clockInBtn.innerHTML = '<span>Starting...</span>';
        }

        // Temporarily open a virtual drawer so clockIn() can update it
        // (clockIn reads activeDrawer for the button loading state).
        // We use null — clockIn already guards against null activeDrawer.
        getGps(function(lat, lng) {
            fetch('/crm/api/job-timer.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'start', visit_id: visitId, lat: lat, lng: lng })
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    if (visits[visitId]) {
                        visits[visitId].entryId = data.entry_id;
                        visits[visitId].startTime = new Date();
                        visits[visitId].status = 'prompt_before';
                        if (data.tracking_level) visits[visitId].trackingLevel = data.tracking_level;
                        if (data.require_photos !== undefined) visits[visitId].requirePhotos = data.require_photos;
                    }
                    // Update global active timer state regardless of pill registration
                    if (MW_SCHEDULE_STATE) {
                        MW_SCHEDULE_STATE.activeTimer = {
                            visit_id: visitId,
                            start_time: new Date().toISOString(),
                            elapsed_seconds: 0
                        };
                    }
                    if (window.MwTimeClock) {
                        window.MwTimeClock.notifyJobTimerStarted();
                        if (data.tracking_level === 'heightened') window.MwTimeClock.setTrackingInterval('heightened');
                    }
                    if (visits[visitId]) {
                        updatePillVisual(visitId, 'in_progress');
                        startPillTimer(visitId);
                    }
                    footerSetTiming(stopId, visitId);
                    pvSetTiming(visitId);

                    // Open the pill drawer for before-photo prompt (only if pill registered)
                    if (visits[visitId] && visits[visitId].pill) {
                        var card = visits[visitId].pill.closest('.mw-mc-card');
                        var drawer = card ? card.querySelector('.mw-mc-pill-drawer') : null;
                        if (drawer) {
                            renderPhotoPrompt(drawer, visitId, 'before');
                            drawer.style.display = 'block';
                            activeDrawer = drawer;
                            activeDrawerVisitId = visitId;
                        }
                    }
                } else {
                    showToast('Could not start timer: ' + (data.error || data.message || 'Unknown error'));
                    if (clockInBtn) {
                        clockInBtn.disabled = false;
                        clockInBtn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="5 3 19 12 5 21 5 3"/></svg> Clock In';
                    }
                }
            })
            .catch(function() {
                showToast('Network error. Check your connection.');
                if (clockInBtn) {
                    clockInBtn.disabled = false;
                    clockInBtn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="5 3 19 12 5 21 5 3"/></svg> Clock In';
                }
            });
        });
    }

    /**
     * Complete Job via footer button.
     * If a timer is running → stop it + complete the visit.
     * If no timer → directly mark the visit completed via end_visit.
     */
    function handleFooterComplete(stopId, footer) {
        var completeBtn = footer.querySelector('[data-footer-complete]');
        if (completeBtn) {
            completeBtn.disabled = true;
            completeBtn.innerHTML = '<span>Completing...</span>';
        }

        // Find the running or primary visit for this stop
        var card = footer.closest('.mw-mc-card');
        var runningVisitId = getRunningVisitForCard(card);
        var primaryVisitId = parseInt(footer.dataset.footerVisit, 10);
        var targetVisitId = runningVisitId || primaryVisitId;

        if (!targetVisitId) {
            if (completeBtn) { completeBtn.disabled = false; completeBtn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> Complete Job'; }
            return;
        }

        if (runningVisitId) {
            // Timer is running → stop it and mark complete (same as clockOut)
            clockOut(runningVisitId);
            // clockOut handles the visual updates; also update footer
            setTimeout(function() {
                footerSetIdle(stopId);
                if (completeBtn) completeBtn.style.display = 'none';
                pvSetDone(runningVisitId);
            }, 500);
        } else {
            // No timer → directly complete via end_visit
            getGps(function(lat, lng) {
                fetch('/crm/api/pow-actions.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'end_visit',
                        visit_id: targetVisitId,
                        lat: lat,
                        lng: lng,
                        csrf_token: state.csrf
                    })
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.success) {
                        // Mark visit complete in local state
                        if (visits[targetVisitId]) {
                            visits[targetVisitId].status = 'completed';
                            updatePillVisual(targetVisitId, 'completed');
                        }
                        footerSetIdle(stopId);
                        if (completeBtn) completeBtn.style.display = 'none';
                        pvSetDone(targetVisitId);
                        if (card) checkStopComplete(card);
                        showToast('Job completed');
                    } else {
                        showToast('Could not complete: ' + (data.error || 'Unknown error'));
                        if (completeBtn) {
                            completeBtn.disabled = false;
                            completeBtn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> Complete Job';
                        }
                    }
                })
                .catch(function() {
                    showToast('Network error. Check your connection.');
                    if (completeBtn) {
                        completeBtn.disabled = false;
                        completeBtn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> Complete Job';
                    }
                });
            });
        }
    }

    // ═══════════════════════════════════════════════════════
    //  PUBLIC API  (used by schedule.php card-expand handler)
    // ═══════════════════════════════════════════════════════

    /**
     * Render photo strips for every visit pill found on a given card element.
     * Called when a compact card is expanded so placeholders appear immediately.
     */
    function renderStripsForCard(card) {
        var pills = card.querySelectorAll('.mw-mc-pill-interactive');
        pills.forEach(function(pill) {
            var vid = parseInt(pill.dataset.visitId, 10);
            if (vid && visits[vid]) renderPhotoStrip(vid);
        });
    }

    window.MwPillWorkflow = { renderStripsForCard: renderStripsForCard };

    // ═══════════════════════════════════════════════════════
    //  BOOT
    // ═══════════════════════════════════════════════════════

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
