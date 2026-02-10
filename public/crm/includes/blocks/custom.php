<?php
/**
 * Custom HTML Block Renderer
 *
 * Config: {html_content}
 * WARNING: Admin-only block. Content is output as-is without escaping.
 * Only admins should be able to add/edit custom blocks.
 */

$htmlContent = $config['html_content'] ?? '';
?>

<section class="custom-html-block py-5">
  <div class="container">
    <?php echo $htmlContent; ?>
  </div>
</section>
