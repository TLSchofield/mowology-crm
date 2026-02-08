<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

$pageTitle = 'Contact Us | Mowology Landscaping';
$pageDescription = 'Contact Mowology for professional landscaping services. Get a free quote for your property in Vancouver, Burnaby, or Richmond.';
$activeNav = 'contact';

require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/header.php';
?>

  <section class="page-hero">
    <div class="container">
      <h1>Get Your Free Quote</h1>
      <p>Let's discuss how we can enhance your property</p>
    </div>
  </section>

  <section class="contact-section">
    <div class="container">
      <div class="contact-grid">
        <!-- Quote Form (Left) - jobFlow form -->
        <div class="contact-form-wrapper">
          <?php
            // Set up output buffering to capture jobFlow form without full page markup
            ob_start();

            // Store current error reporting
            $oldErrorReporting = error_reporting(E_ALL);
            $oldDisplayErrors = ini_get('display_errors');
            ini_set('display_errors', '0');

            // Include jobFlow - it will output the full page
            include __DIR__ . '/jobFlow/jobFlow-getQuote.php';

            // Restore error reporting
            error_reporting($oldErrorReporting);
            ini_set('display_errors', $oldDisplayErrors);

            $output = ob_get_clean();

            // Extract just the form content (from <form method="POST" action=""> to </form>)
            // This removes the header, progress indicator, and other jobFlow UI elements
            if (preg_match('/<form method="POST"[^>]*>.*?<\/form>/is', $output, $matches)) {
              echo $matches[0];
            } else {
              // Fallback: extract body content if form not found
              if (preg_match('/<body[^>]*>(.*?)<\/body>/is', $output, $matches)) {
                echo $matches[1];
              } else {
                echo $output;
              }
            }
          ?>
        </div>

        <!-- Contact Information (Right) -->
        <div class="contact-info-wrapper">
          <div class="contact-info-card">
            <h3>Contact Information</h3>

            <div class="contact-method">
              <div class="contact-icon">📞</div>
              <div>
                <h4>Phone</h4>
                <p><a href="tel:7788469273">778-846-9273</a></p>
                <p class="contact-note">Mon - Fri: 8:00 AM - 4:00 PM</p>
              </div>
            </div>

            <div class="contact-method">
              <div class="contact-icon">✉️</div>
              <div>
                <h4>Email</h4>
                <p><a href="mailto:office@mowology.ca">office@mowology.ca</a></p>
                <p class="contact-note">We respond within 24 hours</p>
              </div>
            </div>

            <div class="contact-method">
              <div class="contact-icon">📍</div>
              <div>
                <h4>Service Areas</h4>
                <p>Vancouver, Burnaby, Richmond</p>
                <p class="contact-note">Proudly serving Metro Vancouver</p>
              </div>
            </div>
          </div>

          <div class="contact-info-card">
            <h3>Why Choose Mowology?</h3>
            <ul class="why-list">
              <li>✓ Free, no-obligation quotes</li>
              <li>✓ Photo-verified service reports</li>
              <li>✓ Fully insured and WCB compliant</li>
              <li>✓ Dedicated account managers</li>
              <li>✓ Same-day emergency response</li>
              <li>✓ Satisfaction guaranteed</li>
            </ul>
          </div>

          <div class="contact-info-card">
            <h3>Office Hours</h3>
            <div class="hours-list">
              <div class="hours-row">
                <span>Monday - Friday</span>
                <span>8:00 AM - 4:00 PM</span>
              </div>
              <div class="hours-row">
                <span>Saturday</span>
                <span>By Appointment</span>
              </div>
              <div class="hours-row">
                <span>Sunday</span>
                <span>Closed</span>
              </div>
            </div>
            <p class="contact-note">Emergency services available 24/7</p>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- Alternative Contact Methods -->
  <section class="alt-contact">
    <div class="container">
      <h2 class="section-title">Prefer to Talk?</h2>
      <p class="section-subtitle">We'd love to hear from you directly</p>
      <div class="alt-contact-buttons">
        <a href="tel:7788469273" class="btn btn-primary-large">Call Now: 778-846-9273</a>
        <a href="mailto:office@mowology.ca" class="btn btn-secondary-large">Email Us</a>
      </div>
    </div>
  </section>

<?php require __DIR__ . '/includes/footer.php'; ?>
