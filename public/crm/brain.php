<?php
/**
 * A department head's brain, full page — /crm/brain.php?head=penny|sam|otto|mia|yui|charlie
 *
 * Opened from a head's brain on the dashboard cards and from the faces on the Action Board
 * (head-brain.js / action-board.js). The live 3D brain large, its headline numbers and tier
 * bar, then everything the head knows by skill and tier (searchable).
 *
 * &view=client — the version Tim shows a client on his laptop/phone (behind login; not
 * public): no payee, vendor, person or crew names and no amounts — the brain, tiers,
 * counts and categories only, and what the head does for the client. Redacted server side
 * in BrainPageService::view(); nothing private reaches this page's HTML.
 *
 * Thin controller: all reading and shaping lives in BrainPageService.
 */
require_once __DIR__ . '/../loginAuth/auth.php';
require_once __DIR__ . '/includes/functions.php';
requireLogin();
$user = getCurrentUser();
requirePermission('expenses.approve');   // the same people who see the heads deck

require_once APP_ROOT . '/Services/BrainPageService.php';

$head = strtolower(preg_replace('/[^a-z]/i', '', (string)($_GET['head'] ?? 'penny')));
if (!BrainPageService::isHead($head)) $head = 'penny';
$isClient = ($_GET['view'] ?? '') === 'client';

$loaded = (new BrainPageService(getDB()))->load($head);
$v = BrainPageService::view($head, $loaded['brain'], $loaded['bright'], $isClient);

$pageTitle = $v['name'] . "'s brain";
$activePage = 'dashboard';
$extraHead = '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,500;0,600;1,500&display=swap">';

$self = '/crm/brain.php?head=' . rawurlencode($head);
$they = BrainPageService::HEADS[$head]['they'];
$sinceTxt = !empty($v['since']) ? date('M j, Y', strtotime((string)$v['since'])) : '';
$SHOW = 12;   // rows shown per tier before "Show more"
?>
<?php include __DIR__ . '/includes/appstack_head.php'; ?>

<div class="mw-brp<?= $isClient ? ' is-client' : '' ?>" data-brain-page>

  <section class="mw-brp-hero" aria-labelledby="mw-brp-title">
    <div class="mw-brp-stage">
      <canvas class="mw-brp-canvas" data-brain-stage
              data-units="<?= (int)$v['units'] ?>"
              data-bright="<?= h(number_format((float)$v['bright'], 2, '.', '')) ?>"
              data-parts="<?= h((string)json_encode($v['parts'])) ?>"
              role="img" aria-label="<?= h($v['name']) ?>'s brain: <?= (int)$v['units'] ?> things learned, shape <?= (int)$v['shape'] ?> of <?= (int)$v['shapes'] ?>"></canvas>
      <p class="mw-brp-shape" data-brain-shape>Shape <?= (int)$v['shape'] ?> of <?= (int)$v['shapes'] ?></p>
    </div>

    <div class="mw-brp-intro">
      <div class="mw-brp-who">
        <img src="/crm/img/heads/<?= h($head) ?>.jpg" alt="" width="72" height="72">
        <div>
          <p class="mw-brp-eyebrow"><?= h($v['role']) ?></p>
          <h1 class="mw-brp-title" id="mw-brp-title"><?= h($v['name']) ?>’s brain</h1>
        </div>
      </div>

      <?php if ($isClient && $v['about'] !== ''): ?>
        <p class="mw-brp-about"><?= h($v['about']) ?></p>
      <?php endif; ?>
      <p class="mw-brp-line"><?= h($v['line']) ?></p>

      <dl class="mw-brp-stats">
        <div><dt>Things learned</dt><dd><?= number_format((int)$v['units']) ?></dd></div>
        <div><dt>At <?= h($v['top']['name'] ?? 'Platinum') ?></dt><dd><?= number_format((int)($v['top']['n'] ?? 0)) ?></dd></div>
        <div><dt>Shape</dt><dd><?= (int)$v['shape'] ?><small> of <?= (int)$v['shapes'] ?></small></dd></div>
      </dl>

      <div class="mw-brp-tierbar" role="img" aria-label="<?= h(implode(', ', array_map(fn($t) => $t['n'] . ' ' . $t['name'], array_filter($v['tiers'], fn($t) => $t['n'] > 0)))) ?>">
        <?php foreach ($v['tiers'] as $t): if ($t['n'] < 1) continue; ?>
          <span class="mw-brp-seg mw-tier-<?= h($t['slug']) ?>" style="flex-grow: <?= (int)$t['n'] ?>" title="<?= h($t['name']) ?>: <?= (int)$t['n'] ?>"></span>
        <?php endforeach; ?>
        <?php if ($v['items_n'] === 0): ?><span class="mw-brp-seg is-empty"></span><?php endif; ?>
      </div>
      <ol class="mw-brp-legend">
        <?php foreach ($v['tiers'] as $t): ?>
          <li class="<?= $t['n'] ? '' : 'is-zero' ?>"><i class="mw-brp-chip mw-tier-<?= h($t['slug']) ?>"></i><?= h($t['name']) ?> <b><?= (int)$t['n'] ?></b><small><?= (int)$t['min'] ?>+</small></li>
        <?php endforeach; ?>
      </ol>

      <div class="mw-brp-actions">
        <?php if ($isClient): ?>
          <a class="mw-brp-toggle" href="<?= h($self) ?>">Owner view</a>
        <?php else: ?>
          <a class="mw-brp-toggle" href="<?= h($self . '&view=client') ?>" title="Hides every name and amount: just the brain, tiers and counts">Client view</a>
          <a class="mw-brp-back" href="/crm/dashboard_appstack.php">Back to dashboard</a>
        <?php endif; ?>
      </div>
    </div>
  </section>

  <nav class="mw-brp-team" aria-label="The team's brains">
    <?php foreach (BrainPageService::HEADS as $slug => $hd): ?>
      <a href="/crm/brain.php?head=<?= h($slug) ?><?= $isClient ? '&amp;view=client' : '' ?>" class="<?= $slug === $head ? 'is-on' : '' ?>"<?= $slug === $head ? ' aria-current="page"' : '' ?>>
        <img src="/crm/img/heads/<?= h($slug) ?>.jpg" alt="" width="32" height="32" loading="lazy"><span><?= h($hd['name']) ?></span>
      </a>
    <?php endforeach; ?>
  </nav>

  <section class="mw-brp-knows" aria-labelledby="mw-brp-knows-title">
    <div class="mw-brp-knows-head">
      <h2 id="mw-brp-knows-title">What <?= h($they) ?> knows</h2>
      <?php if (!$isClient && $v['items_n'] > 0): ?>
        <label class="mw-brp-search"><span class="sr-only">Search</span>
          <input type="search" placeholder="Search <?= (int)$v['items_n'] ?> things…" data-brp-search autocomplete="off">
        </label>
      <?php endif; ?>
    </div>

    <?php if ($isClient): ?>
      <div class="mw-brp-cats">
        <?php foreach ($v['groups'] as $g): ?>
          <article class="mw-brp-cat">
            <p class="mw-brp-cat-n"><?= number_format((int)$g['n']) ?></p>
            <p class="mw-brp-cat-name"><?= h($g['name']) ?></p>
            <div class="mw-brp-tierbar is-thin">
              <?php foreach ($g['tiers'] as $t): ?><span class="mw-brp-seg mw-tier-<?= h($t['slug']) ?>" style="flex-grow: <?= (int)$t['n'] ?>"></span><?php endforeach; ?>
            </div>
            <p class="mw-brp-cat-tiers"><?= h(implode(' · ', array_map(fn($t) => $t['n'] . ' ' . $t['name'], $g['tiers']))) ?></p>
          </article>
        <?php endforeach; ?>
        <?php foreach ($v['counted'] as $c): ?>
          <article class="mw-brp-cat is-count">
            <p class="mw-brp-cat-n"><?= number_format((int)$c['n']) ?></p>
            <p class="mw-brp-cat-name"><?= h(preg_replace('/^\d+\s+/', '', $c['label'])) ?></p>
          </article>
        <?php endforeach; ?>
      </div>
      <p class="mw-brp-foot">Every thing <?= h($v['name']) ?> learns lights its own triangle. Its colour is how many times in a row it has been confirmed without a correction: Obsidian when it's new, Silver at 5, Gold at 10, Platinum at 50. One correction drops it a step.</p>
    <?php else: ?>
      <?php
        $tabs = $v['groups'];
        $hasCounted = !empty($v['counted']);
        $hasLearning = !empty($v['learning']);
      ?>
      <?php if (count($tabs) + ($hasCounted ? 1 : 0) + ($hasLearning ? 1 : 0) > 1): ?>
        <div class="mw-brp-tabs" role="tablist" aria-label="Kinds of knowledge">
          <?php foreach ($tabs as $i => $g): ?>
            <button type="button" role="tab" id="tab-<?= h($g['id']) ?>" aria-controls="<?= h($g['id']) ?>" aria-selected="<?= $i === 0 ? 'true' : 'false' ?>" data-brp-tab><?= h($g['name']) ?> <b><?= number_format((int)$g['n']) ?></b></button>
          <?php endforeach; ?>
          <?php if ($hasCounted): ?>
            <button type="button" role="tab" id="tab-g-counted" aria-controls="g-counted" aria-selected="<?= $tabs ? 'false' : 'true' ?>" data-brp-tab>Also learned <b><?= count($v['counted']) ?></b></button>
          <?php endif; ?>
          <?php if ($hasLearning): ?>
            <button type="button" role="tab" id="tab-g-learning" aria-controls="g-learning" aria-selected="false" data-brp-tab>Still learning <b><?= count($v['learning']) ?></b></button>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <?php foreach ($tabs as $i => $g): ?>
        <div class="mw-brp-panel" role="tabpanel" id="<?= h($g['id']) ?>" aria-labelledby="tab-<?= h($g['id']) ?>"<?= $i === 0 ? '' : ' hidden' ?>>
          <?php foreach ($g['tiers'] as $t): ?>
            <section class="mw-brp-tier" data-brp-tier>
              <h3><i class="mw-brp-chip mw-tier-<?= h($t['slug']) ?>"></i><?= h($t['name']) ?> <span><?= (int)$t['n'] ?></span></h3>
              <ul class="mw-brp-rows">
                <?php foreach ($t['items'] as $j => $it): ?>
                  <li class="mw-brp-row" data-q="<?= h(mb_strtolower($it['label'] . ' ' . $it['raw'])) ?>"<?= $j >= $SHOW ? ' data-brp-extra hidden' : '' ?>>
                    <span class="mw-brp-name"><?= h($it['label']) ?><?php if ($it['raw'] !== ''): ?><small><?= h($it['raw']) ?></small><?php endif; ?></span>
                    <span class="mw-brp-tag mw-tier-<?= h($t['slug']) ?>"><?= h($t['name']) ?></span>
                    <span class="mw-brp-note"><?= h($it['note']) ?><?php if ($it['corrected']): ?> <em>corrected recently</em><?php endif; ?></span>
                    <span class="mw-brp-bar" aria-hidden="true"><i class="mw-tier-<?= h($t['slug']) ?>" style="width: <?= (int)$it['bar'] ?>%"></i></span>
                  </li>
                <?php endforeach; ?>
              </ul>
              <?php if ($t['n'] > $SHOW): ?>
                <button type="button" class="mw-brp-more" data-brp-more>Show <?= (int)$t['n'] - $SHOW ?> more <?= h($t['name']) ?></button>
              <?php endif; ?>
            </section>
          <?php endforeach; ?>
          <p class="mw-brp-none" data-brp-none hidden>Nothing here matches.</p>
        </div>
      <?php endforeach; ?>

      <?php if ($hasCounted): ?>
        <div class="mw-brp-panel" role="tabpanel" id="g-counted" aria-labelledby="tab-g-counted"<?= $tabs ? ' hidden' : '' ?>>
          <ul class="mw-brp-counts">
            <?php foreach ($v['counted'] as $c): ?><li><b><?= number_format((int)$c['n']) ?></b> <?= h(preg_replace('/^\d+\s+/', '', $c['label'])) ?></li><?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

      <?php if ($hasLearning): ?>
        <div class="mw-brp-panel" role="tabpanel" id="g-learning" aria-labelledby="tab-g-learning" hidden>
          <p class="mw-brp-hint">Known from a rule you haven't confirmed twice yet (or one that was switched off), so <?= h($they) ?> doesn't act on these alone and they don't light a triangle.</p>
          <ul class="mw-brp-rows is-learning">
            <?php foreach ($v['learning'] as $it): ?>
              <li class="mw-brp-row" data-q="<?= h(mb_strtolower($it['label'] . ' ' . $it['raw'])) ?>">
                <span class="mw-brp-name"><?= h($it['label']) ?><?php if ($it['raw'] !== ''): ?><small><?= h($it['raw']) ?></small><?php endif; ?></span>
                <span class="mw-brp-tag">Learning</span>
                <span class="mw-brp-note"><?= h($it['note']) ?></span>
                <span class="mw-brp-bar" aria-hidden="true"><i></i></span>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

      <?php if ($v['units'] === 0): ?>
        <p class="mw-brp-hint">Nothing learned yet. Every decision you make on <?= h($v['name']) ?>’s card teaches <?= $they === 'she' ? 'her' : 'him' ?> something.</p>
      <?php endif; ?>

      <p class="mw-brp-foot">Each thing <?= h($v['name']) ?> learns lights its own triangle. Its colour is how many times in a row you've confirmed it unchanged: Obsidian first seen, Black 2, Bronze 3, Silver 5, Gold 10, White 20, Platinum 50. A correction drops it one step. The brain glows brighter the more often <?= h($they) ?>'s right first time (<?= (int)round($v['bright'] * 100) ?>%).<?= $sinceTxt ? ' Counting since ' . h($sinceTxt) . '.' : '' ?></p>
    <?php endif; ?>
  </section>
</div>

<script src="<?= _av('/crm/js/head-brain.js') ?>" defer></script>
<script src="<?= _av('/crm/js/brain-page.js') ?>" defer></script>

<?php include __DIR__ . '/includes/appstack_footer.php'; ?>
