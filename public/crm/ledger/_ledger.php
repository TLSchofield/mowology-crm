<?php
/**
 * Shared start of every Ledger page (Accountant's Ledger, 2026-10-13): login, ledger.view, the
 * services, and small presentation helpers. Opened directly it does nothing.
 */
if (!defined('MW_LEDGER_PAGE')) { http_response_code(404); exit; }

require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

// A drill-down fetched in place (?part=1) answers 401 when signed out — never a redirect to the login page,
// whose HTML the journal would otherwise paste inside a table row (review 2026-10-13). The office idle
// timeout (appstack_head.php, 30 minutes) applies here too; an expand counts as activity.
if (!empty($_GET['part'])) {
    if (isLoggedIn() && function_exists('checkSessionTimeout') && class_exists('RoleRules')
        && RoleRules::hasIdleTimeout((string)($_SESSION['user_role'] ?? ''))) {
        $__last = (int)($_SESSION['last_activity'] ?? 0);
        if ($__last > 0 && time() - $__last > 1800) logoutUser();
        elseif (isLoggedIn()) $_SESSION['last_activity'] = time();
    }
    if (!isLoggedIn()) {
        http_response_code(401);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        echo 'Signed out';
        exit;
    }
}
requireLogin();
$user = getCurrentUser();
requirePermission('ledger.view');

require_once APP_ROOT . '/Modules/Ledger/Services/GifiRules.php';
require_once APP_ROOT . '/Modules/Ledger/Services/GifiService.php';
require_once APP_ROOT . '/Modules/Ledger/Services/JournalBrowserService.php';
require_once APP_ROOT . '/Modules/Ledger/Services/LockExceptionService.php';
require_once APP_ROOT . '/Modules/Ledger/Services/LedgerExport.php';
require_once APP_ROOT . '/Modules/Ledger/Services/FiscalYear.php';
require_once APP_ROOT . '/Modules/Ledger/Services/LedgerSite.php';
require_once __DIR__ . '/_nav.php';

$ledgerCanPost    = userHasPermission('ledger.post');      // admin: post, edit GIFI
$ledgerCanPropose = userHasPermission('ledger.propose');   // the accountant: propose, confirm GIFI
$ledgerIsAccountant = AccountantFence::isAccountantRole($user['role'] ?? '');
$activePage = 'ledger';

/** Money: right-aligned in tables by CSS; negatives in parentheses, as accountants read them. */
function mwLedgerMoney($n, bool $blankZero = false): string
{
    $v = round((float)$n, 2);
    if ($blankZero && abs($v) < 0.005) return '';
    $s = number_format(abs($v), 2);
    return $v < 0 ? '(' . $s . ')' : $s;
}

/** Whole dollars (GIFI). */
function mwLedgerDollars($n): string
{
    $v = (int)$n;
    return $v < 0 ? '(' . number_format(abs($v)) . ')' : number_format($v);
}

/** A branded date trigger + its hidden native input (CLAUDE.md rule 12; pattern B with a range group, a single date with $group = ''). */
function mwLedgerDate(string $id, string $name, ?string $value, string $group, string $role, string $label): void
{
    echo '<label class="mw-ledger-field"><span>' . h($label) . '</span>'
       . '<button type="button" class="mw-datepicker-trigger" data-mw-dp-commit="input" data-mw-dp-target="#' . h($id) . '"'
       . ($group !== '' ? ' data-mw-dp-range-group="' . h($group) . '" data-mw-dp-range-role="' . h($role) . '"' : '') . ' aria-haspopup="true" aria-expanded="false">'
       . '<svg class="mw-datepicker-cal-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>'
       . '<span class="mw-datepicker-date" data-mw-dp-label></span>'
       . '<svg class="mw-datepicker-chevron" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>'
       . '</button><input type="date" id="' . h($id) . '" name="' . h($name) . '" hidden value="' . h((string)$value) . '"></label>';
}
