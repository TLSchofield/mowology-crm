<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * One photo, one expense. Pins the rule behind #408/#409/#410 (2026-10-07): three
 * expenses made in the same second from receipt_media_id 1073. A create that carries a
 * photo which is already a live expense answers with that expense instead of inserting.
 * Runs against in-memory SQLite (no GET_LOCK there — the SELECT alone is exercised).
 */
class ExpenseCreateGuardTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite not available');
        }
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec("CREATE TABLE expenses (id INTEGER PRIMARY KEY, receipt_media_id INT, status TEXT)");
    }

    private function expense(int $id, ?int $media, string $status): void
    {
        $this->db->prepare("INSERT INTO expenses (id, receipt_media_id, status) VALUES (?, ?, ?)")->execute([$id, $media, $status]);
    }

    public function test_a_second_create_for_the_same_photo_gets_the_first_expense(): void
    {
        $this->expense(408, 1073, 'pending_approval');
        $guard = new ExpenseCreateGuard($this->db);
        $this->assertSame(408, $guard->claim(1073), 'the repeat answers with #408 — no #409, no #410');
    }

    public function test_the_first_create_for_a_photo_goes_ahead(): void
    {
        $this->expense(400, 999, 'draft');
        $guard = new ExpenseCreateGuard($this->db);
        $this->assertNull($guard->claim(1073));
        $guard->release(1073);   // no lock held here: a no-op, never an error
    }

    public function test_a_rejected_expense_does_not_block_resubmitting_the_photo(): void
    {
        $this->expense(409, 1073, 'rejected');
        $this->assertNull((new ExpenseCreateGuard($this->db))->claim(1073), 'set aside as a duplicate or rejected — may come in again');
        $this->expense(410, 1073, 'approved');
        $this->assertSame(410, (new ExpenseCreateGuard($this->db))->claim(1073));
    }

    public function test_a_create_without_a_photo_is_never_held_back(): void
    {
        $this->expense(1, null, 'draft');
        $this->assertNull((new ExpenseCreateGuard($this->db))->claim(null));
        $this->assertNull((new ExpenseCreateGuard($this->db))->claim(0));
    }

    public function test_pick_existing_takes_the_oldest_live_row(): void
    {
        $this->assertNull(ExpenseCreateGuard::pickExisting([]));
        $this->assertSame(9, ExpenseCreateGuard::pickExisting([
            ['id' => 3, 'status' => 'rejected'], ['id' => 9, 'status' => 'forwarded'], ['id' => 12, 'status' => 'draft'],
        ]));
    }

    public function test_the_answer_looks_like_a_successful_create(): void
    {
        $r = ExpenseCreateGuard::existingResponse(408);
        $this->assertTrue($r['success']);
        $this->assertSame(408, $r['expense_id'], 'clients read expense_id exactly as after a create');
        $this->assertTrue($r['deduplicated']);
    }
}
