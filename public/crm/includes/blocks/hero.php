<?php
/**
 * Hero Block Renderer — Public Site Design System
 *
 * Supports 3 hero styles:
 *   - image_bg:   Full-width background image with overlay (homepage style)
 *   - image_side: Side-by-side text + image (service landing style)
 *   - text_only:  Gradient background, no image (inner pages)
 *
 * Config: {headline, subheadline, cta_text, cta_url, secondary_cta_text, secondary_cta_url,
 *          media_id, media_alt, image, image_tablet, image_mobile, hero_style, trust_line}
 */

$headline = $config['headline'] ?? 'Welcome';
$subheadline = $config['subheadline'] ?? '';
$ctaText = $config['cta_text'] ?? 'Learn More';
$ctaUrl = $config['cta_url'] ?? '#';
$secondaryCtaText = $config['secondary_cta_text'] ?? '';
$secondaryCtaUrl = $config['secondary_cta_url'] ?? '';
$mediaId = $config['media_id'] ?? null;
$mediaAlt = $config['media_alt'] ?? '';
$imagePath = $config['image'] ?? '';
$imageTablet = $config['image_tablet'] ?? '';
$imageMobile = $config['image_mobile'] ?? '';
$heroStyle = $config['hero_style'] ?? 'auto'; // auto, image_bg, image_side, text_only
$trustLine = $config['trust_line'] ?? '';

// Resolve media asset if media_id provided
$resolvedImage = '';
$resolvedAlt = $mediaAlt;
if ($mediaId && function_exists('cms_getMediaAssetById')) {
    $media = cms_getMediaAssetById((int)$mediaId);
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

// Auto-detect style if set to 'auto'
if ($heroStyle === 'auto') {
    $heroStyle = $resolvedImage ? 'image_side' : 'text_only';
}

// Force text_only if no image available for image styles
if (in_array($heroStyle, ['image_bg', 'image_side']) && !$resolvedImage) {
    $heroStyle = 'text_only';
}

// ── Render based on style ──

if ($heroStyle === 'image_bg'): ?>

  <!-- Full-Width Background Image Hero (homepage style) -->
  <section class="hero hero--img">
    <picture class="hero-media" aria-hidden="true">
      <?php if ($imageMobile): ?>
        <source media="(max-width: 768px)" srcset="<?php echo h($imageMobile); ?>">
      <?php endif; ?>
      <?php if ($imageTablet): ?>
        <source media="(max-width: 1200px)" srcset="<?php echo h($imageTablet); ?>">
      <?php endif; ?>
      <img src="<?php echo h($resolvedImage); ?>" alt="<?php echo h($resolvedAlt ?: $headline); ?>" loading="eager" decoding="async">
    </picture>
    <div class="hero-overlay"></div>
    <div class="container">
      <div class="hero-content">
        <h1 class="hero-title"><?php echo h($headline); ?></h1>
        <?php if ($subheadline): ?>
          <p class="hero-subtitle"><?php echo h($subheadline); ?></p>
        <?php endif; ?>
        <div class="hero-buttons">
          <?php if ($ctaText && $ctaUrl): ?>
            <a href="<?php echo h($ctaUrl); ?>" class="btn btn-primary"><?php echo h($ctaText); ?></a>
          <?php endif; ?>
          <?php if ($secondaryCtaText && $secondaryCtaUrl): ?>
            <a href="<?php echo h($secondaryCtaUrl); ?>" class="btn btn-secondary"><?php echo h($secondaryCtaText); ?></a>
          <?php endif; ?>
        </div>
        <?php if ($trustLine): ?>
          <p class="hero-trust"><?php echo h($trustLine); ?></p>
        <?php endif; ?>
      </div>
    </div>
  </section>

<?php elseif ($heroStyle === 'image_side'): ?>

  <!-- Side-by-Side Hero (service landing style) -->
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
          <?php if ($secondaryCtaText && $secondaryCtaUrl): ?>
            <a href="<?php echo h($secondaryCtaUrl); ?>" class="btn btn-secondary-large ml-2"><?php echo h($secondaryCtaText); ?></a>
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
      <div class="hero-buttons">
        <?php if ($ctaText && $ctaUrl && $ctaUrl !== '#'): ?>
          <a href="<?php echo h($ctaUrl); ?>" class="btn btn-primary-large"><?php echo h($ctaText); ?></a>
        <?php endif; ?>
        <?php if ($secondaryCtaText && $secondaryCtaUrl): ?>
          <a href="<?php echo h($secondaryCtaUrl); ?>" class="btn btn-secondary-large"><?php echo h($secondaryCtaText); ?></a>
        <?php endif; ?>
      </div>
    </div>
  </section>

<?php endif; ?>
