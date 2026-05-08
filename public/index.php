<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$pageTitle       = 'Mowology | Professional Landscaping & Grounds Maintenance | Vancouver, Burnaby, Richmond';
$pageDescription = 'Professional landscaping and grounds maintenance services in Vancouver, Burnaby & Richmond. Specializing in strata properties and residential gardens.';
$pageKeywords    = 'landscaping Vancouver, strata landscaping, property management landscaping, residential landscaping, grounds maintenance, Burnaby landscaping, Richmond landscaping';
$activeNav       = 'home';

$heroImgDesktop = '/assets/img/hero/hero-residential-lawn.jpg';

$heroWords = ['Professional', 'Landscaping', 'Services', 'in', 'Metro', 'Vancouver'];

$testimonials = [
    ['name' => 'Linda N.',      'role' => 'Strata President',  'stars' => 5, 'quote' => "I've lived here for over 30 years and I've never seen our gardens look this good. Mowology transformed our complex."],
    ['name' => 'Colleen Jiang', 'role' => 'Home Owner',        'stars' => 5, 'quote' => "Professional knowledge and excellent service. They made my garden beautiful again and I couldn't be happier."],
    ['name' => 'Michael T.',    'role' => 'Property Manager',  'stars' => 5, 'quote' => 'Professional, reliable, and always on time. The photo reports give us peace of mind every single week.'],
    ['name' => 'Sarah K.',      'role' => 'Strata Council VP', 'stars' => 5, 'quote' => 'Switching to Mowology was the best decision our strata made. Night-and-day difference in quality and communication.'],
    ['name' => 'David R.',      'role' => 'Home Owner',        'stars' => 5, 'quote' => 'They handle everything from spring cleanup to fall leaf removal. Professional crew every single time.'],
];

?>
<?php require __DIR__ . '/includes/head.php'; ?>
<?php require __DIR__ . '/includes/header.php'; ?>

  <!-- ── Enhanced Hero ──────────────────────────────────────── -->
  <section class="mw-hero" id="mw-hero">
    <div class="mw-hero__bg" style="background-image:url('<?= htmlspecialchars($heroImgDesktop, ENT_QUOTES) ?>')"></div>
    <div class="mw-hero__overlay"></div>
    <div class="mw-hero__particles" id="mw-hero-particles" aria-hidden="true"></div>

    <div class="mw-hero__content">
      <span class="mw-hero__badge">Vancouver&rsquo;s Trusted Landscapers</span>

      <h1 class="mw-hero__headline">
        <?php foreach ($heroWords as $i => $word): ?>
          <span class="mw-hero__word" style="--delay:<?= number_format(0.35 + $i * 0.1, 2) ?>s"><?= htmlspecialchars($word, ENT_QUOTES) ?></span>
        <?php endforeach; ?>
      </h1>

      <p class="mw-hero__sub">Expert grounds maintenance for strata properties and residential gardens across Vancouver, Burnaby &amp; Richmond.</p>

      <div class="mw-hero__actions">
        <a href="/jobFlow/jobFlow-getQuote.php" class="mw-btn mw-btn--primary">
          Get Free Quote
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
        </a>
        <a href="/services" class="mw-btn mw-btn--ghost">View Services</a>
      </div>

      <div class="mw-hero__trust">
        <div class="mw-hero__trust-item">
          <span class="mw-hero__trust-num">500+</span>
          <span>Properties Served</span>
        </div>
        <div class="mw-hero__trust-sep" aria-hidden="true"></div>
        <div class="mw-hero__trust-item">
          <span class="mw-hero__trust-num">10+</span>
          <span>Years Experience</span>
        </div>
        <div class="mw-hero__trust-sep" aria-hidden="true"></div>
        <div class="mw-hero__trust-item">
          <span class="mw-hero__trust-num">100%</span>
          <span>Photo Verified</span>
        </div>
      </div>
    </div>

    <div class="mw-hero__scroll" aria-hidden="true">
      <div class="mw-hero__scroll-line"></div>
      <span>Scroll</span>
    </div>
  </section>

  <!-- ── Stats Banner ────────────────────────────────────────── -->
  <section class="mw-stats" id="mw-stats" aria-label="At a glance">
    <div class="mw-stats__inner">

      <div class="mw-stat">
        <div class="mw-stat__circle" aria-hidden="true">
          <svg viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
        </div>
        <div class="mw-stat__num"><span class="mw-stat__count" data-target="500">0</span>+</div>
        <p class="mw-stat__label">Properties Served</p>
      </div>

      <div class="mw-stat">
        <div class="mw-stat__circle" aria-hidden="true">
          <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        </div>
        <div class="mw-stat__num"><span class="mw-stat__count" data-target="10">0</span>+</div>
        <p class="mw-stat__label">Years Experience</p>
      </div>

      <div class="mw-stat">
        <div class="mw-stat__circle" aria-hidden="true">
          <svg viewBox="0 0 24 24"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
        </div>
        <div class="mw-stat__num"><span class="mw-stat__count" data-target="100">0</span>%</div>
        <p class="mw-stat__label">Photo Verified</p>
      </div>

      <div class="mw-stat">
        <div class="mw-stat__circle" aria-hidden="true">
          <svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
        </div>
        <div class="mw-stat__num"><span class="mw-stat__count" data-target="98">0</span>%</div>
        <p class="mw-stat__label">Client Retention</p>
      </div>

    </div>
  </section>

  <!-- ── Services Overview ───────────────────────────────────── -->
  <section class="services-overview">
    <div class="container">
      <span class="mw-label">What We Do</span>
      <h2 class="mw-section-heading">Who We Serve</h2>

      <div class="services-grid">

        <div class="service-card mw-reveal">
          <div class="service-card__photo">
            <img src="/assets/img/services/strata-aerial.jpg" alt="Strata property grounds maintenance from above" loading="lazy">
          </div>
          <div class="service-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24"><rect x="2" y="7" width="20" height="15" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/><line x1="12" y1="12" x2="12" y2="17"/><line x1="9" y1="14.5" x2="15" y2="14.5"/></svg>
          </div>
          <h3>Property Management &amp; Strata</h3>
          <p>Reliable, photo-verified landscaping for townhomes, condos, and multi-unit complexes. Weekly maintenance, seasonal cleanups, and emergency storm response by insured, strata-dedicated crews.</p>
          <ul class="service-features">
            <li>Photo-verified service reports</li>
            <li>Dedicated account managers</li>
            <li>Emergency response available</li>
            <li>Fully insured crews</li>
          </ul>
          <a href="/services" class="btn-link">Learn More &rarr;</a>
        </div>

        <div class="service-card mw-reveal" style="--delay:0.1s">
          <div class="service-card__photo">
            <img src="/assets/img/hero/hero-residential-lawn.jpg" alt="Child playing on a beautifully maintained residential lawn" loading="lazy">
          </div>
          <div class="service-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
          </div>
          <h3>Residential Properties</h3>
          <p>Professional garden care and landscape maintenance for homeowners who want beautiful, well-maintained outdoor spaces. From weekly lawn care to seasonal cleanups and garden design.</p>
          <ul class="service-features">
            <li>Personalized service plans</li>
            <li>Flexible scheduling</li>
            <li>Garden enhancement</li>
            <li>Seasonal programs</li>
          </ul>
          <a href="/services" class="btn-link">Learn More &rarr;</a>
        </div>

      </div>
    </div>
  </section>

  <!-- ── Why Choose Us ───────────────────────────────────────── -->
  <section class="why-choose">
    <div class="container">
      <span class="mw-label">Our Difference</span>
      <h2 class="mw-section-heading">Why Choose Mowology</h2>

      <div class="features-grid">

        <div class="feature mw-reveal">
          <div class="feature-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
          </div>
          <h3>Higher Standards</h3>
          <p>We deliver a higher degree of service with attention to detail and consistent quality.</p>
        </div>

        <div class="feature mw-reveal" style="--delay:0.08s">
          <div class="feature-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
          </div>
          <h3>Photo Verification</h3>
          <p>Every service includes photo documentation so you know the work was completed to standard.</p>
        </div>

        <div class="feature mw-reveal" style="--delay:0.16s">
          <div class="feature-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
          </div>
          <h3>Fully Insured</h3>
          <p>All crews are fully insured and trained in proper safety and landscaping techniques.</p>
        </div>

        <div class="feature mw-reveal" style="--delay:0.24s">
          <div class="feature-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
          </div>
          <h3>Professional Team</h3>
          <p>Experienced landscaping professionals who take pride in their work and your property.</p>
        </div>

        <div class="feature mw-reveal" style="--delay:0.32s">
          <div class="feature-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
          </div>
          <h3>Reliable Service</h3>
          <p>Consistent scheduling and dependable service delivery, rain or shine.</p>
        </div>

        <div class="feature mw-reveal" style="--delay:0.4s">
          <div class="feature-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M8 14s1.5 2 4 2 4-2 4-2"/><line x1="9" y1="9" x2="9.01" y2="9"/><line x1="15" y1="9" x2="15.01" y2="9"/></svg>
          </div>
          <h3>Eco-Friendly</h3>
          <p>Sustainable practices and environmentally conscious maintenance methods.</p>
        </div>

      </div>
    </div>
  </section>

  <!-- ── Service Areas ───────────────────────────────────────── -->
  <section class="service-areas">
    <div class="container">
      <span class="mw-label">Where We Work</span>
      <h2 class="mw-section-heading">Service Areas</h2>
      <p class="section-subtitle">Proudly serving Metro Vancouver communities</p>

      <div class="areas-grid">

        <div class="area-card mw-reveal">
          <h3>Vancouver</h3>
          <p>Downtown, West End, Kitsilano, Point Grey, Dunbar, Kerrisdale, Mount Pleasant, Commercial Drive</p>
        </div>

        <div class="area-card mw-reveal" style="--delay:0.1s">
          <h3>Burnaby</h3>
          <p>Metrotown, Brentwood, Lougheed, Deer Lake, Edmonds, Capitol Hill, Heights</p>
        </div>

        <div class="area-card mw-reveal" style="--delay:0.2s">
          <h3>Richmond</h3>
          <p>Richmond Centre, Steveston, Brighouse, City Centre, Hamilton, Terra Nova</p>
        </div>

      </div>
    </div>
  </section>

  <!-- ── Testimonials Carousel ───────────────────────────────── -->
  <section class="mw-testimonials" id="mw-testimonials">
    <div class="mw-testimonials__inner">

      <div class="mw-testimonials__header mw-reveal">
        <span class="mw-label">Client Stories</span>
        <h2 class="mw-section-heading">What Our Clients Say</h2>
      </div>

      <div class="mw-testi-track-wrap">
        <div class="mw-testi-track">
          <?php foreach (array_merge($testimonials, $testimonials) as $t): ?>
          <article class="mw-testi-card">
            <div class="mw-testi-card__stars" aria-label="<?= (int)($t['stars'] ?? 5) ?> stars">
              <?php for ($s = 0; $s < (int)($t['stars'] ?? 5); $s++): ?>&#9733;<?php endfor; ?>
            </div>
            <blockquote class="mw-testi-card__quote">&ldquo;<?= htmlspecialchars($t['quote'] ?? '', ENT_QUOTES) ?>&rdquo;</blockquote>
            <footer class="mw-testi-card__footer">
              <div class="mw-testi-card__avatar" aria-hidden="true"><?= htmlspecialchars(mb_substr($t['name'] ?? '?', 0, 1), ENT_QUOTES) ?></div>
              <div>
                <div class="mw-testi-card__name"><?= htmlspecialchars($t['name'] ?? '', ENT_QUOTES) ?></div>
                <div class="mw-testi-card__role"><?= htmlspecialchars($t['role'] ?? '', ENT_QUOTES) ?></div>
              </div>
            </footer>
          </article>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="mw-testi-controls">
        <button class="mw-testi-btn" id="mw-testi-prev" aria-label="Previous testimonial">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M19 12H5M12 5l-7 7 7 7"/></svg>
        </button>
        <div class="mw-testi-dots" id="mw-testi-dots" role="tablist" aria-label="Testimonials"></div>
        <button class="mw-testi-btn" id="mw-testi-next" aria-label="Next testimonial">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
        </button>
      </div>

    </div>
  </section>

  <!-- ── CTA Section ─────────────────────────────────────────── -->
  <section class="cta-section">
    <div class="container">
      <h2>Ready to Elevate Your Property&rsquo;s Landscape?</h2>
      <p>Get a free, no-obligation quote for your property</p>
      <div class="cta-buttons">
        <a href="/jobFlow/jobFlow-getQuote.php" class="mw-btn mw-btn--white">
          Request Free Quote
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
        </a>
        <a href="tel:7788469273" class="mw-btn mw-btn--outline-white">Call 778-846-9273</a>
      </div>
    </div>
  </section>

<?php require __DIR__ . '/includes/footer.php'; ?>
