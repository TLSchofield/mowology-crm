<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

// CMS-first: render from database if CMS version exists and is published
require_once __DIR__ . '/crm/includes/cms-functions.php';
$_cmsPage = cms_getPageBySlug('home');
if ($_cmsPage && $_cmsPage['status'] === 'published') {
    require_once __DIR__ . '/crm/includes/cms-token-engine.php';
    require_once __DIR__ . '/crm/includes/cms-renderer.php';
    cms_renderPage($_cmsPage);
    exit;
}
unset($_cmsPage);

$pageTitle = 'Mowology | Professional Landscaping & Grounds Maintenance | Vancouver, Burnaby, Richmond';
$pageDescription = 'Professional landscaping and grounds maintenance services in Vancouver, Burnaby & Richmond. Specializing in strata properties and residential gardens.';
$pageKeywords = 'landscaping Vancouver, strata landscaping, property management landscaping, residential landscaping, grounds maintenance, Burnaby landscaping, Richmond landscaping';
$activeNav = 'home';

/**
 * CMS-ready hero image paths (store these exact strings in CMS later)
 * Web-root relative paths so they work across environments.
 */
$heroImgDesktop = '/assets/img/hero/hero-lawn-care-1920x1080.jpg';
$heroImgTablet  = '/assets/img/hero/hero-lawn-care-1024x768.jpg';
$heroImgMobile  = '/assets/img/hero/hero-lawn-care-768x1024.jpg';

// app_config lives at /public_html/app_config

?>
<?php require __DIR__ . '/includes/head.php'; ?>
<?php require __DIR__ . '/includes/header.php'; ?>

  <!-- Hero Section -->
  <section class="hero hero--img">
    <!-- Responsive hero image (better performance + CMS-ready) -->
    <picture class="hero-media" aria-hidden="true">
      <source media="(max-width: 768px)" srcset="<?= htmlspecialchars($heroImgMobile, ENT_QUOTES) ?>">
      <source media="(max-width: 1200px)" srcset="<?= htmlspecialchars($heroImgTablet, ENT_QUOTES) ?>">
      <img src="<?= htmlspecialchars($heroImgDesktop, ENT_QUOTES) ?>" alt="" loading="eager" decoding="async">
    </picture>

    <div class="hero-overlay"></div>

    <div class="container">
      <div class="hero-content">
        <h1 class="hero-title">Professional Landscaping Services in Metro Vancouver</h1>
        <p class="hero-subtitle">Expert grounds maintenance for property management companies and residential properties in Vancouver, Burnaby & Richmond</p>
        <div class="hero-buttons">
          <a href="jobFlow/jobFlow-getQuote.php" class="btn btn-primary">Get Free Quote</a>
          <a href="/services.php" class="btn btn-secondary">Our Services</a>
        </div>
      </div>
    </div>
  </section>

  <!-- Services Overview -->
  <section class="services-overview">
    <div class="container">
      <h2 class="section-title">Who We Serve</h2>
      <div class="services-grid">
        <div class="service-card">
          <div class="service-icon">🏢</div>
          <h3>Property Management & Strata</h3>
          <p>Reliable, photo-verified landscaping for townhomes, condos, and multi-unit complexes. Weekly maintenance, seasonal cleanups, and emergency storm response by insured, strata-dedicated crews.</p>
          <ul class="service-features">
            <li>✓ Photo-verified service reports</li>
            <li>✓ Dedicated account managers</li>
            <li>✓ Emergency response available</li>
            <li>✓ Fully insured crews</li>
          </ul>
          <a href="/services.php#property-management" class="btn-link">Learn More →</a>
        </div>

        <div class="service-card">
          <div class="service-icon">🏡</div>
          <h3>Residential Properties</h3>
          <p>Professional garden care and landscape maintenance for homeowners who want beautiful, well-maintained outdoor spaces. From weekly lawn care to seasonal cleanups and garden design.</p>
          <ul class="service-features">
            <li>✓ Personalized service</li>
            <li>✓ Flexible scheduling</li>
            <li>✓ Garden enhancement</li>
            <li>✓ Seasonal programs</li>
          </ul>
          <a href="/services.php#residential" class="btn-link">Learn More →</a>
        </div>
      </div>
    </div>
  </section>

  <!-- Why Choose Us -->
  <section class="why-choose">
    <div class="container">
      <h2 class="section-title">Why Choose Mowology</h2>
      <div class="features-grid">
        <div class="feature">
          <div class="feature-icon">⭐</div>
          <h3>Higher Standards</h3>
          <p>We deliver a higher degree of service with attention to detail and consistent quality.</p>
        </div>
        <div class="feature">
          <div class="feature-icon">📸</div>
          <h3>Photo Verification</h3>
          <p>Every service includes photo documentation so you know the work was completed to standard.</p>
        </div>
        <div class="feature">
          <div class="feature-icon">🛡️</div>
          <h3>Fully Insured</h3>
          <p>All crews are fully insured and trained in proper safety and landscaping techniques.</p>
        </div>
        <div class="feature">
          <div class="feature-icon">💼</div>
          <h3>Professional Team</h3>
          <p>Experienced landscaping professionals who take pride in their work and your property.</p>
        </div>
        <div class="feature">
          <div class="feature-icon">⚡</div>
          <h3>Reliable Service</h3>
          <p>Consistent scheduling and dependable service delivery, rain or shine.</p>
        </div>
        <div class="feature">
          <div class="feature-icon">🌱</div>
          <h3>Eco-Friendly</h3>
          <p>Sustainable practices and environmentally conscious maintenance methods.</p>
        </div>
      </div>
    </div>
  </section>

  <!-- Service Areas -->
  <section class="service-areas">
    <div class="container">
      <h2 class="section-title">Service Areas</h2>
      <p class="section-subtitle">Proudly serving Metro Vancouver communities</p>
      <div class="areas-grid">
        <div class="area-card">
          <h3>Vancouver</h3>
          <p>Downtown, West End, Kitsilano, Point Grey, Dunbar, Kerrisdale, Mount Pleasant, Commercial Drive</p>
        </div>
        <div class="area-card">
          <h3>Burnaby</h3>
          <p>Metrotown, Brentwood, Lougheed, Deer Lake, Edmonds, Capitol Hill, Heights</p>
        </div>
        <div class="area-card">
          <h3>Richmond</h3>
          <p>Richmond Centre, Steveston, Brighouse, City Centre, Hamilton, Terra Nova</p>
        </div>
      </div>
    </div>
  </section>

  <!-- Testimonials -->
  <section class="testimonials">
    <div class="container">
      <h2 class="section-title">What Our Clients Say</h2>
      <div class="testimonials-grid">
        <div class="testimonial-card">
          <div class="stars">★★★★★</div>
          <p class="testimonial-text">"I've lived here for over 30 years and I've never seen our gardens look this good."</p>
          <p class="testimonial-author">Linda N.</p>
          <p class="testimonial-role">Strata President</p>
        </div>
        <div class="testimonial-card">
          <div class="stars">★★★★★</div>
          <p class="testimonial-text">"Mowology offers professional knowledge and excellent service for my garden. They are very nice. They did a very good maintenance job and making my garden looks beautiful again. I would like to recommend to all my friends."</p>
          <p class="testimonial-author">Colleen Jiang</p>
          <p class="testimonial-role">Home Owner</p>
        </div>
        <div class="testimonial-card">
          <div class="stars">★★★★★</div>
          <p class="testimonial-text">"Professional, reliable, and always on time. The photo reports give us peace of mind that the work is being done right."</p>
          <p class="testimonial-author">Michael T.</p>
          <p class="testimonial-role">Property Manager</p>
        </div>
      </div>
    </div>
  </section>

  <!-- CTA Section -->
  <section class="cta-section">
    <div class="container">
      <h2>Ready to Elevate Your Property's Landscape?</h2>
      <p>Get a free, no-obligation quote for your property</p>
      <div class="cta-buttons">
        <a href="/jobFlow/jobFlow-getQuote.php" class="btn btn-primary-large">Request Free Quote</a>
        <a href="tel:7788469273" class="btn btn-secondary-large">Call 778-846-9273</a>
      </div>
    </div>
  </section>

<?php require __DIR__ . '/includes/footer.php'; ?>
