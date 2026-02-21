<?php
/**
 * Stats Banner Block Renderer
 *
 * Horizontal row of numbers + labels. High visual impact, good trust-builder.
 *
 * Config: {title, background (green|dark|light), stats[{number, label}]}
 */

$title      = $config['title'] ?? '';
$background = $config['background'] ?? 'green';
$stats      = $config['stats'] ?? [];

if (empty($stats)) {
    return;
}

$bgClass = match ($background) {
    'dark'  => 'stats-banner--dark',
    'light' => 'stats-banner--light',
    default => 'stats-banner--green',
};
?>
<section class="stats-banner <?php echo h($bgClass); ?>">
  <div class="container">
    <?php if ($title): ?>
      <h2 class="stats-banner-title"><?php echo h($title); ?></h2>
    <?php endif; ?>
    <div class="stats-banner-grid">
      <?php foreach ($stats as $stat): ?>
      <div class="stats-banner-item">
        <span class="stats-banner-number"><?php echo h($stat['number'] ?? ''); ?></span>
        <span class="stats-banner-label"><?php echo h($stat['label'] ?? ''); ?></span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
