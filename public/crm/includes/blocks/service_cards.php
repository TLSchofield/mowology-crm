<?php
/**
 * Service Cards Block Renderer — Public Site Design System
 *
 * Uses .services-overview / .services-grid / .service-card from home.css
 *
 * Config: {title, description, services: [{icon, title, description, url}...]}
 */

$title = $config['title'] ?? 'Our Services';
$description = $config['description'] ?? '';
$services = $config['services'] ?? [];
?>

<section class="services-overview">
  <div class="container">
    <?php if ($title): ?>
      <h2 class="section-title"><?php echo h($title); ?></h2>
    <?php endif; ?>
    <?php if ($description): ?>
      <p class="section-subtitle"><?php echo h($description); ?></p>
    <?php endif; ?>

    <div class="services-grid">
      <?php foreach ($services as $service): ?>
        <div class="service-card">
          <?php if (!empty($service['icon'])): ?>
            <span class="service-icon"><?php echo h($service['icon']); ?></span>
          <?php endif; ?>
          <h3><?php echo h($service['title'] ?? ''); ?></h3>
          <p><?php echo h($service['description'] ?? ''); ?></p>
          <?php if (!empty($service['url'])): ?>
            <a href="<?php echo h($service['url']); ?>" class="btn">Learn More →</a>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
