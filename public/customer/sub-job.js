/* Subcontract job page: each tap goes to the server, and the page only moves on once the server has it. */
(function () {
  'use strict';
  var root = document.getElementById('sj');
  var form = document.getElementById('sj-form');
  var api = '/customer/api/sub-job.php';
  var modeKey = 'sj-mode';

  var sun = document.getElementById('sj-sun');
  function setMode(on) {
    if (on) document.documentElement.setAttribute('data-mode', 'field');
    else document.documentElement.removeAttribute('data-mode');
    if (sun) sun.setAttribute('aria-pressed', on ? 'true' : 'false');
  }
  try { setMode(localStorage.getItem(modeKey) === 'field'); } catch (e) { /* private window */ }
  if (sun) sun.addEventListener('click', function () {
    var on = document.documentElement.getAttribute('data-mode') !== 'field';
    setMode(on);
    try { localStorage.setItem(modeKey, on ? 'field' : ''); } catch (e) { /* private window */ }
  });

  if (!root || !form) return;
  var token = root.getAttribute('data-token');
  var errorBox = document.getElementById('sj-error');

  function showError(words) {
    if (!errorBox) return;
    errorBox.textContent = words || '';
    errorBox.hidden = !words;
  }

  function send(body) {
    body.append('t', token);
    return fetch(api, { method: 'POST', body: body, credentials: 'omit', cache: 'no-store' }).then(function (r) {
      return r.json().catch(function () { return { ok: false, error: 'We could not reach the server. Check your signal and try again.' }; });
    }).catch(function () {
      return { ok: false, error: 'No signal. Your tap was not saved. Try again when you have a connection.' };
    });
  }

  /* Sent from here, not the page load, so a messaging app's link preview does not count as her opening it. */
  var opened = new FormData();
  opened.append('action', 'opened');
  send(opened);

  function location() {
    return new Promise(function (resolve) {
      if (!navigator.geolocation) return resolve(null);
      navigator.geolocation.getCurrentPosition(function (p) { resolve(p.coords); }, function () { resolve(null); },
        { enableHighAccuracy: true, timeout: 8000, maximumAge: 30000 });
    });
  }

  function act(button) {
    var action = button.getAttribute('data-action');
    var label = button.textContent;
    button.disabled = true;
    button.textContent = 'Sending…';
    showError('');
    var body = new FormData(form);
    body.append('action', action);
    var ready = (action === 'start' || action === 'finish') ? location() : Promise.resolve(null);
    ready.then(function (c) {
      if (c) { body.append('lat', c.latitude); body.append('lng', c.longitude); body.append('accuracy', c.accuracy); }
      return send(body);
    }).then(function (res) {
      if (res.ok) { window.location.reload(); return; }
      button.disabled = false;
      button.textContent = label;
      showError(res.error || 'That did not save. Please try again.');
    });
  }

  form.addEventListener('submit', function (ev) {
    ev.preventDefault();
    var button = form.querySelector('button[type="submit"]');
    if (button && !button.disabled) act(button);
  });
  document.querySelectorAll('[data-action]').forEach(function (b) {
    if (b.type === 'submit') return;
    b.addEventListener('click', function () { if (!b.disabled) act(b); });
  });

  /* A phone photo is shrunk first: a 15 MB original on cellular times out. Anything that can't be drawn goes as it is. */
  function shrink(file) {
    if (!/^image\/(jpeg|png|webp)$/.test(file.type) || !window.createImageBitmap) return Promise.resolve(file);
    return createImageBitmap(file, { imageOrientation: 'from-image' }).then(function (img) {
      var scale = Math.min(1, 1800 / Math.max(img.width, img.height));
      var canvas = document.createElement('canvas');
      canvas.width = Math.round(img.width * scale);
      canvas.height = Math.round(img.height * scale);
      canvas.getContext('2d').drawImage(img, 0, 0, canvas.width, canvas.height);
      return new Promise(function (resolve) {
        canvas.toBlob(function (blob) { resolve(blob ? new File([blob], 'photo.jpg', { type: 'image/jpeg' }) : file); }, 'image/jpeg', 0.85);
      });
    }).catch(function () { return file; });
  }

  function uploads(input, kind, word, status, doneWord) {
    if (!input) return;
    var submit = form.querySelector('button[type="submit"]');
    input.addEventListener('change', function () {
      var files = Array.prototype.slice.call(input.files || []);
      if (!files.length) return;
      var sent = 0;
      var failed = 0;
      status.hidden = false;
      if (submit) submit.disabled = true;
      showError('');
      files.reduce(function (chain, file, i) {
        return chain.then(function () {
          status.textContent = 'Sending ' + (i + 1) + ' of ' + files.length + '…';
          return shrink(file).then(function (ready) {
            var body = new FormData();
            body.append('action', 'upload');
            body.append('kind', kind);
            body.append('file', ready, ready.name || 'file');
            return send(body);
          }).then(function (res) {
            if (res.ok) { sent++; } else { failed++; showError(res.error); }
          });
        });
      }, Promise.resolve()).then(function () {
        if (submit) submit.disabled = false;
        input.value = '';
        status.textContent = failed ? (sent + ' sent. ' + failed + ' did not send. Tap to try those again.') : (sent + ' sent.');
        if (sent && word) word.textContent = doneWord;
      });
    });
  }

  uploads(document.getElementById('sj-photos'), 'photo', document.getElementById('sj-photo-word'), document.getElementById('sj-photo-status'), 'Add more photos');
  uploads(document.getElementById('sj-invoice'), 'invoice', document.getElementById('sj-invoice-word'), document.getElementById('sj-invoice-status'), 'Invoice attached. Replace or add');
})();
