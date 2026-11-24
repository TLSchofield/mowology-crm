<?php
/**
 * ReaderRelearnService — when the reader learns a vendor's layout, re-read that vendor's waiting receipts
 * (Tim, 2026-10-15: "when Penny learns a lesson on Chevron doesn't it trigger a rescan?" — it didn't:
 * a layout lesson only helped the NEXT receipt; 70 of 99 Chevron receipts had no items).
 *
 * teachReaderFromPenny() → queue(vendor). The penny_prepare cron → run(): up to RUN_MAX of the vendor's
 * waiting receipts (draft / pending approval, with a photo) whose items are missing or don't add up are
 * rescanned (ExpenseRescanService: OCR + the vendor's learned layout; lines a person linked to a product
 * are kept). Receipts whose items already add up, and approved ones, are never touched. Each receipt is
 * rescanned once per lesson (its gate audit row, source 'reader_relearn', after the lesson time). Then
 * Penny's reads of the vendor are marked out of date (BookkeeperDeskService::markRelearn), so she reads
 * them again with the better items.
 *
 * Global-namespace service: require_once the file.
 */
declare(strict_types=1);

class ReaderRelearnService
{
    public const KEY = 'reader_relearn';
    public const SOURCE = 'reader_relearn';          // the gate's audit rows for the rescan's writes
    public const MARK = 'reader_relearn_mark';       // the once-per-lesson marker (note = 'lesson:<id>')
    public const RUN_MAX = 3;
    public const TOLERANCE = 0.05;

    private PDO $db;
    /** @var callable|null fn(int $expenseId): void — tests replace the rescan */
    private $rescan;

    public function __construct(PDO $db, ?callable $rescan = null)
    {
        $this->db = $db;
        $this->rescan = $rescan;
    }

    /** Do this receipt's items fail to describe it? No items, or they add up to neither the subtotal nor the total. */
    public static function needsRescan(int $items, float $itemsSum, ?float $subtotal, float $total): bool
    {
        if ($items === 0) return true;
        $fits = abs($itemsSum - $total) <= self::TOLERANCE || ($subtotal !== null && $subtotal > 0 && abs($itemsSum - $subtotal) <= self::TOLERANCE);
        return !$fits;
    }

    /** A layout lesson for this vendor: its waiting receipts are due a re-read. */
    public function queue(int $vendorId): void
    {
        if ($vendorId <= 0) return;
        // Each lesson gets its own id: a new lesson in the same second as an old marker can't be mistaken for done.
        $this->locked(function () use ($vendorId) {
            $map = $this->map();
            $map[(string)$vendorId] = date('YmdHis') . '-' . bin2hex(random_bytes(4));
            $this->save($map);
        }, 5);
    }

    /** The vendor's waiting receipts due a rescan since $since, oldest first. */
    public function candidates(int $vendorId, string $lesson, int $limit): array
    {
        // Items missing or not adding up (needsRescan, in SQL so nothing past a cap is missed); never a receipt
        // whose lines a person edited (expense_change_log, a user's write touching line_items).
        $t = self::TOLERANCE;
        $s = $this->db->prepare("
            SELECT e.id FROM expenses e
            LEFT JOIN (SELECT expense_id, COUNT(*) AS n, SUM(line_total) AS s FROM expense_line_items GROUP BY expense_id) li ON li.expense_id = e.id
            WHERE e.vendor_id = ? AND e.status IN ('draft', 'pending_approval') AND e.receipt_media_id IS NOT NULL
              AND (li.n IS NULL OR (ABS(li.s - e.total) > {$t} AND (e.amount IS NULL OR e.amount <= 0 OR ABS(li.s - e.amount) > {$t})))
              AND NOT EXISTS (SELECT 1 FROM expense_change_log l WHERE l.expense_id = e.id AND l.source = ? AND l.note = ?)
              AND NOT EXISTS (SELECT 1 FROM expense_change_log u WHERE u.expense_id = e.id AND u.actor_kind = 'user' AND u.fields LIKE '%line_item%')
            ORDER BY e.expense_date, e.id
            LIMIT " . max(1, (int)$limit));
        $s->execute([$vendorId, self::MARK, 'lesson:' . $lesson]);
        return array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Rescan up to $max queued receipts. A vendor with nothing left drops off the queue and Penny's reads
     * of it are marked out of date. @return array{rescanned: int, failed: int, vendors_done: int}
     */
    public function run(int $max = self::RUN_MAX): array
    {
        $r = $this->locked(fn() => $this->runLocked($max), 0);
        return $r ?? ['rescanned' => 0, 'failed' => 0, 'vendors_done' => 0];
    }

    /** Run $fn under the queue's named lock (wait up to $wait s); null when the lock is busy. */
    private function locked(callable $fn, int $wait)
    {
        $got = true;
        try { $got = (int)$this->db->query("SELECT GET_LOCK(CONCAT(DATABASE(), '.reader_relearn'), " . (int)$wait . ")")->fetchColumn() === 1; }
        catch (Throwable $e) { $got = true; }   // no named locks (tests): behave as before
        if (!$got) return null;
        try {
            return $fn();
        } finally {
            try { $this->db->query("SELECT RELEASE_LOCK(CONCAT(DATABASE(), '.reader_relearn'))"); } catch (Throwable $e) { /* ends with the connection */ }
        }
    }

    private function runLocked(int $max): array
    {
        $map = $this->map();
        $done = 0; $failed = 0; $finished = 0;
        $gone = [];
        foreach ($map as $vid => $lesson) {
            $left = $max - $done - $failed;
            $ids = $left > 0 ? $this->candidates((int)$vid, (string)$lesson, $left) : [];
            foreach ($ids as $id) {
                try {
                    $this->rescanOne($id, (string)$lesson);
                    $done++;
                } catch (Throwable $e) {
                    error_log('Reader relearn #' . $id . ': ' . $e->getMessage());
                    $this->mark($id, (string)$lesson);   // once per lesson, even on failure
                    $failed++;
                }
            }
            if (!$this->candidates((int)$vid, (string)$lesson, 1)) {
                $gone[(string)$vid] = (string)$lesson;
                $finished++;
                $this->pennyRereads((int)$vid);
            }
            if ($done + $failed >= $max) break;
        }
        // Re-read the queue now (we hold the lock): drop only finished vendors whose lesson is unchanged.
        $now = $this->map();
        foreach ($gone as $vid => $lesson) if (($now[$vid] ?? null) === $lesson) unset($now[$vid]);
        $this->save($now);
        return ['rescanned' => $done, 'failed' => $failed, 'vendors_done' => $finished];
    }

    private function rescanOne(int $id, string $lesson): void
    {
        if ($this->rescan) { ($this->rescan)($id); $this->mark($id, $lesson); return; }
        require_once __DIR__ . '/ExpenseRescanService.php';
        (new ExpenseRescanService($this->db))->rescan($id, ['id' => null, 'kind' => 'system'], self::SOURCE, ['waiting_only' => true]);
        $this->mark($id, $lesson);
    }

    /** The once-per-lesson marker: this receipt was tried for this lesson (rescanned or failed). */
    private function mark(int $id, string $lesson): void
    {
        try {
            $this->db->prepare("INSERT INTO expense_change_log (expense_id, action, source, actor_user_id, actor_kind, fields, before_text, after_text, note, created_at)
                                VALUES (?, 'update', ?, NULL, 'system', '', NULL, NULL, ?, ?)")
               ->execute([$id, self::MARK, 'lesson:' . $lesson, date('Y-m-d H:i:s')]);
        } catch (Throwable $e) {
            error_log('Reader relearn mark #' . $id . ': ' . $e->getMessage());
        }
    }

    private function pennyRereads(int $vendorId): void
    {
        try {
            $s = $this->db->prepare('SELECT name FROM vendors WHERE id = ?');
            $s->execute([$vendorId]);
            $name = trim((string)$s->fetchColumn());
            if ($name === '') return;
            require_once __DIR__ . '/BookkeeperDeskService.php';
            require_once __DIR__ . '/ReceiptBookkeeperService.php';
            (new BookkeeperDeskService($this->db, new ReceiptBookkeeperService($this->db)))->markRelearn($name);
        } catch (Throwable $e) {
            error_log('Reader relearn → Penny: ' . $e->getMessage());
        }
    }

    public function map(): array
    {
        try {
            $s = $this->db->prepare('SELECT setting_value FROM ops_settings WHERE setting_key = ?');
            $s->execute([self::KEY]);
            $m = json_decode((string)$s->fetchColumn(), true);
            return is_array($m) ? $m : [];
        } catch (Throwable $e) {
            return [];
        }
    }

    private function save(array $map): void
    {
        try {
            $u = $this->db->prepare('UPDATE ops_settings SET setting_value = ? WHERE setting_key = ?');
            $u->execute([json_encode($map), self::KEY]);
            if ($u->rowCount() === 0) {
                $c = $this->db->prepare('SELECT COUNT(*) FROM ops_settings WHERE setting_key = ?');
                $c->execute([self::KEY]);
                if (!(int)$c->fetchColumn()) {
                    $this->db->prepare("INSERT INTO ops_settings (setting_key, setting_value, description) VALUES (?, ?, 'Reader: vendors whose waiting receipts are rescanned after a layout lesson')")
                       ->execute([self::KEY, json_encode($map)]);
                }
            }
        } catch (Throwable $e) {
            error_log('Reader relearn save: ' . $e->getMessage());
        }
    }
}
