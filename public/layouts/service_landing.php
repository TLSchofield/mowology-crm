<?php
/**
 * Service Landing Page CMS Layout
 *
 * Full-width hero + proof sections in container.
 * Variables available: $page, $blocks (set by cms_renderPage)
 */
?>

<main role="main" class="cms-page cms-page-service-landing">
  <?php echo cms_renderHero($blocks); ?>

  <section class="proof-sections">
    <div class="container">
      <?php echo cms_renderSections($blocks, 1); ?>
    </div>
  </section>
</main>
