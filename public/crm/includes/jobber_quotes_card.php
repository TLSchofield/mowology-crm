<?php
/**
 * "Quotes (from Jobber)" card — what was quoted to this client and whether they took it (JobberQuoteHistory,
 * migration 1392). Read only. Expects $jobberQuotes — JobberQuoteHistory::forScope(). Renders nothing when empty.
 * An archived quote is labelled "archived", never "lost": it may have been replaced by a newer quote.
 */
if (empty($jobberQuotes)) return;
$mwCount = ['accepted' => 0, 'archived' => 0, 'open' => 0, 'draft' => 0];
foreach ($jobberQuotes as $q) $mwCount[$q['outcome']]++;
$mwBadge = ['accepted' => ['badge-success', 'Accepted'], 'archived' => ['badge-secondary', 'Archived'], 'open' => ['badge-warning', 'No answer'], 'draft' => ['badge-light', 'Draft']];
?>
<div class="card mb-4">
    <div class="card-header">
        <h5 class="card-title mb-0">Quotes (from Jobber)</h5>
        <small class="text-muted"><?= count($jobberQuotes) ?> quotes: <?= (int)$mwCount['accepted'] ?> accepted, <?= (int)$mwCount['archived'] ?> archived, <?= (int)$mwCount['open'] ?> no answer. "Archived" is not proof of a no &mdash; it may have been replaced by a newer quote.</small>
    </div>
    <div class="card-body p-0 mw-bbc-scroll">
        <table class="table table-sm mb-0 mw-bbc-table">
            <thead><tr><th>Sent</th><th>Quote</th><th>Outcome</th><th class="mw-bbc-num">Total</th></tr></thead>
            <tbody>
            <?php foreach (array_slice($jobberQuotes, 0, 25) as $q): [$cls, $lab] = $mwBadge[$q['outcome']]; ?>
                <tr>
                    <td class="text-nowrap"><?= htmlspecialchars((string)$q['date']) ?></td>
                    <td>
                        <?= htmlspecialchars((string)($q['title'] ?: 'Quote #' . $q['quote_number'])) ?>
                        <?php if ($q['lines']): ?>
                            <div class="text-muted small"><?= htmlspecialchars(implode(', ', array_map(
                                fn($l) => $l['name'] . ($l['total'] > 0 ? ' $' . number_format($l['total'], 2) : ''),
                                array_filter($q['lines'], fn($l) => $l['total'] > 0)))) ?></div>
                        <?php endif; ?>
                    </td>
                    <td><span class="badge <?= $cls ?>"><?= $lab ?></span></td>
                    <td class="mw-bbc-num">$<?= number_format((float)$q['total'], 2) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php if (count($jobberQuotes) > 25): ?><div class="text-muted small p-2">Showing the newest 25 of <?= count($jobberQuotes) ?>.</div><?php endif; ?>
    </div>
</div>
