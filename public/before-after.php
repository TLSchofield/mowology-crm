<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

$pageTitle       = 'Before & After | Mowology Landscaping';
$pageDescription = 'See real landscaping transformations by Mowology crews across Metro Vancouver. Lawn care, hedge trimming, garden cleanups and strata maintenance.';
$activeNav       = 'portfolio';

// Fetch published pairs
$pairs = [];
try {
    $db   = getDB();
    $stmt = $db->query("
        SELECT p.id, p.label, p.service, p.category, p.crew,
               mb.file_path AS before_url, ma.file_path AS after_url,
               DATE_FORMAT(p.updated_at, '%M %Y') AS date
        FROM  ba_pairs p
        JOIN  media_assets mb ON mb.id = p.before_id
        JOIN  media_assets ma ON ma.id = p.after_id
        WHERE p.published = 1
        ORDER BY p.sort_order ASC, p.id DESC
        LIMIT 50
    ");
    $pairs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    // Continue with empty state
}

$pairsJson = json_encode(array_values($pairs), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);

require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/header.php';
?>

  <section class="ba-hero">
    <div class="container">
      <div class="ba-hero__label">Our Work</div>
      <h1 class="ba-hero__heading">The Mowology Difference</h1>
      <p class="ba-hero__sub">Real results from our crews across Metro Vancouver. Click any pair to compare before and after.</p>
    </div>
  </section>

  <section class="ba-section">
    <div class="container">

      <div class="ba-filters" role="group" aria-label="Filter by service type">
        <button class="ba-filter-btn is-active" data-filter="all">All Work</button>
        <button class="ba-filter-btn" data-filter="lawn">Lawn</button>
        <button class="ba-filter-btn" data-filter="garden">Garden</button>
        <button class="ba-filter-btn" data-filter="cleanup">Cleanup</button>
        <button class="ba-filter-btn" data-filter="strata">Strata</button>
      </div>

      <?php if (empty($pairs)): ?>
        <div class="ba-empty">
          <p>No transformations published yet — check back soon.</p>
        </div>
      <?php else: ?>
        <div class="ba-grid" id="baGrid" data-pairs="<?= htmlspecialchars($pairsJson, ENT_QUOTES, 'UTF-8') ?>">
          <?php foreach ($pairs as $i => $p): ?>
            <div class="ba-card"
                 data-index="<?= $i ?>"
                 data-category="<?= h($p['category'] ?? 'general') ?>"
                 role="button"
                 tabindex="0"
                 aria-label="View before and after: <?= h($p['label'] ?? 'Transformation') ?>">
              <div class="ba-card__after"  style="background-image:url('<?= h($p['after_url'])  ?>')"></div>
              <div class="ba-card__before" style="background-image:url('<?= h($p['before_url']) ?>')"></div>
              <div class="ba-card__line"></div>
              <div class="ba-card__handle" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                  <polyline points="15 18 9 12 15 6"/><polyline points="9 18 3 12 9 6"/>
                </svg>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16" style="margin-left:-4px">
                  <polyline points="9 18 15 12 9 6"/><polyline points="15 18 21 12 15 6"/>
                </svg>
              </div>
              <div class="ba-card__labels" aria-hidden="true">
                <span class="ba-card__lbl ba-card__lbl--before">Before</span>
                <span class="ba-card__lbl ba-card__lbl--after">After</span>
              </div>
              <div class="ba-card__overlay">
                <div class="ba-card__meta">
                  <?php if (!empty($p['service'])): ?>
                    <div class="ba-card__service"><?= h($p['service']) ?></div>
                  <?php endif; ?>
                  <?php if (!empty($p['label'])): ?>
                    <div class="ba-card__title"><?= h($p['label']) ?></div>
                  <?php endif; ?>
                  <?php if (!empty($p['date'])): ?>
                    <div class="ba-card__date"><?= h($p['date']) ?></div>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
        <p class="ba-empty-msg" id="baEmptyMsg" aria-live="polite">No results for this filter.</p>
      <?php endif; ?>

    </div>
  </section>

  <!-- Lightbox -->
  <div class="ba-lb-backdrop" id="baBackdrop"></div>
  <div class="ba-lb" id="baLb" role="dialog" aria-modal="true" aria-label="Before and after comparison">
    <button class="ba-lb__close" id="baClose" aria-label="Close">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
    </button>
    <div class="ba-lb__slider" id="baSlider">
      <div class="ba-lb__after"  id="baLbAfter"></div>
      <div class="ba-lb__before" id="baLbBefore"></div>
      <div class="ba-lb__line"   id="baLbLine"></div>
      <div class="ba-lb__grip"   id="baLbGrip">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><polyline points="15 18 9 12 15 6"/><polyline points="9 18 3 12 9 6"/></svg>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16" style="margin-left:-4px"><polyline points="9 18 15 12 9 6"/><polyline points="15 18 21 12 15 6"/></svg>
      </div>
      <span class="ba-lb__lbl ba-lb__lbl--before">Before</span>
      <span class="ba-lb__lbl ba-lb__lbl--after">After</span>
    </div>
    <div class="ba-lb__meta" id="baLbMeta"></div>
    <div class="ba-lb__nav">
      <button class="ba-lb__nav-btn" id="baPrev" aria-label="Previous pair">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5M12 5l-7 7 7 7"/></svg>
      </button>
      <button class="ba-lb__nav-btn" id="baNext" aria-label="Next pair">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
      </button>
    </div>
  </div>

<script>
(function () {
  var filterBtns  = document.querySelectorAll('.ba-filter-btn');
  var cards       = document.querySelectorAll('.ba-card');
  var emptyMsg    = document.getElementById('baEmptyMsg');
  var grid        = document.getElementById('baGrid');
  var backdrop    = document.getElementById('baBackdrop');
  var lb          = document.getElementById('baLb');
  var lbAfter     = document.getElementById('baLbAfter');
  var lbBefore    = document.getElementById('baLbBefore');
  var lbLine      = document.getElementById('baLbLine');
  var lbGrip      = document.getElementById('baLbGrip');
  var lbMeta      = document.getElementById('baLbMeta');
  var closeBtn    = document.getElementById('baClose');
  var prevBtn     = document.getElementById('baPrev');
  var nextBtn     = document.getElementById('baNext');
  var slider      = document.getElementById('baSlider');

  var allPairs    = [];
  var visibleIdx  = [];
  var curPos      = 0;
  var dragging    = false;

  if (grid) {
    try { allPairs = JSON.parse(grid.dataset.pairs || '[]'); } catch (e) {}
  }

  // ── Helpers ──────────────────────────────────────────────────────────
  function esc(str) {
    var d = document.createElement('div');
    d.textContent = str || '';
    return d.innerHTML;
  }

  function setSliderPct(pct) {
    lbBefore.style.clipPath = 'inset(0 ' + (100 - pct) + '% 0 0)';
    lbLine.style.left       = pct + '%';
    lbGrip.style.left       = pct + '%';
    lbGrip.style.transform  = 'translate(-50%, -50%)';
  }

  function rebuildVisible() {
    visibleIdx = [];
    cards.forEach(function (card) {
      if (!card.classList.contains('is-hidden')) {
        visibleIdx.push(parseInt(card.dataset.index, 10));
      }
    });
  }

  // ── Filter ───────────────────────────────────────────────────────────
  filterBtns.forEach(function (btn) {
    btn.addEventListener('click', function () {
      var filter = btn.dataset.filter;
      filterBtns.forEach(function (b) { b.classList.remove('is-active'); });
      btn.classList.add('is-active');

      var visible = 0;
      cards.forEach(function (card) {
        var show = filter === 'all' || (card.dataset.category || 'general') === filter;
        card.classList.toggle('is-hidden', !show);
        if (show) visible++;
      });

      if (emptyMsg) emptyMsg.style.display = visible === 0 ? 'block' : 'none';
    });
  });

  // ── Lightbox open/close ──────────────────────────────────────────────
  function openLb(pairIdx) {
    var pair = allPairs[pairIdx];
    if (!pair) return;

    lbAfter.style.backgroundImage  = 'url(' + pair.after_url  + ')';
    lbBefore.style.backgroundImage = 'url(' + pair.before_url + ')';
    setSliderPct(50);

    var meta = '';
    if (pair.service) meta += '<div class="ba-lb__meta-service">' + esc(pair.service) + '</div>';
    if (pair.label)   meta += '<div class="ba-lb__meta-title">'   + esc(pair.label)   + '</div>';
    lbMeta.innerHTML = meta;

    curPos = visibleIdx.indexOf(pairIdx);
    if (curPos === -1) curPos = 0;

    backdrop.classList.add('is-open');
    lb.classList.add('is-open');
    document.body.style.overflow = 'hidden';
    if (closeBtn) closeBtn.focus();
  }

  function closeLb() {
    backdrop.classList.remove('is-open');
    lb.classList.remove('is-open');
    document.body.style.overflow = '';
  }

  if (!grid || !lb) return;

  // ── Card click ───────────────────────────────────────────────────────
  cards.forEach(function (card) {
    card.addEventListener('click', function () {
      rebuildVisible();
      openLb(parseInt(card.dataset.index, 10));
    });
    card.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        rebuildVisible();
        openLb(parseInt(card.dataset.index, 10));
      }
    });
  });

  closeBtn.addEventListener('click', closeLb);
  backdrop.addEventListener('click', closeLb);

  prevBtn.addEventListener('click', function () {
    if (!visibleIdx.length) return;
    curPos = (curPos - 1 + visibleIdx.length) % visibleIdx.length;
    openLb(visibleIdx[curPos]);
  });

  nextBtn.addEventListener('click', function () {
    if (!visibleIdx.length) return;
    curPos = (curPos + 1) % visibleIdx.length;
    openLb(visibleIdx[curPos]);
  });

  document.addEventListener('keydown', function (e) {
    if (!lb.classList.contains('is-open')) return;
    if (e.key === 'Escape')     { closeLb(); return; }
    if (e.key === 'ArrowLeft')  { curPos = (curPos - 1 + visibleIdx.length) % visibleIdx.length; openLb(visibleIdx[curPos]); }
    if (e.key === 'ArrowRight') { curPos = (curPos + 1) % visibleIdx.length; openLb(visibleIdx[curPos]); }
  });

  // ── Slider drag ──────────────────────────────────────────────────────
  function getPct(e) {
    var r = slider.getBoundingClientRect();
    var x = (e.touches ? e.touches[0].clientX : e.clientX) - r.left;
    return Math.min(100, Math.max(0, (x / r.width) * 100));
  }

  slider.addEventListener('mousedown', function (e) {
    dragging = true;
    setSliderPct(getPct(e));
    e.preventDefault();
  });

  document.addEventListener('mousemove', function (e) {
    if (dragging) setSliderPct(getPct(e));
  });

  document.addEventListener('mouseup', function () { dragging = false; });

  slider.addEventListener('touchstart', function (e) {
    dragging = true;
    setSliderPct(getPct(e));
  }, { passive: true });

  slider.addEventListener('touchmove', function (e) {
    if (dragging) { setSliderPct(getPct(e)); e.preventDefault(); }
  }, { passive: false });

  slider.addEventListener('touchend', function () { dragging = false; });
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
