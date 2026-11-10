<?php
/**
 * The job strip — Penny's duplicate-receipt job across the top of her pages in the Ittai desktop shell.
 * Included from appstack_head.php inside <div class="container-fluid p-0">, only when $itShell is on.
 *
 * Nothing is decided here: PennyJobAdapter turns DuplicateReceiptService's groups (the ones the web Penny card
 * shows) into jobs, and /crm/js/ittai-job.js sends the same bookkeeper.php requests the card does (dupe_merge,
 * not_dupe, unmerge). Shown only on Penny's pages, only to the owner, only while she has a waiting pair.
 * Any failure renders nothing — it must never break the page it sits on.
 */
if (empty($itShell)) return;
if (!defined('APP_ROOT')) return;
try {
    require_once __DIR__ . '/nav-items.php';
    $__ijUser = (array)($user ?? (function_exists('getCurrentUser') ? getCurrentUser() : []) ?? []);
    $__ijHere = false;
    foreach (mwIttaiNavBlocks((array)($navItems ?? []), $__ijUser, (string)($activePage ?? '')) as $__ijB) {
        if (($__ijB['slug'] ?? '') === 'penny' && !empty($__ijB['here'])) { $__ijHere = true; break; }
    }
    if (!$__ijHere) return;
    // Merge and Different go through bookkeeper.php, which asks for expenses.approve; the strip asks for view too.
    if (function_exists('userHasPermission') && !(userHasPermission('expenses.view') && userHasPermission('expenses.approve'))) return;
    require_once APP_ROOT . '/Services/HeadIdentity.php';
    require_once APP_ROOT . '/Modules/ChiefOfStaff/Services/CharlieDeskService.php';
    $__ijDb = getDB();
    $__ijDesk = new CharlieDeskService($__ijDb);
    if (!$__ijDesk->ready() || !$__ijDesk->isOwner($__ijUser)) return;
    require_once APP_ROOT . '/Modules/Expenses/Services/PennyJobAdapter.php';
    if (!PennyJobAdapter::hasWork($__ijDb)) return;
    $__ijJobs = PennyJobAdapter::jobs($__ijDb, $__ijUser);
} catch (Throwable $__e) {
    error_log('Job strip unavailable: ' . $__e->getMessage());
    return;
}
if (!$__ijJobs) return;

require_once __DIR__ . '/ittai-icons.php';
$__ijFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
$__ijIcon = ['same' => 'check', 'differs' => 'neq', 'missing' => 'x', 'info' => 'info'];
$__ijWord = ['same' => 'same', 'differs' => 'differs', 'missing' => 'missing', 'info' => 'info'];
$__ijSvg = static function (string $id): string {
    return '<svg class="it-job-ic" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><use href="#it-w-' . $id . '"/></svg>';
};
// One receipt's thumbnail + caption.
$__ijFig = static function (array $side, bool $keep): string {
    $img = !empty($side['image'])
        ? '<img src="' . h($side['image']) . '" alt="" loading="lazy">'
        : '<span class="it-job-noimg" aria-hidden="true"></span>';
    $cap = (string)($side['label'] ?? '') . (($side['who'] ?? '') !== '' ? ' · ' . $side['who'] : '');
    return '<figure class="it-job-fig' . ($keep ? ' is-keep' : '') . '">' . $img . '<figcaption>' . h($cap) . '</figcaption></figure>';
};
// The rail for one job; null prints the empty skeleton the script clones for the next job.
$__ijRail = static function (?array $j) use ($__ijIcon, $__ijWord, $__ijSvg, $__ijFig, $__ijFlags): string {
    $cols = '';
    foreach ((array)($j['columns'] ?? []) as $c) {
        $st = (string)$c['state'];
        $cols .= '<li class="it-job-col"><span class="it-job-top">' . h($c['top']) . '</span>'
            . '<span class="it-job-disc it-job-disc-' . h($st) . '" role="img" aria-label="' . h($c['label'] . ': ' . ($__ijWord[$st] ?? 'info')) . '">' . $__ijSvg($__ijIcon[$st] ?? 'info') . '</span>'
            . '<span class="it-job-bot">' . h($c['bottom']) . '</span><span class="it-job-lbl">' . h($c['label']) . '</span></li>';
    }
    $btn = static function (string $cls, string $icon, ?array $a) use ($__ijSvg, $__ijFlags): string {
        return '<button type="button" class="it-btn ' . $cls . '"' . ($a ? ' data-request="' . h(json_encode($a['request'], $__ijFlags)) . '"' : '') . '>'
            . $__ijSvg($icon) . '<b>' . h($a['label'] ?? '') . '</b><span>' . h($a['sub'] ?? '') . '</span></button>';
    };
    $anchor = (array)($j['anchor'] ?? ['label' => 'Ledger', 'href' => '/crm/ledger/']);
    return '<div class="it-job-rail"><ol class="it-job-cols">' . $cols . '</ol>'
        . '<div class="it-job-imgs">' . $__ijFig((array)($j['keep'] ?? []), true) . $__ijFig((array)($j['copy'] ?? []), false) . '</div>'
        . '<div class="it-job-acts">' . $btn('it-btn-fill', 'merge', $j['primary'] ?? null) . $btn('it-btn-line', 'split', $j['secondary'] ?? null) . '</div>'
        . '<a class="it-job-anchor" href="' . h($anchor['href']) . '"><span class="it-ad">' . $__ijSvg('book') . '</span><span>' . h($anchor['label']) . '</span></a></div>';
};
$__ij = $__ijJobs[0];
itIconSprite();
?>
<section class="it-job" data-head="penny" aria-label="Penny's job">
  <h2 class="it-job-t"><span class="it-job-tt"><?= h($__ij['title']) ?></span> <span class="it-tag">AI</span><span class="it-job-c"><?= h($__ij['confidence']) ?></span></h2>
  <?= $__ijRail($__ij) ?>
  <p class="it-job-foot"><span class="it-job-n">1 of <?= (int)$__ij['total'] ?></span><?php if ((int)$__ij['total'] > 1): ?> · <button type="button" class="it-job-skip">Skip for now</button><?php endif; ?></p>
  <p class="it-job-msg" role="status" aria-live="polite" hidden><span class="it-job-msgt"></span> <button type="button" class="it-job-undo" hidden>Undo</button></p>
  <template class="it-job-tpl"><?= $__ijRail(null) ?></template>
  <script type="application/json" class="it-job-data"><?= json_encode($__ijJobs, $__ijFlags) ?></script>
</section>
<script src="<?= function_exists('_av') ? _av('/crm/js/ittai-job.js') : '/crm/js/ittai-job.js' ?>" defer></script>
