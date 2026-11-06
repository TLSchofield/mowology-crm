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
                   description TEXT, approved_by INT, approved_at TEXT, rejection_reason TEXT, created_by INT,
                   vendor_name_raw TEXT, expense_date TEXT, total REAL)");
        $db->exec("CREATE TABLE vendors (id INTEGER PRIMARY KEY, name TEXT)");
        $db->exec("CREATE TABLE expense_merges (id INTEGER PRIMARY KEY, copy_id INT, kept_id INT, evidence TEXT, evidence_text TEXT,
                   copy_before_json TEXT, merged_by INT, merged_at TEXT, unmerged_by INT, unmerged_at TEXT, unmerge_kind TEXT)");
        $db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, full_name TEXT)");
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

    // ── Merge with a way back (expense_merges, migration 1442) ────────────────

    public function test_a_merge_records_what_is_needed_to_split_it_off(): void
    {
        $db = $this->deskDb();
        $res = $this->desk($db)->removeCopies(412, [408, 409], ['id' => 1]);
        $this->assertTrue($res['ok'], $res['message']);
        $this->assertTrue($res['undo']);
        $this->assertSame([408, 409], array_keys($res['merges']));
        $m = $db->query("SELECT * FROM expense_merges WHERE copy_id = 408")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(412, (int)$m['kept_id']);
        $this->assertSame(1, (int)$m['merged_by']);
        $this->assertSame('total_date', $m['evidence'], '#412 is a second photo of the slip, with no printed facts here');
        $before = json_decode($m['copy_before_json'], true);
        $this->assertSame('pending_approval', $before['status']);
        $this->assertSame(['job_id' => 55], $before['took']['fill']);
        $this->assertSame(1073, $before['took']['photo'], 'the kept one had no photo and took #408\'s');
        $this->assertTrue(json_decode($db->query("SELECT copy_before_json FROM expense_merges WHERE copy_id = 409")->fetchColumn(), true)['took']['lines']);
    }

    public function test_undo_puts_the_copy_back_and_the_kept_one_gives_back_what_it_took(): void
    {
        $db = $this->deskDb();
        $svc = $this->desk($db);
        $svc->removeCopies(412, [408, 409], ['id' => 1]);
        $res = $svc->unmerge(409, ['id' => 1], 'undo');
        $this->assertTrue($res['ok'], $res['message']);
        $res = $svc->unmerge(408, ['id' => 1], 'undo');
        $this->assertTrue($res['ok'], $res['message']);
        $row = fn($id) => $db->query("SELECT * FROM expenses WHERE id = {$id}")->fetch(PDO::FETCH_ASSOC);
        foreach ([408, 409] as $id) {
            $this->assertSame('pending_approval', $row($id)['status'], "#{$id} waits again");
            $this->assertNull($row($id)['rejection_reason']);
            $this->assertNull($row($id)['approved_by'], 'reject had set approved_by — cleared again');
        }
        $this->assertNull($row(412)['job_id'], "#412 gives back #408's job");
        $this->assertNull($row(412)['receipt_media_id'], "#412 gives back #408's photo");
        $this->assertSame(2, (int)$db->query("SELECT COUNT(*) FROM expense_line_items WHERE expense_id = 409")->fetchColumn(), 'line items go back');
        $this->assertSame('undo', $db->query("SELECT unmerge_kind FROM expense_merges WHERE copy_id = 408")->fetchColumn());
        $this->assertSame([], $svc->mergedInto(412));
    }

    public function test_a_kept_receipt_already_in_the_books_keeps_what_it_has(): void
    {
        $db = $this->deskDb();
        $svc = $this->desk($db);
        $svc->removeCopies(412, [408], ['id' => 1]);
        $db->exec("UPDATE expenses SET status = 'approved' WHERE id = 412");
        $res = $svc->unmerge(408, ['id' => 1], 'split');
        $this->assertTrue($res['ok'], $res['message']);
        $this->assertSame(55, (int)$db->query("SELECT job_id FROM expenses WHERE id = 412")->fetchColumn(), 'an approved receipt is not edited');
        $this->assertStringContainsString('keeps what it took', $res['message']);
        $this->assertSame('split', $db->query("SELECT unmerge_kind FROM expense_merges WHERE copy_id = 408")->fetchColumn());
    }

    public function test_split_off_is_refused_for_a_receipt_that_was_not_merged(): void
    {
        $db = $this->deskDb();
        $this->assertFalse($this->desk($db)->unmerge(408, ['id' => 1])['ok']);
        $db->exec("UPDATE expenses SET status = 'rejected', rejection_reason = 'Wrong photo' WHERE id = 410");
        $this->assertFalse($this->desk($db)->unmerge(410, ['id' => 1])['ok'], 'a plain reject is not a merge');
    }

    public function test_split_off_twice_is_refused(): void
    {
        $db = $this->deskDb();
        $svc = $this->desk($db);
        $svc->removeCopies(412, [408], ['id' => 1]);
        $this->assertTrue($svc->unmerge(408, ['id' => 1])['ok']);
        $this->assertFalse($svc->unmerge(408, ['id' => 1])['ok']);
    }

    public function test_the_gate_unmerge_takes_only_a_rejected_receipt_back_to_waiting(): void
    {
        $db = $this->deskDb();
        $gate = new ExpenseGate($db);
        $this->expectException(InvalidArgumentException::class);
        $gate->apply(408, ['status' => 'draft'], ['id' => 1, 'kind' => 'user'], 'test', ['transition' => 'unmerge']);
    }

    public function test_the_gate_unmerge_carries_nothing_but_status_and_rejection_fields(): void
    {
        $db = $this->deskDb();
        $db->exec("UPDATE expenses SET status = 'rejected' WHERE id = 410");
        $this->expectException(InvalidArgumentException::class);
        (new ExpenseGate($db))->apply(410, ['status' => 'draft', 'job_id' => 9], ['id' => 1, 'kind' => 'user'], 'test', ['transition' => 'unmerge']);
    }

    public function test_merged_into_lists_the_copies_for_also_on_this_purchase(): void
    {
        $db = $this->deskDb();
        $svc = $this->desk($db);
        $svc->removeCopies(412, [410], ['id' => 1]);
        $rows = $svc->mergedInto(412);
        $this->assertCount(1, $rows);
        $this->assertSame(410, $rows[0]['copy_id']);
        $this->assertSame('/crm/api/serve-receipt.php?id=1073', $rows[0]['receipt_path']);
    }

    public function test_penny_record_counts_merges_and_splits_per_vendor(): void
    {
        $db = $this->deskDb();
        $db->exec("UPDATE expenses SET vendor_name_raw = 'Shell'");
        $svc = $this->desk($db);
        $svc->removeCopies(412, [408, 409], ['id' => 1]);
        $svc->unmerge(409, ['id' => 1], 'undo');     // a slip: counts nowhere
        $db->exec("INSERT INTO expenses (id, status, rejection_reason, vendor_name_raw) VALUES (700, 'rejected', 'Duplicate of receipt #12', 'shell ')");
        $rec = $this->desk($db)->vendorRecords();
        $this->assertSame(['agreed' => 2, 'said_different' => 0, 'split_off' => 0, 'called' => 2], $rec['shell'], '#408 merged + one older reject');
    }

    // ── What the card shows (pure) ─────────────────────────────────────────────

    public function test_verdict_names_the_printed_number_when_it_is_on_both(): void
    {
        $facts = [1 => ['doc_number' => '433970', 'doc_kind' => 'auth'], 2 => ['doc_number' => '433970', 'doc_kind' => 'auth']];
        $v = DuplicateReceiptService::verdictFor([['id' => 1], ['id' => 2]], $facts);
        $this->assertSame('number', $v['kind']);
        $this->assertSame('Same receipt: auth 433970 is on both.', $v['text']);
    }

    public function test_verdict_falls_back_to_photo_then_time_then_total_and_date(): void
    {
        $this->assertSame('photo', DuplicateReceiptService::verdictFor([['id' => 1, 'receipt_media_id' => 9], ['id' => 2, 'receipt_media_id' => 9]], [])['kind']);
        $t = DuplicateReceiptService::verdictFor([['id' => 1], ['id' => 2]], [1 => ['time_first' => '09:02'], 2 => ['time_first' => '09:02']]);
        $this->assertSame('time', $t['kind']);
        $this->assertStringContainsString('09:02', $t['text']);
        $n = DuplicateReceiptService::verdictFor([['id' => 1], ['id' => 2]], [1 => ['time_first' => '09:02']]);
        $this->assertSame('Same total and date. Nothing printed proves it either way.', $n['text']);
    }

    public function test_a_short_pad_number_alone_is_not_the_same_receipt(): void
    {
        // Lawnboy's pads run 1–100 again and again (ReceiptFactsService::duplicateVerdict).
        $facts = [1 => ['doc_number' => '037'], 2 => ['doc_number' => '037']];
        $this->assertSame('total_date', DuplicateReceiptService::verdictFor([['id' => 1], ['id' => 2]], $facts)['kind']);
    }

    public function test_the_keeper_is_the_one_in_the_books_else_bank_linked_else_first(): void
    {
        $w = fn($id, $st = 'draft') => ['id' => $id, 'status' => $st];
        $this->assertSame(['id' => 254, 'reason' => 'already in the books'], DuplicateReceiptService::keeperFor([$w(126), $w(254, 'approved')], []));
        $this->assertSame(['id' => 216, 'reason' => 'matched to a bank line'], DuplicateReceiptService::keeperFor([$w(151), $w(216)], [216 => true]));
        $this->assertSame(['id' => 151, 'reason' => 'sent in first'], DuplicateReceiptService::keeperFor([$w(216), $w(151)], []));
    }

    public function test_confidence_line_says_her_record_or_that_it_is_the_first(): void
    {
        $this->assertSame("First Lawnboy pair I've seen. Check the photos.", DuplicateReceiptService::confidenceLine('Lawnboy', null));
        $this->assertSame("With Shell I've been right 14 of 14 times.", DuplicateReceiptService::confidenceLine('Shell', ['called' => 14, 'agreed' => 14]));
        $this->assertSame("With Rona I've been right 1 of 1 time.", DuplicateReceiptService::confidenceLine('Rona', ['called' => 1, 'agreed' => 1]));
    }

    public function test_fact_rows_tick_what_matches_and_highlight_what_differs(): void
    {
        $members = [
            ['id' => 1, 'vendor_name' => 'Shell', 'expense_date' => '2026-10-03', 'total' => '20.00', 'status' => 'approved', 'submitted_by' => 'Tim'],
            ['id' => 2, 'vendor_name' => 'SHELL', 'expense_date' => '2026-10-03', 'total' => '20.00', 'status' => 'draft', 'submitted_by' => 'Trevor'],
        ];
        $facts = [1 => ['time_first' => '12:04', 'card_last4' => '7640'], 2 => ['time_first' => '12:09']];
        $rows = [];
        foreach (DuplicateReceiptService::factRows($members, $facts) as $r) $rows[$r['label']] = $r;
        $this->assertSame('same', $rows['Vendor']['state'], 'case does not matter');
        $this->assertSame('same', $rows['Total']['state']);
        $this->assertSame('$20.00', $rows['Total']['values'][0]);
        $this->assertSame('differs', $rows['Time']['state']);
        $this->assertSame('missing', $rows['Card']['state']);
        $this->assertSame([null], array_slice($rows['Card']['values'], 1));
        $this->assertSame(['in the books', 'waiting'], $rows['Status']['values']);
        $this->assertSame('info', $rows['Status']['state']);
        $this->assertArrayNotHasKey('Number', $rows, 'a row nobody has is left out');
    }

    public function test_present_group_keeps_the_approved_one_and_offers_no_swap(): void
    {
        $g = ['members' => [['id' => 254, 'status' => 'approved', 'vendor_name' => 'Shell', 'total' => 20],
                            ['id' => 126, 'status' => 'draft', 'vendor_name' => 'Shell', 'total' => 20]], 'pairs' => [[126, 254]]];
        $p = DuplicateReceiptService::presentGroup($g, [], [], ['shell' => ['called' => 3, 'agreed' => 3]]);
        $this->assertSame(254, $p['keep_id']);
        $this->assertSame('Keeping #254 (already in the books)', $p['keep_line']);
        $this->assertSame([126], $p['copy_ids']);
        $this->assertSame('into #254', $p['merge_label']);
        $this->assertSame([], $p['can_swap']);
        $this->assertSame("With Shell I've been right 3 of 3 times.", $p['confidence']);
        $this->assertSame([126, 254], $p['member_ids']);
    }

    public function test_present_group_of_three_waiting_merges_all_into_the_first(): void
    {
        $m = fn($id) => ['id' => $id, 'status' => 'pending_approval', 'vendor_name' => 'Champion'];
        $p = DuplicateReceiptService::presentGroup(['members' => [$m(9), $m(3), $m(5)], 'pairs' => []], [], [], []);
        $this->assertSame(3, $p['keep_id']);
        $this->assertSame([5, 9], $p['copy_ids']);
        $this->assertSame('all into #3', $p['merge_label']);
        $this->assertSame([5, 9], $p['can_swap']);
    }

    // ── Review fixes (2026-11-06) ─────────────────────────────────────────────

    private function withLessons(PDO $db): PDO
    {
        $db->exec("CREATE TABLE head_lessons (id INTEGER PRIMARY KEY, site_id INT, head TEXT, subject_kind TEXT, subject_key TEXT, field TEXT,
                   said TEXT, correct TEXT, source TEXT, ref_type TEXT, ref_id INT, corrected_by INT, reason TEXT, created_at TEXT)");
        return $db;
    }

    public function test_undo_takes_back_the_lesson_the_merge_wrote(): void
    {
        $db = $this->withLessons($this->deskDb());
        $svc = $this->desk($db);
        $svc->removeCopies(412, [408], ['id' => 1]);
        $this->assertSame(1, (int)$db->query("SELECT COUNT(*) FROM head_lessons WHERE source = 'duplicate_merge' AND ref_id = 408")->fetchColumn(), 'merge = Penny was right');
        $svc->unmerge(408, ['id' => 1], 'undo');
        $this->assertSame(0, (int)$db->query("SELECT COUNT(*) FROM head_lessons")->fetchColumn(), 'a slip teaches nothing');
    }

    public function test_split_off_replaces_the_agreement_with_the_correction(): void
    {
        $db = $this->withLessons($this->deskDb());
        $svc = $this->desk($db);
        $svc->removeCopies(412, [408], ['id' => 1]);
        $svc->unmerge(408, ['id' => 1], 'split');
        $rows = $db->query("SELECT source, correct FROM head_lessons")->fetchAll(PDO::FETCH_ASSOC);
        $this->assertSame([['source' => 'duplicate_split_off', 'correct' => 'not a duplicate']], $rows);
    }

    public function test_lines_edited_after_the_merge_stay_on_the_kept_receipt(): void
    {
        $db = $this->deskDb();
        $svc = $this->desk($db);
        $svc->removeCopies(412, [409], ['id' => 1]);
        $db->exec("INSERT INTO expense_line_items (expense_id, name) VALUES (412, 'Chain oil')");   // the owner adds a line
        $this->assertTrue($svc->unmerge(409, ['id' => 1], 'undo')['ok']);
        $this->assertSame(3, (int)$db->query("SELECT COUNT(*) FROM expense_line_items WHERE expense_id = 412")->fetchColumn(), 'nothing moved: the set changed');
        $this->assertSame(0, (int)$db->query("SELECT COUNT(*) FROM expense_line_items WHERE expense_id = 409")->fetchColumn());
    }

    public function test_a_copy_with_no_vendor_counts_under_the_kept_receipts_vendor(): void
    {
        $db = $this->deskDb();
        $db->exec("UPDATE expenses SET vendor_name_raw = 'Home Depot' WHERE id = 412");
        $this->desk($db)->removeCopies(412, [408], ['id' => 1]);
        $rec = $this->desk($db)->vendorRecords();
        $this->assertSame(1, $rec['home depot']['agreed'] ?? 0);
        $this->assertArrayNotHasKey('unknown vendor', $rec);
    }
}
