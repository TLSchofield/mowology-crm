<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The crew break button end to end against in-memory SQLite (2026-10-13): start / end, clock-out
 * during a break, the truck login, an offline replay with the tap time, GPS overlap, paid vs
 * unpaid, the BC flag, and the tracking server ignoring fixes while on break.
 */
class BreakButtonDbTest extends TestCase
{
    private PDO $db;
    private BreakService $svc;
    private string $tz;

    protected function setUp(): void
    {
        // Production runs in America/Vancouver; phone times carry their offset.
        $this->tz = date_default_timezone_get();
        date_default_timezone_set('America/Vancouver');
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite not available');
        }
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->db->sqliteCreateFunction('CONCAT', static fn (...$a) => implode('', array_map('strval', $a)), -1);
        $this->db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, device_type TEXT, first_name TEXT, last_name TEXT)");
        $this->db->exec("CREATE TABLE time_clock_entries (id INTEGER PRIMARY KEY, user_id INT, clock_in TEXT, clock_out TEXT,
                         status TEXT DEFAULT 'active', total_minutes INT, break_note TEXT)");
        $this->db->exec("CREATE TABLE time_clock_breaks (id INTEGER PRIMARY KEY, entry_id INT, user_id INT, break_start TEXT,
                         break_end TEXT, minutes INT DEFAULT 0, source TEXT DEFAULT 'gps', place TEXT, ended_by TEXT, time_note TEXT,
                         created_at TEXT)");
        $this->db->exec("CREATE TABLE ops_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT)");
        $this->db->exec("INSERT INTO users (id, device_type, first_name) VALUES (5, 'personal', 'Nigel'), (6, 'truck', 'Dodge')");
        $this->svc = new BreakService($this->db);
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->tz);
    }

    private function shift(int $user, string $in, ?string $out = null): int
    {
        $this->db->prepare("INSERT INTO time_clock_entries (user_id, clock_in, clock_out, status) VALUES (?, ?, ?, ?)")
                 ->execute([$user, $in, $out, $out ? 'completed' : 'active']);
        return (int)$this->db->lastInsertId();
    }

    /** What clockOut() does around the UPDATE: close the shift, then afterClockOut(). */
    private function clockOut(int $user, int $entry, string $at): void
    {
        $this->db->prepare("UPDATE time_clock_entries SET clock_out = ?, status = 'completed' WHERE id = ?")->execute([$at, $entry]);
        $this->svc->afterClockOut($user, $entry);
    }

    private function breaks(): array
    {
        return $this->db->query("SELECT * FROM time_clock_breaks ORDER BY break_start")->fetchAll();
    }

    public function test_start_and_end_break(): void
    {
        $e = $this->shift(5, '2026-10-13 07:00:00');
        $r = $this->svc->start(5, null, '2026-10-13 12:30:00');
        $this->assertTrue($r['ok']);
        $this->assertSame('2026-10-13 12:30:00', $r['break']['started_at']);
        $this->assertSame($e, $r['break']['entry_id']);
        $this->assertNotNull($this->svc->openBreak(5));
        $this->assertSame('2026-10-13 12:30:00', $this->svc->clientConfig(5)['on_break']['started_at']);

        // A second Start break (double tap / replay) is the same break.
        $again = $this->svc->start(5, null, '2026-10-13 12:31:00');
        $this->assertTrue($again['already']);
        $this->assertCount(1, $this->breaks());

        $end = $this->svc->end(5, null, 'tap', '2026-10-13 13:00:00');
        $this->assertSame(30, $end['minutes']);
        $row = $this->breaks()[0];
        $this->assertSame('manual', $row['source']);
        $this->assertSame('2026-10-13 13:00:00', $row['break_end']);
        $this->assertSame('tap', $row['ended_by']);
        $this->assertNull($this->svc->openBreak(5));
        // End break with nothing running is a harmless no-op.
        $this->assertTrue($this->svc->end(5, null, 'tap', '2026-10-13 13:05:00')['already']);
    }

    public function test_clock_out_during_a_break_ends_the_break_first(): void
    {
        $e = $this->shift(5, '2026-10-13 07:00:00');
        $this->svc->start(5, null, '2026-10-13 14:40:00');
        $this->clockOut(5, $e, '2026-10-13 15:00:00');
        $row = $this->breaks()[0];
        $this->assertSame('2026-10-13 15:00:00', $row['break_end']);
        $this->assertSame(20, (int)$row['minutes']);
        $this->assertSame('clock_out', $row['ended_by']);
        $this->assertNull($this->svc->openBreak(5));
        // Next morning's shift does not show yesterday's break.
        $this->shift(5, '2026-10-14 07:00:00');
        $this->assertNull($this->svc->clientConfig(5)['on_break']);
    }

    public function test_the_truck_login_has_no_break_button(): void
    {
        $this->shift(6, '2026-10-13 07:00:00');
        $r = $this->svc->start(6, null, '2026-10-13 12:00:00');
        $this->assertFalse($r['ok']);
        $this->assertSame(403, $r['status']);
        $this->assertFalse($this->svc->clientConfig(6)['breaks']['allowed']);
        $this->assertTrue($this->svc->clientConfig(5)['breaks']['allowed']);
        $this->assertSame([], $this->breaks());
    }

    public function test_not_clocked_in_is_refused(): void
    {
        $r = $this->svc->start(5, null, '2026-10-13 12:00:00');
        $this->assertFalse($r['ok']);
        $this->assertSame(409, $r['status']);
    }

    public function test_an_offline_tap_files_at_the_tap_time_not_the_replay_time(): void
    {
        $this->shift(5, '2026-10-13 07:00:00');
        // Tapped at 12:05 in a dead zone, reached the server at 12:50.
        $r = $this->svc->start(5, '2026-10-13T12:05:00-07:00', '2026-10-13 12:50:00');
        $this->assertSame('2026-10-13 12:05:00', $r['break']['started_at']);
        $this->assertSame('sent late', $r['time_flag']);
        $this->assertStringContainsString('with no signal', (string)$this->breaks()[0]['time_note']);
        // Its End break, also queued, tapped 12:35 — replayed right behind it.
        $end = $this->svc->end(5, '2026-10-13T12:35:00-07:00', 'tap', '2026-10-13 12:50:01');
        $this->assertSame(30, $end['minutes']);
        // The same Start replayed again after a lost response: no second break.
        $dup = $this->svc->start(5, '2026-10-13T12:05:00-07:00', '2026-10-13 12:51:00');
        $this->assertTrue($dup['already']);
        $this->assertCount(1, $this->breaks());
    }

    public function test_a_queued_end_break_arriving_after_the_clock_out_ends_at_the_tap(): void
    {
        $e = $this->shift(5, '2026-10-13 07:00:00');
        $this->svc->start(5, null, '2026-10-13 12:00:00');
        // The clock-out (from the office) lands first and closes the break at 15:00 …
        $this->clockOut(5, $e, '2026-10-13 15:00:00');
        // … a later End break replay is then a no-op, never reopening or moving it.
        $this->assertTrue($this->svc->end(5, '2026-10-13T12:30:00-07:00', 'tap', '2026-10-13 15:10:00')['already']);
        $this->assertSame('2026-10-13 15:00:00', $this->breaks()[0]['break_end']);
    }

    public function test_gps_overlap_is_counted_once_and_paid_vs_unpaid(): void
    {
        $e = $this->shift(5, '2026-10-13 07:00:00', '2026-10-13 15:30:00');
        $ins = $this->db->prepare("INSERT INTO time_clock_breaks (entry_id, user_id, break_start, break_end, minutes, source) VALUES (?, 5, ?, ?, ?, ?)");
        $ins->execute([$e, '2026-10-13 12:00:00', '2026-10-13 12:30:00', 30, 'manual']);
        $ins->execute([$e, '2026-10-13 11:55:00', '2026-10-13 12:40:00', 45, 'gps']);   // same lunch, seen by GPS
        $ins->execute([$e, '2026-10-13 09:30:00', '2026-10-13 09:50:00', 20, 'gps']);   // coffee, not tapped

        $sum = $this->svc->forEntries([['id' => $e, 'clock_in' => '2026-10-13 07:00:00', 'clock_out' => '2026-10-13 15:30:00']])[$e];
        $this->assertSame(50, $sum['break_minutes'], '30 tapped + 20 GPS; the overlapping 45 is not counted');
        $this->assertSame(0, $sum['unpaid_minutes'], 'breaks_paid defaults to paid');

        $this->db->exec("INSERT INTO ops_settings VALUES ('breaks_paid', '0')");
        $sum = $this->svc->forEntries([['id' => $e, 'clock_in' => '2026-10-13 07:00:00', 'clock_out' => '2026-10-13 15:30:00']])[$e];
        $this->assertSame(30, $sum['unpaid_minutes'], 'only the tapped break comes off pay');

        $p = $this->svc->periodForUser(5, '2026-10-13', '2026-10-13');
        $this->assertSame(['break_minutes' => 50, 'unpaid_minutes' => 30], $p['days']['2026-10-13']);
        $this->assertSame(2, $p['count']);
    }

    public function test_bc_flag_is_written_at_clock_out(): void
    {
        $e = $this->shift(5, '2026-10-13 07:00:00');
        $this->clockOut(5, $e, '2026-10-13 15:00:00');                                   // 8 h, no break
        $note = $this->db->query("SELECT break_note FROM time_clock_entries WHERE id = $e")->fetchColumn();
        $this->assertStringContainsString('no break taken', (string)$note);

        $e2 = $this->shift(5, '2026-10-14 07:00:00');
        $this->svc->start(5, null, '2026-10-14 11:30:00');
        $this->svc->end(5, null, 'tap', '2026-10-14 12:00:00');
        $this->clockOut(5, $e2, '2026-10-14 15:00:00');
        $this->assertNull($this->db->query("SELECT break_note FROM time_clock_entries WHERE id = $e2")->fetchColumn());

        $t = $this->shift(6, '2026-10-14 07:00:00');                                      // the truck: never flagged
        $this->clockOut(6, $t, '2026-10-14 15:00:00');
        $this->assertNull($this->db->query("SELECT break_note FROM time_clock_entries WHERE id = $t")->fetchColumn());
    }

    public function test_tracking_ignores_fixes_on_a_break_and_policy_says_on_break(): void
    {
        $in = 1790000000;
        $shifts = [[$in, null]];
        $pauses = [[$in + 3600, null]];
        $p = ['lat' => 49.2, 'lng' => -123.1, 'ts' => $in + 3700];
        $this->assertSame('on_break', TrackingIngestService::classify($p, $in + 3800, $shifts, $pauses));
        $this->assertSame('ok', TrackingIngestService::classify(['ts' => $in + 1800] + $p, $in + 3800, $shifts, $pauses));
        // After End break, fixes count again.
        $this->assertSame('ok', TrackingIngestService::classify(['ts' => $in + 5500] + $p, $in + 5600, $shifts, [[$in + 3600, $in + 5400]]));

        $pol = TrackingIngestService::policy(true, true, true, true, null, true);
        $this->assertFalse($pol['tracking_allowed']);
        $this->assertSame('on_break', $pol['reason']);
        $this->assertTrue($pol['clocked_in']);
        $this->assertTrue(TrackingIngestService::policy(true, true, true, true, null)['tracking_allowed']);
    }
}
