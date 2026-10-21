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

    // ── The ladder (owner, 2026-10-20) ─────────────────────────────────────
    // Shape N has exactly N triangles, one per thing learned. Picked once from Monkey Mind's Shape Lab
    // (~/Projects/monkeymind/tools/shape-lab/generators.js, solveByFaceCount) and stored here as one short
    // recipe per stage: the eight equilateral-triangle solids, geodesic spheres and their stellated (spiky)
    // versions, Sierpinski triangles, snowflakes, whirlpools, mandalas, droplets and rough stones. A count
    // with no named shape shows the next solid part-built ("u:<N>"), one face joining an edge of the last.
    // Regenerate with scripts/brain/brain-ladder.mjs (it rewrites RECIPES); the iOS app runs this same file.
    var RECIPES = '1 book si:1 T4 u:6 T6 u:8 T8 si:2 T10 u:12 T12 u:14 T14 sn:5,1 T16 u:18 s:b:3 u:20 T20 sn:7,1 w:1,1,11 u:24 s:T8 m:5,3 w:1,1,13 si:3 sn:4,2 u:30 s:b:5 u:32 g:octa,2 sn:11,1 u:36 m:7,3 g:tetra,3 u:42 u:42 sn:13,1 w:1,2,10 u:42 s:b:7 u:48 w:1,2,11 sn:15,1 u:48 u:48 s:g:tetra,2 sn:7,2 w:1,5,5 m:17,2 ra:13,52 u:60 sn:18,1 m:11,3 w:1,2,14 u:60 u:60 u:60 s:T20 u:64 u:64 sn:21,1 g:tetra,4 m:13,3 w:1,3,11 u:72 ra:17,68 sn:23,1 w:1,5,7 u:72 g:octa,3 u:80 u:80 sn:5,3 ra:19,76 m:11,4 w:1,3,13 u:80 g:icosa,2 si:4 u:96 u:96 sn:12,2 m:17,3 u:96 u:96 w:1,4,11 u:96 sn:6,3 m:13,4 ra:23,92 u:96 u:96 m:19,3 s:g:octa,2 u:100 sn:14,2 m:11,5 g:tetra,5 u:108 u:108 u:108 w:1,4,13 sn:15,2 u:108 u:108 s:g:tetra,3 u:128 w:1,5,11 u:128 sn:16,2 u:128 u:128 m:23,3 ra:29,116 u:128 u:128 sn:17,2 w:1,4,15 m:11,6 u:128 u:128 sn:4,4 m:25,3 w:3,3,7 u:128 g:octa,4 u:144 w:1,5,13 u:144 m:12,6 sn:19,2 u:144 u:144 ra:34,136 u:144 u:144 u:144 sn:20,2 u:144 u:144 m:13,6 g:tetra,6 u:180 u:180 sn:21,2 ra:37,148 u:180 w:1,5,15 u:180 ra:38,152 m:17,5 sn:22,2 u:180 w:1,6,13 u:180 u:180 u:180 w:1,5,16 sn:23,2 m:18,5 u:180 ra:41,164 sn:11,3 u:180 u:180 w:1,6,14 m:13,7 d:17 u:180 ra:43,172 u:180 u:180 m:25,4 w:2,4,11 u:180 u:180 u:180 g:icosa,3 u:192 m:26,4 u:192 ra:46,184 u:192 sn:6,4 m:17,6 ra:47,188 u:192 d:19 u:192 s:g:tetra,4 u:196 u:196 sn:13,3 g:tetra,7 u:200 w:3,3,11 u:200 g:octa,5 u:216 u:216 m:29,4 ra:51,204 u:216 u:216 m:23,5 w:2,4,13 u:216 sn:14,3 u:216 ra:53,212 u:216 u:216 u:216 s:g:octa,3 sn:7,4 u:240 u:240 w:2,5,11 m:17,7 u:240 u:240 w:2,4,14 sn:15,3 u:240 u:240 ra:57,228 u:240 d:23 m:21,6 ra:58,232 u:240 w:3,3,13 u:240 ra:59,236 u:240 u:240 u:240 s:g:icosa,2 u:256 m:22,6 si:5 ra:61,244 u:256 u:256 m:19,7 sn:8,4 u:256 w:5,5,5 u:256 m:28,5 u:256 u:256 sn:17,3 g:tetra,8 u:288 u:288 u:288 w:2,5,13 m:29,5 u:288 u:288 w:2,6,11 u:288 u:288 u:288 ra:67,268 u:288 sn:18,3 u:288 ra:68,272 m:21,7 u:288 u:288 ra:69,276 u:288 u:288 sn:9,4 w:2,5,14 u:288 u:288 u:288 ra:71,284 sn:19,3 m:26,6 u:288 g:octa,6 u:300 d:29 u:300 ra:73,292 u:300 u:300 u:300 ra:74,296 m:27,6 u:300 u:300 s:g:tetra,5 u:320 u:320 u:320 ra:76,304 u:320 u:320 u:320 m:28,6 u:320 sn:10,4 u:320 w:2,6,13 u:320 u:320 sn:21,3 ra:79,316 u:320 u:320 m:29,6 g:icosa,4 u:324 u:324 u:324 g:tetra,9 m:25,7 u:384 u:384 ra:82,328 u:384 sn:22,3 u:384 ra:83,332 u:384 u:384 u:384 w:2,6,14 u:384 m:26,7 u:384 d:34 sn:11,4 u:384 u:384 ra:86,344 sn:23,3 u:384 u:384 ra:87,348 u:384 w:5,5,7 m:27,7 ra:88,352 u:384 u:384 u:384 ra:89,356 u:384 u:384 u:384 sn:24,3 u:384 u:384 u:384 m:28,7 u:384 u:384 u:384 ra:92,368 u:384 d:37 u:384 sn:12,4 u:384 u:384 m:25,8 ra:94,376 u:384 u:384 u:384 d:38 u:384 u:384 u:384 s:g:octa,4 u:392 u:392 u:392 ra:97,388 u:392 w:3,5,13 u:392 g:octa,7 u:400 u:400 u:400 w:3,6,11 u:400 u:400 u:400 g:tetra,10 u:432 u:432 sn:13,4 ra:101,404 m:27,8 u:432 u:432 ra:102,408 u:432 d:41 u:432 ra:103,412 u:432 u:432 u:432 w:4,4,13 u:432 u:432 u:432 w:3,5,14 u:432 u:432 u:432 ra:106,424 u:432 u:432 u:432 ra:107,428 u:432 d:43 u:432 s:g:tetra,6 u:484 sn:14,4 m:29,8 ra:109,436 u:484 u:484 u:484 w:4,5,11 u:484 u:484 u:484 ra:111,444 u:484 u:484 u:484 w:4,4,14 u:484 d:45 u:484 ra:113,452 u:484 u:484 u:484 ra:114,456 u:484 u:484 u:484 d:46 u:484 u:484 u:484 ra:116,464 sn:15,4 u:484 u:484 w:3,6,13 u:484 d:47 u:484 ra:118,472 u:484 u:484 u:484 ra:119,476 u:484 u:484 u:484 w:3,5,16 u:484 u:484 u:484 g:tetra,11 u:500 u:500 u:500 ra:122,488 u:500 d:49 u:500 ra:123,492 u:500 u:500 u:500 sn:16,4 u:500 u:500 u:500 g:icosa,5'.split(' ');
    // The eight convex deltahedra, exact (every edge the same length), faces in build order.
    var SOLIDS = {
        T4: [[[0.5774,-0.5774,-0.5774],[-0.5774,0.5774,-0.5774],[0.5774,0.5774,0.5774]], [[-0.5774,-0.5774,0.5774],[0.5774,0.5774,0.5774],[-0.5774,0.5774,-0.5774]], [[-0.5774,-0.5774,0.5774],[-0.5774,0.5774,-0.5774],[0.5774,-0.5774,-0.5774]], [[-0.5774,-0.5774,0.5774],[0.5774,-0.5774,-0.5774],[0.5774,0.5774,0.5774]]],
        T6: [[[0,1,0],[0.7071,0,0],[-0.3536,0,-0.6124]], [[0,1,0],[-0.3536,0,-0.6124],[-0.3536,0,0.6124]], [[0,1,0],[-0.3536,0,0.6124],[0.7071,0,0]], [[0,-1,0],[-0.3536,0,-0.6124],[0.7071,0,0]], [[-0.3536,0,0.6124],[-0.3536,0,-0.6124],[0,-1,0]], [[-0.3536,0,0.6124],[0,-1,0],[0.7071,0,0]]],
        T8: [[[0,1,0],[1,0,0],[0,0,-1]], [[0,1,0],[0,0,-1],[-1,0,0]], [[0,1,0],[0,0,1],[1,0,0]], [[0,1,0],[-1,0,0],[0,0,1]], [[0,0,-1],[0,-1,0],[-1,0,0]], [[0,0,-1],[1,0,0],[0,-1,0]], [[0,0,1],[-1,0,0],[0,-1,0]], [[0,0,1],[0,-1,0],[1,0,0]]],
        T10: [[[0,0.618,0],[0.309,0,0.9511],[1,0,0]], [[0,0.618,0],[1,0,0],[0.309,0,-0.9511]], [[0,0.618,0],[0.309,0,-0.9511],[-0.809,0,-0.5878]], [[0,0.618,0],[-0.809,0,0.5878],[0.309,0,0.9511]], [[0,0.618,0],[-0.809,0,-0.5878],[-0.809,0,0.5878]], [[0.309,0,0.9511],[0,-0.618,0],[1,0,0]], [[0.309,0,-0.9511],[1,0,0],[0,-0.618,0]], [[0.309,0,-0.9511],[0,-0.618,0],[-0.809,0,-0.5878]], [[-0.809,0,0.5878],[-0.809,0,-0.5878],[0,-0.618,0]], [[-0.809,0,0.5878],[0,-0.618,0],[0.309,0,0.9511]]],
        T12: [[[0,0.8431,0.5377],[0.6932,0.2211,0],[0,0.8431,-0.5377]], [[0,0.8431,0.5377],[0,0.8431,-0.5377],[-0.6932,0.2211,0]], [[0,0.8431,-0.5377],[0,-0.2211,-0.6932],[-0.6932,0.2211,0]], [[0,0.8431,-0.5377],[0.6932,0.2211,0],[0,-0.2211,-0.6932]], [[0,0.8431,0.5377],[0,-0.2211,0.6932],[0.6932,0.2211,0]], [[0,0.8431,0.5377],[-0.6932,0.2211,0],[0,-0.2211,0.6932]], [[0,-0.2211,-0.6932],[0.6932,0.2211,0],[0.5377,-0.8431,0]], [[0,-0.2211,0.6932],[0.5377,-0.8431,0],[0.6932,0.2211,0]], [[-0.5377,-0.8431,0],[-0.6932,0.2211,0],[0,-0.2211,-0.6932]], [[-0.5377,-0.8431,0],[0,-0.2211,0.6932],[-0.6932,0.2211,0]], [[-0.5377,-0.8431,0],[0,-0.2211,-0.6932],[0.5377,-0.8431,0]], [[-0.5377,-0.8431,0],[0.5377,-0.8431,0],[0,-0.2211,0.6932]]],
        T14: [[[-0.2899,0.5021,0.5021],[0.5798,0.5021,0],[-0.2899,0.5021,-0.5021]], [[-0.2899,0.5021,-0.5021],[0.5798,0.5021,0],[0.5,0,-0.866]], [[-0.2899,0.5021,0.5021],[0.5,0,0.866],[0.5798,0.5021,0]], [[-0.2899,0.5021,0.5021],[-0.2899,0.5021,-0.5021],[-1,0,0]], [[0.5,0,-0.866],[0.5798,0.5021,0],[0.5798,-0.5021,0]], [[0.5,0,0.866],[0.5798,-0.5021,0],[0.5798,0.5021,0]], [[-0.2899,-0.5021,-0.5021],[-1,0,0],[-0.2899,0.5021,-0.5021]], [[-0.2899,-0.5021,-0.5021],[-0.2899,0.5021,-0.5021],[0.5,0,-0.866]], [[-0.2899,-0.5021,-0.5021],[0.5,0,-0.866],[0.5798,-0.5021,0]], [[-0.2899,-0.5021,0.5021],[-0.2899,0.5021,0.5021],[-1,0,0]], [[-0.2899,-0.5021,0.5021],[0.5,0,0.866],[-0.2899,0.5021,0.5021]], [[-0.2899,-0.5021,0.5021],[0.5798,-0.5021,0],[0.5,0,0.866]], [[-0.2899,-0.5021,0.5021],[-1,0,0],[-0.2899,-0.5021,-0.5021]], [[-0.2899,-0.5021,0.5021],[-0.2899,-0.5021,-0.5021],[0.5798,-0.5021,0]]],
        T16: [[[0,1,0],[0,0.3729,-0.6271],[-0.6271,0.3729,0]], [[0,1,0],[0.6271,0.3729,0],[0,0.3729,-0.6271]], [[0,0.3729,0.6271],[0.6271,0.3729,0],[0,1,0]], [[0,0.3729,0.6271],[0,1,0],[-0.6271,0.3729,0]], [[-0.4434,-0.3729,-0.4434],[-0.6271,0.3729,0],[0,0.3729,-0.6271]], [[0.4434,-0.3729,-0.4434],[0,0.3729,-0.6271],[0.6271,0.3729,0]], [[0.4434,-0.3729,-0.4434],[-0.4434,-0.3729,-0.4434],[0,0.3729,-0.6271]], [[0.4434,-0.3729,0.4434],[0.6271,0.3729,0],[0,0.3729,0.6271]], [[0.4434,-0.3729,0.4434],[0.4434,-0.3729,-0.4434],[0.6271,0.3729,0]], [[-0.4434,-0.3729,0.4434],[0,0.3729,0.6271],[-0.6271,0.3729,0]], [[-0.4434,-0.3729,0.4434],[-0.6271,0.3729,0],[-0.4434,-0.3729,-0.4434]], [[-0.4434,-0.3729,0.4434],[0.4434,-0.3729,0.4434],[0,0.3729,0.6271]], [[0.4434,-0.3729,-0.4434],[0,-1,0],[-0.4434,-0.3729,-0.4434]], [[0.4434,-0.3729,0.4434],[0,-1,0],[0.4434,-0.3729,-0.4434]], [[-0.4434,-0.3729,0.4434],[-0.4434,-0.3729,-0.4434],[0,-1,0]], [[-0.4434,-0.3729,0.4434],[0,-1,0],[0.4434,-0.3729,0.4434]]],
        T20: [[[0.5257,0.8507,0],[-0.5257,0.8507,0],[0,0.5257,0.8507]], [[0.5257,0.8507,0],[0,0.5257,-0.8507],[-0.5257,0.8507,0]], [[-0.5257,0.8507,0],[-0.8507,0,0.5257],[0,0.5257,0.8507]], [[-0.8507,0,-0.5257],[-0.5257,0.8507,0],[0,0.5257,-0.8507]], [[-0.8507,0,-0.5257],[-0.8507,0,0.5257],[-0.5257,0.8507,0]], [[0.5257,0.8507,0],[0,0.5257,0.8507],[0.8507,0,0.5257]], [[0.5257,0.8507,0],[0.8507,0,-0.5257],[0,0.5257,-0.8507]], [[0.5257,0.8507,0],[0.8507,0,0.5257],[0.8507,0,-0.5257]], [[0,-0.5257,0.8507],[0.8507,0,0.5257],[0,0.5257,0.8507]], [[0,-0.5257,0.8507],[0,0.5257,0.8507],[-0.8507,0,0.5257]], [[0.8507,0,-0.5257],[0,-0.5257,-0.8507],[0,0.5257,-0.8507]], [[-0.8507,0,-0.5257],[0,0.5257,-0.8507],[0,-0.5257,-0.8507]], [[0.8507,0,-0.5257],[0.8507,0,0.5257],[0.5257,-0.8507,0]], [[0,-0.5257,0.8507],[0.5257,-0.8507,0],[0.8507,0,0.5257]], [[0.8507,0,-0.5257],[0.5257,-0.8507,0],[0,-0.5257,-0.8507]], [[-0.5257,-0.8507,0],[-0.8507,0,0.5257],[-0.8507,0,-0.5257]], [[-0.5257,-0.8507,0],[0,-0.5257,0.8507],[-0.8507,0,0.5257]], [[-0.5257,-0.8507,0],[-0.8507,0,-0.5257],[0,-0.5257,-0.8507]], [[-0.5257,-0.8507,0],[0,-0.5257,-0.8507],[0.5257,-0.8507,0]], [[-0.5257,-0.8507,0],[0.5257,-0.8507,0],[0,-0.5257,0.8507]]]
    };
    var SOLID_NAMES = { T4: 'tetrahedron', T6: 'triangular bipyramid', T8: 'octahedron', T10: 'pentagonal bipyramid', T12: 'snub disphenoid',
        T14: 'triaugmented triangular prism', T16: 'gyroelongated square bipyramid', T20: 'icosahedron' };
    function v3(p) { return [p[0], p[1], p.length > 2 ? p[2] : 0]; }
    function flatTris(T) { return T.map(function (t) { return [v3(t[0]), v3(t[1]), v3(t[2])]; }); }
    function eqTri(r, rot) { var p = []; for (var k = 0; k < 3; k++) { var a = rot + k * 2 * Math.PI / 3; p.push([r * Math.cos(a), r * Math.sin(a)]); } return p; }
    function mid2(a, b) { return [(a[0] + b[0]) / 2, (a[1] + b[1]) / 2]; }
    // Shape Lab generators, ported without three.js (same maths, same face counts).
    var GEN = {
        bipyramid: function (nn, s) {
            var r = s / (2 * Math.sin(Math.PI / nn)), h = Math.sqrt(Math.max(0.001, s * s - r * r)), eq = [], T = [];
            for (var i = 0; i < nn; i++) { var a = i / nn * Math.PI * 2; eq.push([Math.cos(a) * r, 0, Math.sin(a) * r]); }
            for (i = 0; i < nn; i++) { var p = eq[i], q = eq[(i + 1) % nn]; T.push([[0, h, 0], p, q], [[0, -h, 0], q, p]); }
            return T;
        },
        antiprism: function (n, h) {
            var T = [], tc = [0, 0, h], bc = [0, 0, -h], top = [], bot = [];
            for (var i = 0; i < n; i++) { var a = i * 2 * Math.PI / n, b = a + Math.PI / n; top.push([Math.cos(a), Math.sin(a), h]); bot.push([Math.cos(b), Math.sin(b), -h]); }
            for (i = 0; i < n; i++) { var j = (i + 1) % n; T.push([top[i], bot[i], top[j]], [bot[i], bot[j], top[j]], [tc, top[i], top[j]], [bc, bot[j], bot[i]]); }
            return T;
        },
        mandala: function (P, rings) {
            var rp = [[[0, 0]]], T = [];
            for (var r = 1; r <= rings; r++) { var pts = []; for (var i = 0; i < P; i++) { var a = i / P * 2 * Math.PI + (r % 2) * (Math.PI / P), rad = r / rings; pts.push([Math.cos(a) * rad, Math.sin(a) * rad]); } rp.push(pts); }
            var c = rp[0][0];
            for (i = 0; i < P; i++) T.push([c, rp[1][i], rp[1][(i + 1) % P]]);
            for (r = 1; r < rings; r++) { var A = rp[r], B = rp[r + 1]; for (i = 0; i < P; i++) { T.push([A[i], B[i], A[(i + 1) % P]]); T.push([A[(i + 1) % P], B[i], B[(i + 1) % P]]); } }
            return flatTris(T);
        },
        droplet: function (segs) {
            var PR = [[0, -1.0], [0.55, -0.85], [0.82, -0.55], [0.78, -0.1], [0.55, 0.35], [0.22, 0.75], [0, 1.0]], T = [];
            var rings = PR.map(function (q) { var pts = []; for (var i = 0; i < segs; i++) { var a = i / segs * 2 * Math.PI; pts.push([Math.cos(a) * q[0], q[1], Math.sin(a) * q[0]]); } return pts; });
            for (var ri = 0; ri < rings.length - 1; ri++) {
                var A = rings[ri], B = rings[ri + 1];
                for (var i = 0; i < segs; i++) { var j = (i + 1) % segs; if (PR[ri][0] > 0.001) T.push([A[i], B[i], A[j]]); if (PR[ri + 1][0] > 0.001) T.push([A[j], B[i], B[j]]); }
            }
            return T;
        },
        whirlpool: function (arms, turns, segsPerTurn, rMax) {
            var T = [], steps = turns * segsPerTurn, bw = 0.06;
            for (var arm = 0; arm < arms; arm++) {
                var phase = arm * 2 * Math.PI / arms, pi = null, po = null;
                for (var s = 0; s <= steps; s++) {
                    var t = s / steps, th = t * turns * 2 * Math.PI + phase, r = t * rMax, cx = Math.cos(th), sx = Math.sin(th);
                    var rin = Math.max(0, r - bw), rout = r + bw, a = [cx * rin, sx * rin, 0], b = [cx * rout, sx * rout, 0];
                    if (pi) T.push([pi, po, a], [a, po, b]);
                    pi = a; po = b;
                }
            }
            return T;
        },
        sierpinski: function (depth) {
            var T = [eqTri(1, Math.PI / 2)];
            for (var d = 0; d < depth; d++) {
                var nt = [];
                T.forEach(function (t) { var m = [mid2(t[0], t[1]), mid2(t[1], t[2]), mid2(t[2], t[0])]; nt.push([t[0], m[0], m[2]], [m[0], t[1], m[1]], [m[2], m[1], t[2]]); });
                T = nt;
            }
            return flatTris(T);
        },
        snowflake: function (arms, levels) {
            var T = [], spread = Math.PI / 5, decay = 0.5, at = 0.55;
            function spike(px, py, dir, len, hw) {
                var dx = Math.cos(dir), dy = Math.sin(dir), wx = Math.cos(dir + Math.PI / 2) * hw, wy = Math.sin(dir + Math.PI / 2) * hw;
                return [[px + wx, py + wy], [px - wx, py - wy], [px + dx * len, py + dy * len]];
            }
            function branch(px, py, dir, len, hw, level) {
                T.push(spike(px, py, dir, len, hw));
                if (level >= levels) return;
                var bx = px + Math.cos(dir) * len * at, by = py + Math.sin(dir) * len * at;
                branch(bx, by, dir - spread, len * decay, hw * decay, level + 1);
                branch(bx, by, dir + spread, len * decay, hw * decay, level + 1);
            }
            for (var i = 0; i < arms; i++) branch(0, 0, (i / arms) * 2 * Math.PI + Math.PI / 2, 1, 0.05, 0);
            return flatTris(T);
        },
        // Pyramidalise each face: 3 spiky faces per face.
        stellate: function (T, h) {
            var out = [];
            T.forEach(function (t) {
                var a = t[0], b = t[1], c = t[2], ct = [(a[0] + b[0] + c[0]) / 3, (a[1] + b[1] + c[1]) / 3, (a[2] + b[2] + c[2]) / 3];
                var u = [b[0] - a[0], b[1] - a[1], b[2] - a[2]], w = [c[0] - a[0], c[1] - a[1], c[2] - a[2]];
                var n = norm([u[1] * w[2] - u[2] * w[1], u[2] * w[0] - u[0] * w[2], u[0] * w[1] - u[1] * w[0]]);
                if (n[0] * ct[0] + n[1] * ct[1] + n[2] * ct[2] < 0) n = [-n[0], -n[1], -n[2]];   // always outwards
                var ap = [ct[0] + n[0] * h, ct[1] + n[1] * h, ct[2] + n[2] * h];
                out.push([a, b, ap], [b, c, ap], [c, a, ap]);
            });
            return out;
        },
        // Deterministic vertex noise: a rough stone. Same seed, same stone, every time.
        jitter: function (T, amt, seed) {
            var vm = {};
            return T.map(function (t) {
                return t.map(function (v) {
                    var k = v[0].toFixed(3) + ',' + v[1].toFixed(3) + ',' + v[2].toFixed(3);
                    if (!vm[k]) {
                        var s = (k.split('').reduce(function (a, ch) { return Math.imul(a, 31) + ch.charCodeAt(0) | 0; }, 0) ^ seed) >>> 0;
                        var h = function () { s = Math.imul(s, 1664525) + 1013904223 >>> 0; return s / 4294967295 - 0.5; };
                        vm[k] = [v[0] + h() * amt, v[1] + h() * amt, v[2] + h() * amt];
                    }
                    return vm[k];
                });
            });
        }
    };
    /** Centre on the origin and scale so the farthest point is 1 (every stage the same size). */
    function fit(T) {
        var c = [0, 0, 0], n = 0, m = 0;
        T.forEach(function (t) { t.forEach(function (v) { c[0] += v[0]; c[1] += v[1]; c[2] += v[2]; n++; }); });
        c = [c[0] / n, c[1] / n, c[2] / n];
        T.forEach(function (t) { t.forEach(function (v) { m = Math.max(m, Math.hypot(v[0] - c[0], v[1] - c[1], v[2] - c[2])); }); });
        m = m || 1;
        return T.map(function (t) { return t.map(function (v) { return [(v[0] - c[0]) / m, (v[1] - c[1]) / m, (v[2] - c[2]) / m]; }); });
    }
    /** Faces in growth order: start at the top, then always the face touching the most built edges. */
    function growthOrder(T) {
        function key(v) { return v[0].toFixed(4) + ',' + v[1].toFixed(4) + ',' + v[2].toFixed(4); }
        var cen = T.map(function (t) { return (t[0][1] + t[1][1] + t[2][1]) / 3; });
        var E = T.map(function (t) { return [[t[0], t[1]], [t[1], t[2]], [t[2], t[0]]].map(function (e) { return [key(e[0]), key(e[1])].sort().join('|'); }); });
        var by = {}, done = [], score = [], order = [], i;
        E.forEach(function (es, f) { es.forEach(function (e) { (by[e] = by[e] || []).push(f); }); });
        for (i = 0; i < T.length; i++) { done.push(false); score.push(0); }
        function take(f) { done[f] = true; order.push(f); E[f].forEach(function (e) { by[e].forEach(function (g) { if (!done[g]) score[g] += 10; }); }); }
        var first = 0; for (i = 1; i < T.length; i++) if (cen[i] > cen[first]) first = i;
        take(first);
        while (order.length < T.length) {
            var best = -1, bs = -Infinity;
            for (i = 0; i < T.length; i++) if (!done[i] && score[i] + cen[i] > bs) { bs = score[i] + cen[i]; best = i; }
            take(best);
        }
        return order.map(function (f) { return T[f]; });
    }
    function nums(r) { return r.split(':').pop().split(',').map(Number); }
    /** A recipe → its triangles (not yet fitted) and plain name. */
    function cook(r) {
        var k = r.split(':')[0], p;
        if (r === '1') return { tris: [[[0, 1, 0], [-0.866, -0.5, 0], [0.866, -0.5, 0]]], name: 'triangle', flat: true };
        if (r === 'book') return { tris: [[[0, 0.85, -0.31], [0, -0.85, -0.31], [1.02, 0, 0.15]], [[0, 0.85, -0.31], [0, -0.85, -0.31], [-1.02, 0, 0.15]]], name: 'open book' };
        if (SOLIDS[r]) return { tris: SOLIDS[r], name: SOLID_NAMES[r] };
        if (k === 's') { var base = cook(r.slice(2)); return { tris: GEN.stellate(base.tris, 0.35), name: 'stellated ' + base.name, geo: base.geo }; }
        if (k === 'u') {
            var n = nums(r)[0], whole = cook(RECIPES[n - 1]);
            return { tris: growthOrder(fit(whole.tris)), name: 'building the ' + whole.name, building: n, geo: whole.geo };
        }
        p = nums(r);
        if (k === 'g') {
            var b = r.split(':')[1].split(',')[0];
            return { tris: geodesic(b === 'icosa' ? 'ico' : b, p[1]), name: (b === 'icosa' ? 'geodesic sphere' : b + ' geodesic') + ', frequency ' + p[1], geo: true };
        }
        if (k === 'si') return { tris: GEN.sierpinski(p[0]), name: 'Sierpinski triangle, depth ' + p[0], flat: true };
        if (k === 'sn') return { tris: GEN.snowflake(p[0], p[1]), name: p[0] + '-arm snowflake', flat: true };
        if (k === 'w') return { tris: GEN.whirlpool(p[0], p[1], p[2], 1), name: 'whirlpool', flat: true };
        if (k === 'm') return { tris: GEN.mandala(p[0], p[1]), name: p[0] + '-petal mandala', flat: true };
        if (k === 'd') return { tris: GEN.droplet(p[0]), name: 'droplet' };
        if (k === 'rb') return { tris: GEN.jitter(GEN.bipyramid(p[0], 1), 0.35, p[1]), name: 'rough stone' };
        if (k === 'ra') return { tris: GEN.jitter(GEN.antiprism(p[0], 0.6), 0.35, p[1]), name: 'rough boulder' };
        if (k === 'b') return { tris: GEN.bipyramid(p[0], 1), name: p[0] + '-sided bipyramid' };
        if (k === 'a') return { tris: GEN.antiprism(p[0], 0.6), name: p[0] + '-sided antiprism' };
        return { tris: SOLIDS.T4, name: 'tetrahedron' };
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
        var r = rng(k * 7919), c = cook(RECIPES[k - 1] || '1'), part = c.building ? k : c.tris.length;
        var tris = fit(c.tris).slice(0, part), name = c.name, fam = c.flat ? 'flat' : c.geo ? 'geo' : 'solid';
        // Brain folds: a few seeded waves; deeper and finer as k grows.
        // Only the geodesic spheres fold (from shape 80): a fold would bend a solid's flat faces or a flat figure.
        var depth = fam === 'geo' && k >= 80 ? 0.02 + 0.16 * (k / SHAPES) : 0, waves = [];
        for (var w = 0; w < 3; w++) {
            waves.push({ d: norm([r() - 0.5, r() - 0.5, r() - 0.5]), k: 2 + Math.floor(r() * (2 + k / 60)), ph: r() * 6.283 });
        }
        // Its own turn in space, so no two shapes look alike.
        var ax = r() * 6.283, ay = r() * 6.283;
        if (k <= 4) { ax = 0.3; ay = 0.55; }      // the first shapes rest face-on (a random tilt showed shape 1 edge-on)
        if (fam === 'flat') { ax = 0; ay = 0; }   // flat figures face the viewer; draw() sways them instead of spinning
        var cx = Math.cos(ax), sx = Math.sin(ax), cy = Math.cos(ay), sy = Math.sin(ay);
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
        return { k: k, tris: tris, name: name, family: fam, flat: fam === 'flat' };
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
        var k = Math.max(1, Math.min(SHAPES, units));   // shape 1 = one triangle: the first thing learned
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
            // A flat figure sways about 40° each way so it never turns edge-on; everything else turns round.
            var spin = (now - t0) / 11000 * 2 * Math.PI;
            var a = sh.flat ? (still ? 0 : 0.7 * Math.sin(spin)) : (still ? 0.6 : spin), tilt = sh.flat ? 0.15 : 0.45;
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
                    // Unlit outline: pale on dark cards, forest on light ones (else it vanishes).
                    g.strokeStyle = light ? 'rgba(13,59,46,' + (fc.front ? 0.35 : 0.12) + ')' : 'rgba(232,243,240,' + (fc.front ? 0.26 : 0.07) + ')';
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
            // data-no-page: a brain inside a task (Penny's approve window) opens the pop-up, never leaves the page.
            var page = PAGES.indexOf(who.toLowerCase()) >= 0 && !btn.hasAttribute('data-no-page') ? '/crm/brain.php?head=' + who.toLowerCase() : '';
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
    // mount(): draw brains added after the page loaded (Penny's approve window, 2026-10-14). Safe to repeat.
    window.HeadBrain = { shape: shape, SHAPES: SHAPES, TIERS: TIERS, tierRank: tierRank, hash: hash, mount: init };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
