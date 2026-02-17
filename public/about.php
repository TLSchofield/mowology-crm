<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

// CMS-first: render from database if CMS version exists and is published
require_once __DIR__ . '/crm/includes/cms-functions.php';
$_cmsPage = cms_getPageBySlug('about');
if ($_cmsPage && $_cmsPage['status'] === 'published') {
    require_once __DIR__ . '/crm/includes/cms-token-engine.php';
    require_once __DIR__ . '/crm/includes/cms-renderer.php';
    cms_renderPage($_cmsPage);
    exit;
}
unset($_cmsPage);

$pageTitle = 'About Us | Mowology Landscaping';
$pageDescription = "Learn about Mowology - Metro Vancouver's trusted landscaping company serving property management and residential clients since 2010.";
$activeNav = 'about';

require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/header.php';
?>

    <section class="page-hero">
        <div class="container">
            <h1>About Mowology</h1>
            <p>A higher degree of service in landscaping</p>
        </div>
    </section>

    <section class="about-intro">
        <div class="container">
            <div class="about-content">
                <h2>Our Story</h2>
                <p class="lead-text">Mowology was founded on a simple principle: landscaping services should be reliable, professional, and exceed expectations. Since 2010, we've been serving property management companies and residential clients across Metro Vancouver with that commitment.</p>
                
                <p>What started as a small team with a few lawn mowers has grown into one of Vancouver's most trusted landscaping companies. But despite our growth, our core values remain the same: quality work, reliable service, and treating every property like it's our own.</p>

                <p>Our name "Mowology" reflects our belief that there's a science to great landscaping. It's not just about cutting grass or trimming hedges—it's about understanding plants, soil conditions, seasonal patterns, and how to create outdoor spaces that thrive year-round.</p>
            </div>
        </div>
    </section>

    <section class="mission-values">
        <div class="container">
            <h2 class="section-title">Our Mission & Values</h2>
            <div class="values-grid">
                <div class="value-card">
                    <div class="value-icon">🎯</div>
                    <h3>Our Mission</h3>
                    <p>To deliver exceptional landscaping services that enhance property value, create beautiful outdoor spaces, and exceed client expectations through consistent quality and reliable service.</p>
                </div>
                <div class="value-card">
                    <div class="value-icon">⭐</div>
                    <h3>Quality First</h3>
                    <p>We never cut corners. Every job is completed to the highest standards with attention to detail and professional craftsmanship.</p>
                </div>
                <div class="value-card">
                    <div class="value-icon">🤝</div>
                    <h3>Reliability</h3>
                    <p>We show up when promised, do what we say, and maintain consistent service standards rain or shine.</p>
                </div>
                <div class="value-card">
                    <div class="value-icon">💚</div>
                    <h3>Environmental Stewardship</h3>
                    <p>We use sustainable practices, minimize waste, and promote healthy gardens that benefit local ecosystems.</p>
                </div>
                <div class="value-card">
                    <div class="value-icon">🔧</div>
                    <h3>Professionalism</h3>
                    <p>Our team is trained, insured, and equipped with professional-grade tools to deliver superior results.</p>
                </div>
                <div class="value-card">
                    <div class="value-icon">📈</div>
                    <h3>Continuous Improvement</h3>
                    <p>We invest in training, new techniques, and equipment to stay at the forefront of the landscaping industry.</p>
                </div>
            </div>
        </div>
    </section>

    <section class="what-makes-different">
        <div class="container">
            <h2 class="section-title">What Makes Us Different</h2>
            <div class="difference-grid">
                <div class="difference-item">
                    <h3>📸 Photo Documentation</h3>
                    <p>Every service visit includes photo reports so property managers and homeowners can verify work completion and quality. This transparency builds trust and provides peace of mind.</p>
                </div>
                <div class="difference-item">
                    <h3>👥 Dedicated Teams</h3>
                    <p>We assign the same crew to your property each visit. This consistency means they learn your property's specific needs and preferences, resulting in better service over time.</p>
                </div>
                <div class="difference-item">
                    <h3>🎓 Trained Professionals</h3>
                    <p>Our team receives ongoing training in proper pruning techniques, plant identification, equipment operation, and safety protocols. We're not just laborers—we're skilled landscaping professionals.</p>
                </div>
                <div class="difference-item">
                    <h3>🛡️ Fully Insured</h3>
                    <p>We carry $5 million in liability insurance and full WCB coverage. This protects you from liability and demonstrates our commitment to operating as a professional business.</p>
                </div>
                <div class="difference-item">
                    <h3>⚡ Emergency Response</h3>
                    <p>Storm damage? Fallen tree? We offer 24/7 emergency response for urgent situations, especially for our property management clients.</p>
                </div>
                <div class="difference-item">
                    <h3>💼 Account Managers</h3>
                    <p>Property management clients get a dedicated account manager who serves as your single point of contact for scheduling, concerns, and planning.</p>
                </div>
            </div>
        </div>
    </section>

    <section class="why-trust">
        <div class="container">
            <h2 class="section-title">Why Clients Trust Us</h2>
            <div class="trust-features">
                <div class="trust-item">
                    <div class="trust-number">15+</div>
                    <div class="trust-label">Years Serving Metro Vancouver</div>
                </div>
                <div class="trust-item">
                    <div class="trust-number">500+</div>
                    <div class="trust-label">Properties Under Care</div>
                </div>
                <div class="trust-item">
                    <div class="trust-number">98%</div>
                    <div class="trust-label">Client Retention Rate</div>
                </div>
                <div class="trust-item">
                    <div class="trust-number">5⭐</div>
                    <div class="trust-label">Average Client Rating</div>
                </div>
            </div>
        </div>
    </section>

    <section class="certifications">
        <div class="container">
            <h2 class="section-title">Certifications & Credentials</h2>
            <div class="cert-grid">
                <div class="cert-card">
                    <h3>🛡️ Insurance Coverage</h3>
                    <p>$5,000,000 General Liability Insurance</p>
                    <p>Full WCB Coverage</p>
                </div>
                <div class="cert-card">
                    <h3>✓ Licensed & Bonded</h3>
                    <p>Licensed Business in BC</p>
                    <p>Bonded for Client Protection</p>
                </div>
                <div class="cert-card">
                    <h3>🌱 Certified Professionals</h3>
                    <p>ISA Certified Arborists on Staff</p>
                    <p>Landscape Industry Certified</p>
                </div>
                <div class="cert-card">
                    <h3>🏆 Industry Recognition</h3>
                    <p>Better Business Bureau Member</p>
                    <p>Vancouver Island Landscape Association</p>
                </div>
            </div>
        </div>
    </section>

    <section class="team-section">
        <div class="container">
            <h2 class="section-title">Our Commitment to You</h2>
            <div class="commitment-text">
                <p>Whether you're a property manager overseeing multiple complexes or a homeowner who wants a beautiful garden, you can count on Mowology to deliver:</p>
                <ul class="commitment-list">
                    <li>✓ Consistent, reliable service on schedule</li>
                    <li>✓ Professional crews who respect your property</li>
                    <li>✓ Quality workmanship on every visit</li>
                    <li>✓ Clear communication and responsiveness</li>
                    <li>✓ Fair pricing with no hidden fees</li>
                    <li>✓ Satisfaction guaranteed on all services</li>
                </ul>
                <p class="final-commitment">We don't just maintain landscapes—we build lasting relationships with our clients based on trust, quality, and exceptional service. That's the Mowology difference.</p>
            </div>
        </div>
    </section>

    <!-- CTA -->
    <section class="cta-section">
        <div class="container">
            <h2>Experience the Mowology Difference</h2>
            <p>Join hundreds of satisfied clients across Metro Vancouver</p>
            <div class="cta-buttons">
                <a href="contact.php" class="btn btn-primary-large">Get Free Quote</a>
                <a href="tel:7788469273" class="btn btn-secondary-large">Call 778-846-9273</a>
            </div>
        </div>
    </section>

<?php require __DIR__ . '/includes/footer.php'; ?>
