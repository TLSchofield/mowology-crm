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
    var visits = {};        // visitId -> { status, pill, serviceLabel, entryId, startTime, timerInterval, beforeThumb, afterThumb }
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

            visits[visitId] = {
                status: visitStatus,
                pill: pill,
                serviceLabel: serviceLabel,
                entryId: null,
                startTime: null,
                timerInterval: null,
                beforeThumb: null,
                afterThumb: null
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
                    renderPhotoStrip(numVid);
                }
            }
            console.log('[PillWorkflow] Pre-loaded ' + photoCount + ' existing photo thumbnails');
        }

        // Pre-fetch GPS for later use
        getGps(function(lat, lng) {
            console.log('[PillWorkflow] GPS ready:', lat, lng);
        });
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

        drawer.innerHTML =
            '<div class="mw-mc-drawer-photo-prompt">' +
            '  <button class="mw-mc-drawer-camera-btn" data-action="take-photo">' +
            '    ' + icon +
            '    <span>' + label + '</span>' +
            '  </button>' +
            '  <button class="mw-mc-drawer-skip" data-action="skip-photo">Skip</button>' +
            '</div>';

        drawer.querySelector('[data-action="take-photo"]')
            .addEventListener('click', function(e) {
                e.stopPropagation();
                triggerCamera(visitId, category);
            });

        drawer.querySelector('[data-action="skip-photo"]')
            .addEventListener('click', function(e) {
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
    function triggerCamera(visitId, category) {
        var card = visits[visitId].pill.closest('.mw-mc-card');
        var input = card.querySelector('.mw-mc-camera-input');
        if (!input) return;

        // Set up one-time change handler
        var handler = function() {
            input.removeEventListener('change', handler);
            if (!input.files || !input.files.length) return;

            uploadPhoto(visitId, input.files[0], category, function(success, thumbUrl) {
                input.value = ''; // Reset for reuse

                if (category === 'before') {
                    // After before photo → go to working state with thumb preview
                    visits[visitId].status = 'in_progress';
                    updatePillVisual(visitId, 'in_progress');
                    startPillTimer(visitId);

                    // Show before thumb in drawer briefly, then close
                    if (thumbUrl) {
                        showThumbConfirmation(card, thumbUrl, 'Before', visitId, function() {
                            closeDrawer();
                        });
                    } else {
                        closeDrawer();
                    }
                } else if (category === 'after') {
                    // After after photo → show thumb briefly, then clock out
                    if (thumbUrl) {
                        showThumbConfirmation(card, thumbUrl, 'After', visitId, function() {
                            clockOut(visitId);
                        });
                    } else {
                        clockOut(visitId);
                    }
                }
                // 'during' photos: stay in working state, show thumb briefly
                if (category === 'during' && thumbUrl) {
                    showThumbConfirmation(card, thumbUrl, 'Photo', visitId, null);
                }
            });
        };

        input.addEventListener('change', handler);
        input.click();
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
        .then(function(data) {
            console.log('[PillWorkflow] Upload response:', JSON.stringify(data));
            if (data.success && data.total_uploaded > 0) {
                // Store thumbnail URL for display on pill
                var thumbUrl = null;
                if (data.results && data.results[0]) {
                    var r = data.results[0];
                    console.log('[PillWorkflow] Upload result: thumb_url=' + r.thumb_url + ', file_path=' + r.file_path);
                    thumbUrl = r.thumb_url || r.file_path || null;
                }
                if (thumbUrl && visits[visitId]) {
                    if (category === 'before') {
                        visits[visitId].beforeThumb = thumbUrl;
                    } else if (category === 'after') {
                        visits[visitId].afterThumb = thumbUrl;
                    }
                }
                flashPillFeedback(visitId, 'Photo saved');
                callback(true, thumbUrl);
            } else {
                var errMsg = 'Upload failed';
                if (data.results && data.results[0] && data.results[0].errors) {
                    errMsg = data.results[0].errors.join(', ');
                }
                showToast(errMsg);
                callback(false, null);
            }
        })
        .catch(function(err) {
            showToast('Upload failed. Check connection.');
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
     * Hide the card-level Route button when any visit on that card is
     * in-progress or completed (crew is already at the location).
     * Show it only when all visits are still scheduled.
     */
    function updateRouteButtonVisibility(pill) {
        var card = pill.closest('.mw-mc-card');
        if (!card) return;
        var routeBtn = card.querySelector('.mw-mc-btn-route');
        if (!routeBtn) return;

        // Check if any pill on this card is active or done
        var cardPills = card.querySelectorAll('.mw-mc-pill-interactive');
        var anyActive = false;
        cardPills.forEach(function(p) {
            if (p.classList.contains('mw-mc-pill-active') || p.classList.contains('mw-mc-pill-done')) {
                anyActive = true;
            }
        });

        // Hide route button (and its parent actions row) when crew is on-site
        var actionsRow = routeBtn.closest('.mw-mc-actions');
        if (anyActive) {
            if (actionsRow) actionsRow.style.display = 'none';
        } else {
            if (actionsRow) actionsRow.style.display = '';
        }
    }

    /**
     * Render/update a persistent before/after thumbnail strip for a visit.
     * Uses .mw-mc-photo-strips container (outside the drawer) so it persists.
     * Called after every photo capture — incrementally adds thumbs.
     */
    function renderPhotoStrip(visitId) {
        var v = visits[visitId];
        if (!v) return;
        if (!v.beforeThumb && !v.afterThumb) return;

        var card = v.pill.closest('.mw-mc-card');
        if (!card) return;

        // Use the persistent photo strips container (NOT the drawer)
        var container = card.querySelector('.mw-mc-photo-strips');
        if (!container) return;

        // Find or create this visit's strip div
        var stripId = 'photo-strip-' + visitId;
        var strip = container.querySelector('[data-strip-visit="' + visitId + '"]');
        if (!strip) {
            strip = document.createElement('div');
            strip.className = 'mw-mc-photo-strip';
            strip.setAttribute('data-strip-visit', visitId);
            container.appendChild(strip);
        }

        // Rebuild strip contents
        var html = '';
        if (v.beforeThumb) {
            html += '<div class="mw-mc-photo-thumb">' +
                    '  <img src="' + escHtml(v.beforeThumb) + '" alt="Before" loading="lazy">' +
                    '  <span class="mw-mc-photo-thumb-label">Before</span>' +
                    '</div>';
        }
        if (v.afterThumb) {
            html += '<div class="mw-mc-photo-thumb">' +
                    '  <img src="' + escHtml(v.afterThumb) + '" alt="After" loading="lazy">' +
                    '  <span class="mw-mc-photo-thumb-label">After</span>' +
                    '</div>';
        }
        strip.innerHTML = html;

        console.log('[PillWorkflow] Photo strip updated for visit ' + visitId +
            ' (before: ' + (v.beforeThumb ? 'yes' : 'no') +
            ', after: ' + (v.afterThumb ? 'yes' : 'no') + ')');
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
    //  BOOT
    // ═══════════════════════════════════════════════════════

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
