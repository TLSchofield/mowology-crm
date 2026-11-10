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
    require_once APP_ROOT . '/Modules/Expenses/Services/ExpensePulseService.php';
    $__ijPulse = $__ijJobs ? ExpensePulseService::load($__ijDb, time()) : null;
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
/** The approval triangle, as whole as Penny's record with the vendor: forming (broken outline), held (outline), earned (solid). */
$__ijConf = static function (string $sure, string $line): string {
    $sure = in_array($sure, ['forming', 'held', 'earned'], true) ? $sure : 'forming';
    $sym = $sure === 'earned' ? 'it-st-approval' : 'it-st-approval-' . $sure;
    $label = 'Penny proposes; your approval decides. ' . ($sure !== 'forming' && $line !== '' ? $line : 'First time with this vendor.');
    return '<span class="it-job-conf is-' . $sure . '" role="img" aria-label="' . h($label) . '" title="' . h($label) . '"><svg class="it-si" width="24" height="24" viewBox="0 0 16 16" aria-hidden="true"><use href="#' . $sym . '"/></svg></span>';
};
$__ijRail = static function (?array $j) use ($__ijIcon, $__ijWord, $__ijSvg, $__ijFig, $__ijFlags, $__ijConf): string {
    // The phone's sentence read left to right (Tim, 2026-11-10): the line from the head's card runs on as the rail;
    // kept receipt ABOVE it, waiting receipt BELOW, each disc on the rail where the two still touch; the receipts and
    // the two choices stacked the same way; the line comes back together into the Ledger at the end.
    $cols = '';
    foreach ((array)($j['columns'] ?? []) as $c) {
        $st = (string)$c['state'];
        $cols .= '<li class="it-job-col"><span class="it-job-top">' . h($c['top']) . '</span>'
            . '<span class="it-job-disc it-job-disc-' . h($st) . '" role="img" aria-label="' . h($c['label'] . ': ' . ($__ijWord[$st] ?? 'info')) . '">' . $__ijSvg($__ijIcon[$st] ?? 'info') . '</span>'
            . '<span class="it-job-bot">' . h($c['bottom']) . '</span></li>';
    }
    $btn = static function (string $cls, string $icon, ?array $a) use ($__ijSvg, $__ijFlags): string {
        return '<button type="button" class="it-btn ' . $cls . '"' . ($a ? ' data-request="' . h(json_encode($a['request'], $__ijFlags)) . '"' : '') . '>'
            . $__ijSvg($icon) . '<b>' . h($a['label'] ?? '') . '</b><span>' . h($a['sub'] ?? '') . '</span></button>';
    };
    $anchor = (array)($j['anchor'] ?? ['label' => 'Ledger', 'href' => '/crm/ledger/']);
    return '<div class="it-job-rail">'
        . '<a class="it-job-anchor it-job-anchor-start" href="/crm/receipts-review_appstack.php"><span class="it-ad it-ad-fill">' . $__ijSvg('copies') . '</span><span>Duplicate</span></a>'
        . $__ijConf((string)($j['sure'] ?? 'forming'), (string)($j['confidence'] ?? ''))
        . '<div class="it-job-split">'
        . '<div class="it-job-col it-job-imgs">' . $__ijFig((array)($j['keep'] ?? []), true)
        . '<button type="button" class="it-job-view" aria-label="View both receipts side by side"><span class="it-ad it-ad-sm">' . $__ijSvg('eye') . '</span><span>View</span></button>'
        . $__ijFig((array)($j['copy'] ?? []), false) . '</div>'
        . '<ol class="it-job-cols">' . $cols . '</ol>'
        . '<div class="it-job-col it-job-acts">' . $btn('it-btn-fill', 'merge', $j['primary'] ?? null) . '<span class="it-job-m"></span>' . $btn('it-btn-line', 'split', $j['secondary'] ?? null) . '</div>'
        . '</div>'
        . '<a class="it-job-anchor" href="' . h($anchor['href']) . '"><span class="it-ad">' . $__ijSvg('book') . '</span><span>' . h($anchor['label']) . '</span></a>'
        . '</div>';
};
$__ij = $__ijJobs[0];
// The one picture beside the decision (Tim, 2026-11-10): six months of approved spend, this month marked, and the
// quarter's GST on receipts — what a solo operator needs to know while deciding. Tokens only; bars are inline SVG.
$__ijViz = static function (?array $p): string {
    if (!$p) return '';
    $w = 288; $h = 120; $pad = 8; $n = count($p['months']); $bw = (int)floor(($w - $pad * 2) / $n) - 8;
    $max = $p['max'] > 0 ? $p['max'] : 1;
    $bars = '';
    foreach ($p['months'] as $i => $m) {
        $bh = (int)round(($m['total'] / $max) * 80);
        $x = $pad + $i * ($bw + 8); $y = 96 - $bh;
        $bars .= '<rect x="' . $x . '" y="' . $y . '" width="' . $bw . '" height="' . max($bh, 1) . '" rx="3" class="' . ($m['now'] ? 'it-viz-now' : 'it-viz-bar') . '"></rect>'
               . '<text x="' . ($x + $bw / 2) . '" y="112" text-anchor="middle" class="it-viz-lbl">' . h($m['label']) . '</text>';
    }
    $money = static fn(float $v): string => '$' . number_format($v, $v >= 1000 ? 0 : 2);
    return '<figure class="it-job-viz"><figcaption><b>Spend, last 6 months</b><span>' . h($money((float)$p['this_month'])) . ' this month</span></figcaption>'
        . '<svg viewBox="0 0 ' . $w . ' ' . $h . '" width="' . $w . '" height="' . $h . '" role="img" aria-label="Approved receipts by month, last six months">' . $bars . '</svg>'
        . '<p class="it-job-viz-gst"><b>' . h($money((float)$p['quarter_gst'])) . '</b> GST on receipts in ' . h($p['quarter_label']) . ' <span>— claim it back</span></p></figure>';
};
itIconSprite();
?>
<div class="it-job-outer"><div class="it-job-wrap">
<section class="it-job" data-head="penny" aria-label="Penny's job">
  <h2 class="it-vh it-job-tt"><?= h($__ij['title']) ?></h2>
  <div class="it-job-body"><?= $__ijRail($__ij) ?></div>
  <p class="it-job-foot"><span class="it-job-n">1 of <?= (int)$__ij['total'] ?></span><?php if ((int)$__ij['total'] > 1): ?> · <button type="button" class="it-job-skip">Skip for now</button><?php endif; ?></p>
  <p class="it-job-msg" role="status" aria-live="polite" hidden><span class="it-job-msgt"></span> <button type="button" class="it-job-undo" hidden>Undo</button></p>
  <template class="it-job-tpl"><?= $__ijRail(null) ?></template>
  <dialog class="it-job-dlg" aria-label="Both receipts">
    <div class="it-job-dlg-body">
      <figure class="it-job-dlg-fig is-keep"><img alt="" src=""><figcaption></figcaption></figure>
      <figure class="it-job-dlg-fig"><img alt="" src=""><figcaption></figcaption></figure>
    </div>
    <form method="dialog"><button type="submit" class="it-job-dlg-close">Close</button></form>
  </dialog>
  <script type="application/json" class="it-job-data"><?= json_encode($__ijJobs, $__ijFlags) ?></script>
</section>
<?= $__ijViz($__ijPulse ?? null) ?>
</div></div>
<script src="<?= function_exists('_av') ? _av('/crm/js/ittai-job.js') : '/crm/js/ittai-job.js' ?>" defer></script>
