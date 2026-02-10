<?php
/**
 * Default CMS Page Layout
 *
 * Simple layout with full-width container
 * Variables available: $page, $blocks
 */

require_once dirname(__DIR__) . '/crm/includes/cms-renderer.php';
?>

<main role="main" class="cms-page cms-page-default">
  <div class="container">
    <?php echo cms_renderSections($blocks); ?>
  </div>
</main>
