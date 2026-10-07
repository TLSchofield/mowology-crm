/**
 * Penny's brain — a slowly turning 3D shape of triangles on her dashboard card.
 *
 * 500 shapes, always the same (built from the shape number, no randomness between page
 * loads): shape N has at least N + 3 triangles, and the families alternate — double
 * pyramids and twisted double pyramids while small, then geodesic spheres (from a
 * tetrahedron, octahedron or icosahedron) — with "brain folds" that deepen as she grows.
 * Every thing she has learned (PennyBrainService) moves her on one shape and lights one
 * more triangle; the newest one pulses; she glows brighter the more often she's right.
 *
 * Markup: <button class="mw-brain" data-units data-bright data-parts='[…]'><canvas></canvas></button>
 * No libraries — canvas 2D with a hand-rolled projection.
 */
(function () {
    'use strict';
    var SHAPES = 500;

    // ── Deterministic randomness per shape ─────────────────────────────────
    function rng(seed) {
        return function () {
            seed |= 0; seed = seed + 0x6D2B79F5 | 0;
            var t = Math.imul(seed ^ seed >>> 15, 1 | seed);
            t = t + Math.imul(t ^ t >>> 7, 61 | t) ^ t;
            return ((t ^ t >>> 14) >>> 0) / 4294967296;
        };
    }
    function norm(v) { var l = Math.hypot(v[0], v[1], v[2]) || 1; return [v[0] / l, v[1] / l, v[2] / l]; }

    // ── Families ───────────────────────────────────────────────────────────
    function bipyramid(n) {                     // 2n triangles
        var ring = [], tris = [], top = [0, 1.15, 0], bot = [0, -1.15, 0];
        for (var i = 0; i < n; i++) { var a = i / n * 2 * Math.PI; ring.push([Math.cos(a), 0, Math.sin(a)]); }
        for (i = 0; i < n; i++) { var p = ring[i], q = ring[(i + 1) % n]; tris.push([top, q, p], [bot, p, q]); }
        return tris;
    }
    function gyrobipyramid(n) {                 // 4n triangles: two pyramids on an antiprism
        var up = [], dn = [], tris = [], top = [0, 1.25, 0], bot = [0, -1.25, 0];
        for (var i = 0; i < n; i++) {
            var a = i / n * 2 * Math.PI, b = a + Math.PI / n;
            up.push([Math.cos(a), 0.38, Math.sin(a)]); dn.push([Math.cos(b), -0.38, Math.sin(b)]);
        }
        for (i = 0; i < n; i++) {
            var j = (i + 1) % n;
            tris.push([top, up[j], up[i]], [up[i], up[j], dn[i]], [up[j], dn[j], dn[i]], [bot, dn[i], dn[j]]);
        }
        return tris;
    }
    var BASES = {
        tetra: function () {
            var V = [[1, 1, 1], [-1, -1, 1], [-1, 1, -1], [1, -1, -1]].map(norm);
            return [[0, 1, 2], [0, 3, 1], [0, 2, 3], [1, 3, 2]].map(function (f) { return [V[f[0]], V[f[1]], V[f[2]]]; });
        },
        octa: function () {
            var V = [[1, 0, 0], [-1, 0, 0], [0, 1, 0], [0, -1, 0], [0, 0, 1], [0, 0, -1]];
            return [[0, 2, 4], [2, 1, 4], [1, 3, 4], [3, 0, 4], [2, 0, 5], [1, 2, 5], [3, 1, 5], [0, 3, 5]]
                .map(function (f) { return [V[f[0]], V[f[1]], V[f[2]]]; });
        },
        ico: function () {
            var t = (1 + Math.sqrt(5)) / 2;
            var V = [[-1, t, 0], [1, t, 0], [-1, -t, 0], [1, -t, 0], [0, -1, t], [0, 1, t], [0, -1, -t], [0, 1, -t], [t, 0, -1], [t, 0, 1], [-t, 0, -1], [-t, 0, 1]].map(norm);
            return [[0, 11, 5], [0, 5, 1], [0, 1, 7], [0, 7, 10], [0, 10, 11], [1, 5, 9], [5, 11, 4], [11, 10, 2], [10, 7, 6], [7, 1, 8],
                    [3, 9, 4], [3, 4, 2], [3, 2, 6], [3, 6, 8], [3, 8, 9], [4, 9, 5], [2, 4, 11], [6, 2, 10], [8, 6, 7], [9, 8, 1]]
                .map(function (f) { return [V[f[0]], V[f[1]], V[f[2]]]; });
        }
    };
    var BASE_FACES = { tetra: 4, octa: 8, ico: 20 };
    function geodesic(base, f) {                // BASE_FACES · f² triangles on a sphere
        var out = [];
        BASES[base]().forEach(function (T) {
            var A = T[0], B = T[1], C = T[2];
            function pt(i, j) {                  // barycentric grid point
                var a = (f - i - j) / f, b = i / f, c = j / f;
                return norm([a * A[0] + b * B[0] + c * C[0], a * A[1] + b * B[1] + c * C[1], a * A[2] + b * B[2] + c * C[2]]);
            }
            for (var i = 0; i < f; i++) {
                for (var j = 0; j < f - i; j++) {
                    out.push([pt(i, j), pt(i + 1, j), pt(i, j + 1)]);
                    if (i + j < f - 1) out.push([pt(i + 1, j), pt(i + 1, j + 1), pt(i, j + 1)]);
                }
            }
        });
        return out;
    }

    /** Shape k (1…500): its family, triangles and folds — the same every time. */
    function shape(k) {
        var need = k + 3, r = rng(k * 7919), fam, tris, name;
        if (k === 1) {
            tris = geodesic('tetra', 1); name = 'tetrahedron'; fam = 'geo';
        } else if (k <= 90 && k % 3 !== 0) {
            if (k % 3 === 1) { var n = Math.max(3, Math.ceil(need / 2)); tris = bipyramid(n); name = n + '-sided double pyramid'; }
            else { var m = Math.max(3, Math.ceil(need / 4)); tris = gyrobipyramid(m); name = m + '-sided twisted double pyramid'; }
            fam = 'pyramid';
        } else {
            var best = null;                     // geodesic with the least overshoot; rotate bases for variety
            ['tetra', 'octa', 'ico'].forEach(function (b, idx) {
                var f = Math.max(1, Math.ceil(Math.sqrt(need / BASE_FACES[b])));
                var faces = BASE_FACES[b] * f * f, score = faces + ((idx + k) % 3) * 0.5;
                if (!best || score < best.score) best = { b: b, f: f, score: score };
            });
            tris = geodesic(best.b, best.f);
            name = { tetra: 'tetra', octa: 'octa', ico: 'icosa' }[best.b] + ' geodesic, frequency ' + best.f;
            fam = 'geo';
        }
        // Brain folds: a few seeded waves; deeper and finer as k grows.
        var depth = 0.02 + 0.16 * (k / SHAPES), waves = [];
        for (var w = 0; w < 3; w++) {
            waves.push({ d: norm([r() - 0.5, r() - 0.5, r() - 0.5]), k: 2 + Math.floor(r() * (2 + k / 60)), ph: r() * 6.283 });
        }
        // Its own turn in space, so no two shapes look alike.
        var ax = r() * 6.283, ay = r() * 6.283, cx = Math.cos(ax), sx = Math.sin(ax), cy = Math.cos(ay), sy = Math.sin(ay);
        function turn(v) {
            var y = v[1] * cx - v[2] * sx, z = v[1] * sx + v[2] * cx;
            return [v[0] * cy + z * sy, y, -v[0] * sy + z * cy];
        }
        var cache = new Map();
        function fold(v) {
            var key = v[0].toFixed(4) + ',' + v[1].toFixed(4) + ',' + v[2].toFixed(4);
            if (cache.has(key)) return cache.get(key);
            var s = 0;
            waves.forEach(function (wv) { s += Math.sin(wv.k * (v[0] * wv.d[0] + v[1] * wv.d[1] + v[2] * wv.d[2]) * Math.PI + wv.ph); });
            var rad = 1 + depth * s / 3, out = turn([v[0] * rad, v[1] * rad, v[2] * rad]);
            cache.set(key, out);
            return out;
        }
        tris = tris.map(function (t) { return [fold(t[0]), fold(t[1]), fold(t[2])]; });
        return { k: k, tris: tris, name: name, family: fam };
    }

    /** Which triangles light first: sweeping from the front-top, like a thought spreading. */
    function lightOrder(tris) {
        return tris.map(function (t, i) {
            var c = [(t[0][0] + t[1][0] + t[2][0]) / 3, (t[0][1] + t[1][1] + t[2][1]) / 3, (t[0][2] + t[1][2] + t[2][2]) / 3];
            return { i: i, k: -(c[1] * 0.8 + c[2] * 0.5 + c[0] * 0.2) };
        }).sort(function (a, b) { return a.k - b.k; }).map(function (o) { return o.i; });
    }

    var still = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    function draw(canvas, units, bright) {
        var k = Math.max(1, Math.min(SHAPES, units + 1));
        var sh = shape(k), order = lightOrder(sh.tris), litN = Math.min(units, sh.tris.length);
        var lit = new Uint8Array(sh.tris.length);
        order.slice(0, litN).forEach(function (i) { lit[i] = 1; });
        var newest = litN > 0 ? order[litN - 1] : -1;
        var t0 = performance.now(), alive = true;
        function frame(now) {
            if (!alive || !canvas.isConnected) return;
            var dpr = window.devicePixelRatio || 1, W = canvas.clientWidth, H = canvas.clientHeight;
            if (!W || !H) { requestAnimationFrame(frame); return; }
            if (canvas.width !== Math.round(W * dpr)) { canvas.width = Math.round(W * dpr); canvas.height = Math.round(H * dpr); }
            var g = canvas.getContext('2d');
            g.setTransform(dpr, 0, 0, dpr, 0, 0);
            var R = Math.min(W, H) * 0.32;
            var a = still ? 0.6 : (now - t0) / 11000 * 2 * Math.PI, tilt = 0.45;
            var ca = Math.cos(a), sa = Math.sin(a), ct = Math.cos(tilt), st = Math.sin(tilt);
            function P(v) {
                var x = v[0] * ca + v[2] * sa, z = -v[0] * sa + v[2] * ca, y = v[1];
                var y2 = y * ct - z * st, z2 = y * st + z * ct, s = 3.2 / (3.2 - z2);
                return [W / 2 + x * R * s, H / 2 - y2 * R * s, z2];
            }
            g.clearRect(0, 0, W, H);
            var faces = new Array(sh.tris.length);
            for (var i = 0; i < sh.tris.length; i++) {
                var tr = sh.tris[i], p0 = P(tr[0]), p1 = P(tr[1]), p2 = P(tr[2]);
                var nz = (p1[0] - p0[0]) * (p2[1] - p0[1]) - (p1[1] - p0[1]) * (p2[0] - p0[0]);
                faces[i] = { p: [p0, p1, p2], z: (p0[2] + p1[2] + p2[2]) / 3, i: i, front: nz < 0 };
            }
            faces.sort(function (x, y) { return x.z - y.z; });
            var pulse = still ? 1 : 0.5 + 0.5 * Math.sin(now / 380), thin = sh.tris.length > 300 ? 0.5 : 0.8;
            for (var f = 0; f < faces.length; f++) {
                var fc = faces[f], depth = (fc.z + 1.2) / 2.4;
                g.beginPath(); g.moveTo(fc.p[0][0], fc.p[0][1]); g.lineTo(fc.p[1][0], fc.p[1][1]); g.lineTo(fc.p[2][0], fc.p[2][1]); g.closePath();
                if (lit[fc.i]) {
                    var al = (fc.front ? 0.3 + 0.6 * depth : 0.1) * (0.45 + 0.55 * bright);
                    if (fc.i === newest) al = Math.min(1, al + 0.4 * pulse);
                    g.fillStyle = 'rgba(127,216,88,' + al.toFixed(3) + ')'; g.fill();
                    g.strokeStyle = 'rgba(200,245,180,' + (fc.front ? 0.7 : 0.18) + ')';
                } else {
                    g.strokeStyle = 'rgba(232,243,240,' + (fc.front ? 0.26 : 0.07) + ')';
                }
                g.lineWidth = thin; g.stroke();
            }
            if (!still) requestAnimationFrame(frame);
        }
        requestAnimationFrame(frame);
        return { shape: sh, lit: litN, stop: function () { alive = false; } };
    }

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function open(btn, units, bright, parts, since) {
        var pop = document.createElement('div');
        pop.className = 'mw-brain-pop';
        pop.setAttribute('role', 'dialog');
        pop.setAttribute('aria-label', "Penny's brain");
        pop.innerHTML = '<div class="mw-brain-card"><button type="button" class="mw-brain-close" aria-label="Close">✕</button>' +
            '<canvas class="mw-brain-big"></canvas><div class="mw-brain-facts"></div></div>';
        document.body.appendChild(pop);
        var view = draw(pop.querySelector('canvas'), units, bright);
        var k = view.shape.k;
        pop.querySelector('.mw-brain-facts').innerHTML =
            '<h4>Penny\'s brain · shape ' + k + ' of ' + SHAPES + '</h4>' +
            '<p>' + esc(view.shape.name) + ' · ' + view.lit + ' of ' + view.shape.tris.length + ' triangles lit</p>' +
            (parts.length ? '<ul>' + parts.map(function (p) { return '<li>▲ ' + esc(p.label) + '</li>'; }).join('') + '</ul>'
                          : '<p>Nothing learned yet. Every receipt you approve or correct teaches her something.</p>') +
            '<p class="mw-brain-note">Each thing she learns lights a triangle and moves her on to the next, more complex shape. ' +
            'She glows brighter the more often she\'s right first time (' + Math.round(bright * 100) + '%).' +
            (since ? ' Counting since ' + esc(since) + '.' : '') + '</p>';
        function close() { view.stop(); pop.remove(); document.removeEventListener('keydown', onKey); btn.focus(); }
        function onKey(e) { if (e.key === 'Escape') close(); }
        pop.addEventListener('click', function (e) { if (e.target === pop || e.target.classList.contains('mw-brain-close')) close(); });
        document.addEventListener('keydown', onKey);
        pop.querySelector('.mw-brain-close').focus();
    }

    function init() {
        document.querySelectorAll('.mw-brain').forEach(function (btn) {
            if (btn.dataset.ready) return;
            btn.dataset.ready = '1';
            var units = parseInt(btn.getAttribute('data-units') || '0', 10) || 0;
            var bright = Math.max(0, Math.min(1, parseFloat(btn.getAttribute('data-bright') || '0.5')));
            var parts = [];
            try { parts = JSON.parse(btn.getAttribute('data-parts') || '[]'); } catch (e) {}
            var view = draw(btn.querySelector('canvas'), units, bright);
            btn.title = "Penny's brain: " + units + ' thing' + (units === 1 ? '' : 's') + ' learned · shape ' + view.shape.k + ' of ' + SHAPES + ' (click for more)';
            var since = btn.getAttribute('data-since') || '';
            btn.addEventListener('click', function () { open(btn, units, bright, parts, since); });
        });
    }
    window.PennyBrain = { shape: shape, SHAPES: SHAPES };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
