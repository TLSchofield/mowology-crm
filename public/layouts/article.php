<?php
/**
 * Article Layout — blog posts (cms_pages rows with slug blog/<slug>).
 *
 * Variables: $page, $blocks (set by cms_renderPage). The body is the page's
 * rich_text block (position 1). Article metadata (author, FAQ, keyword, city,
 * reading time) lives in $page['generated_variables'] — see ArticleService.
 *
 * Styles: /assets/css/pages/blog.css (linked by cms_renderPage for this layout).
 */
require_once APP_ROOT . '/Modules/CMS/Services/ArticleService.php';
require_once PUBLIC_ROOT . '/includes/service-links.php';

$meta      = ArticleService::meta($page);
$author    = trim((string)($meta['author'] ?? ''));
$published = $page['published_at'] ?? $page['publish_at'] ?? $page['created_at'] ?? null;
$updated   = $page['updated_at'] ?? null;
$minutes   = (int)($meta['reading_minutes'] ?? 0);
$showUpdated = $published && $updated && (strtotime((string)$updated) - strtotime((string)$published)) > 86400;

$related = [];
try {
    $related = (new ArticleService(getDB()))->related((int)$page['id'], 3);
} catch (\Throwable $e) {
    $related = [];
}
$services = mw_serviceLinks();
?>

<main role="main" class="cms-page cms-page-article">

  <header class="mw-article-head">
    <div class="container">
      <?= cms_renderBreadcrumbs($page) ?>
      <h1 class="mw-article-title"><?= h($page['title']) ?></h1>
      <?php if (!empty($page['meta_description'])): ?>
        <p class="mw-article-standfirst"><?= h($page['meta_description']) ?></p>
      <?php endif; ?>
      <p class="mw-article-meta">
        <?php if ($author !== ''): ?>
          <span class="mw-article-author">By <?= h($author) ?>, <?= h(SITE_NAME) ?></span>
        <?php else: ?>
          <span class="mw-article-author">By the <?= h(SITE_NAME) ?> team</span>
        <?php endif; ?>
        <?php if ($published): ?>
          <span aria-hidden="true">·</span>
          <time datetime="<?= h(date('Y-m-d', strtotime((string)$published))) ?>">Published <?= h(date('F j, Y', strtotime((string)$published))) ?></time>
        <?php endif; ?>
        <?php if ($showUpdated): ?>
          <span aria-hidden="true">·</span>
          <time datetime="<?= h(date('Y-m-d', strtotime((string)$updated))) ?>">Updated <?= h(date('F j, Y', strtotime((string)$updated))) ?></time>
        <?php endif; ?>
        <?php if ($minutes > 0): ?>
          <span aria-hidden="true">·</span>
          <span><?= $minutes ?> min read</span>
        <?php endif; ?>
      </p>
    </div>
  </header>

  <?php if (!empty($page['og_image_path'])): ?>
  <figure class="mw-article-hero">
    <div class="container">
      <img src="<?= h($page['og_image_path']) ?>" alt="<?= h($page['title']) ?>" width="1200" height="630" fetchpriority="high">
    </div>
  </figure>
  <?php endif; ?>

  <article class="mw-article-body">
    <?= cms_renderSections($blocks, 1) ?>
  </article>

  <section class="cta-section mw-article-cta">
    <div class="container">
      <h2>Want this handled at your property?</h2>
      <p>Free, no-obligation quotes for strata, commercial and residential properties in Vancouver, Burnaby and Richmond.</p>
      <div class="cta-buttons">
        <a href="/quote?src=blog" class="btn btn-primary-large">Request a Free Quote</a>
        <a href="tel:<?= h(SITE_PHONE_TEL) ?>" class="btn btn-secondary-large">Call <?= h(SITE_PHONE_DISPLAY) ?></a>
      </div>
    </div>
  </section>

  <?php if ($services): ?>
  <section class="slp-section slp-alt mw-article-services">
    <div class="container">
      <h2 class="slp-heading">Our Services</h2>
      <div class="slp-benefits-grid">
        <?php foreach ($services as $svc): ?>
          <div class="slp-benefit">
            <strong><a href="<?= h($svc['url']) ?>"><?= h($svc['title']) ?></a></strong>
            <?php if ($svc['desc'] !== ''): ?><p><?= h($svc['desc']) ?></p><?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </section>
  <?php endif; ?>

  <?php if ($related): ?>
  <section class="slp-section mw-article-related">
    <div class="container">
      <h2 class="slp-heading">More Tips &amp; Guides</h2>
      <div class="mw-blog-grid">
        <?php foreach ($related as $r): ?>
          <a class="mw-blog-card" href="/<?= h($r['slug']) ?>">
            <h3><?= h($r['title']) ?></h3>
            <p><?= h($r['excerpt']) ?></p>
            <span class="mw-blog-card__more">Read more &rarr;</span>
          </a>
        <?php endforeach; ?>
      </div>
      <p class="mw-blog-all"><a href="/blog">All tips &amp; guides &rarr;</a></p>
    </div>
  </section>
  <?php endif; ?>

</main>
