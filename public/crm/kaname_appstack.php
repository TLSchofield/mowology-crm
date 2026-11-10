<?php
/**
 * Kaname's workspace, rest posture: the morning brief. Thin page: everything comes from
 * KanameWorkspaceService, read from the saved copy of Charlie's brief (no live build).
 */
require_once __DIR__ . '/../loginAuth/auth.php';
requireLogin();
$user = getCurrentUser();

require_once APP_ROOT . '/Services/HeadIdentity.php';
require_once APP_ROOT . '/Modules/ChiefOfStaff/Services/HeadNavService.php';
require_once APP_ROOT . '/Modules/ChiefOfStaff/Services/KanameWorkspaceService.php';

$__chief = HeadIdentity::chiefName();
$pageTitle = $__chief . ' · Morning brief';
$activePage = 'kaname';

try {
    $__kb = KanameWorkspaceService::load(getDB(), time());
} catch (Throwable $__e) {
    error_log('Kaname workspace: ' . get_class($__e));
    $__kb = KanameWorkspaceService::brief(null, null, [], time());
}

// The shell CSS only loads with the it_design cookie; this page needs it either way. filemtime, not _av()
// (not defined yet at this point in the head).
if (($_COOKIE['it_design'] ?? '') !== '1') {
    $extraHead = '<link rel="stylesheet" href="/crm/css/ittai-tokens.css?v=' . (int)@filemtime(__DIR__ . '/css/ittai-tokens.css') . '">'
               . '<link rel="stylesheet" href="/crm/css/ittai-shell.css?v=' . (int)@filemtime(__DIR__ . '/css/ittai-shell.css') . '">';
}

$__face = static fn(string $s): string => in_array($s, HeadNavService::HEADS, true) ? $s : 'charlie';
?>
<?php include 'includes/appstack_head.php'; ?>
<?php require_once __DIR__ . '/includes/ittai-icons.php'; itIconSprite(); ?>

          <div class="it-design"><div class="it-ws rest" data-head="charlie">
            <h1 class="it-vh"><?= h($__chief) ?>, morning brief</h1>
            <div class="it-kb">
              <h2><?= h($__kb['heading']) ?></h2>
              <?php if ($__kb['as_of'] !== ''): ?><p class="it-kb-asof"><?= h($__kb['as_of']) ?></p><?php endif; ?>
              <?php if ($__kb['empty']): ?>
              <p class="it-kb-empty"><?= h($__chief) ?> has not built today's brief yet. The team's desks are checked every 15 minutes.</p>
              <?php else: ?>
              <?php if ($__kb['count_line'] !== ''): ?>
              <p class="it-kb-need"><svg class="it-si" width="16" height="16" aria-hidden="true"><use href="#it-st-approval"/></svg><?= h($__kb['count_line']) ?></p>
              <?php endif; ?>
              <?php if ($__kb['need_you']): ?>
              <ul class="it-kb-list">
                <?php foreach ($__kb['need_you'] as $__r): ?>
                <li><img class="it-kb-face" src="/crm/img/heads/<?= h($__face($__r['head'])) ?>.jpg" width="28" height="28" alt="" loading="lazy"><span class="it-kb-text"><?= h($__r['name']) ?>: <?= h($__r['text']) ?></span><a class="it-kb-open" href="<?= h($__r['url']) ?>" aria-label="Open: <?= h($__r['text']) ?>">Open</a></li>
                <?php endforeach; ?>
              </ul>
              <?php endif; ?>
              <?php if ($__kb['also']): ?>
              <h3>Also on the desk</h3>
              <ul class="it-kb-list it-kb-also">
                <?php foreach ($__kb['also'] as $__r): ?>
                <li><img class="it-kb-face" src="/crm/img/heads/<?= h($__face($__r['head'])) ?>.jpg" width="28" height="28" alt="" loading="lazy"><span class="it-kb-text"><?= h($__r['name']) ?>: <?= h($__r['text']) ?></span><a class="it-kb-open" href="<?= h($__r['url']) ?>" aria-label="Open: <?= h($__r['text']) ?>">Open</a></li>
                <?php endforeach; ?>
              </ul>
              <?php endif; ?>
              <?php if ($__kb['handled']): ?>
              <h3>Already handled</h3>
              <ul class="it-kb-handled">
                <?php foreach ($__kb['handled'] as $__r): ?>
                <li><?= h($__r['name']) ?>: <?= h($__r['text']) ?></li>
                <?php endforeach; ?>
              </ul>
              <?php endif; ?>
              <?php endif; ?>
            </div>
          </div></div>

<?php include 'includes/appstack_footer.php'; ?>
