<?php
/**
 * Enhanced Testimonials Block Renderer — Auto-scrolling carousel
 *
 * Config: {title, layout, testimonials[]}
 * Each testimonial: {name, quote, role, stars}
 */

$title = $config['title'] ?? 'What Our Clients Say';
$testimonials = $config['testimonials'] ?? [];
$uid = 'mw-testi-' . ($block['id'] ?? uniqid());

// Default testimonials
if (empty($testimonials)) {
    $testimonials = [
        ['name' => 'Linda N.',      'role' => 'Strata President',  'stars' => 5, 'quote' => "I've lived here for over 30 years and I've never seen our gardens look this good. Mowology transformed our complex."],
        ['name' => 'Colleen Jiang', 'role' => 'Home Owner',        'stars' => 5, 'quote' => 'Professional knowledge and excellent service. They made my garden beautiful again and I couldn\'t be happier.'],
        ['name' => 'Michael T.',    'role' => 'Property Manager',  'stars' => 5, 'quote' => 'Professional, reliable, and always on time. The photo reports give us peace of mind every single week.'],
        ['name' => 'Sarah K.',      'role' => 'Strata Council VP', 'stars' => 5, 'quote' => 'Switching to Mowology was the best decision our strata made. Night-and-day difference in quality and communication.'],
        ['name' => 'David R.',      'role' => 'Home Owner',        'stars' => 5, 'quote' => 'They handle everything from spring cleanup to fall leaf removal. Professional crew every single time.'],
    ];
}

$cardCount = count($testimonials);
// Duplicate for infinite loop feel
$displayTestimonials = array_merge($testimonials, $testimonials);
?>

<section class="mw-testimonials" data-module="testimonials" id="<?= h($uid) ?>">
  <div class="mw-testimonials__inner">
    <div class="mw-testimonials__header mw-reveal">
      <span class="mw-label">Client Stories</span>
      <h2 class="mw-section-heading"><?= h($title) ?></h2>
    </div>

    <div class="mw-testi-track-wrap">
      <div class="mw-testi-track" id="<?= h($uid) ?>-track">
        <?php foreach ($displayTestimonials as $t): ?>
        <article class="mw-testi-card">
          <div class="mw-testi-card__stars">
            <?php $stars = (int)($t['stars'] ?? 5); for ($s = 0; $s < $stars; $s++): ?>&#9733;<?php endfor; ?>
          </div>
          <blockquote class="mw-testi-card__quote">&ldquo;<?= h($t['quote'] ?? '') ?>&rdquo;</blockquote>
          <footer class="mw-testi-card__footer">
            <div class="mw-testi-card__avatar"><?= h(mb_substr($t['name'] ?? '?', 0, 1)) ?></div>
            <div>
              <div class="mw-testi-card__name"><?= h($t['name'] ?? '') ?></div>
              <div class="mw-testi-card__role"><?= h($t['role'] ?? '') ?></div>
            </div>
          </footer>
        </article>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="mw-testi-controls">
      <button class="mw-testi-btn" id="<?= h($uid) ?>-prev" aria-label="Previous">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5M12 5l-7 7 7 7"/></svg>
      </button>
      <div class="mw-testi-dots" id="<?= h($uid) ?>-dots"></div>
      <button class="mw-testi-btn" id="<?= h($uid) ?>-next" aria-label="Next">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
      </button>
    </div>
  </div>
</section>

<style>
.mw-testimonials { background:#fff; padding:clamp(4rem, 8vw, 7rem) 0; overflow:hidden; }
.mw-testimonials__inner { max-width:1280px; margin:0 auto; }
.mw-testimonials__header { text-align:center; margin-bottom:3rem; padding:0 max(1.5rem, calc((100vw - 1280px)/2 + 1.5rem)); }
.mw-testi-track-wrap { overflow:hidden; cursor:grab; }
.mw-testi-track-wrap:active { cursor:grabbing; }
.mw-testi-track { display:flex; gap:1.5rem; padding:1rem max(1.5rem, calc((100vw - 1280px)/2 + 1.5rem)); transition:transform 0.5s cubic-bezier(.22,.61,.36,1); will-change:transform; }
.mw-testi-card { flex:0 0 360px; background:#f8f6f1; border-radius:12px; padding:2rem; display:flex; flex-direction:column; gap:1.25rem; border:1px solid rgba(26,46,30,0.06); transition:transform 0.3s, box-shadow 0.3s; }
.mw-testi-card:hover { transform:translateY(-4px); box-shadow:0 12px 40px rgba(26,46,30,0.1); }
.mw-testi-card__stars { font-size:1rem; color:#c9a84c; letter-spacing:2px; }
.mw-testi-card__quote { font-family:'Playfair Display',serif; font-size:1.05rem; font-style:italic; color:#2a3d2e; line-height:1.7; margin:0; flex:1; }
.mw-testi-card__footer { display:flex; align-items:center; gap:12px; }
.mw-testi-card__avatar { width:40px; height:40px; background:linear-gradient(135deg, #2d7a3a, #1a5227); border-radius:50%; display:flex; align-items:center; justify-content:center; font-family:'Playfair Display',serif; font-size:1.1rem; color:#fff; font-weight:700; flex-shrink:0; }
.mw-testi-card__name { font-family:'DM Sans',sans-serif; font-size:0.9rem; font-weight:700; color:#1a2e1e; }
.mw-testi-card__role { font-family:'DM Sans',sans-serif; font-size:0.78rem; color:#7a9280; }
.mw-testi-controls { display:flex; align-items:center; justify-content:center; gap:1.5rem; margin-top:2.5rem; padding:0 1.5rem; }
.mw-testi-btn { width:44px; height:44px; background:#fff; border:1.5px solid #d4e0d8; border-radius:50%; display:flex; align-items:center; justify-content:center; cursor:pointer; transition:all 0.25s; }
.mw-testi-btn svg { width:18px; height:18px; stroke:#2d7a3a; }
.mw-testi-btn:hover { background:#2d7a3a; border-color:#2d7a3a; }
.mw-testi-btn:hover svg { stroke:#fff; }
.mw-testi-dots { display:flex; gap:8px; }
.mw-testi-dot { width:8px; height:8px; background:#c9d8cc; border-radius:50%; border:none; cursor:pointer; transition:all 0.25s; padding:0; }
.mw-testi-dot.is-active { background:#2d7a3a; transform:scale(1.3); }
@media (max-width:768px) { .mw-testi-card { flex:0 0 300px; } }
</style>

<script>
(function() {
  var uid = '<?= h($uid) ?>';
  var track = document.getElementById(uid + '-track');
  var dotsWrap = document.getElementById(uid + '-dots');
  if (!track) return;

  var cardCount = <?= $cardCount ?>;
  var cardWidth = 360 + 24;
  var current = 0;
  var isDragging = false, dragStart = 0, dragX = 0;

  // Responsive card width
  function updateCardW() {
    var card = track.querySelector('.mw-testi-card');
    if (card) cardWidth = card.offsetWidth + 24;
  }
  updateCardW();
  window.addEventListener('resize', updateCardW);

  // Create dots
  for (var i = 0; i < cardCount; i++) {
    var dot = document.createElement('button');
    dot.className = 'mw-testi-dot' + (i === 0 ? ' is-active' : '');
    dot.dataset.idx = i;
    dot.addEventListener('click', function() { goTo(parseInt(this.dataset.idx)); });
    dotsWrap.appendChild(dot);
  }

  function goTo(idx) {
    current = ((idx % cardCount) + cardCount) % cardCount;
    track.style.transform = 'translateX(-' + (current * cardWidth) + 'px)';
    dotsWrap.querySelectorAll('.mw-testi-dot').forEach(function(d, i) {
      d.classList.toggle('is-active', i === current);
    });
  }

  document.getElementById(uid + '-prev').addEventListener('click', function() { goTo(current - 1); });
  document.getElementById(uid + '-next').addEventListener('click', function() { goTo(current + 1); });

  // Drag
  track.addEventListener('pointerdown', function(e) { isDragging = true; dragStart = e.clientX; track.setPointerCapture(e.pointerId); track.style.transition = 'none'; });
  track.addEventListener('pointermove', function(e) { if (!isDragging) return; dragX = e.clientX - dragStart; track.style.transform = 'translateX(' + (-current * cardWidth + dragX) + 'px)'; });
  track.addEventListener('pointerup', function() {
    if (!isDragging) return;
    isDragging = false;
    track.style.transition = '';
    if (dragX < -60) goTo(current + 1);
    else if (dragX > 60) goTo(current - 1);
    else goTo(current);
    dragX = 0;
  });

  // Auto-advance
  var autoTimer = setInterval(function() { goTo(current + 1); }, 5000);
  track.addEventListener('pointerenter', function() { clearInterval(autoTimer); });
  track.addEventListener('pointerleave', function() { autoTimer = setInterval(function() { goTo(current + 1); }, 5000); });

  // Reveal
  var revealObs = new IntersectionObserver(function(entries) {
    entries.forEach(function(e) { if (e.isIntersecting) { e.target.classList.add('is-visible'); revealObs.unobserve(e.target); } });
  }, { threshold: 0.12 });
  document.getElementById(uid).querySelectorAll('.mw-reveal').forEach(function(el) { revealObs.observe(el); });
})();
</script>
