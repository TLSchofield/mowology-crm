/* Otto's Subcontracts carousel: one job at a time, arrows or a swipe, and the job's one button. */
(function () {
  'use strict';
  var board = document.getElementById('mw-subboard');
  if (!board) return;
  var slides = Array.prototype.slice.call(board.querySelectorAll('.mw-subboard-slide'));
  var indexEl = board.querySelector('[data-sub-index]');
  var errorEl = board.querySelector('.mw-subboard-error');
  var at = 0;

  function show(i) {
    at = (i + slides.length) % slides.length;
    slides.forEach(function (s, n) { s.hidden = n !== at; });
    if (indexEl) indexEl.textContent = String(at + 1);
    centreCurrent(slides[at]);
  }

  // A long track scrolls sideways on a phone: keep the stage it is at in view.
  function centreCurrent(slide) {
    var track = slide.querySelector('.mw-subboard-track');
    var now = slide.querySelector('[aria-current="step"]');
    if (track && now && track.scrollWidth > track.clientWidth) {
      track.scrollLeft = Math.max(0, now.offsetLeft - track.offsetLeft - (track.clientWidth - now.offsetWidth) / 2);
    }
  }
  if (slides.length) centreCurrent(slides[0]);

  board.querySelectorAll('[data-sub-step]').forEach(function (b) {
    b.addEventListener('click', function () { show(at + parseInt(b.getAttribute('data-sub-step'), 10)); });
  });

  // Swipe on a phone or tablet: a sideways drag of 40 px moves one job.
  var startX = null;
  // A drag on the stage track scrolls the track, not the jobs.
  board.addEventListener('touchstart', function (e) { startX = e.target.closest('.mw-subboard-track') ? null : e.touches[0].clientX; }, { passive: true });
  board.addEventListener('touchend', function (e) {
    if (startX === null || slides.length < 2) return;
    var dx = e.changedTouches[0].clientX - startX;
    startX = null;
    if (Math.abs(dx) > 40) show(at + (dx < 0 ? 1 : -1));
  });

  var confirmWords = { accept: 'Accept the work? This completes the visit.', mark_paid: 'Mark this paid? Only do this once the money has gone.' };
  board.querySelectorAll('[data-sub-board]').forEach(function (b) {
    b.addEventListener('click', function () {
      var action = b.getAttribute('data-sub-board');
      if (confirmWords[action] && !window.confirm(confirmWords[action])) return;
      b.disabled = true;
      errorEl.hidden = true;
      fetch('/crm/api/subcontract.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.MW_CSRF_TOKEN || '' },
        body: JSON.stringify({ action: action, job_id: parseInt(b.getAttribute('data-job'), 10) })
      }).then(function (r) {
        return r.json().catch(function () { return { ok: false, error: 'The server did not answer properly. Nothing was changed.' }; });
      }).catch(function () {
        return { ok: false, error: 'No connection. Nothing was changed.' };
      }).then(function (res) {
        if (res.ok) { window.location.reload(); return; }
        b.disabled = false;
        errorEl.textContent = res.error || 'That did not work. Nothing was changed.';
        errorEl.hidden = false;
      });
    });
  });
})();
