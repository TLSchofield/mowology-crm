<?php
/**
 * Custom HTML Block Renderer — Public Site Design System
 *
 * Config: {html_content}
 * Content is allow-listed by CmsHtmlSanitizer (2026-10-12) — no scripts, handlers or embeds.
 * Only admins should be able to add/edit custom blocks.
 */

// Allow-listed HTML since 2026-10-12 (Fable review): no scripts, handlers or embeds on the public site.
require_once APP_ROOT . '/Modules/CMS/Services/CmsHtmlSanitizer.php';
$htmlContent = CmsHtmlSanitizer::clean((string)($config['html_content'] ?? ''));
?>

<section class="slp-section">
  <div class="container">
    <?php echo $htmlContent; ?>
  </div>
</section>
