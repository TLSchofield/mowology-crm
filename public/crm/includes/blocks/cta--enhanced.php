<?php
/**
 * Enhanced CTA Block Renderer — Green gradient banner with dual buttons
 *
 * Config: {headline, subheadline, primary_text, primary_url, secondary_text, secondary_url, style}
 */

$headline      = $config['headline']       ?? 'Ready to Elevate Your Property?';
$subheadline   = $config['subheadline']    ?? 'Get a free, no-obligation quote. We respond within 24 hours.';
$primaryText   = $config['primary_text']   ?? 'Request Free Quote';
$primaryUrl    = $config['primary_url']    ?? '/jobFlow/jobFlow-getQuote.php';
$secondaryText = $config['secondary_text'] ?? '778-846-9273';
$secondaryUrl  = $config['secondary_url']  ?? 'tel:7788469273';
$uid           = 'mw-cta-' . ($block['id'] ?? uniqid());
?>

<section class="mw-cta" data-module="cta-banner" id="<?= h($uid) ?>">
  <div class="mw-cta__inner">
    <div class="mw-cta__text mw-reveal">
      <h2 class="mw-cta__heading"><?= h($headline) ?></h2>
      <p class="mw-cta__sub"><?= h($subheadline) ?></p>
    </div>
    <div class="mw-cta__actions mw-reveal" style="--delay:.15s">
      <a href="<?= h($primaryUrl) ?>" class="mw-btn mw-btn--white">
        <?= h($primaryText) ?>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
      </a>
      <?php if ($secondaryText && $secondaryUrl): ?>
      <a href="<?= h($secondaryUrl) ?>" class="mw-btn mw-btn--outline-white"><?= h($secondaryText) ?></a>
      <?php endif; ?>
    </div>
  </div>
</section>

<style>
.mw-cta { background:#2d7a3a; padding:clamp(3.5rem, 6vw, 5rem) 0; position:relative; overflow:hidden; }
.mw-cta::before { content:''; position:absolute; inset:0; background-image:radial-gradient(circle at 10% 50%, rgba(255,255,255,0.07) 0%, transparent 50%), radial-gradient(circle at 90% 50%, rgba(0,0,0,0.12) 0%, transparent 50%); }
.mw-cta__inner { position:relative; max-width:1280px; margin:0 auto; padding:0 max(1.5rem, calc((100vw - 1280px)/2 + 1.5rem)); display:flex; align-items:center; justify-content:space-between; gap:2rem; flex-wrap:wrap; }
.mw-cta__heading { font-family:'Playfair Display',serif; font-size:clamp(1.8rem, 3vw, 2.5rem); font-weight:700; color:#fff; margin:0 0 0.75rem; line-height:1.2; }
.mw-cta__sub { font-family:'DM Sans',sans-serif; font-size:1rem; color:rgba(255,255,255,0.75); margin:0; }
.mw-cta__actions { display:flex; gap:1rem; align-items:center; flex-wrap:wrap; }
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
