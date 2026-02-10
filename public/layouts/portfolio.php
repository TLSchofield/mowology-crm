<?php
/**
 * Portfolio Page CMS Layout
 *
 * Full-width sections for gallery/filters
 */

require_once dirname(__DIR__) . '/crm/includes/cms-renderer.php';
?>

<main role="main" class="cms-page cms-page-portfolio">
  <div class="container-fluid">
    <?php echo cms_renderSections($blocks); ?>
  </div>
</main>
