<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The full brain page (/crm/brain.php, BrainPageService): tier counts for the tier bar,
 * the plain-English line, and the client view — which must not carry a single name,
 * raw bank wording, note or key in what the server sends.
 */
class BrainPageTest extends TestCase
{
    private static function brain(): array
    {
        $items = [
            HeadBrain::item('payee:point sale macdon', 'Point Sale Macdon', 107, ['group' => 'Bank payees', 'streak' => 107, 'at' => '2026-10-06 10:00:00']),
            HeadBrain::item('payee:etransfer debit tim schofield', 'Etransfer Debit Tim Schofield', 57, ['group' => 'Bank payees', 'streak' => 57]),
            HeadBrain::item('payee:point sale tlnk', 'TransLink', 12, ['group' => 'Bank payees', 'raw' => 'Point Sale Tlnk', 'corrected_recently' => true]),
            HeadBrain::item('payee:telus mobility', 'Telus Mobility', 1, ['group' => 'Bank payees']),
            HeadBrain::item('vendor:12', 'Rona Lonsdale', 6, ['group' => 'Receipt vendors', 'note' => '$412.50 last time']),
            HeadBrain::item('vendor:13', 'Jane Doe Lawn Supply', null, ['group' => 'Receipt vendors']),
        ];
        $b = HeadBrain::withItems(HeadBrain::combine(['lessons' => 3], ['lessons' => ['reading lesson remembered', 'reading lessons remembered']]), $items);
        return $b + ['since' => '2026-10-05', 'learning' => [
            HeadBrain::item('payee:wave pyrl', 'Wave Pyrl', 1, ['group' => 'Bank payees']) + ['learning' => true],
        ]];
    }

    public function test_tier_counts_cover_all_seven_strongest_first(): void
    {
        $t = HeadBrain::tierCounts(self::brain()['parts']);
        $this->assertSame(['platinum', 'white', 'gold', 'silver', 'bronze', 'black', 'obsidian'], array_column($t, 'slug'));
        $n = array_column($t, 'n', 'slug');
        $this->assertSame(2, $n['platinum']);
        $this->assertSame(1, $n['gold']);
        $this->assertSame(1, $n['silver']);
        $this->assertSame(1, $n['bronze'], 'strength not tracked reads as Bronze, as drawn');
        $this->assertSame(1, $n['obsidian']);
        $this->assertSame(6, array_sum($n), 'counted things (no strength) are not tiers');
    }

    public function test_owner_view_lists_items_by_group_and_tier_with_a_plain_line(): void
    {
        $v = BrainPageService::view('penny', self::brain(), 0.8, false);
        $this->assertSame(9, $v['units']);
        $this->assertSame(10, $v['shape']);
        $this->assertSame('platinum', $v['top']['slug']);
        $this->assertSame(['Bank payees', 'Receipt vendors'], array_column($v['groups'], 'name'));
        $bank = $v['groups'][0];
        $this->assertSame(['platinum', 'gold', 'obsidian'], array_column($bank['tiers'], 'slug'));
        $this->assertSame('Point Sale Macdon', $bank['tiers'][0]['items'][0]['label'], 'strongest first');
        $this->assertSame('107 in a row', $bank['tiers'][0]['items'][0]['note']);
        $this->assertSame(100, $bank['tiers'][0]['items'][0]['bar']);
        $this->assertSame('Point Sale Tlnk', $bank['tiers'][1]['items'][0]['raw']);
        $this->assertTrue($bank['tiers'][1]['items'][0]['corrected']);
        $this->assertSame("Penny knows 4 bank payees and 2 receipt vendors; 2 she's handled 50+ times in a row without a correction.", $v['line']);
        $this->assertSame([['label' => '3 reading lessons remembered', 'n' => 3]], $v['counted']);
        $this->assertSame('Wave Pyrl', $v['learning'][0]['label']);
        $this->assertSame(1, $v['learning_n']);
        $this->assertSame('payee:point sale macdon', $v['parts'][0]['key'], 'the owner view lights the same triangles as the card');
    }

    public function test_client_view_carries_no_names_raw_wording_notes_or_keys(): void
    {
        $v = BrainPageService::view('penny', self::brain(), 0.8, true);
        $json = json_encode($v);
        foreach (['Macdon', 'Tim Schofield', 'Schofield', 'TransLink', 'Tlnk', 'Telus', 'Rona', 'Jane Doe', 'Wave', '412', 'payee:', 'vendor:'] as $private) {
            $this->assertStringNotContainsStringIgnoringCase($private, $json, $private . ' leaked into the client view');
        }
        // Still everything a client is meant to see: the brain, tiers, counts and categories.
        $this->assertSame(9, $v['units']);
        $this->assertCount(6, $v['parts'], 'one triangle per thing learned');
        $this->assertMatchesRegularExpression('/^i:[0-9a-f]{12}$/', $v['parts'][0]['key']);
        $this->assertSame($v['parts'], BrainPageService::view('penny', self::brain(), 0.8, true)['parts'], 'the same triangles every load');
        $this->assertSame(['suppliers and payees recognised', 'suppliers recognised from receipts'], array_column($v['groups'], 'name'));
        $this->assertSame([], array_merge(...array_map(fn($g) => array_merge(...array_column($g['tiers'], 'items')), $v['groups'])));
        $this->assertSame([], $v['learning']);
        $this->assertSame('4 suppliers and payees recognised · 2 suppliers recognised from receipts · 2 at Platinum', $v['line']);
        $this->assertStringContainsString('receipt and payment', $v['about']);
    }

    public function test_an_empty_brain_reads_plainly(): void
    {
        $v = BrainPageService::view('sam', ['units' => 0, 'parts' => []], 0.5, false);
        $this->assertSame(1, $v['shape']);
        $this->assertNull($v['top']);
        $this->assertSame('Nothing learned yet: every decision you make teaches him something.', $v['line']);
    }

    public function test_strength_bar_is_log_scaled(): void
    {
        $this->assertSame(0, BrainPageService::bar(null));
        $this->assertSame(18, BrainPageService::bar(1));
        $this->assertSame(46, BrainPageService::bar(5));
        $this->assertSame(100, BrainPageService::bar(50));
        $this->assertSame(100, BrainPageService::bar(500));
    }
}
