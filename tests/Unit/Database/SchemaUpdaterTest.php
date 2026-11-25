<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** In-memory store: no MySQL. */
final class FakeSchemaStore implements SchemaUpdaterStore
{
    public bool $log = true;
    public bool $created = false;
    public array $rows = [];
    public array $ran = [];
    public array $failWith = [];   // statement => mysql code
    public array $successes = [];
    public array $failures = [];

    public bool $free = true;
    public int $unlocked = 0;
    public function lock(): bool { return $this->free; }
    public function unlock(): void { $this->unlocked++; }
    public function hasLog(): bool { return $this->log; }
    public function createLog(): void { $this->created = true; $this->log = true; }
    public function logRows(): array { return $this->rows; }
    public function run(string $statement): void
    {
        $this->ran[] = $statement;
        if (isset($this->failWith[$statement])) throw new SchemaUpdaterException('boom ' . $statement, $this->failWith[$statement]);
    }
    public function recordSuccess(string $file, string $checksum, string $notes): void { $this->successes[$file] = [$checksum, $notes]; }
    public function recordFailed(string $file, string $checksum, string $error, string $notes): void { $this->failures[$file] = [$checksum, $error, $notes]; }
}

class SchemaUpdaterTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/schema_updater_' . uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) unlink($f);
        rmdir($this->dir);
    }

    private function file(string $name, string $sql): string
    {
        file_put_contents($this->dir . '/' . $name, $sql);
        touch($this->dir . '/' . $name, time() - 3600);   // settled long ago
        return hash('sha256', $sql);
    }

    private function updater(FakeSchemaStore $s): SchemaUpdater
    {
        return new SchemaUpdater($s, $this->dir);
    }

    public function test_selection_baseline_bad_names_and_order(): void
    {
        $names = ['1444_old.sql', '1445_b.sql', '1445_a.sql', '1446_x.sql', '12345_big.sql', '1447_Bad.sql',
                  '1448_x.txt', 'todo-1450_x.sql', '1449-x.sql', '999_x.sql'];
        $this->assertSame(['1445_a.sql', '1445_b.sql', '1446_x.sql', '12345_big.sql'], SchemaUpdater::select($names));
    }

    public function test_adopted_list_is_empty_until_filled(): void
    {
        $this->assertSame([], SchemaUpdater::ADOPTED);
        $this->assertFalse(SchemaUpdater::isAutomatic('1400_old.sql'));
    }

    public function test_applies_pending_in_order_and_skips_applied(): void
    {
        $this->file('1446_b.sql', 'SELECT 2;');
        $sum = $this->file('1445_a.sql', 'SELECT 1;');
        $this->file('1444_history.sql', 'SELECT 0;');
        $s = new FakeSchemaStore();
        $s->rows['1445_a.sql'] = ['status' => 'success', 'checksum' => $sum, 'error' => ''];
        $r = $this->updater($s)->run();
        $this->assertSame(['1446_b.sql'], $r['applied']);
        $this->assertSame(['SELECT 2'], $s->ran);
        $this->assertSame('automatic (1 run, 0 already there)', $s->successes['1446_b.sql'][1]);
        $this->assertSame('schema_update mowology: 1 applied (1446_b.sql), 0 pending', SchemaUpdater::summary('mowology', $r));
    }

    public function test_changed_checksum_of_applied_file_only_warns(): void
    {
        $this->file('1445_a.sql', 'SELECT 1;');
        $s = new FakeSchemaStore();
        $s->rows['1445_a.sql'] = ['status' => 'success', 'checksum' => 'old', 'error' => ''];
        $r = $this->updater($s)->run();
        $this->assertSame([], $s->ran);
        $this->assertCount(1, $r['warnings']);
        $this->assertStringContainsString('warning: 1445_a.sql changed', SchemaUpdater::summary('x', $r));
    }

    public function test_failed_file_waits_unless_changed_or_retry(): void
    {
        $sum = $this->file('1445_a.sql', 'SELECT 1;');
        $this->file('1446_b.sql', 'SELECT 2;');
        $s = new FakeSchemaStore();
        $s->rows['1445_a.sql'] = ['status' => 'failed', 'checksum' => $sum, 'error' => 'bad thing', 'at' => time()];
        $r = $this->updater($s)->run();
        $this->assertSame([], $s->ran);
        $this->assertTrue($r['failed']['earlier']);
        $this->assertSame('schema_update t: failed earlier: 1445_a.sql: bad thing; 1 file waiting', SchemaUpdater::summary('t', $r));

        $r = $this->updater($s)->run(false, true);   // --retry
        $this->assertSame(['1445_a.sql', '1446_b.sql'], $r['applied']);

        $s2 = new FakeSchemaStore();
        $s2->rows['1445_a.sql'] = ['status' => 'failed', 'checksum' => 'different', 'error' => 'bad thing', 'at' => time()];
        $r = $this->updater($s2)->run();
        $this->assertSame(['1445_a.sql', '1446_b.sql'], $r['applied']);
    }

    public function test_tolerated_codes_are_counted_as_already_there(): void
    {
        $this->file('1445_a.sql', 'CREATE TABLE t (a INT); ALTER TABLE t ADD b INT; DROP INDEX i ON t; SELECT 9;');
        $s = new FakeSchemaStore();
        $s->failWith = ['CREATE TABLE t (a INT)' => 1050, 'ALTER TABLE t ADD b INT' => 1060, 'DROP INDEX i ON t' => 1091];
        $r = $this->updater($s)->run();
        $this->assertSame(['1445_a.sql'], $r['applied']);
        $this->assertSame(3, $r['skipped']);
        $this->assertSame('automatic (1 run, 3 already there)', $s->successes['1445_a.sql'][1]);
        $this->assertCount(4, $s->ran);
    }

    public function test_other_errors_stop_and_record_failed_and_later_files_wait(): void
    {
        $sum = $this->file('1445_a.sql', 'SELECT 1; BAD STATEMENT; SELECT 3;');
        $this->file('1446_b.sql', 'SELECT 2;');
        $this->file('1447_c.sql', 'SELECT 4;');
        $s = new FakeSchemaStore();
        $s->failWith = ['BAD STATEMENT' => 1064];
        $r = $this->updater($s)->run();
        $this->assertSame(['SELECT 1', 'BAD STATEMENT'], $s->ran);
        $this->assertSame([], $s->successes);
        $this->assertSame([$sum, 'boom BAD STATEMENT', 'automatic'], $s->failures['1445_a.sql']);
        $this->assertFalse($r['failed']['earlier']);
        $this->assertSame(2, $r['waiting']);
        $this->assertTrue(SchemaUpdater::failed($r));
        $this->assertSame('schema_update testcompany: FAILED 1445_a.sql: boom BAD STATEMENT; 2 files waiting', SchemaUpdater::summary('testcompany', $r));
    }

    public function test_empty_file_is_a_failure_never_applied(): void
    {
        $this->file('1445_a.sql', "-- only a comment\n");
        $this->file('1446_b.sql', 'SELECT 2;');
        $s = new FakeSchemaStore();
        $r = $this->updater($s)->run();
        $this->assertSame([], $s->ran);
        $this->assertSame([], $s->successes);
        $this->assertArrayHasKey('1445_a.sql', $s->failures);
        $this->assertSame(1, $r['waiting']);
    }

    public function test_failed_file_is_tried_again_after_half_an_hour(): void
    {
        $sum = $this->file('1445_a.sql', 'SELECT 1;');
        $s = new FakeSchemaStore();
        $t = time();
        $s->rows = ['1445_a.sql' => ['status' => 'failed', 'checksum' => $sum, 'error' => 'Lock wait timeout', 'at' => $t]];
        $r = $this->updater($s)->run(false, false, $t + SchemaUpdater::RETRY_AFTER_SEC - 1);
        $this->assertTrue($r['failed']['earlier']);
        $this->assertSame([], $s->ran);
        $r = $this->updater($s)->run(false, false, $t + SchemaUpdater::RETRY_AFTER_SEC);
        $this->assertSame(['1445_a.sql'], $r['applied']);
    }

    public function test_file_changed_moments_ago_waits_and_holds_later_files(): void
    {
        $this->file('1445_a.sql', 'SELECT 1;');
        $this->file('1446_b.sql', 'SELECT 2;');
        touch($this->dir . '/1445_a.sql', time());
        $s = new FakeSchemaStore();
        $r = $this->updater($s)->run();
        $this->assertSame([], $s->ran);
        $this->assertSame('1445_a.sql', $r['settling']);
        $this->assertSame(2, $r['waiting']);
        $this->assertFalse(SchemaUpdater::failed($r));
        $this->assertStringContainsString('2 waiting (1445_a.sql was changed in the last two minutes)', SchemaUpdater::summary('x', $r));
    }

    public function test_second_run_does_nothing_while_the_lock_is_held(): void
    {
        $this->file('1445_a.sql', 'SELECT 1;');
        $s = new FakeSchemaStore();
        $s->free = false;
        $r = $this->updater($s)->run();
        $this->assertTrue($r['locked']);
        $this->assertSame([], $s->ran);
        $this->assertSame(0, $s->unlocked);
        $this->assertSame('schema_update x: another run holds the lock, nothing done', SchemaUpdater::summary('x', $r));
        $s->free = true;
        $this->updater($s)->run();
        $this->assertSame(1, $s->unlocked);
    }

    public function test_delimiter_file_is_refused_and_badly_named_file_warns(): void
    {
        $this->file('1445_a.sql', "DELIMITER //\nCREATE TRIGGER t BEFORE INSERT ON x FOR EACH ROW BEGIN SET NEW.a = 1; END//");
        $this->file('1446-Bad.sql', 'SELECT 1;');
        $s = new FakeSchemaStore();
        $r = $this->updater($s)->run();
        $this->assertSame([], $s->ran);
        $this->assertStringContainsString('DELIMITER', $r['failed']['error']);
        $this->assertSame(['1446-Bad.sql is not named NNNN_lower_case.sql (ignored)'], $r['warnings']);
    }

    public function test_splitter_handles_escapes_double_quotes_backticks_and_hash_comments(): void
    {
        $sql = "# don't; break\nALTER TABLE t COMMENT 'user\\'s; x';\nINSERT INTO t (`a;b`) VALUES (\"x -- y; z\", '#1;');\nSELECT 2";
        $this->assertSame([
            "ALTER TABLE t COMMENT 'user\\'s; x'",
            "INSERT INTO t (`a;b`) VALUES (\"x -- y; z\", '#1;')",
            'SELECT 2',
        ], SchemaUpdater::splitStatements($sql));
    }

    public function test_summary_with_nothing_to_do(): void
    {
        $r = $this->updater(new FakeSchemaStore())->run();
        $this->assertSame('schema_update mowology: 0 applied, 0 pending', SchemaUpdater::summary('mowology', $r));
        $this->assertFalse(SchemaUpdater::failed($r));
    }

    public function test_dry_run_changes_nothing(): void
    {
        $this->file('1445_a.sql', 'SELECT 1;');
        $this->file('1446_b.sql', 'SELECT 2;');
        $s = new FakeSchemaStore();
        $s->log = false;
        $r = $this->updater($s)->run(true);
        $this->assertSame(['1445_a.sql', '1446_b.sql'], $r['pending']);
        $this->assertSame([], $s->ran);
        $this->assertSame([], $s->successes);
        $this->assertFalse($s->created);
        $this->assertSame('schema_update m: dry run, 2 pending (1445_a.sql, 1446_b.sql)', SchemaUpdater::summary('m', $r));
    }

    public function test_missing_migrations_log_is_created_first(): void
    {
        $this->file('1445_a.sql', 'SELECT 1;');
        $s = new FakeSchemaStore();
        $s->log = false;
        $r = $this->updater($s)->run();
        $this->assertTrue($s->created);
        $this->assertTrue($r['created_log']);
        $this->assertSame(['1445_a.sql'], $r['applied']);
    }

    public function test_splitter_respects_quotes_and_comments(): void
    {
        $this->assertSame(["INSERT INTO t VALUES ('a;b', 'it''s')", 'SELECT 1'],
            SchemaUpdater::splitStatements("-- c\nINSERT INTO t VALUES ('a;b', 'it''s'); /* x; */ SELECT 1;"));
    }

    public function test_resolve_dir_takes_first_existing(): void
    {
        $this->assertSame($this->dir, SchemaUpdater::resolveDir(['/nonexistent/x', $this->dir]));
        $this->assertNull(SchemaUpdater::resolveDir(['/nonexistent/x']));
    }
}
