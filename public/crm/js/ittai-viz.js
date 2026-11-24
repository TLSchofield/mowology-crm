(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    var strips = document.querySelectorAll('.it-inv-viz');
    strips.forEach(function (strip) {
      var figures = strip.querySelectorAll('figure.it-job-viz');
      strip.classList.add('is-armed');
      figures.forEach(function (figure, index) {
        figure.style.setProperty('--d', (index * 90) + 'ms');
      });
      if (!('IntersectionObserver' in window)) {
        figures.forEach(function (figure) { figure.classList.add('is-in'); });
        return;
      }
      var observer = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
          if (!entry.isIntersecting) return;
          entry.target.classList.add('is-in');
          observer.unobserve(entry.target);
        });
      }, { threshold: 0.25 });
      figures.forEach(function (figure) { observer.observe(figure); });
    });
  });
}());
