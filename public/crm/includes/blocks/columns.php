<?php
/**
 * Columns (Two-Column Layout) Block Renderer — P3-J
 *
 * Side-by-side content columns. Each column supports: heading, body text,
 * an optional button, and an optional image (media_id OR external URL).
 *
 * Config: {
 *   gap     : 'balanced' | 'wide-left' | 'wide-right' (default: balanced)
 *   valign  : 'top' | 'center' (default: center)
 *   reverse : bool (swap columns on desktop, default false)
 *   left    : { heading, body, button_text, button_url, media_id, img_url }
 *   right   : { heading, body, button_text, button_url, media_id, img_url }
 * }
 */

$gap     = $config['gap']     ?? 'balanced';
$valign  = $config['valign']  ?? 'center';
$reverse = !empty($config['reverse']);
$left    = $config['left']    ?? [];
$right   = $config['right']   ?? [];

// Column widths
$widths = match ($gap) {
    'wide-left'  => ['col-lg-7', 'col-lg-5'],
    'wide-right' => ['col-lg-5', 'col-lg-7'],
    default      => ['col-lg-6', 'col-lg-6'],
};
if ($reverse) $widths = array_reverse($widths);

// Resolve media IDs
$mediaIds = [];
if (!empty($left['media_id']))  $mediaIds[] = (int)$left['media_id'];
if (!empty($right['media_id'])) $mediaIds[] = (int)$right['media_id'];
$mediaMap = [];
if (!empty($mediaIds) && function_exists('getDB')) {
    try {
        $db  = getDB();
        $phs = implode(',', array_fill(0, count($mediaIds), '?'));
        $st  = $db->prepare("SELECT id, file_path, alt_text FROM media_assets WHERE id IN ($phs)");
        $st->execute($mediaIds);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $mediaMap[(int)$row['id']] = $row;
        }
    } catch (\Exception $e) {}
}

$vaClass = $valign === 'top' ? 'align-items-start' : 'align-items-center';

/**
 * Render a single column's content.
 */
$renderCol = function(array $col, string $colClass) use ($mediaMap): void {
    echo '<div class="col-12 ' . htmlspecialchars($colClass) . ' col-block">';

    // Image (media library or external URL)
    $imgSrc = '';
    $imgAlt = '';
    if (!empty($col['media_id']) && isset($mediaMap[(int)$col['media_id']])) {
        $m      = $mediaMap[(int)$col['media_id']];
        $imgSrc = $m['file_path'];
        $imgAlt = $m['alt_text'] ?? '';
    } elseif (!empty($col['img_url'])) {
        $imgSrc = $col['img_url'];
        $imgAlt = $col['heading'] ?? '';
    }
    if ($imgSrc) {
        echo '<img src="' . htmlspecialchars($imgSrc) . '" alt="' . htmlspecialchars($imgAlt)
            . '" class="col-block-img" loading="lazy">';
    }

    if (!empty($col['heading'])) {
        echo '<h2 class="col-block-heading">' . htmlspecialchars($col['heading']) . '</h2>';
    }
    if (!empty($col['body'])) {
        // Allow simple <br> and <strong>/<em> — strip everything else
        $safeBody = strip_tags($col['body'], '<br><strong><em><p><ul><li><ol>');
        echo '<div class="col-block-body">' . $safeBody . '</div>';
    }
    if (!empty($col['button_text']) && !empty($col['button_url'])) {
        echo '<a href="' . htmlspecialchars($col['button_url']) . '" class="btn btn-primary-large col-block-btn">'
            . htmlspecialchars($col['button_text']) . '</a>';
    }

    echo '</div>';
};
?>
<section class="section-pad">
  <div class="container">
    <div class="row <?php echo h($vaClass); ?>">
      <?php
      $cols = $reverse ? [$right, $left] : [$left, $right];
      $renderCol($cols[0], $widths[0]);
      $renderCol($cols[1], $widths[1]);
      ?>
    </div>
  </div>
</section>
