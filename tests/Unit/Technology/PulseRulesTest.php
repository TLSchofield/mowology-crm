<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Pulse's judgement, with no server: log lines in, "is it worth telling the owner" out. Fixture lines are invented but in the real log's format. */
class PulseRulesTest extends TestCase
{
    private const NOW = 1793193600;   // a fixed moment; log lines are built relative to it

    private static function line(int $agoSeconds, string $req, int $status, string $ua = 'Mowology%20CRM/292 CFNetwork/3826 Darwin/24.5.0'): string
    {
        $t = date('d/M/Y:H:i:s', self::NOW - $agoSeconds) . ' ' . date('O', self::NOW);
        return "203.0.113.5 - - [$t] \"$req HTTP/1.1\" $status 929 \"-\" \"$ua\"";
    }

    private static function parse(array $lines): array
    {
        return array_values(array_filter(array_map([PulseRules::class, 'parseAccessLine'], $lines)));
    }

    public function testParsesARealStyleLine(): void
    {
        $p = PulseRules::parseAccessLine('108.180.62.147 - - [28/Oct/2026:09:16:07 -0400] "GET /api/team/team-mobile?mode=brief&head=charlie HTTP/1.1" 200 844 "-" "Mowology%20CRM/292 CFNetwork/3826.500.131 Darwin/24.5.0"');
        $this->assertSame('GET', $p['method']);
        $this->assertSame('/api/team/team-mobile', $p['path']);
        $this->assertSame('mode=brief&head=charlie', $p['query']);
        $this->assertSame(200, $p['status']);
        $this->assertSame(844, $p['bytes']);
        $this->assertSame(strtotime('2026-10-28 13:16:07 UTC'), $p['ts'], 'the -0400 offset is honoured');
        $this->assertNull(PulseRules::parseAccessLine('garbage'));
    }

    public function testGroupingCollapsesIdsAndKeepsTheMode(): void
    {
        $this->assertSame('/api/team/team-mobile [brief]', PulseRules::group('/api/team/team-mobile', 'mode=brief&head=charlie'));
        $this->assertSame('/api/jobs/#/photos', PulseRules::group('/api/jobs/123/photos', ''));
        $this->assertSame('/crm/api/ops-settings.php', PulseRules::group('/crm/api/ops-settings.php', 'action=get&key=x'));
    }

    public function testRepeatedServerErrorsOnOneScreenFail(): void
    {
        $lines = [];
        for ($i = 0; $i < 6; $i++) $lines[] = self::line(60 + $i, 'GET /api/team/team-mobile?mode=brief&head=charlie', 500);
        for ($i = 0; $i < 3; $i++) $lines[] = self::line(70 + $i, 'GET /api/team/team-mobile?mode=brief&head=charlie', 200);
        for ($i = 0; $i < 40; $i++) $lines[] = self::line(30 + $i, 'GET /api/schedule/day?date=2026-10-28', 200);
        $f = PulseRules::requestFindings(self::parse($lines), self::NOW);
        $this->assertSame(['/api/team/team-mobile [brief]'], array_keys($f), 'only the broken screen is reported');
        $this->assertSame('fail', $f['/api/team/team-mobile [brief]']['status']);
        $c = PulseRules::requestsCheck($f, 49);
        $this->assertSame('fail', $c['status']);
        $this->assertStringContainsString('6 of 9 requests failed', $c['detail']);
    }

    public function testASmallShareOfErrorsOnABusyScreenIsOnlyAWarning(): void
    {
        $lines = [self::line(10, 'GET /api/schedule/day', 500), self::line(11, 'GET /api/schedule/day', 500)];
        for ($i = 0; $i < 60; $i++) $lines[] = self::line(20 + $i, 'GET /api/schedule/day', 200);
        $f = PulseRules::requestFindings(self::parse($lines), self::NOW);
        $this->assertSame('warn', array_values($f)[0]['status']);
        $this->assertSame('warn', PulseRules::requestsCheck($f, 62)['status']);
    }

    public function testNoiseIsIgnored(): void
    {
        $lines = [
            self::line(10, 'GET /assets/css/master.css', 500),
            self::line(10, 'GET /api/x', 500, 'Googlebot/2.1'),
            self::line(10, 'GET /crm/api/once-a21d2974aca12e08.php', 500),
            self::line(5000, 'GET /api/old', 500),           // outside the 15-minute window
            self::line(10, 'GET /wp-login.php', 404),        // not a server error
        ];
        $f = PulseRules::requestFindings(self::parse($lines), self::NOW);
        $this->assertSame([], $f);
        $this->assertSame('ok', PulseRules::requestsCheck($f, 4)['status']);
    }

    public function testNewFatalsCountButOurOwnRunnerDoesNot(): void
    {
        $ts = fn(int $ago) => date('d-M-Y H:i:s', self::NOW - $ago) . ' ' . date_default_timezone_get();
        $lines = [
            '[' . $ts(100) . '] PHP Fatal error:  Uncaught Error: Call to undefined function foo() in /home/x/public_html/app/Modules/A.php:12',
            '[' . $ts(110) . '] PHP Fatal error:  Uncaught Error: Call to undefined function foo() in /home/x/public_html/app/Modules/A.php:12',
            '[' . $ts(120) . '] PHP Fatal error:  Cannot declare class Foo in /home/x/public_html/crm/api/once-a21d2974aca12e08.php on line 62',
            '[' . $ts(5000) . '] PHP Fatal error:  Uncaught Error: old news in /home/x/B.php:1',
            '[' . $ts(100) . '] PHP Warning:  Undefined variable $x in /home/x/C.php on line 3',
        ];
        $f = PulseRules::fatalFindings($lines, self::NOW);
        $this->assertCount(1, $f, 'the same crash twice is one finding; the runner file, the old line and the warning are not');
        $this->assertSame(2, array_values($f)[0]['count']);
        $this->assertSame('warn', PulseRules::fatalsCheck($f)['status']);
        $this->assertSame('ok', PulseRules::fatalsCheck([])['status']);
        $many = [str_repeat('k', 1) => ['count' => 4, 'msg' => 'boom']];
        $this->assertSame('fail', PulseRules::fatalsCheck($many)['status']);
    }

    public function testAiHealthThresholds(): void
    {
        $this->assertSame('ok', PulseRules::aiCheck(0, 0, 0)['status']);
        $this->assertSame('ok', PulseRules::aiCheck(2, 2, 0)['status'], 'too few calls to judge');
        $this->assertSame('fail', PulseRules::aiCheck(6, 4, 0)['status']);
        $this->assertSame('warn', PulseRules::aiCheck(8, 2, 0)['status']);
        $this->assertSame('ok', PulseRules::aiCheck(20, 1, 0)['status']);
        $this->assertSame('warn', PulseRules::aiCheck(5, 5, 5)['status'], 'a kill switch is deliberate, not a failure');
    }

    public function testStreakAndAlertAreOncePerIncident(): void
    {
        $h = [['status' => 'fail', 'run_at' => '2026-10-28 09:10:00'], ['status' => 'fail', 'run_at' => '2026-10-28 09:05:00'], ['status' => 'ok', 'run_at' => '2026-10-28 09:00:00']];
        $s = PulseRules::streak($h);
        $this->assertSame(2, $s['streak']);
        $this->assertSame('2026-10-28 09:05:00', $s['since']);
        $a = PulseRules::alert('head_cards', 'Otto card isn\'t building.', $s['since'], 2);
        $this->assertSame('urgent:pulse:head_cards:202610280905', $a['key']);
        $this->assertStringContainsString("isn't working: 2 checks in a row failed (since 9:05 am)", $a['text']);
        // A third failing run in the same incident has the same key (so it is not re-sent) ...
        $h3 = array_merge([['status' => 'fail', 'run_at' => '2026-10-28 09:15:00']], $h);
        $this->assertSame($a['key'], PulseRules::alert('head_cards', 'x', PulseRules::streak($h3)['since'], 3)['key']);
        // ... and a later incident is a new key.
        $later = [['status' => 'fail', 'run_at' => '2026-10-28 14:10:00'], ['status' => 'fail', 'run_at' => '2026-10-28 14:05:00'], ['status' => 'ok', 'run_at' => '2026-10-28 14:00:00']];
        $this->assertNotSame($a['key'], PulseRules::alert('head_cards', 'x', PulseRules::streak($later)['since'], 2)['key']);
        $this->assertSame(0, PulseRules::streak([['status' => 'ok', 'run_at' => 'x'], ['status' => 'fail', 'run_at' => 'y']])['streak'], 'recovered');
    }

    public function testAdaStates(): void
    {
        $this->assertSame('green', PulseRules::adaState('ok'));
        $this->assertSame('amber', PulseRules::adaState('warn'));
        $this->assertSame('red', PulseRules::adaState('fail'));
        $this->assertSame('unknown', PulseRules::adaState('unknown'));
    }
}
