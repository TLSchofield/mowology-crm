<?php
/**
 * My Team — the department heads, on the crew app (standalone crew page, NOT AppStack).
 *
 * Starts with Penny (bookkeeper): the card charges she can't find a receipt for, asked of the
 * person who probably made them (MissingReceiptService, migration 1245). More heads (Otto: route /
 * visit questions) slot in as more entries in $heads, each an include under includes/.
 *
 * Deep link (push tap): /crm/my-team.php?penny=missing&id=N — scrolls to and highlights item N.
 * Like homebase.php this page builds its own <head>: there is NO window.MW_CSRF_TOKEN here.
 * crew-team.js uses the page's own token and refreshes it from /crm/api/get-csrf.php on a 403.
 */
declare(strict_types=1);

if (!defined('APP_ROOT')) {
    $__dir = __DIR__;
    for ($__i = 0; $__i < 5; $__i++) {
        $__dir = dirname($__dir);
        if (is_file($__dir . '/app/Core/paths.php')) {
            require_once $__dir . '/app/Core/paths.php';
            break;
        }
    }
    unset($__dir, $__i);
}

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
require_once CRM_INCLUDES . '/functions.php';
require_once APP_ROOT . '/Modules/Expenses/Services/PennyQuestionService.php';
require_once APP_ROOT . '/Modules/Expenses/Services/MissingReceiptService.php';

requireLogin();
$user = getCurrentUser();
$csrf = generateCSRFToken();     // before the close — the token lives in the session
session_write_close();

$firstName = PennyQuestionService::firstName((array)$user);
$focusId = (($_GET['penny'] ?? '') === 'missing') ? (int)($_GET['id'] ?? 0) : 0;

// ── Penny ──────────────────────────────────────────────────────────────────────
$__pc = ['name' => $firstName, 'items' => [], 'focus' => $focusId, 'ready' => false, 'focus_status' => null];
try {
    $mrs = new MissingReceiptService(getDB());
    if ($mrs->ready()) {
        $__pc['ready'] = true;
        foreach ($mrs->openFor((int)$user['id']) as $i) {
            $__pc['items'][] = ['id' => (int)$i['id'], 'ask' => MissingReceiptService::ask($i, $firstName),
                                'amount' => (float)$i['amount'], 'date' => (string)$i['charge_date'],
                                'time' => $i['charge_time'] ?? null, 'vendor' => (string)$i['vendor_label'],
                                'basis_note' => (string)($i['basis_note'] ?? '')];
        }
        if ($focusId && !in_array($focusId, array_column($__pc['items'], 'id'), true)) {
            $it = $mrs->item($focusId);
            if ($it && (int)$it['user_id'] === (int)$user['id']) $__pc['focus_status'] = (string)$it['status'];
        }
    }
} catch (Throwable $e) {
    error_log('my-team.php Penny: ' . $e->getMessage());
}

// Heads shown to crew. Only Penny for now; Otto (route / visit questions) is next.
$heads = [['slug' => 'penny', 'include' => __DIR__ . '/includes/penny-crew-card.php']];
$back = '/crm/homebase.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0D3B2E">
    <title>My Team — Mowology</title>
    <link rel="manifest" href="/assets/favicon/site.webmanifest">
    <link rel="icon" href="/assets/favicon/favicon.ico">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="/crm/css/tokens.css?v=20260410a" rel="stylesheet">
    <link href="/crm/css/crew-team.css?v=20261007a" rel="stylesheet">
    <script src="/crm/js/sw-register.js?v=20260410a" defer></script>
    <script src="/crm/js/mw-haptics.js?v=20260410a" defer></script>
    <script src="/crm/js/capacitor-bridge.js?v=20261007p" defer></script>
    <script src="/crm/js/crew-team.js?v=20261007a" defer></script>
</head>
<body class="ct-body">
<header class="ct-bar">
    <a class="ct-back" href="<?= htmlspecialchars($back) ?>" aria-label="Back">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>
    </a>
    <div>
        <div class="ct-bar-title">My team</div>
        <div class="ct-bar-sub">The office, asking you directly</div>
    </div>
</header>

<main class="ct-main" id="ct-main" data-csrf="<?= htmlspecialchars($csrf) ?>" data-focus="<?= (int)$focusId ?>"
      data-focus-status="<?= htmlspecialchars((string)($__pc['focus_status'] ?? '')) ?>">
    <?php foreach ($heads as $__head) include $__head['include']; ?>
</main>

<!-- Bottom sheet: "It's already in" / "No receipt" -->
<div class="ct-sheet" id="ct-sheet" hidden>
    <div class="ct-sheet-scrim" data-close></div>
    <div class="ct-sheet-panel" role="dialog" aria-modal="true" aria-labelledby="ct-sheet-title">
        <div class="ct-sheet-handle"></div>
        <h2 class="ct-sheet-title" id="ct-sheet-title"></h2>
        <div class="ct-sheet-body" id="ct-sheet-body"></div>
        <button type="button" class="ct-btn ct-btn-quiet ct-sheet-cancel" data-close>Cancel</button>
    </div>
</div>
<div class="ct-toast" id="ct-toast" role="status" aria-live="polite" hidden></div>
</body>
</html>
