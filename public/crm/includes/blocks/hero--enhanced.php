<?php
/**
 * Enhanced Hero Block Renderer — Parallax with word-by-word reveal
 *
 * Config: {headline, subheadline, cta_text, cta_url, secondary_cta_text,
 *          secondary_cta_url, media_id, media_alt, badge, trust_line}
 */

$headline       = $config['headline']          ?? 'A Higher Degree of Service';
$subheadline    = $config['subheadline']        ?? 'Professional landscaping and grounds maintenance for Metro Vancouver properties';
$ctaText        = $config['cta_text']           ?? 'Get Free Quote';
$ctaUrl         = $config['cta_url']            ?? '/jobFlow/jobFlow-getQuote.php';
$ctaSecondary   = $config['secondary_cta_text'] ?? 'View Services';
$ctaSecondaryUrl= $config['secondary_cta_url']  ?? '/services';
$badge          = $config['badge']              ?? "Vancouver's Trusted Landscapers";
$trustLine      = $config['trust_line']         ?? '';

// Resolve background image from media library
$bgImage = '/assets/img/hero/hero-lawn-care-1920x1080.jpg';
if (!empty($config['media_id'])) {
    $asset = cms_getMediaAssetById((int)$config['media_id']);
    if ($asset && !empty($asset['file_path'])) {
        $bgImage = $asset['file_path'];
    }
}

// Generate unique IDs for this block instance
$uid = 'mw-hero-' . ($block['id'] ?? uniqid());
?>

<section class="mw-hero" data-module="hero" id="<?= h($uid) ?>">
  <div class="mw-hero__bg" style="background-image:url('<?= h($bgImage) ?>')"></div>
  <div class="mw-hero__overlay"></div>
  <div class="mw-hero__particles" id="<?= h($uid) ?>-particles"></div>

  <div class="mw-hero__content">
    <?php if ($badge): ?>
    <span class="mw-hero__badge"><?= h($badge) ?></span>
    <?php endif; ?>
    <h1 class="mw-hero__headline">
      <?php foreach (explode(' ', $headline) as $i => $word): ?>
        <span class="mw-hero__word" style="animation-delay:<?= 0.08 * $i ?>s"><?= h($word) ?></span>
      <?php endforeach; ?>
    </h1>
    <p class="mw-hero__sub"><?= h($subheadline) ?></p>
    <div class="mw-hero__actions">
      <a href="<?= h($ctaUrl) ?>" class="mw-btn mw-btn--primary">
        <?= h($ctaText) ?>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
      </a>
      <?php if ($ctaSecondary): ?>
      <a href="<?= h($ctaSecondaryUrl) ?>" class="mw-btn mw-btn--ghost"><?= h($ctaSecondary) ?></a>
      <?php endif; ?>
    </div>
    <div class="mw-hero__trust">
      <div class="mw-hero__trust-item"><span class="mw-hero__trust-num">500+</span><span>Properties Served</span></div>
      <div class="mw-hero__trust-sep"></div>
      <div class="mw-hero__trust-item"><span class="mw-hero__trust-num">10+</span><span>Years Experience</span></div>
      <div class="mw-hero__trust-sep"></div>
      <div class="mw-hero__trust-item"><span class="mw-hero__trust-num">100%</span><span>Photo Verified</span></div>
    </div>
  </div>

  <div class="mw-hero__scroll">
    <div class="mw-hero__scroll-line"></div>
    <span>Scroll</span>
  </div>
</section>

<style>
.mw-hero {
  position: relative;
  height: 100svh;
  min-height: 600px;
  overflow: hidden;
  display: flex;
  align-items: center;
}
.mw-hero__bg {
  position: absolute;
  inset: -10%;
  background-size: cover;
  background-position: center;
  will-change: transform;
  transition: transform 0.1s linear;
}
.mw-hero__overlay {
  position: absolute;
  inset: 0;
  background: linear-gradient(135deg,
    rgba(10,30,15,0.78) 0%,
    rgba(15,45,25,0.60) 50%,
    rgba(8,25,12,0.45) 100%
  );
}
.mw-hero__particles {
  position: absolute;
  inset: 0;
  pointer-events: none;
}
.mw-hero__content {
  position: relative;
  z-index: 2;
  max-width: 800px;
  padding: 0 max(2rem, calc((100vw - 1280px)/2 + 2rem));
}
.mw-hero__badge {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  background: rgba(201,168,76,0.18);
  border: 1px solid rgba(201,168,76,0.4);
  color: #c9a84c;
  font-family: 'DM Sans', sans-serif;
  font-size: 0.75rem;
  font-weight: 600;
  letter-spacing: 0.12em;
  text-transform: uppercase;
  padding: 6px 14px;
  border-radius: 50px;
  margin-bottom: 1.5rem;
  opacity: 0;
  animation: mwFadeUp 0.6s 0.2s forwards;
}
.mw-hero__badge::before {
  content: '';
  width: 6px; height: 6px;
  background: #c9a84c;
  border-radius: 50%;
  box-shadow: 0 0 8px #c9a84c;
  animation: mwPulse 1.5s infinite;
}
.mw-hero__headline {
  font-family: 'Playfair Display', Georgia, serif;
  font-size: clamp(2.4rem, 5.5vw, 4.5rem);
  font-weight: 700;
  line-height: 1.12;
  color: #fff;
  margin: 0 0 1.5rem;
  overflow: hidden;
}
.mw-hero__word {
  display: inline-block;
  opacity: 0;
  transform: translateY(30px);
  animation: mwWordReveal 0.5s forwards;
  margin-right: 0.25em;
}
.mw-hero__sub {
  font-family: 'DM Sans', sans-serif;
  font-size: clamp(1rem, 1.8vw, 1.2rem);
  color: rgba(255,255,255,0.75);
  line-height: 1.65;
  max-width: 560px;
  margin: 0 0 2.5rem;
  opacity: 0;
  animation: mwFadeUp 0.6s 0.6s forwards;
}
.mw-hero__actions {
  display: flex;
  gap: 1rem;
  flex-wrap: wrap;
  margin-bottom: 3rem;
  opacity: 0;
  animation: mwFadeUp 0.6s 0.8s forwards;
}
.mw-hero__trust {
  display: flex;
  align-items: center;
  gap: 1.5rem;
  opacity: 0;
  animation: mwFadeUp 0.6s 1s forwards;
}
.mw-hero__trust-item {
  display: flex;
  flex-direction: column;
  gap: 2px;
}
.mw-hero__trust-num {
  font-family: 'Playfair Display', serif;
  font-size: 1.5rem;
  font-weight: 700;
  color: #c9a84c;
  line-height: 1;
}
.mw-hero__trust-item span:last-child {
  font-family: 'DM Sans', sans-serif;
  font-size: 0.72rem;
  color: rgba(255,255,255,0.55);
  text-transform: uppercase;
  letter-spacing: 0.08em;
}
.mw-hero__trust-sep {
  width: 1px;
  height: 36px;
  background: rgba(255,255,255,0.2);
}
.mw-hero__scroll {
  position: absolute;
  bottom: 2rem;
  right: max(2rem, calc((100vw - 1280px)/2 + 2rem));
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 8px;
  opacity: 0;
  animation: mwFadeUp 0.6s 1.2s forwards;
}
.mw-hero__scroll-line {
  width: 1px;
  height: 50px;
  background: linear-gradient(to bottom, transparent, rgba(255,255,255,0.5));
  animation: mwScrollLine 1.5s infinite;
}
.mw-hero__scroll span {
  font-family: 'DM Sans', sans-serif;
  font-size: 0.65rem;
  letter-spacing: 0.15em;
  text-transform: uppercase;
  color: rgba(255,255,255,0.5);
  writing-mode: vertical-rl;
}
.mw-hero__particle {
  position: absolute;
  bottom: 0;
  width: 2px;
  background: linear-gradient(to top, rgba(45,122,58,0), rgba(45,122,58,0.6));
  border-radius: 2px;
  animation: mwGrass linear infinite;
  transform-origin: bottom center;
}

@keyframes mwWordReveal { to { opacity: 1; transform: translateY(0); } }
@keyframes mwFadeUp { to { opacity: 1; transform: translateY(0); } }
@keyframes mwPulse { 0%,100% { opacity: 1; } 50% { opacity: 0.3; } }
@keyframes mwScrollLine {
  0% { transform: scaleY(0); transform-origin: top; }
  50% { transform: scaleY(1); transform-origin: top; }
  51% { transform-origin: bottom; }
  100% { transform: scaleY(0); transform-origin: bottom; }
}
@keyframes mwGrass {
  0% { transform: rotate(-8deg); }
  50% { transform: rotate(8deg); }
  100% { transform: rotate(-8deg); }
}
@media (max-width: 640px) {
  .mw-hero__trust { gap: 1rem; }
  .mw-hero__trust-sep { display: none; }
}
</style>

<script>
(function() {
  var section = document.getElementById('<?= h($uid) ?>');
  if (!section) return;

  // Parallax
  var bg = section.querySelector('.mw-hero__bg');
  if (bg) {
    window.addEventListener('scroll', function() {
      bg.style.transform = 'translateY(' + (window.scrollY * 0.4) + 'px)';
    }, { passive: true });
  }

  // Grass particles
  var container = document.getElementById('<?= h($uid) ?>-particles');
  if (container) {
    for (var i = 0; i < 40; i++) {
      var p = document.createElement('div');
      p.className = 'mw-hero__particle';
      var h = 20 + Math.random() * 80;
      p.style.cssText =
        'left:' + (Math.random() * 100) + '%;' +
        'height:' + h + 'px;' +
        'opacity:' + (0.1 + Math.random() * 0.3) + ';' +
        'animation-duration:' + (2 + Math.random() * 3) + 's;' +
        'animation-delay:' + (Math.random() * 3) + 's;';
      container.appendChild(p);
    }
  }
})();
</script>
