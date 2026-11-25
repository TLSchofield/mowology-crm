/* Invoices-page insight panels: dollar figures count up from $0 to their value as the page loads.
   The server already prints the final text, so with no script or with reduced motion the numbers simply stand.
   The bars, lines and headlines animate in CSS (ittai-shell.css), not here. */
(function () {
  'use strict';

  var MONEY = /(−?\$)(\d[\d,]*(?:\.\d+)?)(k|M)?/g;
  var DURATION = 1300;

  function format(value, original) {
    var dec = (original.split('.')[1] || '').length;
    var s = value.toFixed(dec);
    if (original.indexOf(',') !== -1) {
      var parts = s.split('.');
      parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',');
      s = parts.join('.');
    }
    return s;
  }

  function prepare(el) {
    var text = el.textContent, parts = [], last = 0, m;
    MONEY.lastIndex = 0;
    while ((m = MONEY.exec(text)) !== null) {
      parts.push({ pre: text.slice(last, m.index), sign: m[1], raw: m[2], suffix: m[3] || '', value: parseFloat(m[2].replace(/,/g, '')) });
      last = m.index + m[0].length;
    }
    if (!parts.length) return null;
    return { el: el, parts: parts, tail: text.slice(last) };
  }

  function render(item, k) {
    var out = '';
    item.parts.forEach(function (p) { out += p.pre + p.sign + format(p.value * k, p.raw) + p.suffix; });
    item.el.textContent = out + item.tail;
  }

  document.addEventListener('DOMContentLoaded', function () {
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    var strip = document.querySelector('.it-inv-viz');
    if (!strip) return;
    var items = [];
    Array.prototype.forEach.call(strip.querySelectorAll('figure.it-job-viz'), function (fig, i) {
      Array.prototype.forEach.call(fig.querySelectorAll('.it-viz-headline, .it-viz-amount, .it-viz-cash-lbl:not(.it-viz-floor-lbl), .it-job-viz-gst b'), function (el) {
        var item = prepare(el);
        if (item) { item.delay = 200 + i * 140; items.push(item); render(item, 0); }
      });
    });
    var t0 = null;
    function frame(now) {
      if (t0 === null) t0 = now;
      var running = false;
      items.forEach(function (item) {
        var p = Math.min(1, Math.max(0, (now - t0 - item.delay) / DURATION));
        render(item, 1 - Math.pow(1 - p, 3));
        if (p < 1) running = true;
      });
      if (running) requestAnimationFrame(frame);
    }
    requestAnimationFrame(frame);
  });
}());
