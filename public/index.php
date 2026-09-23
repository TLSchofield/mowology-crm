<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$pageTitle = 'Mowology | Professional Landscaping & Grounds Maintenance | Vancouver, Burnaby, Richmond';
$pageDescription = 'Professional landscaping and grounds maintenance services in Vancouver, Burnaby & Richmond. Specializing in strata properties and residential gardens.';
$pageKeywords = 'landscaping Vancouver, strata landscaping, property management landscaping, residential landscaping, grounds maintenance, Burnaby landscaping, Richmond landscaping';
$activeNav = 'home';

$heroImg = '/assets/img/hero/hero-lawn-care-1920x1080.jpg';

?>
<?php require __DIR__ . '/includes/head.php'; ?>
<?php require __DIR__ . '/includes/header.php'; ?>

<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "LocalBusiness",
  "name": <?= json_encode(SITE_NAME) ?>,
  "url": <?= json_encode(SITE_URL) ?>,
  "telephone": <?= json_encode('+1' . SITE_PHONE_TEL) ?>,
  "email": <?= json_encode(SITE_EMAIL) ?>,
  "description": "Professional landscaping and grounds maintenance for strata, property managers, and residential properties in Metro Vancouver. Photo-verified service reports, fully insured crews, 8+ years experience.",
  "image": <?= json_encode(SITE_URL . '/assets/img/hero/hero-lawn-care-1920x1080.jpg') ?>,
  "priceRange": "$$",
  "areaServed": [
    {"@type": "City", "name": "Vancouver", "addressRegion": "BC", "addressCountry": "CA"},
    {"@type": "City", "name": "Burnaby",  "addressRegion": "BC", "addressCountry": "CA"},
    {"@type": "City", "name": "Richmond", "addressRegion": "BC", "addressCountry": "CA"}
  ],
  "aggregateRating": {
    "@type": "AggregateRating",
    "ratingValue": "5",
    "bestRating": "5",
    "worstRating": "1",
    "ratingCount": "5"
  },
  "review": [
    {
      "@type": "Review",
      "reviewRating": {"@type": "Rating", "ratingValue": "5", "bestRating": "5"},
      "author": {"@type": "Person", "name": "Linda N."},
      "reviewBody": "I've lived here for over 30 years and I've never seen our gardens look this good."
    },
    {
      "@type": "Review",
      "reviewRating": {"@type": "Rating", "ratingValue": "5", "bestRating": "5"},
      "author": {"@type": "Person", "name": "Colleen Jiang"},
      "reviewBody": "Mowology offers professional knowledge and excellent service for my garden. They did a very good maintenance job and making my garden looks beautiful again."
    },
    {
      "@type": "Review",
      "reviewRating": {"@type": "Rating", "ratingValue": "5", "bestRating": "5"},
      "author": {"@type": "Person", "name": "Michael T."},
      "reviewBody": "Professional, reliable, and always on time. The photo reports give us peace of mind that the work is being done right."
    },
    {
      "@type": "Review",
      "reviewRating": {"@type": "Rating", "ratingValue": "5", "bestRating": "5"},
      "author": {"@type": "Person", "name": "David K."},
      "reviewBody": "Our strata has tried multiple landscaping companies and Mowology is by far the most professional and communicative."
    },
    {
      "@type": "Review",
      "reviewRating": {"@type": "Rating", "ratingValue": "5", "bestRating": "5"},
      "author": {"@type": "Person", "name": "Sarah W."},
      "reviewBody": "They show up every week without fail. The grounds have never looked better and our residents love it."
    }
  ]
}
</script>

  <!-- Hero Section -->
  <section class="mw-hero">
    <picture class="mw-hero__bg">
      <source type="image/webp"
        srcset="/assets/img/hero/hero-lawn-care-480w.webp 480w,
                /assets/img/hero/hero-lawn-care-1080w.webp 1080w,
                /assets/img/hero/hero-lawn-care-1920w.webp 1920w"
        sizes="100vw">
      <source type="image/jpeg"
        srcset="/assets/img/hero/hero-lawn-care-480w.jpg 480w,
                /assets/img/hero/hero-lawn-care-1080w.jpg 1080w,
                /assets/img/hero/hero-lawn-care-1920x1080.jpg 1920w"
        sizes="100vw">
      <img src="/assets/img/hero/hero-lawn-care-1920x1080.jpg"
           alt="Professional landscaping services in Metro Vancouver"
           fetchpriority="high"
           decoding="async">
    </picture>
    <div class="mw-hero__overlay"></div>
    <div class="mw-hero__particles" aria-hidden="true" id="mwHeroParticles"></div>

    <div class="container">
      <div class="mw-hero__content">

        <div class="mw-hero__badge">Metro Vancouver &middot; Est. 2019</div>

        <h1 class="mw-hero__headline">
          <?php
          $words = explode(' ', 'Professional Landscaping Services in Metro Vancouver');
          $baseDelay = 0.3;
          foreach ($words as $i => $word) {
              $delay = number_format($baseDelay + ($i * 0.08), 2);
              echo '<span class="mw-hero__word" style="animation-delay:' . $delay . 's">' . htmlspecialchars($word, ENT_QUOTES) . '</span>';
          }
          ?>
        </h1>

        <p class="mw-hero__sub">Expert grounds maintenance for property management companies and residential properties in Vancouver, Burnaby &amp; Richmond</p>

        <div class="mw-hero__actions">
          <a href="/jobFlow/jobFlow-getQuote.php" class="btn btn-primary">Get Free Quote</a>
          <a href="/services" class="btn btn-secondary">Our Services</a>
        </div>

        <div class="mw-hero__trust">
          <div class="mw-hero__trust-item">
            <span class="mw-hero__trust-num">250+</span>
            <span>Properties Served</span>
          </div>
          <div class="mw-hero__trust-sep"></div>
          <div class="mw-hero__trust-item">
            <span class="mw-hero__trust-num">8+</span>
            <span>Years Experience</span>
          </div>
          <div class="mw-hero__trust-sep"></div>
          <div class="mw-hero__trust-item">
            <span class="mw-hero__trust-num">100%</span>
            <span>Verified Service</span>
          </div>
        </div>

      </div>
    </div>

    <div class="mw-hero__scroll" aria-hidden="true">
      <div class="mw-hero__scroll-line"></div>
      <span>Scroll</span>
    </div>
  </section>

  <!-- Stats Banner -->
  <section class="mw-stats" aria-label="Key statistics">
    <div class="mw-stats__inner">
      <div class="mw-stat">
        <div class="mw-stat__circle">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
        </div>
        <p class="mw-stat__num">250+</p>
        <p class="mw-stat__label">Properties Maintained</p>
      </div>
      <div class="mw-stat">
        <div class="mw-stat__circle">
          <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        </div>
        <p class="mw-stat__num">8+</p>
        <p class="mw-stat__label">Years Experience</p>
      </div>
      <div class="mw-stat">
        <div class="mw-stat__circle">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
        </div>
        <p class="mw-stat__num">100%</p>
        <p class="mw-stat__label">Photo-Verified Service</p>
      </div>
      <div class="mw-stat">
        <div class="mw-stat__circle">
          <svg viewBox="0 0 24 24" aria-hidden="true"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
        </div>
        <p class="mw-stat__num">5&#9733;</p>
        <p class="mw-stat__label">Average Rating</p>
      </div>
    </div>
  </section>

  <!-- Services Overview -->
  <section class="services-overview">
    <div class="container">
      <h2 class="section-title">Who We Serve</h2>
      <div class="services-grid">

        <div class="service-card">
          <div class="service-icon">
            <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="2" y="7" width="20" height="15" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/><line x1="12" y1="12" x2="12" y2="17"/><line x1="9" y1="14.5" x2="15" y2="14.5"/></svg>
          </div>
          <h3>Property Management &amp; Strata</h3>
          <p>Reliable, photo-verified landscaping for townhomes, condos, and multi-unit complexes. Weekly maintenance, seasonal cleanups, and emergency storm response by insured, strata-dedicated crews.</p>
          <ul class="service-features">
            <li>Photo-verified service reports</li>
            <li>Dedicated account managers</li>
            <li>Emergency response available</li>
            <li>Fully insured crews</li>
          </ul>
          <a href="/services#property-management" class="btn-link">Learn More &rarr;</a>
        </div>

        <div class="service-card">
          <div class="service-icon">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
          </div>
          <h3>Residential Properties</h3>
          <p>Professional garden care and landscape maintenance for homeowners who want beautiful, well-maintained outdoor spaces. From weekly lawn care to seasonal cleanups and garden design.</p>
          <ul class="service-features">
            <li>Personalized service</li>
            <li>Flexible scheduling</li>
            <li>Garden enhancement</li>
            <li>Seasonal programs</li>
          </ul>
          <a href="/services#residential" class="btn-link">Learn More &rarr;</a>
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
          <div class="feature-icon">
            <svg viewBox="0 0 24 24" aria-hidden="true"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
          </div>
          <h3>Higher Standards</h3>
          <p>We deliver a higher degree of service with attention to detail and consistent quality.</p>
        </div>

        <div class="feature">
          <div class="feature-icon">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
          </div>
          <h3>Photo Verification</h3>
          <p>Every service includes photo documentation so you know the work was completed to standard.</p>
        </div>

        <div class="feature">
          <div class="feature-icon">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
          </div>
          <h3>Fully Insured</h3>
          <p>All crews are fully insured and trained in proper safety and landscaping techniques.</p>
        </div>

        <div class="feature">
          <div class="feature-icon">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
          </div>
          <h3>Professional Team</h3>
          <p>Experienced landscaping professionals who take pride in their work and your property.</p>
        </div>

        <div class="feature">
          <div class="feature-icon">
            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
          </div>
          <h3>Reliable Service</h3>
          <p>Consistent scheduling and dependable service delivery, rain or shine.</p>
        </div>

        <div class="feature">
          <div class="feature-icon">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 22V12"/><path d="M12 12C12 12 7 10 7 6a5 5 0 0 1 10 0c0 4-5 6-5 6z"/><path d="M12 22c-3 0-7-2-7-8"/><path d="M12 22c3 0 7-2 7-8"/></svg>
          </div>
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

  <!-- Testimonials Carousel -->
  <section class="mw-testimonials">
    <div class="mw-testimonials__inner">
      <div class="mw-testimonials__header">
        <h2 class="section-title">What Our Clients Say</h2>
      </div>
      <div class="mw-testi-track-wrap" id="mwTestiWrap">
        <div class="mw-testi-track" id="mwTestiTrack">

          <div class="mw-testi-card">
            <div class="mw-testi-card__stars">&#9733;&#9733;&#9733;&#9733;&#9733;</div>
            <p class="mw-testi-card__quote">"I've lived here for over 30 years and I've never seen our gardens look this good."</p>
            <div class="mw-testi-card__footer">
              <div class="mw-testi-card__avatar">L</div>
              <div>
                <div class="mw-testi-card__name">Linda N.</div>
                <div class="mw-testi-card__role">Strata President</div>
              </div>
            </div>
          </div>

          <div class="mw-testi-card">
            <div class="mw-testi-card__stars">&#9733;&#9733;&#9733;&#9733;&#9733;</div>
            <p class="mw-testi-card__quote">"Mowology offers professional knowledge and excellent service for my garden. They did a very good maintenance job and making my garden looks beautiful again."</p>
            <div class="mw-testi-card__footer">
              <div class="mw-testi-card__avatar">C</div>
              <div>
                <div class="mw-testi-card__name">Colleen Jiang</div>
                <div class="mw-testi-card__role">Home Owner</div>
              </div>
            </div>
          </div>

          <div class="mw-testi-card">
            <div class="mw-testi-card__stars">&#9733;&#9733;&#9733;&#9733;&#9733;</div>
            <p class="mw-testi-card__quote">"Professional, reliable, and always on time. The photo reports give us peace of mind that the work is being done right."</p>
            <div class="mw-testi-card__footer">
              <div class="mw-testi-card__avatar">M</div>
              <div>
                <div class="mw-testi-card__name">Michael T.</div>
                <div class="mw-testi-card__role">Property Manager</div>
              </div>
            </div>
          </div>

          <div class="mw-testi-card">
            <div class="mw-testi-card__stars">&#9733;&#9733;&#9733;&#9733;&#9733;</div>
            <p class="mw-testi-card__quote">"Our strata has tried multiple landscaping companies and Mowology is by far the most professional and communicative."</p>
            <div class="mw-testi-card__footer">
              <div class="mw-testi-card__avatar">D</div>
              <div>
                <div class="mw-testi-card__name">David K.</div>
                <div class="mw-testi-card__role">Strata Council Member</div>
              </div>
            </div>
          </div>

          <div class="mw-testi-card">
            <div class="mw-testi-card__stars">&#9733;&#9733;&#9733;&#9733;&#9733;</div>
            <p class="mw-testi-card__quote">"They show up every week without fail. The grounds have never looked better and our residents love it."</p>
            <div class="mw-testi-card__footer">
              <div class="mw-testi-card__avatar">S</div>
              <div>
                <div class="mw-testi-card__name">Sarah W.</div>
                <div class="mw-testi-card__role">Property Manager, Burnaby</div>
              </div>
            </div>
          </div>

        </div>
      </div>
      <div class="mw-testi-controls" id="mwTestiControls">
        <button class="mw-testi-btn" id="mwTestiPrev" aria-label="Previous testimonial">
          <svg viewBox="0 0 24 24" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>
        </button>
        <div class="mw-testi-dots" id="mwTestiDots"></div>
        <button class="mw-testi-btn" id="mwTestiNext" aria-label="Next testimonial">
          <svg viewBox="0 0 24 24" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg>
        </button>
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
