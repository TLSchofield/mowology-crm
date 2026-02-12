<?php
/**
 * Portfolio Page CMS Layout
 *
 * Full-width sections for gallery/filters.
 * Variables available: $page, $blocks (set by cms_renderPage)
 */
?>

<main role="main" class="cms-page cms-page-portfolio">
  <div class="container-fluid">
    <?php echo cms_renderSections($blocks, 0); ?>
  </div>
</main>
