<?php
/**
 * Enhanced Area Cards Block Renderer — Animated region grid
 *
 * Config: {title, subtitle, areas[]}
 * Each area: {name, neighborhoods (comma-separated string), icon}
 */

$title    = $config['title']    ?? 'Metro Vancouver Service Areas';
$subtitle = $config['subtitle'] ?? 'Proudly serving communities across the Lower Mainland';
$areas    = $config['areas']    ?? [];
$uid      = 'mw-areas-' . ($block['id'] ?? uniqid());

// Default areas if none configured
if (empty($areas)) {
    $areas = [
        ['name' => 'Vancouver',   'neighborhoods' => 'Kitsilano, Kerrisdale, Point Grey, Mount Pleasant, West End, Dunbar', 'icon' => '🌆'],
        ['name' => 'Burnaby',     'neighborhoods' => 'Metrotown, Brentwood, Lougheed, Deer Lake, Capitol Hill, Heights',    'icon' => '🏘️'],
        ['name' => 'Richmond',    'neighborhoods' => 'Steveston, City Centre, Brighouse, Terra Nova, Hamilton, Sea Island',  'icon' => '🌿'],
        ['name' => 'North Shore', 'neighborhoods' => 'North Van, West Van, Deep Cove, Lynn Valley, Edgemont, Lonsdale',     'icon' => '⛰️'],
    ];
}
?>

<section class="mw-areas" data-module="service-areas" id="<?= h($uid) ?>">
  <div class="mw-areas__inner">
    <div class="mw-areas__header mw-reveal">
      <span class="mw-label" style="color:#c9a84c">Where We Work</span>
      <h2 class="mw-section-heading" style="color:#fff"><?= h($title) ?></h2>
      <p class="mw-areas__sub"><?= h($subtitle) ?></p>
    </div>
    <div class="mw-areas__grid">
      <?php foreach ($areas as $i => $area):
          $name = $area['name'] ?? '';
          $icon = $area['icon'] ?? '🌿';
          // neighborhoods can be a string or array
          $hoods = $area['neighborhoods'] ?? '';
          if (is_string($hoods)) {
              $hoods = array_map('trim', explode(',', $hoods));
          }
      ?>
      <div class="mw-area-card mw-reveal" style="--delay:<?= $i * 0.12 ?>s">
        <div class="mw-area-card__icon"><?= $icon ?></div>
        <h3 class="mw-area-card__name"><?= h($name) ?></h3>
        <ul class="mw-area-card__hoods">
          <?php foreach ($hoods as $n): ?>
          <li><?= h($n) ?></li>
          <?php endforeach; ?>
        </ul>
        <a href="/contact" class="mw-area-card__cta">Get Quote &rarr;</a>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<style>
.mw-areas { background:linear-gradient(160deg, #0f2518 0%, #1a3d2b 60%, #0a1f12 100%); padding:clamp(4rem, 8vw, 7rem) 0; position:relative; overflow:hidden; }
.mw-areas::after { content:''; position:absolute; bottom:-80px; right:-80px; width:400px; height:400px; background:radial-gradient(circle, rgba(201,168,76,0.06), transparent 70%); pointer-events:none; }
.mw-areas__inner { max-width:1280px; margin:0 auto; padding:0 max(1.5rem, calc((100vw - 1280px)/2 + 1.5rem)); }
.mw-areas__header { text-align:center; margin-bottom:3rem; }
.mw-areas .mw-label::before { background:#c9a84c; }
.mw-areas__sub { font-family:'DM Sans',sans-serif; color:rgba(255,255,255,0.55); font-size:1rem; margin:0.75rem 0 0; }
.mw-areas__grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(260px, 1fr)); gap:1.25rem; }
.mw-area-card { background:rgba(255,255,255,0.05); border:1px solid rgba(255,255,255,0.1); border-radius:12px; padding:2rem; transition:background 0.3s, border-color 0.3s, transform 0.3s; }
.mw-area-card:hover { background:rgba(45,122,58,0.15); border-color:rgba(45,122,58,0.4); transform:translateY(-4px); }
.mw-area-card__icon { font-size:2rem; margin-bottom:0.75rem; display:block; }
.mw-area-card__name { font-family:'Playfair Display',serif; font-size:1.4rem; font-weight:700; color:#fff; margin:0 0 1rem; }
.mw-area-card__hoods { list-style:none; padding:0; margin:0 0 1.5rem; display:flex; flex-wrap:wrap; gap:6px; }
.mw-area-card__hoods li { font-family:'DM Sans',sans-serif; font-size:0.78rem; color:rgba(255,255,255,0.6); background:rgba(255,255,255,0.08); padding:3px 10px; border-radius:50px; }
.mw-area-card__cta { font-family:'DM Sans',sans-serif; font-size:0.85rem; font-weight:700; color:#c9a84c; text-decoration:none; transition:gap 0.2s, letter-spacing 0.2s; display:inline-block; }
.mw-area-card__cta:hover { letter-spacing:0.03em; }
</style>

<script>
(function() {
  var section = document.getElementById('<?= h($uid) ?>');
  if (!section) return;
  var obs = new IntersectionObserver(function(entries) {
    entries.forEach(function(e) { if (e.isIntersecting) { e.target.classList.add('is-visible'); obs.unobserve(e.target); } });
  }, { threshold: 0.12 });
  section.querySelectorAll('.mw-reveal').forEach(function(el) { obs.observe(el); });
})();
</script>
