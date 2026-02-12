<?php
/**
 * Contact Page CMS Layout
 *
 * Two-column: content on left, info sidebar on right.
 * Variables available: $page, $blocks (set by cms_renderPage)
 */
?>

<main role="main" class="cms-page cms-page-contact">
  <div class="container">
    <div class="row">
      <div class="col-md-8">
        <?php echo cms_renderSections($blocks, 0); ?>
      </div>
      <div class="col-md-4 contact-sidebar">
        <div class="card">
          <div class="card-body">
            <h5>Contact Info</h5>
            <p>
              <strong>Phone:</strong><br>
              <a href="tel:<?php echo defined('SITE_PHONE_TEL') ? SITE_PHONE_TEL : ''; ?>"><?php echo defined('SITE_PHONE_DISPLAY') ? SITE_PHONE_DISPLAY : ''; ?></a>
            </p>
            <p>
              <strong>Email:</strong><br>
              <a href="mailto:<?php echo defined('SITE_EMAIL') ? SITE_EMAIL : ''; ?>"><?php echo defined('SITE_EMAIL') ? SITE_EMAIL : ''; ?></a>
            </p>
          </div>
        </div>
      </div>
    </div>
  </div>
</main>
