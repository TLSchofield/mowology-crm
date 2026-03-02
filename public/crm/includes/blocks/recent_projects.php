<?php
/**
 * Recent Projects Block Renderer (P2-E)
 *
 * Pulls the latest portfolio items from the database dynamically.
 * Config: { headline, count, category, cta_text, cta_url, layout }
 */

$headline  = $config['headline']  ?? 'Recent Projects';
$count     = max(1, min(12, (int)($config['count'] ?? 6)));
$category  = $config['category']  ?? '';
$ctaText   = $config['cta_text']  ?? 'View All Projects';
$ctaUrl    = $config['cta_url']   ?? '/portfolio';
$layout    = $config['layout']    ?? 'grid'; // grid | carousel

$projects = [];
try {
    $db = getDB();
    if ($category) {
        $stmt = $db->prepare("
            SELECT id, title, description, photo_url, category, created_at
            FROM portfolio_items
            WHERE status = 'published' AND category = ?
            ORDER BY created_at DESC
            LIMIT ?
        ");
        $stmt->execute([$category, $count]);
    } else {
        $stmt = $db->prepare("
            SELECT id, title, description, photo_url, category, created_at
            FROM portfolio_items
            WHERE status = 'published'
            ORDER BY created_at DESC
            LIMIT ?
        ");
        $stmt->execute([$count]);
    }
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (\Throwable $e) {
    // Graceful degradation — block renders nothing if DB fails
    $projects = [];
}
?>

<section class="recent-projects-block section-pad">
  <div class="container">
    <?php if ($headline): ?>
    <h2 class="section-title text-center mb-4"><?php echo h($headline); ?></h2>
    <?php endif; ?>

    <?php if (empty($projects)): ?>
      <p class="text-center text-muted">No projects found.</p>
    <?php else: ?>
    <div class="row <?php echo $layout === 'carousel' ? 'slick-carousel' : ''; ?>">
      <?php foreach ($projects as $proj): ?>
      <div class="col-md-4 mb-4">
        <div class="card h-100 border-0 shadow-sm">
          <?php if (!empty($proj['photo_url'])): ?>
          <img src="<?php echo h($proj['photo_url']); ?>"
               alt="<?php echo h($proj['title']); ?>"
               class="card-img-top"
               style="height:200px;object-fit:cover;"
               loading="lazy">
          <?php endif; ?>
          <div class="card-body">
            <?php if (!empty($proj['category'])): ?>
              <span class="badge badge-success mb-2"><?php echo h($proj['category']); ?></span>
            <?php endif; ?>
            <h5 class="card-title mb-1"><?php echo h($proj['title']); ?></h5>
            <?php if (!empty($proj['description'])): ?>
              <p class="card-text small text-muted"><?php echo h(mb_substr($proj['description'], 0, 120)) . (mb_strlen($proj['description']) > 120 ? '…' : ''); ?></p>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <?php if ($ctaText && $ctaUrl): ?>
    <div class="text-center mt-4">
      <a href="<?php echo h($ctaUrl); ?>" class="btn btn-primary-large"><?php echo h($ctaText); ?></a>
    </div>
    <?php endif; ?>
    <?php endif; ?>
  </div>
</section>
