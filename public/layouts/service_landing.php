<?php
/**
 * Service Landing Page CMS Layout
 *
 * Hero + proof sections in container
 */

require_once dirname(__DIR__) . '/crm/includes/cms-renderer.php';
?>

<main role="main" class="cms-page cms-page-service-landing">
  <?php echo cms_renderHero($blocks); ?>

  <section class="proof-sections">
    <div class="container">
      <?php echo cms_renderSections($blocks, 1); ?>
    </div>
  </section>
</main>
