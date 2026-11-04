/* =============================================================================
   ittai-knowledge-glyph.js — the knowledge glyph, shared (COMPONENTS.md). Moved here unchanged from
   penny-onboarding.js (2026-10-20) and given one more way to grow:

   setState(state, animate)   Penny's business identity: recognized = three loose sides drifting;
                              identified / understood = the triangle locks (understood also fills).
   setLocked([bool,bool,bool], fresh, animate)   "About you" (owner, 2026-10-20): each side locks on its own,
                              in gold, as its piece is known (name to use · how to reach you · when not to bother
                              you); the finished triangle then joins Penny's brain (onboarding-about.js).

   Never the Ittai symbol (that is the hexagon). Reduced motion: the same states, no movement. The meaning is
   always also in the title / aria-label, never in colour alone.
   ============================================================================= */
(function () {
  'use strict';

  function KnowledgeGlyph(element, options) {
    options = options || {};
    this.element = element;
    this.reducedMotion = !!options.reducedMotion;
    this.label = options.label || null;          // (n locked) => text, for the setLocked mode
    this.state = null;
    this.previousState = null;
    this.frame = 0;
    this.locked = null;                          // null = setState mode; [bool,bool,bool] = setLocked mode
    this.endpoints = [[36, 10, 63, 57], [63, 57, 9, 57], [9, 57, 36, 10]];
    // Recognized = three separate pieces of the triangle: each side shortened to 70%, pushed out from the centre and turned a
    // little, so they never cross or meet (owner, 2026-10-17). [outward push, tangential shift, degrees, drift amplitude, phase]
    this.chaos = [[0.38, 2.5, -12, 1.1, .2], [0.42, -3, 9, .8, 2.3], [0.36, 2, -10, 1.25, 4.4]];
    // About you = messier (owner, 2026-10-20): the loose sides are pulled in towards the centre, kept longer and turned
    // steeply so they cross each other and drift more. Same shape of values, plus [length share, turn amplitude].
    this.mess = [[-0.55, -9, -31, 3.2, .2, .92, .16], [-0.95, 9, 71, 2.8, 2.3, .78, .13], [-0.4, 2, 23, 3.4, 4.4, .86, .18]];
    element.innerHTML = '<svg class="it-ob-triangle" viewBox="0 0 72 72" role="img"><title></title>'
      + '<path class="it-ob-triangle-fill" d="M36 10 L63 57 L9 57 Z"></path><line x1="36" y1="10" x2="63" y2="57"></line><line x1="63" y1="57" x2="9" y2="57"></line><line x1="9" y1="57" x2="36" y2="10"></line></svg>';
    this.svg = element.querySelector('svg');
    this.title = element.querySelector('title');
    this.sides = Array.prototype.slice.call(element.querySelectorAll('line'));
    this.mark = function (i, on) { this.sides[i].classList.toggle('it-ob-side-locked', !!on); };
  }
  KnowledgeGlyph.prototype.coords = function (side, values) {
    side.setAttribute('x1', values[0]); side.setAttribute('y1', values[1]);
    side.setAttribute('x2', values[2]); side.setAttribute('y2', values[3]);
  };
  KnowledgeGlyph.prototype.loose = function (i, time) {
    var c = this.locked ? this.mess[i] : this.chaos[i]; var e = this.endpoints[i];
    var cx = 36, cy = 41.33;                                   // the triangle's centroid
    var mx = (e[0] + e[2]) / 2, my = (e[1] + e[3]) / 2;        // this side's midpoint
    var dx = e[2] - e[0], dy = e[3] - e[1], len = Math.hypot(dx, dy), ux = dx / len, uy = dy / len;
    var x = mx + (mx - cx) * c[0] + ux * c[1] + Math.sin(time * .0007 + c[4]) * c[3] * 0.5;
    var y = my + (my - cy) * c[0] + uy * c[1] + Math.cos(time * .0009 + c[4]) * c[3] * 0.5;
    var angle = Math.atan2(dy, dx) + c[2] * Math.PI / 180 + Math.sin(time * .0005 + c[4]) * (c[6] || .02);
    var half = len * (c[5] || 0.7) / 2;
    return [x - Math.cos(angle) * half, y - Math.sin(angle) * half, x + Math.cos(angle) * half, y + Math.sin(angle) * half];
  };
  function ease(p) { return p < .5 ? 2 * p * p : 1 - Math.pow(-2 * p + 2, 2) / 2; }
  /** Blend two segments as wholes (midpoint, angle, length), so a side turns and glides instead of kinking. */
  KnowledgeGlyph.prototype.blend = function (a, b, t) {
    function polar(v) { var dx = v[2] - v[0], dy = v[3] - v[1]; return [(v[0] + v[2]) / 2, (v[1] + v[3]) / 2, Math.atan2(dy, dx), Math.hypot(dx, dy)]; }
    var p = polar(a), q = polar(b);
    var turn = q[2] - p[2];
    while (turn > Math.PI) turn -= 2 * Math.PI;
    while (turn < -Math.PI) turn += 2 * Math.PI;
    var x = p[0] + (q[0] - p[0]) * t, y = p[1] + (q[1] - p[1]) * t, ang = p[2] + turn * t, half = (p[3] + (q[3] - p[3]) * t) / 2;
    return [x - Math.cos(ang) * half, y - Math.sin(ang) * half, x + Math.cos(ang) * half, y + Math.sin(ang) * half];
  };
  KnowledgeGlyph.prototype.now = function (i) { var side = this.sides[i]; return ['x1', 'y1', 'x2', 'y2'].map(function (a) { return parseFloat(side.getAttribute(a)); }); };
  /** Which sides drift: all of them in the recognized state; the unlocked ones in setLocked mode. */
  KnowledgeGlyph.prototype.isLoose = function (i) {
    return this.locked ? !this.locked[i] : this.state === 'recognized';
  };
  KnowledgeGlyph.prototype.drift = function () {
    var glyph = this;
    window.cancelAnimationFrame(this.frame);
    function draw(time) {
      var any = false;
      glyph.sides.forEach(function (side, i) {
        if (!glyph.isLoose(i)) return;
        any = true;
        var target = glyph.loose(i, time), r = glyph.release && glyph.release[i];
        if (r) {                                                   // just came loose: ease out of its place
          var p = Math.min(1, (time - r.at) / 700);
          if (p >= 1) delete glyph.release[i]; else target = glyph.blend(r.from, target, ease(p));
        }
        glyph.coords(side, target);
      });
      if (any && !glyph.reducedMotion) glyph.frame = window.requestAnimationFrame(draw);
    }
    if (this.reducedMotion) this.sides.forEach(function (side, i) { if (glyph.isLoose(i)) glyph.coords(side, glyph.loose(i, 0)); });
    else this.frame = window.requestAnimationFrame(draw);
  };
  /** Move the given sides from where they are to their place in the triangle. */
  KnowledgeGlyph.prototype.settle = function (which, animate, ms) {
    var glyph = this;
    if (!animate || this.reducedMotion) { which.forEach(function (i) { glyph.coords(glyph.sides[i], glyph.endpoints[i]); }); return Promise.resolve(); }
    var starts = which.map(function (i) { return glyph.now(i); });
    var token = this.settling = (this.settling || 0) + 1;
    return new Promise(function (resolve) {
      var begun;
      function draw(now) {
        if (token !== glyph.settling) { resolve(); return; }      // a newer change took over
        if (!begun) begun = now;
        var progress = Math.min(1, (now - begun) / ms);
        which.forEach(function (i, k) { if (glyph.locked && !glyph.locked[i]) return; glyph.coords(glyph.sides[i], glyph.blend(starts[k], glyph.endpoints[i], ease(progress))); });
        if (progress < 1) window.requestAnimationFrame(draw); else resolve();
      }
      window.requestAnimationFrame(draw);
    });
  };
  KnowledgeGlyph.prototype.setState = function (state, animate) {
    var glyph = this;
    var descriptions = { recognized: 'Business identity recognized.', identified: 'Business identity identified.', understood: 'Business identity understood.', mastered: 'Business identity mastered.' };
    window.cancelAnimationFrame(this.frame);
    this.locked = null;
    this.svg.classList.remove('it-ob-by-side');
    this.previousState = this.state;
    this.state = state || null;
    this.element.hidden = !descriptions[state];
    if (!descriptions[state]) return Promise.resolve();
    this.title.textContent = descriptions[state];
    this.svg.setAttribute('aria-label', descriptions[state]);
    this.svg.classList.toggle('it-ob-understood', state === 'understood' || state === 'mastered');
    if (state === 'recognized') { this.drift(); return Promise.resolve(); }
    var starts = this.sides.map(function (side) { return ['x1', 'y1', 'x2', 'y2'].map(function (a) { return parseFloat(side.getAttribute(a)); }); });
    if (!animate || this.reducedMotion || this.previousState === state) {
      this.sides.forEach(function (side, i) { glyph.coords(side, glyph.endpoints[i]); });
      return Promise.resolve();
    }
    return new Promise(function (resolve) {
      var begun;
      function draw(now) {
        if (!begun) begun = now;
        var progress = Math.min(1, (now - begun) / 1200);
        var eased = progress < .5 ? 2 * progress * progress : 1 - Math.pow(-2 * progress + 2, 2) / 2;
        glyph.sides.forEach(function (side, i) { glyph.coords(side, starts[i].map(function (v, j) { return v + (glyph.endpoints[i][j] - v) * eased; })); });
        if (progress < 1) glyph.frame = window.requestAnimationFrame(draw);
        else resolve();
      }
      glyph.frame = window.requestAnimationFrame(draw);
    });
  };
  /**
   * "About you": sides lock one by one. `locked` = [bool, bool, bool]; `fresh` = the indexes that were just
   * completed (they animate in; already-known sides are placed at once).
   */
  KnowledgeGlyph.prototype.setLocked = function (locked, fresh, animate) {
    var glyph = this;
    window.cancelAnimationFrame(this.frame);
    this.state = null;
    var live = this.locked !== null, was = this.locked || [false, false, false];
    this.svg.classList.add('it-ob-by-side');
    this.locked = [!!locked[0], !!locked[1], !!locked[2]];
    this.release = this.release || {};
    var t0 = window.performance ? window.performance.now() : 0;
    [0, 1, 2].forEach(function (i) {
      if (glyph.locked[i]) { delete glyph.release[i]; return; }
      if (live && was[i] && animate && !glyph.reducedMotion) glyph.release[i] = { from: glyph.now(i), at: t0 };
      else if (!live) glyph.coords(glyph.sides[i], glyph.loose(i, t0));   // open already tangled, no flash of the triangle
    });
    var n = this.locked.filter(Boolean).length;
    var text = this.label ? this.label(n) : (n + ' of 3 known.');
    this.element.hidden = false;
    this.title.textContent = text;
    this.svg.setAttribute('aria-label', text);
    fresh = (fresh || []).filter(function (i) { return glyph.locked[i]; });
    var old = [0, 1, 2].filter(function (i) { return glyph.locked[i] && fresh.indexOf(i) < 0; });
    if (!live) {
      this.settle(old, false, 0);
      fresh.forEach(function (i) { glyph.coords(glyph.sides[i], glyph.loose(i, t0)); });   // they start loose, then lock in
    }
    [0, 1, 2].forEach(function (i) { glyph.mark(i, old.indexOf(i) >= 0); });
    this.drift();
    // A live change also finishes any side still on its way in (a newer change cancels the older glide).
    return this.settle(live ? old.concat(fresh) : fresh, animate, 900).then(function () {
      fresh.forEach(function (i) { glyph.mark(i, glyph.locked && glyph.locked[i]); });
    });
  };

  window.ItKnowledgeGlyph = KnowledgeGlyph;
})();
