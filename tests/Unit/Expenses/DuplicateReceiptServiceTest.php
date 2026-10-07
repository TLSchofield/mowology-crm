<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Possible duplicate receipts — found by ExpenseLookupService::findDuplicates (the
 * receipts page's rule), paired up and held back from Penny's approval line.
 */
class DuplicateReceiptServiceTest extends TestCase
{
    private function r(int $id, string $status = 'draft'): array
    {
        return ['id' => $id, 'status' => $status, 'expense_date' => '2026-09-28', 'total' => 48.38];
    }

    public function test_pairs_each_waiting_receipt_with_its_twin_once(): void
    {
        $mine = [$this->r(7), $this->r(9, 'pending_approval')];
        $cands = [7 => [$this->r(9, 'pending_approval')], 9 => [$this->r(7)]];
        $p = DuplicateReceiptService::pairUp($mine, $cands);
        $this->assertCount(1, $p, 'the same pair seen from both sides is one pair');
        $this->assertSame(7, $p[0]['a']['id'], 'older waiting receipt on the left');
    }

    public function test_a_waiting_copy_of_an_approved_receipt_is_caught_with_the_waiting_one_first(): void
    {
        $p = DuplicateReceiptService::pairUp([$this->r(9)], [9 => [$this->r(3, 'forwarded')]]);
        $this->assertSame(9, $p[0]['a']['id']);
        $this->assertSame([9], DuplicateReceiptService::heldIds($p), 'only the waiting one is held');
    }

    public function test_rejected_and_dismissed_twins_are_ignored(): void
    {
        $this->assertSame([], DuplicateReceiptService::pairUp([$this->r(9)], [9 => [$this->r(3, 'rejected')]]));
        $this->assertSame([], DuplicateReceiptService::pairUp([$this->r(9)], [9 => [$this->r(3)]], ['3-9' => true]));
    }

    public function test_copies_linked_by_any_pair_are_one_group(): void
    {
        $pairs = [['a' => $this->r(1), 'b' => $this->r(4)], ['a' => $this->r(4), 'b' => $this->r(7, 'forwarded')], ['a' => $this->r(24), 'b' => $this->r(290)]];
        $g = DuplicateReceiptService::groups($pairs);
        $this->assertCount(2, $g);
        $this->assertSame([1, 4, 7], array_map(fn($m) => (int)$m['id'], $g[0]['members']));
        $this->assertCount(2, $g[0]['pairs']);
    }

    // ── Same photo ──────────────────────────────────────────────────────

    public function test_receipts_from_the_same_photo_join_the_group_whatever_their_dates(): void
    {
        // #408 (04-02) and #410 (04-08) are 6 days apart — outside findDuplicates' ±3 days —
        // but they are one photo (media 1073).
        $found = [['id' => 409, 'status' => 'draft']];
        $same  = [['id' => 409, 'status' => 'draft'], ['id' => 410, 'status' => 'draft']];
        $this->assertSame([409, 410], array_map(fn($r) => $r['id'], DuplicateReceiptService::withSamePhoto($found, $same)));
    }

    // ── Keep this one (removeCopies) ────────────────────────────────────

    public function test_same_group_needs_every_id_in_one_group(): void
    {
        $g = DuplicateReceiptService::groups([['a' => $this->r(408), 'b' => $this->r(409)], ['a' => $this->r(409), 'b' => $this->r(412)],
                                              ['a' => $this->r(20), 'b' => $this->r(21)]]);
        $this->assertTrue(DuplicateReceiptService::sameGroup($g, [412, 408, 409]));
        $this->assertFalse(DuplicateReceiptService::sameGroup($g, [412, 20]), 'two different groups');
        $this->assertFalse(DuplicateReceiptService::sameGroup($g, [412, 999]));
    }

    public function test_merge_fills_only_what_the_kept_one_is_missing(): void
    {
        $keep = ['job_id' => null, 'accounting_category' => 'Fuel', 'notes' => '', 'asset_tag' => null, 'description' => null];
        $copy = ['job_id' => 77, 'accounting_category' => 'Materials', 'notes' => 'for the Dodge', 'asset_tag' => 'truck',
                 'description' => 'Auto-saved from offline queue — please review', 'payment_method' => 'debit'];
        $this->assertSame(['job_id' => 77, 'asset_tag' => 'truck', 'notes' => 'for the Dodge'],
            DuplicateReceiptService::mergeFill($keep, $copy),
            'category kept (set), placeholder description and a column the kept row lacks skipped');
    }

    private function deskDb(): PDO
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite not available');
        }
        $db = class_exists('Pdo\Sqlite') ? new \Pdo\Sqlite('sqlite::memory:') : new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $now = static fn () => date('Y-m-d H:i:s');
        method_exists($db, 'createFunction') ? $db->createFunction('NOW', $now) : $db->sqliteCreateFunction('NOW', $now);
        $db->exec("CREATE TABLE expenses (id INTEGER PRIMARY KEY, status TEXT, receipt_media_id INT, job_id INT, property_id INT,
                   contact_id INT, accounting_category TEXT, asset_tag TEXT, payment_method TEXT, vendor_id INT, notes TEXT,
                   description TEXT, approved_by INT, approved_at TEXT, rejection_reason TEXT, created_by INT)");
        $db->exec("CREATE TABLE expense_line_items (id INTEGER PRIMARY KEY, expense_id INT, name TEXT)");
        $db->exec("CREATE TABLE expense_suggestions (id INTEGER PRIMARY KEY, expense_id INT, source TEXT, status TEXT)");
        $db->exec("CREATE TABLE activity_log (id INTEGER PRIMARY KEY, user_id INT, action TEXT, entity_type TEXT, entity_id INT,
                   details TEXT, created_at TEXT)");
        // The live case: #408-#410 are one photo (1073), #412 a second photo of the slip.
        foreach ([[408, 'pending_approval', 1073, 55], [409, 'pending_approval', 1073, null], [410, 'pending_approval', 1073, null],
                  [412, 'pending_approval', null, null], [500, 'approved', 2000, null]] as [$id, $st, $media, $job]) {
            $db->prepare("INSERT INTO expenses (id, status, receipt_media_id, job_id, created_by) VALUES (?, ?, ?, ?, 6)")
               ->execute([$id, $st, $media, $job]);
        }
        $db->exec("INSERT INTO expense_line_items (expense_id, name) VALUES (409, 'Bar oil'), (409, '2-stroke')");
        $db->exec("INSERT INTO expense_suggestions (expense_id, source, status) VALUES (408, 'live', 'pending'), (412, 'live', 'pending')");
        return $db;
    }

    /** The group check is findDuplicates() (MySQL date arithmetic) — stood in for here. */
    private function desk(PDO $db, bool $oneGroup = true): DuplicateReceiptService
    {
        return new class($db, $oneGroup) extends DuplicateReceiptService {
            private bool $one;
            public function __construct(PDO $db, bool $one) { parent::__construct($db); $this->one = $one; }
            protected function inOneGroup(int $keepId, array $removeIds): bool { return $this->one; }
        };
    }

    public function test_keep_this_one_sets_every_other_copy_aside_and_merges_into_the_kept_one(): void
    {
        $db = $this->deskDb();
        $res = $this->desk($db)->removeCopies(412, [408, 409, 410], ['id' => 1]);
        $this->assertTrue($res['ok'], $res['message']);
        $this->assertSame([408, 409, 410], $res['removed']);

        $by = [];
        foreach ($db->query("SELECT id, status, rejection_reason, receipt_media_id, job_id FROM expenses")->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $by[(int)$row['id']] = $row;
        }
        foreach ([408, 409, 410] as $id) {
            $this->assertSame('rejected', $by[$id]['status'], "#{$id} soft-removed, never deleted");
            $this->assertSame('Duplicate of receipt #412', $by[$id]['rejection_reason']);
        }
        $this->assertSame('pending_approval', $by[412]['status']);
        $this->assertSame(55, (int)$by[412]['job_id'], "#408's job carried to the kept receipt");
        $this->assertSame(1073, (int)$by[412]['receipt_media_id'], "the kept one had no photo — it takes the copy's");
        $this->assertSame(2, (int)$db->query("SELECT COUNT(*) FROM expense_line_items WHERE expense_id = 412")->fetchColumn(), 'line items move over');
        $this->assertSame('superseded', $db->query("SELECT status FROM expense_suggestions WHERE expense_id = 408")->fetchColumn(), "Penny stops on the copy (as the web's removeCopy)");
        $this->assertSame('pending', $db->query("SELECT status FROM expense_suggestions WHERE expense_id = 412")->fetchColumn());
        $this->assertSame(3, (int)$db->query("SELECT COUNT(*) FROM activity_log WHERE action = 'expense_rejected'")->fetchColumn());
        $this->assertStringContainsString('3 copies', $res['message']);
    }

    public function test_an_approved_copy_is_never_removed_and_nothing_changes(): void
    {
        $db = $this->deskDb();
        $res = $this->desk($db)->removeCopies(412, [408, 500], ['id' => 1]);
        $this->assertFalse($res['ok']);
        $this->assertSame(0, (int)$db->query("SELECT COUNT(*) FROM expenses WHERE status = 'rejected'")->fetchColumn(), 'all-or-nothing');
    }

    public function test_receipts_outside_the_group_are_refused(): void
    {
        $db = $this->deskDb();
        $res = $this->desk($db, false)->removeCopies(412, [408], ['id' => 1]);
        $this->assertFalse($res['ok']);
        $this->assertSame('pending_approval', $db->query("SELECT status FROM expenses WHERE id = 408")->fetchColumn());
    }

    public function test_keep_and_remove_must_differ(): void
    {
        $db = $this->deskDb();
        $this->assertFalse($this->desk($db)->removeCopies(412, [412], ['id' => 1])['ok']);
        $this->assertFalse($this->desk($db)->removeCopies(412, [], ['id' => 1])['ok']);
    }
}
