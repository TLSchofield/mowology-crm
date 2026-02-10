<?php
/**
 * Hero Block Renderer
 *
 * Config: {headline, subheadline, cta_text, cta_url, media_id, media_alt}
 */

$headline = $config['headline'] ?? 'Welcome';
$subheadline = $config['subheadline'] ?? '';
$ctaText = $config['cta_text'] ?? 'Learn More';
$ctaUrl = $config['cta_url'] ?? '#';
$mediaId = $config['media_id'] ?? null;
$mediaAlt = $config['media_alt'] ?? 'Hero image';
?>

<section class="hero-block py-5" role="region" aria-label="Hero section" style="background: linear-gradient(135deg, #2D8659 0%, #1A5F4A 100%); color: white;">
  <div class="container">
    <div class="row align-items-center">
      <div class="col-md-6 mb-4 mb-md-0">
        <h1 class="display-4 font-weight-bold mb-3"><?php echo h($headline); ?></h1>
        <?php if ($subheadline): ?>
          <p class="lead mb-4"><?php echo h($subheadline); ?></p>
        <?php endif; ?>

        <?php if ($ctaUrl): ?>
          <a href="<?php echo h($ctaUrl); ?>" class="btn btn-light btn-lg">
            <?php echo h($ctaText); ?>
          </a>
        <?php endif; ?>
      </div>

      <?php if ($mediaId): ?>
        <?php $media = cms_getMediaAssetById($mediaId); ?>
        <?php if ($media): ?>
          <div class="col-md-6">
            <img
              src="<?php echo h($media['file_path']); ?>"
              alt="<?php echo h($mediaAlt ?: $media['alt_text'] ?? ''); ?>"
              class="img-fluid rounded"
              loading="lazy"
              style="max-width: 100%; height: auto;"
            >
          </div>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
</section>
