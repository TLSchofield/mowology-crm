<?php
/**
 * The job strip — Penny's duplicate-receipt job across the top of her pages in the Ittai desktop shell.
 * Included from appstack_head.php inside <div class="container-fluid p-0">, only when $itShell is on.
 *
 * Nothing is decided here: PennyJobAdapter turns DuplicateReceiptService's groups (the ones the web Penny card
 * shows) into jobs, and /crm/js/ittai-job.js sends the same bookkeeper.php requests the card does (dupe_merge,
 * not_dupe, unmerge). Her second job (2026-11-10) is the pending e-Transfer to record: the Invoices card's own
 * reading (EtransferDeskService) and its own Record payment form (etransfer-confirm.php), drawn in the same
 * language with the "2/3" fraction — how many of the email, the invoice match and the bank deposit are in hand.
 * Duplicates queue first, then e-Transfers; Skip rotates. Shown only on Penny's pages, only to the owner, only
 * while she has something waiting. Any failure renders nothing — it must never break the page it sits on.
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
    // Record payment goes through etransfer-confirm.php, which asks for billing.edit: the e-Transfer job needs it too.
    $__ijCanBill = !function_exists('userHasPermission') || userHasPermission('billing.edit');
    if (!PennyJobAdapter::hasWork($__ijDb) && !($__ijCanBill && PennyJobAdapter::hasEtransferWork($__ijDb))) return;
    $__ijJobs = PennyJobAdapter::jobs($__ijDb, $__ijUser, $__ijCanBill);
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
/** The knowledge triangle, as whole as Penny's record: forming (three loose lines), held (broken triangle), known (solid blue), mastered (gold). */
$__ijConf = static function (string $sure, string $line): string {
    $sure = in_array($sure, ['forming', 'held', 'known', 'mastered'], true) ? $sure : 'forming';
    $sym = $sure === 'known' ? 'it-st-approval' : 'it-st-approval-' . $sure;
    $words = ['forming' => 'First time with this vendor.', 'held' => 'Noticed, not yet known: check it.', 'known' => 'She knows this one.', 'mastered' => 'She has mastered this.'];
    $label = 'Penny proposes; your approval decides. ' . $words[$sure] . ($sure !== 'forming' && $line !== '' ? ' ' . $line : '');
    return '<span class="it-job-conf is-' . $sure . '" role="img" aria-label="' . h($label) . '" title="' . h($label) . '"><svg class="it-si" width="24" height="24" viewBox="0 0 16 16" aria-hidden="true"><use href="#' . $sym . '"/></svg></span>';
};
// Shared pieces of either rail: the fact columns, the two choices stacked, the end anchor.
$__ijCols = static function (?array $j) use ($__ijIcon, $__ijWord, $__ijSvg): string {
    $cols = '';
    foreach ((array)($j['columns'] ?? []) as $c) {
        $st = (string)$c['state'];
        $cols .= '<li class="it-job-col"><span class="it-job-top" title="' . h($c['top']) . '">' . h($c['top']) . '</span>'
            . '<span class="it-job-disc it-job-disc-' . h($st) . '" role="img" aria-label="' . h($c['label'] . ': ' . ($__ijWord[$st] ?? 'info')) . '">' . $__ijSvg($__ijIcon[$st] ?? 'info') . '</span>'
            . '<span class="it-job-bot" title="' . h($c['bottom']) . '">' . h($c['bottom']) . '</span></li>';
    }
    return '<ol class="it-job-cols">' . $cols . '</ol>';
};
$__ijActs = static function (?array $j, string $primaryIcon, string $secondaryIcon) use ($__ijSvg, $__ijFlags): string {
    $btn = static function (string $cls, string $icon, ?array $a) use ($__ijSvg, $__ijFlags): string {
        return '<button type="button" class="it-btn ' . $cls . '"' . ($a ? ' data-request="' . h(json_encode($a['request'], $__ijFlags)) . '"' : '') . '>'
            . $__ijSvg($icon) . '<b>' . h($a['label'] ?? '') . '</b><span>' . h($a['sub'] ?? '') . '</span></button>';
    };
    return '<div class="it-job-col it-job-acts">' . $btn('it-btn-fill', $primaryIcon, $j['primary'] ?? null) . '<span class="it-job-m"></span>' . $btn('it-btn-line', $secondaryIcon, $j['secondary'] ?? null) . '</div>';
};
$__ijEnd = static function (?array $j) use ($__ijSvg): string {
    $anchor = (array)($j['anchor'] ?? ['label' => 'Ledger', 'href' => '/crm/ledger/']);
    return '<a class="it-job-anchor" href="' . h($anchor['href']) . '"><span class="it-ad">' . $__ijSvg('book') . '</span><span>' . h($anchor['label']) . '</span></a>';
};
// The rail for one job; null prints the empty skeleton the script clones for the next job of that kind.
$__ijRail = static function (?array $j) use ($__ijSvg, $__ijFig, $__ijConf, $__ijCols, $__ijActs, $__ijEnd): string {
    // The phone's sentence read left to right (Tim, 2026-11-10): the line from the head's card runs on as the rail;
    // kept receipt ABOVE it, waiting receipt BELOW, each disc on the rail where the two still touch; the receipts and
    // the two choices stacked the same way; the line comes back together into the Ledger at the end.
    return '<div class="it-job-rail" data-kind="duplicate">'
        . '<a class="it-job-anchor it-job-anchor-start" href="/crm/receipts-review_appstack.php"><span class="it-ad it-ad-fill">' . $__ijSvg('copies') . '</span><span>Duplicate</span></a>'
        . $__ijConf((string)($j['sure'] ?? 'forming'), (string)($j['confidence'] ?? ''))
        . '<div class="it-job-split">'
        . '<div class="it-job-col it-job-imgs">' . $__ijFig((array)($j['keep'] ?? []), true)
        . '<button type="button" class="it-job-view" aria-label="View both receipts side by side"><span class="it-ad it-ad-sm">' . $__ijSvg('eye') . '</span><span>View</span></button>'
        . $__ijFig((array)($j['copy'] ?? []), false) . '</div>'
        . $__ijCols($j)
        . $__ijActs($j, 'merge', 'split')
        . '</div>'
        . $__ijEnd($j)
        . '</div>';
};
/** One side of the e-Transfer job: the transfer (above the rail) or the invoice(s) (below it). */
$__ijSide = static function (array $side, bool $top): string {
    $out = '<span class="it-job-side it-job-side-' . ($top ? 'top' : 'bot') . '"><b>' . h((string)($side['label'] ?? '')) . '</b>';
    foreach (['who', 'when'] as $k) if (($side[$k] ?? '') !== '') $out .= '<span>' . h((string)$side[$k]) . '</span>';
    return $out . '</span>';
};
/** The fraction from the phone: how many of the three pieces (email, invoice, bank deposit) are already in hand, and the match's name. */
$__ijPieces = static function (?array $j): string {
    $m = (int)($j['pieces']['matched'] ?? 0); $t = (int)($j['pieces']['total'] ?? 3);
    return '<div class="it-job-pieces"><span class="it-job-frac" role="img" aria-label="' . h($m . ' of ' . $t . ' pieces in hand') . '">'
        . '<b class="it-job-frac-n">' . $m . '</b><svg class="it-job-frac-s" aria-hidden="true" viewBox="0 0 72 72" width="72" height="72"><path d="M0 36.5H14L35 .5 56 36.5H72"/></svg><b class="it-job-frac-d">' . $t . '</b></span>'
        . '<span class="it-job-lbl">' . implode('', array_map(fn($w) => '<span>' . h($w) . '</span>', explode(' + ', (string)($j['label'] ?? '')))) . '</span></div>';
};
// The e-Transfer rail (Tim's phone reference, 2026-11-10): Bank → the fraction and the match's name → the triangle →
// the frame: e-Transfer above the rail, invoice(s) below, a disc per fact where they meet → Record payment → Ledger.
$__ijRailEt = static function (?array $j) use ($__ijSvg, $__ijConf, $__ijCols, $__ijActs, $__ijEnd, $__ijSide, $__ijPieces): string {
    $start = (array)($j['start'] ?? ['label' => 'Bank', 'href' => '/crm/invoices/#etransfers']);
    return '<div class="it-job-rail" data-kind="etransfer">'
        . '<a class="it-job-anchor it-job-anchor-start" href="' . h($start['href']) . '"><span class="it-ad it-ad-fill">' . $__ijSvg('bank') . '</span><span>' . h($start['label']) . '</span></a>'
        . $__ijPieces($j)
        . $__ijConf((string)($j['sure'] ?? 'forming'), (string)($j['confidence'] ?? ''))
        . '<div class="it-job-split">'
        . '<div class="it-job-col it-job-lblcol it-job-sides">' . $__ijSide((array)($j['left'] ?? []), true)
        . '<span class="it-job-disc it-job-disc-info it-job-disc-flow" aria-hidden="true">' . $__ijSvg('arrow') . '</span>'
        . $__ijSide((array)($j['right'] ?? []), false) . '</div>'
        . $__ijCols($j)
        . $__ijActs($j, 'check', 'arrow')
        . '</div>'
        . $__ijEnd($j)
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
<section class="it-job" data-head="penny" data-kind="<?= h((string)($__ij['kind'] ?? '')) ?>" aria-label="Penny's job">
  <h2 class="it-vh it-job-tt"><?= h($__ij['title']) ?></h2>
  <div class="it-job-body"><?= ($__ij['kind'] ?? '') === 'etransfer' ? $__ijRailEt($__ij) : $__ijRail($__ij) ?></div>
  <p class="it-job-foot"><span class="it-job-n">1 of <?= (int)$__ij['total'] ?></span><?php if ((int)$__ij['total'] > 1): ?> · <button type="button" class="it-job-skip">Skip for now</button><?php endif; ?></p>
  <p class="it-job-msg" role="status" aria-live="polite" hidden><span class="it-job-msgt"></span> <button type="button" class="it-job-undo" hidden>Undo</button></p>
  <template class="it-job-tpl" data-kind="duplicate"><?= $__ijRail(null) ?></template>
  <template class="it-job-tpl" data-kind="etransfer"><?= $__ijRailEt(null) ?></template>
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
