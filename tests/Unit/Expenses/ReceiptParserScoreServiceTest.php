<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ReceiptParserScoreServiceTest extends TestCase
{
    public function testReplayInputHandlesAllStoredShapes(): void
    {
        $this->assertSame(['plain', null, 'text'], ReceiptParserScoreService::replayInput('plain'));
        $vision = '{"responses":[{"fullTextAnnotation":{"text":"vision"}}]}';
        [$text, $raw, $shape] = ReceiptParserScoreService::replayInput($vision);
        $this->assertSame('vision', $text); $this->assertIsArray($raw); $this->assertSame('vision_json', $shape);
        $this->assertSame(['email', null, 'inbox_json'], ReceiptParserScoreService::replayInput('{"text":"email","parsed":{},"source":"mail"}'));
    }

    public function testTruthTreatsBlankPretaxZeroAsUnknown(): void
    {
        $this->assertNull(ReceiptParserScoreService::truthOf(['amount' => '0.00', 'total' => '10.00'], 'subtotal'));
        $this->assertSame('0.00', ReceiptParserScoreService::truthOf(['amount' => '0.00', 'total' => '0.00'], 'subtotal'));
    }

    public function testTotalMissKinds(): void
    {
        $this->assertSame('read the pre-tax amount (GST 5%)', ReceiptParserScoreService::totalMissKind('105.00', '100.00'));
        $this->assertSame('read the pre-tax amount (GST+PST 12%)', ReceiptParserScoreService::totalMissKind('112.00', '100.00'));
        $this->assertSame('no total read', ReceiptParserScoreService::totalMissKind('10.00', null));
        $this->assertStringContainsString('larger', ReceiptParserScoreService::totalMissKind('10.00', '20.00'));
        $this->assertStringContainsString('smaller', ReceiptParserScoreService::totalMissKind('10.00', '8.00'));
    }

    public function testScoreCountsDeviceAndGroupsAtMostFiveExamples(): void
    {
        $rows = [];
        for ($i = 1; $i <= 7; $i++) {
            $rows[] = ['id' => $i, 'raw_ocr_json' => 'main ' . $i, 'device_text' => $i === 1 ? 'device' : '',
                       'vendor_name' => $i < 4 ? 'A' : 'B', 'total' => '10.00', 'amount' => '10.00',
                       'gst_amount' => '0.00', 'pst_amount' => '0.00', 'expense_date' => '2026-11-01'];
        }
        $score = ReceiptParserScoreService::score($rows, function (string $text): array {
            return ['total' => $text === 'device' ? '10.00' : null, 'subtotal' => '10.00',
                    'gst' => null, 'pst' => null, 'date' => '2026-11-01'];
        });
        $this->assertSame(['right' => 0, 'of' => 7], $score['summary']['total']);
        $this->assertSame(['right' => 1, 'of' => 1], $score['device_summary']['total']);
        $this->assertSame(7, $score['misses']['no total read']['n']);
        $this->assertSame(2, $score['misses']['no total read']['vendor_count']);
        $this->assertCount(5, $score['misses']['no total read']['examples']);
    }

    public function testBriefWordingForRiseDropAndSteadyNonMonday(): void
    {
        $misses = json_encode(['no total read' => ['count' => 54, 'vendors' => 18]]);
        $old = ['run_date' => '2026-11-01', 'parser_hash' => 'old', 'total_right' => 64, 'total_of' => 100, 'misses_json' => $misses];
        $rise = ['run_date' => '2026-11-02', 'parser_hash' => 'new', 'total_right' => 737, 'total_of' => 1000, 'misses_json' => $misses];
        $item = PennyBriefAdapter::receiptScoreItem([$rise, $old], '2026-11-03');
        $this->assertStringContainsString('73.7% of 1000 approved receipts (+9.7 since 2026-11-01)', $item['text']);
        $this->assertStringContainsString('Biggest miss: no total read — 54 receipts, 18 vendors.', $item['text']);
        $drop = $rise; $drop['total_right'] = 500;
        $this->assertStringStartsWith('Warning:', PennyBriefAdapter::receiptScoreItem([$drop, $old], '2026-11-03')['text']);
        $steady = $old; $steady['run_date'] = '2026-11-02';
        $this->assertNull(PennyBriefAdapter::receiptScoreItem([$steady, $old], '2026-11-04'));
    }
}
