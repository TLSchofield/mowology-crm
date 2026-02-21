<?php
/**
 * Before/After Block Renderer
 *
 * Side-by-side image comparison pairs with optional caption.
 *
 * Config: {title, description, pairs[{before_media_id, after_media_id, caption}]}
 */

$title       = $config['title'] ?? '';
$description = $config['description'] ?? '';
$pairs       = $config['pairs'] ?? [];

if (empty($pairs)) {
    return;
}

// Resolve media assets in one pass
$mediaIds = [];
foreach ($pairs as $pair) {
    if (!empty($pair['before_media_id'])) $mediaIds[] = (int)$pair['before_media_id'];
    if (!empty($pair['after_media_id']))  $mediaIds[] = (int)$pair['after_media_id'];
}
$mediaMap = [];
if (!empty($mediaIds) && function_exists('getDB')) {
    try {
        $db = getDB();
        $ph = implode(',', array_fill(0, count($mediaIds), '?'));
        $stmt = $db->prepare("SELECT id, file_path, alt_text FROM media_assets WHERE id IN ($ph)");
        $stmt->execute($mediaIds);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $mediaMap[(int)$row['id']] = $row;
        }
    } catch (Exception $e) {}
}
?>
<section class="section-pad">
  <div class="container">
    <?php if ($title): ?>
      <h2 class="section-title text-center"><?php echo h($title); ?></h2>
    <?php endif; ?>
    <?php if ($description): ?>
      <p class="section-intro text-center"><?php echo h($description); ?></p>
    <?php endif; ?>

    <div class="ba-grid">
      <?php foreach ($pairs as $pair): ?>
      <?php
        $before = $mediaMap[(int)($pair['before_media_id'] ?? 0)] ?? null;
        $after  = $mediaMap[(int)($pair['after_media_id']  ?? 0)] ?? null;
        if (!$before && !$after) continue;
      ?>
      <figure class="ba-pair">
        <div class="ba-images">
          <div class="ba-side ba-before">
            <?php if ($before): ?>
              <img src="<?php echo h($before['file_path']); ?>" alt="<?php echo h($before['alt_text'] ?: 'Before'); ?>" loading="lazy">
            <?php endif; ?>
            <span class="ba-label ba-label-before">Before</span>
          </div>
          <div class="ba-side ba-after">
            <?php if ($after): ?>
              <img src="<?php echo h($after['file_path']); ?>" alt="<?php echo h($after['alt_text'] ?: 'After'); ?>" loading="lazy">
            <?php endif; ?>
            <span class="ba-label ba-label-after">After</span>
          </div>
        </div>
        <?php if (!empty($pair['caption'])): ?>
          <figcaption class="ba-caption"><?php echo h($pair['caption']); ?></figcaption>
        <?php endif; ?>
      </figure>
      <?php endforeach; ?>
    </div>
  </div>
</section>
