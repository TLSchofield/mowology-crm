<?php
/**
 * Public Site Footer — Single Source of Truth
 * ────────────────────────────────────────────
 * Closes the page: footer content, </body>, </html>.
 *
 * Required: bootstrap.php must be loaded BEFORE this file.
 */
?>
  <footer class="footer">
    <div class="container">
      <div class="footer-content">
        <div class="footer-col">
          <h3><?= h(SITE_NAME) ?></h3>
          <p>A higher degree of service in landscaping and grounds maintenance.</p>
          <p class="footer-tagline">Serving Vancouver, Burnaby & Richmond</p>
        </div>

        <div class="footer-col">
          <h3>Quick Links</h3>
          <ul>
            <li><a href="/">Home</a></li>
            <li><a href="/services">Services</a></li>
            <li><a href="/portfolio">Portfolio</a></li>
            <li><a href="/about">About Us</a></li>
            <li><a href="/contact">Contact</a></li>
            <li><a href="/quote">Get a Free Quote</a></li>
            <li><a href="/blog">Tips &amp; Guides</a></li>
            <li><a href="/privacy">Privacy Policy</a></li>
          </ul>
        </div>

        <div class="footer-col">
          <h3>Services</h3>
          <ul>
            <?php
            // Sitewide links to every service landing page (file-based + CMS) — these
            // pages are the site's ranking targets and must be reachable from every page.
            require_once __DIR__ . '/service-links.php';
            foreach (mw_serviceLinks() as $__svc):
            ?>
            <li><a href="<?= h($__svc['url']) ?>"><?= h($__svc['title']) ?></a></li>
            <?php endforeach; unset($__svc); ?>
            <li><a href="/services">All Services</a></li>
          </ul>
        </div>

        <div class="footer-col">
          <h3>Contact</h3>
          <ul>
            <li><a href="tel:<?= h(SITE_PHONE_TEL) ?>"><?= h(SITE_PHONE_DISPLAY) ?></a></li>
            <li><a href="mailto:<?= h(SITE_EMAIL) ?>"><?= h(SITE_EMAIL) ?></a></li>
            <li>Mon - Fri: 8:00 - 16:00</li>
          </ul>
        </div>
      </div>

      <div class="footer-bottom">
        <p>&copy; <?= h(SITE_YEAR) ?> <?= h(SITE_NAME) ?>. All rights reserved.</p>
      </div>
    </div>
  </footer>

  <script src="/script.js" defer></script>
</body>
</html>
