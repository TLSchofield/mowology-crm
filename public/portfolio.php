<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

$pageTitle       = 'Our Work | Mowology Portfolio';
$pageDescription = 'View our portfolio of landscaping transformations in Vancouver, Burnaby, and Richmond. Real before & after results from strata and residential projects.';
$activeNav       = 'portfolio';

require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/header.php';
?>

  <!-- ── Page Hero ──────────────────────────────────────────── -->
  <section class="page-hero">
    <div class="container">
      <h1>The Mowology Difference</h1>
      <p>Real transformations across Metro Vancouver — drag the slider to see the results</p>
    </div>
  </section>

  <!-- ── Enhanced Before & After Module ──────────────────────── -->
  <section class="mw-ba" id="mw-ba-portfolio"
           data-auto-feed="false"
           data-max-pairs="6"
           data-pairs="[]">

    <div class="mw-ba__inner">

      <div class="mw-ba__header mw-reveal">
        <span class="mw-label">Our Work</span>
        <h2 class="mw-ba__heading">Before &amp; After</h2>
        <p class="mw-ba__sub">Real results from our crews across Metro Vancouver. Click any card to drag the comparison slider.</p>
      </div>

      <div class="mw-ba__filters mw-reveal" style="--delay:0.15s">
        <button class="mw-ba__filter-btn is-active" data-filter="all">All Work</button>
        <button class="mw-ba__filter-btn" data-filter="lawn">Lawn</button>
        <button class="mw-ba__filter-btn" data-filter="garden">Garden</button>
        <button class="mw-ba__filter-btn" data-filter="cleanup">Cleanup</button>
        <button class="mw-ba__filter-btn" data-filter="strata">Strata</button>
      </div>

      <div class="mw-ba__grid" id="mw-ba-portfolio-grid">
        <div class="mw-ba__loading" id="mw-ba-portfolio-loading">
          <div class="mw-ba__spinner"></div>
          <span>Loading transformations&hellip;</span>
        </div>
      </div>

      <!-- Lightbox -->
      <div class="mw-ba__lightbox" id="mw-ba-portfolio-lb"
           role="dialog" aria-modal="true" aria-label="Before &amp; After comparison">
        <button class="mw-ba__lb-close" aria-label="Close">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <line x1="18" y1="6" x2="6" y2="18"/>
            <line x1="6" y1="6" x2="18" y2="18"/>
          </svg>
        </button>
        <div class="mw-ba__lb-slider">
          <div class="mw-ba__lb-before"></div>
          <div class="mw-ba__lb-after"></div>
          <div class="mw-ba__lb-handle">
            <div class="mw-ba__lb-line"></div>
            <div class="mw-ba__lb-grip">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="15 18 9 12 15 6"/><polyline points="9 18 3 12 9 6"/></svg>
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="9 18 15 12 9 6"/><polyline points="15 18 21 12 15 6"/></svg>
            </div>
          </div>
          <div class="mw-ba__lb-label mw-ba__lb-label--before">Before</div>
          <div class="mw-ba__lb-label mw-ba__lb-label--after">After</div>
        </div>
        <div class="mw-ba__lb-meta"></div>
        <div class="mw-ba__lb-nav">
          <button class="mw-ba__lb-nav-btn mw-ba__lb-prev" aria-label="Previous">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M19 12H5M12 5l-7 7 7 7"/></svg>
          </button>
          <button class="mw-ba__lb-nav-btn mw-ba__lb-next" aria-label="Next">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
          </button>
        </div>
      </div>
      <div class="mw-ba__lb-backdrop" id="mw-ba-portfolio-backdrop"></div>

    </div>
  </section>

  <!-- ── CTA ─────────────────────────────────────────────────── -->
  <section class="cta-section">
    <div class="container">
      <h2>Ready to Transform Your Property?</h2>
      <p>Get a free, no-obligation quote for your property</p>
      <div class="cta-buttons">
        <a href="/jobFlow/jobFlow-getQuote.php" class="mw-btn mw-btn--white">
          Request Free Quote
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
        </a>
        <a href="tel:7788469273" class="mw-btn mw-btn--outline-white">Call 778-846-9273</a>
      </div>
    </div>
  </section>

<?php require __DIR__ . '/includes/footer.php'; ?>

<script>
(function () {
  'use strict';
  var sectionId = 'mw-ba-portfolio';
  var section  = document.getElementById(sectionId);
  var grid     = document.getElementById(sectionId + '-grid');
  var loading  = document.getElementById(sectionId + '-loading');
  if (!section || !grid) return;

  var maxPairs   = parseInt(section.dataset.maxPairs) || 6;
  var activeFilter = 'all';
  var lbIndex = 0;
  var allPairs = [], filteredPairs = [];

  var DEMO_PAIRS = [
    { id:1, before_url:'/assets/img/portfolio/hedge-before.jpg', after_url:'/assets/img/portfolio/hedge-after.jpg', label:'Hedge Trim — Point Grey', service:'Hedge Trimming', date:'March 2026', category:'garden', crew:'Team A' },
    { id:2, before_url:'https://images.unsplash.com/photo-1504307651254-35680f356dfd?w=900', after_url:'/assets/img/portfolio/lawn-cut-service.jpg', label:'Lawn Recovery — Burnaby', service:'Lawn Care', date:'February 2026', category:'lawn', crew:'Team B' },
    { id:3, before_url:'https://images.unsplash.com/photo-1523348837708-15d4a09cfac2?w=900', after_url:'https://images.unsplash.com/photo-1508193638397-1c4234db14d8?w=900', label:'Strata Common Area — Richmond', service:'Strata Maintenance', date:'March 2026', category:'strata', crew:'Team A' },
    { id:4, before_url:'https://images.unsplash.com/photo-1416879595882-3373a0480b5b?w=900', after_url:'https://images.unsplash.com/photo-1414235077428-338989a2e8c0?w=900', label:'Spring Cleanup — Kitsilano', service:'Seasonal Cleanup', date:'March 2026', category:'cleanup', crew:'Team C' },
    { id:5, before_url:'/assets/img/portfolio/hedge-trimming.jpg', after_url:'/assets/img/portfolio/hedge-after.jpg', label:'Hedge & Border Refresh — West Van', service:'Garden Care', date:'February 2026', category:'garden', crew:'Team B' },
    { id:6, before_url:'https://images.unsplash.com/photo-1503945438517-f65904a52ce6?w=900', after_url:'https://images.unsplash.com/photo-1448630360428-65456885c650?w=900', label:'Property Grounds — Metrotown', service:'Strata Maintenance', date:'January 2026', category:'strata', crew:'Team A' }
  ];

  // Lightbox elements
  var lb       = document.getElementById(sectionId + '-lb');
  var backdrop = document.getElementById(sectionId + '-backdrop');
  var lbSlider = lb.querySelector('.mw-ba__lb-slider');
  var lbBefore = lb.querySelector('.mw-ba__lb-before');
  var lbAfter  = lb.querySelector('.mw-ba__lb-after');
  var lbHandle = lb.querySelector('.mw-ba__lb-handle');
  var lbMeta   = lb.querySelector('.mw-ba__lb-meta');

  var revealObs = new IntersectionObserver(function(entries) {
    entries.forEach(function(e) {
      if (e.isIntersecting) { e.target.classList.add('is-visible'); revealObs.unobserve(e.target); }
    });
  }, { threshold: 0.1 });

  function escHtml(str) {
    var d = document.createElement('div');
    d.appendChild(document.createTextNode(str || ''));
    return d.innerHTML;
  }

  function renderGrid() {
    if (loading) { loading.remove(); loading = null; }
    grid.innerHTML = '';
    var pairs = filteredPairs.slice(0, maxPairs);
    if (!pairs.length) {
      grid.innerHTML = '<div class="mw-ba__empty">No transformations yet — check back soon!</div>';
      return;
    }
    pairs.forEach(function(pair, idx) {
      var card = document.createElement('div');
      card.className = 'mw-ba-card';
      card.style.setProperty('--delay', (idx * 0.08) + 's');
      card.dataset.index = idx;
      card.innerHTML =
        '<div class="mw-ba-card__after" style="background-image:url(\'' + pair.after_url + '\')"></div>' +
        '<div class="mw-ba-card__before" style="background-image:url(\'' + pair.before_url + '\')"></div>' +
        '<div class="mw-ba-card__line"></div>' +
        '<div class="mw-ba-card__handle"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/><polyline points="15 18 9 12 15 6"/></svg></div>' +
        '<div class="mw-ba-card__labels"><span class="mw-ba-card__label mw-ba-card__label--before">Before</span><span class="mw-ba-card__label mw-ba-card__label--after">After</span></div>' +
        '<div class="mw-ba-card__overlay"><div class="mw-ba-card__meta"><div class="mw-ba-card__service">' + escHtml(pair.service) + '</div><div class="mw-ba-card__label-text">' + escHtml(pair.label) + '</div><div class="mw-ba-card__date">' + escHtml(pair.date) + (pair.crew ? ' · ' + escHtml(pair.crew) : '') + '</div></div></div>' +
        '<div class="mw-ba-card__hint"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 3 21 3 21 9"/><polyline points="9 21 3 21 3 15"/><line x1="21" y1="3" x2="14" y2="10"/><line x1="3" y1="21" x2="10" y2="14"/></svg></div>';
      card.addEventListener('click', function() { openLightbox(idx); });
      grid.appendChild(card);
      revealObs.observe(card);
    });
  }

  function init() {
    allPairs = DEMO_PAIRS;
    filteredPairs = allPairs;
    renderGrid();
  }

  // Filters
  section.querySelectorAll('.mw-ba__filter-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
      section.querySelectorAll('.mw-ba__filter-btn').forEach(function(b) { b.classList.remove('is-active'); });
      btn.classList.add('is-active');
      activeFilter = btn.dataset.filter;
      filteredPairs = activeFilter === 'all' ? allPairs : allPairs.filter(function(p) { return p.category === activeFilter; });
      renderGrid();
    });
  });

  // Lightbox
  function updateHandle(pct) {
    pct = Math.max(3, Math.min(97, pct));
    lbHandle.style.left = pct + '%';
    lbBefore.style.clipPath = 'inset(0 ' + (100 - pct) + '% 0 0)';
  }
  function loadLbPair() {
    var pair = filteredPairs[lbIndex]; if (!pair) return;
    lbBefore.style.backgroundImage = "url('" + pair.before_url + "')";
    lbAfter.style.backgroundImage  = "url('" + pair.after_url + "')";
    lbMeta.innerHTML = '<div class="mw-ba__lb-meta-service">' + escHtml(pair.service) + '</div><div class="mw-ba__lb-meta-label">' + escHtml(pair.label) + '</div><div class="mw-ba__lb-meta-date">' + escHtml(pair.date) + (pair.crew ? ' · ' + escHtml(pair.crew) : '') + '</div>';
    updateHandle(50);
  }
  function openLightbox(idx) {
    lbIndex = idx; loadLbPair();
    lb.classList.add('is-open'); backdrop.classList.add('is-open');
    document.body.style.overflow = 'hidden';
  }
  function closeLightbox() {
    lb.classList.remove('is-open'); backdrop.classList.remove('is-open');
    document.body.style.overflow = '';
  }
  function navigate(dir) {
    lbIndex = ((lbIndex + dir) + filteredPairs.length) % filteredPairs.length;
    loadLbPair();
  }

  lb.querySelector('.mw-ba__lb-close').addEventListener('click', closeLightbox);
  backdrop.addEventListener('click', closeLightbox);
  lb.querySelector('.mw-ba__lb-prev').addEventListener('click', function() { navigate(-1); });
  lb.querySelector('.mw-ba__lb-next').addEventListener('click', function() { navigate(1); });
  document.addEventListener('keydown', function(e) {
    if (!lb.classList.contains('is-open')) return;
    if (e.key === 'Escape')      closeLightbox();
    if (e.key === 'ArrowLeft')   navigate(-1);
    if (e.key === 'ArrowRight')  navigate(1);
  });

  // Drag slider in lightbox
  var isDragging = false;
  lbSlider.addEventListener('mousedown',  function() { isDragging = true; });
  window.addEventListener('mousemove', function(e) {
    if (!isDragging) return;
    var rect = lbSlider.getBoundingClientRect();
    updateHandle(((e.clientX - rect.left) / rect.width) * 100);
  });
  window.addEventListener('mouseup', function() { isDragging = false; });
  lbSlider.addEventListener('touchstart', function() { isDragging = true; }, { passive: true });
  lbSlider.addEventListener('touchmove', function(e) {
    if (!isDragging) return;
    var rect = lbSlider.getBoundingClientRect();
    updateHandle(((e.touches[0].clientX - rect.left) / rect.width) * 100);
  }, { passive: true });
  lbSlider.addEventListener('touchend', function() { isDragging = false; });

  // Reveal header elements
  section.querySelectorAll('.mw-reveal').forEach(function(el) {
    new IntersectionObserver(function(entries) {
      if (entries[0].isIntersecting) { entries[0].target.classList.add('is-visible'); }
    }, { threshold: 0.1 }).observe(el);
  });

  init();
})();
</script>
