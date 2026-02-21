<?php
/**
 * Portfolio Showcase Block Renderer — Public Site Design System
 *
 * Renders projects from the repeatable 'projects' config array.
 * Each project has: title, media_id, url (optional)
 * Media IDs are bulk-resolved to file paths in a single query.
 *
 * Config keys: title, description, cta_text, cta_url, projects[]
 */

$title       = $config['title'] ?? 'Our Work';
$description = $config['description'] ?? '';
$ctaText     = $config['cta_text'] ?? '';
$ctaUrl      = $config['cta_url'] ?? '';
$projects    = is_array($config['projects'] ?? null) ? $config['projects'] : [];

// Bulk-resolve media IDs → file paths in one query
$mediaMap = [];
if (!empty($projects)) {
    $mediaIds = array_filter(array_map(fn($p) => (int)($p['media_id'] ?? 0), $projects));
    $mediaIds = array_values(array_unique($mediaIds));
    if (!empty($mediaIds) && function_exists('getDB')) {
        try {
            $db  = getDB();
            $phs = implode(',', array_fill(0, count($mediaIds), '?'));
            $st  = $db->prepare("SELECT id, file_path FROM media_assets WHERE id IN ($phs)");
            $st->execute($mediaIds);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $mediaMap[(int)$row['id']] = $row['file_path'];
            }
        } catch (Exception $e) {
            // silently skip — render without images
        }
    }
}
?>

<section class="slp-section slp-alt">
  <div class="container">
    <?php if ($title): ?>
      <h2 class="slp-heading"><?php echo h($title); ?></h2>
    <?php endif; ?>
    <?php if ($description): ?>
      <p class="slp-intro"><?php echo h($description); ?></p>
    <?php endif; ?>

    <?php if (!empty($projects)): ?>
      <div class="services-grid" style="--cols: <?php echo min(count($projects), 3); ?>;">
        <?php foreach ($projects as $project):
            $imgPath  = $mediaMap[(int)($project['media_id'] ?? 0)] ?? '';
            $projTitle= (string)($project['title'] ?? '');
            $projUrl  = (string)($project['url'] ?? '');
        ?>
          <div class="service-card portfolio-item">
            <?php if ($imgPath): ?>
              <img src="<?php echo h($imgPath); ?>"
                   alt="<?php echo h($projTitle); ?>"
                   loading="lazy"
                   style="width:100%;height:200px;object-fit:cover;border-radius:8px 8px 0 0;">
            <?php else: ?>
              <div style="width:100%;height:160px;background:var(--mowology-light,#e8f3f0);border-radius:8px 8px 0 0;display:flex;align-items:center;justify-content:center;">
                <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="opacity:.35;"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
              </div>
            <?php endif; ?>
            <div style="padding:16px;">
              <?php if ($projTitle): ?>
                <h3 style="margin:0 0 8px;font-size:1.05rem;"><?php echo h($projTitle); ?></h3>
              <?php endif; ?>
              <?php if ($projUrl): ?>
                <a href="<?php echo h($projUrl); ?>" class="btn" style="margin-top:8px;">View Project →</a>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <?php if ($ctaText && $ctaUrl): ?>
        <div style="text-align:center;margin-top:40px;">
          <a href="<?php echo h($ctaUrl); ?>" class="btn btn-primary"><?php echo h($ctaText); ?></a>
        </div>
      <?php endif; ?>

    <?php else: ?>
      <p style="text-align:center;color:var(--text-light,#6a6a6a);padding:40px 0;">
        No portfolio items to display yet.
      </p>
    <?php endif; ?>
  </div>
</section>
