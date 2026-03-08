<?php
/**
 * Enhanced Stats Banner Block Renderer — Animated scroll-triggered counters
 *
 * Config: {title, background, stats[]}
 * Each stat: {number, label, icon}
 */

$stats = $config['stats'] ?? [];
$uid = 'mw-stats-' . ($block['id'] ?? uniqid());

// Icon SVGs keyed by name
$statIcons = [
    'home'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>',
    'calendar' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>',
    'camera'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M23 19a2 2 0 01-2 2H3a2 2 0 01-2-2V8a2 2 0 012-2h4l2-3h6l2 3h4a2 2 0 012 2z"/><circle cx="12" cy="13" r="4"/></svg>',
    'heart'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M20.84 4.61a5.5 5.5 0 00-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 00-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 000-7.78z"/></svg>',
    'star'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>',
    'users'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>',
    'check'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><polyline points="20 6 9 17 4 12"/></svg>',
];

// Default stats if none configured
if (empty($stats)) {
    $stats = [
        ['number' => '500+', 'label' => 'Properties Served',   'icon' => 'home'],
        ['number' => '10+',  'label' => 'Years Experience',    'icon' => 'calendar'],
        ['number' => '100%', 'label' => 'Photo Verified Jobs', 'icon' => 'camera'],
        ['number' => '98%',  'label' => 'Client Retention',    'icon' => 'heart'],
    ];
}
?>

<section class="mw-stats" data-module="stats-counter" id="<?= h($uid) ?>">
  <div class="mw-stats__inner">
    <?php foreach ($stats as $i => $s):
        $numStr = $s['number'] ?? '0';
        // Extract numeric value and suffix
        preg_match('/(\d+)(.*)/', $numStr, $m);
        $numVal = $m[1] ?? 0;
        $suffix = $m[2] ?? '';
        $icon = $s['icon'] ?? 'home';
    ?>
    <div class="mw-stat mw-reveal" style="--delay:<?= $i * 0.1 ?>s">
      <div class="mw-stat__circle">
        <?= $statIcons[$icon] ?? $statIcons['home'] ?>
      </div>
      <div class="mw-stat__num">
        <span class="mw-stat__count" data-target="<?= (int)$numVal ?>"><?= (int)$numVal ?></span><?= h($suffix) ?>
      </div>
      <p class="mw-stat__label"><?= h($s['label'] ?? '') ?></p>
    </div>
    <?php endforeach; ?>
  </div>
</section>

<style>
.mw-stats {
  background: linear-gradient(135deg, #1a3d2b 0%, #0f2518 100%);
  padding: clamp(3rem, 6vw, 5rem) 0;
  position: relative;
  overflow: hidden;
}
.mw-stats::before {
  content: '';
  position: absolute;
  inset: 0;
  background-image: radial-gradient(circle at 20% 50%, rgba(45,122,58,0.15) 0%, transparent 60%),
                    radial-gradient(circle at 80% 50%, rgba(201,168,76,0.08) 0%, transparent 60%);
}
.mw-stats__inner {
  position: relative;
  max-width: 1280px;
  margin: 0 auto;
  padding: 0 max(1.5rem, calc((100vw - 1280px)/2 + 1.5rem));
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 2rem;
}
.mw-stat {
  text-align: center;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 1rem;
}
.mw-stat__circle {
  width: 64px; height: 64px;
  background: rgba(255,255,255,0.07);
  border: 1px solid rgba(255,255,255,0.12);
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: background 0.3s, border-color 0.3s;
}
.mw-stat:hover .mw-stat__circle {
  background: rgba(45,122,58,0.3);
  border-color: rgba(45,122,58,0.5);
}
.mw-stat__circle svg { width: 24px; height: 24px; stroke: #c9a84c; }
.mw-stat__num {
  font-family: 'Playfair Display', serif;
  font-size: clamp(2.2rem, 4vw, 3.2rem);
  font-weight: 700;
  color: #fff;
  line-height: 1;
  display: flex;
  align-items: baseline;
  gap: 2px;
}
.mw-stat__label {
  font-family: 'DM Sans', sans-serif;
  font-size: 0.82rem;
  letter-spacing: 0.1em;
  text-transform: uppercase;
  color: rgba(255,255,255,0.5);
  margin: 0;
}
</style>

<script>
(function() {
  var section = document.getElementById('<?= h($uid) ?>');
  if (!section) return;

  function animateCount(el, target, duration) {
    var start = 0;
    var step = function(timestamp) {
      if (!start) start = timestamp;
      var progress = Math.min((timestamp - start) / duration, 1);
      var ease = 1 - Math.pow(1 - progress, 3);
      el.textContent = Math.floor(ease * target);
      if (progress < 1) requestAnimationFrame(step);
    };
    requestAnimationFrame(step);
  }

  var observer = new IntersectionObserver(function(entries) {
    entries.forEach(function(entry) {
      if (entry.isIntersecting) {
        entry.target.querySelectorAll('.mw-stat__count').forEach(function(el) {
          animateCount(el, parseInt(el.dataset.target), 1800);
        });
        observer.unobserve(entry.target);
      }
    });
  }, { threshold: 0.3 });

  observer.observe(section);

  // Reveal animation
  var revealObs = new IntersectionObserver(function(entries) {
    entries.forEach(function(e) {
      if (e.isIntersecting) { e.target.classList.add('is-visible'); revealObs.unobserve(e.target); }
    });
  }, { threshold: 0.12 });
  section.querySelectorAll('.mw-reveal').forEach(function(el) { revealObs.observe(el); });
})();
</script>
