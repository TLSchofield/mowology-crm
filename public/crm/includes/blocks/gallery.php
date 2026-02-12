<?php
/**
 * Gallery Block Renderer — Public Site Design System
 *
 * Uses CSS Grid for responsive gallery layout.
 * No Bootstrap classes — uses public site design tokens.
 *
 * Config: {title, description, columns (2/3/4), images: [{media_id, caption}...]}
 */

$title = $config['title'] ?? '';
$description = $config['description'] ?? '';
$columns = (int)($config['columns'] ?? 3);
$images = $config['images'] ?? [];
$galleryId = 'gallery-' . uniqid();
?>

<section class="slp-section slp-alt">
  <div class="container">
    <?php if ($title): ?>
      <h2 class="slp-heading"><?php echo h($title); ?></h2>
    <?php endif; ?>
    <?php if ($description): ?>
      <p class="slp-intro"><?php echo h($description); ?></p>
    <?php endif; ?>

    <div class="cms-gallery" id="<?php echo $galleryId; ?>" style="--gallery-cols: <?php echo $columns; ?>;">
      <?php foreach ($images as $image): ?>
        <?php if (!empty($image['media_id']) && function_exists('cms_getMediaAssetById')): ?>
          <?php $media = cms_getMediaAssetById($image['media_id']); ?>
          <?php if ($media): ?>
          <div class="cms-gallery-item">
            <img
              src="<?php echo h($media['file_path']); ?>"
              alt="<?php echo h($image['caption'] ?: ($media['alt_text'] ?? '')); ?>"
              loading="lazy"
            >
            <?php if (!empty($image['caption'])): ?>
              <div class="cms-gallery-caption"><?php echo h($image['caption']); ?></div>
            <?php endif; ?>
          </div>
          <?php endif; ?>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<style>
.cms-gallery {
  display: grid;
  grid-template-columns: repeat(var(--gallery-cols, 3), 1fr);
  gap: 16px;
}

@media (max-width: 768px) {
  .cms-gallery { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 480px) {
  .cms-gallery { grid-template-columns: 1fr; }
}

.cms-gallery-item {
  position: relative;
  overflow: hidden;
  border-radius: 8px;
}

.cms-gallery-item img {
  width: 100%;
  height: 250px;
  object-fit: cover;
  display: block;
  transition: transform 0.3s ease;
}

.cms-gallery-item:hover img {
  transform: scale(1.05);
}

.cms-gallery-caption {
  position: absolute;
  bottom: 0;
  left: 0;
  right: 0;
  background: rgba(0, 0, 0, 0.6);
  color: #fff;
  padding: 10px 14px;
  font-size: 0.9rem;
}
</style>
