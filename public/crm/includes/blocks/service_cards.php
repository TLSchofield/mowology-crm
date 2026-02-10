<?php
/**
 * Service Cards Block Renderer
 *
 * Config: {title, description, columns (2/3/4), services: [{icon, title, description, url}...]}
 */

$title = $config['title'] ?? 'Our Services';
$description = $config['description'] ?? '';
$columns = (int)($config['columns'] ?? 3);
$services = $config['services'] ?? [];

$colClass = match($columns) {
    2 => 'col-md-6',
    4 => 'col-md-3',
    default => 'col-md-4',
};
?>

<section class="service-cards-block py-5">
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
      <?php foreach ($services as $service): ?>
      <div class="<?php echo $colClass; ?> mb-4">
        <div class="card h-100 border-0 shadow-sm hover-shadow" style="transition: transform 0.3s ease, box-shadow 0.3s ease;">
          <div class="card-body">
            <?php if (!empty($service['icon'])): ?>
              <div class="mb-3" style="font-size: 2.5rem; color: var(--mw-green, #2D8659);">
                <i data-feather="<?php echo h($service['icon']); ?>"></i>
              </div>
            <?php endif; ?>
            <h5 class="card-title"><?php echo h($service['title'] ?? ''); ?></h5>
            <p class="card-text text-muted"><?php echo h($service['description'] ?? ''); ?></p>
            <?php if (!empty($service['url'])): ?>
              <a href="<?php echo h($service['url']); ?>" class="btn btn-sm btn-outline-primary">
                Learn More <i data-feather="arrow-right" style="width: 16px; height: 16px; margin-left: 4px;"></i>
              </a>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<style>
.service-cards-block .card:hover {
  transform: translateY(-5px);
  box-shadow: 0 1rem 3rem rgba(45, 134, 89, 0.15) !important;
}
</style>
