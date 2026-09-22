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

// Before & After transformations: crew-endorsed, manager-approved pairs (BeforeAfterService).
// Rendered server-side with real <img alt> tags so Google Images can index them.
$baPairs = [];
try {
    require_once APP_ROOT . '/Modules/Portfolio/Services/BeforeAfterService.php';
    $baPairs = (new BeforeAfterService(getDB()))->published(24);
} catch (Throwable $e) {
    $baPairs = []; // migration 1118 not run yet, or DB down — the page still renders
}
if ($baPairs) {
    $pageImage = $baPairs[0]['after_url'];
}
// The module's stylesheet is not in the flattened live bundle, so link it here.
$extraHead = '<link rel="stylesheet" href="/assets/css/pages/portfolio.css?v=20260922">';
if ($baPairs) {
    $ld = [
        '@context' => 'https://schema.org',
        '@type'    => 'ImageGallery',
        'name'     => 'Mowology before and after landscaping transformations',
        'url'      => 'https://mowology.ca/portfolio.php',
        'associatedMedia' => [],
    ];
    foreach ($baPairs as $bp) {
        foreach (['before', 'after'] as $side) {
            $ld['associatedMedia'][] = [
                '@type'       => 'ImageObject',
                'contentUrl'  => 'https://mowology.ca' . $bp[$side . '_url'],
                'name'        => $bp['label'] . ' (' . $side . ')',
                'description' => $bp['alt_' . $side],
                'creditText'  => 'Mowology',
            ];
            if ($bp['area'] !== '') {
                $ld['associatedMedia'][count($ld['associatedMedia']) - 1]['contentLocation'] = [
                    '@type' => 'Place', 'name' => $bp['area'] . ', Metro Vancouver, BC',
                ];
            }
        }
    }
    $extraHead .= "\n" . '<script type="application/ld+json">' . json_encode($ld, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>';
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

    <?php if ($baPairs): ?>
    <!-- ── Before & After (crew-endorsed, manager-approved) ────────────── -->
    <section class="mw-ba" id="mw-ba-portfolio">
      <div class="mw-ba__inner">
        <div class="mw-ba__header mw-reveal">
          <span class="mw-label">Real Results</span>
          <h2 class="mw-ba__heading">Before &amp; After</h2>
          <p class="mw-ba__sub">Photographed by our crews on the job, across Metro Vancouver. Tap a card and drag the slider.</p>
        </div>

        <div class="mw-ba__filters mw-reveal">
          <button class="mw-ba__filter-btn is-active" data-filter="all">All Work</button>
          <button class="mw-ba__filter-btn" data-filter="lawn">Lawn</button>
          <button class="mw-ba__filter-btn" data-filter="garden">Garden</button>
          <button class="mw-ba__filter-btn" data-filter="cleanup">Cleanup</button>
          <button class="mw-ba__filter-btn" data-filter="strata">Strata</button>
        </div>

        <div class="mw-ba__grid" id="mw-ba-portfolio-grid">
          <?php foreach ($baPairs as $i => $bp): ?>
          <div class="mw-ba-card is-visible" data-category="<?php echo htmlspecialchars($bp['category']); ?>" data-index="<?php echo $i; ?>"
               data-before="<?php echo htmlspecialchars($bp['before_url']); ?>" data-after="<?php echo htmlspecialchars($bp['after_url']); ?>"
               data-service="<?php echo htmlspecialchars($bp['service']); ?>" data-label="<?php echo htmlspecialchars($bp['label']); ?>" data-date="<?php echo htmlspecialchars($bp['date']); ?>"
               role="button" tabindex="0" aria-label="Open <?php echo htmlspecialchars($bp['label']); ?> comparison">
            <div class="mw-ba-card__after"><img src="<?php echo htmlspecialchars($bp['after_url']); ?>" alt="<?php echo htmlspecialchars($bp['alt_after']); ?>" loading="<?php echo $i < 3 ? 'eager' : 'lazy'; ?>" decoding="async"></div>
            <div class="mw-ba-card__before"><img src="<?php echo htmlspecialchars($bp['before_url']); ?>" alt="<?php echo htmlspecialchars($bp['alt_before']); ?>" loading="<?php echo $i < 3 ? 'eager' : 'lazy'; ?>" decoding="async"></div>
            <div class="mw-ba-card__line"></div>
            <div class="mw-ba-card__handle"><svg viewBox="0 0 24 24" aria-hidden="true"><polyline points="9 18 15 12 9 6"/><polyline points="15 18 9 12 15 6"/></svg></div>
            <div class="mw-ba-card__labels"><span class="mw-ba-card__label mw-ba-card__label--before">Before</span><span class="mw-ba-card__label mw-ba-card__label--after">After</span></div>
            <div class="mw-ba-card__overlay"><div class="mw-ba-card__meta">
              <div class="mw-ba-card__service"><?php echo htmlspecialchars($bp['service']); ?></div>
              <div class="mw-ba-card__label-text"><?php echo htmlspecialchars($bp['label']); ?></div>
              <div class="mw-ba-card__date"><?php echo htmlspecialchars($bp['date']); ?></div>
            </div></div>
            <div class="mw-ba-card__hint"><svg viewBox="0 0 24 24" aria-hidden="true"><polyline points="15 3 21 3 21 9"/><polyline points="9 21 3 21 3 15"/><line x1="21" y1="3" x2="14" y2="10"/><line x1="3" y1="21" x2="10" y2="14"/></svg></div>
          </div>
          <?php endforeach; ?>
        </div>

        <!-- Lightbox -->
        <div class="mw-ba__lightbox" id="mw-ba-portfolio-lb" role="dialog" aria-modal="true" aria-label="Before and after comparison">
          <button class="mw-ba__lb-close" aria-label="Close">
            <svg viewBox="0 0 24 24" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
          </button>
          <div class="mw-ba__lb-slider">
            <div class="mw-ba__lb-before"></div>
            <div class="mw-ba__lb-after"></div>
            <div class="mw-ba__lb-handle">
              <div class="mw-ba__lb-line"></div>
              <div class="mw-ba__lb-grip">
                <svg viewBox="0 0 24 24" aria-hidden="true"><polyline points="15 18 9 12 15 6"/><polyline points="9 18 3 12 9 6"/></svg>
                <svg viewBox="0 0 24 24" aria-hidden="true"><polyline points="9 18 15 12 9 6"/><polyline points="15 18 21 12 15 6"/></svg>
              </div>
            </div>
            <div class="mw-ba__lb-label mw-ba__lb-label--before">Before</div>
            <div class="mw-ba__lb-label mw-ba__lb-label--after">After</div>
          </div>
          <div class="mw-ba__lb-meta"></div>
          <div class="mw-ba__lb-nav">
            <button class="mw-ba__lb-nav-btn mw-ba__lb-prev" aria-label="Previous"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M19 12H5M12 5l-7 7 7 7"/></svg></button>
            <button class="mw-ba__lb-nav-btn mw-ba__lb-next" aria-label="Next"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M12 5l7 7-7 7"/></svg></button>
          </div>
        </div>
        <div class="mw-ba__lb-backdrop" id="mw-ba-portfolio-backdrop"></div>
      </div>
    </section>

    <script>
    (function () {
      'use strict';
      var section = document.getElementById('mw-ba-portfolio');
      if (!section) return;
      var cards = Array.prototype.slice.call(section.querySelectorAll('.mw-ba-card'));
      var lb = document.getElementById('mw-ba-portfolio-lb');
      var backdrop = document.getElementById('mw-ba-portfolio-backdrop');
      var lbSlider = lb.querySelector('.mw-ba__lb-slider');
      var lbBefore = lb.querySelector('.mw-ba__lb-before');
      var lbAfter  = lb.querySelector('.mw-ba__lb-after');
      var lbHandle = lb.querySelector('.mw-ba__lb-handle');
      var lbMeta   = lb.querySelector('.mw-ba__lb-meta');
      var visible = cards.slice();
      var lbIndex = 0;

      // Filters (cards are already in the page; filtering only hides them)
      section.querySelectorAll('.mw-ba__filter-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
          section.querySelectorAll('.mw-ba__filter-btn').forEach(function (b) { b.classList.remove('is-active'); });
          btn.classList.add('is-active');
          var f = btn.dataset.filter;
          visible = [];
          cards.forEach(function (c) {
            var show = f === 'all' || c.dataset.category === f;
            c.style.display = show ? '' : 'none';
            if (show) visible.push(c);
          });
        });
      });

      // Lightbox
      function esc(str) { var d = document.createElement('div'); d.appendChild(document.createTextNode(str || '')); return d.innerHTML; }
      function updateHandle(pct) {
        pct = Math.max(3, Math.min(97, pct));
        lbHandle.style.left = pct + '%';
        lbBefore.style.clipPath = 'inset(0 ' + (100 - pct) + '% 0 0)';
      }
      function load() {
        var c = visible[lbIndex]; if (!c) return;
        lbBefore.style.backgroundImage = "url('" + c.dataset.before + "')";
        lbAfter.style.backgroundImage  = "url('" + c.dataset.after + "')";
        lbMeta.innerHTML = '<div class="mw-ba__lb-meta-service">' + esc(c.dataset.service) + '</div><div class="mw-ba__lb-meta-label">' + esc(c.dataset.label) + '</div><div class="mw-ba__lb-meta-date">' + esc(c.dataset.date) + '</div>';
        updateHandle(50);
      }
      function open(card) {
        lbIndex = Math.max(0, visible.indexOf(card)); load();
        lb.classList.add('is-open'); backdrop.classList.add('is-open');
        document.body.style.overflow = 'hidden';
      }
      function close() {
        lb.classList.remove('is-open'); backdrop.classList.remove('is-open');
        document.body.style.overflow = '';
      }
      function nav(dir) { if (!visible.length) return; lbIndex = (lbIndex + dir + visible.length) % visible.length; load(); }

      cards.forEach(function (c) {
        c.addEventListener('click', function () { open(c); });
        c.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(c); } });
      });
      lb.querySelector('.mw-ba__lb-close').addEventListener('click', close);
      backdrop.addEventListener('click', close);
      lb.querySelector('.mw-ba__lb-prev').addEventListener('click', function () { nav(-1); });
      lb.querySelector('.mw-ba__lb-next').addEventListener('click', function () { nav(1); });
      document.addEventListener('keydown', function (e) {
        if (!lb.classList.contains('is-open')) return;
        if (e.key === 'Escape') close();
        if (e.key === 'ArrowLeft') nav(-1);
        if (e.key === 'ArrowRight') nav(1);
      });

      // Drag the divider
      var dragging = false;
      function moveTo(clientX) { var r = lbSlider.getBoundingClientRect(); updateHandle(((clientX - r.left) / r.width) * 100); }
      lbSlider.addEventListener('mousedown', function (e) { dragging = true; moveTo(e.clientX); });
      window.addEventListener('mousemove', function (e) { if (dragging) moveTo(e.clientX); });
      window.addEventListener('mouseup', function () { dragging = false; });
      lbSlider.addEventListener('touchstart', function (e) { dragging = true; moveTo(e.touches[0].clientX); }, { passive: true });
      lbSlider.addEventListener('touchmove', function (e) { if (dragging) moveTo(e.touches[0].clientX); }, { passive: true });
      lbSlider.addEventListener('touchend', function () { dragging = false; });

      section.querySelectorAll('.mw-reveal').forEach(function (el) { el.classList.add('is-visible'); });
    })();
    </script>
    <?php endif; ?>

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
                                    <img src="<?php echo htmlspecialchars($displayImage); ?>" alt="<?php echo htmlspecialchars($project['project_name']); ?>" style="width: 100%; height: 100%; object-fit: cover;" width="800" height="600" loading="lazy">
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
