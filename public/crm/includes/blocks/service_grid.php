<?php
/**
 * Service Grid Block Renderer
 *
 * 3-column service overview cards with icon, description, and optional link.
 *
 * Config: {title, description, services[], cta_text, cta_url}
 */

$title       = $config['title'] ?? '';
$description = $config['description'] ?? '';
$services    = $config['services'] ?? [];
$ctaText     = $config['cta_text'] ?? '';
$ctaUrl      = $config['cta_url'] ?? '';

if (empty($services)) {
    return;
}
?>
<section class="section-pad bg-white">
  <div class="container">
    <?php if ($title): ?>
      <h2 class="section-title text-center"><?php echo h($title); ?></h2>
    <?php endif; ?>
    <?php if ($description): ?>
      <p class="section-intro text-center"><?php echo h($description); ?></p>
    <?php endif; ?>

    <div class="sg-grid">
      <?php foreach ($services as $svc): ?>
      <div class="sg-card">
        <?php if (!empty($svc['icon'])): ?>
        <div class="sg-icon" aria-hidden="true">
          <i data-feather="<?php echo h($svc['icon']); ?>"></i>
        </div>
        <?php endif; ?>
        <h3 class="sg-title"><?php echo h($svc['title'] ?? ''); ?></h3>
        <?php if (!empty($svc['description'])): ?>
          <p class="sg-desc"><?php echo h($svc['description']); ?></p>
        <?php endif; ?>
        <?php if (!empty($svc['url'])): ?>
          <a href="<?php echo h($svc['url']); ?>" class="sg-link">
            Learn more <i data-feather="arrow-right" style="width:14px;height:14px;"></i>
          </a>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>

    <?php if ($ctaText && $ctaUrl): ?>
    <div class="text-center mt-5">
      <a href="<?php echo h($ctaUrl); ?>" class="btn btn-primary-large"><?php echo h($ctaText); ?></a>
    </div>
    <?php endif; ?>
  </div>
</section>
