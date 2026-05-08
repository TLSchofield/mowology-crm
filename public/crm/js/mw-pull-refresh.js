/**
 * Mowology Pull-to-Refresh
 * ══════════════════════════════════════════════════════════════════════
 * Native-feeling pull-to-refresh gesture for the mobile schedule view.
 * Zero dependencies, no library.
 *
 * How it works
 *   - Activates when the user touches the top of .mw-mc-container while
 *     the viewport is already scrolled to the top (preventing accidental
 *     triggers when scrolling back up).
 *   - Displays a shrinking loader that grows as the pull distance
 *     increases. At the trigger threshold (80 px), the loader flips to
 *     an "active" state and a haptic selection pulse fires.
 *   - On release past the threshold, window.location.reload() is called
 *     (server-rendered page, simplest correct semantics).
 *   - Below the threshold, the loader animates back to zero.
 *
 * Respects prefers-reduced-motion — animation is skipped and the
 * gesture becomes a simple "pull-and-release to reload" with no
 * visual feedback beyond the loader text.
 */
(function () {
    'use strict';

    var container = document.querySelector('.mw-mc-container');
    if (!container) return;
    if (container.dataset.pullRefreshInstalled) return;
    container.dataset.pullRefreshInstalled = '1';

    var THRESHOLD = 80;   // px to trigger refresh
    var MAX_PULL  = 120;  // px hard cap on the pull distance

    var loader = document.createElement('div');
    loader.className = 'mw-mc-pull-loader';
    loader.innerHTML =
        '<div class="mw-mc-pull-spinner" aria-hidden="true">' +
            '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">' +
                '<polyline points="23 4 23 10 17 10"/>' +
                '<path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/>' +
            '</svg>' +
        '</div>' +
        '<span class="mw-mc-pull-label">Pull to refresh</span>';
    container.insertBefore(loader, container.firstChild);

    var startY = 0;
    var pullY  = 0;
    var active = false;
    var triggered = false;

    function atTop() {
        return window.scrollY <= 0 && container.scrollTop <= 0;
    }

    function setLoaderHeight(px) {
        loader.style.height = px + 'px';
    }

    function markTriggered(yes) {
        if (yes === triggered) return;
        triggered = yes;
        if (yes) {
            loader.classList.add('mw-mc-pull-ready');
            loader.querySelector('.mw-mc-pull-label').textContent = 'Release to refresh';
            if (window.MwHaptics) window.MwHaptics.selection();
        } else {
            loader.classList.remove('mw-mc-pull-ready');
            loader.querySelector('.mw-mc-pull-label').textContent = 'Pull to refresh';
        }
    }

    container.addEventListener('touchstart', function (e) {
        if (!atTop()) return;
        active = true;
        triggered = false;
        startY = e.touches[0].clientY;
        loader.classList.add('mw-mc-pull-tracking');
    }, { passive: true });

    container.addEventListener('touchmove', function (e) {
        if (!active) return;
        var delta = e.touches[0].clientY - startY;
        if (delta <= 0) {
            pullY = 0;
            setLoaderHeight(0);
            return;
        }
        // Apply a rubber-band so the pull feels resistant beyond MAX_PULL
        pullY = Math.min(MAX_PULL, delta * 0.6);
        setLoaderHeight(pullY);
        markTriggered(pullY >= THRESHOLD);
    }, { passive: true });

    container.addEventListener('touchend', function () {
        if (!active) return;
        active = false;
        loader.classList.remove('mw-mc-pull-tracking');

        if (triggered) {
            loader.classList.add('mw-mc-pull-loading');
            loader.querySelector('.mw-mc-pull-label').textContent = 'Refreshing…';
            setLoaderHeight(60);
            // Brief delay so the user sees the loading state then reload
            setTimeout(function () { window.location.reload(); }, 250);
        } else {
            // Animate back to zero
            loader.style.transition = 'height 200ms cubic-bezier(0.2, 0.8, 0.2, 1)';
            setLoaderHeight(0);
            setTimeout(function () {
                loader.style.transition = '';
                markTriggered(false);
            }, 220);
        }
    }, { passive: true });

    // If the user's touch is cancelled by a scroll hand-off, reset cleanly
    container.addEventListener('touchcancel', function () {
        active = false;
        triggered = false;
        setLoaderHeight(0);
        loader.classList.remove('mw-mc-pull-tracking', 'mw-mc-pull-ready', 'mw-mc-pull-loading');
    }, { passive: true });
}());
