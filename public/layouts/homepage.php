<?php
/**
 * Homepage CMS Layout
 *
 * Full-width hero followed by container sections
 * Variables available: $page, $blocks
 */

require_once dirname(__DIR__) . '/crm/includes/cms-renderer.php';
?>

<main role="main" class="cms-page cms-page-homepage">
  <?php echo cms_renderHero($blocks); ?>

  <div class="container">
    <?php echo cms_renderSections($blocks, 1); ?>
  </div>
</main>
