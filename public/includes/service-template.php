<?php
/**
 * Service Landing Page Template — Rendering Engine
 * ─────────────────────────────────────────────────
 * Receives a $service array and renders a full conversion-optimized
 * landing page: hero, proof sections, checklist, process, CTA.
 *
 * CMS-ready: when the CMS is built, swap the require of the static
 * data file for a database read that returns the same array shape.
 *
 * Required: bootstrap.php must be loaded BEFORE this file.
 *
 * Expected $service array shape:
 * [
 *   'slug'            => 'hedge-trimming',
 *   'title'           => 'Hedge Trimming & Shaping',
 *   'meta_title'      => 'Hedge Trimming Vancouver | Mowology',
 *   'meta_description' => '...',
 *   'meta_keywords'   => '...',
 *   'og_image'        => '/assets/img/services/hedge-trimming-og.jpg',
 *
 *   'hero' => [
 *     'headline'   => 'Expert Hedge Trimming & Shaping',
 *     'subheadline' => 'Keep your hedges...',
 *     'cta_text'   => 'Get a Free Quote',
 *     'cta_url'    => '/quote?service=hedge_trimming',
 *     'image'      => '/assets/img/services/hedge-trimming-hero.jpg',
 *     'image_alt'  => 'Mowology crew trimming a laurel hedge',
 *   ],
 *
 *   'proof_sections' => [
 *     [
 *       'type'     => 'checklist',       // checklist | before_after | process | benefits
 *       'heading'  => 'What's Included',
 *       'items'    => ['Item 1', 'Item 2', ...],
 *     ],
 *     [
 *       'type'    => 'benefits',
 *       'heading' => 'Why Choose Mowology',
 *       'items'   => [
 *         ['title' => 'Insured', 'desc' => '$5M liability...'],
 *       ],
 *     ],
 *     [
 *       'type'    => 'process',
 *       'heading' => 'How It Works',
 *       'steps'   => [
 *         ['title' => 'Book', 'desc' => 'Request a quote...'],
 *       ],
 *     ],
 *     [
 *       'type'    => 'before_after',
 *       'heading' => 'See the Difference',
 *       'pairs'   => [
 *         ['before' => '/img/before.jpg', 'after' => '/img/after.jpg', 'caption' => '...'],
 *       ],
 *     ],
 *   ],
 *
 *   'faq' => [
 *     ['q' => 'How often...?', 'a' => 'We recommend...'],
 *   ],
 *
 *   'cta' => [
 *     'headline'      => 'Ready for a Quote?',
 *     'subheadline'   => 'Free, no-obligation...',
 *     'primary_text'  => 'Request Free Quote',
 *     'primary_url'   => '/quote?service=hedge_trimming',
 *     'secondary_text' => 'Call 778-846-9273',
 *     'secondary_url' => 'tel:7788469273',
 *   ],
 *
 *   'schema' => [              // optional: JSON-LD structured data
 *     'service_type' => 'Hedge Trimming',
 *     'area_served'  => ['Vancouver', 'Burnaby', 'Richmond'],
 *   ],
 *
 *   'form_presets' => [        // passed to jobFlow via query params
 *     'service'       => 'hedge_trimming',
 *     'property_type' => '',   // leave blank to not pre-select
 *   ],
 * ]
 */

if (!isset($service) || !is_array($service)) {
    header('Location: /services.php');
    exit();
}

/**
 * Safely render text with allowed formatting tags
 * Escapes everything, then restores specific safe HTML tags
 */
function allowFormatting($text) {
    $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    // Restore safe formatting tags
    $text = str_replace(
        ['&lt;em&gt;', '&lt;/em&gt;', '&lt;strong&gt;', '&lt;/strong&gt;', '&lt;br&gt;', '&lt;br /&gt;'],
        ['<em>', '</em>', '<strong>', '</strong>', '<br>', '<br>'],
        $text
    );
    return $text;
}

// ── Page meta ──
$pageTitle       = $service['meta_title']       ?? ($service['title'] . ' | ' . SITE_NAME);
$pageDescription = $service['meta_description'] ?? '';
$pageKeywords    = $service['meta_keywords']    ?? '';
$pageImage       = $service['og_image']         ?? '/assets/img/hero/hero-lawn-care-1920x1080.jpg';
$activeNav       = 'services';

// JSON-LD structured data for local service
$schemaData = $service['schema'] ?? null;
if ($schemaData) {
    $jsonLd = [
        '@context' => 'https://schema.org',
        '@type'    => 'Service',
        'name'     => $schemaData['service_type'] ?? $service['title'],
        'provider' => [
            '@type'     => 'LocalBusiness',
            'name'      => SITE_NAME,
            'telephone' => SITE_PHONE_DISPLAY,
            'email'     => SITE_EMAIL,
            'url'       => SITE_URL,
        ],
        'areaServed' => array_map(function ($area) {
            return ['@type' => 'City', 'name' => $area];
        }, $schemaData['area_served'] ?? ['Vancouver', 'Burnaby', 'Richmond']),
    ];
    $extraHead = '<script type="application/ld+json">' . json_encode($jsonLd, JSON_UNESCAPED_SLASHES) . '</script>';
}

require __DIR__ . '/head.php';
require __DIR__ . '/header.php';

$hero = $service['hero'] ?? [];
$ctaBlock = $service['cta'] ?? [];
?>

  <!-- Service Hero — Above the Fold -->
  <section class="service-landing-hero">
    <div class="container">
      <div class="slh-grid">
        <div class="slh-content">
          <h1><?= allowFormatting($hero['headline'] ?? $service['title']) ?></h1>
          <?php if (!empty($hero['subheadline'])): ?>
            <p class="slh-sub"><?= h($hero['subheadline']) ?></p>
          <?php endif; ?>
          <?php if (!empty($hero['cta_text'])): ?>
            <a href="<?= h($hero['cta_url'] ?? '/quote') ?>" class="btn btn-primary-large"><?= h($hero['cta_text']) ?></a>
          <?php endif; ?>
          <p class="slh-trust">Serving Vancouver, Burnaby & Richmond</p>
        </div>
        <?php if (!empty($hero['image'])): ?>
        <div class="slh-image">
          <img src="<?= h($hero['image']) ?>" alt="<?= h($hero['image_alt'] ?? $service['title']) ?>" loading="eager">
        </div>
        <?php endif; ?>
      </div>
    </div>
  </section>

  <!-- Proof Sections -->
  <?php foreach (($service['proof_sections'] ?? []) as $i => $section): ?>
    <?php $altBg = ($i % 2 === 1) ? ' slp-alt' : ''; ?>

    <?php if ($section['type'] === 'checklist'): ?>
    <section class="slp-section<?= $altBg ?>">
      <div class="container">
        <h2 class="slp-heading"><?= h($section['heading'] ?? 'What\'s Included') ?></h2>
        <?php if (!empty($section['intro'])): ?>
          <p class="slp-intro"><?= h($section['intro']) ?></p>
        <?php endif; ?>
        <ul class="slp-checklist">
          <?php foreach (($section['items'] ?? []) as $item): ?>
            <li><?= h($item) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    </section>

    <?php elseif ($section['type'] === 'benefits'): ?>
    <section class="slp-section<?= $altBg ?>">
      <div class="container">
        <h2 class="slp-heading"><?= h($section['heading'] ?? 'Why Choose Mowology') ?></h2>
        <div class="slp-benefits-grid">
          <?php foreach (($section['items'] ?? []) as $benefit): ?>
            <div class="slp-benefit">
              <strong><?= h($benefit['title']) ?></strong>
              <p><?= h($benefit['desc']) ?></p>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </section>

    <?php elseif ($section['type'] === 'process'): ?>
    <section class="slp-section<?= $altBg ?>">
      <div class="container">
        <h2 class="slp-heading"><?= h($section['heading'] ?? 'How It Works') ?></h2>
        <div class="slp-process">
          <?php foreach (($section['steps'] ?? []) as $stepNum => $step): ?>
            <div class="slp-step">
              <div class="slp-step-num"><?= $stepNum + 1 ?></div>
              <h3><?= h($step['title']) ?></h3>
              <p><?= h($step['desc']) ?></p>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </section>

    <?php elseif ($section['type'] === 'before_after'): ?>
    <section class="slp-section<?= $altBg ?>">
      <div class="container">
        <h2 class="slp-heading"><?= h($section['heading'] ?? 'See the Difference') ?></h2>
        <div class="slp-ba-grid">
          <?php foreach (($section['pairs'] ?? []) as $pair): ?>
            <div class="slp-ba-pair">
              <div class="slp-ba-img">
                <span class="slp-ba-label">Before</span>
                <img src="<?= h($pair['before']) ?>" alt="Before — <?= h($pair['caption'] ?? '') ?>" loading="lazy">
              </div>
              <div class="slp-ba-img">
                <span class="slp-ba-label slp-ba-label--after">After</span>
                <img src="<?= h($pair['after']) ?>" alt="After — <?= h($pair['caption'] ?? '') ?>" loading="lazy">
              </div>
              <?php if (!empty($pair['caption'])): ?>
                <p class="slp-ba-caption"><?= h($pair['caption']) ?></p>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </section>
    <?php endif; ?>

  <?php endforeach; ?>

  <!-- FAQ (if provided) -->
  <?php if (!empty($service['faq'])): ?>
  <section class="slp-section slp-faq">
    <div class="container">
      <h2 class="slp-heading">Frequently Asked Questions</h2>
      <div class="slp-faq-list">
        <?php foreach ($service['faq'] as $faq): ?>
          <details class="slp-faq-item">
            <summary><?= h($faq['q']) ?></summary>
            <p><?= h($faq['a']) ?></p>
          </details>
        <?php endforeach; ?>
      </div>
    </div>
  </section>
  <?php endif; ?>

  <!-- Bottom CTA -->
  <?php if (!empty($ctaBlock)): ?>
  <section class="cta-section">
    <div class="container">
      <h2><?= h($ctaBlock['headline'] ?? 'Ready to Get Started?') ?></h2>
      <?php if (!empty($ctaBlock['subheadline'])): ?>
        <p><?= h($ctaBlock['subheadline']) ?></p>
      <?php endif; ?>
      <div class="cta-buttons">
        <a href="<?= h($ctaBlock['primary_url'] ?? '/quote') ?>" class="btn btn-primary-large"><?= h($ctaBlock['primary_text'] ?? 'Request Free Quote') ?></a>
        <a href="<?= h($ctaBlock['secondary_url'] ?? 'tel:7788469273') ?>" class="btn btn-secondary-large"><?= h($ctaBlock['secondary_text'] ?? 'Call 778-846-9273') ?></a>
      </div>
    </div>
  </section>
  <?php endif; ?>

<?php require __DIR__ . '/footer.php'; ?>
