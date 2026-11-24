<?php
/**
 * Receipt Parser Unit Tests
 *
 * Tests parseReceiptText() with Canadian receipt fixtures.
 * No Composer/PHPUnit required — pure PHP assertions.
 *
 * Usage:
 *   php tests/php/receipt-parser-test.php
 *   php tests/php/receipt-parser-test.php --verbose
 *
 * Exit code: 0 = all pass, 1 = one or more failures
 */

declare(strict_types=1);

// ── Bootstrap ────────────────────────────────────────────────────────────────
// Find the repo root by walking upward from this file's directory
$dir = __DIR__;
for ($i = 0; $i < 6; $i++) {
    $candidate = $dir . '/app/Core/paths.php';
    if (is_file($candidate)) {
        require_once $candidate;
        break;
    }
    $dir = dirname($dir);
}
if (!defined('APP_ROOT')) {
    fwrite(STDERR, "ERROR: Could not find app/Core/paths.php — run from the repo root.\n");
    exit(1);
}
require_once APP_ROOT . '/Services/Receipts/ReceiptParser.php';
// ReceiptParser.php is procedural — provides global function parseReceiptText()

$verbose = in_array('--verbose', $argv ?? [], true);

// ── Test harness ─────────────────────────────────────────────────────────────

$passed = 0;
$failed = 0;
$failures = [];

function assert_equals(string $label, mixed $expected, mixed $actual): void {
    global $passed, $failed, $failures, $verbose;
    if ($expected === $actual) {
        $passed++;
        if ($verbose) echo "  ✓ $label\n";
    } else {
        $failed++;
        $msg = "  ✗ $label\n    Expected: " . var_export($expected, true) . "\n    Got:      " . var_export($actual, true);
        $failures[] = $msg;
        echo "$msg\n";
    }
}

function assert_null(string $label, mixed $actual): void {
    assert_equals($label, null, $actual);
}

function assert_not_null(string $label, mixed $actual): void {
    global $passed, $failed, $failures, $verbose;
    if ($actual !== null) {
        $passed++;
        if ($verbose) echo "  ✓ $label\n";
    } else {
        $failed++;
        $msg = "  ✗ $label — expected non-null, got null";
        $failures[] = $msg;
        echo "$msg\n";
    }
}

function assert_within(string $label, float $expected, mixed $actual, float $delta = 0.01): void {
    global $passed, $failed, $failures, $verbose;
    $actual_f = (float) $actual;
    if (abs($expected - $actual_f) <= $delta) {
        $passed++;
        if ($verbose) echo "  ✓ $label\n";
    } else {
        $failed++;
        $msg = "  ✗ $label\n    Expected ~$expected ±$delta, got $actual";
        $failures[] = $msg;
        echo "$msg\n";
    }
}

function section(string $name): void {
    echo "\n▶ $name\n";
}

// ── Fixtures ─────────────────────────────────────────────────────────────────

/**
 * Fixture 1: City of Vancouver parking meter receipt
 * Source: Real receipt from the #preview in ReceiptReviewView
 */
$VANCOUVER_PARKING = <<<'RECEIPT'
CITY OF VANCOUVER
PARKING METER RECEIPT
123 Main St E
Station 4521
2025-05-28 14:32
Subtotal  34.47
GST 5%     1.72
Total     36.19
VISA **** 4921
Thank you
RECEIPT;

/**
 * Fixture 2: Home Depot building supplies
 */
$HOME_DEPOT = <<<'RECEIPT'
THE HOME DEPOT
Store #7041 - Burnaby BC
1177 United Blvd
Tel: (604) 555-0142

INVOICE

1x  Husqvarna String Trimmer   $399.00
2x  Safety Glasses              $24.98
3x  Work Gloves                 $44.97

Subtotal               $468.95
GST (5%)                $23.45
Total                  $492.40
MASTERCARD *4112
02/15/2025 09:44 AM
RECEIPT;

/**
 * Fixture 3: Chevron gas station
 */
$CHEVRON = <<<'RECEIPT'
CHEVRON
4521 Kingsway
Burnaby BC
V5H 2A9

PUMP 3
REGULAR UNLEADED
42.73 L @ $1.749/L

Fuel              $74.71
Subtotal          $74.71
GST                $3.74
Total             $78.45
DEBIT PURCHASE
APPROVED

2025-03-12
RECEIPT;

/**
 * Fixture 4: Tim Hortons (no explicit GST line — must back-calculate)
 */
$TIM_HORTONS = <<<'RECEIPT'
Tim Hortons
1055 West Georgia St
Vancouver BC

2x  Medium Coffee       $3.79
1x  Bagel w/ Cream Ch   $4.29

TOTAL                  $12.45
CONTACTLESS
VISA DEBIT *8832
2025-04-01 07:12
RECEIPT;

/**
 * Fixture 5: Multi-currency guard — USD amounts should not be picked up
 * as Canadian totals (parser should handle gracefully, not throw)
 */
$AMAZON_USD = <<<'RECEIPT'
Amazon.ca
Order #111-2345678

1x  Item                USD $25.99
Shipping                USD  $4.99
Total                   USD $30.98
Paid via: AMEX *3782
RECEIPT;

/**
 * Fixture 6: French-language receipt (Quebec)
 */
$QUEBEC_IGA = <<<'RECEIPT'
IGA Extra
Montréal, QC
H2X 1Y6

Épicerie             $47.83
Viande               $22.15

Sous-total           $69.98
TPS  5%               $3.50
TVQ  9.975%           $6.98
Total                $80.46
Paiement - comptant
2025-01-15
RECEIPT;

// ═════════════════════════════════════════════════════════════════════════════
// TESTS
// ═════════════════════════════════════════════════════════════════════════════

section('Fixture 1: City of Vancouver parking');
$r = parseReceiptText($VANCOUVER_PARKING);

assert_within('total = 36.19',    36.19, $r['total']);
assert_within('gst = 1.72',        1.72, $r['gst']);
assert_within('subtotal = 34.47', 34.47, $r['subtotal']);
assert_equals('date = 2025-05-28', '2025-05-28', $r['date']);
assert_equals('payment = credit_card', 'credit_card', $r['payment_method']);
assert_not_null('vendor_hint present', $r['vendor_hint']);

// ─────────────────────────────────────────────────────────────────────────────
section('Fixture 2: Home Depot');
$r = parseReceiptText($HOME_DEPOT);

assert_within('total = 492.40',    492.40, $r['total']);
assert_within('gst = 23.45',        23.45, $r['gst']);
assert_within('subtotal = 468.95', 468.95, $r['subtotal']);
assert_equals('date = 2025-02-15', '2025-02-15', $r['date']);
assert_equals('payment = credit_card', 'credit_card', $r['payment_method']);

// ─────────────────────────────────────────────────────────────────────────────
section('Fixture 3: Chevron gas station');
$r = parseReceiptText($CHEVRON);

assert_within('total = 78.45',   78.45, $r['total']);
assert_within('gst = 3.74',       3.74, $r['gst']);
assert_within('subtotal = 74.71', 74.71, $r['subtotal']);
assert_equals('date = 2025-03-12', '2025-03-12', $r['date']);
assert_equals('payment = debit', 'debit', $r['payment_method']);

// ─────────────────────────────────────────────────────────────────────────────
section('Fixture 4: Tim Hortons (GST back-calculation from total)');
$r = parseReceiptText($TIM_HORTONS);

// Total is explicit; subtotal + GST must be back-calculated at 5%
assert_within('total = 12.45', 12.45, $r['total']);
// GST should be ~0.59 (12.45 / 1.05 * 0.05 ≈ 0.593 → 0.59)
$gst_expected = round(12.45 / 1.05 * 0.05, 2);
assert_within("gst ≈ $gst_expected (back-calc)", $gst_expected, $r['gst'] ?? 0.0, 0.02);

// ─────────────────────────────────────────────────────────────────────────────
section('Fixture 5: Amazon USD receipt (graceful handling)');
$r = parseReceiptText($AMAZON_USD);

// Parser should not throw; amounts may or may not be populated but the
// payment method (AMEX → credit_card) should be detected
assert_equals('payment = credit_card (AMEX)', 'credit_card', $r['payment_method']);
// Must not return negative or obviously wrong total
if (isset($r['total']) && $r['total'] !== null) {
    $total = (float) $r['total'];
    assert_equals('total > 0 (sane)', true, $total > 0);
}

// ─────────────────────────────────────────────────────────────────────────────
section('Fixture 6: French-language (Quebec IGA — TPS/TVQ)');
$r = parseReceiptText($QUEBEC_IGA);

assert_within('total = 80.46', 80.46, $r['total']);
// TPS should be detected as GST equivalent
assert_not_null('gst present (TPS)', $r['gst']);
assert_equals('date = 2025-01-15', '2025-01-15', $r['date']);
// "comptant" (French for cash) is not yet supported — parser returns null.
// TODO: add COMPTANT → cash mapping to extractPaymentInfo() in ReceiptParser.php.
assert_null('payment = null (French "comptant" not yet recognised — known gap)', $r['payment_method']);

// ─────────────────────────────────────────────────────────────────────────────
section('Edge cases');

// Empty input must not throw
$r = parseReceiptText('');
assert_null('empty input: no total', $r['total']);
assert_null('empty input: no date',  $r['date']);

// Single amount — should become total, subtotal back-calc attempted
$r = parseReceiptText("SOME STORE\nTotal  $99.95\n2025-06-01");
assert_within('single-amount total = 99.95', 99.95, $r['total']);

// ═════════════════════════════════════════════════════════════════════════════
// Results
// ═════════════════════════════════════════════════════════════════════════════
echo "\n";
echo str_repeat('─', 56) . "\n";
printf("  PASS: %-3d   FAIL: %-3d\n", $passed, $failed);
echo str_repeat('─', 56) . "\n";

if ($failed > 0) {
    echo "\nFailed tests:\n";
    foreach ($failures as $f) {
        echo "$f\n";
    }
    exit(1);
}

echo "\n  All tests passed.\n\n";
exit(0);
