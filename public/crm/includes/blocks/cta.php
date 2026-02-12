<?php
/**
 * Call-to-Action Block Renderer — Public Site Design System
 *
 * Uses .cta-section / .cta-buttons from cta.css
 *
 * Config: {headline, subheadline, primary_text, primary_url, secondary_text, secondary_url, style}
 */

$headline = $config['headline'] ?? 'Ready to Get Started?';
$subheadline = $config['subheadline'] ?? '';
$primaryText = $config['primary_text'] ?? 'Get a Quote';
$primaryUrl = $config['primary_url'] ?? '/quote';
$secondaryText = $config['secondary_text'] ?? '';
$secondaryUrl = $config['secondary_url'] ?? '';
?>

<section class="cta-section">
  <div class="container">
    <h2><?php echo h($headline); ?></h2>
    <?php if ($subheadline): ?>
      <p><?php echo h($subheadline); ?></p>
    <?php endif; ?>
    <div class="cta-buttons">
      <a href="<?php echo h($primaryUrl); ?>" class="btn btn-primary-large"><?php echo h($primaryText); ?></a>
      <?php if ($secondaryText && $secondaryUrl): ?>
        <a href="<?php echo h($secondaryUrl); ?>" class="btn btn-secondary-large"><?php echo h($secondaryText); ?></a>
      <?php endif; ?>
    </div>
  </div>
</section>
