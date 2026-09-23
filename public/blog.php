<?php
/**
 * Blog index — /blog
 * Lists published articles (CMS pages under blog/, see ArticleService).
 * Reached through cms-render.php's legacy fallback map when no CMS page
 * claims the "blog" slug.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/crm/includes/cms-functions.php';
require_once APP_ROOT . '/Modules/CMS/Services/ArticleService.php';

$perPage = 12;
// NB: the CMS catch-all rewrite already uses ?page= for the slug (QSA), so pagination is ?p=
$pageNum = max(1, (int)($_GET['p'] ?? 1));

$articles = [];
$total    = 0;
try {
    $svc      = new ArticleService(getDB());
    $total    = $svc->countPublished();
    $articles = $svc->listPublished($perPage, ($pageNum - 1) * $perPage);
} catch (\Throwable $e) {
    error_log('blog index: ' . $e->getMessage());
}
$lastPage = max(1, (int)ceil($total / $perPage));
if ($pageNum > $lastPage) {
    header('HTTP/1.1 404 Not Found');
    $pageNum = $lastPage;
}

$pageTitle       = 'Landscaping Tips & Guides for Metro Vancouver | Mowology';
$pageDescription = 'Practical guides on strata and commercial landscaping, lawn care, hedges and seasonal property care in Vancouver, Burnaby and Richmond, from the Mowology crew.';
$activeNav       = 'blog';
$canonicalPath   = '/blog' . ($pageNum > 1 ? '?p=' . $pageNum : '');
$extraHead       = '<link rel="stylesheet" href="' . h(asset('/assets/css/pages/blog.css')) . '">' . "\n"
                 . '<link rel="alternate" type="application/rss+xml" title="' . h(SITE_NAME) . ' — Tips & Guides" href="' . h(SITE_URL) . '/blog/feed">' . "\n";
if ($pageNum > 1) {
    $extraHead .= '<link rel="prev" href="' . h(SITE_URL . '/blog' . ($pageNum > 2 ? '?p=' . ($pageNum - 1) : '')) . '">' . "\n";
}
if ($pageNum < $lastPage) {
    $extraHead .= '<link rel="next" href="' . h(SITE_URL . '/blog?p=' . ($pageNum + 1)) . '">' . "\n";
}
$extraHead .= '<script type="application/ld+json">' . json_encode([
    '@context' => 'https://schema.org',
    '@type'    => 'Blog',
    'name'     => SITE_NAME . ' Tips & Guides',
    'url'      => SITE_URL . '/blog',
    'publisher' => ['@id' => SITE_URL . '/#business'],
    'blogPost' => array_map(fn($a) => [
        '@type'    => 'BlogPosting',
        'headline' => $a['title'],
        'url'      => SITE_URL . '/' . $a['slug'],
        'datePublished' => date('c', strtotime((string)($a['published_at'] ?? $a['created_at']))),
    ], $articles),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>';

require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/header.php';
?>

  <section class="page-hero">
    <div class="container">
      <h1>Landscaping Tips &amp; Guides</h1>
      <p>What we've learned maintaining hundreds of strata, commercial and residential properties across Metro Vancouver since <?= SITE_FOUNDED ?>.</p>
    </div>
  </section>

  <section class="slp-section mw-blog-index">
    <div class="container">
      <?php if (!$articles): ?>
        <div class="mw-blog-empty">
          <h2>First guides are on the way</h2>
          <p>We're writing up the questions strata councils, property managers and homeowners ask us most. In the meantime, see what we do:</p>
          <p><a class="btn btn-primary-large" href="/services">Our Services</a></p>
        </div>
      <?php else: ?>
        <div class="mw-blog-grid">
          <?php foreach ($articles as $a):
              $when = $a['published_at'] ?? $a['created_at'] ?? null;
              $mins = (int)($a['meta']['reading_minutes'] ?? 0); ?>
            <a class="mw-blog-card" href="/<?= h($a['slug']) ?>">
              <?php if (!empty($a['og_image_path'])): ?>
                <img src="<?= h($a['og_image_path']) ?>" alt="" loading="lazy" width="600" height="315">
              <?php endif; ?>
              <h2><?= h($a['title']) ?></h2>
              <p><?= h($a['excerpt']) ?></p>
              <span class="mw-blog-card__meta">
                <?php if ($when): ?><time datetime="<?= h(date('Y-m-d', strtotime((string)$when))) ?>"><?= h(date('F j, Y', strtotime((string)$when))) ?></time><?php endif; ?>
                <?php if ($mins): ?> · <?= $mins ?> min read<?php endif; ?>
              </span>
            </a>
          <?php endforeach; ?>
        </div>

        <?php if ($lastPage > 1): ?>
        <nav class="mw-blog-pagination" aria-label="Pagination">
          <?php if ($pageNum > 1): ?><a href="/blog<?= $pageNum > 2 ? '?p=' . ($pageNum - 1) : '' ?>">&larr; Newer</a><?php endif; ?>
          <span>Page <?= $pageNum ?> of <?= $lastPage ?></span>
          <?php if ($pageNum < $lastPage): ?><a href="/blog?p=<?= $pageNum + 1 ?>">Older &rarr;</a><?php endif; ?>
        </nav>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </section>

  <section class="cta-section">
    <div class="container">
      <h2>Need a hand with your property?</h2>
      <p>Free, no-obligation quotes for strata, commercial and residential properties.</p>
      <div class="cta-buttons">
        <a href="/quote?src=blog" class="btn btn-primary-large">Request a Free Quote</a>
        <a href="tel:<?= h(SITE_PHONE_TEL) ?>" class="btn btn-secondary-large">Call <?= h(SITE_PHONE_DISPLAY) ?></a>
      </div>
    </div>
  </section>

<?php require __DIR__ . '/includes/footer.php'; ?>
