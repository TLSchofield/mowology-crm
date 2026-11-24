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
    // Invoices page (Tim, 2026-11-20): the line there decides invoice things only (the e-Transfers to record); with none
    // waiting, the invoice graphs take its place. Receipt duplicates, emailed receipts, month balancing and the spend chart stay on her other pages.
    $__ijInv = ((string)($activePage ?? '')) === 'invoices';
    // Record payment goes through etransfer-confirm.php, which asks for billing.edit: the e-Transfer job needs it too.
    $__ijCanBill = !function_exists('userHasPermission') || userHasPermission('billing.edit');
    // Month balancing (2026-11-10): the oldest open month's bank lines, through bank_decide (expenses.edit); off unless the switch is on.
    require_once APP_ROOT . '/Modules/Expenses/Services/PennyMonthJobAdapter.php';
    $__ijCanMonth = (!function_exists('userHasPermission') || userHasPermission('expenses.edit')) && PennyMonthJobAdapter::hasWork($__ijDb);
    // Emailed receipts (2026-11-10): Approve posts the Expenses card's own form to receipt-inbox-confirm.php (expenses.edit).
    require_once APP_ROOT . '/Modules/Expenses/Services/PennyReceiptJobAdapter.php';
    $__ijCanReceipt = (!function_exists('userHasPermission') || userHasPermission('expenses.edit')) && PennyReceiptJobAdapter::hasWork($__ijDb);
    // The line is always there (Tim, 2026-11-10): with nothing to decide it rests as a bare line, "Nothing to decide".
    if ($__ijInv) { $__ijCanMonth = false; $__ijCanReceipt = false; }
    $__ijWork = $__ijInv ? ($__ijCanBill && PennyJobAdapter::hasEtransferWork($__ijDb))
                         : (PennyJobAdapter::hasWork($__ijDb) || ($__ijCanBill && PennyJobAdapter::hasEtransferWork($__ijDb)) || $__ijCanMonth || $__ijCanReceipt);
    $__ijJobs = $__ijWork ? PennyJobAdapter::jobs($__ijDb, $__ijUser, $__ijCanBill) : [];
    if ($__ijInv) $__ijJobs = array_values(array_filter($__ijJobs, static fn($j) => ($j['kind'] ?? '') === 'etransfer'));
    if ($__ijCanReceipt) {
        // after the duplicates (a twin is settled first), before the e-Transfers and the months
        $__ijAt = 0;
        foreach ($__ijJobs as $__ijJ) { if (($__ijJ['kind'] ?? '') !== 'duplicate') break; $__ijAt++; }
        array_splice($__ijJobs, $__ijAt, 0, PennyReceiptJobAdapter::jobs($__ijDb));
    }
    if ($__ijCanMonth) foreach (PennyMonthJobAdapter::jobs($__ijDb) as $__ijM) $__ijJobs[] = $__ijM;
    foreach ($__ijJobs as $__ijI => $__ijJ) { $__ijJobs[$__ijI]['index'] = $__ijI + 1; $__ijJobs[$__ijI]['total'] = count($__ijJobs); }
    if ($__ijInv) {
        require_once APP_ROOT . '/Modules/Invoices/Services/InvoiceInsightsService.php';
        $__ijIns = InvoiceInsightsService::snapshot($__ijDb, date('Y-m-d'));
        try {
            require_once APP_ROOT . '/Modules/Accounting/Services/RecurringBillService.php';
            require_once APP_ROOT . '/Modules/Accounting/Services/CashFlowForecastService.php';
            $__ijForecast = (new CashFlowForecastService($__ijDb))->forecast();
            $__ijIns['cash'] = InvoiceInsightsService::cashLine($__ijForecast, date('Y-m-d'));
            $__ijIns['holders'] = InvoiceInsightsService::holders(array_merge((array)($__ijForecast['invoice_events'] ?? []), array_map(
                static fn($d) => ['who' => $d['who'] ?? 'client', 'amount' => $d['amount'], 'date' => '', 'late' => true],   // over 120 days late: not forecast, still owed
                (array)($__ijForecast['doubtful'] ?? []))), date('Y-m-d'));
        } catch (Throwable $__forecastError) {
            error_log('Invoice insights forecast unavailable: ' . $__forecastError->getMessage());
        }
        try {   // the target: what the business must turn over a month to cover costs and the owner's pay (the Freedom page's figure); ops_settings.invoice_monthly_target overrides
            $__ijOver = (float)($__ijDb->query("SELECT setting_value FROM ops_settings WHERE setting_key = 'invoice_monthly_target'")->fetchColumn() ?: 0);
            if ($__ijOver > 0) {
                $__ijIns['target'] = round($__ijOver);
            } else {
                // The Freedom workload runs many year-wide queries, so it runs once a day and the answer is kept (ops_settings.invoice_target_cache = "YYYY-MM-DD|amount").
                $__ijCache = explode('|', (string)($__ijDb->query("SELECT setting_value FROM ops_settings WHERE setting_key = 'invoice_target_cache'")->fetchColumn() ?: ''));
                if (($__ijCache[0] ?? '') === date('Y-m-d')) {
                    if ((float)($__ijCache[1] ?? 0) > 0) $__ijIns['target'] = (float)$__ijCache[1];
                } else {
                    require_once APP_ROOT . '/Modules/Accounting/Services/OwnerFreedomService.php';
                    $__ijOf = new OwnerFreedomService($__ijDb);
                    $__ijOfS = $__ijOf->settings();
                    $__ijOfM = OwnerFreedomService::compute($__ijOf->loadInputs(date('Y-m-d', strtotime('-365 days')), date('Y-m-d'), $__ijOfS), $__ijOfS);
                    $__ijNeed = !empty($__ijOfM['turnover_needed_year']) ? round((float)$__ijOfM['turnover_needed_year'] / 12) : 0;
                    if ($__ijNeed > 0) $__ijIns['target'] = $__ijNeed;
                    $__ijDb->prepare("INSERT INTO ops_settings (setting_key, setting_value, description, updated_by) VALUES ('invoice_target_cache', ?, 'Daily cache of the monthly turnover target shown on the Invoices page', ?)
                                      ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)")
                        ->execute([date('Y-m-d') . '|' . $__ijNeed, (int)($__ijUser['id'] ?? 0)]);
                }
            }
        } catch (Throwable $__targetError) {
            error_log('Invoice insights target unavailable: ' . $__targetError->getMessage());
        }
        $__ijPulse = null;
    } else {
        require_once APP_ROOT . '/Modules/Expenses/Services/ExpensePulseService.php';
        $__ijPulse = ExpensePulseService::load($__ijDb, time());
    }
} catch (Throwable $__e) {
    error_log('Job strip unavailable: ' . $__e->getMessage());
    return;
}

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
            . '<span class="it-job-disc it-job-disc-' . h($st) . '" role="img" aria-label="' . h($c['label'] . ': ' . ($__ijWord[$st] ?? 'info')) . '" title="' . h($c['label'] . ': ' . ($__ijWord[$st] ?? 'info')) . '">' . $__ijSvg($__ijIcon[$st] ?? 'info') . '</span>'
            . '<span class="it-job-bot" title="' . h($c['bottom']) . '">' . h($c['bottom']) . '</span></li>';
    }
    return '<ol class="it-job-cols">' . $cols . '</ol>';
};
$__ijActs = static function (?array $j, string $primaryIcon, string $secondaryIcon) use ($__ijSvg, $__ijFlags): string {
    $btn = static function (string $cls, string $icon, ?array $a) use ($__ijSvg, $__ijFlags): string {
        return '<button type="button" class="it-btn ' . $cls . '"' . ($a ? ' data-request="' . h(json_encode($a['request'], $__ijFlags)) . '"' : ' hidden') . '>'
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
    foreach ((array)($side['more'] ?? []) as $m) if ((string)$m !== '') $out .= '<span>' . h((string)$m) . '</span>';   // more lines of evidence (the month job)
    return $out . '</span>';
};
/** What the 2/3 counts, in the lower card's own words: the Interac email, the invoice, the bank deposit. */
$__ijPiecesText = static function (int $m): string {
    $out = [];
    foreach (['Interac email', 'invoice', 'bank deposit'] as $i => $name) $out[] = $name . ($i < $m ? ' ✓' : ' not yet');
    return implode(' · ', $out);
};
/** The inferred fraction (SPEC-v1.2): an equilateral tent on the rail, the whole inside, the count beside it. No words: hover or a screen reader names the three pieces. */
$__ijPieces = static function (?array $j) use ($__ijPiecesText): string {
    $m = (int)($j['pieces']['matched'] ?? 0); $t = (int)($j['pieces']['total'] ?? 3);
    $say = $__ijPiecesText($m);
    return '<div class="it-job-pieces"><span class="it-job-frac" role="img" aria-label="' . h($m . ' of ' . $t . ': ' . $say) . '" title="' . h($say) . '">'
        . '<b class="it-job-frac-n">' . $m . '</b><svg class="it-job-frac-s" aria-hidden="true" viewBox="0 0 72 72" width="72" height="72"><path d="M0 36.5H14L35 .5 56 36.5H72"/></svg><b class="it-job-frac-d">' . $t . '</b></span></div>';
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
// Month balancing rail (2026-11-10): the e-Transfer rail's frame; after the two buttons comes the month's disc (what the month
// stands at once this line is settled), then the Ledger. The other months are a quiet row in the foot.
$__ijGlyph = ['same' => '✓', 'differs' => '≠', 'missing' => '✗', 'info' => 'ℹ'];
$__ijAfter = static function (?array $j) use ($__ijIcon, $__ijSvg): string {
    $m = (array)($j['after'] ?? []);
    if ($j !== null && !$m) return '';
    $st = (string)($m['state'] ?? 'info');
    $lbl = trim((string)($m['label'] ?? '') . ': ' . (string)($m['bottom'] ?? '') . (!empty($m['carried']) ? ' (carried from the month before)' : ''), ': ');
    return '<div class="it-job-col it-job-after"><span class="it-job-top">' . h($m['top'] ?? '') . '</span>'
        . '<span class="it-job-disc it-job-disc-' . h($st) . '" role="img" aria-label="' . h($lbl) . '" title="' . h($lbl) . '">' . $__ijSvg($__ijIcon[$st] ?? 'info') . '</span>'
        . '<span class="it-job-bot">' . h($m['bottom'] ?? '') . '</span></div>';
};
$__ijMonthRow = static function (?array $j) use ($__ijGlyph): string {
    $out = '';
    foreach ((array)($j['months'] ?? []) as $m) {
        $cls = 'it-job-mchip' . (!empty($m['focus']) ? ' is-focus' : '') . (!empty($m['carried']) ? ' is-carried' : '');
        $t = $m['label'] . ': ' . $m['bottom'] . (!empty($m['carried']) ? ' (carried from the month before)' : '');
        $out .= '<span class="' . $cls . '" title="' . h($t) . '">' . h($m['top']) . ' ' . ($__ijGlyph[$m['state']] ?? '') . '</span>';
    }
    return $out;
};
$__ijRailMonth = static function (?array $j) use ($__ijSvg, $__ijConf, $__ijCols, $__ijActs, $__ijEnd, $__ijSide, $__ijAfter): string {
    $start = (array)($j['start'] ?? ['label' => 'Bank', 'href' => '/crm/accounting/bank-balance-check.php']);
    return '<div class="it-job-rail" data-kind="month">'
        . '<a class="it-job-anchor it-job-anchor-start" href="' . h($start['href']) . '"><span class="it-ad it-ad-fill">' . $__ijSvg('bank') . '</span><span>' . h($start['label']) . '</span></a>'
        . $__ijConf((string)($j['sure'] ?? 'forming'), (string)($j['confidence'] ?? ''))
        . '<div class="it-job-split">'
        . '<div class="it-job-col it-job-lblcol it-job-sides">' . $__ijSide((array)($j['left'] ?? []), true)
        . '<span class="it-job-disc it-job-disc-info it-job-disc-flow" aria-hidden="true">' . $__ijSvg('arrow') . '</span>'
        . $__ijSide((array)($j['right'] ?? []), false) . '</div>'
        . $__ijCols($j)
        . $__ijActs($j, 'check', 'x')
        . '</div>'
        . $__ijAfter($j)
        . $__ijEnd($j)
        . '</div>';
};
// Receipt rail (2026-11-10): the e-Transfer frame without the fraction — the emailed receipt above the rail (its name opens the file),
// what Penny read from it below, a disc per fact where they meet → Approve → Ledger.
$__ijRailReceipt = static function (?array $j) use ($__ijSvg, $__ijConf, $__ijCols, $__ijActs, $__ijEnd, $__ijSide): string {
    $start = (array)($j['start'] ?? ['label' => 'Receipt', 'href' => '/crm/expenses_appstack.php#receipt-inbox']);
    $left = (array)($j['left'] ?? []);
    $top = $__ijSide($left, true);
    if (($left['href'] ?? '') !== '' && preg_match('#^/crm/#', (string)$left['href'])) {
        $top = str_replace('<b>' . h((string)($left['label'] ?? '')) . '</b>', '<b><a class="it-job-rc-view" href="' . h((string)$left['href']) . '" target="_blank" rel="noopener">' . h((string)($left['label'] ?? '')) . '</a></b>', $top);
    }
    return '<div class="it-job-rail" data-kind="receipt">'
        . '<a class="it-job-anchor it-job-anchor-start" href="' . h($start['href']) . '"><span class="it-ad it-ad-fill">' . $__ijSvg('mail') . '</span><span>' . h($start['label']) . '</span></a>'
        . $__ijConf((string)($j['sure'] ?? 'forming'), (string)($j['confidence'] ?? ''))
        . '<div class="it-job-split">'
        . '<div class="it-job-col it-job-lblcol it-job-sides">' . $top
        . '<span class="it-job-disc it-job-disc-info it-job-disc-flow" aria-hidden="true">' . $__ijSvg('arrow') . '</span>'
        . $__ijSide((array)($j['right'] ?? []), false) . '</div>'
        . $__ijCols($j)
        . $__ijActs($j, 'check', 'x')
        . '</div>'
        . $__ijEnd($j)
        . '</div>';
};
// The resting line (Tim, 2026-11-10): nothing to decide, so the line runs bare from the Bank to the Ledger. The caption describes
// Penny's queue, never the books: a month can still be off the bank with nothing she can propose.
$__ijRailIdle = static function () use ($__ijSvg, $__ijEnd): string {
    return '<div class="it-job-rail" data-kind="idle">'
        . '<a class="it-job-anchor it-job-anchor-start" href="/crm/accounting/bank-balance-check.php"><span class="it-ad it-ad-fill">' . $__ijSvg('bank') . '</span><span>Bank</span></a>'
        . '<span class="it-job-idle">Nothing to decide</span>'
        . $__ijEnd(null)
        . '</div>';
};
$__ij = $__ijJobs[0] ?? null;   // null: the line rests
// The one picture beside the decision (Tim, 2026-11-10): six months of approved spend, this month marked, and the
// quarter's GST on receipts — what a solo operator needs to know while deciding. Tokens only; bars are inline SVG.
$__ijViz = static function (?array $p) use ($__ijSvg): string {
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
    return '<figure class="it-job-viz"><span class="it-ad it-ad-sm it-job-viz-node" aria-hidden="true">' . $__ijSvg('chart') . '</span><figcaption><b>Spend, last 6 months</b><span>' . h($money((float)$p['this_month'])) . ' this month</span></figcaption>'
        . '<svg viewBox="0 0 ' . $w . ' ' . $h . '" width="' . $w . '" height="' . $h . '" role="img" aria-label="Approved receipts by month, last six months">' . $bars . '</svg>'
        . '<p class="it-job-viz-gst"><b>' . h($money((float)$p['quarter_gst'])) . '</b> GST on receipts in ' . h($p['quarter_label']) . ' <span>— claim it back</span></p></figure>';
};
// Invoice graphs (Invoices page only). Each picture follows its owner-facing sentence.
$__ijMoney = static fn(float $v): string => '$' . number_format($v, $v >= 1000 ? 0 : 2);
$__ijBars = static function (string $kind, string $title, string $headline, array $rows, string $foot = ''): string {
    $w = 288; $h = 120; $pad = 8; $n = max(1, count($rows)); $gap = $n > 8 ? 4 : 8; $bw = (int)floor(($w - $pad * 2) / $n) - $gap;
    $max = 0.0; foreach ($rows as $r) $max = max($max, (float)$r[1]);
    $max = $max > 0 ? $max : 1;
    $bars = '';
    foreach ($rows as $i => $r) {
        $bh = (int)round(((float)$r[1] / $max) * 80);
        $x = $pad + $i * ($bw + $gap); $y = 96 - $bh;
        $bars .= '<rect x="' . $x . '" y="' . $y . '" width="' . $bw . '" height="' . max($bh, 1) . '" rx="3" class="' . h($r[2]) . '" style="--i:' . (int)$i . '"></rect>'
               . (($n > 8 && $i % 2 === 0) ? '' : '<text x="' . ($x + $bw / 2) . '" y="112" text-anchor="middle" class="it-viz-lbl">' . h($r[0]) . '</text>');
    }
    return '<figure class="it-job-viz it-viz-flat" data-viz="' . h($kind) . '"><figcaption><span class="it-viz-title">' . h($title) . '</span><b class="it-viz-headline">' . h($headline) . '</b></figcaption>'
        . '<svg viewBox="0 0 ' . $w . ' ' . $h . '" width="' . $w . '" height="' . $h . '" role="img" aria-label="' . h($headline) . '">' . $bars . '</svg>'
        . ($foot !== '' ? '<p class="it-job-viz-gst">' . $foot . '</p>' : '') . '</figure>';
};
$__ijShort = static function (float $v): string {   // $16.3k, $24k, −$500
    $a = abs($v);
    $t = $a >= 1000000 ? number_format($a / 1000000, 1) . 'M' : ($a >= 10000 ? number_format($a / 1000, 0) . 'k' : ($a >= 1000 ? number_format($a / 1000, 1) . 'k' : number_format($a, 0)));
    return ($v < 0 ? '−$' : '$') . $t;
};
$__ijCashViz = static function (array $cash) use ($__ijShort): string {
    $w = 288; $h = 124; $left = 6; $right = 282; $top = 26; $bottom = 98;
    $pts = $cash['points']; $n = count($pts);
    $short = $__ijShort;
    $vals = [];
    foreach ($pts as $p) { $vals[] = $p['low']; $vals[] = $p['high']; $vals[] = $p['closing']; }
    // The floor only joins the scale when cash is within reach of it; far below, it would flatten the whole line.
    $floorShown = $cash['floor'] > 0 && min($vals) < $cash['floor'] * 3;
    if ($floorShown) $vals[] = $cash['floor'];
    $min = min($vals); $max = max($vals); if ($max <= $min) $max = $min + 1;
    $x = static fn(int $i): float => round($left + ($right - $left) * $i / max(1, $n - 1), 1);
    $y = static fn(float $v): float => round($bottom - ($v - $min) / ($max - $min) * ($bottom - $top), 1);
    $line = []; $high = []; $low = []; $lowX = $left; $lowY = $bottom; $lowI = 0;
    foreach ($pts as $i => $p) {
        $px = $x($i); $py = $y((float)$p['closing']);
        $line[] = $px . ',' . $py; $high[] = $px . ',' . $y((float)$p['high']); $low[] = $px . ',' . $y((float)$p['low']);
        if ($p['start'] === $cash['low']['start']) { $lowX = $px; $lowY = $py; $lowI = $i; }
    }
    $band = implode(' ', array_merge($high, array_reverse($low)));
    $area = implode(' ', $line) . ' ' . $right . ',' . $bottom . ' ' . $left . ',' . $bottom;
    $t = static fn(float $px, float $py, string $anchor, string $txt, string $cls = 'it-viz-cash-lbl'): string =>
        '<text x="' . $px . '" y="' . max(10.0, $py) . '" text-anchor="' . $anchor . '" class="it-viz-lbl ' . $cls . '">' . h($txt) . '</text>';
    $labels = $t($left, $y((float)$pts[0]['closing']) - 7, 'start', 'Now ' . $short((float)$pts[0]['closing']))
        . ($n > 1 ? $t($right, $y((float)$pts[$n - 1]['closing']) - 7, 'end', $short((float)$pts[$n - 1]['closing'])) : '');
    if ($lowI >= 2 && $lowI <= $n - 3) {   // an interior low gets its own label; at either end the Now / end label already says it
        $labels .= $t(min(max($lowX, 30.0), 258.0), $lowY + 14 < 108 ? $lowY + 14 : $lowY - 8, 'middle', 'low ' . $short((float)$cash['low']['closing']));
    }
    $axis = '';
    for ($i = 1; $i < $n; $i++) {
        $m = date('M', strtotime($pts[$i]['start']));
        if ($m !== date('M', strtotime($pts[$i - 1]['start']))) $axis .= '<text x="' . min(max($x($i), 12.0), 276.0) . '" y="119" text-anchor="middle" class="it-viz-lbl">' . h($m) . '</text>';
    }
    $floor = '';
    if ($floorShown) {
        $fy = $y((float)$cash['floor']);
        $floor = '<line class="it-viz-floor" x1="' . $left . '" y1="' . $fy . '" x2="' . $right . '" y2="' . $fy . '"></line>'
            . $t($right, $fy + 9 < 110 ? $fy + 9 : $fy - 3, 'end', 'floor ' . $short((float)$cash['floor']), 'it-viz-cash-lbl it-viz-floor-lbl');
    }
    $foot = 'Line: expected balance. Shaded: if clients pay earlier or later (±' . number_format((float)$cash['band_pct'], 1) . '%). Bank as of ' . date('M j, Y', strtotime((string)$cash['as_of'])) . '.';
    if ($cash['thin']) $foot .= ' Some clients pay unpredictably.';
    return '<figure class="it-job-viz it-viz-flat" data-viz="cash"><figcaption><span class="it-viz-title">Predicted cash, next 13 weeks</span><b class="it-viz-headline">' . h($cash['headline']) . '</b></figcaption>'
        . '<svg viewBox="0 0 ' . $w . ' ' . $h . '" width="' . $w . '" height="' . $h . '" role="img" aria-label="' . h($cash['headline']) . '">'
        . '<polygon class="it-viz-cash-area" points="' . h($area) . '"></polygon><polygon class="it-viz-band" points="' . h($band) . '"></polygon>'
        . $floor
        . '<path class="it-viz-cash-line" pathLength="1" d="M ' . h(implode(' L ', $line)) . '"></path>'
        . '<circle class="it-viz-low" cx="' . $lowX . '" cy="' . $lowY . '" r="4"></circle>'
        . $labels . $axis . '</svg>'
        . '<p class="it-job-viz-gst">' . h($foot) . '</p></figure>';
};
// Money in: this year's months as slim columns, each beside the same month last year (hollow), and the monthly target as a dashed line.
$__ijMoneyViz = static function (array $d) use ($__ijMoney, $__ijShort): string {
    $w = 288; $pad = 8; $n = max(1, count($d['months'])); $slot = ($w - $pad * 2) / $n; $bw = max(3, (int)floor($slot / 2) - 1);
    $target = !empty($d['target']) ? (float)$d['target'] : null;
    $max = max($target ?: 0.0, 1.0);
    foreach ($d['months'] as $m) $max = max($max, (float)$m['total'], (float)($m['last_year'] ?? 0));
    $base = 96; $scale = 76; $bars = ''; $lbl = '';
    foreach ($d['months'] as $i => $m) {
        $x = round($pad + $i * $slot + ($slot - 2 * $bw - 2) / 2, 1);
        $ly = (float)($m['last_year'] ?? 0); $ty = (float)$m['total'];
        $lh = (int)round($ly / $max * $scale); $th = (int)round($ty / $max * $scale);
        if ($lh > 0) $bars .= '<rect x="' . $x . '" y="' . ($base - $lh) . '" width="' . $bw . '" height="' . $lh . '" rx="2" class="it-viz-ly" style="--i:' . (int)$i . '"></rect>';
        $bars .= '<rect x="' . ($x + $bw + 2) . '" y="' . ($base - max($th, 1)) . '" width="' . $bw . '" height="' . max($th, 1) . '" rx="2" class="' . ($m['now'] ? 'it-viz-now' : 'it-viz-yr') . '" style="--i:' . (int)$i . '"></rect>';
        $lbl .= '<text x="' . round($x + $bw + 1, 1) . '" y="112" text-anchor="middle" class="it-viz-lbl">' . h($m['label']) . '</text>';
    }
    $line = '';
    if ($target) {
        $ty = round($base - $target / $max * $scale, 1);
        $line = '<line class="it-viz-target" x1="' . $pad . '" y1="' . $ty . '" x2="' . ($w - $pad) . '" y2="' . $ty . '"></line>'
            . '<text x="' . $pad . '" y="' . max(10.0, $ty - 3) . '" text-anchor="start" class="it-viz-lbl it-viz-cash-lbl">target ' . h($__ijShort($target)) . '/mo</text>';
    }
    $foot = '<b>' . h($__ijMoney((float)$d['year_total'])) . '</b> paid in the last 12 months. Hollow bars: the same month last year.'
        . ($target ? ' Dashed: ' . h($__ijMoney($target)) . ' a month covers costs and your pay.' : '');
    return '<figure class="it-job-viz it-viz-flat" data-viz="in"><figcaption><span class="it-viz-title">Money in</span><b class="it-viz-headline">' . h($d['money_headline']) . '</b></figcaption>'
        . '<svg viewBox="0 0 ' . $w . ' 120" width="' . $w . '" height="120" role="img" aria-label="' . h($d['money_headline']) . '">' . $bars . $line . $lbl . '</svg>'
        . '<p class="it-job-viz-gst">' . $foot . '</p></figure>';
};
$__ijHoldersViz = static function (array $holders) use ($__ijMoney): string {
    if (empty($holders['rows'])) return '';
    $w = 288; $rowH = 26; $h = count($holders['rows']) * $rowH + 8; $max = max(array_column($holders['rows'], 'amount')) ?: 1;
    $bars = '';
    foreach ($holders['rows'] as $i => $row) {
        $y = 5 + $i * $rowH; $barW = max(2, (int)round((float)$row['amount'] / $max * 76));
        $class = $row['late'] ? 'it-viz-hbar it-viz-late' : 'it-viz-hbar';
        $bars .= '<text x="4" y="' . ($y + 8) . '" class="it-viz-lbl it-viz-who">' . h(function_exists('mb_strimwidth') ? mb_strimwidth((string)$row['who'], 0, 30, '…', 'UTF-8') : (string)$row['who']) . '</text>'
            . '<text x="4" y="' . ($y + 19) . '" class="it-viz-lbl it-viz-expect">' . ($row['expect'] !== '' ? 'expect ~' . h($row['expect']) : 'long overdue') . '</text>'
            . '<rect x="164" y="' . $y . '" width="' . $barW . '" height="18" rx="3" class="' . h($class) . '" style="--i:' . (int)$i . '"></rect>'
            . '<text x="284" y="' . ($y + 13) . '" text-anchor="end" class="it-viz-lbl it-viz-amount">' . h($__ijMoney((float)$row['amount'])) . '</text>';
    }
    return '<figure class="it-job-viz it-viz-flat" data-viz="holders"><figcaption><span class="it-viz-title">Who’s holding your money</span><b class="it-viz-headline">' . h($holders['headline']) . '</b></figcaption>'
        . '<svg viewBox="0 0 ' . $w . ' ' . $h . '" width="' . $w . '" height="' . $h . '" role="img" aria-label="' . h($holders['headline']) . '">' . $bars . '</svg></figure>';
};
$__ijInvViz = static function (array $d, bool $all) use ($__ijBars, $__ijCashViz, $__ijHoldersViz, $__ijMoneyViz, $__ijMoney): string {
    $money = $__ijMoneyViz($d);
    if (!$all) return $money;
    $out = !empty($d['cash']) ? $__ijCashViz($d['cash']) : '';
    $out .= !empty($d['holders']) ? $__ijHoldersViz($d['holders']) : '';
    $out .= $money;
    $pay = array_map(static fn($m) => [$m['label'], (float)($m['days'] ?? 0), $m['now'] ? 'it-viz-now' : 'it-viz-bar'], $d['pay_days']);
    $out .= $__ijBars('pay', 'Days to get paid', $d['pay_headline'], $pay,
        'Invoice date to payment date, by month paid');
    return $out;
};
itIconSprite();
?>
<?php if (!empty($__ijInv) && !$__ij): /* nothing to decide on Invoices: the invoice graphs */ ?>
<?php $__ijPanels = 2 + (!empty($__ijIns['cash']) ? 1 : 0) + (!empty($__ijIns['holders']['rows']) ? 1 : 0); ?>
<div class="it-job-outer"><section class="it-inv-viz" data-n="<?= (int)$__ijPanels ?>" aria-label="Invoice insights">
  <?= $__ijInvViz($__ijIns, true) ?></section></div>
<script src="<?= function_exists('_av') ? _av('/crm/js/ittai-viz.js') : '/crm/js/ittai-viz.js' ?>" defer></script>
<?php return; endif; ?>
<div class="it-job-outer"><div class="it-job-wrap">
<section class="it-job" data-head="penny" data-kind="<?= h((string)($__ij['kind'] ?? 'idle')) ?>" aria-label="Penny's job">
  <h2 class="it-vh it-job-tt"><?= h($__ij ? $__ij['title'] : 'Penny has nothing to decide') ?></h2>
  <div class="it-job-body"><?= !$__ij ? $__ijRailIdle() : (($__ij['kind'] ?? '') === 'receipt' ? $__ijRailReceipt($__ij) : (($__ij['kind'] ?? '') === 'month' ? $__ijRailMonth($__ij) : (($__ij['kind'] ?? '') === 'etransfer' ? $__ijRailEt($__ij) : $__ijRail($__ij))) )?></div>
  <p class="it-job-foot"<?= $__ij ? '' : ' hidden' ?>><span class="it-job-n">1 of <?= (int)($__ij['total'] ?? 0) ?></span><?php if ((int)($__ij['total'] ?? 0) > 1): ?><button type="button" class="it-job-skip">Skip for now</button><?php endif; ?><span class="it-job-mrow" aria-label="The months, oldest first"><?= ($__ij['kind'] ?? '') === 'month' ? $__ijMonthRow($__ij) : '' ?></span></p>
  <p class="it-job-msg" role="status" aria-live="polite" hidden><span class="it-job-msgt"></span> <button type="button" class="it-job-undo" hidden>Undo</button></p>
  <template class="it-job-tpl" data-kind="duplicate"><?= $__ijRail(null) ?></template>
  <template class="it-job-tpl" data-kind="etransfer"><?= $__ijRailEt(null) ?></template>
  <template class="it-job-tpl" data-kind="month"><?= $__ijRailMonth(null) ?></template>
  <template class="it-job-tpl" data-kind="idle"><?= $__ijRailIdle() ?></template>
  <template class="it-job-tpl" data-kind="receipt"><?= $__ijRailReceipt(null) ?></template>
  <dialog class="it-job-dlg" aria-label="Both receipts">
    <div class="it-job-dlg-body">
      <figure class="it-job-dlg-fig is-keep"><img alt="" src=""><figcaption></figcaption></figure>
      <figure class="it-job-dlg-fig"><img alt="" src=""><figcaption></figcaption></figure>
    </div>
    <form method="dialog"><button type="submit" class="it-job-dlg-close">Close</button></form>
  </dialog>
  <script type="application/json" class="it-job-data"><?= json_encode($__ijJobs, $__ijFlags) ?></script>
</section>
<?= !empty($__ijInv) ? $__ijInvViz($__ijIns, false) : $__ijViz($__ijPulse ?? null) ?>
</div></div>
<script src="<?= function_exists('_av') ? _av('/crm/js/ittai-job.js') : '/crm/js/ittai-job.js' ?>" defer></script>
