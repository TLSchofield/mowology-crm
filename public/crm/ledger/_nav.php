<?php
/**
 * The Ledger's sub-nav and page list — shared by the Ledger pages and the statements they link to
 * (/crm/accounting/balance-sheet.php, income-statement.php, trial-balance.php), so the balance sheet and
 * income statement are reachable from the Ledger and the Ledger from them (review 2026-10-13).
 * appstack_sidebar.php's accountant-only sidebar reads mwLedgerPages() too. Opened directly it does nothing.
 */
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) { http_response_code(404); exit; }

if (!function_exists('mwLedgerPages')) {
    /** key => [label, href, feather icon]: one list for the sub-nav and the accountant's sidebar. */
    function mwLedgerPages(): array
    {
        return [
            'overview'   => ['Overview', '/crm/ledger/', 'book'],
            'tb'         => ['Trial balance', '/crm/ledger/trial-balance.php', 'columns'],
            'gl'         => ['General ledger', '/crm/ledger/general-ledger.php', 'book-open'],
            'journal'    => ['Journal', '/crm/ledger/journal.php', 'list'],
            'bs'         => ['Balance sheet', '/crm/accounting/balance-sheet.php', 'layers'],
            'is'         => ['Income statement', '/crm/accounting/income-statement.php', 'trending-up'],
            'gifi'       => ['GIFI mapping', '/crm/ledger/gifi.php', 'hash'],
            'exceptions' => ['Exceptions', '/crm/ledger/exceptions.php', 'alert-triangle'],
        ];
    }

    /** The Ledger sub-nav. */
    function mwLedgerNav(string $active, bool $canInvite): void
    {
        $items = [];
        foreach (mwLedgerPages() as $k => [$label, $href]) $items[$k] = [$label, $href];
        if ($canInvite) $items['access'] = ['Accountant access', '/crm/ledger/invite.php'];
        if (!empty($GLOBALS['ledgerIsAccountant']) || !empty($GLOBALS['mwLedgerOnly'])) $items['signout'] = ['Sign out', '/crm/logout_secure.php?stay=1'];
        echo '<nav class="mw-ledger-nav" aria-label="Ledger"><span class="mw-ledger-nav-title">Ledger</span><ul>';
        foreach ($items as $k => [$label, $href]) {
            $cur = $k === $active ? ' aria-current="page" class="is-active"' : '';
            echo '<li><a href="' . htmlspecialchars($href, ENT_QUOTES) . '"' . $cur . '>' . htmlspecialchars($label, ENT_QUOTES) . '</a></li>';
        }
        echo '</ul></nav>';
    }
}
