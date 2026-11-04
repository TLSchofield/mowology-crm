<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../app/Modules/ChiefOfStaff/Services/BriefStatus.php';
require_once __DIR__ . '/../../../app/Modules/ChiefOfStaff/Services/UrgencyGuard.php';
require_once __DIR__ . '/../../../app/Modules/ChiefOfStaff/Services/BriefTrend.php';
require_once __DIR__ . '/../../../app/Modules/ChiefOfStaff/Services/CharlieBriefEmail.php';

/**
 * Kaname harvest (2026-11-03): one status (the worst head, forced red), a head cannot make everything urgent,
 * every number carries its comparison with yesterday, an honest footer.
 */
class KanameHarvestRulesTest extends TestCase
{
    private const TODAY = '2026-11-03';

    private function line(string $head, string $key, int $p, string $text, ?float $value = null, ?string $since = null, bool $yes = false): array
    {
        return ['head' => $head, 'key' => $key, 'kind' => $head . ':x', 'priority' => $p, 'text' => $text, 'value' => $value, 'since' => $since, 'yes' => $yes];
    }

    // A. one status ───────────────────────────────────────────────────────────

    public function test_deadline_days_and_forced_red(): void
    {
        $d = $this->line('charlie', 'charlie:deadline:payroll_remit:2026-10-15', 1, 'Payroll remittance to CRA — overdue since Oct 15');
        $this->assertSame(-19, BriefStatus::deadlineDays($d, self::TODAY));
        $this->assertSame('deadline overdue', BriefStatus::forcedRed($d, self::TODAY));
        $soon = $this->line('charlie', 'charlie:deadline:gst:2026-11-09', 2, 'GST return due Nov 9');
        $this->assertSame('deadline in 6 days', BriefStatus::forcedRed($soon, self::TODAY));
        $far = $this->line('charlie', 'charlie:deadline:insurance:2026-12-15', 2, 'Insurance renewal Dec 15');
        $this->assertNull(BriefStatus::forcedRed($far, self::TODAY));
        $this->assertNull(BriefStatus::deadlineDays($this->line('penny', 'penny:x', 1, 'x'), self::TODAY));
        $money = $this->line('house', 'house:overdue', 1, '31 overdue invoices — $11,277.82 outstanding past due date', 11277.82);
        $this->assertSame('an amount of $11,278 past due', BriefStatus::forcedRed($money, self::TODAY));
        $this->assertNull(BriefStatus::forcedRed($this->line('sam', 's', 1, 'Gary Hughes looks lost — worth $6,723 a year', 6723.37), self::TODAY));   // a big number with no date passed is not forced red
        $this->assertNull(BriefStatus::forcedRed($this->line('house', 'h', 1, 'Failed payment $120', 120.0), self::TODAY));                            // under $500
        $this->assertNull(BriefStatus::forcedRed($this->line('house', 'h', 2, 'Overdue $900', 900.0), self::TODAY));                                   // not urgent
    }

    public function test_the_overall_status_is_the_worst_head_with_the_deciding_line(): void
    {
        $byHead = [
            'charlie' => [$this->line('charlie', 'charlie:deadline:payroll_remit:2026-10-15', 1, 'Payroll remittance to CRA — overdue since Oct 15', null, '2026-10-08'),
                          $this->line('charlie', 'charlie:deadline:wcb_premiums:2026-10-31', 1, 'WorkSafeBC — overdue since Oct 31', null, '2026-10-17')],
            'sam'  => [$this->line('sam', 'sam:1', 1, 'A reply is waiting')],
            'mia'  => [$this->line('mia', 'mia:1', 2, 'Draft a post')],
            'ada'  => [],
        ];
        $s = BriefStatus::assess($byHead, [], ['charlie' => 'Kaname'], self::TODAY, '2026-11-03');
        $this->assertSame('red', $s['overall']);
        $this->assertSame('Payroll remittance to CRA — overdue since Oct 15', $s['line']);         // the older of the two reds decides
        $this->assertSame('deadline overdue', $s['why']);
        $this->assertSame(['red', 'amber', 'green', 'quiet'], array_map(fn($h) => $h['status'], array_values($s['heads'])));
        $this->assertSame('nothing to report', $s['heads']['ada']['why']);
        $this->assertSame('RED — Payroll remittance to CRA — overdue since Oct 15.', BriefStatus::sentence($s));
        $this->assertTrue($s['defaults']);
        $this->assertStringContainsString('review them after Dec 1', $s['note']);
    }

    public function test_a_head_that_failed_is_unknown_never_green_and_amber_at_worst(): void
    {
        $s = BriefStatus::assess(['sam' => [$this->line('sam', 's', 2, 'x')]], ['penny' => 'timeout'], ['penny' => 'Penny'], self::TODAY, '2026-09-01');
        $this->assertSame('unknown', $s['overall']);
        $this->assertSame('unknown', $s['heads']['penny']['status']);
        $this->assertStringStartsWith('AMBER — a head could not be reached', BriefStatus::sentence($s));
        $this->assertFalse($s['defaults']);                                                           // used for more than 4 weeks
        $this->assertNull($s['note']);
        $green = BriefStatus::assess(['sam' => [$this->line('sam', 's', 2, 'x')]], [], [], self::TODAY, '2026-09-01');
        $this->assertSame('GREEN — nothing is overdue and nothing is urgent.', BriefStatus::sentence($green));
        $amber = BriefStatus::assess(['sam' => [$this->line('sam', 's', 1, 'x')]], [], [], self::TODAY, '2026-09-01');
        $this->assertSame('AMBER — nothing is overdue; some lines are urgent.', BriefStatus::sentence($amber));
        $this->assertTrue(BriefStatus::onDefaults(null, self::TODAY));
        $this->assertTrue(BriefStatus::onDefaults('2026-10-20', self::TODAY));
        $this->assertFalse(BriefStatus::onDefaults('2026-10-05', self::TODAY));
    }

    // B. urgency guard ────────────────────────────────────────────────────────

    public function test_a_head_keeps_five_urgent_lines_and_the_rest_are_ranked_as_two(): void
    {
        $items = [];
        for ($i = 1; $i <= 9; $i++) $items[] = $this->line('yui', "yui:p{$i}", 1, "Approval {$i}", null, '2026-10-' . sprintf('%02d', 10 + $i));
        $items[] = $this->line('yui', 'yui:money', 1, 'Big one', 900.0, '2026-11-01');
        $items[] = $this->line('yui', 'yui:yes', 1, 'A client said yes', null, '2026-11-02', true);
        $items[] = $this->line('penny', 'penny:1', 1, 'One urgent');
        $r = UrgencyGuard::apply($items);
        $byKey = array_column($r['items'], null, 'key');
        $this->assertSame(1, $byKey['yui:yes']['priority']);                                          // a yes is never demoted
        $this->assertSame(1, $byKey['yui:money']['priority']);                                        // the amount keeps its place
        $this->assertSame(1, $byKey['yui:p3']['priority']);                                           // the oldest keep theirs: yes, amount, p1, p2, p3
        $this->assertSame(2, $byKey['yui:p4']['priority']);
        $this->assertSame(2, $byKey['yui:p9']['priority']);
        $this->assertTrue($byKey['yui:p9']['demoted']);
        $this->assertSame(1, $byKey['penny:1']['priority']);
        $kept = count(array_filter($r['items'], fn($i) => $i['head'] === 'yui' && $i['priority'] === 1));
        $this->assertSame(5, $kept);                                                                  // the cap counts the yes
        $this->assertSame(['yui' => ['p1' => 11, 'total' => 11, 'kept' => 5]], $r['inflated']);
        // A yes BEYOND the cap is still never demoted (e.g. six yeses and nothing else).
        $yeses = []; for ($i = 1; $i <= 7; $i++) $yeses[] = $this->line('sam', "sam:y{$i}", 1, "Yes {$i}", null, null, true);
        $this->assertSame(0, count(array_filter(UrgencyGuard::apply($yeses)['items'], fn($i) => !empty($i['demoted']))));
        $this->assertSame([], UrgencyGuard::apply([$this->line('sam', 's', 1, 'x')])['inflated']);
        $n = UrgencyGuard::notes($r['inflated'], ['yui' => 'Yui']);
        $this->assertSame('Yui marks 11 of 11 lines urgent; more than 5 usually means its rule is too loose, so only its top 5 count as urgent here.', $n[0]);
    }

    // C. comparison ───────────────────────────────────────────────────────────

    public function test_every_number_carries_its_comparison(): void
    {
        $this->assertSame('▼ 14 since yesterday', BriefTrend::arrow(186, 200));
        $this->assertSame('▲ 3 since yesterday', BriefTrend::arrow(53, 50));
        $this->assertSame('▬', BriefTrend::arrow(200, 200));
        $this->assertSame('▬', BriefTrend::arrow(1000, 1005));                                       // under 1%
        $this->assertSame('(no prior baseline)', BriefTrend::arrow(5, null));
        $c = BriefTrend::compare(200, 58, ['total' => 214, 'urgent' => null]);
        $this->assertSame('200 lines ▼ 14 since yesterday, 58 urgent (no prior baseline)', $c['line']);
        $this->assertSame('No material changes since yesterday: 5 lines, 2 urgent.', BriefTrend::compare(5, 2, ['total' => 5, 'urgent' => 2])['line']);
        $this->assertSame('1 line (no prior baseline), 0 urgent (no prior baseline)', BriefTrend::compare(1, 0, ['total' => null, 'urgent' => null])['line']);
        // Yesterday's saved snapshot: the total comes from its heads' waiting counts, or from the new counts field.
        $snap = ['heads' => ['sam' => ['waiting' => 30, 'items' => []], 'otto' => ['waiting' => 100, 'items' => []]]];
        $this->assertSame(['total' => 130, 'urgent' => null], BriefTrend::fromSnapshot($snap));
        $this->assertSame(['total' => 12, 'urgent' => 3], BriefTrend::fromSnapshot(['counts' => ['total' => 12, 'urgent' => 3]]));
        $this->assertSame(['total' => null, 'urgent' => null], BriefTrend::fromSnapshot(null));
    }

    // D. the email ────────────────────────────────────────────────────────────

    public function test_the_email_leads_with_the_status_and_ends_with_an_honest_footer(): void
    {
        $payload = ['one' => 'k1', 'items' => [['key' => 'k1', 'head' => 'charlie', 'text' => 'Payroll remittance overdue', 'url' => '/crm/x', 'priority' => 1]],
                    'heads' => [], 'bad' => [],
                    'status' => ['overall' => 'red', 'sentence' => 'RED — Payroll remittance to CRA overdue since Oct 15.', 'note' => 'The colours use the starting thresholds; review them after Dec 1.'],
                    'trend' => '200 lines ▼ 14 since yesterday, 58 urgent (no prior baseline)',
                    'built' => ['at' => '07:02', 'heads_ok' => 7, 'heads_total' => 8, 'failed' => ['penny']]];
        $m = CharlieBriefEmail::render($payload, 'Tim', 'https://mowology.ca');
        $this->assertStringContainsString('RED — Payroll remittance to CRA overdue since Oct 15.', $m['body']);
        $this->assertStringContainsString('▼ 14 since yesterday', $m['body']);
        $this->assertStringContainsString('Built 07:02 from 7 of 8 heads.', $m['body']);
        $this->assertStringContainsString('review them after Dec 1', $m['body']);
        $this->assertLessThan(strpos($m['body'], 'The one thing'), strpos($m['body'], 'RED —'));      // status before the one thing
        // An old snapshot with none of these fields renders as it always did.
        $old = CharlieBriefEmail::render(['one' => null, 'items' => [], 'heads' => [], 'bad' => []], 'Tim', 'https://mowology.ca');
        $this->assertStringNotContainsString('Built ', $old['body']);
        $this->assertStringContainsString('Nothing needs you this morning', $old['body']);
    }

    // E. my own wording bug ───────────────────────────────────────────────────

    public function test_quiet_clients_never_read_gone_gone(): void
    {
        require_once __DIR__ . '/../../../app/Modules/Sales/Services/SamSignals.php';
        $src = (string)file_get_contents(__DIR__ . '/../../../app/Modules/Sales/Services/SalesDeskService.php');
        $this->assertStringNotContainsString("' has gone ' . \$q['state']", $src);
        $this->assertStringContainsString("looks lost", $src);
        $sig = (string)file_get_contents(__DIR__ . '/../../../app/Modules/Sales/Services/SamSignals.php');
        $this->assertStringNotContainsString("' has gone ' . (\$q['state']", $sig);
    }
}
