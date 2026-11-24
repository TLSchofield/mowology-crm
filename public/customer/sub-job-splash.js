/* The Ittai splash on the subcontract page: logo motion v4 "pinball", the app icon becoming the wordmark.
   Same timeline as public/crm/assets/design-system/brand/logo-motion-v4-pinball.html, at its 4.8 s splash speed.
   Plays once per link on a device, because the page reloads after every step. A tap skips it.
   Built here rather than in the page, so without JavaScript the job is simply there. */
(function () {
  'use strict';
  var key = 'sj-splash-' + (location.search.match(/[?&]t=([a-f0-9]{8})/) || [, 'x'])[1];
  try {
    if (localStorage.getItem(key)) return;
    localStorage.setItem(key, '1');
  } catch (e) { return; }
  if (window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches) return;

  var T = 2.4, SPEED = 0.5, HOLD_MS = 500, FADE_MS = 300;
  var NS = 'http://www.w3.org/2000/svg', INK = '#FFFFFF', GOLD = '#C9A65A';
  var clamp = function (v) { return Math.max(0, Math.min(1, v)); };
  var seg = function (t, a, b) { return clamp((t - a) / (b - a)); };
  var lerp = function (a, b, p) { return a + (b - a) * p; };
  var ioc = function (p) { return p < .5 ? 4 * p * p * p : 1 - Math.pow(-2 * p + 2, 3) / 2; };
  var oc = function (p) { return 1 - Math.pow(1 - p, 3); };
  var ob = function (p, k) { k = k === undefined ? 1.2 : k; return p <= 0 ? 0 : 1 + (k + 1) * Math.pow(p - 1, 3) + k * Math.pow(p - 1, 2); };

  var R = 6;
  var ICON = { s1x: 33.5, s2x: 59.5, top: 62.9, barX: 28.8, barY: 70.4, barW: 48.4, barH: 10.2, d1x: 40, d2x: 66, dy: 48.9, dr: 7.9 };
  var WORD = { t1x: 31, t2x: 62, top: 28, barX: 25, barY: 44, barW: 56, barH: 11, i1x: 0, i2x: 165.2, aStemX: 134.2 };
  var FLICK0 = .75, FLICK1 = 1.05, V0 = 250, LAND = 1.55, RING_TOP = 72 - 22.1 - 6.5;
  var stemTop = function (t) { return lerp(ICON.top, WORD.top, oc(seg(t, FLICK0, FLICK1))); };
  var DOT_BOTTOM = ICON.dy + ICON.dr, TC = FLICK0, lo = FLICK0, hi = FLICK1;
  for (var i = 0; i < 40; i++) { TC = (lo + hi) / 2; if (stemTop(TC) > DOT_BOTTOM) lo = TC; else hi = TC; }

  function hop(p, x0, y0, x1, y1, h) {
    var st = Math.sin(Math.PI * p) * .12;
    return [lerp(x0, x1, ioc(p) * .35 + p * .65), lerp(y0, y1, p) - h * 4 * p * (1 - p), R * (1 - st), R * (1 + st)];
  }
  function squash(k, cx, floor, amt) {
    var s = Math.sin(Math.PI * k) * (amt === undefined ? .32 : amt), ry = R * (1 - s);
    return [cx, floor - ry, R * (1 + s * .9), ry];
  }
  function rise(k, cx, y0, y1) {
    var e = 1 + 2.2 * Math.pow(k - 1, 3) + 1.2 * Math.pow(k - 1, 2), st = Math.sin(Math.PI * Math.min(1, k * 1.6)) * .1;
    return [cx, lerp(y0, y1, e), R * (1 - st), R * (1 + st)];
  }
  function fly(t, t0, x0, y0, v0, t1, x1, y1) {
    var D = t1 - t0, u = t - t0, gr = 2 * (y1 - y0 + v0 * D) / (D * D);
    return [lerp(x0, x1, u / D), y0 - v0 * u + .5 * gr * u * u, -v0 + gr * u];
  }
  function flightShape(t, f) {
    var r = lerp(ICON.dr, R, clamp((t - TC) / .25)), y = f[1];
    if (t < FLICK1) y = Math.min(y, stemTop(t) - r);
    var hit = Math.sin(Math.PI * seg(t, TC, TC + .06)) * .3, st = Math.min(1, Math.abs(f[2]) / V0) * .14 - hit;
    return [f[0], y, r * (1 - st), r * (1 + st)];
  }
  function landing(t, x) {
    var rest = 44 - R;
    if (t < LAND + .06) return squash(seg(t, LAND, LAND + .06), x, 44);
    if (t < LAND + .36) {
      var p = seg(t, LAND + .06, LAND + .36), st = Math.abs(Math.cos(Math.PI * p) * .08);
      return [x, rest - 12 * 4 * p * (1 - p), R * (1 - st), R * (1 + st)];
    }
    if (t < LAND + .41) return squash(seg(t, LAND + .36, LAND + .41), x, 44, .2);
    return rise(seg(t, LAND + .41, LAND + .7), x, rest, 31.5);
  }

  var overlay = document.createElement('div');
  overlay.className = 'sj-splash';
  overlay.setAttribute('aria-hidden', 'true');
  var svg = document.createElementNS(NS, 'svg');
  svg.setAttribute('viewBox', '-20 -24 220 148');
  var cam = document.createElementNS(NS, 'g');
  svg.appendChild(cam);
  function part(tag, fill) {
    var el = document.createElementNS(NS, tag);
    if (fill) el.setAttribute('fill', fill);
    cam.appendChild(el);
    return el;
  }
  var i1 = part('rect', INK), t1 = part('rect', INK), t2 = part('rect', INK), aRing = part('circle');
  aRing.setAttribute('cx', '118.6'); aRing.setAttribute('cy', '72'); aRing.setAttribute('r', '22.1');
  aRing.setAttribute('fill', 'none'); aRing.setAttribute('stroke', INK); aRing.setAttribute('stroke-width', '13');
  var aStem = part('rect', INK), i2 = part('rect', INK), bar = part('rect', GOLD), d1El = part('ellipse', GOLD), d2El = part('ellipse', GOLD);
  overlay.appendChild(svg);

  function setR(el, x, y, w, h, rx) {
    el.setAttribute('x', x); el.setAttribute('y', y); el.setAttribute('width', Math.max(0, w)); el.setAttribute('height', Math.max(0, h)); el.setAttribute('rx', rx || 0);
  }
  function setE(el, s) { el.setAttribute('cx', s[0]); el.setAttribute('cy', s[1]); el.setAttribute('rx', s[2]); el.setAttribute('ry', s[3]); }

  function render(t) {
    var g = oc(seg(t, FLICK0, FLICK1)), top = stemTop(t);
    setR(t1, lerp(ICON.s1x, WORD.t1x, g), top, 13, 100 - top);
    setR(t2, lerp(ICON.s2x, WORD.t2x, g), top, 13, 100 - top);
    var bh = lerp(ICON.barH, WORD.barH, g);
    setR(bar, lerp(ICON.barX, WORD.barX, g), lerp(ICON.barY, WORD.barY, g), lerp(ICON.barW, WORD.barW, g), bh, bh / 2);

    var a = ob(seg(t, .38, .72));
    setR(i1, WORD.i1x, 100 - 56 * a, 13, 56 * a);
    var ring = ob(seg(t, .46, .8), 1.0);
    aRing.setAttribute('transform', 'translate(118.6 72) scale(' + Math.max(ring, 0.0001) + ') translate(-118.6 -72)');
    aRing.setAttribute('opacity', ring > 0 ? 1 : 0);
    var as = ob(seg(t, .54, .88));
    setR(aStem, WORD.aStemX, 100 - 56 * as, 13, 56 * as);
    var b = ob(seg(t, .62, .98));
    setR(i2, WORD.i2x, 100 - 56 * b, 13, 56 * b);

    var d1, d2;
    if (t < TC) {
      d1 = [ICON.d1x, ICON.dy, ICON.dr, ICON.dr];
      d2 = [ICON.d2x, ICON.dy, ICON.dr, ICON.dr];
    } else {
      d1 = t < LAND ? flightShape(t, fly(t, TC, ICON.d1x, ICON.dy, V0, LAND, 6.5, 44 - R)) : landing(t, 6.5);
      if (t < 1.2) d2 = flightShape(t, fly(t, TC, ICON.d2x, ICON.dy, V0, 1.2, 118.6, RING_TOP - R));
      else if (t < 1.26) d2 = squash(seg(t, 1.2, 1.26), 118.6, RING_TOP, .26);
      else if (t < LAND) d2 = hop(seg(t, 1.26, LAND), 118.6, RING_TOP - R, 171.7, 44 - R, 10);
      else d2 = landing(t, 171.7);
    }
    setE(d1El, d1);
    setE(d2El, d2);

    var c = ioc(seg(t, .3, 1.0));
    var cTop = Math.min(top, d1[1] - d1[3], d2[1] - d2[3]);
    var cx = lerp(53, 89.1, c), cy = lerp((cTop + 100) / 2, 62, c), s = lerp(Math.min(1.45, 140 / (100 - cTop + 12)), .92, c);
    cam.setAttribute('transform', 'translate(90 50) scale(' + s + ') translate(' + (-cx) + ' ' + (-cy) + ')');
  }

  var raf, closing = false;
  function close() {
    if (closing) return;
    closing = true;
    cancelAnimationFrame(raf);
    overlay.classList.add('sj-splash-out');
    setTimeout(function () { if (overlay.parentNode) overlay.parentNode.removeChild(overlay); }, FADE_MS);
  }
  overlay.addEventListener('click', close);
  render(0);
  document.body.appendChild(overlay);

  var start = performance.now();
  function step(now) {
    var t = (now - start) / 1000 * SPEED;
    render(Math.min(t, T));
    if (t >= T) { setTimeout(close, HOLD_MS); return; }
    raf = requestAnimationFrame(step);
  }
  raf = requestAnimationFrame(step);
})();
