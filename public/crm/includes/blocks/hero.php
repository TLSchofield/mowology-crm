<?php
/**
 * Hero Block Renderer — Public Site Design System
 *
 * Uses .service-landing-hero / .slh-* classes from service-landing.css
 * OR .page-hero from page-hero.css for text-only heroes.
 *
 * Config: {headline, subheadline, cta_text, cta_url, media_id, media_alt, image}
 */

$headline = $config['headline'] ?? 'Welcome';
$subheadline = $config['subheadline'] ?? '';
$ctaText = $config['cta_text'] ?? 'Learn More';
$ctaUrl = $config['cta_url'] ?? '#';
$mediaId = $config['media_id'] ?? null;
$mediaAlt = $config['media_alt'] ?? '';
$imagePath = $config['image'] ?? ''; // direct path fallback
$trustLine = $config['trust_line'] ?? '';

// Resolve media asset if media_id provided
$resolvedImage = '';
$resolvedAlt = $mediaAlt;
if ($mediaId && function_exists('cms_getMediaAssetById')) {
    $media = cms_getMediaAssetById($mediaId);
    if ($media) {
        $resolvedImage = $media['file_path'] ?? '';
        if (!$resolvedAlt) {
            $resolvedAlt = $media['alt_text'] ?? '';
        }
    }
}
if (!$resolvedImage && $imagePath) {
    $resolvedImage = $imagePath;
}

// Choose layout: image hero (service-landing-hero) or text-only (page-hero)
if ($resolvedImage): ?>

  <!-- Service Landing Hero — with image -->
  <section class="service-landing-hero">
    <div class="container">
      <div class="slh-grid">
        <div class="slh-content">
          <h1><?php echo h($headline); ?></h1>
          <?php if ($subheadline): ?>
            <p class="slh-sub"><?php echo h($subheadline); ?></p>
          <?php endif; ?>
          <?php if ($ctaText && $ctaUrl): ?>
            <a href="<?php echo h($ctaUrl); ?>" class="btn btn-primary-large"><?php echo h($ctaText); ?></a>
          <?php endif; ?>
          <?php if ($trustLine): ?>
            <p class="slh-trust"><?php echo h($trustLine); ?></p>
          <?php endif; ?>
        </div>
        <div class="slh-image">
          <img src="<?php echo h($resolvedImage); ?>" alt="<?php echo h($resolvedAlt ?: $headline); ?>" loading="eager">
        </div>
      </div>
    </div>
  </section>

<?php else: ?>

  <!-- Page Hero — text only (gradient background) -->
  <section class="page-hero">
    <div class="container">
      <h1><?php echo h($headline); ?></h1>
      <?php if ($subheadline): ?>
        <p><?php echo h($subheadline); ?></p>
      <?php endif; ?>
      <?php if ($ctaText && $ctaUrl): ?>
        <a href="<?php echo h($ctaUrl); ?>" class="btn btn-primary-large"><?php echo h($ctaText); ?></a>
      <?php endif; ?>
    </div>
  </section>

<?php endif; ?>
