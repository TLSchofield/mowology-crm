<?php
/**
 * Feature Grid Block Renderer — Public Site Design System
 *
 * Uses .slp-section / .slp-benefits-grid / .slp-benefit classes from service-landing.css
 * Also supports "why-choose" layout using .features-grid / .feature from home.css
 *
 * Config: {title, description, layout (2/3/4), style (benefits/features), features: [{icon, title, description}...]}
 */

$title = $config['title'] ?? '';
$description = $config['description'] ?? '';
$features = $config['features'] ?? [];
$style = $config['style'] ?? 'benefits'; // benefits (card style) or features (icon centered)
?>

<section class="slp-section">
  <div class="container">
    <?php if ($title): ?>
      <h2 class="slp-heading"><?php echo h($title); ?></h2>
    <?php endif; ?>
    <?php if ($description): ?>
      <p class="slp-intro"><?php echo h($description); ?></p>
    <?php endif; ?>

    <?php if ($style === 'features'): ?>
      <!-- Icon-centered grid (like homepage "Why Choose" section) -->
      <div class="features-grid">
        <?php foreach ($features as $feature): ?>
          <div class="feature">
            <?php if (!empty($feature['icon'])): ?>
              <div class="feature-icon"><?php echo h($feature['icon']); ?></div>
            <?php endif; ?>
            <h3><?php echo h($feature['title'] ?? ''); ?></h3>
            <p><?php echo h($feature['description'] ?? ''); ?></p>
          </div>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <!-- Benefits card grid (like service landing pages) -->
      <div class="slp-benefits-grid">
        <?php foreach ($features as $feature): ?>
          <div class="slp-benefit">
            <strong><?php echo h($feature['title'] ?? ''); ?></strong>
            <p><?php echo h($feature['description'] ?? ''); ?></p>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</section>
