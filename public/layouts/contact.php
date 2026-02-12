<?php
/**
 * Contact Page CMS Layout
 *
 * Content blocks rendered normally, with a contact sidebar below.
 * Each block renderer includes its own .container.
 *
 * Variables available: $page, $blocks (set by cms_renderPage)
 */
?>

<main role="main" class="cms-page cms-page-contact">
  <?php echo cms_renderSections($blocks, 0); ?>

  <section class="slp-section">
    <div class="container">
      <div class="contact-info-card" style="max-width: 500px; margin: 0 auto; background: var(--bg-light, #f8f9fa); padding: 30px; border-radius: 8px; border-left: 4px solid var(--mowology-green, #2D8659);">
        <h3 style="color: var(--mowology-dark, #1A5F4A); margin-bottom: 20px;">Contact Info</h3>
        <p>
          <strong>Phone:</strong><br>
          <a href="tel:<?php echo defined('SITE_PHONE_TEL') ? SITE_PHONE_TEL : ''; ?>" style="color: var(--mowology-green, #2D8659);"><?php echo defined('SITE_PHONE_DISPLAY') ? SITE_PHONE_DISPLAY : ''; ?></a>
        </p>
        <p>
          <strong>Email:</strong><br>
          <a href="mailto:<?php echo defined('SITE_EMAIL') ? SITE_EMAIL : ''; ?>" style="color: var(--mowology-green, #2D8659);"><?php echo defined('SITE_EMAIL') ? SITE_EMAIL : ''; ?></a>
        </p>
      </div>
    </div>
  </section>
</main>
