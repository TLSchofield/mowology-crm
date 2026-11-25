<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

$pageTitle = 'About Us | Mowology Landscaping';
$pageDescription = "Mowology is a small, owner-run landscaping crew serving property management and residential clients in Metro Vancouver since " . SITE_FOUNDED . ".";
$activeNav = 'about';

require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/header.php';
?>

    <section class="page-hero">
        <div class="container">
            <h1>About Mowology</h1>
            <p>A small crew that does the work itself</p>
        </div>
    </section>

    <section class="about-intro">
        <div class="container">
            <div class="about-content">
                <h2>Our Story</h2>
                <p class="lead-text">Mowology is a small, owner-run landscaping crew. Since <?= SITE_FOUNDED ?> we have looked after properties for property management companies and homeowners across Metro Vancouver.</p>
                
                <p>We started as a small team with a few lawn mowers. We are still small on purpose. You deal with the people who do the work, and we treat every property as if it were our own.</p>

                <p>The name Mowology comes from a belief that there is a science to good landscaping. It is more than cutting grass and trimming hedges. It means knowing plants, soil and the seasons, so a property does well all year.</p>
            </div>
        </div>
    </section>

    <section class="mission-values">
        <div class="container">
            <h2 class="section-title">How We Work</h2>
            <div class="values-grid">
                <div class="value-card">
                    <h3>Small on purpose</h3>
                    <p>We are a small crew. You reach the people who do the work, not a call centre.</p>
                </div>
                <div class="value-card">
                    <h3>We do the job properly</h3>
                    <p>We do not cut corners. Every job is finished the way we would want it done at our own place.</p>
                </div>
                <div class="value-card">
                    <h3>We do what we say</h3>
                    <p>We show up when we promise, and we keep the same standard rain or shine.</p>
                </div>
                <div class="value-card">
                    <h3>Easy on the garden</h3>
                    <p>We use sustainable practices, keep waste down and grow healthy gardens that help local ecosystems.</p>
                </div>
                <div class="value-card">
                    <h3>Trained and insured</h3>
                    <p>The team is trained and insured, and we work with professional-grade tools.</p>
                </div>
                <div class="value-card">
                    <h3>We keep learning</h3>
                    <p>We keep learning new techniques and keep our equipment up to date.</p>
                </div>
            </div>
        </div>
    </section>

    <section class="what-makes-different">
        <div class="container">
            <h2 class="section-title">What Makes Us Different</h2>
            <div class="difference-grid">
                <div class="difference-item">
                    <h3>Photo reports</h3>
                    <p>We send photos after every visit, so property managers and homeowners can see the work was done.</p>
                </div>
                <div class="difference-item">
                    <h3>Same crew each visit</h3>
                    <p>We send the same crew to your property each visit. They learn what it needs, and the service gets better over time.</p>
                </div>
                <div class="difference-item">
                    <h3>Trained crew</h3>
                    <p>The team gets ongoing training in pruning, plant identification, equipment and safety.</p>
                </div>
                <div class="difference-item">
                    <h3>Fully insured</h3>
                    <p>We carry $5 million in liability insurance and full WCB coverage.</p>
                </div>
                <div class="difference-item">
                    <h3>Emergency response</h3>
                    <p>Storm damage or a fallen tree? Call us. We respond within 24 hours, especially for property management clients.</p>
                </div>
                <div class="difference-item">
                    <h3>One person to call</h3>
                    <p>Property management clients get one account manager for scheduling, concerns and planning.</p>
                </div>
            </div>
        </div>
    </section>

    <section class="why-trust">
        <div class="container">
            <h2 class="section-title">Why Clients Trust Us</h2>
            <div class="trust-features">
                <div class="trust-item">
                    <div class="trust-number"><?= SITE_YEARS_IN_BUSINESS ?>+</div>
                    <div class="trust-label">Years Serving Metro Vancouver</div>
                </div>

                <div class="trust-item">
                    <div class="trust-number">$5M</div>
                    <div class="trust-label">Liability Coverage, Plus Full WCB</div>
                </div>
                <div class="trust-item">
                    <div class="trust-number">Every</div>
                    <div class="trust-label">Visit Documented With a Photo Report</div>
                </div>
            </div>
        </div>
    </section>

    <section class="team-section">
        <div class="container">
            <h2 class="section-title">What You Can Count On</h2>
            <div class="commitment-text">
                <p>Whether you manage several complexes or have one garden at home, this is what you get:</p>
                <ul class="commitment-list">
                    <li>✓ Service on the schedule we agreed</li>
                    <li>✓ A crew that respects your property</li>
                    <li>✓ Good work on every visit</li>
                    <li>✓ Quick answers when you call or write</li>
                    <li>✓ Fair pricing with no hidden fees</li>
                    <li>✓ Satisfaction guaranteed on all services</li>
                </ul>
                <p class="final-commitment">We would rather keep a client for years than win a new one every season. That means doing the work well and answering when you call.</p>
            </div>
        </div>
    </section>

    <!-- CTA -->
    <section class="cta-section">
        <div class="container">
            <h2>Tell Us About Your Property</h2>
            <p>Free quote, no obligation.</p>
            <div class="cta-buttons">
                <a href="contact.php" class="btn btn-primary-large">Get Free Quote</a>
                <a href="tel:7788469273" class="btn btn-secondary-large">Call 778-846-9273</a>
            </div>
        </div>
    </section>

<?php require __DIR__ . '/includes/footer.php'; ?>
