<?php
/**
 * READ-ONLY probe: can our Stripe key read payouts, and what does a payout contain?
 *
 * Settles the unknown before Penny matches Stripe payouts to invoices (2026-10-05):
 *   - lists the last few payouts (GET /v1/payouts) and, for one, its balance
 *     transactions (GET /v1/balance_transactions?payout=…) — charges, fees, net;
 *   - maps each charge to our invoice through stripe_payments.stripe_charge_id;
 *   - measures Stripe payouts booked as income from the bank (possible double count).
 * Only GET requests are made to Stripe. Nothing is written anywhere. Admin only.
 * ?payout=po_… picks a payout; default the newest.
 */
declare(strict_types=1);
header('Content-Type: application/json');

$__dir = __DIR__;
for ($__i = 0; $__i < 5; $__i++) {
    $__dir = dirname($__dir);
    if (is_file($__dir . '/app/Core/paths.php')) {
        require_once $__dir . '/app/Core/paths.php';
        break;
    }
}
unset($__dir, $__i);

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
requireLogin();
session_write_close();
if (!isAdmin()) {
    http_response_code(403);
    echo json_encode(['error' => 'Admin only']);
    exit;
}

$vendorAutoload = PUBLIC_ROOT . '/vendor/autoload.php';
if (!file_exists($vendorAutoload)) $vendorAutoload = dirname(PUBLIC_ROOT) . '/vendor/autoload.php';
require_once $vendorAutoload;
require_once PUBLIC_ROOT . '/app_config/secrets.php';

$db = getDB();
$out = ['read_only' => true];

// 1. Stripe income booked from the bank (the possible double count).
$out['bank_stripe_income'] = $db->query("
    SELECT YEAR(t.transaction_date) AS yr, a.code, COUNT(*) AS n, ROUND(SUM(t.amount), 2) AS total
    FROM accounting_transactions t LEFT JOIN chart_of_accounts a ON a.id = t.account_id
    WHERE t.reference_type = 'bank_import' AND t.type = 'income' AND t.description LIKE '%STRIPE%'
      AND COALESCE(t.status, '') NOT IN ('void', 'deleted')
    GROUP BY yr, a.code ORDER BY yr, a.code
")->fetchAll(PDO::FETCH_ASSOC);

// 2. Can the key read payouts?
try {
    $stripe = new \Stripe\StripeClient(STRIPE_SECRET_KEY);
    $payouts = $stripe->payouts->all(['limit' => 5]);
    $out['payouts'] = array_map(fn($p) => [
        'id' => $p->id, 'amount' => $p->amount / 100, 'arrival' => date('Y-m-d', (int)$p->arrival_date), 'status' => $p->status,
    ], $payouts->data);

    $pick = (string)($_GET['payout'] ?? ($payouts->data[0]->id ?? ''));
    if ($pick !== '') {
        $lines = [];
        $charge = $db->prepare("SELECT i.invoice_number, sp.amount_cents FROM stripe_payments sp JOIN invoices i ON i.id = sp.invoice_id WHERE sp.stripe_charge_id = ? LIMIT 1");
        foreach ($stripe->balanceTransactions->all(['payout' => $pick, 'limit' => 100])->autoPagingIterator() as $bt) {
            $src = is_string($bt->source) ? $bt->source : ($bt->source->id ?? null);
            $inv = null;
            if ($src) { $charge->execute([$src]); $inv = $charge->fetch(PDO::FETCH_ASSOC) ?: null; }
            $lines[] = ['type' => $bt->type, 'amount' => $bt->amount / 100, 'fee' => $bt->fee / 100, 'net' => $bt->net / 100,
                        'source' => $src, 'invoice' => $inv['invoice_number'] ?? null];
        }
        $out['payout'] = ['id' => $pick, 'lines' => $lines,
                          'net_sum' => round(array_sum(array_column($lines, 'net')), 2),
                          'fees' => round(array_sum(array_column($lines, 'fee')), 2)];
    }
    // ?sample=N: for the last N payouts — are their invoices already counted as income,
    // and is the payout's bank line booked as income too (the double count)?
    if (!empty($_GET['sample'])) {
        $n = max(1, min(40, (int)$_GET['sample']));
        $invRow = $db->prepare("SELECT i.invoice_number, i.status, EXISTS(SELECT 1 FROM accounting_transactions t WHERE t.reference_type = 'invoice' AND t.reference_id = i.id AND t.type = 'income') AS income_row
                                FROM stripe_payments sp JOIN invoices i ON i.id = sp.invoice_id WHERE sp.stripe_charge_id = ? LIMIT 1");
        $bank = $db->prepare("SELECT t.id, t.type, a.code FROM accounting_transactions t LEFT JOIN chart_of_accounts a ON a.id = t.account_id
                              WHERE t.reference_type = 'bank_import' AND t.description LIKE '%STRIPE%' AND ABS(t.amount - ?) < 0.01
                                AND t.transaction_date BETWEEN DATE_SUB(?, INTERVAL 4 DAY) AND DATE_ADD(?, INTERVAL 4 DAY) LIMIT 1");
        $rows = [];
        foreach ($stripe->payouts->all(['limit' => $n, 'status' => 'paid'])->data as $p) {
            $amt = $p->amount / 100; $day = date('Y-m-d', (int)$p->arrival_date);
            $charges = 0; $known = 0; $counted = 0; $fees = 0.0; $other = [];
            foreach ($stripe->balanceTransactions->all(['payout' => $p->id, 'limit' => 100])->autoPagingIterator() as $bt) {
                if ($bt->type === 'payout') continue;
                $fees += $bt->fee / 100;
                if ($bt->type !== 'charge' && $bt->type !== 'payment') { $other[] = $bt->type . ' ' . ($bt->net / 100); continue; }
                $charges++;
                $src = is_string($bt->source) ? $bt->source : ($bt->source->id ?? null);
                $invRow->execute([$src]); $r = $invRow->fetch(PDO::FETCH_ASSOC);
                if ($r) { $known++; if ((int)$r['income_row']) $counted++; }
            }
            $bank->execute([$amt, $day, $day]); $b = $bank->fetch(PDO::FETCH_ASSOC) ?: null;
            $rows[] = ['payout' => $amt, 'date' => $day, 'charges' => $charges, 'invoice_known' => $known, 'invoice_has_income' => $counted,
                       'fees' => round($fees, 2), 'other' => $other, 'bank' => $b ? $b['type'] . ' ' . $b['code'] : 'not imported'];
        }
        $out['sample'] = $rows;
    }
    $out['can_read_payouts'] = true;
} catch (Throwable $e) {
    $out['can_read_payouts'] = false;
    $out['error'] = get_class($e) . ': ' . $e->getMessage();
}

echo json_encode($out, JSON_PRETTY_PRINT);
