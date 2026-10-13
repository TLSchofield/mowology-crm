<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** The append-only audit copy (Fable security audit #25c, 2026-10-12). */
final class AuditExportServiceTest extends TestCase
{
    private PDO $db;
    private string $dir;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec("CREATE TABLE activity_log (id INTEGER PRIMARY KEY, user_id INT, contact_id INT, company_id INT,
            property_id INT, quote_request_id INT, quote_id INT, invoice_id INT, plan_id INT, visit_id INT,
            action TEXT, details TEXT, ip_address TEXT, created_at TEXT)");
        $this->dir = sys_get_temp_dir() . '/mw-audit-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        @unlink($this->dir . '/' . AuditExportService::FILE);
        @rmdir($this->dir);
    }

    private function add(int $id, string $at, string $action = 'Invoice sent', ?string $details = null): void
    {
        $this->db->prepare("INSERT INTO activity_log (id, user_id, company_id, action, details, created_at) VALUES (?, 1, 4, ?, ?, ?)")
                 ->execute([$id, $action, $details, $at]);
    }

    private function lines(): array
    {
        return file($this->dir . '/' . AuditExportService::FILE, FILE_IGNORE_NEW_LINES);
    }

    public function test_exports_rows_before_today_then_only_new_ones_append_only(): void
    {
        $this->add(1, '2026-10-10 09:00:00', 'Quote sent', 'Prix « été » / 50%');
        $this->add(2, '2026-10-11 23:59:59');
        $this->add(3, '2026-10-12 00:00:01');   // today — not yet
        $svc = new AuditExportService($this->db, $this->dir);

        $r = $svc->run('2026-10-12');
        $this->assertSame(2, $r['exported']);
        $this->assertSame([1, 2], [$r['first_id'], $r['last_id']]);
        $this->assertSame(0, AuditExportService::verifyChain($this->lines()));
        $size1 = filesize($svc->path());

        $this->assertSame(0, $svc->run('2026-10-12')['exported']);   // nothing twice
        $this->assertSame($size1, filesize($svc->path()));

        // A row cleared later in the database keeps its exported values in the copy.
        $this->db->exec("UPDATE activity_log SET company_id = NULL WHERE id = 2");
        $r = $svc->run('2026-10-13');
        $this->assertSame(1, $r['exported']);
        $this->assertSame(3, $r['last_id']);
        $lines = $this->lines();
        $this->assertCount(3, $lines);
        $this->assertGreaterThan($size1, filesize($svc->path()));
        $this->assertSame(4, json_decode($lines[1], true)['row']['company_id']);
        $this->assertSame('Prix « été » / 50%', json_decode($lines[0], true)['row']['details']);
        $this->assertSame(0, AuditExportService::verifyChain($lines));
    }

    public function test_a_back_dated_row_after_todays_rows_never_skips_them(): void
    {
        $this->add(1, '2026-10-11 10:00:00');
        $this->add(2, '2026-10-12 08:00:00');   // today
        $this->add(3, '2026-10-01 08:00:00');   // back-dated, higher id
        $svc = new AuditExportService($this->db, $this->dir);
        $this->assertSame(1, $svc->run('2026-10-12')['last_id']);
        $r = $svc->run('2026-10-13');
        $this->assertSame([2, 3], [$r['first_id'], $r['last_id']]);
        $this->assertSame(0, AuditExportService::verifyChain($this->lines()));
    }

    public function test_editing_a_line_breaks_the_chain_there(): void
    {
        foreach ([1, 2, 3] as $i) $this->add($i, "2026-10-0{$i} 10:00:00");
        (new AuditExportService($this->db, $this->dir))->run('2026-10-12');
        $lines = $this->lines();
        $lines[1] = str_replace('Invoice sent', 'Invoice sant', $lines[1]);
        $this->assertSame(2, AuditExportService::verifyChain($lines));
        $this->assertSame(2, AuditExportService::verifyChain([$lines[0], $this->lines()[2]]));   // a deleted line
    }

    public function test_resumes_from_the_file_not_the_database(): void
    {
        $this->assertSame([0, AuditExportService::GENESIS], AuditExportService::tailState(''));
        [$l1, $h1] = AuditExportService::line(['id' => 41, 'action' => 'x', 'created_at' => '2026-10-01 00:00:00'], AuditExportService::GENESIS);
        $this->assertSame([41, $h1], AuditExportService::tailState("partial-garbage\n" . $l1 . "\n"));
    }
}
