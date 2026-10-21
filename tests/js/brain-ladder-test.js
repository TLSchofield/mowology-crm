/**
 * Brain ladder test (public/crm/js/head-brain.js) — runs in Node, no browser.
 *
 *   node tests/js/brain-ladder-test.js
 *
 * Proves the ladder set on 2026-10-20 (owner: "shape 1 should be just one triangle", "go up to 500"):
 *   - shape N has exactly N triangles, for every N from 1 to 500, and every point is a real number;
 *   - shape 1 is one triangle, shape 2 the open book, shape 4 the tetrahedron, 20 the icosahedron,
 *     500 the geodesic sphere of 500 triangles;
 *   - the eight deltahedra are exact: every edge the same length;
 *   - flat figures (Sierpinski, snowflakes, whirlpools, mandalas) are marked flat, so draw() sways them;
 *   - the same N gives the same shape every time (the iOS app and the web must match).
 * Exit code: 0 = pass, 1 = failures.
 */
'use strict';
const fs = require('fs');
const path = require('path');
const vm = require('vm');

let failed = 0, passed = 0;
function ok(cond, msg) { if (cond) passed++; else { failed++; console.log('FAIL', msg); } }

const src = fs.readFileSync(path.join(__dirname, '../../public/crm/js/head-brain.js'), 'utf8');
const ctx = { window: { matchMedia: () => ({ matches: false }) }, document: { readyState: 'loading', addEventListener() {}, querySelectorAll: () => [] }, Map, Math, JSON };
vm.createContext(ctx);
vm.runInContext(src, ctx);
const H = ctx.window.HeadBrain;

for (let k = 1; k <= 500; k++) {
  const s = H.shape(k);
  ok(s.tris.length === k, `shape ${k} has ${s.tris.length} triangles`);
  ok(s.tris.every(t => t.length === 3 && t.every(v => v.every(Number.isFinite))), `shape ${k}: every point a number`);
}

const name = k => H.shape(k).name;
ok(name(1) === 'triangle', 'shape 1 is one triangle');
ok(name(2) === 'open book', 'shape 2 is the open book');
ok(name(4) === 'tetrahedron', 'shape 4 is the tetrahedron');
ok(name(20) === 'icosahedron', 'shape 20 is the icosahedron');
ok(name(500) === 'geodesic sphere, frequency 5', 'shape 500 is the 500-triangle sphere');
ok(/^building the /.test(name(5)), 'shape 5 builds the next solid');

// Exact deltahedra: shapes 4, 6, 8, 10, 12, 14, 16, 20 have every edge the same length (before any fold/turn, which keep lengths).
[4, 6, 8, 10, 12, 14, 16, 20].forEach(k => {
  const L = [];
  H.shape(k).tris.forEach(t => [[0, 1], [1, 2], [2, 0]].forEach(([a, b]) => L.push(Math.hypot(t[a][0] - t[b][0], t[a][1] - t[b][1], t[a][2] - t[b][2]))));
  ok(Math.max(...L) / Math.min(...L) - 1 < 1e-3, `shape ${k} (${name(k)}) has equal edges`);
});

[1, 3, 9, 27, 81, 243].forEach(k => ok(H.shape(k).flat === true, `shape ${k} (${name(k)}) is flat`));
ok(H.shape(4).flat === false, 'the tetrahedron is not flat');

ok(JSON.stringify(H.shape(239).tris) === JSON.stringify(H.shape(239).tris), 'same N, same shape');

console.log(`${passed} passed, ${failed} failed`);
process.exit(failed ? 1 : 0);
