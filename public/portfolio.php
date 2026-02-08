<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

$pageTitle = 'Our Work | Mowology Portfolio';
$pageDescription = 'View our portfolio of completed landscaping projects in Vancouver, Burnaby, and Richmond. Strata and residential landscape transformations.';
$activeNav = 'portfolio';

// Get portfolio projects from database
$dbProjects = [];
try {
    require_once __DIR__ . '/app_config/config.php';
    $db = getDB();
    $stmt = $db->query("
        SELECT *
        FROM portfolio_projects
        WHERE status = 'published'
        ORDER BY featured DESC, display_order ASC
    ");
    $dbProjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Database connection failed or table doesn't exist yet - continue with empty portfolio
    $dbProjects = [];
}

require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/header.php';
?>

    <section class="page-hero">
        <div class="container">
            <h1>Our Portfolio</h1>
            <p>See the difference professional landscaping makes</p>
        </div>
    </section>

    <section class="portfolio-intro">
        <div class="container">
            <div class="intro-content">
                <h2>Transforming Properties Across Metro Vancouver</h2>
                <p>With years of experience serving property management companies and residential clients, we've completed hundreds of landscaping projects across Vancouver, Burnaby, and Richmond. Each project showcases our commitment to quality, attention to detail, and "higher degree of service."</p>
                <p>Browse our work below to see how we can transform your property.</p>
            </div>
        </div>
    </section>

    <!-- Portfolio Filter -->
    <section class="portfolio-section">
        <div class="container">
            <div class="portfolio-filters">
                <button class="filter-btn active" data-filter="all">All Projects</button>
                <button class="filter-btn" data-filter="Strata & Property Management">Strata & Property Management</button>
                <button class="filter-btn" data-filter="Residential">Residential</button>
                <button class="filter-btn" data-filter="Maintenance">Maintenance</button>
                <button class="filter-btn" data-filter="Design & Installation">Design & Installation</button>
            </div>

            <div class="portfolio-grid">
                <?php if (empty($dbProjects)): ?>
                    <div style="grid-column: 1 / -1; text-align: center; padding: 2rem;">
                        <p style="color: #666; font-size: 16px;">No portfolio projects available yet.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($dbProjects as $project): ?>
                        <?php
                        $categories = json_decode($project['categories'] ?? '[]', true);
                        $categoryClass = implode(' ', $categories);
                        ?>
                        <div class="portfolio-item" data-category="<?php echo htmlspecialchars($categoryClass); ?>">
                            <div class="portfolio-image">
                                <?php
                                $displayImage = null;

                                // Priority: gallery image (first one) > after_image > before_image
                                if (!empty($project['gallery_images'])) {
                                    $galleryImages = json_decode($project['gallery_images'], true);
                                    if (is_array($galleryImages) && count($galleryImages) > 0) {
                                        // Use first gallery image
                                        $displayImage = $galleryImages[0];
                                    }
                                }

                                // Fallback to after_image or before_image
                                if (!$displayImage) {
                                    $displayImage = $project['after_image_path'] ?? $project['before_image_path'];
                                }
                                ?>
                                <?php if ($displayImage): ?>
                                    <img src="<?php echo htmlspecialchars($displayImage); ?>" alt="<?php echo htmlspecialchars($project['project_name']); ?>" style="width: 100%; height: 100%; object-fit: cover;">
                                <?php else: ?>
                                    <div class="placeholder-image" style="background: linear-gradient(135deg, #2d5016 0%, #4a7c2c 100%); display: flex; align-items: center; justify-content: center; color: white; font-size: 48px; height: 100%;">
                                        🌿
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="portfolio-info">
                                <h3><?php echo htmlspecialchars($project['project_name']); ?></h3>
                                <?php if ($project['location']): ?>
                                    <p class="portfolio-location">📍 <?php echo htmlspecialchars($project['location']); ?></p>
                                <?php endif; ?>
                                <?php if ($project['description']): ?>
                                    <p class="portfolio-desc"><?php echo htmlspecialchars($project['description']); ?></p>
                                <?php endif; ?>
                                <?php
                                $tags = json_decode($project['tags'] ?? '[]', true);
                                $allTags = array_merge($categories ?? [], $tags ?? []);
                                if (!empty($allTags)):
                                ?>
                                    <div class="portfolio-tags">
                                        <?php foreach ($allTags as $tag): ?>
                                            <span class="tag"><?php echo htmlspecialchars($tag); ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <!-- Results Section -->
    <section class="results-section">
        <div class="container">
            <h2 class="section-title">Our Track Record</h2>
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number">500+</div>
                    <div class="stat-label">Properties Maintained</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number">98%</div>
                    <div class="stat-label">Client Retention Rate</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number">15+</div>
                    <div class="stat-label">Years Experience</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number">100%</div>
                    <div class="stat-label">Satisfaction Guarantee</div>
                </div>
            </div>
        </div>
    </section>

    <!-- CTA -->
    <section class="cta-section">
        <div class="container">
            <h2>Ready to Transform Your Property?</h2>
            <p>Let's create something beautiful together</p>
            <div class="cta-buttons">
                <a href="contact.php" class="btn btn-primary-large">Get Free Quote</a>
                <a href="tel:7788469273" class="btn btn-secondary-large">Call 778-846-9273</a>
            </div>
        </div>
    </section>

<?php require __DIR__ . '/includes/footer.php'; ?>
