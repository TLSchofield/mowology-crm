<?php
/**
 * Penny's badge on the crew home (homebase.php): her face + how many receipts she is asking this
 * person for → /crm/my-team.php. Renders nothing when there are none, before migration 1245, or on
 * any error (never breaks the page). Needs /crm/css/crew-team.css. Expects $user (getCurrentUser()).
 */
try {
    require_once APP_ROOT . '/Modules/Expenses/Services/MissingReceiptService.php';
    $__pbSvc = new MissingReceiptService(getDB());
    $__pbT = $__pbSvc->ready() ? $__pbSvc->totals((int)($user['id'] ?? 0)) : ['open' => 0, 'open_amount' => 0.0];
} catch (Throwable $__e) {
    error_log('Penny crew badge: ' . $__e->getMessage());
    return;
}
if ((int)$__pbT['open'] < 1) return;
$__pbN = (int)$__pbT['open'];
?>
<a class="ct-badge" href="/crm/my-team.php?penny=missing" aria-label="Penny is asking about <?= $__pbN ?> receipt<?= $__pbN === 1 ? '' : 's' ?>">
    <img src="/crm/img/heads/penny.jpg" alt="" width="44" height="44">
    <span class="ct-badge-text">
        <b>Penny has <?= $__pbN === 1 ? 'a question' : $__pbN . ' questions' ?></b>
        <?= $__pbN === 1 ? 'A card charge' : $__pbN . ' card charges' ?> (<?= htmlspecialchars(MissingReceiptService::money((float)$__pbT['open_amount'])) ?>) with no receipt yet
    </span>
    <span class="ct-count"><?= $__pbN ?></span>
</a>
