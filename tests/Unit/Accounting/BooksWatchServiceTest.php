<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** The nightly books-vs-bank watch: news only, once (2026-10-14). */
class BooksWatchServiceTest extends TestCase
{
    private function d(float $v, string $name = 'Chequing'): array
    {
        return ['drift' => $v, 'name' => $name];
    }

    public function test_first_night_is_a_baseline_not_eight_alarms(): void
    {
        $this->assertSame([], BooksWatchService::driftAlerts(null, ['1010|2026-02' => $this->d(-477.37), '1010|2026-09' => $this->d(2344.10)]));
    }

    public function test_only_new_or_changed_differences_alert(): void
    {
        $prev = ['1010|2026-02' => $this->d(-477.37), '1010|2026-03' => $this->d(-529.76)];
        $now  = ['1010|2026-02' => $this->d(-477.37), '1010|2026-03' => $this->d(-529.20), '1010|2026-10' => $this->d(120.00), '2400|2026-05' => $this->d(0.0, 'Visa')];
        $a = BooksWatchService::driftAlerts($prev, $now);
        $this->assertCount(1, $a, 'unchanged and sub-dollar moves are not news; a matching new month is not news');
        $this->assertSame('urgent:books:1010:2026-10:12000', $a[0]['key']);
        $this->assertStringContainsString('October 2026 books are now $120.00 above the bank (was $0.00 above)', $a[0]['text']);
    }

    public function test_a_difference_cleared_is_told_once(): void
    {
        $a = BooksWatchService::driftAlerts(['1010|2026-01' => $this->d(7.01)], ['1010|2026-01' => $this->d(0.0)]);
        $this->assertStringContainsString('January 2026 now matches the bank (it was $7.01 off)', $a[0]['text']);
    }

    public function test_quiet_signals_alert_once_per_quiet_spell(): void
    {
        $last = ['bank_import' => '2026-09-01 10:00:00', 'etransfer' => '2026-10-08 15:20:03', 'text_bridge' => '2026-10-12 06:00:00'];
        $a = BooksWatchService::canaryAlerts($last, '2026-10-14 02:00:00');
        $keys = array_column($a, 'key');
        $this->assertSame(['urgent:canary:bank_import:2026-09-01', 'urgent:canary:text_bridge:2026-10-12'], $keys);
        $this->assertStringContainsString('No bank statement imported for 42 days (last Sep 1)', $a[0]['text']);
    }

    public function test_a_signal_never_seen_is_not_judged(): void
    {
        $this->assertSame([], BooksWatchService::canaryAlerts(['bank_import' => null, 'etransfer' => null, 'text_bridge' => null], '2026-10-14 02:00:00'));
    }

    public function test_run_stores_baseline_then_reports_change(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->exec("CREATE TABLE ops_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT, description TEXT)");
        $w = new class($db) extends BooksWatchService {
            public array $fake = [];
            public function drift(): array { return $this->fake; }
            public function lastSeen(): array { return ['bank_import' => '2026-10-13 19:12:33']; }
        };
        $w->fake = ['1010|2026-09' => ['drift' => 2344.10, 'name' => 'Chequing']];
        $this->assertSame([], $w->run('2026-10-14 02:00:00')['alerts']);
        $w->fake = ['1010|2026-09' => ['drift' => 2300.00, 'name' => 'Chequing']];
        $w->run('2026-10-15 02:00:00');
        $this->assertCount(1, $w->urgent());
        $this->assertSame('books', $w->urgent()[0]['kind']);
    }
}
