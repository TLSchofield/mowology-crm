/**
 * MwCspActions — inline-handler replacement for pages under the nonce CSP (Fable #22, 2026-10-12).
 *
 * The public site and customer portal send `script-src-attr 'none'`, so onclick=/onerror=/
 * onsubmit= attributes no longer run. Mark elements with data attributes instead:
 *
 *   data-mw-call="fnName"            call window.fnName on click (or data-mw-on="change|submit")
 *   data-mw-args='["x", 3, "$this"]' JSON arguments; "$this" = the element, "$event" = the event,
 *                                    "$checked" = element.checked. A false return = preventDefault.
 *   data-mw-toggle="elementId"       click toggles class "show" on #elementId
 *   data-mw-print                    click opens the print dialog
 *   data-mw-hide-on-error="N"        <img>: on load error hide the element N parents up (0 = itself)
 *
 * Only the innermost [data-mw-call] around the target runs. Load with
 * <script src="/assets/js/mw-csp-actions.js"></script> (external, so no nonce needed).
 */
(function () {
    'use strict';
    if (window.MwCspActions) return;

    function args(el, ev) {
        var raw = el.getAttribute('data-mw-args');
        var list = [];
        if (raw) { try { list = JSON.parse(raw); } catch (e) { list = []; } }
        if (!Array.isArray(list)) list = [list];
        return list.map(function (v) {
            if (v === '$this') return el;
            if (v === '$event') return ev;
            if (v === '$checked') return !!el.checked;
            return v;
        });
    }

    function onEvent(ev) {
        var t = ev.target;
        if (!t || !t.closest) return;

        if (ev.type === 'click') {
            var tog = t.closest('[data-mw-toggle]');
            if (tog) {
                var target = document.getElementById(tog.getAttribute('data-mw-toggle'));
                if (target) target.classList.toggle('show');
                return;
            }
            if (t.closest('[data-mw-print]')) { window.print(); return; }
        }

        var el = t.closest('[data-mw-call]');
        if (!el) return;
        if ((el.getAttribute('data-mw-on') || 'click') !== ev.type) return;
        var fn = window[el.getAttribute('data-mw-call')];
        if (typeof fn !== 'function') return;
        if (fn.apply(el, args(el, ev)) === false) ev.preventDefault();
    }

    function hide(img) {
        var n = parseInt(img.getAttribute('data-mw-hide-on-error') || '0', 10) || 0;
        var el = img;
        while (n-- > 0 && el.parentElement) el = el.parentElement;
        el.style.display = 'none';
    }

    function scanBroken() {
        var imgs = document.querySelectorAll('img[data-mw-hide-on-error]');
        for (var i = 0; i < imgs.length; i++) {
            var im = imgs[i];
            if (im.complete && im.naturalWidth === 0 && im.getAttribute('src')) hide(im);
        }
    }

    document.addEventListener('click', onEvent);
    document.addEventListener('change', onEvent);
    document.addEventListener('submit', onEvent);
    // error does not bubble — capture it; images that failed before this ran are caught by scanBroken.
    document.addEventListener('error', function (ev) {
        var t = ev.target;
        if (t && t.tagName === 'IMG' && t.hasAttribute('data-mw-hide-on-error')) hide(t);
    }, true);
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', scanBroken);
    else scanBroken();
    window.addEventListener('load', scanBroken);

    window.MwCspActions = { scanBroken: scanBroken };
})();
