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
          <h4><?= h(SITE_NAME) ?></h4>
          <p>A higher degree of service in landscaping and grounds maintenance.</p>
          <p class="footer-tagline">Serving Vancouver, Burnaby & Richmond</p>
        </div>

        <div class="footer-col">
          <h4>Quick Links</h4>
          <ul>
            <li><a href="/">Home</a></li>
            <li><a href="/services">Services</a></li>
            <li><a href="/portfolio.php">Portfolio</a></li>
            <li><a href="/about.php">About Us</a></li>
            <li><a href="/contact.php">Contact</a></li>
          </ul>
        </div>

        <div class="footer-col">
          <h4>Services</h4>
          <ul>
            <li><a href="/services#property-management">Property Management</a></li>
            <li><a href="/services#residential">Residential Services</a></li>
            <li><a href="/services#maintenance">Weekly Maintenance</a></li>
            <li><a href="/services#seasonal">Seasonal Services</a></li>
          </ul>
        </div>

        <div class="footer-col">
          <h4>Contact</h4>
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

  <script src="/script.js"></script>
</body>
</html>
