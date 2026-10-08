/**
 * A department head's brain — a slowly turning 3D shape of triangles on their dashboard card.
 * Shared by every head (Sam, Otto, Mia, Charlie); generalised from Penny's penny-brain.js.
 *
 * 500 shapes, always the same (built from the shape number, no randomness between page
 * loads): shape N has at least N + 3 triangles, and the families alternate — double
 * pyramids and twisted double pyramids while small, then geodesic spheres (from a
 * tetrahedron, octahedron or icosahedron) — with "brain folds" that deepen as the head grows.
 * Every thing the head has learned (HeadBrain.php) moves it on one shape and lights one
 * more triangle; the newest one pulses; it glows brighter the more often the head is right.
 *
 * Strength tiers (2026-10-07): a part with a stable key ("vendor:12", "payee:telus mobility")
 * is one learned item. It lights ITS OWN triangle (key hash → triangle, linear probing) in
 * the material of its tier — Obsidian → Black → Bronze → Silver → Gold → White → Platinum —
 * from its `strength` (confirmations in a row; HeadBrain::TIERS holds the thresholds, TIERS
 * below mirrors them). Parts without a key are counts: they light the rest in sweep order,
 * as Bronze. The popup lists items by group, then tier, with a swatch and a legend.
 * Penny's card uses this too (.mw-brain[data-head]); penny-brain.js is no longer loaded.
 *
 * Full page (2026-10-07): clicking a head's brain opens /crm/brain.php?head=<slug> (large
 * brain, tiers, searchable list, client view) instead of the pop-up; the pop-up stays only
 * for a head with no page. canvas[data-brain-stage] on that page is drawn large here.
 * The pop-up's brain used to sit below the fold: the grid card inherited align-items:center
 * from an older flex rule, so the canvas was centred against the long list (CSS, fixed).
 *
 * Markup: <button class="mw-head-brain" data-head="Sam" data-units data-bright data-parts='[…]'
 *                 data-since data-empty="…" data-teach="…"><canvas></canvas></button>
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

    // ── Strength tiers ─────────────────────────────────────────────────────
    // Mirrors HeadBrain::TIERS (app/Services/HeadBrain.php) — the thresholds live there;
    // change both together. Strength = confirmations in a row; unset reads as Bronze.
    // Each tier is a material: base colour, specular highlight, how shiny, how much ambient.
    var TIERS = [
        { slug: 'obsidian', name: 'Obsidian', min: 1,  base: [16, 13, 22],    hi: [190, 150, 255], shin: 40, spec: 1.0,  amb: 0.75, glass: true, gloss: 0.6 },
        { slug: 'black',    name: 'Black',    min: 2,  base: [58, 60, 64],    hi: [150, 152, 156], shin: 4,  spec: 0.12, amb: 0.7 },
        { slug: 'bronze',   name: 'Bronze',   min: 3,  base: [150, 86, 40],   hi: [255, 196, 140], shin: 22, spec: 0.95, amb: 0.5,  metal: 0.35, gloss: 0.55 },
        { slug: 'silver',   name: 'Silver',   min: 5,  base: [128, 138, 152], hi: [255, 255, 255], shin: 30, spec: 1.1,  amb: 0.45, metal: 0.45, gloss: 0.75 },
        { slug: 'gold',     name: 'Gold',     min: 10, base: [214, 164, 34],  hi: [255, 248, 196], shin: 26, spec: 1.1,  amb: 0.45, metal: 0.4,  gloss: 0.75 },
        { slug: 'white',    name: 'White',    min: 20, base: [246, 242, 232], hi: [255, 255, 255], shin: 70, spec: 0.6,  amb: 0.88, gloss: 0.45 },
        { slug: 'platinum', name: 'Platinum', min: 50, base: [196, 208, 228], hi: [240, 248, 255], shin: 18, spec: 1.2,  amb: 0.6,  metal: 0.5, gloss: 0.9, irid: true, glow: true }
    ];
    var UNSET_TIER = 2;
    function tierRank(strength) {
        if (strength === null || strength === undefined || strength === '' || isNaN(strength)) return UNSET_TIER;
        var s = Number(strength), r = 0;
        for (var i = 0; i < TIERS.length; i++) if (s >= TIERS[i].min) r = i;
        return r;
    }

    // ── Shading ────────────────────────────────────────────────────────────
    var LIGHT = norm([-0.45, 0.65, 0.62]), HALF = norm([LIGHT[0], LIGHT[1], LIGHT[2] + 1]);
    function clamp(v) { return v < 0 ? 0 : v > 255 ? 255 : Math.round(v); }
    function mix(a, b, t) { return [a[0] + (b[0] - a[0]) * t, a[1] + (b[1] - a[1]) * t, a[2] + (b[2] - a[2]) * t]; }
    function hsl(h, s, l) {                        // h 0..360 → rgb 0..255
        h = ((h % 360) + 360) % 360 / 360;
        function f(n) { var k = (n + h * 12) % 12, a = s * Math.min(l, 1 - l); return 255 * (l - a * Math.max(-1, Math.min(k - 3, 9 - k, 1))); }
        return [f(0), f(8), f(4)];
    }
    /** Colour of a face of this material with view-space normal n (facing the viewer), at time t. */
    function shade(T, n, t) {
        var d = Math.max(0, n[0] * LIGHT[0] + n[1] * LIGHT[1] + n[2] * LIGHT[2]);
        var sp = Math.pow(Math.max(0, n[0] * HALF[0] + n[1] * HALF[1] + n[2] * HALF[2]), T.shin) * T.spec;
        var hi = T.hi;
        if (T.irid) hi = hsl(200 + n[0] * 140 + n[1] * 90 + t * 0.012, 0.75, 0.78);      // cool rainbow sheen that drifts as it turns
        if (T.glass) hi = n[0] > 0 ? [160, 110, 240] : [70, 210, 150];                  // obsidian: purple / green glints
        var k = T.amb + (1 - T.amb) * d;
        var c = [T.base[0] * k + hi[0] * sp, T.base[1] * k + hi[1] * sp, T.base[2] * k + hi[2] * sp];
        // Metals mirror a soft studio "sky": brighter where the face tilts up, darker where it tilts down.
        if (T.metal) {
            var env = mix([40, 44, 52], T.hi, Math.max(0, Math.min(1, (n[1] + 1) / 2)));
            c = mix(c, env, T.metal);
        }
        return [clamp(c[0]), clamp(c[1]), clamp(c[2])];
    }
    function edgeOf(T, n, onLight) {
        if (T.glass) return n[0] > 0 ? [150, 100, 220] : [70, 190, 140];
        if (onLight) return mix(T.base, [20, 24, 28], T.slug === 'black' ? 0.6 : 0.5);
        return mix(T.base, [255, 255, 255], T.slug === 'black' ? 0.3 : 0.4);
    }
    function rgba(c, a) { return 'rgba(' + clamp(c[0]) + ',' + clamp(c[1]) + ',' + clamp(c[2]) + ',' + a.toFixed(3) + ')'; }

    /** Is the brain drawn on a light background (the popup) or the dark one (the card)? */
    function onLightBg(el) {
        for (var i = 0; el && i < 5; i++, el = el.parentElement) {
            var cs = getComputedStyle(el), src = cs.backgroundImage !== 'none' ? cs.backgroundImage : cs.backgroundColor;
            var m = src.match(/rgba?\([^)]+\)/g);
            if (!m) continue;
            var cols = m.map(function (c) { return c.replace(/[^\d.,]/g, '').split(',').map(Number); })
                        .filter(function (c) { return c.length < 4 || c[3] > 0.05; });
            if (!cols.length) continue;
            var l = cols.reduce(function (s, c) { return s + (0.299 * c[0] + 0.587 * c[1] + 0.114 * c[2]) / 255; }, 0) / cols.length;
            return l > 0.6;
        }
        return false;
    }

    // ── Which triangle is whose ────────────────────────────────────────────
    function hash(str) {                           // FNV-1a, 32-bit
        var h = 0x811c9dc5;
        for (var i = 0; i < str.length; i++) { h ^= str.charCodeAt(i); h = Math.imul(h, 0x01000193); }
        return h >>> 0;
    }
    function isItem(p) { return p && typeof p.key === 'string' && p.key.indexOf(':') > 0; }

    /**
     * Each keyed item lands on "its" triangle (key hash modulo the triangle count, linear
     * probing on a collision; keys placed in sorted order so it never depends on list order).
     * The rest of the units (counted things with no key) light in the old sweep order.
     */
    function assign(sh, units, items) {
        var n = sh.tris.length, owner = new Array(n), lit = 0;
        items.slice().sort(function (a, b) { return a.key < b.key ? -1 : a.key > b.key ? 1 : 0; }).forEach(function (it) {
            if (lit >= n) return;
            var i = hash(it.key) % n;
            while (owner[i]) i = (i + 1) % n;
            owner[i] = { tier: tierRank(it.strength), item: it };
            lit++;
        });
        var order = lightOrder(sh.tris), rest = Math.max(0, units - items.length), last = -1;
        for (var o = 0; o < order.length && rest > 0; o++) {
            if (owner[order[o]]) continue;
            owner[order[o]] = { tier: UNSET_TIER, item: null };
            last = order[o]; rest--; lit++;
        }
        // Newest: the item touched most recently (its `at`), else the last counted triangle.
        var newest = last, at = '';
        for (var i = 0; i < n; i++) if (owner[i] && owner[i].item && owner[i].item.at && String(owner[i].item.at) > at) { at = String(owner[i].item.at); newest = i; }
        return { owner: owner, lit: lit, newest: newest };
    }

    var still = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    /**
     * Draw (and turn) a brain on a canvas. It sizes itself to the canvas's CSS box and redraws
     * when that changes (ResizeObserver), turns only while on screen (IntersectionObserver),
     * waits without spinning while the canvas has no size (hidden / display:none), and with
     * prefers-reduced-motion draws one still frame (redrawn on resize).
     * opts.scale: brain radius as a share of the smaller side (default 0.32).
     */
    function draw(canvas, units, bright, items, opts) {
        opts = opts || {};
        units = Math.max(units, items.length);
        var k = Math.max(1, Math.min(SHAPES, units + 1));
        var sh = shape(k), A = assign(sh, units, items), owner = A.owner, newest = A.newest;
        var t0 = performance.now(), alive = true, light = null, raf = 0, visible = true, ro = null, io = null;
        var scale = opts.scale || 0.32;
        function kick() { if (!raf && alive) raf = requestAnimationFrame(frame); }
        function stop() {
            alive = false;
            if (raf) cancelAnimationFrame(raf);
            if (ro) ro.disconnect();
            if (io) io.disconnect();
        }
        if (window.ResizeObserver) { ro = new ResizeObserver(kick); ro.observe(canvas); }
        if (window.IntersectionObserver) {
            io = new IntersectionObserver(function (es) {
                visible = es[es.length - 1].isIntersecting;
                if (visible) kick();
            });
            io.observe(canvas);
        }
        function frame(now) {
            raf = 0;
            if (!alive) return;
            if (!canvas.isConnected) { stop(); return; }
            var dpr = window.devicePixelRatio || 1, W = canvas.clientWidth, H = canvas.clientHeight;
            if (!W || !H) { if (!ro) setTimeout(kick, 250); return; }   // no size yet: ResizeObserver wakes us
            if (canvas.width !== Math.round(W * dpr) || canvas.height !== Math.round(H * dpr)) { canvas.width = Math.round(W * dpr); canvas.height = Math.round(H * dpr); }
            if (light === null) light = onLightBg(canvas);
            var g = canvas.getContext('2d');
            g.setTransform(dpr, 0, 0, dpr, 0, 0);
            var R = Math.min(W, H) * scale;
            var a = still ? 0.6 : (now - t0) / 11000 * 2 * Math.PI, tilt = 0.45;
            var ca = Math.cos(a), sa = Math.sin(a), ct = Math.cos(tilt), st = Math.sin(tilt);
            function P(v) {
                var x = v[0] * ca + v[2] * sa, z = -v[0] * sa + v[2] * ca, y = v[1];
                var y2 = y * ct - z * st, z2 = y * st + z * ct, s = 3.2 / (3.2 - z2);
                return [W / 2 + x * R * s, H / 2 - y2 * R * s, z2, x, y2];
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
            var t = still ? 0 : now - t0, fade = 0.72 + 0.28 * bright;
            for (var f = 0; f < faces.length; f++) {
                var fc = faces[f], q = fc.p, own = owner[fc.i];
                g.beginPath(); g.moveTo(q[0][0], q[0][1]); g.lineTo(q[1][0], q[1][1]); g.lineTo(q[2][0], q[2][1]); g.closePath();
                if (own) {
                    var T = TIERS[own.tier];
                    // view-space normal, turned to face the viewer
                    var ux = q[1][3] - q[0][3], uy = q[1][4] - q[0][4], uz = q[1][2] - q[0][2];
                    var vx = q[2][3] - q[0][3], vy = q[2][4] - q[0][4], vz = q[2][2] - q[0][2];
                    var nrm = norm([uy * vz - uz * vy, uz * vx - ux * vz, ux * vy - uy * vx]);
                    if (nrm[2] < 0) nrm = [-nrm[0], -nrm[1], -nrm[2]];
                    var col = shade(T, nrm, t);
                    if (fc.front) {
                        if (T.glow) { g.shadowColor = rgba(light ? [150, 175, 215] : [215, 230, 255], 0.85); g.shadowBlur = 7; }
                        g.fillStyle = rgba(col, fade); g.fill();
                        g.shadowBlur = 0;
                        if (T.gloss) {
                            // Lacquer: a soft highlight across the face from its lit corner, plus a
                            // thin glint band that travels over the shape as it turns.
                            var cx = (q[0][0] + q[1][0] + q[2][0]) / 3, cy = (q[0][1] + q[1][1] + q[2][1]) / 3;
                            var lx = cx - LIGHT[0] * 40, ly = cy - LIGHT[1] * 40, rx = cx + LIGHT[0] * 40, ry = cy + LIGHT[1] * 40;
                            var sheen = g.createLinearGradient(lx, ly, rx, ry);
                            var hiC = T.irid ? hsl(200 + nrm[0] * 140 + t * 0.012, 0.7, 0.85) : T.hi;
                            var band = 0.5 + 0.45 * Math.sin(t / 1300 + fc.i * 0.7 + nrm[0] * 3);
                            sheen.addColorStop(0, rgba(hiC, 0));
                            sheen.addColorStop(Math.max(0, band - 0.12), rgba(hiC, 0));
                            sheen.addColorStop(band, rgba(hiC, 0.55 * T.gloss * fade));
                            sheen.addColorStop(Math.min(1, band + 0.12), rgba(hiC, 0));
                            sheen.addColorStop(1, rgba([0, 0, 0], 0.18 * T.gloss));
                            g.fillStyle = sheen; g.fill();
                        }
                        if (fc.i === newest) { g.fillStyle = 'rgba(127,216,88,' + (0.55 * pulse).toFixed(3) + ')'; g.fill(); }
                        g.strokeStyle = fc.i === newest ? 'rgba(200,245,180,0.9)' : rgba(edgeOf(T, nrm, light), 0.8);
                    } else {
                        g.fillStyle = rgba(col, 0.14); g.fill();
                        g.strokeStyle = rgba(edgeOf(T, nrm, light), 0.16);
                    }
                } else {
                    g.strokeStyle = 'rgba(232,243,240,' + (fc.front ? 0.26 : 0.07) + ')';
                }
                g.lineWidth = thin; g.stroke();
            }
            if (!still && visible) kick();
        }
        kick();
        return { shape: sh, lit: A.lit, stop: stop };
    }

    /** A small swatch of a tier's material, as an image (drawn once per tier). */
    var swatches = {};
    function swatch(rank) {
        if (swatches[rank]) return swatches[rank];
        var T = TIERS[rank], c = document.createElement('canvas'), s = 2, w = 14;
        c.width = c.height = w * s;
        var g = c.getContext('2d');
        g.scale(s, s);
        var left = shade(T, norm([-0.5, 0.45, 0.75]), 0), right = shade(T, norm([0.55, -0.1, 0.83]), 0);
        var gr = g.createLinearGradient(0, 0, w, w);
        gr.addColorStop(0, rgba(left, 1)); gr.addColorStop(1, rgba(right, 1));
        g.beginPath(); g.moveTo(w / 2, 1); g.lineTo(w - 1, w - 1.5); g.lineTo(1, w - 1.5); g.closePath();
        g.fillStyle = gr; g.fill();
        g.lineWidth = 1; g.strokeStyle = rgba(T.glass ? [150, 100, 220] : mix(T.base, [20, 24, 28], 0.45), 0.9); g.stroke();
        return (swatches[rank] = c.toDataURL());
    }
    function swatchImg(rank) {
        return '<img class="mw-brain-swatch" src="' + swatch(rank) + '" alt="" width="14" height="14">';
    }

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    var SHOW_PER_TIER = 8;
    function itemLine(p) {
        var r = tierRank(p.strength), note = p.note;
        if (!note) {
            if (p.strength === null || p.strength === undefined) note = 'strength not tracked';
            else { var n = p.streak !== undefined ? p.streak : p.strength; note = n + ' in a row'; }
        }
        return '<li>' + swatchImg(r) + ' ' + esc(p.label) + ' · ' + TIERS[r].name + ' · ' + esc(note) +
            (p.corrected_recently ? ' · <span class="mw-brain-corrected">corrected recently</span>' : '') + '</li>';
    }
    /** Items grouped by skill ("Receipt vendors", "Bank payees"…), then by tier, strongest first. */
    function itemsHtml(items) {
        var groups = [], by = {};
        items.forEach(function (p) {
            var gname = p.group || 'Learned';
            if (!by[gname]) { by[gname] = []; groups.push(gname); }
            by[gname].push(p);
        });
        return groups.map(function (gname) {
            var list = by[gname], html = '<div class="mw-brain-group"><h5>' + esc(gname) + ' (' + list.length + ')</h5>';
            for (var r = TIERS.length - 1; r >= 0; r--) {
                var inTier = list.filter(function (p) { return tierRank(p.strength) === r; })
                    .sort(function (a, b) { return (Number(b.strength) || 0) - (Number(a.strength) || 0) || String(a.label).localeCompare(String(b.label)); });
                if (!inTier.length) continue;
                var shown = inTier.slice(0, SHOW_PER_TIER), more = inTier.slice(SHOW_PER_TIER);
                html += '<ul class="mw-brain-tier-list">' + shown.map(itemLine).join('') + '</ul>';
                if (more.length) {
                    html += '<details class="mw-brain-more"><summary>' + more.length + ' more ' + TIERS[r].name + '</summary>' +
                        '<ul class="mw-brain-tier-list">' + more.map(itemLine).join('') + '</ul></details>';
                }
            }
            return html + '</div>';
        }).join('');
    }
    function legendHtml() {
        return '<p class="mw-brain-legend">' + TIERS.map(function (T, r) {
            return '<span>' + swatchImg(r) + ' ' + T.name + '</span>';
        }).join('') + '</p>';
    }

    function open(btn, units, bright, parts, since, who, empty, teach) {
        var items = parts.filter(isItem), counted = parts.filter(function (p) { return !isItem(p); });
        var pop = document.createElement('div');
        pop.className = 'mw-brain-pop mw-head-brain-pop';
        pop.setAttribute('role', 'dialog');
        pop.setAttribute('aria-label', who + "'s brain");
        pop.innerHTML = '<div class="mw-brain-card"><button type="button" class="mw-brain-close" aria-label="Close">✕</button>' +
            '<canvas class="mw-brain-big"></canvas><div class="mw-brain-facts"></div></div>';
        document.body.appendChild(pop);
        var view = draw(pop.querySelector('canvas'), units, bright, items);
        var k = view.shape.k;
        pop.querySelector('.mw-brain-facts').innerHTML =
            '<h4>' + esc(who) + '\'s brain · shape ' + k + ' of ' + SHAPES + '</h4>' +
            '<p>' + esc(view.shape.name) + ' · ' + view.lit + ' of ' + view.shape.tris.length + ' triangles lit</p>' +
            legendHtml() +
            (items.length ? itemsHtml(items) : '') +
            (counted.length ? (items.length ? '<h5 class="mw-brain-also">Also learned</h5>' : '') +
                '<ul>' + counted.map(function (p) { return '<li>▲ ' + esc(p.label) + '</li>'; }).join('') + '</ul>' : '') +
            (!parts.length ? '<p>' + esc(empty) + '</p>' : '') +
            '<p class="mw-brain-note">Each thing ' + esc(who) + ' learns lights its own triangle; its colour is how often in a row you\'ve ' +
            'confirmed it unchanged (Obsidian first seen, Silver 5+, Platinum 50+), and a correction drops it one step. ' +
            esc(teach) + ' (' + Math.round(bright * 100) + '%).' +
            (since ? ' Counting since ' + esc(since) + '.' : '') + '</p>';
        function close() { view.stop(); pop.remove(); document.removeEventListener('keydown', onKey); btn.focus(); }
        function onKey(e) { if (e.key === 'Escape') close(); }
        pop.addEventListener('click', function (e) { if (e.target === pop || e.target.classList.contains('mw-brain-close')) close(); });
        document.addEventListener('keydown', onKey);
        pop.querySelector('.mw-brain-close').focus();
    }

    function init() {
        document.querySelectorAll('.mw-head-brain, .mw-brain[data-head]').forEach(function (btn) {
            if (btn.dataset.ready) return;
            btn.dataset.ready = '1';
            var units = parseInt(btn.getAttribute('data-units') || '0', 10) || 0;
            var bright = Math.max(0, Math.min(1, parseFloat(btn.getAttribute('data-bright') || '0.5')));
            var parts = [];
            try { parts = JSON.parse(btn.getAttribute('data-parts') || '[]'); } catch (e) {}
            if (!Array.isArray(parts)) parts = [];
            var view = draw(btn.querySelector('canvas'), units, bright, parts.filter(isItem));
            var who = btn.getAttribute('data-head') || 'Their';
            var empty = btn.getAttribute('data-empty') || 'Nothing learned yet.';
            var teach = btn.getAttribute('data-teach') || 'It glows brighter the more often it\'s right';
            btn.title = who + "'s brain: " + units + ' thing' + (units === 1 ? '' : 's') + ' learned · shape ' + view.shape.k + ' of ' + SHAPES + ' (click for more)';
            var since = btn.getAttribute('data-since') || '';
            var page = PAGES.indexOf(who.toLowerCase()) >= 0 ? '/crm/brain.php?head=' + who.toLowerCase() : '';
            if (page) btn.title = btn.title.replace('(click for more)', '(click to open the full page)');
            btn.addEventListener('click', function () {
                if (page) { window.location.href = page; return; }   // the full page replaces the pop-up
                open(btn, units, bright, parts, since, who, empty, teach);
            });
        });
        // The full brain page (/crm/brain.php): one large brain, no click, caption filled in here.
        document.querySelectorAll('canvas[data-brain-stage]').forEach(function (cv) {
            if (cv.dataset.ready) return;
            cv.dataset.ready = '1';
            var parts = [];
            try { parts = JSON.parse(cv.getAttribute('data-parts') || '[]'); } catch (e) {}
            if (!Array.isArray(parts)) parts = [];
            var units = parseInt(cv.getAttribute('data-units') || '0', 10) || 0;
            var bright = Math.max(0, Math.min(1, parseFloat(cv.getAttribute('data-bright') || '0.5')));
            var view = draw(cv, units, bright, parts.filter(isItem), { scale: 0.4 });
            var cap = cv.parentElement && cv.parentElement.querySelector('[data-brain-shape]');
            if (cap) cap.textContent = 'Shape ' + view.shape.k + ' of ' + SHAPES + ' · ' + view.shape.name + ' · ' + view.lit + ' of ' + view.shape.tris.length + ' triangles lit';
        });
    }
    /** Heads with a full brain page (BrainPageService::HEADS). */
    var PAGES = ['penny', 'sam', 'otto', 'mia', 'yui', 'charlie'];
    window.HeadBrain = { shape: shape, SHAPES: SHAPES, TIERS: TIERS, tierRank: tierRank, hash: hash };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
