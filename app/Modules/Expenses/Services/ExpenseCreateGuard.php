<?php
/**
 * ExpenseCreateGuard — one photo, one expense.
 *
 * 2026-10-07: expenses #408, #409, #410 were created in the same second from ONE photo
 * (receipt_media_id 1073, Nigel). Every create path (web expenses.php action=create, the
 * offline-queue/service-worker replays that call it, iOS expense-save.php) inserted
 * blindly, so any repeated request — a second tap while the save was still in flight,
 * a retry — became another expense for the same receipt.
 *
 * Rule: a create that carries a receipt_media_id which already has a live (not rejected)
 * expense does not insert; it answers with the existing expense instead. Rejected rows
 * don't count — a receipt set aside as a duplicate, or rejected by mistake, can be
 * submitted again.
 *
 * Concurrency: three requests in the same second can all pass a plain SELECT before any
 * of them INSERTs, so claim() takes a MySQL named lock (GET_LOCK) on the media id first
 * and release() frees it right after the INSERT. On a driver without GET_LOCK (SQLite in
 * tests) it degrades to the SELECT alone.
 *
 * NOTE for "split receipts" (backlog): splitting one photo into several expenses on
 * purpose will need its own path; this guard is for the create endpoints.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
class ExpenseCreateGuard
{
    private PDO $db;
    /** @var array<int, bool> media ids whose named lock this request holds */
    private array $held = [];

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Take the lock for this receipt photo and say whether it is already an expense.
     * Returns the existing expense id (the lock is released again — nothing to insert),
     * or null: go ahead and INSERT, then call release().
     */
    public function claim(?int $mediaId): ?int
    {
        if (!$mediaId) return null;
        $this->lock($mediaId);
        $existing = $this->existingForMedia($mediaId);
        if ($existing !== null) $this->release($mediaId);
        return $existing;
    }

    public function release(?int $mediaId): void
    {
        if (!$mediaId || empty($this->held[$mediaId])) return;
        unset($this->held[$mediaId]);
        try {
            $this->db->prepare("SELECT RELEASE_LOCK(?)")->execute([self::lockName($mediaId)]);
        } catch (Throwable $e) {
            // The lock also ends with the connection.
        }
    }

    /** The live expense already made from this photo, if any. */
    public function existingForMedia(int $mediaId): ?int
    {
        $stmt = $this->db->prepare("SELECT id, status FROM expenses WHERE receipt_media_id = ? ORDER BY id ASC");
        $stmt->execute([$mediaId]);
        return self::pickExisting($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Pure (unit tested)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * The oldest expense that still stands (anything but rejected), or null.
     * @param array<int, array{id: int|string, status: ?string}> $rows
     */
    public static function pickExisting(array $rows): ?int
    {
        foreach ($rows as $r) {
            if (($r['status'] ?? '') !== 'rejected') return (int)$r['id'];
        }
        return null;
    }

    /** The JSON a create endpoint answers with when the photo is already an expense. */
    public static function existingResponse(int $expenseId): array
    {
        return [
            'success'      => true,
            'message'      => 'Already saved — this receipt is expense #' . $expenseId,
            'expense_id'   => $expenseId,
            'deduplicated' => true,
        ];
    }

    public static function lockName(int $mediaId): string
    {
        return 'mw_expense_media_' . $mediaId;
    }

    private function lock(int $mediaId): void
    {
        try {
            if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') return;
            $s = $this->db->prepare("SELECT GET_LOCK(?, 10)");
            $s->execute([self::lockName($mediaId)]);
            if ((int)$s->fetchColumn() === 1) $this->held[$mediaId] = true;
        } catch (Throwable $e) {
            // No lock: the SELECT below still catches every request but a true race.
        }
    }
}
