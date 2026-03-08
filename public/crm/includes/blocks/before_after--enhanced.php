<?php
/**
 * Enhanced Before & After Block Renderer — Slider cards + lightbox
 *
 * Config: {title, description, pairs[], auto_feed, max_pairs, filter_categories}
 * Each pair: {before_media_id, after_media_id, caption}
 *
 * When auto_feed is true, JS fetches from /crm/api/before-after-pairs.php
 */

$heading    = $config['title']       ?? $config['heading']    ?? 'The Mowology Difference';
$subheading = $config['description'] ?? $config['subheading'] ?? 'Real results from our crews across Metro Vancouver. Drag the slider to see the transformation.';
$autoFeed   = !empty($config['auto_feed']);
$maxPairs   = (int)($config['max_pairs'] ?? 6);

// Build static pairs from CMS config if not auto-feeding
$staticPairs = [];
if (!$autoFeed && !empty($config['pairs'])) {
    foreach ($config['pairs'] as $pair) {
        $before = null;
        $after  = null;
        if (!empty($pair['before_media_id'])) {
            $before = cms_getMediaAssetById((int)$pair['before_media_id']);
        }
        if (!empty($pair['after_media_id'])) {
            $after = cms_getMediaAssetById((int)$pair['after_media_id']);
        }
        if ($before && $after) {
            $staticPairs[] = [
                'id'         => count($staticPairs) + 1,
                'before_url' => $before['file_path'] ?? '',
                'after_url'  => $after['file_path'] ?? '',
                'label'      => $pair['caption'] ?? '',
                'service'    => $pair['service'] ?? '',
                'date'       => $pair['date'] ?? '',
                'category'   => $pair['category'] ?? 'general',
                'crew'       => '',
            ];
        }
    }
}

$pairsJson = json_encode($staticPairs, JSON_HEX_TAG | JSON_HEX_APOS);
$uid = 'mw-ba-' . ($block['id'] ?? uniqid());
?>

<section class="mw-ba" id="<?= h($uid) ?>" data-module="before-after"
         data-auto-feed="<?= $autoFeed ? 'true' : 'false' ?>"
         data-max-pairs="<?= $maxPairs ?>"
         data-pairs="<?= h($pairsJson) ?>">

  <div class="mw-ba__inner">
    <div class="mw-ba__header mw-reveal">
      <span class="mw-label" style="color:#c9a84c">Our Work</span>
      <h2 class="mw-ba__heading"><?= h($heading) ?></h2>
      <p class="mw-ba__sub"><?= h($subheading) ?></p>
    </div>

    <div class="mw-ba__filters mw-reveal" style="--delay:.15s">
      <button class="mw-ba__filter-btn is-active" data-filter="all">All Work</button>
      <button class="mw-ba__filter-btn" data-filter="lawn">Lawn</button>
      <button class="mw-ba__filter-btn" data-filter="garden">Garden</button>
      <button class="mw-ba__filter-btn" data-filter="cleanup">Cleanup</button>
      <button class="mw-ba__filter-btn" data-filter="strata">Strata</button>
    </div>

    <div class="mw-ba__grid" id="<?= h($uid) ?>-grid">
      <div class="mw-ba__loading" id="<?= h($uid) ?>-loading">
        <div class="mw-ba__spinner"></div>
        <span>Loading transformations...</span>
      </div>
    </div>

    <!-- Lightbox -->
    <div class="mw-ba__lightbox" id="<?= h($uid) ?>-lb" role="dialog" aria-modal="true" aria-label="Before & After comparison">
      <button class="mw-ba__lb-close" aria-label="Close">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
      <div class="mw-ba__lb-slider">
        <div class="mw-ba__lb-before"></div>
        <div class="mw-ba__lb-after"></div>
        <div class="mw-ba__lb-handle">
          <div class="mw-ba__lb-line"></div>
          <div class="mw-ba__lb-grip">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/><polyline points="9 18 3 12 9 6"/></svg>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/><polyline points="15 18 21 12 15 6"/></svg>
          </div>
        </div>
        <div class="mw-ba__lb-label mw-ba__lb-label--before">Before</div>
        <div class="mw-ba__lb-label mw-ba__lb-label--after">After</div>
      </div>
      <div class="mw-ba__lb-meta"></div>
      <div class="mw-ba__lb-nav">
        <button class="mw-ba__lb-nav-btn mw-ba__lb-prev">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5M12 5l-7 7 7 7"/></svg>
        </button>
        <button class="mw-ba__lb-nav-btn mw-ba__lb-next">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
        </button>
      </div>
    </div>
    <div class="mw-ba__lb-backdrop" id="<?= h($uid) ?>-backdrop"></div>
  </div>
</section>

<style>
.mw-ba { background: #0d1f12; padding: clamp(4rem, 8vw, 7rem) 0; position: relative; overflow: hidden; }
.mw-ba::before { content:''; position:absolute; top:0; left:0; right:0; height:1px; background:linear-gradient(to right, transparent, rgba(201,168,76,0.4), transparent); }
.mw-ba::after { content:''; position:absolute; bottom:0; left:0; right:0; height:1px; background:linear-gradient(to right, transparent, rgba(45,122,58,0.3), transparent); }
.mw-ba__inner { max-width:1380px; margin:0 auto; padding:0 max(1.5rem, calc((100vw - 1380px)/2 + 1.5rem)); }
.mw-ba__header { text-align:center; max-width:640px; margin:0 auto 2.5rem; }
.mw-ba .mw-label::before { background:#c9a84c; }
.mw-ba__heading { font-family:'Playfair Display',serif; font-size:clamp(2rem,3.5vw,3rem); font-weight:700; color:#fff; margin:0 0 1rem; line-height:1.18; }
.mw-ba__sub { font-family:'DM Sans',sans-serif; font-size:1.05rem; color:rgba(255,255,255,0.55); line-height:1.65; margin:0; }
.mw-ba__filters { display:flex; gap:8px; flex-wrap:wrap; justify-content:center; margin-bottom:3rem; }
.mw-ba__filter-btn { font-family:'DM Sans',sans-serif; font-size:0.8rem; font-weight:600; letter-spacing:0.06em; padding:8px 18px; border-radius:50px; border:1.5px solid rgba(255,255,255,0.15); background:transparent; color:rgba(255,255,255,0.5); cursor:pointer; transition:all 0.22s; }
.mw-ba__filter-btn:hover { border-color:rgba(255,255,255,0.4); color:rgba(255,255,255,0.85); }
.mw-ba__filter-btn.is-active { background:#2d7a3a; border-color:#2d7a3a; color:#fff; box-shadow:0 4px 16px rgba(45,122,58,0.35); }
.mw-ba__grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(360px, 1fr)); gap:1.5rem; }
@media (max-width:500px) { .mw-ba__grid { grid-template-columns:1fr; } }
.mw-ba__loading { grid-column:1/-1; display:flex; flex-direction:column; align-items:center; gap:1rem; padding:4rem 0; color:rgba(255,255,255,0.35); font-family:'DM Sans',sans-serif; font-size:0.9rem; }
.mw-ba__spinner { width:36px; height:36px; border:2px solid rgba(255,255,255,0.1); border-top-color:#2d7a3a; border-radius:50%; animation:baSpin 0.8s linear infinite; }
@keyframes baSpin { to { transform:rotate(360deg); } }
.mw-ba-card { position:relative; border-radius:14px; overflow:hidden; cursor:pointer; aspect-ratio:4/3; background:#1a3020; box-shadow:0 4px 24px rgba(0,0,0,0.4); transition:transform 0.35s, box-shadow 0.35s; opacity:0; transform:translateY(28px) scale(0.98); }
.mw-ba-card.is-visible { animation:baCardReveal 0.55s var(--delay,0s) cubic-bezier(.22,.61,.36,1) forwards; }
@keyframes baCardReveal { to { opacity:1; transform:translateY(0) scale(1); } }
.mw-ba-card:hover { transform:translateY(-6px) scale(1.01); box-shadow:0 16px 48px rgba(0,0,0,0.55); }
.mw-ba-card:hover .mw-ba-card__hint { opacity:1; }
.mw-ba-card__after { position:absolute; inset:0; background-size:cover; background-position:center; }
.mw-ba-card__before { position:absolute; inset:0; background-size:cover; background-position:center; clip-path:inset(0 50% 0 0); transition:clip-path 0.4s ease; }
.mw-ba-card:hover .mw-ba-card__before { clip-path:inset(0 35% 0 0); }
.mw-ba-card__line { position:absolute; top:0; bottom:0; left:50%; width:2px; background:rgba(255,255,255,0.7); transform:translateX(-50%); transition:left 0.4s ease; pointer-events:none; }
.mw-ba-card:hover .mw-ba-card__line { left:65%; }
.mw-ba-card__handle { position:absolute; top:50%; left:50%; transform:translate(-50%,-50%); width:36px; height:36px; background:#fff; border-radius:50%; display:flex; align-items:center; justify-content:center; box-shadow:0 2px 12px rgba(0,0,0,0.4); transition:left 0.4s ease, transform 0.25s; pointer-events:none; z-index:4; }
.mw-ba-card__handle svg { width:16px; height:16px; stroke:#1a3020; }
.mw-ba-card__labels { position:absolute; bottom:0; left:0; right:0; z-index:5; display:flex; justify-content:space-between; padding:1rem 1rem 0; pointer-events:none; }
.mw-ba-card__label { font-family:'DM Sans',sans-serif; font-size:0.68rem; font-weight:700; letter-spacing:0.14em; text-transform:uppercase; padding:4px 10px; border-radius:4px; }
.mw-ba-card__label--before { background:rgba(0,0,0,0.55); color:rgba(255,255,255,0.8); backdrop-filter:blur(4px); }
.mw-ba-card__label--after { background:rgba(45,122,58,0.8); color:#fff; backdrop-filter:blur(4px); }
.mw-ba-card__overlay { position:absolute; inset:0; background:linear-gradient(to top, rgba(5,15,8,0.88) 0%, transparent 50%); z-index:3; display:flex; align-items:flex-end; padding:1.25rem; }
.mw-ba-card__service { font-family:'DM Sans',sans-serif; font-size:0.7rem; font-weight:600; letter-spacing:0.12em; text-transform:uppercase; color:#c9a84c; margin-bottom:4px; }
.mw-ba-card__label-text { font-family:'Playfair Display',serif; font-size:1.05rem; font-weight:600; color:#fff; line-height:1.3; margin-bottom:4px; }
.mw-ba-card__date { font-family:'DM Sans',sans-serif; font-size:0.72rem; color:rgba(255,255,255,0.45); }
.mw-ba-card__hint { position:absolute; top:1rem; right:1rem; z-index:6; width:34px; height:34px; background:rgba(255,255,255,0.12); backdrop-filter:blur(6px); border:1px solid rgba(255,255,255,0.25); border-radius:8px; display:flex; align-items:center; justify-content:center; opacity:0; transition:opacity 0.25s; }
.mw-ba-card__hint svg { width:14px; height:14px; stroke:#fff; }
.mw-ba__empty { grid-column:1/-1; text-align:center; padding:4rem 1rem; font-family:'DM Sans',sans-serif; font-size:1rem; color:rgba(255,255,255,0.35); }
/* Lightbox */
.mw-ba__lb-backdrop { position:fixed; inset:0; z-index:10000; background:rgba(5,15,8,0.9); backdrop-filter:blur(8px); opacity:0; pointer-events:none; transition:opacity 0.3s; }
.mw-ba__lb-backdrop.is-open { opacity:1; pointer-events:auto; }
.mw-ba__lightbox { position:fixed; inset:0; z-index:10001; display:flex; flex-direction:column; align-items:center; justify-content:center; gap:1.5rem; pointer-events:none; opacity:0; transform:scale(0.96); transition:opacity 0.3s, transform 0.3s; padding:1.5rem; }
.mw-ba__lightbox.is-open { opacity:1; transform:scale(1); pointer-events:auto; }
.mw-ba__lb-close { position:fixed; top:1.25rem; right:1.25rem; width:44px; height:44px; background:rgba(255,255,255,0.1); border:1px solid rgba(255,255,255,0.2); border-radius:50%; display:flex; align-items:center; justify-content:center; cursor:pointer; z-index:10002; transition:background 0.2s; }
.mw-ba__lb-close:hover { background:rgba(255,255,255,0.2); }
.mw-ba__lb-close svg { width:18px; height:18px; stroke:#fff; }
.mw-ba__lb-slider { position:relative; width:min(90vw, 1100px); height:min(65vh, 680px); border-radius:14px; overflow:hidden; user-select:none; box-shadow:0 24px 80px rgba(0,0,0,0.6); touch-action:none; }
.mw-ba__lb-before, .mw-ba__lb-after { position:absolute; inset:0; background-size:cover; background-position:center; transition:background-image 0.3s; }
.mw-ba__lb-before { clip-path:inset(0 50% 0 0); z-index:2; }
.mw-ba__lb-after { z-index:1; }
.mw-ba__lb-handle { position:absolute; top:0; bottom:0; left:50%; transform:translateX(-50%); z-index:5; cursor:ew-resize; }
.mw-ba__lb-line { position:absolute; left:50%; transform:translateX(-50%); top:0; bottom:0; width:3px; background:linear-gradient(to bottom, rgba(255,255,255,0.2) 0%, rgba(255,255,255,0.9) 30%, rgba(255,255,255,0.9) 70%, rgba(255,255,255,0.2) 100%); box-shadow:0 0 12px rgba(255,255,255,0.5); }
.mw-ba__lb-grip { position:absolute; top:50%; left:50%; transform:translate(-50%,-50%); width:52px; height:52px; background:#fff; border-radius:50%; display:flex; align-items:center; justify-content:center; gap:0; box-shadow:0 4px 20px rgba(0,0,0,0.4), 0 0 0 4px rgba(255,255,255,0.2); transition:transform 0.15s, box-shadow 0.15s; }
.mw-ba__lb-grip:hover { transform:translate(-50%,-50%) scale(1.1); box-shadow:0 6px 28px rgba(0,0,0,0.5), 0 0 0 6px rgba(255,255,255,0.3); }
.mw-ba__lb-grip svg { width:14px; height:14px; stroke:#2d7a3a; }
.mw-ba__lb-grip svg:first-child { transform:translateX(2px); }
.mw-ba__lb-grip svg:last-child { transform:translateX(-2px); }
.mw-ba__lb-label { position:absolute; top:1.25rem; z-index:6; font-family:'DM Sans',sans-serif; font-size:0.75rem; font-weight:700; letter-spacing:0.14em; text-transform:uppercase; padding:6px 14px; border-radius:6px; backdrop-filter:blur(8px); }
.mw-ba__lb-label--before { left:1.25rem; background:rgba(0,0,0,0.5); color:rgba(255,255,255,0.75); }
.mw-ba__lb-label--after { right:1.25rem; background:rgba(45,122,58,0.8); color:#fff; }
.mw-ba__lb-meta { text-align:center; }
.mw-ba__lb-meta-service { font-family:'DM Sans',sans-serif; font-size:0.75rem; font-weight:700; letter-spacing:0.12em; text-transform:uppercase; color:#c9a84c; margin-bottom:4px; }
.mw-ba__lb-meta-label { font-family:'Playfair Display',serif; font-size:1.3rem; font-weight:600; color:#fff; margin-bottom:4px; }
.mw-ba__lb-meta-date { font-family:'DM Sans',sans-serif; font-size:0.8rem; color:rgba(255,255,255,0.4); }
.mw-ba__lb-nav { display:flex; gap:12px; }
.mw-ba__lb-nav-btn { width:46px; height:46px; background:rgba(255,255,255,0.08); border:1.5px solid rgba(255,255,255,0.15); border-radius:50%; display:flex; align-items:center; justify-content:center; cursor:pointer; transition:all 0.22s; }
.mw-ba__lb-nav-btn:hover { background:#2d7a3a; border-color:#2d7a3a; }
.mw-ba__lb-nav-btn svg { width:18px; height:18px; stroke:#fff; }
.mw-ba .mw-reveal { opacity:0; transform:translateY(24px); }
.mw-ba .mw-reveal.is-visible { animation:baReveal 0.65s var(--delay,0s) cubic-bezier(.22,.61,.36,1) forwards; }
@keyframes baReveal { to { opacity:1; transform:translateY(0); } }
</style>

<script>
(function () {
  'use strict';
  var sectionId = '<?= h($uid) ?>';
  var section = document.getElementById(sectionId);
  var grid    = document.getElementById(sectionId + '-grid');
  var loading = document.getElementById(sectionId + '-loading');
  if (!section || !grid) return;

  var autoFeed  = section.dataset.autoFeed === 'true';
  var maxPairs  = parseInt(section.dataset.maxPairs) || 6;
  var staticPairs = (function() { try { return JSON.parse(section.dataset.pairs || '[]'); } catch(e) { return []; } })();

  var DEMO_PAIRS = [
    { id:1, before_url:'https://images.unsplash.com/photo-1558904541-efa843a96f01?w=900', after_url:'https://images.unsplash.com/photo-1416879595882-3373a0480b5b?w=900', label:'Overgrown Garden — Kerrisdale', service:'Garden Restoration', date:'March 2026', category:'garden', crew:'Team A' },
    { id:2, before_url:'https://images.unsplash.com/photo-1504307651254-35680f356dfd?w=900', after_url:'https://images.unsplash.com/photo-1585320806297-9794b3e4eeae?w=900', label:'Lawn Neglect Recovery — Burnaby', service:'Lawn Restoration', date:'February 2026', category:'lawn', crew:'Team B' },
    { id:3, before_url:'https://images.unsplash.com/photo-1523348837708-15d4a09cfac2?w=900', after_url:'https://images.unsplash.com/photo-1508193638397-1c4234db14d8?w=900', label:'Strata Common Area — Richmond', service:'Strata Maintenance', date:'March 2026', category:'strata', crew:'Team A' },
    { id:4, before_url:'https://images.unsplash.com/photo-1416879595882-3373a0480b5b?w=900&flip=h', after_url:'https://images.unsplash.com/photo-1416879595882-3373a0480b5b?w=900', label:'Spring Cleanup — Kitsilano', service:'Seasonal Cleanup', date:'March 2026', category:'cleanup', crew:'Team C' },
    { id:5, before_url:'https://images.unsplash.com/photo-1558618666-fcd25c85cd64?w=900', after_url:'https://images.unsplash.com/photo-1585320806297-9794b3e4eeae?w=900', label:'Hedge & Border Refresh — West Van', service:'Garden Care', date:'February 2026', category:'garden', crew:'Team B' },
    { id:6, before_url:'https://images.unsplash.com/photo-1503945438517-f65904a52ce6?w=900', after_url:'https://images.unsplash.com/photo-1448630360428-65456885c650?w=900', label:'Property Grounds — Metrotown', service:'Strata Maintenance', date:'January 2026', category:'strata', crew:'Team A' }
  ];

  var allPairs = [], filteredPairs = [], activeFilter = 'all', lbIndex = 0;

  // Lightbox elements
  var lb       = document.getElementById(sectionId + '-lb');
  var backdrop = document.getElementById(sectionId + '-backdrop');
  var lbSlider = lb.querySelector('.mw-ba__lb-slider');
  var lbBefore = lb.querySelector('.mw-ba__lb-before');
  var lbAfter  = lb.querySelector('.mw-ba__lb-after');
  var lbHandle = lb.querySelector('.mw-ba__lb-handle');
  var lbMeta   = lb.querySelector('.mw-ba__lb-meta');

  async function loadPairs() {
    if (autoFeed) {
      try {
        var res = await fetch('/crm/api/before-after-pairs.php?limit=' + maxPairs, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        if (res.ok) { var data = await res.json(); allPairs = (data.pairs || []).length ? data.pairs : DEMO_PAIRS; }
        else { allPairs = staticPairs.length ? staticPairs : DEMO_PAIRS; }
      } catch(e) { allPairs = staticPairs.length ? staticPairs : DEMO_PAIRS; }
    } else {
      allPairs = staticPairs.length ? staticPairs : DEMO_PAIRS;
    }
    filteredPairs = allPairs;
    renderGrid();
  }

  var revealObs = new IntersectionObserver(function(entries) {
    entries.forEach(function(e) { if (e.isIntersecting) { e.target.classList.add('is-visible'); revealObs.unobserve(e.target); } });
  }, { threshold: 0.1 });

  function renderGrid() {
    if (loading) loading.remove();
    grid.innerHTML = '';
    var pairs = filteredPairs.slice(0, maxPairs);
    if (!pairs.length) { grid.innerHTML = '<div class="mw-ba__empty"><span style="display:block;font-size:2.5rem;margin-bottom:1rem;opacity:.4">🌿</span>No transformations yet — check back soon!</div>'; return; }
    pairs.forEach(function(pair, idx) {
      var card = document.createElement('div');
      card.className = 'mw-ba-card';
      card.style.setProperty('--delay', idx * 0.08 + 's');
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
  function openLightbox(idx) {
    lbIndex = idx; loadLbPair();
    lb.classList.add('is-open'); backdrop.classList.add('is-open');
    document.body.style.overflow = 'hidden';
    updateHandle(50);
  }
  function closeLightbox() {
    lb.classList.remove('is-open'); backdrop.classList.remove('is-open');
    document.body.style.overflow = '';
  }
  function loadLbPair() {
    var pair = filteredPairs[lbIndex]; if (!pair) return;
    lbBefore.style.backgroundImage = "url('" + pair.before_url + "')";
    lbAfter.style.backgroundImage  = "url('" + pair.after_url + "')";
    lbMeta.innerHTML = '<div class="mw-ba__lb-meta-service">' + escHtml(pair.service) + '</div><div class="mw-ba__lb-meta-label">' + escHtml(pair.label) + '</div><div class="mw-ba__lb-meta-date">' + escHtml(pair.date) + (pair.crew ? ' · ' + escHtml(pair.crew) : '') + '</div>';
    updateHandle(50);
  }
  function updateHandle(pct) {
    pct = Math.max(3, Math.min(97, pct));
    lbHandle.style.left = pct + '%';
    lbBefore.style.clipPath = 'inset(0 ' + (100 - pct) + '% 0 0)';
  }
  function navigate(dir) { lbIndex = ((lbIndex + dir) + filteredPairs.length) % filteredPairs.length; loadLbPair(); }

  lb.querySelector('.mw-ba__lb-close').addEventListener('click', closeLightbox);
  backdrop.addEventListener('click', closeLightbox);
  lb.querySelector('.mw-ba__lb-prev').addEventListener('click', function() { navigate(-1); });
  lb.querySelector('.mw-ba__lb-next').addEventListener('click', function() { navigate(1); });
  document.addEventListener('keydown', function(e) {
    if (!lb.classList.contains('is-open')) return;
    if (e.key === 'Escape') closeLightbox();
    if (e.key === 'ArrowLeft') navigate(-1);
    if (e.key === 'ArrowRight') navigate(1);
  });

  // Drag slider
  var isDragging = false;
  lbSlider.addEventListener('mousedown', function() { isDragging = true; });
  window.addEventListener('mousemove', function(e) { if (!isDragging) return; var rect = lbSlider.getBoundingClientRect(); updateHandle(((e.clientX - rect.left) / rect.width) * 100); });
  window.addEventListener('mouseup', function() { isDragging = false; });
  lbSlider.addEventListener('touchstart', function() { isDragging = true; }, { passive: true });
  lbSlider.addEventListener('touchmove', function(e) { if (!isDragging) return; var rect = lbSlider.getBoundingClientRect(); updateHandle(((e.touches[0].clientX - rect.left) / rect.width) * 100); }, { passive: true });
  lbSlider.addEventListener('touchend', function() { isDragging = false; });

  // Reveal header
  section.querySelectorAll('.mw-ba .mw-reveal').forEach(function(el) {
    new IntersectionObserver(function(entries) { if (entries[0].isIntersecting) entries[0].target.classList.add('is-visible'); }, { threshold: 0.1 }).observe(el);
  });

  function escHtml(str) { var d = document.createElement('div'); d.appendChild(document.createTextNode(str || '')); return d.innerHTML; }
  loadPairs();
})();
</script>
