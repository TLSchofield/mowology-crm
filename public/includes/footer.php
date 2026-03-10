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
          <p>A higher degree of service in landscaping and grounds maintenance across Metro Vancouver.</p>
          <p class="footer-tagline">Vancouver &bull; Burnaby &bull; Richmond</p>
          <nav class="footer-social" aria-label="Social media">
            <a href="https://g.page/r/mowology/review" aria-label="Google Reviews" rel="noopener noreferrer" target="_blank">
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2C6.477 2 2 6.477 2 12s4.477 10 10 10 10-4.477 10-10S17.523 2 12 2zm0 18c-4.418 0-8-3.582-8-8s3.582-8 8-8 8 3.582 8 8-3.582 8-8 8zm3.5-8h-3v-3h-1v3h-3v1h3v3h1v-3h3v-1z"/></svg>
            </a>
            <a href="https://www.instagram.com/mowology" aria-label="Instagram" rel="noopener noreferrer" target="_blank">
              <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="2" y="2" width="20" height="20" rx="5" ry="5"/><path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"/><line x1="17.5" y1="6.5" x2="17.51" y2="6.5"/></svg>
            </a>
            <a href="https://www.facebook.com/mowology" aria-label="Facebook" rel="noopener noreferrer" target="_blank">
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/></svg>
            </a>
          </nav>
        </div>

        <div class="footer-col">
          <h4>Quick Links</h4>
          <ul>
            <li><a href="/">Home</a></li>
            <li><a href="/services">Services</a></li>
            <li><a href="/portfolio.php">Portfolio</a></li>
            <li><a href="/about.php">About Us</a></li>
            <li><a href="/contact.php">Contact</a></li>
            <li><a href="/jobFlow/jobFlow-getQuote.php">Get a Quote</a></li>
          </ul>
        </div>

        <div class="footer-col">
          <h4>Services</h4>
          <ul>
            <li><a href="/services">Property Management</a></li>
            <li><a href="/services">Residential Services</a></li>
            <li><a href="/services">Weekly Maintenance</a></li>
            <li><a href="/services">Seasonal Cleanups</a></li>
            <li><a href="/services">Garden Design</a></li>
          </ul>
        </div>

        <div class="footer-col">
          <h4>Contact</h4>
          <ul>
            <li><a href="tel:<?= h(SITE_PHONE_TEL) ?>"><?= h(SITE_PHONE_DISPLAY) ?></a></li>
            <li><a href="mailto:<?= h(SITE_EMAIL) ?>"><?= h(SITE_EMAIL) ?></a></li>
            <li>Mon&ndash;Fri: 8:00am &ndash; 4:00pm</li>
            <li>Vancouver, Burnaby &amp; Richmond</li>
          </ul>
        </div>
      </div>

      <div class="footer-bottom">
        <p>&copy; <?= h(SITE_YEAR) ?> <?= h(SITE_NAME) ?>. All rights reserved. Licensed &amp; Insured.</p>
      </div>
    </div>
  </footer>

  <script src="/script.js"></script>
</body>
</html>
