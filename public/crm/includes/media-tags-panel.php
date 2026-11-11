<?php
/**
 * Media Library → "Mia's tags" tab. Included by /cms/cms-media_appstack.php when ?tab=tags.
 *
 * Shows what Mia tagged (service, season, before/after, area, subject, quality, …), a privacy
 * badge (ok / blur needed / not checked yet / client opted out), Tim's hero star, and a filter by
 * service, season and stage. Tags come from MediaTagService (daily cron + upload hook).
 * Never fatal: an error or a missing migration shows a note instead.
 */
$__mt = null;
$__mtItems = [];
$__mtFacets = ['service' => [], 'season' => [], 'stage' => []];
$__mtNote = '';
try {
    require_once APP_ROOT . '/Modules/Marketing/Services/MediaTagService.php';
    $__mt = new MediaTagService(getDB());
    if ($__mt->ready()) {
        $__mtItems = $__mt->library($_GET, 60);
        $__mtFacets = $__mt->facets();
    } else {
        $__mtNote = 'Run migration 1202 (media tags) to switch Mia\'s tagging on.';
    }
} catch (Throwable $__e) {
    error_log('Media tags panel: ' . $__e->getMessage());
    $__mtNote = 'Tags could not load just now.';
}
$__sel = function (string $ns) { return MediaTagRules::slug((string)($_GET[$ns] ?? '')); };
$__privacy = function (array $tags): array {
    if (MediaTagRules::value($tags, 'consent') === 'no') return ['is-no', 'Client opted out'];
    $p = MediaTagRules::value($tags, 'privacy');
    if ($p === 'ok') return ['is-ok', 'Privacy OK'];
    if ($p === 'blur-needed') return ['is-blur', 'Blur needed'];
    return ['is-unk', 'Not checked yet'];
};
?>
<div class="mw-mtag" id="mwMediaTags">
  <form method="get" class="mw-mtag-filters">
    <input type="hidden" name="tab" value="tags">
    <?php foreach (['service' => 'Service', 'season' => 'Season', 'stage' => 'Stage'] as $__ns => $__lbl): ?>
    <label><?= h($__lbl) ?>
      <select name="<?= h($__ns) ?>" class="form-control form-control-sm">
        <option value="">Any</option>
        <?php foreach ($__mtFacets[$__ns] as $__v): ?>
        <option value="<?= h($__v) ?>" <?= $__sel($__ns) === $__v ? 'selected' : '' ?>><?= h(ucwords(str_replace('-', ' ', $__v))) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <?php endforeach; ?>
    <label>Hero only
      <select name="hero" class="form-control form-control-sm">
        <option value="">No</option>
        <option value="1" <?= !empty($_GET['hero']) ? 'selected' : '' ?>>Yes</option>
      </select>
    </label>
    <button class="btn btn-sm btn-outline-secondary">Filter</button>
  </form>

  <?php if ($__mtNote !== ''): ?>
  <p class="text-muted"><?= h($__mtNote) ?></p>
  <?php elseif (!$__mtItems): ?>
  <p class="text-muted">Nothing tagged matches yet. Mia tags crew photos as they come in and every morning.</p>
  <?php else: ?>
  <div class="mw-mtag-grid">
    <?php foreach ($__mtItems as $__it): [$__pc, $__pl] = $__privacy($__it['tags']); ?>
    <div class="mw-mtag-card" data-media="<?= (int)$__it['id'] ?>">
      <img src="<?= h((string)($__it['thumb_path'] ?: $__it['file_path'])) ?>" alt="" loading="lazy">
      <button type="button" class="mw-mtag-star <?= !empty($__it['is_favorite']) ? 'is-on' : '' ?>" data-hero="<?= !empty($__it['is_favorite']) ? 1 : 0 ?>"
              aria-label="<?= !empty($__it['is_favorite']) ? 'Remove hero star' : 'Mark as a hero photo' ?>" title="Hero: Mia picks it first">★</button>
      <span class="mw-mtag-privacy <?= h($__pc) ?>"><?= h($__pl) ?></span>
      <div class="mw-mtag-body">
        <div class="mw-mtag-tags">
          <?php foreach ($__it['tags'] as $__t): $__ns = (string)strstr($__t, '/', true); ?>
            <?php if (in_array($__ns, ['privacy', 'consent', 'use'], true)) continue; ?>
            <span class="<?= $__ns === 'service' ? 'is-service' : ($__ns === 'used' ? 'is-used' : '') ?>"><?= h($__t) ?></span>
          <?php endforeach; ?>
        </div>
        <div class="mw-mtag-meta">Used <?= (int)$__it['usage_count'] ?> time<?= (int)$__it['usage_count'] === 1 ? '' : 's' ?></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
<script src="/crm/js/media-tags.js?v=20261006" defer></script>
