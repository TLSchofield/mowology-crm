<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Per-item strength in the heads' brains (Tim, 2026-10-07): the 7-tier ladder
 * (HeadBrain::TIERS), a correction drops one tier, and what each head reports as items.
 */
class BrainTiersTest extends TestCase
{
    private static function slug(?int $s): string
    {
        return HeadBrain::tier($s)['slug'];
    }

    public function test_tier_boundaries(): void
    {
        $cases = [1 => 'obsidian', 2 => 'black', 3 => 'bronze', 4 => 'bronze', 5 => 'silver', 9 => 'silver',
                  10 => 'gold', 19 => 'gold', 20 => 'white', 49 => 'white', 50 => 'platinum', 500 => 'platinum'];
        foreach ($cases as $strength => $slug) {
            $this->assertSame($slug, self::slug($strength), "strength {$strength}");
        }
        $this->assertSame('obsidian', self::slug(0), 'below 1 is still first seen');
        $this->assertSame('bronze', self::slug(null), 'unset strength shows as Bronze');
        $this->assertSame(['obsidian', 'black', 'bronze', 'silver', 'gold', 'white', 'platinum'], array_column(HeadBrain::TIERS, 0));
    }

    public function test_a_correction_drops_exactly_one_tier(): void
    {
        foreach ([2, 3, 4, 5, 7, 9, 10, 15, 19, 20, 35, 49, 50, 120] as $s) {
            $before = HeadBrain::tier($s)['rank'];
            $this->assertSame($before - 1, HeadBrain::tier(HeadBrain::afterCorrection($s))['rank'], "from {$s}");
        }
        $this->assertSame(1, HeadBrain::afterCorrection(1), 'Obsidian has nowhere lower to go');
    }

    public function test_strength_folds_confirmations_and_corrections_in_order(): void
    {
        $h = HeadBrain::strengthFromHistory([true, true, true, true, true, true]);
        $this->assertSame(6, $h['strength']);
        $this->assertSame(6, $h['streak']);
        $this->assertFalse($h['corrected_recently']);

        // Gold (12), corrected → bottom of Silver (5), then two more kept → 7, still Silver.
        $h = HeadBrain::strengthFromHistory(array_merge(array_fill(0, 12, true), [false, true, true]));
        $this->assertSame(7, $h['strength']);
        $this->assertSame(2, $h['streak']);
        $this->assertTrue($h['corrected_recently']);
        $this->assertSame('silver', self::slug($h['strength']));

        $this->assertSame(1, HeadBrain::strengthFromHistory([false])['strength'], 'a first correction is still first seen');
        $this->assertSame(0, HeadBrain::strengthFromHistory([])['strength']);
    }

    public function test_items_each_light_a_triangle_and_come_first_strongest_first(): void
    {
        $brain = HeadBrain::combine(['badges' => 2], ['badges' => ['badge', 'badges']]);
        $out = HeadBrain::withItems($brain, [
            HeadBrain::item('vendor:2', 'Rona', 3),
            HeadBrain::item('vendor:1', 'Chevron', 6, ['group' => 'Receipt vendors', 'streak' => 6]),
        ]);
        $this->assertSame(4, $out['units']);
        $this->assertSame(['vendor:1', 'vendor:2', 'badges'], array_column($out['parts'], 'key'));
        $this->assertSame('Receipt vendors', $out['parts'][0]['group']);
    }

    public function test_template_lessons_start_at_the_rewrite(): void
    {
        $rows = [
            ['template_key' => 'first_nudge', 'channel' => 'email', 'status' => 'sent', 'decided_at' => '2026-09-01'],
            ['template_key' => 'first_nudge', 'channel' => 'email', 'status' => 'edited', 'decided_at' => '2026-09-02'],
            ['template_key' => 'first_nudge', 'channel' => 'email', 'status' => 'sent', 'decided_at' => '2026-09-03'],
            ['template_key' => 'first_nudge', 'channel' => 'email', 'status' => 'sent', 'decided_at' => '2026-09-04'],
            ['template_key' => 'last_call', 'channel' => 'sms', 'status' => 'sent', 'decided_at' => '2026-09-05'],
        ];
        $items = HeadBrain::templateItemsFrom($rows, 'Follow-ups', SamBrainService::SITUATIONS);
        $this->assertCount(1, $items, 'never rewritten = nothing learned');
        $this->assertSame('template:first_nudge:email', $items[0]['key']);
        $this->assertSame('First nudge (email)', $items[0]['label']);
        $this->assertSame(3, $items[0]['strength']);
    }

    public function test_penny_vendors_keep_their_streak_and_a_correction_drops_a_tier(): void
    {
        $rows = [];
        for ($i = 0; $i < 6; $i++) $rows[] = ['vendor_id' => 12, 'vendor' => 'Chevron', 'status' => 'accepted', 'decided_at' => "2026-09-0{$i}"];
        for ($i = 0; $i < 10; $i++) $rows[] = ['vendor_id' => 7, 'vendor' => 'Rona', 'status' => 'accepted', 'decided_at' => '2026-09-10'];
        $rows[] = ['vendor_id' => 7, 'vendor' => 'Rona', 'status' => 'edited', 'decided_at' => '2026-09-11'];
        $rows[] = ['vendor_id' => null, 'vendor' => 'Corner Store', 'status' => 'accepted', 'decided_at' => '2026-09-12'];
        $items = array_column(PennyBrainService::vendorItemsFrom($rows), null, 'key');

        $this->assertSame(6, $items['vendor:12']['strength']);
        $this->assertSame('silver', self::slug($items['vendor:12']['strength']));
        $this->assertSame('silver', self::slug($items['vendor:7']['strength']), 'Gold, then one correction → Silver');
        $this->assertTrue($items['vendor:7']['corrected_recently']);
        $this->assertSame(1, $items['vendor:name:corner store']['strength']);
        $this->assertSame('Receipt vendors', $items['vendor:12']['group']);
    }

    public function test_penny_bank_payees_are_their_own_triangles(): void
    {
        $rev = function (string $key, int $sug, int $fin, string $outcome = 'accepted', string $desc = '') {
            return ['key' => $key, 'suggested_account_id' => $sug, 'final_account_id' => $fin, 'outcome' => $outcome,
                    'decided_at' => '2026-10-01', 'description' => $desc];
        };
        $reviews = [
            $rev('telus mobility', 5, 5, 'accepted', 'PRE-AUTHORIZED DEBIT TELUS MOBILITY 00451'),
            $rev('telus mobility', 5, 5),
            $rev('telus mobility', 5, 9, 'edited'),           // a change of account: a correction
            $rev('point of sale chevron', 0, 4, 'edited', 'POINT OF SALE (CHEVRON 0123 VANCOUVER BCCA)'),
            $rev('insurance corporation', 3, 3, 'kept'),      // left as it was: teaches nothing
        ];
        $rules = [
            'first insurance' => ['active_owner' => 12, 'owner' => 12],   // active, owner-confirmed 12 times
            'telus mobility'  => ['active_owner' => 40, 'owner' => 40],
        ];
        $items = array_column(PennyBrainService::payeeItemsFrom($reviews, $rules, ['point of sale chevron' => 'Chevron Lonsdale']), null, 'key');

        $this->assertSame(['payee:telus mobility', 'payee:point of sale chevron', 'payee:first insurance'], array_keys($items));
        $this->assertSame('Telus Mobility', $items['payee:telus mobility']['label']);
        $this->assertSame('obsidian', self::slug($items['payee:telus mobility']['strength']), 'Black, then corrected: one tier down, and rule confirmations no longer lift it');
        $this->assertSame('Chevron Lonsdale', $items['payee:point of sale chevron']['label'], 'a taught name (bank_payee_names) is the display name');
        $this->assertSame('Chevron', $items['payee:point of sale chevron']['raw'], "the bank's own wording is kept");
        $this->assertSame(1, $items['payee:point of sale chevron']['strength']);
        $this->assertSame(12, $items['payee:first insurance']['strength'], "never corrected: the owner's confirmations on the active rule count");
        $this->assertSame('Bank payees', $items['payee:first insurance']['group']);

        // Chevron the receipt vendor and Chevron the bank payee: two triangles, one per skill.
        $vendor = PennyBrainService::vendorItemsFrom([['vendor_id' => 12, 'vendor' => 'Chevron', 'status' => 'accepted', 'decided_at' => 'x']]);
        $this->assertNotSame($vendor[0]['key'], $items['payee:point of sale chevron']['key']);
    }

    /**
     * 2026-10-07: payee strength comes from the OWNER only. learned_count (imports nobody looked at)
     * made "TD ON Line Loans System" and "Wave Pyrl" White 28 while their rules sent loan and payroll
     * lines to Credit Card Payable; migration 1220 switched those rules off.
     */
    public function test_payee_strength_is_owner_confirmations_on_active_rules_only(): void
    {
        $rules = [
            'td on line loans system' => ['active_owner' => 0, 'owner' => 0, 'learned_count' => 28],  // switched off, never confirmed
            'wave pyrl'               => ['active_owner' => 0, 'owner' => 1],                          // off, 1 owner confirmation
            'telus mobility'          => ['active_owner' => 1, 'owner' => 1],                          // on but only 1: not trusted yet
            'bc hydro'                => ['active_owner' => 7, 'owner' => 7],                          // on, confirmed 7 times
            'old style'               => 50,                                                           // a bare learned_count: ignored
        ];
        $items = array_column(PennyBrainService::payeeItemsFrom([], $rules), null, 'key');

        $this->assertArrayNotHasKey('payee:td on line loans system', $items, 'no owner decision at all: not shown');
        $this->assertArrayNotHasKey('payee:old style', $items, 'learned_count alone never counts');
        $this->assertTrue($items['payee:wave pyrl']['learning'], 'rule switched off: still learning');
        $this->assertSame(1, $items['payee:wave pyrl']['strength']);
        $this->assertTrue($items['payee:telus mobility']['learning'], 'one owner confirmation is not yet trusted');
        $this->assertArrayNotHasKey('learning', $items['payee:bc hydro']);
        $this->assertSame('silver', self::slug($items['payee:bc hydro']['strength']));

        // A switched-off rule never lifts strength above what the owner's own card decisions say.
        $rev = ['key' => 'wave pyrl', 'suggested_account_id' => 5, 'final_account_id' => 5, 'outcome' => 'accepted', 'decided_at' => 'x', 'description' => 'WAVE PYRL'];
        $one = PennyBrainService::payeeItemsFrom([$rev], ['wave pyrl' => ['active_owner' => 0, 'owner' => 30]]);
        $this->assertSame(1, $one[0]['strength']);
        $this->assertArrayNotHasKey('learning', $one[0], 'an owner decision on her card is learned');
    }

    public function test_payee_labels_read_plainly(): void
    {
        $this->assertSame('TD Loans', PennyBrainService::payeeLabel('TD LOANS 4471'));
        $this->assertSame('Insurance Corporation of BC', PennyBrainService::payeeLabel('INSURANCE CORPORATION OF BC'));
        $this->assertSame('Ifs Premium Fin', PennyBrainService::payeeLabel('IFS PREMIUM FIN'));
        $this->assertSame('First Insurance', PennyBrainService::payeeLabel('first insurance'));
    }

    public function test_otto_rain_calls_and_mia_templates(): void
    {
        $w = OttoBrainService::weatherItems(['lawn mowing' => ['keep' => [40, 45, 50, 55, 60, 65], 'move' => []],
                                             'hedges' => ['keep' => [40], 'move' => [80]]]);   // 2 calls: not yet
        $this->assertCount(1, $w);
        $this->assertSame('weather:lawn mowing', $w[0]['key']);
        $this->assertSame(6, $w[0]['strength']);

        $m = MiaBrainService::templateItemsFrom(['reconnect' => '2026-09-01', 'referral' => null], ['reconnect' => 11]);
        $this->assertSame(11, $m[0]['strength']);
        $this->assertSame('11 results', $m[0]['note']);
        $this->assertSame(1, $m[1]['strength'], 'no results yet = first seen');
    }
}
