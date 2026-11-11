<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Ledger journal filters, entry kinds, and the locked-period exception report. */
class JournalAndExceptionsTest extends TestCase
{
    private function db(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->exec("CREATE TABLE chart_of_accounts (id INTEGER PRIMARY KEY, code TEXT, name TEXT)");
        $db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, full_name TEXT)");
        $db->exec("INSERT INTO users VALUES (1, 'Tim Schofield')");
        $db->exec("CREATE TABLE journal_entries (id INTEGER PRIMARY KEY, entry_date TEXT, memo TEXT, source_type TEXT, source_id INTEGER,
                   entry_kind TEXT DEFAULT 'system', status TEXT DEFAULT 'posted', is_adjusting INTEGER DEFAULT 0, reversed_by_entry_id INTEGER,
                   created_by INTEGER, proposed_by TEXT, created_at TEXT)");
        $db->exec("CREATE TABLE journal_lines (id INTEGER PRIMARY KEY, entry_id INTEGER, account_id INTEGER, debit REAL DEFAULT 0, credit REAL DEFAULT 0, gst_amount REAL DEFAULT 0, pst_amount REAL DEFAULT 0, description TEXT)");
        $db->exec("INSERT INTO chart_of_accounts VALUES (1,'1010','Chequing'),(2,'6100','Fuel & Vehicle'),(3,'4900','Other Services'),(4,'1100','AR')");
        $e = $db->prepare("INSERT INTO journal_entries (id, entry_date, memo, source_type, source_id, entry_kind, created_by, created_at) VALUES (?,?,?,?,?,?,?,?)");
        $l = $db->prepare("INSERT INTO journal_lines (entry_id, account_id, debit, credit, description) VALUES (?,?,?,?,?)");
        foreach ([
            [1, '2026-01-05', 'Esso fuel', 'bank_import', 100, 'system', null, [[2, 80.50, 0, 'ESSO 123'], [1, 0, 80.50, null]]],
            [2, '2026-02-10', 'Invoice #7', 'invoice', 7, 'system', null, [[4, 1194.39, 0, null], [3, 0, 1194.39, 'Monthly care']]],
            [3, '2026-03-01', 'Macdonald cheque already in the books', 'manual', null, 'manual', 1, [[1, 50, 0, null], [4, 0, 50, null]]],
            [4, '2026-03-02', 'Reversal of #1 — wrong account', 'adjusting', 1, 'reversal', 1, [[1, 80.50, 0, null], [2, 0, 80.50, null]]],
        ] as [$id, $d, $memo, $st, $sid, $kind, $by, $lines]) {
            $e->execute([$id, $d, $memo, $st, $sid, $kind, $by, "$d 10:00:00"]);
            foreach ($lines as [$acc, $dr, $cr, $desc]) $l->execute([$id, $acc, $dr, $cr, $desc]);
        }
        return $db;
    }

    private function ids(PDO $db, array $q): array
    {
        [$where, $p] = JournalBrowserService::whereFor(JournalBrowserService::normalizeFilters($q));
        $st = $db->prepare("SELECT je.id FROM journal_entries je WHERE $where ORDER BY je.id");
        $st->execute($p);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    }

    public function test_journal_filters(): void
    {
        $db = $this->db();
        $this->assertSame([1, 2, 3, 4], $this->ids($db, []));
        $this->assertSame([2, 3], $this->ids($db, ['from' => '2026-02-01', 'to' => '2026-03-01']), 'date range, inclusive');
        $this->assertSame([2, 3], $this->ids($db, ['from' => '2026-03-01', 'to' => '2026-02-01']), 'reversed range is swapped');
        $this->assertSame([1, 4], $this->ids($db, ['account' => '6100']), 'any line on the account');
        $this->assertSame([3], $this->ids($db, ['source' => 'manual']));
        $this->assertSame([4], $this->ids($db, ['kind' => 'reversal']));
        $this->assertSame([3, 4], $this->ids($db, ['user' => '1']));
        $this->assertSame([3], $this->ids($db, ['q' => 'macdonald']), 'memo text');
        $this->assertSame([1], $this->ids($db, ['q' => 'ESSO 123']), 'line description');
        $this->assertSame([2], $this->ids($db, ['q' => 'monthly']), 'line description text');
        $this->assertSame([2], $this->ids($db, ['q' => '1194.39']), 'amount');
        $this->assertSame([1, 4], $this->ids($db, ['q' => '#1']), 'entry number, or the entry it reverses (source id)');
        $this->assertSame([1, 2, 3, 4], $this->ids($db, ['kind' => 'bogus', 'from' => 'yesterday', 'account' => "x' OR 1=1"]), 'junk filters are dropped');
    }

    public function test_paging_is_bounded(): void
    {
        $f = JournalBrowserService::normalizeFilters(['per' => '100000', 'page' => '-3']);
        $this->assertSame(50, $f['per']);
        $this->assertSame(1, $f['page']);
    }

    public function test_kinds_are_shown_distinctly(): void
    {
        $this->assertSame('AJE', JournalBrowserService::displayKind(['entry_kind' => 'aje']));
        $this->assertSame('RJE', JournalBrowserService::displayKind(['entry_kind' => 'rje']));
        $this->assertSame('Reversal', JournalBrowserService::displayKind(['entry_kind' => 'system', 'source_type' => 'adjusting']));
        $this->assertSame('Manual', JournalBrowserService::displayKind(['entry_kind' => 'manual']));
        $this->assertSame('System', JournalBrowserService::displayKind(['entry_kind' => 'system', 'source_type' => 'invoice']));
        $this->assertSame('manual', LedgerService::entryKindFor('fy_opening'));
        $this->assertSame('reversal', LedgerService::entryKindFor('adjusting'));
        $this->assertSame('system', LedgerService::entryKindFor('bank_import'));
        $this->assertSame('aje', LedgerService::entryKindFor('manual', 'aje'));
        $this->assertSame('manual', LedgerService::entryKindFor('manual', 'nonsense'));
    }

    public function test_entry_detail_links_reversals_and_finds_missing_sources(): void
    {
        $db = $this->db();
        $db->exec("UPDATE journal_entries SET reversed_by_entry_id = 4 WHERE id = 1");
        $svc = new JournalBrowserService($db);
        $e = $svc->entry(1);
        $this->assertSame(4, (int)$e['reversed_by']['id']);
        $this->assertCount(2, $e['lines']);
        $this->assertFalse($e['source']['found'], 'bank line 100 does not exist here');
        $r = $svc->entry(4);
        $this->assertSame(1, (int)$r['reverses']['id']);
        $this->assertSame('Tim Schofield', $r['created_by_name']);
        $this->assertSame('Reversal', $r['display_kind']);
        $this->assertNull($svc->entry(999));
    }

    public function test_penny_summary_reads_her_suggestion(): void
    {
        $s = JournalBrowserService::pennySummary(json_encode(['category' => ['value' => 'Meals', 'reason' => 'coffee and drinks'], 'gst' => ['value' => 0.42]]), 'accepted');
        $this->assertSame('Category: Meals (coffee and drinks); Gst: 0.42 — accepted', $s);
        $this->assertNull(JournalBrowserService::pennySummary('not json'));
    }

    public function test_exception_report_lists_changes_after_a_lock(): void
    {
        $locked = ['2025-12' => ['closed_at' => '2026-10-07 20:03:03', 'closed_by' => null, 'notes' => 'FY2025 filed']];
        $rows = LockExceptionService::classify([
            // before the lock: not an exception
            ['id' => 10, 'entry_date' => '2025-12-15', 'created_at' => '2026-06-19 23:00:05', 'memo' => 'Bank line', 'source_type' => 'bank_import'],
            // dated inside the locked month, made after the lock
            ['id' => 11, 'entry_date' => '2025-12-20', 'created_at' => '2026-10-08 09:00:00', 'memo' => 'Direct write', 'source_type' => 'manual', 'created_by' => 1],
            // automatic posting moved to the current month
            ['id' => 12, 'entry_date' => '2026-10-09', 'created_at' => '2026-10-09 23:30:00', 'memo' => 'Late entry for 2025-12-30 — Expense #88', 'source_type' => 'expense'],
            // a locked-month entry reversed after the lock (the reversal lands today)
            ['id' => 13, 'entry_date' => '2026-10-12', 'created_at' => '2026-10-12 17:32:12', 'memo' => 'Reversal of #10 — duplicate', 'source_type' => 'adjusting',
             'source_id' => 10, 'orig_date' => '2025-12-15', 'created_by' => 1],
            // a reversal of an OPEN month entry: not an exception
            ['id' => 14, 'entry_date' => '2026-10-12', 'created_at' => '2026-10-12 17:33:00', 'memo' => 'Reversal of #2', 'source_type' => 'adjusting',
             'source_id' => 2, 'orig_date' => '2026-02-10'],
            // late entry for a month that is not locked: not an exception
            ['id' => 15, 'entry_date' => '2026-10-09', 'created_at' => '2026-10-09 23:30:00', 'memo' => 'Late entry for 2026-09-30 — x', 'source_type' => 'expense'],
        ], $locked);
        $byId = array_column($rows, 'kind', 'id');
        $this->assertSame([13 => 'reversed', 12 => 'late', 11 => 'inside'], $byId, 'newest first');
        $this->assertSame('2025-12', $rows[0]['period']);
        $this->assertSame('2026-10-07 20:03:03', $rows[0]['locked_at']);
    }

    public function test_exceptions_classify_against_the_first_lock(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->exec("CREATE TABLE accounting_periods (year INTEGER, month INTEGER, status TEXT, closed_at TEXT, closed_by INTEGER, notes TEXT)");
        $db->exec("CREATE TABLE accounting_period_locks (id INTEGER PRIMARY KEY, year INTEGER, month INTEGER, locked_at TEXT, locked_by INTEGER,
                   lock_reason TEXT, unlocked_at TEXT, unlocked_by INTEGER, unlock_reason TEXT)");
        // March 2026: locked Apr 5, reopened Apr 20 for a fix, locked again May 2 — accounting_periods shows only May 2.
        $db->exec("INSERT INTO accounting_periods VALUES (2026, 3, 'locked', '2026-05-02 09:00:00', 1, 'relocked')");
        $db->exec("INSERT INTO accounting_period_locks (year, month, locked_at, unlocked_at, unlock_reason) VALUES
                   (2026, 3, '2026-04-05 09:00:00', '2026-04-20 10:00:00', 'Missed deposit'), (2026, 3, '2026-05-02 09:00:00', NULL, NULL)");
        $locked = (new LockExceptionService($db))->lockedPeriods();
        $this->assertSame('2026-04-05 09:00:00', $locked['2026-03']['closed_at'], 'the first lock, not the re-lock');
        $this->assertTrue($locked['2026-03']['relocked']);
        $rows = LockExceptionService::classify([
            // made while the month was reopened: still a change after it was first locked
            ['id' => 20, 'entry_date' => '2026-03-31', 'created_at' => '2026-04-21 08:00:00', 'memo' => 'Missed deposit', 'source_type' => 'manual'],
            ['id' => 21, 'entry_date' => '2026-03-15', 'created_at' => '2026-03-16 08:00:00', 'memo' => 'Before any lock', 'source_type' => 'manual'],
            // the late flag (set at post time) — no memo needed
            ['id' => 22, 'entry_date' => '2026-06-01', 'created_at' => '2026-06-01 08:00:00', 'memo' => 'Expense #9', 'source_type' => 'expense', 'late_for' => '2026-03-28'],
        ], $locked);
        $this->assertSame([22 => 'late', 20 => 'inside'], array_column($rows, 'kind', 'id'));
    }

    public function test_exceptions_query_uses_month_ranges(): void
    {
        [$sql, $p] = LockExceptionService::rangesSql(['2025-02', '2025-01', '2025-12', '2025-03'], 'je.entry_date');
        $this->assertSame('je.entry_date BETWEEN ? AND ? OR je.entry_date BETWEEN ? AND ?', $sql, 'adjacent months merge');
        $this->assertSame(['2025-01-01', '2025-03-31', '2025-12-01', '2025-12-31'], $p);
        $this->assertSame(['1 = 0', []], LockExceptionService::rangesSql([], 'x'));
    }

    public function test_journal_export_streams_every_line_in_order(): void
    {
        $db = $this->db();
        $db->exec("ALTER TABLE chart_of_accounts ADD COLUMN gifi_code TEXT");
        // well past the old 20,000-entry cap would be slow here; what matters is one query, every line, entry then line order
        $seen = [];
        $n = (new JournalBrowserService($db))->streamLines(JournalBrowserService::normalizeFilters([]), function (array $l) use (&$seen) {
            $seen[] = [(int)$l['id'], $l['code'], (float)$l['debit'], (float)$l['credit'], $l['display_kind']];
        });
        $this->assertSame(8, $n);
        $this->assertSame([1, '6100', 80.5, 0.0, 'System'], $seen[0]);
        $this->assertSame([4, '6100', 0.0, 80.5, 'Reversal'], $seen[7]);
        $this->assertSame([1, 1, 2, 2, 3, 3, 4, 4], array_column($seen, 0));
        $m = (new JournalBrowserService($db))->streamLines(JournalBrowserService::normalizeFilters(['account' => '4900']), function () {});
        $this->assertSame(2, $m, 'filters apply: the entry with a 4900 line, all its lines');
    }
}
