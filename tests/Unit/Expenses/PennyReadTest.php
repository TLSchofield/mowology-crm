<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../app/Services/Receipts/ReceiptParser.php';
require_once __DIR__ . '/../../../app/Services/Receipts/ReceiptLearning.php';

/**
 * Chevron #424 (production, 2026-10-13): the reader kept two "CRF 0.07" lines and guessed Fuel;
 * Penny read the coffee, the cokes, the deposit and the CRF. Her read now shows on the receipt
 * screens (PennyReadService), and once the owner accepts her items the reader learns the
 * vendor's layout (learnLineLayout / applyLineLayout) and reads the next one itself.
 * The OCR text is #424's shape; prices on the "next" receipt are fake.
 */
class PennyReadTest extends TestCase
{
    /** #424 as Vision returned it (the item block reads price-before-name). */
    private const RECEIPT_424 = <<<TXT
16&Macdonld Twn Pnty
3250 MacDonald St
Vancouver, BC
V6L 2N3
DATE: 2026-10-13 TIME: 08:12:37
STORE #: 43016
PRODUCT
QTY
PRICE
AMOUNT
CRF 0.07
2
0.07
0.14
T
2.49 2.49P
BISTRO 24OZ HOT BE
@ $ 3.00 PG
1 6.00 6.00
BP COKE SPARKLING
2 DIET COKE 500ML
2 Deposit (\$0.20)
GST 5.0000 % 0.42
PST 7.0000 % 0.42
CAD $
TOTAL CAD $
9.67
Debit Purchase $ 9.67
Interac
APPROVED 295570
TXT;

    /** The same layout another day, other prices: 3 CRF, a 2.79 coffee, cokes at 3.25. */
    private const RECEIPT_NEXT = <<<TXT
16&Macdonld Twn Pnty
3250 MacDonald St
Vancouver, BC
DATE: 2026-10-20 TIME: 07:58:02
PRODUCT
QTY
PRICE
AMOUNT
CRF 0.07
3
0.07
0.21
T
2.79 2.79P
BISTRO 24OZ HOT BE
@ $ 3.25 PG
1 6.50 6.50
BP COKE SPARKLING
2 DIET COKE 500ML
2 Deposit (\$0.20)
GST 5.0000 % 0.48
PST 7.0000 % 0.46
CAD $
TOTAL CAD $
10.64
Debit Purchase $ 10.64
TXT;

    /** Penny's line items on suggestion 248. */
    private const PENNY_ITEMS = [
        ['name' => 'CRF 0.07 (x2)', 'amount' => 0.14],
        ['name' => 'BISTRO 24OZ HOT BE', 'amount' => 2.49],
        ['name' => 'BP COKE SPARKLING (2 DIET COKE 500ML @ $3.00)', 'amount' => 6.00],
        ['name' => '2 Deposit', 'amount' => 0.20],
    ];

    public function test_the_reader_alone_misses_the_drinks(): void
    {
        $p = parseReceiptText(self::RECEIPT_424);
        $names = array_column($p['line_items'], 'name');
        $this->assertNotContains('BISTRO 24OZ HOT BE', $names, 'the generic pass still cannot read this layout');
    }

    public function test_a_printed_tax_rate_is_not_read_as_the_tax(): void
    {
        $this->assertSame('0.42', extractGST(self::RECEIPT_424), '"GST 5.0000 % 0.42" used to read 5.00');
        $this->assertSame('0.42', extractPST(self::RECEIPT_424));
        $this->assertSame('8.83', parseReceiptText(self::RECEIPT_424)['subtotal']);
        $this->assertSame('3.45', extractGST("GST 5% 3.45"));
        $this->assertSame('3.45', extractGST("GST \$3.45"));
        $this->assertSame('2.25', extractGST("GST Sales Tax (5%) \$2.25"));
    }

    public function test_accepted_items_are_located_on_the_receipt(): void
    {
        $layout = learnLineLayout(self::RECEIPT_424, self::PENNY_ITEMS);
        $byKey = array_column($layout, null, 'key');
        $this->assertCount(4, $layout);
        $this->assertSame(['key' => 'CRF', 'name' => 'CRF (x2)', 'off' => 3, 'idx' => 0], $byKey['CRF']);
        $this->assertSame(-1, $byKey['BISTRO24OZHOTBE']['off'], 'the coffee price is printed above its name');
        $this->assertSame(-1, $byKey['BPCOKESPARKLING']['off']);
        $this->assertSame('BP COKE SPARKLING (2 DIET COKE 500ML)', $byKey['BPCOKESPARKLING']['name'], 'prices are not kept in names');
        $this->assertSame(0, $byKey['DEPOSIT']['off']);
    }

    public function test_the_next_same_layout_receipt_is_read_by_the_reader_itself(): void
    {
        $layout = learnLineLayout(self::RECEIPT_424, self::PENNY_ITEMS);
        $p = parseReceiptText(self::RECEIPT_NEXT, null, ['layout' => $layout]);
        $got = array_map(fn($i) => [$i['name'], $i['amount']], $p['line_items']);
        $this->assertSame([
            ['CRF (x2)', '0.21'],
            ['BISTRO 24OZ HOT BE', '2.79'],
            ['BP COKE SPARKLING (2 DIET COKE 500ML)', '6.50'],
            ['2 Deposit', '0.20'],
        ], $got);
        $this->assertSame('9.70', $p['subtotal']);
        $this->assertSame('match', $p['line_items_quality'], 'adds up, so no Vision / LLM escalation');
        $this->assertSame('layout', $p['line_items'][0]['name_source']);
    }

    public function test_a_layout_that_does_not_add_up_never_replaces_the_readers_items(): void
    {
        $parsed = ['subtotal' => '20.00', 'total' => '21.00', 'line_items' => [['name' => 'A', 'amount' => '20.00']]];
        $out = mergeLayoutItems($parsed, [['name' => 'X', 'amount' => '3.00']]);
        $this->assertSame('A', $out['line_items'][0]['name']);
        $this->assertSame([], applyLineLayout("nothing\nhere 1.00", learnLineLayout(self::RECEIPT_424, self::PENNY_ITEMS)));
    }

    public function test_reader_vs_kept_items_differ_by_amounts_not_names(): void
    {
        $reader = [['name' => 'CRF 0.07', 'amount' => '0.07'], ['name' => 'CRF 0.07', 'amount' => '0.07']];
        $kept = array_map(fn($i) => ['name' => $i['name'], 'line_total' => $i['amount']], self::PENNY_ITEMS);
        $this->assertTrue(readerItemsDiffer($reader, $kept));
        $renamed = [['name' => 'Bistro coffee', 'line_total' => '2.49'], ['name' => 'Coke', 'line_total' => 6]];
        $this->assertFalse(readerItemsDiffer([['name' => 'BISTRO', 'amount' => 2.49], ['name' => 'COKE', 'amount' => '6.00']], $renamed));
        $this->assertTrue(readerItemsDiffer(['CRF 0.07', 'CRF 0.07'], $kept), 'names only when the reader has no amounts');
    }

    // ── PennyReadService ─────────────────────────────────────────────────────

    private function expense(array $over = []): array
    {
        return $over + ['id' => 424, 'created_by' => 6, 'status' => 'pending_approval', 'forwarded_to_accounting' => 0,
            'vendor_display' => 'CHEVRON', 'accounting_category' => 'Fuel', 'asset_tag' => null, 'job_id' => null,
            'amount' => '8.83', 'gst_amount' => '0.42', 'pst_amount' => '0.42', 'total' => '9.67'];
    }

    private function suggestion(): array
    {
        $f = fn($v, $r = 'r', $c = 'high') => ['value' => $v, 'reason' => $r, 'confidence' => $c];
        return [
            'vendor' => $f('Chevron (16 & Macdonald Town Pantry, 3250 MacDonald St)'),
            'accounting_category' => $f('Meals', "Coffee and diet cokes, no fuel. The reader's 'Fuel' is wrong."),
            'asset_tag' => $f('none'), 'job' => $f(null),
            'subtotal' => $f(8.83, 'No subtotal printed', 'low'), 'gst' => $f(0.42), 'pst' => $f(0.42), 'total' => $f(9.67),
            'line_items' => self::PENNY_ITEMS, 'notes' => 'The reader called this Fuel.',
        ];
    }

    public function test_only_the_fields_she_reads_differently_are_shown(): void
    {
        $d = PennyReadService::differences($this->suggestion(), $this->expense());
        $this->assertSame(['accounting_category'], array_column($d, 'field'), 'Chevron (…) is the same vendor; amounts agree');
        $this->assertSame('Meals', $d[0]['penny']);
        $this->assertSame('Fuel', $d[0]['receipt']);
        $this->assertStringContainsString('no fuel', $d[0]['reason']);

        $items = PennyReadService::itemsDiff($this->suggestion(), [['name' => 'CRF 0.07', 'line_total' => '0.07'], ['name' => 'CRF 0.07', 'line_total' => '0.07']]);
        $this->assertNotNull($items);
        $this->assertSame(8.83, $items['penny_sum']);
        $this->assertSame(0.14, $items['receipt_sum']);
        $same = array_map(fn($i) => ['name' => $i['name'], 'line_total' => $i['amount']], self::PENNY_ITEMS);
        $this->assertNull(PennyReadService::itemsDiff($this->suggestion(), $same));
    }

    public function test_who_sees_and_applies_her_read(): void
    {
        $e = $this->expense();
        $owner = PennyReadService::rights($e, ['id' => 1, 'office' => true, 'can_approve' => true]);
        $this->assertSame(['see' => true, 'apply' => true, 'approve' => true, 'keep' => true], $owner);

        $crewOwn = PennyReadService::rights($e, ['id' => 6, 'office' => false, 'can_approve' => false]);
        $this->assertTrue($crewOwn['see']);
        $this->assertTrue($crewOwn['apply'], 'crew may apply on their own open receipt (left for the office)');
        $this->assertFalse($crewOwn['approve']);
        $this->assertFalse($crewOwn['keep']);

        $crewOther = PennyReadService::rights($e, ['id' => 9, 'office' => false, 'can_approve' => false]);
        $this->assertFalse($crewOther['see']);
        $this->assertFalse($crewOther['apply']);

        $approved = PennyReadService::rights($this->expense(['status' => 'approved']), ['id' => 6, 'office' => false, 'can_approve' => false]);
        $this->assertFalse($approved['apply'], 'an approved receipt is the office\'s to change');
        $sent = PennyReadService::rights($this->expense(['forwarded_to_accounting' => 1]), ['id' => 1, 'office' => true, 'can_approve' => true]);
        $this->assertFalse($sent['apply']);
    }

    public function test_her_items_become_expense_lines(): void
    {
        $lines = BookkeeperDeskService::pennyItems(['line_items' => self::PENNY_ITEMS + [4 => ['name' => '', 'amount' => 1]]]);
        $this->assertCount(4, $lines);
        $this->assertSame(['name' => 'CRF 0.07 (x2)', 'quantity' => 1, 'unit_price' => null, 'line_total' => 0.14], $lines[0]);
    }
}
