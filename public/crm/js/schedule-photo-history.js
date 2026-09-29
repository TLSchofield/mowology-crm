/**
 * Photo history — mobile schedule cards.
 *
 * The card's "Photo history" button (partials/job-card.php) opens a sheet of photos
 * from earlier visits at the same property, newest visit first. Tapping a photo
 * opens a full-screen viewer that swipes through every photo in the history.
 * Loaded on demand from /crm/api/visit-photo-history.php and cached per visit.
 */
(function () {
    'use strict';

    var cache = {};      // visitId -> history array
    var sheet = null;
    var viewer = null;
    var flat = [];       // every photo in the open history, for the viewer
    var index = 0;

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function parseISO(iso) {
        var p = (iso || '').split('-');
        return p.length === 3 ? new Date(+p[0], +p[1] - 1, +p[2]) : null;
    }

    function dateLabel(iso) {
        var d = parseISO(iso);
        if (!d) return iso || '';
        var opts = d.getFullYear() === new Date().getFullYear()
            ? { weekday: 'short', month: 'short', day: 'numeric' }
            : { month: 'short', day: 'numeric', year: 'numeric' };
        return d.toLocaleDateString(undefined, opts);
    }

    function agoLabel(iso) {
        var d = parseISO(iso);
        if (!d) return '';
        var today = new Date(); today.setHours(0, 0, 0, 0);
        var days = Math.round((today - d) / 86400000);
        if (days < 0) return '';
        if (days === 0) return 'today';
        if (days === 1) return 'yesterday';
        if (days < 60) return days + ' days ago';
        if (days < 365) return Math.floor(days / 30) + ' months ago';
        return Math.floor(days / 365) + ' yr ago';
    }

    function typeLabel(t) {
        return t === 'before' ? 'Before' : (t === 'after' ? 'After' : '');
    }

    // ── Sheet ────────────────────────────────────────────────────────────────

    function ensureSheet() {
        if (sheet) return;
        sheet = document.createElement('div');
        sheet.className = 'mw-ph-sheet';
        sheet.hidden = true;
        sheet.setAttribute('role', 'dialog');
        sheet.setAttribute('aria-modal', 'true');
        sheet.innerHTML =
            '<div class="mw-ph-backdrop" data-ph-close></div>' +
            '<div class="mw-ph-panel">' +
                '<div class="mw-ph-handle"></div>' +
                '<div class="mw-ph-head">' +
                    '<div><p class="mw-ph-title">Photo history</p><p class="mw-ph-sub"></p></div>' +
                    '<button type="button" class="mw-ph-close" data-ph-close aria-label="Close">&#10005;</button>' +
                '</div>' +
                '<div class="mw-ph-body"></div>' +
            '</div>';
        document.body.appendChild(sheet);

        sheet.addEventListener('click', function (e) {
            if (e.target.closest('[data-ph-close]')) { closeSheet(); return; }
            var thumb = e.target.closest('.mw-ph-thumb');
            if (thumb) openViewer(parseInt(thumb.getAttribute('data-ph-index'), 10));
        });
    }

    function closeSheet() {
        if (sheet) sheet.hidden = true;
        document.body.style.overflow = '';
    }

    function renderHistory(history) {
        var body = sheet.querySelector('.mw-ph-body');
        flat = [];

        if (!history.length) {
            body.innerHTML = '<p class="mw-ph-empty">No photos from earlier visits here yet.</p>';
            return;
        }

        var html = '';
        history.forEach(function (visit) {
            html += '<div class="mw-ph-visit">' +
                '<div class="mw-ph-visit-head">' +
                    '<span class="mw-ph-date">' + esc(dateLabel(visit.date)) + '</span>' +
                    '<span class="mw-ph-service">' + esc(visit.service) + '</span>' +
                    '<span class="mw-ph-ago">' + esc(agoLabel(visit.date)) + '</span>' +
                '</div><div class="mw-ph-strip">';
            (visit.photos || []).forEach(function (photo) {
                var label = typeLabel(photo.photo_type);
                var caption = [dateLabel(visit.date), visit.service, label].filter(Boolean).join(' · ');
                flat.push({ url: photo.photo_url, caption: caption });
                html += '<button type="button" class="mw-ph-thumb" data-ph-index="' + (flat.length - 1) + '">' +
                    '<img src="' + esc(photo.thumb_url || photo.photo_url) + '" alt="' + esc(caption) + '" loading="lazy">' +
                    (label ? '<span class="mw-ph-tag">' + label + '</span>' : '') +
                '</button>';
            });
            html += '</div></div>';
        });
        body.innerHTML = html;
    }

    function open(btn) {
        ensureSheet();
        var visitId = parseInt(btn.getAttribute('data-photo-history'), 10);
        sheet.querySelector('.mw-ph-sub').textContent = btn.getAttribute('data-photo-history-label') || '';
        sheet.hidden = false;
        document.body.style.overflow = 'hidden';

        if (cache[visitId]) { renderHistory(cache[visitId]); return; }

        sheet.querySelector('.mw-ph-body').innerHTML = '<p class="mw-ph-empty">Loading…</p>';
        fetch('/crm/api/visit-photo-history.php?visit_id=' + visitId, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d || !d.success) throw new Error('failed');
                cache[visitId] = d.history || [];
                renderHistory(cache[visitId]);
            })
            .catch(function () {
                sheet.querySelector('.mw-ph-body').innerHTML =
                    '<p class="mw-ph-empty">Could not load photos — check your signal and try again.</p>';
            });
    }

    // ── Full-screen viewer ───────────────────────────────────────────────────

    function ensureViewer() {
        if (viewer) return;
        viewer = document.createElement('div');
        viewer.className = 'mw-ph-viewer';
        viewer.hidden = true;
        viewer.innerHTML =
            '<div class="mw-ph-viewer-bar">' +
                '<div><p class="mw-ph-viewer-caption"></p><p class="mw-ph-viewer-count"></p></div>' +
                '<button type="button" class="mw-ph-viewer-close" aria-label="Close">&#10005;</button>' +
            '</div>' +
            '<img class="mw-ph-viewer-img" alt="">' +
            '<button type="button" class="mw-ph-viewer-nav mw-ph-viewer-prev" aria-label="Previous photo">&#8249;</button>' +
            '<button type="button" class="mw-ph-viewer-nav mw-ph-viewer-next" aria-label="Next photo">&#8250;</button>';
        document.body.appendChild(viewer);

        viewer.querySelector('.mw-ph-viewer-close').addEventListener('click', closeViewer);
        viewer.querySelector('.mw-ph-viewer-prev').addEventListener('click', function () { show(index - 1); });
        viewer.querySelector('.mw-ph-viewer-next').addEventListener('click', function () { show(index + 1); });

        var startX = 0, startY = 0;
        viewer.addEventListener('touchstart', function (e) {
            startX = e.touches[0].clientX; startY = e.touches[0].clientY;
        }, { passive: true });
        viewer.addEventListener('touchend', function (e) {
            var dx = e.changedTouches[0].clientX - startX;
            var dy = e.changedTouches[0].clientY - startY;
            if (Math.abs(dx) > 50 && Math.abs(dx) > Math.abs(dy)) { show(index + (dx < 0 ? 1 : -1)); }
            else if (dy > 80 && Math.abs(dy) > Math.abs(dx)) { closeViewer(); }
        }, { passive: true });
    }

    function show(i) {
        if (i < 0 || i >= flat.length) return;
        index = i;
        viewer.querySelector('.mw-ph-viewer-img').src = flat[i].url;
        viewer.querySelector('.mw-ph-viewer-caption').textContent = flat[i].caption;
        viewer.querySelector('.mw-ph-viewer-count').textContent = (i + 1) + ' of ' + flat.length;
        viewer.querySelector('.mw-ph-viewer-prev').disabled = i === 0;
        viewer.querySelector('.mw-ph-viewer-next').disabled = i === flat.length - 1;
    }

    function openViewer(i) {
        ensureViewer();
        viewer.hidden = false;
        show(i);
    }

    function closeViewer() {
        if (viewer) viewer.hidden = true;
    }

    // Capture phase: beats the card's tap-to-expand handler.
    document.addEventListener('click', function (e) {
        var btn = e.target.closest ? e.target.closest('.mw-mc-photo-history-btn') : null;
        if (!btn) return;
        e.preventDefault();
        e.stopPropagation();
        open(btn);
    }, true);

    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        if (viewer && !viewer.hidden) { closeViewer(); } else { closeSheet(); }
    });
})();
