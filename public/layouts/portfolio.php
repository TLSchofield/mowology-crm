<?php
/**
 * Portfolio Page CMS Layout
 *
 * Full-width sections for gallery/portfolio content.
 * Each block renderer includes its own .container.
 *
 * Variables available: $page, $blocks (set by cms_renderPage)
 */
?>

<main role="main" class="cms-page cms-page-portfolio">
  <?php echo cms_renderSections($blocks, 0); ?>
</main>
