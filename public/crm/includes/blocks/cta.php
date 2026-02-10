<?php
/**
 * Call-to-Action Block Renderer
 *
 * Config: {headline, subheadline, primary_text, primary_url, secondary_text, secondary_url, style (light/dark/gradient)}
 */

$headline = $config['headline'] ?? 'Ready to Get Started?';
$subheadline = $config['subheadline'] ?? '';
$primaryText = $config['primary_text'] ?? 'Get a Quote';
$primaryUrl = $config['primary_url'] ?? '/quote';
$secondaryText = $config['secondary_text'] ?? '';
$secondaryUrl = $config['secondary_url'] ?? '#';
$style = $config['style'] ?? 'gradient';

$bgStyle = match($style) {
    'light' => 'background-color: #f8f9fa;',
    'dark' => 'background: linear-gradient(135deg, #1A5F4A 0%, #0D3B2E 100%); color: white;',
    default => 'background: linear-gradient(135deg, #2D8659 0%, #1A5F4A 100%); color: white;',
};
?>

<section class="cta-block py-5" style="<?php echo $bgStyle; ?>">
  <div class="container">
    <div class="row">
      <div class="col-lg-8 mx-auto text-center">
        <h2 class="mb-3"><?php echo h($headline); ?></h2>
        <?php if ($subheadline): ?>
          <p class="lead mb-4"><?php echo h($subheadline); ?></p>
        <?php endif; ?>

        <div class="mt-4">
          <a href="<?php echo h($primaryUrl); ?>" class="btn btn-<?php echo $style === 'light' ? 'primary' : 'light'; ?> btn-lg mr-2">
            <?php echo h($primaryText); ?>
          </a>
          <?php if ($secondaryText && $secondaryUrl): ?>
            <a href="<?php echo h($secondaryUrl); ?>" class="btn btn-<?php echo $style === 'light' ? 'outline-primary' : 'outline-light'; ?> btn-lg">
              <?php echo h($secondaryText); ?>
            </a>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</section>
