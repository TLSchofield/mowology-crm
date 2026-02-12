<?php
/**
 * Default CMS Page Layout
 *
 * Simple layout with full-width container.
 * Variables available: $page, $blocks (set by cms_renderPage)
 * cms-renderer.php is already loaded by the front controller.
 */
?>

<main role="main" class="cms-page cms-page-default">
  <div class="container">
    <?php echo cms_renderSections($blocks, 0); ?>
  </div>
</main>
