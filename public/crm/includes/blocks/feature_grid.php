<?php
/**
 * Feature Grid Block Renderer
 *
 * Config: {title, description, layout (2/3/4), features: [{icon, title, description}...]}
 */

$title = $config['title'] ?? '';
$description = $config['description'] ?? '';
$layout = (int)($config['layout'] ?? 3);
$features = $config['features'] ?? [];

$colClass = match($layout) {
    2 => 'col-md-6',
    4 => 'col-md-3',
    default => 'col-md-4',
};
?>

<section class="feature-grid-block py-5" style="background-color: #f8f9fa;">
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

    <div class="row">
      <?php foreach ($features as $feature): ?>
      <div class="<?php echo $colClass; ?> mb-4">
        <div class="card h-100 border-0 shadow-sm">
          <div class="card-body text-center">
            <?php if (!empty($feature['icon'])): ?>
              <div class="mb-3" style="font-size: 2.5rem; color: var(--mw-green, #2D8659);">
                <i data-feather="<?php echo h($feature['icon']); ?>"></i>
              </div>
            <?php endif; ?>
            <h5 class="card-title"><?php echo h($feature['title'] ?? ''); ?></h5>
            <p class="card-text text-muted small"><?php echo h($feature['description'] ?? ''); ?></p>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
