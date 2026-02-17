<?php
/**
 * CMS Page Seeder — One-time migration of static public pages into CMS database
 *
 * Seeds: Home, About, Services, Contact as editable CMS pages with blocks.
 * Safe to run multiple times — checks for existing pages by slug before inserting.
 *
 * @package Mowology CRM
 * @subpackage CMS
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/cms-functions.php';

requireLogin();
$user = getCurrentUser();

if (!isAdmin()) {
    die('Admin access required.');
}

$pageTitle = 'Seed CMS Pages';
$activePage = 'cms';

$messages = [];
$errors = [];

// Handle POST — run seeder
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';

    if ($action === 'seed') {
        $db = getDB();

        // Register area_cards block type if not exists
        $existing = $db->prepare("SELECT id FROM cms_block_types WHERE block_type = 'area_cards'");
        $existing->execute();
        if (!$existing->fetch()) {
            $db->prepare("
                INSERT INTO cms_block_types (block_type, label, description, schema_json, is_active)
                VALUES ('area_cards', 'Area Cards', 'Grid of area/location cards with neighborhoods', '{}', 1)
            ")->execute();
            $messages[] = 'Registered area_cards block type.';
        }

        // ── Page definitions ──
        $pages = getPageDefinitions();

        foreach ($pages as $pageDef) {
            // Check if page already exists
            $check = $db->prepare("SELECT id FROM cms_pages WHERE slug = ?");
            $check->execute([$pageDef['slug']]);
            $existingPage = $check->fetch();

            if ($existingPage) {
                $messages[] = "Skipped '{$pageDef['title']}' — already exists (slug: {$pageDef['slug']}).";
                continue;
            }

            try {
                // Insert page
                $db->prepare("
                    INSERT INTO cms_pages (slug, title, meta_title, meta_description, meta_keywords, page_type, layout_template, status, created_by, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'published', ?, NOW(), NOW())
                ")->execute([
                    $pageDef['slug'],
                    $pageDef['title'],
                    $pageDef['meta_title'],
                    $pageDef['meta_description'],
                    $pageDef['meta_keywords'] ?? '',
                    $pageDef['page_type'],
                    $pageDef['layout_template'],
                    $user['id'],
                ]);
                $newPageId = (int)$db->lastInsertId();

                // Insert blocks
                foreach ($pageDef['blocks'] as $position => $blockDef) {
                    $configJson = json_encode($blockDef['config'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    $db->prepare("
                        INSERT INTO cms_blocks (page_id, block_type, position, config_json, created_at, updated_at)
                        VALUES (?, ?, ?, ?, NOW(), NOW())
                    ")->execute([
                        $newPageId,
                        $blockDef['type'],
                        $position,
                        $configJson,
                    ]);
                }

                $messages[] = "Seeded '{$pageDef['title']}' with " . count($pageDef['blocks']) . " blocks (ID: {$newPageId}).";
            } catch (Exception $e) {
                $errors[] = "Error seeding '{$pageDef['title']}': " . $e->getMessage();
            }
        }
    }
}

$csrfToken = generateCSRFToken();

// ── Page definitions function ──
function getPageDefinitions(): array
{
    return [
        // ──────────── HOME PAGE ────────────
        [
            'slug' => 'home',
            'title' => 'Home',
            'meta_title' => 'Mowology | Professional Landscaping & Grounds Maintenance | Vancouver, Burnaby, Richmond',
            'meta_description' => 'Professional landscaping and grounds maintenance services in Vancouver, Burnaby & Richmond. Specializing in strata properties and residential gardens.',
            'meta_keywords' => 'landscaping Vancouver, strata landscaping, property management landscaping, residential landscaping, grounds maintenance, Burnaby landscaping, Richmond landscaping',
            'page_type' => 'home',
            'layout_template' => 'homepage',
            'blocks' => [
                [
                    'type' => 'hero',
                    'config' => [
                        'headline' => 'Professional Landscaping Services in Metro Vancouver',
                        'subheadline' => 'Expert grounds maintenance for property management companies and residential properties in Vancouver, Burnaby & Richmond',
                        'cta_text' => 'Get Free Quote',
                        'cta_url' => '/jobFlow/jobFlow-getQuote.php',
                        'secondary_cta_text' => 'Our Services',
                        'secondary_cta_url' => '/services',
                        'hero_style' => 'image_bg',
                        'image' => '/assets/img/hero/hero-lawn-care-1920x1080.jpg',
                        'image_tablet' => '/assets/img/hero/hero-lawn-care-1024x768.jpg',
                        'image_mobile' => '/assets/img/hero/hero-lawn-care-768x1024.jpg',
                        'media_alt' => 'Professional landscaping services in Vancouver',
                    ],
                ],
                [
                    'type' => 'service_cards',
                    'config' => [
                        'title' => 'Who We Serve',
                        'services' => [
                            [
                                'icon' => '🏢',
                                'title' => 'Property Management & Strata',
                                'description' => 'Reliable, photo-verified landscaping for townhomes, condos, and multi-unit complexes. Weekly maintenance, seasonal cleanups, and emergency storm response by insured, strata-dedicated crews.',
                                'url' => '/services#property-management',
                            ],
                            [
                                'icon' => '🏡',
                                'title' => 'Residential Properties',
                                'description' => 'Professional garden care and landscape maintenance for homeowners who want beautiful, well-maintained outdoor spaces. From weekly lawn care to seasonal cleanups and garden design.',
                                'url' => '/services#residential',
                            ],
                        ],
                    ],
                ],
                [
                    'type' => 'feature_grid',
                    'config' => [
                        'title' => 'Why Choose Mowology',
                        'style' => 'features',
                        'features' => [
                            ['icon' => '⭐', 'title' => 'Higher Standards', 'description' => 'We deliver a higher degree of service with attention to detail and consistent quality.'],
                            ['icon' => '📸', 'title' => 'Photo Verification', 'description' => 'Every service includes photo documentation so you know the work was completed to standard.'],
                            ['icon' => '🛡️', 'title' => 'Fully Insured', 'description' => 'All crews are fully insured and trained in proper safety and landscaping techniques.'],
                            ['icon' => '💼', 'title' => 'Professional Team', 'description' => 'Experienced landscaping professionals who take pride in their work and your property.'],
                            ['icon' => '⚡', 'title' => 'Reliable Service', 'description' => 'Consistent scheduling and dependable service delivery, rain or shine.'],
                            ['icon' => '🌱', 'title' => 'Eco-Friendly', 'description' => 'Sustainable practices and environmentally conscious maintenance methods.'],
                        ],
                    ],
                ],
                [
                    'type' => 'area_cards',
                    'config' => [
                        'title' => 'Service Areas',
                        'subtitle' => 'Proudly serving Metro Vancouver communities',
                        'areas' => [
                            ['name' => 'Vancouver', 'neighborhoods' => 'Downtown, West End, Kitsilano, Point Grey, Dunbar, Kerrisdale, Mount Pleasant, Commercial Drive'],
                            ['name' => 'Burnaby', 'neighborhoods' => 'Metrotown, Brentwood, Lougheed, Deer Lake, Edmonds, Capitol Hill, Heights'],
                            ['name' => 'Richmond', 'neighborhoods' => 'Richmond Centre, Steveston, Brighouse, City Centre, Hamilton, Terra Nova'],
                        ],
                    ],
                ],
                [
                    'type' => 'testimonials',
                    'config' => [
                        'title' => 'What Our Clients Say',
                        'testimonials' => [
                            ['name' => 'Linda N.', 'role' => 'Strata President', 'content' => "I've lived here for over 30 years and I've never seen our gardens look this good.", 'rating' => 5],
                            ['name' => 'Colleen Jiang', 'role' => 'Home Owner', 'content' => 'Mowology offers professional knowledge and excellent service for my garden. They are very nice. They did a very good maintenance job and making my garden looks beautiful again. I would like to recommend to all my friends.', 'rating' => 5],
                            ['name' => 'Michael T.', 'role' => 'Property Manager', 'content' => 'Professional, reliable, and always on time. The photo reports give us peace of mind that the work is being done right.', 'rating' => 5],
                        ],
                    ],
                ],
                [
                    'type' => 'cta',
                    'config' => [
                        'headline' => "Ready to Elevate Your Property's Landscape?",
                        'subheadline' => 'Get a free, no-obligation quote for your property',
                        'primary_text' => 'Request Free Quote',
                        'primary_url' => '/jobFlow/jobFlow-getQuote.php',
                        'secondary_text' => 'Call 778-846-9273',
                        'secondary_url' => 'tel:7788469273',
                    ],
                ],
            ],
        ],

        // ──────────── ABOUT PAGE ────────────
        [
            'slug' => 'about',
            'title' => 'About Us',
            'meta_title' => 'About Us | Mowology Landscaping',
            'meta_description' => "Learn about Mowology - Metro Vancouver's trusted landscaping company serving property management and residential clients since 2010.",
            'meta_keywords' => '',
            'page_type' => 'about',
            'layout_template' => 'default',
            'blocks' => [
                [
                    'type' => 'hero',
                    'config' => [
                        'headline' => 'About Mowology',
                        'subheadline' => 'A higher degree of service in landscaping',
                        'hero_style' => 'text_only',
                    ],
                ],
                [
                    'type' => 'rich_text',
                    'config' => [
                        'content' => "<h2>Our Story</h2>\n<p class=\"lead-text\">Mowology was founded on a simple principle: landscaping services should be reliable, professional, and exceed expectations. Since 2010, we've been serving property management companies and residential clients across Metro Vancouver with that commitment.</p>\n<p>What started as a small team with a few lawn mowers has grown into one of Vancouver's most trusted landscaping companies. But despite our growth, our core values remain the same: quality work, reliable service, and treating every property like it's our own.</p>\n<p>Our name \"Mowology\" reflects our belief that there's a science to great landscaping. It's not just about cutting grass or trimming hedges — it's about understanding plants, soil conditions, seasonal patterns, and how to create outdoor spaces that thrive year-round.</p>",
                    ],
                ],
                [
                    'type' => 'feature_grid',
                    'config' => [
                        'title' => 'Our Mission & Values',
                        'style' => 'features',
                        'features' => [
                            ['icon' => '🎯', 'title' => 'Our Mission', 'description' => 'To deliver exceptional landscaping services that enhance property value, create beautiful outdoor spaces, and exceed client expectations through consistent quality and reliable service.'],
                            ['icon' => '⭐', 'title' => 'Quality First', 'description' => 'We never cut corners. Every job is completed to the highest standards with attention to detail and professional craftsmanship.'],
                            ['icon' => '🤝', 'title' => 'Reliability', 'description' => 'We show up when promised, do what we say, and maintain consistent service standards rain or shine.'],
                            ['icon' => '💚', 'title' => 'Environmental Stewardship', 'description' => 'We use sustainable practices, minimize waste, and promote healthy gardens that benefit local ecosystems.'],
                            ['icon' => '🔧', 'title' => 'Professionalism', 'description' => 'Our team is trained, insured, and equipped with professional-grade tools to deliver superior results.'],
                            ['icon' => '📈', 'title' => 'Continuous Improvement', 'description' => 'We invest in training, new techniques, and equipment to stay at the forefront of the landscaping industry.'],
                        ],
                    ],
                ],
                [
                    'type' => 'feature_grid',
                    'config' => [
                        'title' => 'What Makes Us Different',
                        'style' => 'benefits',
                        'features' => [
                            ['title' => '📸 Photo Documentation', 'description' => 'Every service visit includes photo reports so property managers and homeowners can verify work completion and quality.'],
                            ['title' => '👥 Dedicated Teams', 'description' => 'We assign the same crew to your property each visit so they learn your specific needs and preferences.'],
                            ['title' => '🎓 Trained Professionals', 'description' => 'Ongoing training in pruning techniques, plant identification, equipment operation, and safety protocols.'],
                            ['title' => '🛡️ Fully Insured', 'description' => '$5 million in liability insurance and full WCB coverage for your protection.'],
                            ['title' => '⚡ Emergency Response', 'description' => '24/7 emergency response for urgent situations like storm damage and fallen trees.'],
                            ['title' => '💼 Account Managers', 'description' => 'Property management clients get a dedicated account manager as your single point of contact.'],
                        ],
                    ],
                ],
                [
                    'type' => 'rich_text',
                    'config' => [
                        'content' => "<h2 style=\"text-align:center\">Our Commitment to You</h2>\n<p>Whether you're a property manager overseeing multiple complexes or a homeowner who wants a beautiful garden, you can count on Mowology to deliver:</p>\n<ul>\n<li>Consistent, reliable service on schedule</li>\n<li>Professional crews who respect your property</li>\n<li>Quality workmanship on every visit</li>\n<li>Clear communication and responsiveness</li>\n<li>Fair pricing with no hidden fees</li>\n<li>Satisfaction guaranteed on all services</li>\n</ul>\n<p>We don't just maintain landscapes — we build lasting relationships with our clients based on trust, quality, and exceptional service. That's the Mowology difference.</p>",
                    ],
                ],
                [
                    'type' => 'cta',
                    'config' => [
                        'headline' => 'Experience the Mowology Difference',
                        'subheadline' => 'Join hundreds of satisfied clients across Metro Vancouver',
                        'primary_text' => 'Get Free Quote',
                        'primary_url' => '/contact',
                        'secondary_text' => 'Call 778-846-9273',
                        'secondary_url' => 'tel:7788469273',
                    ],
                ],
            ],
        ],

        // ──────────── SERVICES PAGE ────────────
        [
            'slug' => 'services',
            'title' => 'Our Services',
            'meta_title' => 'Our Services | Mowology Landscaping',
            'meta_description' => 'Complete landscaping services for property management and residential clients. Weekly maintenance, seasonal cleanups, hedge trimming, and more.',
            'meta_keywords' => '',
            'page_type' => 'services',
            'layout_template' => 'default',
            'blocks' => [
                [
                    'type' => 'hero',
                    'config' => [
                        'headline' => 'Our Services',
                        'subheadline' => 'Professional landscaping solutions tailored to your needs',
                        'hero_style' => 'text_only',
                    ],
                ],
                [
                    'type' => 'feature_grid',
                    'config' => [
                        'title' => 'Property Management & Strata Services',
                        'description' => 'Comprehensive landscaping solutions designed specifically for strata councils, property managers, and multi-unit residential complexes.',
                        'style' => 'benefits',
                        'features' => [
                            ['title' => '📸 Photo Verification', 'description' => 'Every service visit includes timestamped photos so you have proof of completed work.'],
                            ['title' => '👤 Dedicated Account Manager', 'description' => 'One point of contact who knows your property and responds quickly to concerns.'],
                            ['title' => '⚡ Emergency Response', 'description' => '24/7 availability for storm damage, fallen trees, and urgent maintenance needs.'],
                            ['title' => '📊 Detailed Reporting', 'description' => 'Monthly reports and seasonal planning to keep your board informed.'],
                            ['title' => '🛡️ Full Insurance', 'description' => '$5M liability coverage and WCB compliance for your peace of mind.'],
                            ['title' => '💰 Budget Planning', 'description' => 'Annual service agreements with predictable costs and no surprise fees.'],
                        ],
                    ],
                ],
                [
                    'type' => 'feature_grid',
                    'config' => [
                        'title' => 'Residential Landscaping Services',
                        'description' => 'Personalized garden care and landscape maintenance for homeowners who want beautiful outdoor spaces without the work.',
                        'style' => 'benefits',
                        'features' => [
                            ['title' => '🌱 Garden Expertise', 'description' => 'Our team knows plants, soil, and proper pruning techniques to keep your garden thriving.'],
                            ['title' => '📅 Flexible Scheduling', 'description' => 'Weekly, bi-weekly, or monthly service plans that fit your needs and budget.'],
                            ['title' => '💬 Direct Communication', 'description' => 'Text or call your crew leader directly with questions or special requests.'],
                            ['title' => '🎨 Design Consultation', 'description' => 'Free advice on plant selection, garden improvements, and seasonal color.'],
                            ['title' => '✅ Consistent Quality', 'description' => 'Same crew every visit who learns your preferences and property details.'],
                            ['title' => '🏆 Satisfaction Guarantee', 'description' => "If you're not happy with any service, we'll make it right at no charge."],
                        ],
                    ],
                ],
                [
                    'type' => 'feature_grid',
                    'config' => [
                        'title' => 'Comprehensive Service Capabilities',
                        'style' => 'features',
                        'features' => [
                            ['icon' => '🌿', 'title' => 'Lawn Care', 'description' => 'Professional mowing, edging, fertilization, aeration, and weed control.'],
                            ['icon' => '✂️', 'title' => 'Pruning & Trimming', 'description' => 'Hedge trimming, shrub pruning, small tree pruning, and rose care.'],
                            ['icon' => '🌸', 'title' => 'Garden Maintenance', 'description' => 'Weeding, mulching, deadheading, plant health monitoring, and soil amendment.'],
                            ['icon' => '🍂', 'title' => 'Seasonal Services', 'description' => 'Spring preparation, annual planting, fall cleanup, and winter storm response.'],
                            ['icon' => '🌳', 'title' => 'Planting & Design', 'description' => 'Garden design, plant installation, perennial gardens, and native plants.'],
                            ['icon' => '🧹', 'title' => 'Cleanup Services', 'description' => 'Debris removal, pressure washing, gutter cleaning, and storm damage cleanup.'],
                        ],
                    ],
                ],
                [
                    'type' => 'cta',
                    'config' => [
                        'headline' => 'Ready to Get Started?',
                        'subheadline' => 'Request a free quote and see how we can transform your property',
                        'primary_text' => 'Get Free Quote',
                        'primary_url' => '/quote',
                        'secondary_text' => 'Call 778-846-9273',
                        'secondary_url' => 'tel:7788469273',
                    ],
                ],
            ],
        ],

        // ──────────── CONTACT PAGE ────────────
        [
            'slug' => 'contact',
            'title' => 'Contact Us',
            'meta_title' => 'Contact Us | Mowology Landscaping',
            'meta_description' => 'Contact Mowology for professional landscaping services. Get a free quote for your property in Vancouver, Burnaby, or Richmond.',
            'meta_keywords' => '',
            'page_type' => 'contact',
            'layout_template' => 'default',
            'blocks' => [
                [
                    'type' => 'hero',
                    'config' => [
                        'headline' => 'Get Your Free Quote',
                        'subheadline' => "Let's discuss how we can enhance your property",
                        'hero_style' => 'text_only',
                    ],
                ],
                [
                    'type' => 'rich_text',
                    'config' => [
                        'content' => "<div class=\"contact-grid\">\n<div class=\"contact-info-card\">\n<h3>Contact Information</h3>\n<p><strong>Phone:</strong> <a href=\"tel:7788469273\">778-846-9273</a><br>Mon - Fri: 8:00 AM - 4:00 PM</p>\n<p><strong>Email:</strong> <a href=\"mailto:office@mowology.ca\">office@mowology.ca</a><br>We respond within 24 hours</p>\n<p><strong>Service Areas:</strong><br>Vancouver, Burnaby, Richmond</p>\n</div>\n<div class=\"contact-info-card\">\n<h3>Office Hours</h3>\n<p>Monday - Friday: 8:00 AM - 4:00 PM<br>Saturday: By Appointment<br>Sunday: Closed<br>Emergency services available 24/7</p>\n</div>\n</div>",
                    ],
                ],
                [
                    'type' => 'cta',
                    'config' => [
                        'headline' => 'Prefer to Talk?',
                        'subheadline' => "We'd love to hear from you directly",
                        'primary_text' => 'Call Now: 778-846-9273',
                        'primary_url' => 'tel:7788469273',
                        'secondary_text' => 'Email Us',
                        'secondary_url' => 'mailto:office@mowology.ca',
                    ],
                ],
            ],
        ],
    ];
}
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

          <div class="d-flex justify-content-between align-items-center mb-4">
              <div>
                  <a href="/crm/cms-pages_appstack.php" class="text-muted small">&larr; Back to CMS</a>
                  <h1 class="h3 mb-0 mt-1">Seed CMS Pages</h1>
                  <p class="text-muted">Import static public pages (Home, About, Services, Contact) into the CMS database.</p>
              </div>
          </div>

          <?php foreach ($messages as $msg): ?>
              <div class="alert alert-success"><?php echo h($msg); ?></div>
          <?php endforeach; ?>
          <?php foreach ($errors as $err): ?>
              <div class="alert alert-danger"><?php echo h($err); ?></div>
          <?php endforeach; ?>

          <div class="card mb-4">
              <div class="card-header"><h5 class="mb-0">What This Does</h5></div>
              <div class="card-body">
                  <ul class="mb-3">
                      <li>Creates CMS page records for: <strong>Home, About, Services, Contact</strong></li>
                      <li>Populates each page with content blocks matching the current static content</li>
                      <li>Sets pages as <span class="badge badge-success">published</span> immediately</li>
                      <li>Registers the <code>area_cards</code> block type if not already registered</li>
                      <li><strong>Safe to run multiple times</strong> — skips pages that already exist</li>
                  </ul>
                  <p class="text-muted small mb-0">After seeding, you'll need to add the CMS-first check to each static page file to activate CMS rendering. The static files serve as fallback if the CMS page is unpublished.</p>
              </div>
          </div>

          <div class="card">
              <div class="card-body text-center">
                  <form method="post">
                      <input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>">
                      <input type="hidden" name="action" value="seed">
                      <button type="submit" class="btn btn-lg btn-primary" onclick="return confirm('Seed all 4 pages into the CMS database?')">
                          <i data-feather="database" class="mr-2" style="width:20px;height:20px;"></i>
                          Seed Pages Now
                      </button>
                  </form>
              </div>
          </div>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
