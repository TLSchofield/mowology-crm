/**
 * MwPhotoMarkers — numbered badges on a photo, positioned by fractions of the image (0–1 from
 * the top-left, the same convention as visit_photo_annotations). No dependencies; used by the
 * CRM Plants card (pruning plans) and the customer portal, so it lives under /assets/js.
 *
 *   var m = MwPhotoMarkers.render(container, {
 *     src: '/_media/…jpg', alt: '',
 *     markers: [{ n: 1, type: 'shorten', x: 0.42, y: 0.31, confidence: 0.8, title: 'Shorten…' }],
 *     draggable: false,                 // true: pointer-drag moves a badge; onMove fires on release
 *     onMove: function (n, x, y) {},    // fractions, 4 dp
 *     onSelect: function (n) {}         // click/tap on a badge
 *   });
 *   m.setActive(2); m.positions() → [{n, x, y}]; m.destroy();
 *
 * Markers with confidence < 0.35 render dashed ("unsure"); confidence 0 is not drawn at all.
 */
(function () {
    'use strict';
    var CSS_WRAP = 'mw-pmark-wrap', CSS_MARK = 'mw-pmark';

    function clamp01(v) { return Math.max(0, Math.min(1, v)); }
    function round4(v) { return Math.round(v * 10000) / 10000; }

    function render(container, opts) {
        opts = opts || {};
        container.innerHTML = '';
        var wrap = document.createElement('div');
        wrap.className = CSS_WRAP;
        var img = document.createElement('img');
        img.src = opts.src || '';
        img.alt = opts.alt || '';
        img.draggable = false;
        wrap.appendChild(img);
        container.appendChild(wrap);

        var badges = {};
        var positions = {};
        var active = null;

        (opts.markers || []).forEach(function (mk) {
            if (!(mk.confidence > 0)) return;
            var b = document.createElement('span');
            b.className = CSS_MARK + (mk.confidence < 0.35 ? ' is-unsure' : '') + (opts.draggable ? ' is-draggable' : '');
            b.setAttribute('data-type', mk.type || '');
            b.setAttribute('data-n', String(mk.n));
            b.setAttribute('role', 'button');
            b.setAttribute('tabindex', '0');
            if (mk.title) b.title = mk.title;
            b.textContent = String(mk.n);
            positions[mk.n] = { x: clamp01(+mk.x || 0), y: clamp01(+mk.y || 0) };
            place(b, positions[mk.n]);
            badges[mk.n] = b;
            wrap.appendChild(b);
            b.addEventListener('click', function (e) {
                e.preventDefault();
                if (b.getAttribute('data-moved') === '1') { b.removeAttribute('data-moved'); return; }
                setActive(mk.n);
                if (opts.onSelect) opts.onSelect(mk.n);
            });
            if (opts.draggable) drag(b, mk.n);
        });

        function place(b, p) { b.style.left = (p.x * 100) + '%'; b.style.top = (p.y * 100) + '%'; }

        function frac(ev) {
            var r = img.getBoundingClientRect();
            var x = (ev.clientX - r.left) / Math.max(1, r.width);
            var y = (ev.clientY - r.top) / Math.max(1, r.height);
            return { x: clamp01(x), y: clamp01(y) };
        }

        function drag(b, n) {
            var moving = false, start = null;
            b.addEventListener('pointerdown', function (ev) {
                if (ev.button !== undefined && ev.button !== 0) return;
                moving = true; start = { x: ev.clientX, y: ev.clientY };
                b.classList.add('is-dragging');
                try { b.setPointerCapture(ev.pointerId); } catch (e) {}
                ev.preventDefault();
            });
            b.addEventListener('pointermove', function (ev) {
                if (!moving) return;
                var p = frac(ev);
                positions[n] = p; place(b, p);
            });
            function end(ev) {
                if (!moving) return;
                moving = false;
                b.classList.remove('is-dragging');
                var dist = start ? Math.hypot(ev.clientX - start.x, ev.clientY - start.y) : 0;
                if (dist > 3) {
                    b.setAttribute('data-moved', '1');
                    setTimeout(function () { b.removeAttribute('data-moved'); }, 0);
                    if (opts.onMove) opts.onMove(n, round4(positions[n].x), round4(positions[n].y));
                }
            }
            b.addEventListener('pointerup', end);
            b.addEventListener('pointercancel', end);
        }

        function setActive(n) {
            if (active !== null && badges[active]) badges[active].classList.remove('is-active');
            active = (n !== null && badges[n]) ? n : null;
            if (active !== null) badges[active].classList.add('is-active');
        }

        return {
            setActive: setActive,
            positions: function () { return Object.keys(positions).map(function (k) { return { n: +k, x: round4(positions[k].x), y: round4(positions[k].y) }; }); },
            destroy: function () { container.innerHTML = ''; }
        };
    }

    window.MwPhotoMarkers = { render: render };
})();
