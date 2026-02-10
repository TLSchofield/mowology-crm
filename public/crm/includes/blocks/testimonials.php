<?php
/**
 * Testimonials Block Renderer
 *
 * Config: {title, layout (carousel/grid), testimonials: [{name, role, content, image_id}...]}
 */

$title = $config['title'] ?? 'What Our Clients Say';
$layout = $config['layout'] ?? 'grid';
$testimonials = $config['testimonials'] ?? [];
?>

<section class="testimonials-block py-5">
  <div class="container">
    <?php if ($title): ?>
      <div class="row mb-5">
        <div class="col-lg-8 mx-auto text-center">
          <h2 class="mb-3"><?php echo h($title); ?></h2>
        </div>
      </div>
    <?php endif; ?>

    <?php if ($layout === 'carousel'): ?>
      <!-- Carousel Layout -->
      <div id="testimonialCarousel-<?php echo uniqid(); ?>" class="carousel slide" data-ride="carousel">
        <div class="carousel-inner">
          <?php foreach ($testimonials as $index => $testimonial): ?>
          <div class="carousel-item <?php echo $index === 0 ? 'active' : ''; ?>">
            <div class="row align-items-center">
              <?php if (!empty($testimonial['image_id'])): ?>
                <?php $media = cms_getMediaAssetById($testimonial['image_id']); ?>
                <?php if ($media): ?>
                <div class="col-md-4 text-center">
                  <img
                    src="<?php echo h($media['file_path']); ?>"
                    alt="<?php echo h($testimonial['name'] ?? ''); ?>"
                    class="rounded-circle"
                    style="width: 150px; height: 150px; object-fit: cover;"
                    loading="lazy"
                  >
                </div>
                <?php endif; ?>
              <?php endif; ?>
              <div class="col-md-8">
                <blockquote class="blockquote">
                  <p class="mb-0"><?php echo h($testimonial['content'] ?? ''); ?></p>
                  <footer class="blockquote-footer mt-3">
                    <strong><?php echo h($testimonial['name'] ?? ''); ?></strong>
                    <?php if (!empty($testimonial['role'])): ?>
                      <br><small><?php echo h($testimonial['role']); ?></small>
                    <?php endif; ?>
                  </footer>
                </blockquote>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php if (count($testimonials) > 1): ?>
        <a class="carousel-control-prev" href="#testimonialCarousel-<?php echo uniqid(); ?>" role="button" data-slide="prev">
          <span class="carousel-control-prev-icon" aria-hidden="true"></span>
          <span class="sr-only">Previous</span>
        </a>
        <a class="carousel-control-next" href="#testimonialCarousel-<?php echo uniqid(); ?>" role="button" data-slide="next">
          <span class="carousel-control-next-icon" aria-hidden="true"></span>
          <span class="sr-only">Next</span>
        </a>
        <?php endif; ?>
      </div>
    <?php else: ?>
      <!-- Grid Layout -->
      <div class="row">
        <?php foreach ($testimonials as $testimonial): ?>
        <div class="col-md-4 mb-4">
          <div class="card h-100 border-0 shadow-sm">
            <div class="card-body">
              <div class="mb-3">
                <i class="fas fa-star" style="color: #ffc107;"></i>
                <i class="fas fa-star" style="color: #ffc107;"></i>
                <i class="fas fa-star" style="color: #ffc107;"></i>
                <i class="fas fa-star" style="color: #ffc107;"></i>
                <i class="fas fa-star" style="color: #ffc107;"></i>
              </div>
              <p class="card-text"><?php echo h($testimonial['content'] ?? ''); ?></p>
              <div class="d-flex align-items-center mt-4">
                <?php if (!empty($testimonial['image_id'])): ?>
                  <?php $media = cms_getMediaAssetById($testimonial['image_id']); ?>
                  <?php if ($media): ?>
                  <img
                    src="<?php echo h($media['file_path']); ?>"
                    alt="<?php echo h($testimonial['name'] ?? ''); ?>"
                    class="rounded-circle mr-3"
                    style="width: 50px; height: 50px; object-fit: cover;"
                    loading="lazy"
                  >
                  <?php endif; ?>
                <?php endif; ?>
                <div>
                  <strong><?php echo h($testimonial['name'] ?? ''); ?></strong>
                  <?php if (!empty($testimonial['role'])): ?>
                    <br><small class="text-muted"><?php echo h($testimonial['role']); ?></small>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</section>
