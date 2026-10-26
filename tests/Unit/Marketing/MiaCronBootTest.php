<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * CLI boot test for the Mia weekly crons (vault lesson: a cron that cannot boot under CLI stays "Never run" for days).
 *
 * Each cron is copied into a throwaway app tree whose Core/config.php is a SQLite stub, and run as a real PHP CLI
 * process. That proves the parts unit tests cannot: the paths.php search, the require chain, CronLock, recordCronRun,
 * the exit codes and the crash reporting. The service classes are the real ones (symlinked) unless a test swaps them.
 */
class MiaCronBootTest extends TestCase
{
    private const CRONS = ['mia_audit_weekly', 'mia_search_weekly'];
    private string $root = '';
    private string $repo = '';

    protected function setUp(): void
    {
        $this->repo = realpath(__DIR__ . '/../../..');
        $this->root = sys_get_temp_dir() . '/mia_cron_boot_' . bin2hex(random_bytes(4));
        mkdir($this->root . '/app/Core', 0777, true);
        mkdir($this->root . '/app/Modules/Marketing/Cron', 0777, true);
        mkdir($this->root . '/public/crm', 0777, true);
        copy($this->repo . '/app/Core/paths.php', $this->root . '/app/Core/paths.php');
        copy($this->repo . '/app/Core/CronLock.php', $this->root . '/app/Core/CronLock.php');
        symlink($this->repo . '/app/Services', $this->root . '/app/Services');
        symlink($this->repo . '/app/Modules/Marketing/Services', $this->root . '/app/Modules/Marketing/Services');
        file_put_contents($this->root . '/app/Core/config.php', <<<'PHP'
<?php
date_default_timezone_set('America/Vancouver');
function getDB(): PDO {
    static $db = null;
    if ($db === null) {
        $db = new PDO('sqlite:' . getenv('MIA_TEST_DB'));
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->sqliteCreateFunction('NOW', function () { return date('Y-m-d H:i:s'); });
    }
    return $db;
}
PHP);
        $db = new PDO('sqlite:' . $this->root . '/test.sqlite');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec("CREATE TABLE cron_runs (id INTEGER PRIMARY KEY AUTOINCREMENT, cron_key TEXT, triggered_by TEXT, status TEXT, duration_ms INT, summary TEXT, error_message TEXT, ran_at TEXT)");
        $db->exec("CREATE TABLE ops_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT, description TEXT)");
        $db->exec("CREATE TABLE mia_websites (id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INT NOT NULL DEFAULT 1, host TEXT, status TEXT DEFAULT 'verified')");
        foreach (['mia_site_pages', 'mia_site_links', 'mia_audits', 'mia_audit_findings', 'mia_actions', 'mia_action_events', 'mia_decisions', 'mia_memory', 'mia_llm_usage', 'mia_content_drafts'] as $t) {
            $db->exec("CREATE TABLE $t (id INTEGER PRIMARY KEY)");
        }
    }

    protected function tearDown(): void
    {
        if ($this->root !== '' && is_dir($this->root)) {
            exec('rm -rf ' . escapeshellarg($this->root));
        }
    }

    private function install(string $cron): string
    {
        $dst = $this->root . '/app/Modules/Marketing/Cron/' . $cron . '.php';
        copy($this->repo . '/app/Modules/Marketing/Cron/' . $cron . '.php', $dst);
        return $dst;
    }

    /** @return array{0:int,1:string,2:string} exit code, stdout, stderr */
    private function runCron(string $file, array $args = []): array
    {
        $cmd = array_merge([PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', 'error_log=' . $this->root . '/php_errors.log', $file], $args);
        $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->root,
            ['MIA_TEST_DB' => $this->root . '/test.sqlite', 'TMPDIR' => $this->root, 'PATH' => getenv('PATH') ?: '/usr/bin']);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        return [proc_close($p), (string)$out, (string)$err];
    }

    private function runs(): array
    {
        $db = new PDO('sqlite:' . $this->root . '/test.sqlite');
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        return $db->query("SELECT * FROM cron_runs ORDER BY id")->fetchAll();
    }

    // ---- boot ----

    /** @dataProvider crons */
    public function testBootsUnderCliAndRecordsASuccessfulRun(string $cron): void
    {
        list($code, $out) = $this->runCron($this->install($cron));
        $this->assertSame(0, $code, $out);
        $runs = $this->runs();
        $this->assertCount(1, $runs);
        $this->assertSame($cron, $runs[0]['cron_key']);
        $this->assertSame('success', $runs[0]['status']);
        $this->assertSame('cron', $runs[0]['triggered_by']);
        $this->assertSame('No verified websites to work on', $runs[0]['summary']);
        $this->assertSame('', trim($out), 'a normal run is silent (cPanel would e-mail output)');
        $this->assertNotNull($runs[0]['duration_ms']);
    }

    public function crons(): array
    {
        return [['mia_audit_weekly'], ['mia_search_weekly']];
    }

    /** @dataProvider crons */
    public function testAMissingMigrationIsAWarningThatNamesTheTable(string $cron): void
    {
        $db = new PDO('sqlite:' . $this->root . '/test.sqlite');
        $db->exec("DROP TABLE " . ($cron === 'mia_audit_weekly' ? 'mia_site_links' : 'mia_content_drafts'));
        list($code, $out) = $this->runCron($this->install($cron));
        $this->assertSame(0, $code);
        $this->assertStringContainsString('warning:', $out, 'warnings are printed so they get noticed');
        $r = $this->runs()[0];
        $this->assertSame('warning', $r['status']);
        $this->assertStringContainsString($cron === 'mia_audit_weekly' ? 'mia_site_links' : 'mia_content_drafts', $r['summary']);
        $this->assertStringContainsString('Migration not run yet', $r['summary']);
    }

    /** @dataProvider crons */
    public function testAHeldLockMakesTheSecondRunExitQuietlyWithoutARecord(string $cron): void
    {
        $lock = fopen($this->root . '/mowology_cron_' . $cron . '.lock', 'c');
        $this->assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        list($code, $out) = $this->runCron($this->install($cron));
        flock($lock, LOCK_UN); fclose($lock);
        $this->assertSame(0, $code, 'overlap is not an error');
        $this->assertSame('', trim($out));
        $this->assertSame([], $this->runs());
        // and once the lock is free it runs
        list($code2) = $this->runCron($this->install($cron));
        $this->assertSame(0, $code2);
        $this->assertCount(1, $this->runs());
    }

    // ---- failure reporting ----

    public function testAnExceptionIsRecordedWithClassMessageFileAndLineAndExitsNonZero(): void
    {
        $this->swapJobs('<?php final class MiaScheduledJobs { const TABLES_AUDIT = []; const TABLES_SEARCH = []; public function __construct($db) { throw new DomainException("secret sauce missing"); } public static function cause($e) { return "x"; } }');
        list($code) = $this->runCron($this->install('mia_audit_weekly'));
        $this->assertSame(1, $code);
        $r = $this->runs()[0];
        $this->assertSame('error', $r['status']);
        $this->assertSame('Cron failed', $r['summary']);
        $this->assertMatchesRegularExpression('/^DomainException: secret sauce missing at MiaScheduledJobs\.php:\d+$/', $r['error_message']);
    }

    public function testASyntaxErrorInAServiceIsRecordedWithItsFileAndLine(): void
    {
        $this->swapJobs('<?php this is not php {{{');
        list($code) = $this->runCron($this->install('mia_search_weekly'));
        $this->assertNotSame(0, $code);
        $runs = $this->runs();
        $this->assertCount(1, $runs);
        $this->assertSame('error', $runs[0]['status']);
        $this->assertStringContainsString('ParseError', $runs[0]['error_message']);
        $this->assertStringContainsString('MiaScheduledJobs.php:1', $runs[0]['error_message']);
    }

    public function testAnUncatchableFatalIsStillRecordedNotLeftAsNeverRun(): void
    {
        // Redeclaring a built-in class is a compile-time FATAL (not an exception): only the shutdown handler can record it.
        $this->swapJobs('<?php final class DateTimeImmutable {}');
        list($code) = $this->runCron($this->install('mia_audit_weekly'));
        $this->assertNotSame(0, $code);
        $runs = $this->runs();
        $this->assertCount(1, $runs, 'a crash must not leave the Cron Jobs tab saying "Never run"');
        $this->assertSame('error', $runs[0]['status']);
        $this->assertSame('Cron crashed', $runs[0]['summary']);
        $this->assertStringContainsString('MiaScheduledJobs.php:1', $runs[0]['error_message']);
    }

    public function testAFailedRunReportsErrorStatusFromTheJobAndExitsNonZero(): void
    {
        $this->swapJobs('<?php final class MiaScheduledJobs { const TABLES_AUDIT = []; const TABLES_SEARCH = []; public function __construct($db) {} public function missingTables($t) { return []; }
            public function auditWeekly($now, $force) { return ["status" => "error", "summary" => "0 of 1 websites audited, 1 failed", "errors" => ["a.example: needs to be verified again"]]; }
            public static function cause($e) { return "x"; } }');
        list($code) = $this->runCron($this->install('mia_audit_weekly'));
        $this->assertSame(1, $code);
        $r = $this->runs()[0];
        $this->assertSame('error', $r['status']);
        $this->assertSame('a.example: needs to be verified again', $r['error_message']);
    }

    public function testForceFlagReachesTheJob(): void
    {
        $this->swapJobs('<?php final class MiaScheduledJobs { const TABLES_AUDIT = []; const TABLES_SEARCH = []; public function __construct($db) {} public function missingTables($t) { return []; }
            public function searchWeekly($now, $force) { return ["status" => "success", "summary" => "force=" . var_export($force, true), "errors" => []]; }
            public static function cause($e) { return "x"; } }');
        $this->runCron($this->install('mia_search_weekly'));
        $this->runCron($this->install('mia_search_weekly'), ['--force']);
        $this->assertSame(['force=false', 'force=true'], array_column($this->runs(), 'summary'));
    }

    private function swapJobs(string $php): void
    {
        unlink($this->root . '/app/Modules/Marketing/Services');                        // the symlink
        mkdir($this->root . '/app/Modules/Marketing/Services');
        file_put_contents($this->root . '/app/Modules/Marketing/Services/MiaScheduledJobs.php', $php);
    }

    // ---- registry, shims, schedule ----

    /** @dataProvider crons */
    public function testRegistryShimAndCrontabLineAgree(string $cron): void
    {
        $reg = (string)file_get_contents($this->repo . '/public/crm/database_appstack.php');
        $this->assertSame(1, preg_match("/\\['key'=>'" . $cron . "',([^\\n]*)\\],\\n/", $reg, $m), 'registry row');
        $this->assertStringContainsString("'app'=>'/app/Modules/Marketing/Cron/$cron.php'", $m[1]);
        $this->assertStringContainsString("'run'=>'/crm/cron/$cron.php'", $m[1]);
        $this->assertStringContainsString("'cat'=>'marketing'", $m[1]);
        preg_match("/'expr'=>'([^']+)'/", $m[1], $e);
        $src = (string)file_get_contents($this->repo . "/app/Modules/Marketing/Cron/$cron.php");
        $this->assertStringContainsString($e[1], $src, 'the schedule in the cron header matches the registry');
        $shim = (string)file_get_contents($this->repo . "/public/crm/cron/$cron.php");
        $this->assertStringContainsString("require_once __DIR__ . '/_guard.php';", $shim);
        $this->assertStringContainsString("/app/Modules/Marketing/Cron/$cron.php", $shim);
        $this->assertSame(1, preg_match_all("/'key'=>'" . $cron . "'/", $reg), 'registered once');
    }

    public function testTheAuditRunsBeforeTheSearchJobAndWithTimeToFinish(): void
    {
        $reg = (string)file_get_contents($this->repo . '/public/crm/database_appstack.php');
        preg_match("/'key'=>'mia_audit_weekly'.*?'expr'=>'(\\d+) (\\d+) \\* \\* (\\d)'/", $reg, $a);
        preg_match("/'key'=>'mia_search_weekly'.*?'expr'=>'(\\d+) (\\d+) \\* \\* (\\d)'/", $reg, $s);
        $this->assertSame($a[3], $s[3], 'same weekday');
        $gap = ((int)$s[2] * 60 + (int)$s[1]) - ((int)$a[2] * 60 + (int)$a[1]);
        $this->assertGreaterThanOrEqual(ceil(MiaScheduledJobs::BUDGET_SECONDS / 60), $gap, 'the audit cannot still be running when the search job starts');
    }

    /** @dataProvider crons */
    public function testEveryCronRequireResolvesToARealFile(string $cron): void
    {
        $src = (string)file_get_contents($this->repo . "/app/Modules/Marketing/Cron/$cron.php");
        preg_match_all("#require(?:_once)?\\s+APP_ROOT\\s*\\.\\s*'([^']+)'#", $src, $m);
        $this->assertNotEmpty($m[1]);
        foreach ($m[1] as $rel) { $this->assertFileExists($this->repo . '/app' . $rel, $rel); }
    }

    public function testForceViaTheWebShimOnlyAcceptsForceEqualsOne(): void
    {
        $src = (string)file_get_contents($this->repo . '/app/Modules/Marketing/Cron/mia_search_weekly.php');
        $this->assertStringContainsString("(\$_GET['force'] ?? '') === '1'", $src);
        $this->assertStringNotContainsString("!empty(\$_GET['force'])", $src);
    }

    public function testMissingPathsFileFailsWithAClearMessageNotAnUndefinedConstant(): void
    {
        $lonely = sys_get_temp_dir() . '/mia_cron_nopaths_' . bin2hex(random_bytes(3));
        mkdir($lonely, 0777, true);
        copy($this->repo . '/app/Modules/Marketing/Cron/mia_audit_weekly.php', $lonely . '/mia_audit_weekly.php');
        list($code, $out) = $this->runCron($lonely . '/mia_audit_weekly.php');
        exec('rm -rf ' . escapeshellarg($lonely));
        $this->assertSame(1, $code);
        $this->assertStringContainsString('could not find app', $out);
    }

    public function testACrashingRecordCronRunDoesNotStopTheCronFinishingNormally(): void
    {
        // cron_runs missing: recordCronRun logs and carries on, the cron still exits cleanly
        (new PDO('sqlite:' . $this->root . '/test.sqlite'))->exec('DROP TABLE cron_runs');
        list($code) = $this->runCron($this->install('mia_audit_weekly'));
        $this->assertSame(0, $code);
    }
}
