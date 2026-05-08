/**
 * Mowology Schedule Search
 * ══════════════════════════════════════════════════════════════════════
 * Client-side substring filter over the mobile card execution view.
 *
 * Injects a search input below the schedule top bar and filters
 * .mw-mc-card elements by their textContent (which already contains
 * the customer name, property address, service type, and plan title).
 *
 * Design choices
 *   - Pure vanilla JS, zero dependencies. Loads on schedule.php only.
 *   - Debounced 120ms so typing on slow Android devices doesn't stall.
 *   - Case-insensitive, accent-insensitive via normalize NFD + strip.
 *   - Shows an empty-state hint when the filter excludes every card.
 *   - Escape key and the clear button both reset the filter.
 *   - aria-live on the count announcement so screen readers hear the
 *     filter status change.
 */
(function () {
    'use strict';

    // Only run on the mobile schedule execution view
    var container = document.querySelector('.mw-mc-container');
    if (!container) return;
    if (container.dataset.searchInstalled) return;
    container.dataset.searchInstalled = '1';

    // ── Input element ────────────────────────────────────
    var wrap = document.createElement('div');
    wrap.className = 'mw-mc-search-wrap';
    wrap.innerHTML =
        '<div class="mw-mc-search-field">' +
            '<svg class="mw-mc-search-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">' +
                '<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>' +
            '</svg>' +
            '<input type="text" class="mw-mc-search-input" placeholder="Search customer, address, service…" ' +
                'autocomplete="off" autocorrect="off" spellcheck="false" aria-label="Search schedule">' +
            '<button type="button" class="mw-mc-search-clear" aria-label="Clear search" hidden>&times;</button>' +
        '</div>' +
        '<div class="mw-mc-search-count" role="status" aria-live="polite"></div>';

    // Insert after the top bar so the sticky header stays clean
    var topBar = container.querySelector('.mw-mc-topbar');
    if (topBar && topBar.nextSibling) {
        container.insertBefore(wrap, topBar.nextSibling);
    } else {
        container.insertBefore(wrap, container.firstChild);
    }

    var input = wrap.querySelector('.mw-mc-search-input');
    var clear = wrap.querySelector('.mw-mc-search-clear');
    var count = wrap.querySelector('.mw-mc-search-count');

    // ── Filter logic ─────────────────────────────────────
    function normalize(s) {
        return String(s || '')
            .toLowerCase()
            .normalize('NFD').replace(/[\u0300-\u036f]/g, '');
    }

    var cards = null;           // lazily captured
    var cardText = null;        // cached normalized text per card

    function rebuildCardIndex() {
        cards = Array.prototype.slice.call(container.querySelectorAll('.mw-mc-card'));
        cardText = cards.map(function (el) { return normalize(el.textContent); });
    }

    function applyFilter(query) {
        if (!cards) rebuildCardIndex();
        var q = normalize(query).trim();
        var shown = 0;

        for (var i = 0; i < cards.length; i++) {
            var hit = q === '' || cardText[i].indexOf(q) !== -1;
            cards[i].style.display = hit ? '' : 'none';
            if (hit) shown++;
        }

        // Update the count label. When q is empty we say nothing so the
        // screen reader isn't spammed on page load.
        if (q === '') {
            count.textContent = '';
            wrap.classList.remove('mw-mc-search-active');
        } else {
            wrap.classList.add('mw-mc-search-active');
            count.textContent = shown === 0
                ? 'No stops match "' + query + '"'
                : shown + ' match' + (shown === 1 ? '' : 'es');
        }

        clear.hidden = q === '';
    }

    // Debounce keyup so fast typists don't reflow on every character
    var debounceTimer = null;
    input.addEventListener('input', function () {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(function () { applyFilter(input.value); }, 120);
    });

    // Escape clears
    input.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            input.value = '';
            applyFilter('');
            input.blur();
        }
    });

    clear.addEventListener('click', function () {
        input.value = '';
        applyFilter('');
        input.focus();
    });

    // Re-index when schedule JS replaces cards (e.g. route reordering).
    // IMPORTANT: the pill-workflow setInterval fires every second and
    // mutates innerHTML inside each .mw-mc-card (live timer display).
    // If we re-index on every mutation the card index rebuilds every
    // second while the search box has text, stalling the mobile thread
    // and making the keyboard feel frozen after one keystroke.
    // Only react to structural mutations whose target is NOT inside an
    // existing card — those represent actual card adds/removes.
    if (typeof MutationObserver !== 'undefined') {
        var mo = new MutationObserver(function (mutations) {
            var isStructural = mutations.some(function (m) {
                return typeof m.target.closest !== 'function' ||
                       !m.target.closest('.mw-mc-card');
            });
            if (!isStructural) return;
            cards = null;
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(function () {
                if (input.value) applyFilter(input.value);
            }, 120);
        });
        mo.observe(container, { childList: true, subtree: true });
    }
}());
