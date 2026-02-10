<?php
/**
 * Gallery Block Renderer
 *
 * Config: {title, description, layout (grid/carousel), columns (2/3/4), images: [{media_id, caption}...]}
 */

$title = $config['title'] ?? '';
$description = $config['description'] ?? '';
$layout = $config['layout'] ?? 'grid';
$columns = (int)($config['columns'] ?? 3);
$images = $config['images'] ?? [];
$uniqueId = uniqid('gallery-');

$colClass = match($columns) {
    2 => 'col-md-6',
    4 => 'col-md-3',
    default => 'col-md-4',
};
?>

<section class="gallery-block py-5" style="background-color: #f8f9fa;">
  <div class="container">
    <?php if ($title): ?>
      <div class="row mb-5">
        <div class="col-lg-8 mx-auto text-center">
          <h2 class="mb-3"><?php echo h($title); ?></h2>
          <?php if ($description): ?>
            <p class="lead text-muted"><?php echo h($description); ?></p>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>

    <?php if ($layout === 'carousel'): ?>
      <!-- Carousel Layout -->
      <div id="<?php echo $uniqueId; ?>" class="carousel slide" data-ride="carousel">
        <div class="carousel-inner">
          <?php foreach ($images as $index => $image): ?>
            <?php if (!empty($image['media_id'])): ?>
              <?php $media = cms_getMediaAssetById($image['media_id']); ?>
              <?php if ($media): ?>
              <div class="carousel-item <?php echo $index === 0 ? 'active' : ''; ?>">
                <img
                  src="<?php echo h($media['file_path']); ?>"
                  alt="<?php echo h($image['caption'] ?: $media['alt_text'] ?? ''); ?>"
                  class="d-block w-100"
                  style="max-height: 500px; object-fit: cover;"
                  loading="lazy"
                >
                <?php if (!empty($image['caption'])): ?>
                <div class="carousel-caption d-none d-md-block">
                  <p><?php echo h($image['caption']); ?></p>
                </div>
                <?php endif; ?>
              </div>
              <?php endif; ?>
            <?php endif; ?>
          <?php endforeach; ?>
        </div>
        <?php if (count($images) > 1): ?>
        <a class="carousel-control-prev" href="#<?php echo $uniqueId; ?>" role="button" data-slide="prev">
          <span class="carousel-control-prev-icon" aria-hidden="true"></span>
          <span class="sr-only">Previous</span>
        </a>
        <a class="carousel-control-next" href="#<?php echo $uniqueId; ?>" role="button" data-slide="next">
          <span class="carousel-control-next-icon" aria-hidden="true"></span>
          <span class="sr-only">Next</span>
        </a>
        <?php endif; ?>
      </div>
    <?php else: ?>
      <!-- Grid Layout -->
      <div class="row">
        <?php foreach ($images as $image): ?>
          <?php if (!empty($image['media_id'])): ?>
            <?php $media = cms_getMediaAssetById($image['media_id']); ?>
            <?php if ($media): ?>
            <div class="<?php echo $colClass; ?> mb-4">
              <div class="gallery-item" style="position: relative; overflow: hidden; border-radius: 8px;">
                <img
                  src="<?php echo h($media['file_path']); ?>"
                  alt="<?php echo h($image['caption'] ?: $media['alt_text'] ?? ''); ?>"
                  class="img-fluid"
                  style="width: 100%; height: 250px; object-fit: cover; display: block;"
                  loading="lazy"
                >
                <?php if (!empty($image['caption'])): ?>
                <div style="position: absolute; bottom: 0; left: 0; right: 0; background: rgba(0,0,0,0.6); color: white; padding: 12px; font-size: 0.9rem;">
                  <?php echo h($image['caption']); ?>
                </div>
                <?php endif; ?>
              </div>
            </div>
            <?php endif; ?>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</section>
