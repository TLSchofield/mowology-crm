/**
 * Schedule Stop Detail Modal
 * Opens a rich data panel when clicking a stop card on the desktop schedule grid.
 * Intercepts the crew-assign modal (bubbling) via capturing phase.
 * Polls every 30s for live crew GPS/clock-in updates.
 */
(function () {
    'use strict';

    var _stopId       = null;
    var _pollInterval = null;
    var _downX        = 0;
    var _downY        = 0;

    var SERVICE_COLORS = {
        'lawn_care':          '#2E7D32',
        'hedge_trimming':     '#6A1B9A',
        'garden_maintenance': '#EF6C00',
        'snow_removal':       '#1565C0',
        'landscaping':        '#2D8659',
        'seasonal_cleanup':   '#455A64',
        'fertilization':      '#0288D1',
        'salt':               '#1565C0',
        'aeration':           '#4E342E',
        'overseeding':        '#33691E',
    };

    // ── Utilities ─────────────────────────────────────────────────────────

    function svcColor(type) {
        return SERVICE_COLORS[type] || '#2D8659';
    }

    function esc(str) {
        if (str === null || str === undefined) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function fmtSecondsAgo(sec) {
        if (sec === null || sec === undefined) return 'No GPS';
        sec = parseInt(sec, 10);
        if (sec < 60) return sec + 's ago';
        if (sec < 3600) return Math.floor(sec / 60) + 'm ago';
        return Math.floor(sec / 3600) + 'h ago';
    }

    function gpsDotClass(sec) {
        if (sec === null || sec === undefined) return 'mw-sdm-gps-dot--gray';
        sec = parseInt(sec, 10);
        if (sec < 120) return 'mw-sdm-gps-dot--green';
        if (sec < 300) return 'mw-sdm-gps-dot--amber';
        return 'mw-sdm-gps-dot--red';
    }

    function fmtTime(timeStr) {
        if (!timeStr) return '';
        var t = timeStr.split(' ').pop(); // handle "YYYY-MM-DD HH:MM:SS" or "HH:MM:SS"
        var parts = t.split(':');
        if (parts.length < 2) return timeStr;
        var h = parseInt(parts[0], 10);
        var m = parts[1];
        var ampm = h >= 12 ? 'PM' : 'AM';
        h = h % 12 || 12;
        return h + ':' + m + ' ' + ampm;
    }

    function fmtClockIn(clockInTime) {
        if (!clockInTime) return '';
        return 'Since ' + fmtTime(clockInTime);
    }

    function fmtLastVisit(dateStr) {
        if (!dateStr) return 'Never';
        var then = new Date(dateStr + 'T12:00:00');
        var days = Math.round((Date.now() - then.getTime()) / 86400000);
        if (days === 0) return 'Today';
        if (days === 1) return 'Yesterday';
        return days + ' days ago';
    }

    function statusBadge(status) {
        var map = {
            'scheduled':   ['Scheduled',   'secondary'],
            'in_progress': ['In Progress',  'warning'],
            'en_route':    ['En Route',     'info'],
            'completed':   ['Completed',    'success'],
            'skipped':     ['Skipped',      'secondary'],
            'weather':     ['Weather Hold', 'info'],
            'cancelled':   ['Cancelled',    'danger'],
        };
        var info = map[status] || [status, 'secondary'];
        return '<span class="badge badge-' + info[1] + ' mw-sdm-status-badge">' + esc(info[0]) + '</span>';
    }

    // ── Modal visibility ──────────────────────────────────────────────────

    function showModal() {
        document.getElementById('mw-stop-modal').classList.add('mw-sdm--open');
        document.body.classList.add('mw-sdm-body-lock');
    }

    function hideModal() {
        document.getElementById('mw-stop-modal').classList.remove('mw-sdm--open');
        document.body.classList.remove('mw-sdm-body-lock');
        clearInterval(_pollInterval);
        _pollInterval = null;
        _stopId = null;
    }

    function showSkeleton() {
        document.getElementById('mwSdmHeader').innerHTML =
            '<div class="mw-sdm-skeleton-line" style="width:55%;height:18px;margin-bottom:8px"></div>' +
            '<div class="mw-sdm-skeleton-line" style="width:38%;height:13px"></div>';
        document.getElementById('mwSdmGrid').innerHTML =
            '<div class="mw-sdm-col"><div class="mw-sdm-skeleton-block"></div></div>' +
            '<div class="mw-sdm-col"><div class="mw-sdm-skeleton-block"></div></div>' +
            '<div class="mw-sdm-col"><div class="mw-sdm-skeleton-block"></div></div>';
        document.getElementById('mwSdmFooter').innerHTML = '';
    }

    function showError(msg) {
        document.getElementById('mwSdmHeader').innerHTML =
            '<div style="color:rgba(255,255,255,0.7);padding:8px 0">' + esc(msg) + '</div>';
        document.getElementById('mwSdmGrid').innerHTML =
            '<div class="mw-sdm-error">' +
                '<p>' + esc(msg) + '</p>' +
                '<button class="btn btn-sm btn-outline-secondary" onclick="MwStopModal.open(' + (_stopId || 0) + ')">Retry</button>' +
            '</div>';
        document.getElementById('mwSdmFooter').innerHTML = '';
    }

    // ── Network ───────────────────────────────────────────────────────────

    function fetchData(stopId) {
        return fetch('/crm/api/stop-detail.php?stop_id=' + stopId, {
            credentials: 'same-origin'
        }).then(function (r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        });
    }

    // ── Renderers ─────────────────────────────────────────────────────────

    function renderHeader(stop) {
        var color = stop.visits && stop.visits.length > 0 ? svcColor(stop.visits[0].service_type) : '#2D8659';
        var title = stop.company_name || stop.contact_name || 'Unknown Client';
        var addr  = esc(stop.property_address || '') + (stop.property_city ? ', ' + esc(stop.property_city) : '');
        var plan  = stop.visits && stop.visits.length > 0 ? esc(stop.visits[0].plan_title || '') : '';
        var dt    = '';
        if (stop.stop_date) {
            dt = new Date(stop.stop_date + 'T12:00:00').toLocaleDateString('en-CA', { weekday: 'short', month: 'short', day: 'numeric' });
        }
        var arrival = stop.estimated_arrival ? ' · ' + fmtTime(stop.estimated_arrival) + (stop.estimated_departure ? '–' + fmtTime(stop.estimated_departure) : '') : '';

        document.getElementById('mwSdmHeader').innerHTML =
            '<div class="mw-sdm-header-bar" style="background:' + color + '"></div>' +
            '<div class="mw-sdm-header-body">' +
                '<div class="mw-sdm-header-title">' + esc(title) + '</div>' +
                '<div class="mw-sdm-header-addr">' + addr + '</div>' +
                '<div class="mw-sdm-header-meta">' +
                    (plan ? '<span class="mw-sdm-header-plan">' + plan + '</span>' : '') +
                    (dt ? '<span class="mw-sdm-header-date">' + esc(dt) + esc(arrival) + '</span>' : '') +
                    statusBadge(stop.stop_status) +
                '</div>' +
            '</div>' +
            '<div class="mw-sdm-updated" id="mwSdmUpdatedTime">Just now</div>';
    }

    function renderCrewPanel(stop) {
        var html = '<div class="mw-sdm-panel-label">Crew</div>';

        if (!stop.crew || stop.crew.length === 0) {
            html += '<div class="mw-sdm-no-data">Unassigned</div>';
            return html;
        }

        stop.crew.forEach(function (c) {
            var dotClass  = gpsDotClass(c.gps_seconds_ago);
            var clockStr  = c.is_clocked_in ? fmtClockIn(c.clock_in_time) : 'Not clocked in';
            var gpsStr    = c.gps_seconds_ago !== null ? fmtSecondsAgo(c.gps_seconds_ago) : 'No GPS';
            var distStr   = c.distance_km !== null ? c.distance_km + ' km away' : '';

            html += '<div class="mw-sdm-crew-item" data-user-id="' + c.user_id + '">' +
                '<div class="mw-sdm-crew-avatar">' + esc(c.initials) + '</div>' +
                '<div class="mw-sdm-crew-info">' +
                    '<div class="mw-sdm-crew-name">' + esc(c.full_name) + '</div>' +
                    '<div class="mw-sdm-crew-clock">' +
                        '<span class="mw-sdm-clock-dot ' + (c.is_clocked_in ? 'mw-sdm-clock-dot--in' : 'mw-sdm-clock-dot--out') + '"></span>' +
                        '<span>' + esc(clockStr) + '</span>' +
                    '</div>' +
                    '<div class="mw-sdm-crew-gps-row">' +
                        '<span class="mw-sdm-gps-dot ' + dotClass + '"></span>' +
                        '<span class="mw-sdm-gps-time">' + gpsStr + '</span>' +
                        (distStr ? '<span class="mw-sdm-distance-badge">' + esc(distStr) + '</span>' : '') +
                    '</div>' +
                '</div>' +
            '</div>';
        });

        return html;
    }

    function renderServicesPanel(stop) {
        var html = '<div class="mw-sdm-panel-label">Services</div>';

        if (!stop.visits || stop.visits.length === 0) {
            html += '<div class="mw-sdm-no-data">No visits</div>';
            return html;
        }

        stop.visits.forEach(function (v) {
            var color      = svcColor(v.service_type);
            var checkPct   = v.checklist_total > 0 ? Math.round((v.checklist_complete / v.checklist_total) * 100) : 0;
            var isDone     = v.visit_status === 'completed';
            var isSkipped  = v.visit_status === 'skipped';
            var isScheduled= v.visit_status === 'scheduled';
            var isProgress = v.visit_status === 'in_progress';
            var durationStr= v.estimated_duration ? '~' + v.estimated_duration + ' min' : '';
            var priceStr   = v.price_per_visit ? '$' + parseFloat(v.price_per_visit).toFixed(0) : '';
            var typeLabel  = (v.service_type || '').replace(/_/g, ' ');

            html += '<div class="mw-sdm-visit-card" style="border-left-color:' + color + '" data-visit-id="' + v.visit_id + '">' +
                '<div class="mw-sdm-visit-head">' +
                    '<span class="mw-sdm-svc-pill" style="background:' + color + '">' + esc(typeLabel) + '</span>' +
                    statusBadge(v.visit_status) +
                '</div>' +
                '<div class="mw-sdm-visit-title">' + esc(v.plan_title) + '</div>';

            if (v.checklist_total > 0) {
                html += '<div class="mw-sdm-cl-wrap">' +
                    '<div class="mw-sdm-cl-track"><div class="mw-sdm-cl-fill" style="width:' + checkPct + '%"></div></div>' +
                    '<span class="mw-sdm-cl-label">' + v.checklist_complete + '/' + v.checklist_total + ' checklist</span>' +
                '</div>';
            }

            html += '<div class="mw-sdm-visit-meta">';
            if (v.photos_before > 0 || v.photos_after > 0) {
                html += '<span class="mw-sdm-photo-badge">📷 ' + v.photos_before + ' before · ' + v.photos_after + ' after</span>';
            } else {
                html += '<span class="mw-sdm-photo-badge mw-sdm-photo-badge--none">📷 No photos yet</span>';
            }
            if (durationStr || priceStr) {
                html += '<span class="mw-sdm-visit-timing">' + esc([durationStr, priceStr].filter(Boolean).join(' · ')) + '</span>';
            }
            html += '</div>';

            if (!isDone && !isSkipped) {
                html += '<div class="mw-sdm-visit-actions">';
                if (isScheduled) {
                    html += '<button class="mw-sdm-action-btn mw-sdm-action-btn--start" data-action="start_visit" data-visit-id="' + v.visit_id + '">Start</button>';
                }
                if (isScheduled || isProgress) {
                    html += '<button class="mw-sdm-action-btn mw-sdm-action-btn--complete" data-action="end_visit" data-visit-id="' + v.visit_id + '">Complete</button>';
                }
                if (isScheduled) {
                    html += '<button class="mw-sdm-action-btn mw-sdm-action-btn--skip" data-action="skip_visit" data-visit-id="' + v.visit_id + '">Skip</button>';
                }
                html += '</div>';
            }

            html += '</div>';
        });

        return html;
    }

    function renderPropertyPanel(stop) {
        var html = '<div class="mw-sdm-panel-label">Property</div>';

        if (stop.lawn_sqft) {
            html += '<div class="mw-sdm-prop-row">' +
                '<span class="mw-sdm-prop-icon">📐</span>' +
                '<span>' + Math.round(stop.lawn_sqft).toLocaleString() + ' sq ft</span>' +
            '</div>';
        }

        html += '<div class="mw-sdm-prop-row">' +
            '<span class="mw-sdm-prop-icon">🕒</span>' +
            '<span>Last visit: ' + fmtLastVisit(stop.last_completed_date) + '</span>' +
        '</div>';

        if (stop.property_notes) {
            var notes = stop.property_notes.length > 130
                ? stop.property_notes.substring(0, 127) + '…'
                : stop.property_notes;
            html += '<div class="mw-sdm-prop-notes">' + esc(notes) + '</div>';
        }

        if (stop.contact_name) {
            html += '<div class="mw-sdm-prop-row mw-sdm-contact-row">' +
                '<span class="mw-sdm-prop-icon">👤</span>' +
                '<div>' +
                    '<div class="mw-sdm-contact-name">' + esc(stop.contact_name) + '</div>' +
                    (stop.contact_phone
                        ? '<a href="tel:' + esc(stop.contact_phone) + '" class="mw-sdm-contact-phone">' + esc(stop.contact_phone) + '</a>'
                        : '') +
                '</div>' +
            '</div>';
        }

        if (stop.tags && stop.tags.length > 0) {
            html += '<div class="mw-sdm-tags">';
            stop.tags.forEach(function (tag) {
                var color = tag.tag_color || '#2D8659';
                var val   = (tag.has_value && tag.tag_value) ? ': ' + tag.tag_value : '';
                html += '<span class="mw-sdm-tag-pill" style="border-color:' + color + ';color:' + color + '">' +
                    esc(tag.tag_label + val) +
                '</span>';
            });
            html += '</div>';
        }

        return html;
    }

    function renderFooter(stop) {
        var mapsUrl = '';
        if (stop.latitude && stop.longitude) {
            mapsUrl = 'https://www.google.com/maps/dir/?api=1&destination=' + stop.latitude + ',' + stop.longitude;
        } else if (stop.property_address) {
            mapsUrl = 'https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent((stop.property_address || '') + ', ' + (stop.property_city || 'Vancouver') + ', BC, Canada');
        }

        var primaryVisitId = stop.visits && stop.visits.length > 0 ? stop.visits[0].visit_id : null;

        var html =
            '<div class="mw-sdm-footer-left">' +
                (mapsUrl ? '<a href="' + mapsUrl + '" target="_blank" rel="noopener" class="btn btn-sm mw-sdm-btn-nav">Navigate</a>' : '') +
                (stop.contact_phone ? '<a href="tel:' + esc(stop.contact_phone) + '" class="btn btn-sm mw-sdm-btn-call">Call Client</a>' : '') +
                '<button class="btn btn-sm mw-sdm-btn-crew" id="mwSdmAssignCrewBtn" data-stop-id="' + stop.stop_id + '">Assign Crew</button>' +
            '</div>' +
            '<div class="mw-sdm-footer-right">' +
                (primaryVisitId ? '<a href="/crm/jobs/visit-detail.php?id=' + primaryVisitId + '" class="btn btn-sm btn-primary">View Full Details</a>' : '') +
                '<button class="btn btn-sm btn-outline-secondary" id="mwSdmCloseBtn2">Close</button>' +
            '</div>';

        document.getElementById('mwSdmFooter').innerHTML = html;

        var assignBtn = document.getElementById('mwSdmAssignCrewBtn');
        if (assignBtn) {
            assignBtn.addEventListener('click', function () {
                var sid = this.dataset.stopId;
                var stopIdField = document.getElementById('crewAssignStopId');
                if (stopIdField) stopIdField.value = sid;
                if (window.$ && $('#crewAssignModal').length) {
                    $('#crewAssignModal').modal('show');
                }
            });
        }

        var closeBtn2 = document.getElementById('mwSdmCloseBtn2');
        if (closeBtn2) closeBtn2.addEventListener('click', hideModal);
    }

    function renderModal(data) {
        var stop = data.stop;
        renderHeader(stop);

        document.getElementById('mwSdmGrid').innerHTML =
            '<div class="mw-sdm-col">' + renderCrewPanel(stop) + '</div>' +
            '<div class="mw-sdm-col">' + renderServicesPanel(stop) + '</div>' +
            '<div class="mw-sdm-col">' + renderPropertyPanel(stop) + '</div>';

        renderFooter(stop);

        document.getElementById('mwSdmGrid').querySelectorAll('.mw-sdm-action-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                onActionClick(parseInt(this.dataset.visitId, 10), this.dataset.action, this);
            });
        });
    }

    function refreshLiveData(stop) {
        var el = document.getElementById('mwSdmUpdatedTime');
        if (el) el.textContent = 'Just now';

        if (!stop.crew) return;
        stop.crew.forEach(function (c) {
            var item = document.querySelector('.mw-sdm-crew-item[data-user-id="' + c.user_id + '"]');
            if (!item) return;

            var dot = item.querySelector('.mw-sdm-gps-dot');
            if (dot) dot.className = 'mw-sdm-gps-dot ' + gpsDotClass(c.gps_seconds_ago);

            var gpsTime = item.querySelector('.mw-sdm-gps-time');
            if (gpsTime) gpsTime.textContent = fmtSecondsAgo(c.gps_seconds_ago);

            var distBadge = item.querySelector('.mw-sdm-distance-badge');
            if (distBadge && c.distance_km !== null) {
                distBadge.textContent = c.distance_km + ' km away';
            }
        });
    }

    // ── Action handler ────────────────────────────────────────────────────

    function onActionClick(visitId, action, btn) {
        if (!visitId || !action) return;
        var csrf = window.MW_SCHEDULE_STATE && window.MW_SCHEDULE_STATE.csrf;
        if (!csrf) { alert('Session expired — please refresh the page'); return; }

        var origText = btn.textContent;
        btn.disabled = true;
        btn.textContent = '…';

        fetch('/crm/api/pow-actions.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: action, visit_id: visitId, csrf_token: csrf })
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.success) {
                fetchData(_stopId).then(function (d) {
                    if (d.success) renderModal(d);
                }).catch(function () {});
            } else {
                btn.disabled = false;
                btn.textContent = origText;
                alert(data.error || 'Action failed');
            }
        })
        .catch(function () {
            btn.disabled = false;
            btn.textContent = origText;
            alert('Network error — please try again');
        });
    }

    // ── Public open/close ─────────────────────────────────────────────────

    function open(stopId) {
        _stopId = stopId;
        showSkeleton();
        showModal();

        fetchData(stopId).then(function (data) {
            if (!data.success) {
                showError(data.error || 'Failed to load stop details');
                return;
            }
            renderModal(data);

            _pollInterval = setInterval(function () {
                fetchData(_stopId)
                    .then(function (d) { if (d && d.success) refreshLiveData(d.stop); })
                    .catch(function () {});
            }, 30000);
        }).catch(function () {
            showError('Network error loading stop details');
        });
    }

    // ── Initialise ────────────────────────────────────────────────────────

    function init() {
        // Desktop only — mobile uses the pill-workflow card system
        if (window.innerWidth <= 991) return;

        // Attach capturing mouseup to each stop card.
        // Capturing fires BEFORE the bubbling crew-assign modal handler that's
        // already registered on each card, so stopImmediatePropagation() blocks it.
        document.querySelectorAll('.mw-stop-card, .mw-dv-card').forEach(function (card) {
            card.addEventListener('mousedown', function (e) {
                _downX = e.clientX;
                _downY = e.clientY;
            }, true);

            card.addEventListener('mouseup', function (e) {
                if (e.target.closest && e.target.closest('.mw-dv-pin-btn')) return;
                if (e.target.closest && e.target.closest('.mw-pro-trigger')) return;
                var dx = Math.abs(e.clientX - _downX);
                var dy = Math.abs(e.clientY - _downY);
                if (dx > 5 || dy > 5) return; // was a drag

                var stopId = parseInt(card.dataset.stopId, 10);
                if (!stopId) return;

                e.stopImmediatePropagation();
                open(stopId);
            }, true);
        });

        // Backdrop click closes
        var overlay = document.getElementById('mwSdmOverlay');
        if (overlay) overlay.addEventListener('click', hideModal);

        // Header × closes
        var closeBtn = document.getElementById('mwSdmClose');
        if (closeBtn) closeBtn.addEventListener('click', hideModal);

        // Escape closes
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') hideModal();
        });
    }

    // Public surface
    window.MwStopModal = { open: open, close: hideModal };

    // Init immediately — this script loads at end of <body> so DOM is ready
    init();
})();
