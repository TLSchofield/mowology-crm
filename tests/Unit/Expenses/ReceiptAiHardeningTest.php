<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** The receipt AI layer after the 2026-10-26 audit: what the model says is made safe before it is used. */
class ReceiptAiHardeningTest extends TestCase
{
    private function ans(array $over = []): array
    {
        $f = fn($v, $r = 'printed') => ['value' => $v, 'reason' => $r, 'confidence' => 'high'];
        return $over + ['vendor' => $f('Shell'), 'accounting_category' => $f('Fuel'), 'asset_tag' => $f('truck'), 'job' => $f(null),
                        'subtotal' => $f(40.0), 'gst' => $f(2.0), 'pst' => $f(0.0), 'total' => $f(42.0), 'line_items' => [], 'notes' => ''];
    }

    public function testAnUnusableAmountIsClearedNotApplied(): void
    {
        foreach ([-5.0, 1e309, 250000.0, 'abc'] as $bad) {
            $s = ReceiptBookkeeperService::sanitize($this->ans(['total' => ['value' => $bad, 'reason' => 'x', 'confidence' => 'high']]));
            $this->assertNull($s['total']['value'], 'total ' . var_export($bad, true));
            $this->assertSame('low', $s['total']['confidence']);
        }
        $ok = ReceiptBookkeeperService::sanitize($this->ans());
        $this->assertSame(42.0, $ok['total']['value'], 'a normal amount is untouched');
        $this->assertSame(0.0, $ok['pst']['value'], 'zero is a real answer');
    }

    public function testACategoryNotOnTheOwnersListIsCleared(): void
    {
        $s = ReceiptBookkeeperService::sanitize($this->ans(['accounting_category' => ['value' => 'Crypto mining', 'reason' => 'x', 'confidence' => 'high']]));
        $this->assertNull($s['accounting_category']['value']);
        $this->assertSame('Fuel', ReceiptBookkeeperService::sanitize($this->ans())['accounting_category']['value']);
    }

    public function testFreeTextIsCutAndLineItemsAreBounded(): void
    {
        $long = str_repeat('x', 5000);
        $items = array_fill(0, 100, ['name' => 'Mulch', 'amount' => 10.0]);
        $items[3] = ['name' => 'Bad', 'amount' => 1e12];
        $s = ReceiptBookkeeperService::sanitize($this->ans(['notes' => $long, 'line_items' => $items, 'vendor' => ['value' => $long, 'reason' => $long, 'confidence' => 'low']]));
        $this->assertSame(600, mb_strlen($s['notes']));
        $this->assertSame(120, mb_strlen($s['vendor']['value']));
        $this->assertSame(300, mb_strlen($s['vendor']['reason']));
        $this->assertLessThanOrEqual(60, count($s['line_items']));
        foreach ($s['line_items'] as $it) $this->assertLessThan(100000, abs((float)$it['amount']));
    }

    public function testTheLineItemGateRefusesWildNumbersAndEmptySubtotalNonsense(): void
    {
        $row = fn(string $n, float $lt, float $q = 1.0) => ['name' => $n, 'line_total' => number_format($lt, 2, '.', ''), 'quantity' => $q];
        $text = 'MULCH BARK 12.00 SOIL 8.00';
        $this->assertNull(validateLlmLineItems([$row('Mulch bark', 12.0), $row('Soil', 8.0)], $text, 20.0), 'a normal receipt passes');
        $this->assertNull(validateLlmLineItems([$row('Mulch bark', 12.0), $row('Soil', 8.0), $row('Mulch bark', -2.0)], $text, 18.0), 'a discount line is fine when it adds up');
        $this->assertSame('out_of_range', validateLlmLineItems([$row('Mulch bark', 99999999.0)], $text, null));
        $this->assertSame('out_of_range', validateLlmLineItems([$row('Mulch bark', 5.0, 1e9)], $text, null));
        $this->assertSame('no_positive_total', validateLlmLineItems([$row('Mulch bark', -99.0)], $text, null), 'no subtotal and a negative sum: refuse');
        $this->assertSame('too_many_items', validateLlmLineItems(array_fill(0, 81, $row('Mulch bark', 1.0)), $text, null));
        $this->assertNull(validateLlmLineItems([$row('Mulch bark', 5.0)], $text, null), 'no subtotal but a positive sum: the name gate still decides');
    }

    public function testUsageIsRecordedAndNeverBreaksAReceipt(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        LlmUsageLog::record($db, 'receipt_llm', 'claude-opus-5', 100, 20, true, 5, 'x');   // no table yet: quiet
        $db->exec("CREATE TABLE llm_usage_log (id INTEGER PRIMARY KEY AUTOINCREMENT, purpose TEXT, model TEXT, input_tokens INTEGER, output_tokens INTEGER, ok INTEGER, expense_id INTEGER, note TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)");
        LlmUsageLog::record($db, 'receipt_llm', 'claude-opus-5', 1200, 80, false, 7, 'sum_mismatch');
        $r = $db->query("SELECT * FROM llm_usage_log")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('receipt_llm', $r['purpose']);
        $this->assertSame(1200, (int)$r['input_tokens']);
        $this->assertSame(0, (int)$r['ok']);
        $this->assertSame('sum_mismatch', $r['note']);
    }

    public function testAnEmptyOrHugeOcrTakesNothingFromTheAllowance(): void
    {
        $src = file_get_contents(dirname(__DIR__, 3) . '/app/Services/Receipts/ReceiptLlmExtractor.php');
        $this->assertLessThan(strpos($src, "LlmDailyCap::take(getDB(), 'receipt_llm'"), strpos($src, "OCR text empty or too long"),
            'the OCR check comes before the slot is taken');
    }
}

