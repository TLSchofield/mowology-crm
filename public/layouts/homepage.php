<?php
/**
 * Homepage CMS Layout
 *
 * Full-width hero followed by content sections.
 * Each block renderer includes its own .container, so the layout
 * does NOT wrap sections in a container (avoids double nesting).
 *
 * Variables available: $page, $blocks (set by cms_renderPage)
 */
?>

<main role="main" class="cms-page cms-page-homepage">
  <?php echo cms_renderHero($blocks); ?>
  <?php echo cms_renderSections($blocks, 1); ?>
</main>
