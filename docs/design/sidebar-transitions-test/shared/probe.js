/* Records, on every frame, where the sidebar and its highlight are, so each solution can be judged by numbers:
   frames with no sidebar (a flash), jumps of the sidebar between frames (jitter), and whether the document was replaced.
   The log survives page loads (sessionStorage). Read it with window.__probe(). */
(function () {
  var KEY = 'probe', log;
  try { log = JSON.parse(sessionStorage.getItem(KEY) || '[]'); } catch (e) { log = []; }
  var doc = Math.round(performance.timeOrigin);
  function r(el) { if (!el) return null; var b = el.getBoundingClientRect(); return [Math.round(b.left * 10) / 10, Math.round(b.top * 10) / 10, Math.round(b.width * 10) / 10, Math.round(b.height * 10) / 10]; }
  function frame() {
    var sb = document.querySelector('.sidebar'), hl = document.querySelector('.hl'), card = document.querySelector('.card');
    log.push({ t: Math.round(performance.timeOrigin + performance.now()), d: doc, sb: r(sb), hl: r(hl), card: r(card) });
    if (log.length > 4000) log.splice(0, log.length - 4000);
    requestAnimationFrame(frame);
  }
  requestAnimationFrame(frame);
  function save() { try { sessionStorage.setItem(KEY, JSON.stringify(log)); } catch (e) {} }
  addEventListener('pagehide', save); setInterval(save, 500);
  window.__probe = function () { save(); return JSON.parse(sessionStorage.getItem(KEY) || '[]'); };
  window.__probeClear = function () { log.length = 0; sessionStorage.removeItem(KEY); };
  // the sidebar's own age: proof the sidebar was not rebuilt
  var born = Date.now();
  setInterval(function () { var a = document.getElementById('age'); if (a) a.textContent = Math.round((Date.now() - born) / 1000); }, 500);
})();
