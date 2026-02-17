<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

// CMS-first: render from database if CMS version exists and is published
require_once __DIR__ . '/crm/includes/cms-functions.php';
$_cmsPage = cms_getPageBySlug('services');
if ($_cmsPage && $_cmsPage['status'] === 'published') {
    require_once __DIR__ . '/crm/includes/cms-token-engine.php';
    require_once __DIR__ . '/crm/includes/cms-renderer.php';
    cms_renderPage($_cmsPage);
    exit;
}
unset($_cmsPage);

$pageTitle = 'Our Services | Mowology Landscaping';
$pageDescription = 'Complete landscaping services for property management and residential clients. Weekly maintenance, seasonal cleanups, hedge trimming, and more.';
$activeNav = 'services';

require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/header.php';
?>

  <section class="page-hero">
    <div class="container">
      <h1>Our Services</h1>
      <p>Professional landscaping solutions tailored to your needs</p>
    </div>
  </section>

  <!-- Property Management Services -->
  <section id="property-management" class="service-detail">
    <div class="container">
      <div class="service-header">
        <div class="service-icon-large">🏢</div>
        <div>
          <h2>Property Management & Strata Services</h2>
          <p class="service-intro">Comprehensive landscaping solutions designed specifically for strata councils, property managers, and multi-unit residential complexes.</p>
        </div>
      </div>

      <div class="service-benefits">
        <h3>Why Property Managers Choose Mowology</h3>
        <div class="benefits-grid">
          <div class="benefit-item">
            <strong>📸 Photo Verification</strong>
            <p>Every service visit includes timestamped photos so you have proof of completed work.</p>
          </div>
          <div class="benefit-item">
            <strong>👤 Dedicated Account Manager</strong>
            <p>One point of contact who knows your property and responds quickly to concerns.</p>
          </div>
          <div class="benefit-item">
            <strong>⚡ Emergency Response</strong>
            <p>24/7 availability for storm damage, fallen trees, and urgent maintenance needs.</p>
          </div>
          <div class="benefit-item">
            <strong>📊 Detailed Reporting</strong>
            <p>Monthly reports and seasonal planning to keep your board informed.</p>
          </div>
          <div class="benefit-item">
            <strong>🛡️ Full Insurance</strong>
            <p>$5M liability coverage and WCB compliance for your peace of mind.</p>
          </div>
          <div class="benefit-item">
            <strong>💰 Budget Planning</strong>
            <p>Annual service agreements with predictable costs and no surprise fees.</p>
          </div>
        </div>
      </div>

      <div class="service-offerings">
        <h3>What's Included</h3>
        <div class="offerings-grid">
          <div class="offering">
            <h4>Weekly Maintenance</h4>
            <ul>
              <li>Lawn mowing and edging</li>
              <li>Hedge trimming and shaping</li>
              <li>Garden bed weeding</li>
              <li>Debris removal</li>
              <li>Pathway and sidewalk blowing</li>
              <li>Litter pickup</li>
            </ul>
          </div>
          <div class="offering">
            <h4>Seasonal Services</h4>
            <ul>
              <li>Spring cleanup and mulching</li>
              <li>Summer flower planting</li>
              <li>Fall leaf removal</li>
              <li>Winter storm response</li>
              <li>Annual pruning programs</li>
              <li>Irrigation system checks</li>
            </ul>
          </div>
          <div class="offering">
            <h4>Enhancement Projects</h4>
            <ul>
              <li>Garden bed redesign</li>
              <li>Plant installation</li>
              <li>Soil improvement</li>
              <li>Tree and shrub planting</li>
              <li>Drainage solutions</li>
              <li>Hardscape maintenance</li>
            </ul>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- Residential Services -->
  <section id="residential" class="service-detail alt-bg">
    <div class="container">
      <div class="service-header">
        <div class="service-icon-large">🏡</div>
        <div>
          <h2>Residential Landscaping Services</h2>
          <p class="service-intro">Personalized garden care and landscape maintenance for homeowners who want beautiful outdoor spaces without the work.</p>
        </div>
      </div>

      <div class="service-benefits">
        <h3>The Mowology Residential Advantage</h3>
        <div class="benefits-grid">
          <div class="benefit-item">
            <strong>🌱 Garden Expertise</strong>
            <p>Our team knows plants, soil, and proper pruning techniques to keep your garden thriving.</p>
          </div>
          <div class="benefit-item">
            <strong>📅 Flexible Scheduling</strong>
            <p>Weekly, bi-weekly, or monthly service plans that fit your needs and budget.</p>
          </div>
          <div class="benefit-item">
            <strong>💬 Direct Communication</strong>
            <p>Text or call your crew leader directly with questions or special requests.</p>
          </div>
          <div class="benefit-item">
            <strong>🎨 Design Consultation</strong>
            <p>Free advice on plant selection, garden improvements, and seasonal color.</p>
          </div>
          <div class="benefit-item">
            <strong>✅ Consistent Quality</strong>
            <p>Same crew every visit who learns your preferences and property details.</p>
          </div>
          <div class="benefit-item">
            <strong>🏆 Satisfaction Guarantee</strong>
            <p>If you're not happy with any service, we'll make it right at no charge.</p>
          </div>
        </div>
      </div>

      <div class="service-offerings">
        <h3>Residential Service Options</h3>
        <div class="offerings-grid">
          <div class="offering">
            <h4>Regular Maintenance</h4>
            <ul>
              <li>Lawn mowing and trimming</li>
              <li>Hedge and shrub pruning</li>
              <li>Garden bed maintenance</li>
              <li>Weeding and deadheading</li>
              <li>Lawn fertilization</li>
              <li>Aeration and overseeding</li>
            </ul>
          </div>
          <div class="offering">
            <h4>Seasonal Care</h4>
            <ul>
              <li>Spring garden preparation</li>
              <li>Annual and perennial planting</li>
              <li>Summer garden maintenance</li>
              <li>Fall cleanup and leaf removal</li>
              <li>Winter protection for plants</li>
              <li>Year-round landscape care</li>
            </ul>
          </div>
          <div class="offering">
            <h4>Special Projects</h4>
            <ul>
              <li>Garden design and renovation</li>
              <li>New plant installations</li>
              <li>Lawn renovation</li>
              <li>Stone and gravel work</li>
              <li>Raised garden beds</li>
              <li>One-time cleanups</li>
            </ul>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- Additional Services -->
  <section id="maintenance" class="additional-services">
    <div class="container">
      <h2 class="section-title">Comprehensive Service Capabilities</h2>
      <div class="capabilities-grid">
        <div class="capability-card">
          <h3>🌿 Lawn Care</h3>
          <ul>
            <li>Professional mowing patterns</li>
            <li>Precise edging and trimming</li>
            <li>Fertilization programs</li>
            <li>Aeration and dethatching</li>
            <li>Weed control</li>
            <li>Lawn repair and overseeding</li>
          </ul>
        </div>
        <div class="capability-card">
          <h3>✂️ Pruning & Trimming</h3>
          <ul>
            <li>Hedge trimming and shaping</li>
            <li>Shrub pruning</li>
            <li>Small tree pruning</li>
            <li>Deadwood removal</li>
            <li>Rose care</li>
            <li>Fruit tree maintenance</li>
          </ul>
        </div>
        <div class="capability-card">
          <h3>🌸 Garden Maintenance</h3>
          <ul>
            <li>Weeding and cultivation</li>
            <li>Mulching and top dressing</li>
            <li>Deadheading flowers</li>
            <li>Plant health monitoring</li>
            <li>Pest and disease management</li>
            <li>Soil amendment</li>
          </ul>
        </div>
        <div class="capability-card">
          <h3>🍂 Seasonal Services</h3>
          <ul>
            <li>Spring garden preparation</li>
            <li>Annual planting</li>
            <li>Summer irrigation checks</li>
            <li>Fall cleanup</li>
            <li>Leaf removal</li>
            <li>Winter storm response</li>
          </ul>
        </div>
        <div class="capability-card">
          <h3>🌳 Planting & Design</h3>
          <ul>
            <li>Garden design consultation</li>
            <li>Plant selection advice</li>
            <li>Tree and shrub installation</li>
            <li>Perennial gardens</li>
            <li>Container plantings</li>
            <li>Native plant gardens</li>
          </ul>
        </div>
        <div class="capability-card">
          <h3>🧹 Cleanup Services</h3>
          <ul>
            <li>Debris removal</li>
            <li>Pressure washing</li>
            <li>Gutter cleaning</li>
            <li>Storm damage cleanup</li>
            <li>Property clearing</li>
            <li>Disposal services</li>
          </ul>
        </div>
      </div>
    </div>
  </section>

  <!-- Service Process -->
  <section class="service-process">
    <div class="container">
      <h2 class="section-title">How It Works</h2>
      <div class="process-steps">
        <div class="step">
          <div class="step-number">1</div>
          <h3>Free Consultation</h3>
          <p>Contact us for a free property assessment and detailed quote.</p>
        </div>
        <div class="step">
          <div class="step-number">2</div>
          <h3>Custom Plan</h3>
          <p>We create a maintenance plan tailored to your property's needs and budget.</p>
        </div>
        <div class="step">
          <div class="step-number">3</div>
          <h3>Scheduled Service</h3>
          <p>Our crews arrive on schedule, complete the work, and document with photos.</p>
        </div>
        <div class="step">
          <div class="step-number">4</div>
          <h3>Ongoing Care</h3>
          <p>Regular maintenance keeps your property beautiful year-round.</p>
        </div>
      </div>
    </div>
  </section>

  <!-- CTA -->
  <section class="cta-section">
    <div class="container">
      <h2>Ready to Get Started?</h2>
      <p>Request a free quote and see how we can transform your property</p>
      <div class="cta-buttons">
        <a href="/quote" class="btn btn-primary-large">Get Free Quote</a>
        <a href="tel:7788469273" class="btn btn-secondary-large">Call 778-846-9273</a>
      </div>
    </div>
  </section>

<?php require __DIR__ . '/includes/footer.php'; ?>
