<?php
/**
 * Enhanced Service Cards Block Renderer — Cinematic card grid with image reveal
 *
 * Config: {title, description, columns, services[]}
 * Each service: {title, description, url, media_id, icon, features (comma-separated)}
 */

$heading    = $config['title']       ?? 'What We Do Best';
$subheading = $config['description'] ?? 'Comprehensive grounds care for every type of Metro Vancouver property';
$services   = $config['services']    ?? [];
$uid        = 'mw-svc-' . ($block['id'] ?? uniqid());

// Icon SVG map
$iconSvgs = [
    'scissors' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="6" cy="6" r="3"/><circle cx="6" cy="18" r="3"/><line x1="20" y1="4" x2="8.12" y2="15.88"/><line x1="14.47" y1="14.48" x2="20" y2="20"/><line x1="8.12" y1="8.12" x2="12" y2="12"/></svg>',
    'building' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="1"/><path d="M9 22V12h6v10"/><path d="M3 9h18"/></svg>',
    'leaf'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M17 8C8 10 5.9 16.17 3.82 19.34a1 1 0 00.15 1.23 1 1 0 001.23.15C8.35 18.5 14.24 16 17 8z"/><path d="M3.82 19.34l4.24-4.24"/></svg>',
    'sun'      => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>',
    'home'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>',
];

// Defaults
if (empty($services)) {
    $services = [
        ['title' => 'Lawn Maintenance',   'description' => 'Weekly precision mowing, edging, and trimming. Consistent cuts that keep your property looking immaculate year-round.', 'icon' => 'scissors', 'features' => 'Weekly Scheduling, Edging & Trimming, Clipping Removal', 'url' => '/services#lawn'],
        ['title' => 'Property Management', 'description' => 'Dedicated strata landscaping with photo-verified reporting. Your tenants deserve beautiful, professional grounds.',   'icon' => 'building', 'features' => 'Photo Reports, Dedicated Manager, Emergency Response',   'url' => '/services#property-management'],
        ['title' => 'Garden Care',         'description' => 'Seasonal planting, pruning, weeding, and mulching. Transform your garden into the outdoor space you\'ve always wanted.', 'icon' => 'leaf', 'features' => 'Seasonal Planting, Pruning & Shaping, Mulch Installation', 'url' => '/services#garden'],
        ['title' => 'Seasonal Cleanups',   'description' => 'Spring and fall cleanups that protect your property through every season. Leaf removal, debris clearing, and preparation.', 'icon' => 'sun', 'features' => 'Leaf Removal, Debris Clearing, Bed Preparation', 'url' => '/services#seasonal'],
    ];
}
?>

<section class="mw-services" data-module="services-showcase" id="<?= h($uid) ?>">
  <div class="mw-services__inner">
    <div class="mw-services__header mw-reveal">
      <span class="mw-label">Our Services</span>
      <h2 class="mw-services__heading"><?= h($heading) ?></h2>
      <p class="mw-services__sub"><?= h($subheading) ?></p>
    </div>

    <div class="mw-services__grid">
      <?php foreach ($services as $i => $svc):
          $svcTitle = $svc['title'] ?? '';
          $svcDesc  = $svc['description'] ?? '';
          $svcUrl   = $svc['url'] ?? '#';
          $svcIcon  = $svc['icon'] ?? 'leaf';
          $features = $svc['features'] ?? '';
          if (is_string($features)) {
              $features = array_map('trim', explode(',', $features));
          }

          // Resolve image from media library
          $imgUrl = '/assets/img/services/default.jpg';
          if (!empty($svc['media_id'])) {
              $asset = cms_getMediaAssetById((int)$svc['media_id']);
              if ($asset && !empty($asset['file_path'])) {
                  $imgUrl = $asset['file_path'];
              }
          }
      ?>
      <article class="mw-svc-card mw-reveal" style="--delay:<?= $i * 0.12 ?>s">
        <div class="mw-svc-card__img-wrap">
          <div class="mw-svc-card__img" style="background-image:url('<?= h($imgUrl) ?>')"></div>
          <div class="mw-svc-card__img-overlay"></div>
          <span class="mw-svc-card__icon-wrap">
            <?= $iconSvgs[$svcIcon] ?? $iconSvgs['leaf'] ?>
          </span>
        </div>
        <div class="mw-svc-card__body">
          <h3 class="mw-svc-card__title"><?= h($svcTitle) ?></h3>
          <p class="mw-svc-card__desc"><?= h($svcDesc) ?></p>
          <?php if (!empty($features)): ?>
          <ul class="mw-svc-card__features">
            <?php foreach ($features as $f): ?>
            <li><?= h($f) ?></li>
            <?php endforeach; ?>
          </ul>
          <?php endif; ?>
          <a href="<?= h($svcUrl) ?>" class="mw-svc-card__link">
            Learn More
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
          </a>
        </div>
      </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<style>
.mw-services { background:#f8f6f1; padding:clamp(4rem, 8vw, 7rem) 0; }
.mw-services__inner { max-width:1280px; margin:0 auto; padding:0 max(1.5rem, calc((100vw - 1280px)/2 + 1.5rem)); }
.mw-services__header { text-align:center; max-width:600px; margin:0 auto 4rem; }
.mw-services__heading { font-family:'Playfair Display',serif; font-size:clamp(2rem, 3.5vw, 3rem); font-weight:700; color:#1a2e1e; margin:0 0 1rem; line-height:1.2; }
.mw-services__sub { font-family:'DM Sans',sans-serif; color:#5a6e60; font-size:1.05rem; line-height:1.65; margin:0; }
.mw-services__grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(280px, 1fr)); gap:1.5rem; }
.mw-svc-card { background:#fff; border-radius:12px; overflow:hidden; box-shadow:0 2px 16px rgba(26,46,30,0.08); transition:transform 0.35s, box-shadow 0.35s; }
.mw-svc-card:hover { transform:translateY(-6px); box-shadow:0 12px 40px rgba(26,46,30,0.15); }
.mw-svc-card__img-wrap { position:relative; height:200px; overflow:hidden; }
.mw-svc-card__img { position:absolute; inset:0; background-size:cover; background-position:center; background-color:#2d5a35; transition:transform 0.6s; }
.mw-svc-card:hover .mw-svc-card__img { transform:scale(1.06); }
.mw-svc-card__img-overlay { position:absolute; inset:0; background:linear-gradient(to bottom, transparent 40%, rgba(10,30,15,0.5)); }
.mw-svc-card__icon-wrap { position:absolute; bottom:-20px; left:1.5rem; width:40px; height:40px; background:#fff; border-radius:8px; display:flex; align-items:center; justify-content:center; box-shadow:0 4px 12px rgba(0,0,0,0.15); z-index:1; }
.mw-svc-card__icon-wrap svg { width:20px; height:20px; stroke:#2d7a3a; }
.mw-svc-card__body { padding:2rem 1.5rem 1.5rem; }
.mw-svc-card__title { font-family:'Playfair Display',serif; font-size:1.25rem; font-weight:700; color:#1a2e1e; margin:0 0 0.75rem; }
.mw-svc-card__desc { font-family:'DM Sans',sans-serif; font-size:0.9rem; color:#5a6e60; line-height:1.65; margin:0 0 1.25rem; }
.mw-svc-card__features { list-style:none; padding:0; margin:0 0 1.5rem; display:flex; flex-direction:column; gap:6px; }
.mw-svc-card__features li { font-family:'DM Sans',sans-serif; font-size:0.82rem; color:#3d5442; display:flex; align-items:center; gap:8px; }
.mw-svc-card__features li::before { content:''; width:6px; height:6px; background:#2d7a3a; border-radius:50%; flex-shrink:0; }
.mw-svc-card__link { display:inline-flex; align-items:center; gap:6px; font-family:'DM Sans',sans-serif; font-size:0.88rem; font-weight:700; color:#2d7a3a; text-decoration:none; border-bottom:1.5px solid #2d7a3a; padding-bottom:2px; transition:gap 0.2s, color 0.2s; }
.mw-svc-card__link:hover { gap:10px; color:#1a5227; }
.mw-svc-card__link svg { width:14px; height:14px; }
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
