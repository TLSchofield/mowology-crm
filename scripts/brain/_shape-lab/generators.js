// generators.js — Shape Lab's geometry-generator library.
//
// Ported verbatim (where noted) from the milestone-ladder generators that live
// inline in ../../www/index.html (search for `plumLadder` / `hydLadder` /
// `genericLadder`). These are pure functions with no app-state dependency, so
// this is a straight copy — NOT a shared module. Keep it that way: the app
// stays single-file by design (see CLAUDE.md), so if a generator changes here
// it must be hand-copied back into index.html, not imported from it.
//
// New in this file (not in the app yet): pyramidGeo(nGon) and solveByFaceCount(N).

import * as THREE from 'three';

// ── Core helpers (ported) ──────────────────────────────────────────────────

export function geoFromTris(tris) {
  const pos = [];
  for (const t of tris) for (const v of t) pos.push(v[0], v[1], v[2] !== undefined ? v[2] : 0);
  const g = new THREE.BufferGeometry();
  g.setAttribute('position', new THREE.Float32BufferAttribute(pos, 3));
  g.computeVertexNormals();
  return g;
}

export function normGeo(geo, R) {
  geo.computeBoundingSphere();
  const k = R / ((geo.boundingSphere && geo.boundingSphere.radius) || 1);
  geo.scale(k, k, k);
  return geo;
}

// Per-face placement transforms for ANY triangle-faced geometry — identical
// to the app's faceXforms (www/index.html:3514), producing {pos, quat, scale,
// localVerts} per face so a custom per-face triangle mesh lands exactly on it.
const S = 1; // unit edge reference, matches app's `S` (triangle half-size constant) closely enough for xf math
const myEdge = (new THREE.Vector3(0, S, 0)).distanceTo(new THREE.Vector3(-0.866 * S, -0.5 * S, 0));
export function faceXforms(geo) {
  const posA = geo.attributes.position, idx = geo.index, cnt = idx ? idx.count : posA.count, out = [];
  for (let i = 0; i < cnt; i += 3) {
    const ia = idx ? idx.getX(i) : i, ib = idx ? idx.getX(i + 1) : i + 1, ic = idx ? idx.getX(i + 2) : i + 2;
    const va = new THREE.Vector3().fromBufferAttribute(posA, ia);
    const vb = new THREE.Vector3().fromBufferAttribute(posA, ib);
    const vc = new THREE.Vector3().fromBufferAttribute(posA, ic);
    const ct = new THREE.Vector3().add(va).add(vb).add(vc).multiplyScalar(1 / 3);
    const e1t = new THREE.Vector3().subVectors(va, ct).normalize();
    const nt = new THREE.Vector3().subVectors(vb, va).cross(new THREE.Vector3().subVectors(vc, va)).normalize();
    const e2t = new THREE.Vector3().crossVectors(nt, e1t).normalize();
    const mt = new THREE.Matrix4().makeBasis(e1t, e2t, nt);
    const e1l = new THREE.Vector3(0, 1, 0), nl = new THREE.Vector3(0, 0, 1), e2l = new THREE.Vector3().crossVectors(nl, e1l).normalize();
    const ml = new THREE.Matrix4().makeBasis(e1l, e2l, nl);
    const q = new THREE.Quaternion().setFromRotationMatrix(new THREE.Matrix4().multiplyMatrices(mt, ml.clone().invert()));
    const qInv = q.clone().invert();
    const lva = new THREE.Vector3().subVectors(va, ct).applyQuaternion(qInv);
    const lvb = new THREE.Vector3().subVectors(vb, ct).applyQuaternion(qInv);
    const lvc = new THREE.Vector3().subVectors(vc, ct).applyQuaternion(qInv);
    out.push({
      pos: ct.clone(), quat: q, scale: va.distanceTo(vb) / myEdge,
      localVerts: [[lva.x, lva.y], [lvb.x, lvb.y], [lvc.x, lvc.y]],
    });
  }
  return out;
}

export function faceCount(geo) {
  const idx = geo.index;
  return (idx ? idx.count : geo.attributes.position.count) / 3;
}

export const eqTri = (r, rot) => {
  const p = [];
  for (let k = 0; k < 3; k++) { const a = rot + k * 2 * Math.PI / 3; p.push([r * Math.cos(a), r * Math.sin(a)]); }
  return p;
};
export const mid2 = (a, b) => [(a[0] + b[0]) / 2, (a[1] + b[1]) / 2];

// ── Solid / lattice generators (ported) ─────────────────────────────────────

export function bipyramid(nn, s) {
  const r = s / (2 * Math.sin(Math.PI / nn)), h = Math.sqrt(Math.max(0.001, s * s - r * r)), eq = [];
  for (let i = 0; i < nn; i++) { const a = i / nn * Math.PI * 2; eq.push([Math.cos(a) * r, 0, Math.sin(a) * r]); }
  const p = [];
  for (let i = 0; i < nn; i++) {
    const a = eq[i], b = eq[(i + 1) % nn];
    p.push(0, h, 0, a[0], a[1], a[2], b[0], b[1], b[2]);
    p.push(0, -h, 0, b[0], b[1], b[2], a[0], a[1], a[2]);
  }
  const g = new THREE.BufferGeometry();
  g.setAttribute('position', new THREE.Float32BufferAttribute(p, 3));
  g.computeVertexNormals();
  return g; // faces = 2*nn
}

export function antiprism(n, h) {
  const T = [], tc = [0, 0, h], bc = [0, 0, -h], top = [], bot = [];
  for (let i = 0; i < n; i++) { const a = i * 2 * Math.PI / n, b = a + Math.PI / n; top.push([Math.cos(a), Math.sin(a), h]); bot.push([Math.cos(b), Math.sin(b), -h]); }
  for (let i = 0; i < n; i++) { const j = (i + 1) % n; T.push([top[i], bot[i], top[j]], [bot[i], bot[j], top[j]], [tc, top[i], top[j]], [bc, bot[j], bot[i]]); }
  return geoFromTris(T); // faces = 4*n
}

export function waveGeo(n, h, freq, amp) {
  const T = [], tc = [0, 0, h], bc = [0, 0, -h], top = [], bot = [];
  for (let i = 0; i < n; i++) { const a = i * 2 * Math.PI / n, b = a + Math.PI / n, hh = h + Math.sin(a * freq) * amp; top.push([Math.cos(a), Math.sin(a), hh]); bot.push([Math.cos(b), Math.sin(b), -h]); }
  for (let i = 0; i < n; i++) { const j = (i + 1) % n; T.push([top[i], bot[i], top[j]], [bot[i], bot[j], top[j]], [tc, top[i], top[j]], [bc, bot[j], bot[i]]); }
  return geoFromTris(T); // faces = 4*n
}

export function mandalaGeo(P, rings) {
  const rp = [[[0, 0]]];
  for (let r = 1; r <= rings; r++) {
    const pts = [];
    for (let i = 0; i < P; i++) { const a = i / P * 2 * Math.PI + (r % 2) * (Math.PI / P), rad = r / rings; pts.push([Math.cos(a) * rad, Math.sin(a) * rad]); }
    rp.push(pts);
  }
  const tris = [], c = rp[0][0];
  for (let i = 0; i < P; i++) tris.push([c, rp[1][i], rp[1][(i + 1) % P]]);
  for (let r = 1; r < rings; r++) { const A = rp[r], B = rp[r + 1]; for (let i = 0; i < P; i++) { tris.push([A[i], B[i], A[(i + 1) % P]]); tris.push([A[(i + 1) % P], B[i], B[(i + 1) % P]]); } }
  return geoFromTris(tris); // faces = P*(2*rings-1)
}

export function rippleGeo(P, rings, amp, freq) {
  const rp = [[[0, 0, 0]]];
  for (let r = 1; r <= rings; r++) {
    const pts = [];
    for (let i = 0; i < P; i++) {
      const a = i / P * 2 * Math.PI + (r % 2) * (Math.PI / P), rad = r / rings;
      const z = Math.sin(a * freq + r * 0.7) * amp * (r / rings);
      pts.push([Math.cos(a) * rad, Math.sin(a) * rad, z]);
    }
    rp.push(pts);
  }
  const tris = [], c = rp[0][0];
  for (let i = 0; i < P; i++) tris.push([c, rp[1][i], rp[1][(i + 1) % P]]);
  for (let r = 1; r < rings; r++) { const A = rp[r], B = rp[r + 1]; for (let i = 0; i < P; i++) { tris.push([A[i], B[i], A[(i + 1) % P]]); tris.push([A[(i + 1) % P], B[i], B[(i + 1) % P]]); } }
  return geoFromTris(tris); // faces = P*(2*rings-1)
}

export function dropletGeo(segs) {
  const PROFILE = [[0, -1.0], [0.55, -0.85], [0.82, -0.55], [0.78, -0.1], [0.55, 0.35], [0.22, 0.75], [0, 1.0]];
  const rings = PROFILE.map(([r, y]) => { const pts = []; for (let i = 0; i < segs; i++) { const a = i / segs * 2 * Math.PI; pts.push([Math.cos(a) * r, y, Math.sin(a) * r]); } return pts; });
  const T = [];
  for (let ri = 0; ri < rings.length - 1; ri++) {
    const A = rings[ri], B = rings[ri + 1];
    for (let i = 0; i < segs; i++) {
      const j = (i + 1) % segs;
      if (PROFILE[ri][0] > 0.001) T.push([A[i], B[i], A[j]]);
      if (PROFILE[ri + 1][0] > 0.001) T.push([A[j], B[i], B[j]]);
    }
  }
  return geoFromTris(T); // faces = 10*segs
}

export function whirlpoolGeo(arms, turns, segsPerTurn, rMax) {
  const T = [], steps = turns * segsPerTurn, bandW = 0.06;
  for (let arm = 0; arm < arms; arm++) {
    const phase = arm * 2 * Math.PI / arms;
    let prevIn = null, prevOut = null;
    for (let s = 0; s <= steps; s++) {
      const t = s / steps, theta = t * turns * 2 * Math.PI + phase, r = t * rMax;
      const cx = Math.cos(theta), sx = Math.sin(theta);
      const rIn = Math.max(0, r - bandW), rOut = r + bandW;
      const pIn = [cx * rIn, sx * rIn, 0], pOut = [cx * rOut, sx * rOut, 0];
      if (prevIn) T.push([prevIn, prevOut, pIn], [pIn, prevOut, pOut]);
      prevIn = pIn; prevOut = pOut;
    }
  }
  return geoFromTris(T); // faces = arms*2*turns*segsPerTurn
}

export function sierpinskiGeo(depth) {
  let tris = [eqTri(1, Math.PI / 2)];
  for (let d = 0; d < depth; d++) {
    const nt = [];
    for (const t of tris) { const m = [mid2(t[0], t[1]), mid2(t[1], t[2]), mid2(t[2], t[0])]; nt.push([t[0], m[0], m[2]], [m[0], t[1], m[1]], [m[2], m[1], t[2]]); }
    tris = nt;
  }
  return geoFromTris(tris); // faces = 3^depth
}

const TET = [[1, 1, 1], [1, -1, -1], [-1, 1, -1], [-1, -1, 1]];
export function stellaGeo() {
  const b = TET.map(p => p.map(v => -v)), f = [[0, 1, 2], [0, 1, 3], [0, 2, 3], [1, 2, 3]], T = [];
  for (const t of f) T.push([TET[t[0]], TET[t[1]], TET[t[2]]]);
  for (const t of f) T.push([b[t[0]], b[t[1]], b[t[2]]]);
  return geoFromTris(T); // faces = 8 (fixed — two interpenetrating tetrahedra)
}

// Pyramidalize each face → 3 spiky faces. faces = 3 * (input face count).
export function stellate(geo, h) {
  const pos = geo.attributes.position, idx = geo.index, cnt = idx ? idx.count : pos.count, out = [];
  const get = i => { const k = idx ? idx.getX(i) : i; return new THREE.Vector3().fromBufferAttribute(pos, k); };
  for (let i = 0; i < cnt; i += 3) {
    const a = get(i), b = get(i + 1), c = get(i + 2);
    const ct = new THREE.Vector3().add(a).add(b).add(c).multiplyScalar(1 / 3);
    const nrm = new THREE.Vector3().subVectors(b, a).cross(new THREE.Vector3().subVectors(c, a)).normalize();
    const ap = ct.addScaledVector(nrm, h);
    out.push(a.x, a.y, a.z, b.x, b.y, b.z, ap.x, ap.y, ap.z, b.x, b.y, b.z, c.x, c.y, c.z, ap.x, ap.y, ap.z, c.x, c.y, c.z, a.x, a.y, a.z, ap.x, ap.y, ap.z);
  }
  const g = new THREE.BufferGeometry();
  g.setAttribute('position', new THREE.Float32BufferAttribute(out, 3));
  g.computeVertexNormals();
  return g;
}

export function jitterGeo(geo, amt, seed) {
  const pos = geo.attributes.position, vm = new Map(), out = [];
  for (let i = 0; i < pos.count; i++) {
    const k = `${pos.getX(i).toFixed(3)},${pos.getY(i).toFixed(3)},${pos.getZ(i).toFixed(3)}`;
    if (!vm.has(k)) {
      let s = ([...k].reduce((a, c) => Math.imul(a, 31) + c.charCodeAt(0) | 0, 0) ^ seed) >>> 0;
      const h = () => { s = Math.imul(s, 1664525) + 1013904223 >>> 0; return s / 4294967295 - 0.5; };
      vm.set(k, [pos.getX(i) + h() * amt, pos.getY(i) + h() * amt, pos.getZ(i) + h() * amt]);
    }
    out.push(...vm.get(k));
  }
  const g = new THREE.BufferGeometry();
  g.setAttribute('position', new THREE.Float32BufferAttribute(out, 3));
  g.computeVertexNormals();
  return g; // face count unchanged
}

export const merkabaGeo = () => geoFromTris([eqTri(1, Math.PI / 2), eqTri(1, Math.PI / 2 + Math.PI / 3)]); // faces = 2
export function sriYantraGeo() {
  const T = [], tri = (r, up, yo) => { const a = up ? Math.PI / 2 : -Math.PI / 2, p = []; for (let k = 0; k < 3; k++) { const ang = a + k * 2 * Math.PI / 3; p.push([r * Math.cos(ang), r * Math.sin(ang) + yo]); } T.push(p); };
  tri(1, 1, 0); tri(0.78, 1, 0.1); tri(0.58, 1, 0.18); tri(0.4, 1, 0.25); tri(0.95, 0, 0.04); tri(0.74, 0, -0.04); tri(0.56, 0, -0.1); tri(0.4, 0, -0.16); tri(0.28, 0, -0.04);
  return geoFromTris(T); // faces = 9
}
export function pentagramGeo() {
  const OR = 1, IR = OR * (3 - Math.sqrt(5)) / 2, T = [];
  for (let k = 0; k < 5; k++) { const a = Math.PI / 2 + k * 2 * Math.PI / 5; T.push([[Math.cos(a) * OR, Math.sin(a) * OR], [Math.cos(a - Math.PI / 5) * IR, Math.sin(a - Math.PI / 5) * IR], [Math.cos(a + Math.PI / 5) * IR, Math.sin(a + Math.PI / 5) * IR]]); }
  return geoFromTris(T); // faces = 5
}
// Dendritic snowflake — `arms` main branches radiating from center, each RECURSIVELY
// sprouting a pair of child branches partway along its length, down to `levels` deep (a
// real fractal tree, not one flat pair of side-spikes — matches an actual ice-crystal's
// self-similar branching). One spike = one triangle (base near its attach point, tip
// extended outward), same idiom as pentagramGeo's points.
// faces per arm = 2^(levels+1) - 1 (1 + 2 + 4 + ... down to `levels`), so
// faces = arms * (2^(levels+1) - 1) exactly. levels=1 reduces to the old flat 3-per-arm case.
function spikeAt(px, py, dirAngle, length, halfWidth) {
  const dx = Math.cos(dirAngle), dy = Math.sin(dirAngle);
  const perp = dirAngle + Math.PI / 2, wx = Math.cos(perp) * halfWidth, wy = Math.sin(perp) * halfWidth;
  return [[px + wx, py + wy], [px - wx, py - wy], [px + dx * length, py + dy * length]];
}
function branchRecursive(T, px, py, dirAngle, length, halfWidth, level, maxLevel, branchSpread, lengthDecay, branchAt) {
  T.push(spikeAt(px, py, dirAngle, length, halfWidth));
  if (level >= maxLevel) return;
  const bx = px + Math.cos(dirAngle) * length * branchAt, by = py + Math.sin(dirAngle) * length * branchAt;
  const childLen = length * lengthDecay, childWidth = halfWidth * lengthDecay;
  branchRecursive(T, bx, by, dirAngle - branchSpread, childLen, childWidth, level + 1, maxLevel, branchSpread, lengthDecay, branchAt);
  branchRecursive(T, bx, by, dirAngle + branchSpread, childLen, childWidth, level + 1, maxLevel, branchSpread, lengthDecay, branchAt);
}
export function snowflakeGeo(arms = 6, levels = 2, mainLen = 1, branchSpread = Math.PI / 5, lengthDecay = 0.5, branchAt = 0.55) {
  const T = [], halfWidth = 0.05;
  for (let i = 0; i < arms; i++) {
    const a = (i / arms) * 2 * Math.PI + Math.PI / 2;
    branchRecursive(T, 0, 0, a, mainLen, halfWidth, 0, levels, branchSpread, lengthDecay, branchAt);
  }
  return geoFromTris(T); // faces = arms * (2^(levels+1) - 1)
}

export function triadGeo() {
  const c = eqTri(1, Math.PI / 2), m = [mid2(c[0], c[1]), mid2(c[1], c[2]), mid2(c[2], c[0])];
  return geoFromTris([[c[0], m[0], m[2]], [m[0], c[1], m[1]], [m[2], m[1], c[2]]]); // faces = 3
}
export function tetraNetGeo() {
  const [A, B, C] = eqTri(1, Math.PI / 2), Mab = mid2(A, B), Mbc = mid2(B, C), Mac = mid2(A, C);
  return geoFromTris([[Mab, Mbc, Mac], [A, Mab, Mac], [B, Mbc, Mab], [C, Mac, Mbc]]); // faces = 4
}

// ── New: apex-fan pyramid over an n-gon, no base cap ─────────────────────────
// nGon triangular sides only (the base polygon itself is never triangulated
// in), so faceCount === nGon exactly — the direct generator for "N triangles,
// simplest possible shape" whenever N has no other tidy family match
// (e.g. the pentagon → pentagonal-pyramid idea: pyramidGeo(5)).
export function pyramidGeo(nGon, apexHeight = 1.1, baseRadius = 1, baseZ = -0.4) {
  const ring = [];
  for (let i = 0; i < nGon; i++) { const a = i / nGon * 2 * Math.PI; ring.push([Math.cos(a) * baseRadius, Math.sin(a) * baseRadius, baseZ]); }
  const apex = [0, 0, apexHeight + baseZ];
  const T = [];
  for (let i = 0; i < nGon; i++) { const j = (i + 1) % nGon; T.push([ring[i], ring[j], apex]); }
  return geoFromTris(T); // faces = nGon
}

// ── Face-count solver ────────────────────────────────────────────────────────
// Given a target N, brute-forces small integer params across every family's
// closed-form face-count and returns exact matches, ranked simplest-first.
// Each candidate: { family, label, n, params, build(): BufferGeometry }.

const DELTAHEDRA = [
  { n: 4, label: 'tetrahedron', expr: 'new THREE.TetrahedronGeometry(1)', build: () => new THREE.TetrahedronGeometry(1) },
  { n: 6, label: 'triangular bipyramid', expr: 'bipyramid(3,1)', build: () => bipyramid(3, 1) },
  { n: 8, label: 'octahedron', expr: 'new THREE.OctahedronGeometry(1)', build: () => new THREE.OctahedronGeometry(1) },
  { n: 10, label: 'pentagonal bipyramid', expr: 'bipyramid(5,1)', build: () => bipyramid(5, 1) },
  { n: 20, label: 'icosahedron', expr: 'new THREE.IcosahedronGeometry(1)', build: () => new THREE.IcosahedronGeometry(1) },
];
const DELTAHEDRA_UNAVAILABLE = { 12: 'snub disphenoid', 14: 'triaugmented triangular prism', 16: 'gyroelongated square bipyramid' };

// Geodesic spheres — higher-frequency subdivisions of the icosahedron (still all-triangle).
// faces = 20 * (detail+1)^2 — verified empirically against the actual Three.js r0.162
// PolyhedronGeometry subdivision (it is NOT the "4^detail" recursive-split formula you'd
// expect; each edge gets `detail+1` segments, not a 4-way split per level). detail 0 is
// already in DELTAHEDRA (the plain 20-face icosahedron). Confirmed: detail 1→80, 2→180, 3→320.
const GEODESIC = [
  { n: 80,  label: 'geodesic sphere (icosahedron, detail 1)', expr: 'new THREE.IcosahedronGeometry(1,1)', build: () => new THREE.IcosahedronGeometry(1, 1) },
  { n: 180, label: 'geodesic sphere (icosahedron, detail 2)', expr: 'new THREE.IcosahedronGeometry(1,2)', build: () => new THREE.IcosahedronGeometry(1, 2) },
  { n: 320, label: 'geodesic sphere (icosahedron, detail 3)', expr: 'new THREE.IcosahedronGeometry(1,3)', build: () => new THREE.IcosahedronGeometry(1, 3) },
];

// Fixed-face-count sacred-geometry shapes (each is its own bespoke generator, not a
// tunable family) — these have a definite n, so the solver can just check for a match.
const FIXED_SACRED = [
  // The "true" 3D Merkaba — two full tetrahedra interpenetrating (a stellated octahedron)
  // — is stellaGeo() below at n:8, NOT this flat one. This n:2 shape is just the 2D hexagram
  // glyph (two flat crossed triangles, no depth) — useful as an early/simple icon stage, but
  // don't confuse it for "the merkaba" the way sacred-geometry references usually mean it.
  { n: 2, label: 'hexagram (flat, 2 crossed triangles)', expr: 'merkabaGeo()', build: () => merkabaGeo() },
  // Sri Yantra's foundation IS just this — one upward (Shiva) + one downward (Shakti)
  // triangle overlapping. Same construction as the hexagram above; the 9-triangle
  // sriYantraGeo() below is the fully decorated temple form (4 up + 5 down, nested).
  { n: 2, label: 'Sri Yantra core (2 great triangles)', expr: 'merkabaGeo()', build: () => merkabaGeo() },
  { n: 3, label: 'triad', expr: 'triadGeo()', build: () => triadGeo() },
  { n: 4, label: 'tetrahedron net (flat, unfolds)', expr: 'tetraNetGeo()', build: () => tetraNetGeo() },
  { n: 5, label: 'pentagram', expr: 'pentagramGeo()', build: () => pentagramGeo() },
  // The real Merkaba: two full tetrahedra interpenetrating in 3D (geometrically a stellated
  // octahedron) — this is what sacred-geometry art actually means by "merkaba."
  { n: 8, label: 'merkaba (3D star tetrahedron)', expr: 'stellaGeo()', build: () => stellaGeo() },
  { n: 9, label: 'Sri Yantra', expr: 'sriYantraGeo()', build: () => sriYantraGeo() },
];

// Small curated set of "stellatable" bases with known face counts, so the
// stellate() family can be searched without an unbounded recursive search.
function stellatableBases() {
  const bases = [
    { n: 3, label: 'triad', expr: 'triadGeo()', build: () => triadGeo() },
    { n: 4, label: 'tetrahedron', expr: 'new THREE.TetrahedronGeometry(1)', build: () => new THREE.TetrahedronGeometry(1) },
    { n: 8, label: 'octahedron', expr: 'new THREE.OctahedronGeometry(1)', build: () => new THREE.OctahedronGeometry(1) },
    { n: 20, label: 'icosahedron', expr: 'new THREE.IcosahedronGeometry(1)', build: () => new THREE.IcosahedronGeometry(1) },
  ];
  for (let n = 3; n <= 16; n++) bases.push({ n: 2 * n, label: `${n}-gon bipyramid`, expr: `bipyramid(${n},1)`, build: () => bipyramid(n, 1) });
  for (let P = 3; P <= 16; P++) bases.push({ n: P, label: `${P}-gon fan`, expr: `mandalaGeo(${P},1)`, build: () => mandalaGeo(P, 1) });
  for (const g of GEODESIC) bases.push(g);   // stellating these gives the dense spiky-ball look (240, 960 faces)
  return bases;
}

export function solveByFaceCount(N, opts = {}) {
  const maxPerFamily = opts.maxPerFamily || 6, maxTotal = opts.maxTotal || 24;
  const out = [];
  const push = c => out.push(c);

  for (const d of DELTAHEDRA) if (d.n === N) push({ family: 'deltahedron', label: d.label, n: N, params: {}, expr: d.expr, build: d.build });
  if (DELTAHEDRA_UNAVAILABLE[N]) push({ family: 'deltahedron', label: `${DELTAHEDRA_UNAVAILABLE[N]} (canonical, no generator here)`, n: N, params: {}, expr: null, build: null });

  for (const g of GEODESIC) if (g.n === N) push({ family: 'geodesic', label: g.label, n: N, params: {}, expr: g.expr, build: g.build });

  for (const s of FIXED_SACRED) if (s.n === N) push({ family: 'sacred', label: s.label, n: N, params: {}, expr: s.expr, build: s.build });

  // Rough/jittered stand-ins — the app's own fallback for counts with no clean named shape
  // (plumLadder/hydLadder's "cobble/river stone/boulder/erratic/monolith" stages, all
  // jitterGeo(bipyramid/antiprism)). jitterGeo doesn't change face count, so these are always
  // available at any n a plain bipyramid/antiprism hits — offered as a deliberate rough
  // alternative alongside the clean version, not just when nothing else matches. Pushed
  // ahead of the plain bipyramid/antiprism (and well ahead of pyramid fan, below) since a
  // rough solid reads as a far more interesting stage than a flat fan — see also the pyramid
  // fan's own placement, deliberately pushed LAST as the generic fallback-of-last-resort.
  if (N % 2 === 0 && N / 2 >= 3) {
    const n = N / 2;
    push({ family: 'rough', label: `rough stone (jittered ${n}-gon bipyramid)`, n: N, params: { n, amt: 0.35, seed: N }, expr: `jitterGeo(bipyramid(${n},1),0.35,${N})`, build: () => jitterGeo(bipyramid(n, 1), 0.35, N) });
    push({ family: 'bipyramid', label: `${n}-gon bipyramid`, n: N, params: { n }, expr: `bipyramid(${n},1)`, build: () => bipyramid(n, 1) });
  }

  if (N % 4 === 0 && N / 4 >= 3) {
    const n = N / 4;
    push({ family: 'rough', label: `rough boulder (jittered ${n}-gon antiprism)`, n: N, params: { n, amt: 0.35, seed: N }, expr: `jitterGeo(antiprism(${n},0.6),0.35,${N})`, build: () => jitterGeo(antiprism(n, 0.6), 0.35, N) });
    push({ family: 'antiprism', label: `${n}-gon antiprism`, n: N, params: { n }, expr: `antiprism(${n},0.6)`, build: () => antiprism(n, 0.6) });
  }

  { let count = 0;
    for (let rings = 1; rings <= 8 && count < maxPerFamily; rings++) {
      const denom = 2 * rings - 1;
      if (N % denom !== 0) continue;
      const P = N / denom;
      if (P < 3 || P > 32) continue;
      const label = rings === 1 ? `${P}-gon (flat fan)${P===5?' — pentagon':P===6?' — hexagon':P===8?' — octagon':''}` : `${P}-petal mandala (${rings} rings)`;
      push({ family: 'mandala', label, n: N, params: { P, rings }, expr: `mandalaGeo(${P},${rings})`, build: () => mandalaGeo(P, rings) });
      count++;
    }
  }

  { let count = 0;
    for (let rings = 1; rings <= 8 && count < maxPerFamily; rings++) {
      const denom = 2 * rings - 1;
      if (N % denom !== 0) continue;
      const P = N / denom;
      if (P < 3 || P > 32) continue;
      const freq = Math.max(2, Math.round(P / 2));
      push({ family: 'ripple', label: `${P}-ring ripple (${rings} band${rings > 1 ? 's' : ''})`, n: N, params: { P, rings, amp: 0.2, freq }, expr: `rippleGeo(${P},${rings},0.2,${freq})`, build: () => rippleGeo(P, rings, 0.2, freq) });
      count++;
    }
  }

  { const depth = Math.round(Math.log(N) / Math.log(3));
    if (depth >= 0 && Math.pow(3, depth) === N) push({ family: 'sierpinski', label: `Sierpinski depth ${depth}`, n: N, params: { depth }, expr: `sierpinskiGeo(${depth})`, build: () => sierpinskiGeo(depth) });
  }

  if (N % 10 === 0 && N / 10 >= 3) { const segs = N / 10; push({ family: 'droplet', label: `droplet (${segs} segments)`, n: N, params: { segs }, expr: `dropletGeo(${segs})`, build: () => dropletGeo(segs) }); }

  if (N % 3 === 0) {
    const baseFaces = N / 3, bases = stellatableBases();
    let count = 0;
    for (const b of bases) {
      if (b.n !== baseFaces || count >= maxPerFamily) continue;
      push({ family: 'stellate', label: `stellated ${b.label}`, n: N, params: { base: b.label, height: 0.35 }, expr: `stellate(${b.expr},0.35)`, build: () => stellate(b.build(), 0.35) });
      count++;
    }
  }

  // faces = arms * (2^(levels+1) - 1) — search small (arms, levels) combos for an exact hit.
  { let count = 0;
    for (let levels = 1; levels <= 4 && count < maxPerFamily; levels++) {
      const perArm = Math.pow(2, levels + 1) - 1;
      if (N % perArm !== 0) continue;
      const arms = N / perArm;
      if (arms < 3 || arms > 24) continue;
      push({ family: 'snowflake', label: `${arms}-arm snowflake (${levels} branch level${levels > 1 ? 's' : ''})`, n: N, params: { arms, levels }, expr: `snowflakeGeo(${arms},${levels})`, build: () => snowflakeGeo(arms, levels) });
      count++;
    }
  }

  { let count = 0;
    outer:
    for (let arms = 1; arms <= 6; arms++)
      for (let turns = 1; turns <= 6; turns++)
        for (let segsPerTurn = 3; segsPerTurn <= 16; segsPerTurn++) {
          if (arms * 2 * turns * segsPerTurn !== N) continue;
          push({ family: 'whirlpool', label: `whirlpool (${arms} arm${arms > 1 ? 's' : ''}, ${turns} turn${turns > 1 ? 's' : ''})`, n: N, params: { arms, turns, segsPerTurn, rMax: 1 }, expr: `whirlpoolGeo(${arms},${turns},${segsPerTurn},1)`, build: () => whirlpoolGeo(arms, turns, segsPerTurn, 1) });
          count++;
          if (count >= maxPerFamily) break outer;
        }
  }

  // Pyramid fan — pushed dead last. It's an exact match for literally any N≥3, so it would
  // otherwise crowd out every more interesting family; kept only as the fallback-of-last-
  // resort (and often crowded out entirely by maxTotal once anything more interesting exists).
  if (N >= 3) push({ family: 'pyramid', label: `${N}-gon pyramid fan`, n: N, params: { nGon: N }, expr: `pyramidGeo(${N})`, build: () => pyramidGeo(N) });

  return out.slice(0, maxTotal);
}

// Best-fit mandala(P,rings) for an arbitrary target face count AT A GIVEN rings — unlike
// solveByFaceCount (which only returns EXACT matches), this always returns something, by
// solving for the closest P at whatever ring-depth the caller specifies. `rings` is the
// caller's to control deliberately: mandala's own face-count formula (P*(2*rings-1)) makes
// rings=1 an exact hit for almost any target ≤32, which would otherwise always win a
// "just minimize the diff" search — defeating the point of an escalating-density ramp,
// where rings (visual nestedness) matters more than landing exactly on the count.
export function mandalaForRings(targetN, rings) {
  const denom = 2 * rings - 1;
  const P = Math.max(3, Math.min(32, Math.round(targetN / denom)));
  const n = P * denom;
  const label = rings === 1 ? `${P}-gon (flat fan)` : `${P}-petal mandala (${rings} rings)`;
  return { family: 'mandala', label, n, params: { P, rings }, expr: `mandalaGeo(${P},${rings})`, build: () => mandalaGeo(P, rings) };
}
