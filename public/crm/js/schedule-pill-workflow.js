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
            } else if (visitStatus === 'in_progress') {
                // Visit is in-progress (maybe another user's timer) — show visual only
                updatePillVisual(visitId, 'in_progress');
            } else if (visitStatus === 'completed') {
                updatePillVisual(visitId, 'completed');
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
        });
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

                    // Show before photo prompt in drawer
                    var card = visits[visitId].pill.closest('.mw-mc-card');
                    var drawer = card.querySelector('.mw-mc-pill-drawer');
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
     * Trigger the camera via hidden file input
     */
    // Track pending camera sessions to prevent double-fire on tablets
    var pendingCamera = null; // { visitId, category, card, input, handler }

    function triggerCamera(visitId, category) {
        var card = visits[visitId].pill.closest('.mw-mc-card');

        // Each visit gets its own dedicated camera input to avoid cross-visit handler collisions
        // Find or create a visit-specific input
        var inputId = 'mw-mc-cam-' + visitId;
        var input = card.querySelector('[data-cam-visit="' + visitId + '"]');
        if (!input) {
            input = document.createElement('input');
            input.type = 'file';
            input.accept = 'image/*';
            input.setAttribute('capture', 'environment');
            input.style.display = 'none';
            input.setAttribute('data-cam-visit', visitId);
            card.appendChild(input);
        }

        // Tear down any previous pending session on this input (prevents double-fire)
        if (pendingCamera && pendingCamera.input === input) {
            input.removeEventListener('change', pendingCamera.handler);
            pendingCamera = null;
        }
        // Also nuke any legacy handlers by cloning the input
        var fresh = input.cloneNode(false);
        input.parentNode.replaceChild(fresh, input);
        input = fresh;

        var handler = function() {
            // Guard: only fire once
            if (pendingCamera && pendingCamera.handler === handler) {
                pendingCamera = null;
            }
            input.removeEventListener('change', handler);

            if (!input.files || !input.files.length) {
                // User cancelled — re-render strip so placeholders are still tappable
                renderPhotoStrip(visitId);
                return;
            }

            // Show uploading state immediately on the placeholder
            var strip = card.querySelector('[data-strip-visit="' + visitId + '"]');
            if (strip) {
                var ph = strip.querySelector('[data-category="' + category + '"]');
                if (ph) ph.classList.add('mw-mc-placeholder-uploading');
            }

            uploadPhoto(visitId, input.files[0], category, function(success, thumbUrl) {
                // Reset input value for reuse
                try { input.value = ''; } catch(e) {}

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
                    if (!visits[visitId].additionalThumbs) visits[visitId].additionalThumbs = [];
                    if (thumbUrl) visits[visitId].additionalThumbs.push(thumbUrl);
                    renderPhotoStrip(visitId);
                    if (thumbUrl) showThumbConfirmation(card, thumbUrl, 'Photo', visitId, null);
                }
            });
        };

        pendingCamera = { visitId: visitId, category: category, card: card, input: input, handler: handler };
        input.addEventListener('change', handler);

        // Small delay before click on iOS/iPadOS to avoid the file picker firing
        // a stale change event from a previous dismissed session
        setTimeout(function() { input.click(); }, 50);
    }

    /**
     * Upload a photo to the media system
     * Photos use visibility=client_visible so they populate the client portal
     */
    function uploadPhoto(visitId, file, category, callback) {
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

        console.log('[PillWorkflow] Uploading photo: visit=' + visitId + ', category=' + category);
        fetch('/crm/api/media-upload.php', {
            method: 'POST',
            body: formData
        })
        .then(function(r) { return r.json(); })
        .then(function(r) {
            // Guard against non-JSON responses (e.g. server error page)
            var ct = r.headers.get('content-type') || '';
            if (!ct.includes('application/json') && !ct.includes('text/json')) {
                console.warn('[PillWorkflow] Non-JSON response from upload endpoint, status=' + r.status);
                return r.text().then(function(txt) {
                    throw new Error('Server returned non-JSON: ' + r.status + ' ' + txt.slice(0, 120));
                });
            }
            return r.json();
        })
        .then(function(data) {
            console.log('[PillWorkflow] Upload response:', JSON.stringify(data));
            if (data.success && data.total_uploaded > 0) {
                var thumbUrl = null;
                if (data.results && data.results[0]) {
                    var r = data.results[0];
                    console.log('[PillWorkflow] Upload result: thumb_url=' + r.thumb_url + ', file_path=' + r.file_path);
                    // Prefer thumb, fall back to original path, fall back to placeholder data URI
                    thumbUrl = r.thumb_url || r.file_path || null;

                    // If server gave no URL at all (variant generation failure),
                    // create an object URL from the original file so the strip still renders
                    if (!thumbUrl && data.results[0].success) {
                        thumbUrl = '__uploaded__'; // sentinel: shows ✓ tile instead of image
                    }
                }
                if (visits[visitId]) {
                    if (category === 'before') {
                        visits[visitId].beforeThumb = thumbUrl || '__uploaded__';
                    } else if (category === 'after') {
                        visits[visitId].afterThumb = thumbUrl || '__uploaded__';
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
            console.error('[PillWorkflow] Upload error:', err.message || err);
            showToast('Upload failed — check connection and try again.');
            callback(false, null);
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
    var CAMERA_SVG = '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 01-2 2H3a2 2 0 01-2-2V8a2 2 0 012-2h4l2-3h6l2 3h4a2 2 0 012 2z"/><circle cx="12" cy="13" r="4"/></svg>';
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

        var container = card.querySelector('.mw-mc-photo-strips');
        if (!container) return;

        // On compact cards: only show real captured thumbnails (no placeholders, no + button)
        // Placeholders steal taps meant to expand the card — they're shown on the active/hero card only
        var isCompact = card.classList.contains('mw-mc-card-compact');
        var hasBefore = v.beforeThumb && v.beforeThumb !== null;
        var hasAfter  = v.afterThumb  && v.afterThumb  !== null;
        var hasAdditionals = v.additionalThumbs && v.additionalThumbs.length > 0;

        if (isCompact && !hasBefore && !hasAfter && !hasAdditionals) {
            // Nothing real to show on a compact card — clear and hide
            container.innerHTML = '';
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
        } else if (!isCompact) {
            // Placeholder only on active/hero cards, never on compact (would swallow taps)
            beforeHtml =
                '<div class="mw-mc-photo-placeholder' + (required ? ' mw-mc-placeholder-required' : '') + '" data-thumb-type="before" data-visit-id="' + visitId + '" data-category="before">' +
                '  ' + CAMERA_SVG +
                '  <span>Before</span>' +
                '</div>';
        } else {
            beforeHtml = ''; // compact + no photo = nothing
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
        } else if (!isCompact) {
            // Placeholder only on active/hero cards
            afterHtml =
                '<div class="mw-mc-photo-placeholder' + (required ? ' mw-mc-placeholder-required' : '') + '" data-thumb-type="after" data-visit-id="' + visitId + '" data-category="after">' +
                '  ' + CAMERA_SVG +
                '  <span>After</span>' +
                '</div>';
        } else {
            afterHtml = ''; // compact + no photo = nothing
        }

        // ── Additionals ──
        var addThumbs = v.additionalThumbs || [];
        var addHtml = '';

        if (!isCompact) {
            // Only show the additionals row (with + button) on active/hero cards
            addHtml = '<div class="mw-mc-photo-additionals" data-visit-id="' + visitId + '">';
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
        } else if (addThumbs.length > 0) {
            // Compact: show captured additionals inline (no + button)
            addHtml = '<div class="mw-mc-photo-additionals" data-visit-id="' + visitId + '">';
            for (var aj = 0; aj < addThumbs.length; aj++) {
                addHtml +=
                    '<div class="mw-mc-photo-thumb mw-mc-photo-thumb-additional">' +
                    '  <img src="' + escHtml(addThumbs[aj]) + '" alt="Photo ' + (aj + 1) + '" loading="lazy">' +
                    '  <span class="mw-mc-photo-thumb-label">#' + (aj + 1) + '</span>' +
                    '</div>';
            }
            addHtml += '</div>';
        }

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

        console.log('[PillWorkflow] Photo strip rendered for visit ' + visitId +
            ' (before: ' + (v.beforeThumb ? 'yes' : 'no') +
            ', after: ' + (v.afterThumb ? 'yes' : 'no') +
            ', additionals: ' + addThumbs.length + ')');
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
        formData.append('photos[]', file);
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
    //  BOOT
    // ═══════════════════════════════════════════════════════

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
