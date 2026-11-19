/* onboarding-about.js — "About you" (onboarding/step.php?m=name): the knowledge glyph locks one side per
   answer (name to use · how to reach you · when not to bother you) and closes its circle when all three are
   known. A side locks the moment its answer is given on the screen, before Continue (owner, 2026-10-20: "when I
   select one, one line should lock into the triangle"), and comes loose again if the answer is cleared.
   When the last side is saved, the finished triangle flies into Penny's brain and lights there in gold: she keeps
   what she learned (owner, 2026-10-20; PennyBrainService People items). data-locked = saved sides "1,0,0"; data-part = this screen's question;
   data-fresh = the side just saved. Uses ItKnowledgeGlyph. */
(function () {
  'use strict';
  var el = document.getElementById('obAboutGlyph');
  if (!el || !window.ItKnowledgeGlyph) return;
  var saved = (el.getAttribute('data-locked') || '0,0,0').split(',').map(function (v) { return v === '1'; });
  var f = el.getAttribute('data-fresh');
  var justSaved = !(f === '' || f === null);
  var reduced = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
  var glyph = new window.ItKnowledgeGlyph(el, {
    reducedMotion: reduced,
    label: function (n) { return n === 3 ? 'About you: all three known.' : 'About you: ' + n + ' of 3 known.'; }
  });
  // The side saved a moment ago already locked on the screen before: place it, don't replay it.
  var done = glyph.setLocked(saved, [], !justSaved);

  // ── Lock live as the answer is given ─────────────────────────────────────
  var side = { name: 0, reach: 1, quiet: 2 }[el.getAttribute('data-part') || ''];
  var form = document.querySelector('.it-ob-workspace form');
  if (side !== undefined && form) {
    var touched = false;
    var ready = {
      name: function () { return (form.elements.first.value || '').trim() !== ''; },
      // Locks on the pick itself (owner: "when I select one"); a text-message mobile is checked on Continue.
      reach: function () { return !!form.querySelector('input[name="channel"]:checked'); },
      // The times come filled in: the side locks once the owner has looked at them (changed anything).
      quiet: function () { return touched && form.elements.from.value !== '' && form.elements.to.value !== '' && form.elements.from.value !== form.elements.to.value; }
    }[el.getAttribute('data-part')];
    var now = saved.slice();
    var check = function () {
      var on = saved[side] || ready();
      if (on === now[side]) return;
      now[side] = on;
      glyph.setLocked(now, on ? [side] : [], true);
    };
    form.addEventListener('input', function () { touched = true; check(); });
    form.addEventListener('change', function () { touched = true; check(); });
    check();
  }

  // ── All three saved: the triangle joins Penny's brain ──────────────────
  // The page drew her brain without this fact (data-arrive holds it). The finished triangle flies into the brain,
  // then the brain is redrawn with it: one more gold triangle, pulsing as the newest thing she knows.
  var brain = document.getElementById('obPennyBrain');
  var arrive = brain && brain.getAttribute('data-arrive');
  if (!arrive) return;
  function learn() {
    try {
      var item = JSON.parse(arrive);
      item.at = new Date().toISOString().slice(0, 19).replace('T', ' ');   // newest → it pulses
      var parts = JSON.parse(brain.getAttribute('data-parts') || '[]');
      parts.unshift(item);
      brain.setAttribute('data-parts', JSON.stringify(parts));
      brain.setAttribute('data-units', String((parseInt(brain.getAttribute('data-units') || '0', 10) || 0) + 1));
      brain.removeAttribute('data-arrive');
      var fresh = document.createElement('canvas');                // a new canvas: the old drawing stops with it
      brain.replaceChild(fresh, brain.querySelector('canvas'));
      var clone = brain.cloneNode(true);                           // drop the old click handler
      delete clone.dataset.ready;
      brain.parentNode.replaceChild(clone, brain);
      brain = clone;
      if (window.HeadBrain) window.HeadBrain.mount();
    } catch (e) { /* the brain stays as drawn */ }
    if (reduced) return;
    el.style.overflow = 'hidden'; el.style.height = el.offsetHeight + 'px';  // the big glyph has gone to Penny: close its space
    el.style.transition = 'height 400ms ease-in-out, margin 400ms ease-in-out';
    window.requestAnimationFrame(function () { el.style.height = '0px'; el.style.margin = '0'; });
  }
  if (reduced) { done.then(learn); return; }
  done.then(function () {
    window.setTimeout(function () {                       // a breath with the closed triangle before it moves
      var from = el.querySelector('svg').getBoundingClientRect();
      var to = brain.getBoundingClientRect();
      var ghost = document.createElement('div');
      ghost.className = 'it-ob-flying';
      ghost.style.left = from.left + 'px'; ghost.style.top = from.top + 'px';
      ghost.style.width = from.width + 'px'; ghost.style.height = from.height + 'px';
      ghost.appendChild(el.querySelector('svg').cloneNode(true));
      document.body.appendChild(ghost);
      el.style.visibility = 'hidden';
      var scale = (to.width * 0.5) / from.width;            // shrinks into the middle of the brain and fades as it enters
      var dx = to.left + to.width * 0.25 - from.left, dy = to.top + to.height * 0.25 - from.top;
      var finished = false;
      function end() { if (finished) return; finished = true; ghost.remove(); learn(); }
      ghost.addEventListener('transitionend', function (e) { if (e.propertyName === 'transform') end(); });
      window.setTimeout(end, 1200);
      window.requestAnimationFrame(function () {
        window.requestAnimationFrame(function () {
          ghost.style.transform = 'translate(' + dx + 'px,' + dy + 'px) scale(' + scale + ')';
          ghost.style.opacity = '0.2';
        });
      });
    }, justSaved ? 700 : 1100);
  });
})();
