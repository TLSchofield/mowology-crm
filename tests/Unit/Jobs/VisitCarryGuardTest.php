<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * VisitCarryGuard against in-memory SQLite: finished work never travels with a move.
 */
final class VisitCarryGuardTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite not available');
        }
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->sqliteCreateFunction('NOW', static fn () => '2026-10-27 08:00:00', 0);
        $this->db->exec("CREATE TABLE job_visits (id INTEGER PRIMARY KEY, visit_number TEXT, stop_id INT, plan_id INT, status TEXT, scheduled_date TEXT, scheduled_time_start TEXT, sequence_index INT, assigned_crew_id INT, status_changed_at TEXT, completed_at TEXT, completion_notes TEXT, updated_at TEXT)");
        $this->db->exec("CREATE TABLE job_plans (id INTEGER PRIMARY KEY, plan_number TEXT)");
        $this->db->exec("CREATE TABLE media_links (id INTEGER PRIMARY KEY, media_id INT, context_type TEXT, context_id INT, category TEXT)");
        $this->db->exec("CREATE TABLE media_assets (id INTEGER PRIMARY KEY, captured_at TEXT, created_at TEXT)");
        $this->db->exec("INSERT INTO job_plans (id, plan_number) VALUES (7, 'JOB-2026-0007')");
        $this->db->exec("INSERT INTO job_visits (id, visit_number, stop_id, plan_id, status, scheduled_date, scheduled_time_start, sequence_index, assigned_crew_id, completion_notes) VALUES (40, 'JOB-2026-0007-V004', 300, 7, 'scheduled', '2026-10-20', '13:30:00', 4, 12, '')");
    }

    private function photo(int $visitId, string $category, string $capturedAt, ?string $createdAt = null): void
    {
        $this->db->prepare("INSERT INTO media_assets (captured_at, created_at) VALUES (?, ?)")->execute([$capturedAt === '' ? null : $capturedAt, $createdAt ?? '2026-10-20 15:00:00']);
        $id = (int)$this->db->lastInsertId();
        $this->db->prepare("INSERT INTO media_links (media_id, context_type, context_id, category) VALUES (?, 'job_visit', ?, ?)")->execute([$id, $visitId, $category]);
    }

    private function visit(int $id): ?array
    {
        $row = $this->db->query("SELECT * FROM job_visits WHERE id = {$id}")->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function testNoProofMeansNoSplit(): void
    {
        $guard = new VisitCarryGuard($this->db);
        $this->assertNull($guard->proofOfWork(40));
        $this->photo(40, 'before', '2026-10-20 13:35:00');
        $this->assertNull($guard->proofOfWork(40), 'a Before photo alone is not finished work');
        $this->assertNull($guard->splitIfFinished(40, '2026-10-27', 310));
        $this->assertSame('scheduled', $this->visit(40)['status']);
        $this->assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM job_visits")->fetchColumn());
    }

    public function testAfterPhotoCompletesInPlaceAndCreatesTheNextVisit(): void
    {
        $this->photo(40, 'after', '2026-10-20 14:50:00');
        $this->photo(40, 'additional', '2026-10-20 14:55:00');

        $fresh = (new VisitCarryGuard($this->db))->splitIfFinished(40, '2026-10-27', 310);

        $old = $this->visit(40);
        $this->assertSame('completed', $old['status']);
        $this->assertSame('2026-10-20', $old['scheduled_date']);
        $this->assertSame(300, (int)$old['stop_id']);
        $this->assertSame('2026-10-20 14:50:00', $old['completed_at']);
        $this->assertSame('2026-10-27 08:00:00', $old['status_changed_at']);
        $this->assertSame(VisitCarryGuard::NOTE, $old['completion_notes'], 'an empty note is replaced');

        $this->assertNotNull($fresh);
        $new = $this->visit($fresh);
        $this->assertSame('scheduled', $new['status']);
        $this->assertSame('2026-10-27', $new['scheduled_date']);
        $this->assertSame(310, (int)$new['stop_id']);
        $this->assertSame(7, (int)$new['plan_id']);
        $this->assertSame(12, (int)$new['assigned_crew_id']);
        $this->assertSame('13:30:00', $new['scheduled_time_start']);
        $this->assertSame(5, (int)$new['sequence_index']);
        $this->assertSame('JOB-2026-0007-V005', $new['visit_number']);
    }

    public function testCompletedAtAndExistingNotesArePreserved(): void
    {
        $this->db->exec("UPDATE job_visits SET completed_at = '2026-10-20 15:10:00', completion_notes = 'Gate code 1234' WHERE id = 40");
        $this->photo(40, 'after', '', '2026-10-20 14:58:00'); // no EXIF time: created_at stands in

        $fresh = (new VisitCarryGuard($this->db))->splitIfFinished(40, '2026-10-27', null);

        $old = $this->visit(40);
        $this->assertSame('completed', $old['status']);
        $this->assertSame('2026-10-20 15:10:00', $old['completed_at'], 'an existing completed_at is kept');
        $this->assertSame('Gate code 1234', $old['completion_notes'], 'crew notes are never overwritten');
        $this->assertNull($this->visit($fresh)['stop_id']);
    }

    public function testCompletedAtAloneCountsAsProof(): void
    {
        $this->db->exec("UPDATE job_visits SET status = 'in_progress', completed_at = '2026-10-20 15:10:00' WHERE id = 40");
        $this->assertSame('2026-10-20 15:10:00', (new VisitCarryGuard($this->db))->proofOfWork(40));
    }

    public function testFinishedVisitsAreLeftAlone(): void
    {
        $this->db->exec("UPDATE job_visits SET status = 'completed' WHERE id = 40");
        $this->photo(40, 'after', '2026-10-20 14:50:00');
        $this->assertNull((new VisitCarryGuard($this->db))->splitIfFinished(40, '2026-10-27', 310));
        $this->assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM job_visits")->fetchColumn());
    }

    public function testSettleStopHandlesOnlyThePhotographedVisits(): void
    {
        $this->db->exec("INSERT INTO job_visits (id, visit_number, stop_id, plan_id, status, scheduled_date, sequence_index) VALUES (41, 'JOB-2026-0007-V005', 300, 7, 'scheduled', '2026-10-20', 5)");
        $this->photo(40, 'after', '2026-10-20 14:50:00');

        $guard   = new VisitCarryGuard($this->db);
        $settled = $guard->settleStop(300);
        $this->assertSame([40], array_map(static fn (array $v): int => (int)$v['id'], $settled));
        $this->assertSame('completed', $this->visit(40)['status']);
        $this->assertSame('scheduled', $this->visit(41)['status']);

        $ids = $guard->recreateOn($settled, '2026-10-27', 320);
        $this->assertCount(1, $ids);
        $this->assertSame('2026-10-27', $this->visit($ids[0])['scheduled_date']);
        $this->assertSame(6, (int)$this->visit($ids[0])['sequence_index']);
    }

    public function testASingleVisitSplitIsAllOrNothing(): void
    {
        // Codex 2026-10-27: the single-visit movers run outside a transaction, so a failed
        // replacement insert must not leave the original completed with nothing in its place.
        $this->photo(40, 'after', '2026-10-20 14:50:00');
        $this->db->exec("CREATE TRIGGER no_new_visit BEFORE INSERT ON job_visits WHEN NEW.scheduled_date = '2026-10-27' BEGIN SELECT RAISE(ABORT, 'insert refused'); END");

        try {
            (new VisitCarryGuard($this->db))->splitIfFinished(40, '2026-10-27', 310);
            $this->fail('expected the refused insert to throw');
        } catch (PDOException $e) {
            $this->assertStringContainsString('insert refused', $e->getMessage());
        }
        $this->assertSame('scheduled', $this->visit(40)['status'], 'the completion was rolled back');
        $this->assertNull($this->visit(40)['completed_at']);
        $this->assertFalse($this->db->inTransaction());
    }

    public function testStopLevelSettlingLeavesInProgressVisitsAlone(): void
    {
        $this->db->exec("UPDATE job_visits SET status = 'in_progress' WHERE id = 40");
        $this->photo(40, 'after', '2026-10-20 14:50:00');
        $this->assertSame([], (new VisitCarryGuard($this->db))->settleStop(300));
        $this->assertSame('in_progress', $this->visit(40)['status']);
    }
}
