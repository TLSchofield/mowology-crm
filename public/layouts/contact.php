<?php
/**
 * Contact Page CMS Layout
 *
 * Two-column: form on left, info on right
 */

require_once dirname(__DIR__) . '/crm/includes/cms-renderer.php';
?>

<main role="main" class="cms-page cms-page-contact">
  <div class="container">
    <div class="row">
      <div class="col-md-8">
        <?php echo cms_renderSections($blocks); ?>
      </div>
      <div class="col-md-4 contact-sidebar">
        <div class="card">
          <div class="card-body">
            <h5>Contact Info</h5>
            <p>
              <strong>Phone:</strong><br>
              <a href="tel:<?php echo SITE_PHONE_1 ?? ''; ?>"><?php echo SITE_PHONE_1 ?? ''; ?></a>
            </p>
            <p>
              <strong>Email:</strong><br>
              <a href="mailto:<?php echo SITE_EMAIL ?? ''; ?>"><?php echo SITE_EMAIL ?? ''; ?></a>
            </p>
          </div>
        </div>
      </div>
    </div>
  </div>
</main>
