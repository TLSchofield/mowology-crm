<?php
/**
 * Area Cards Block Renderer — Public Site Design System
 *
 * Uses .service-areas / .areas-grid / .area-card from home.css
 *
 * Config: {title, subtitle, areas: [{name, neighborhoods}...]}
 */

$title = $config['title'] ?? 'Service Areas';
$subtitle = $config['subtitle'] ?? '';
$areas = $config['areas'] ?? [];
?>

<section class="service-areas">
  <div class="container">
    <?php if ($title): ?>
      <h2 class="section-title"><?php echo h($title); ?></h2>
    <?php endif; ?>
    <?php if ($subtitle): ?>
      <p class="section-subtitle"><?php echo h($subtitle); ?></p>
    <?php endif; ?>

    <div class="areas-grid">
      <?php foreach ($areas as $area): ?>
        <div class="area-card">
          <h3><?php echo h($area['name'] ?? ''); ?></h3>
          <p><?php echo h($area['neighborhoods'] ?? ''); ?></p>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
