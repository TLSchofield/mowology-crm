<?php
/**
 * "Price history by service" card — what we billed this client/property for each service, by year,
 * from the Jobber invoice history (migration 1239). Read only.
 * Expects: $jobberPrices — the list returned by JobberPriceHistory::forProperty()/forCompany()/forContact().
 * Renders nothing when there is no history.
 */
if (empty($jobberPrices)) return;
$mwMoney = fn($v) => '$' . number_format((float)$v, 2);
?>
<div class="card mb-4">
    <div class="card-header">
        <h5 class="card-title mb-0">Price history by service</h5>
        <small class="text-muted">From Jobber invoices (before the CRM). Unit price = line total &divide; quantity, so a 4-hour job shows its hourly rate. Draft, void and bad-debt invoices are left out.</small>
    </div>
    <div class="card-body p-0 mw-bbc-scroll">
        <table class="table table-sm mb-0 mw-bbc-table">
            <thead>
                <tr><th>Service</th><th>Last billed</th><th>By year (billed &times; count &middot; unit price)</th></tr>
            </thead>
            <tbody>
            <?php foreach ($jobberPrices as $svc): $last = $svc['last']; ?>
                <tr>
                    <td><?= htmlspecialchars($svc['service']) ?><div class="text-muted small"><?= (int)$svc['count'] ?> time<?= $svc['count'] === 1 ? '' : 's' ?> billed</div></td>
                    <td class="mw-bbc-num">
                        <?= htmlspecialchars($mwMoney($last['total'])) ?>
                        <div class="text-muted small"><?= htmlspecialchars($last['date']) ?><?= $last['unit'] !== null && $last['qty'] > 1 ? ' &middot; ' . $last['qty'] + 0 . ' @ ' . htmlspecialchars($mwMoney($last['unit'])) : '' ?></div>
                    </td>
                    <td class="small">
                        <?php foreach ($svc['years'] as $yr => $y): ?>
                            <div>
                                <strong><?= (int)$yr ?></strong>
                                <?= htmlspecialchars($mwMoney($y['sum'])) ?> &times; <?= (int)$y['n'] ?>
                                <?php if (isset($y['unit_min'])): ?>
                                    <span class="text-muted">&middot; <?= $y['unit_min'] === $y['unit_max']
                                        ? htmlspecialchars($mwMoney($y['unit_min']))
                                        : htmlspecialchars($mwMoney($y['unit_min']) . '–' . $mwMoney($y['unit_max'])) ?> each</span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
