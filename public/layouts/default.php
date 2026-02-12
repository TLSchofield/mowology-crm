<?php
/**
 * Default CMS Page Layout
 *
 * Renders all blocks in order. Each block renderer includes its own
 * .container, so no wrapping container is needed here.
 *
 * Variables available: $page, $blocks (set by cms_renderPage)
 * cms-renderer.php is already loaded by the front controller.
 */
?>

<main role="main" class="cms-page cms-page-default">
  <?php echo cms_renderSections($blocks, 0); ?>
</main>
