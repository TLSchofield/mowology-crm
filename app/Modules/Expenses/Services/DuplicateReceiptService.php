<?php
/**
 * DuplicateReceiptService — Penny's "sort duplicates before approval" step.
 *
 * The owner's rule (2026-10-05): a possible duplicate is never offered for approval;
 * it is offered for de-duplication first. This reuses what the receipts page already
 * has rather than a second detector:
 *   detection — ExpenseLookupService::findDuplicates() (same total to the cent within
 *               ±3 days, same vendor) — the same rule as the receipts page grouping and
 *               the review-form warning;
 *   removing  — the copy is rejected "Duplicate of receipt #X" (removeCopy): kept on
 *               record for six years, never deleted. removeCopies() is the phone's
 *               "Keep this one": every other copy of the group goes, its missing fields
 *               merged into the kept one first;
 *   same photo — two expenses with the same receipt_media_id are always a pair, whatever
 *               their OCR'd dates (#408-#410 were one photo read three ways).
 * New here: "not a duplicate" is remembered for good (expense_duplicate_dismissals,
 * migration 1127). The receipts page only remembered it for the browser session.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
require_once __DIR__ . '/ExpenseLookupService.php';
require_once __DIR__ . '/ExpenseApprovalService.php';
require_once __DIR__ . '/ReceiptFactsService.php';
if (!class_exists('HeadLessons')) require_once dirname(__DIR__, 3) . '/Services/HeadLessons.php';

class DuplicateReceiptService
{
    public const WAITING = ['draft', 'pending_approval'];

    private PDO $db;
    /** @var ExpenseGate|null every expense write goes through the gate (migration 1233) */
    private $gate;
    /** @var bool|null expense_merges exists (migration 1442) */
    private $mergesReady = null;
    /** @var array|null vendorRecords(), once per request */
    private $records = null;

    public function __construct(PDO $db, $gate = null)
    {
        $this->db = $db;
        $this->gate = $gate;
    }

    public function dismissalsReady(): bool
    {
        try {
            return $this->db->query("SHOW TABLES LIKE 'expense_duplicate_dismissals'")->rowCount() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** "minId-maxId" => true for every pair the owner said is not a duplicate. */
    public function dismissed(): array
    {
        if (!$this->dismissalsReady()) return [];
        $out = [];
        foreach ($this->db->query("SELECT expense_a, expense_b FROM expense_duplicate_dismissals")->fetchAll(PDO::FETCH_NUM) as [$a, $b]) {
            $out[self::key((int)$a, (int)$b)] = true;
        }
        return $out;
    }

    /**
     * Open duplicate pairs among the given waiting receipts. a = the waiting receipt
     * (the older one when both wait), b = its possible twin.
     * @param int[] $expenseIds
     * @return array<int, array{a: array, b: array}>
     */
    public function pairsFor(array $expenseIds): array
    {
        $expenseIds = array_values(array_unique(array_map('intval', $expenseIds)));
        if (!$expenseIds) return [];
        $in = implode(',', array_fill(0, count($expenseIds), '?'));
        $stmt = $this->db->prepare("
            SELECT e.id, e.expense_date, e.total, e.status, e.vendor_id, e.vendor_name_raw, e.receipt_media_id,
                   v.name AS vendor_name, u.full_name AS submitted_by
            FROM expenses e
            LEFT JOIN vendors v ON v.id = e.vendor_id
            LEFT JOIN users u ON u.id = e.created_by
            WHERE e.id IN ({$in}) AND e.status IN ('draft', 'pending_approval')
        ");
        $stmt->execute($expenseIds);
        $mine = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($mine as &$m) {
            $m['receipt_path'] = $m['receipt_media_id'] ? '/crm/api/serve-receipt.php?id=' . (int)$m['receipt_media_id'] : null;
        }
        unset($m);

        $lookup = new ExpenseLookupService($this->db);
        $candidates = [];
        foreach ($mine as $e) {
            $candidates[(int)$e['id']] = self::withSamePhoto(
                $lookup->findDuplicates(
                    $e['vendor_name'] ?: $e['vendor_name_raw'], $e['vendor_id'] ? (int)$e['vendor_id'] : null,
                    (float)$e['total'], (string)$e['expense_date'], (int)$e['id']
                ),
                $this->samePhoto($e)
            );
        }
        // Printed facts (receipt_facts, migration 1227): same ticket number = the same receipt;
        // a different ticket number or printed time = two receipts (remembered, Penny stops asking).
        [$candidates, $why, $factLines] = $this->applyReceiptFacts($mine, $candidates);
        $pairs = self::pairUp($mine, $candidates, $this->dismissed());
        foreach ($pairs as &$p) {
            $k = self::key((int)$p['a']['id'], (int)$p['b']['id']);
            if (isset($why[$k])) $p['why'] = $why[$k];
            foreach (['a', 'b'] as $side) $p[$side]['facts_line'] = $factLines[(int)$p[$side]['id']] ?? '';
        }
        unset($p);
        // Who sent each one in, for the side-by-side.
        $who = $this->db->prepare("SELECT u.full_name FROM expenses e LEFT JOIN users u ON u.id = e.created_by WHERE e.id = ?");
        foreach ($pairs as &$p) {
            foreach (['a', 'b'] as $side) {
                if (!array_key_exists('submitted_by', $p[$side])) {
                    $who->execute([(int)$p[$side]['id']]);
                    $p[$side]['submitted_by'] = $who->fetchColumn() ?: null;
                }
            }
        }
        unset($p);
        return $pairs;
    }

    /**
     * The printed-facts step of pairsFor(): adds same-vendor + same-ticket-number receipts as
     * certain duplicates, drops (and remembers as "not a duplicate", dismissed_by NULL) pairs whose
     * ticket numbers / printed times differ. No facts → candidates unchanged.
     * @return array{0: array, 1: array<string,string>, 2: array<int,string>} candidates, why by pair key, facts line by id
     */
    private function applyReceiptFacts(array $mine, array $candidates): array
    {
        try {
            $rf = new ReceiptFactsService($this->db);
            if (!$rf->ready()) return [$candidates, [], []];
            $ids = [];
            foreach ($mine as $e) $ids[] = (int)$e['id'];
            $facts = $rf->forExpenses($ids);
            foreach ($mine as $e) {
                $id = (int)$e['id'];
                if (!isset($facts[$id])) continue;
                $candidates[$id] = self::withSamePhoto($candidates[$id] ?? [], $rf->sameDocNumber($e, $facts[$id]));
            }
            foreach ($candidates as $rows) foreach ($rows as $c) $ids[] = (int)$c['id'];
            $facts = $rf->forExpenses($ids);
            $sorted = ReceiptFactsService::sortCandidates($mine, $candidates, $facts);
            if ($sorted['dismiss'] && $this->dismissalsReady()) {
                $ins = $this->db->prepare("INSERT IGNORE INTO expense_duplicate_dismissals (expense_a, expense_b, dismissed_by) VALUES (?, ?, NULL)");
                foreach ($sorted['dismiss'] as [$a, $b, $reason]) {
                    $ins->execute([$a, $b]);
                    error_log("Penny: #{$a} / #{$b} not duplicates — {$reason}");
                }
            }
            $lines = [];
            foreach ($facts as $id => $f) $lines[$id] = ReceiptFactsService::line($f);
            return [$sorted['candidates'], $sorted['why'], $lines];
        } catch (Throwable $e) {
            error_log('DuplicateReceipt facts: ' . $e->getMessage());
            return [$candidates, [], []];
        }
    }

    /**
     * Pairs among the next receipts in Penny's line (her preparation order: submitted
     * first, oldest first) — what the card sorts before anything is approved.
     */
    public function pairsInLine(int $n = 60): array
    {
        $ids = $this->db->query("
            SELECT id FROM expenses WHERE status IN ('draft', 'pending_approval')
            ORDER BY (status = 'pending_approval') DESC, expense_date ASC, id ASC
            LIMIT " . max(1, min(200, $n))
        )->fetchAll(PDO::FETCH_COLUMN);
        return $this->pairsFor($ids);
    }

    /** Waiting receipts in an open pair: held back from approval and from Penny's AI read. */
    public static function heldIds(array $pairs): array
    {
        $ids = [];
        foreach ($pairs as $p) {
            foreach (['a', 'b'] as $side) {
                if (in_array($p[$side]['status'] ?? '', self::WAITING, true)) $ids[(int)$p[$side]['id']] = true;
            }
        }
        return array_keys($ids);
    }

    /**
     * Merge one copy into the kept receipt: REJECT the copy as "Duplicate of receipt #X" — kept for the
     * six-year record, never deleted. Its photo moves to the one kept when that has none (a waiting
     * receipt only — an approved one is locked). Only a waiting receipt can be merged away.
     * New (migration 1442): an expense_merges row holds the copy's prior status and what the kept one
     * took from it, so unmerge() can split it off again exactly; the owner's tap is recorded as agreeing
     * with Penny's call (HeadLessons, per vendor). Callers merge through removeCopies() (group-checked).
     * @param array $took what removeCopies() already carried into the kept one: ['fill' => [col => value], 'lines' => bool]
     */
    public function removeCopy(int $copyId, int $keepId, array $user, array $took = []): array
    {
        if (!$copyId || !$keepId || $copyId === $keepId) return ['ok' => false, 'message' => 'Pick the copy and the one to keep'];
        $s = $this->db->prepare("SELECT * FROM expenses WHERE id IN (?, ?)");
        $s->execute([$copyId, $keepId]);
        $rows = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $rows[(int)$r['id']] = $r;
        $copy = $rows[$copyId] ?? null;
        $keep = $rows[$keepId] ?? null;
        if (!$copy || !$keep) return ['ok' => false, 'message' => 'Receipt not found'];
        if (!in_array($copy['status'], self::WAITING, true)) return ['ok' => false, 'message' => "That one's already approved — remove the other instead"];
        $actor = ['id' => (int)($user['id'] ?? 0) ?: null, 'kind' => 'user'];
        $took = ['fill' => (array)($took['fill'] ?? []), 'lines' => !empty($took['lines']),
                 'line_ids' => array_map('intval', (array)($took['line_ids'] ?? [])), 'photo' => null];
        if (empty($keep['receipt_media_id']) && !empty($copy['receipt_media_id']) && in_array($keep['status'], self::WAITING, true)) {
            $this->gate()->apply($keepId, ['receipt_media_id' => (int)$copy['receipt_media_id']], $actor, 'duplicates');
            $took['photo'] = (int)$copy['receipt_media_id'];
        }
        $evidence = $this->evidenceFor($copy, $keep);
        (new ExpenseApprovalService($this->db, $this->gate()))->reject($copyId, $user, 'Duplicate of receipt #' . $keepId, ['source' => 'duplicates']);
        $this->db->prepare("UPDATE expense_suggestions SET status = 'superseded' WHERE expense_id = ? AND source = 'live' AND status IN ('pending', 'error')")
           ->execute([$copyId]);
        $mergeId = null;
        if ($this->mergesReady()) {
            $before = ['status' => $copy['status'], 'approved_by' => $copy['approved_by'] ?? null, 'approved_at' => $copy['approved_at'] ?? null,
                       'rejection_reason' => $copy['rejection_reason'] ?? null, 'took' => $took];
            $this->db->prepare("INSERT INTO expense_merges (copy_id, kept_id, evidence, evidence_text, copy_before_json, merged_by, merged_at)
                                VALUES (?, ?, ?, ?, ?, ?, ?)")
               ->execute([$copyId, $keepId, $evidence['kind'], mb_substr($evidence['text'], 0, 255), json_encode($before),
                          $actor['id'], date('Y-m-d H:i:s')]);
            $mergeId = (int)$this->db->lastInsertId();
        }
        // Penny proposed it, the owner agreed (Tim 2026-11-05: "affirmation teaches her").
        $this->lesson($copyId, 'copy of #' . $keepId, 'copy of #' . $keepId, 'duplicate_merge', $actor['id'], $evidence['kind'], true);
        return ['ok' => true, 'merge_id' => $mergeId,
                'message' => "Done — #{$copyId} is set aside as a duplicate of #{$keepId} (kept on record, not deleted)."];
    }

    /**
     * "Keep this one" (Penny's phone card): every other copy in the group is set aside
     * the web card's way — removeCopy(), i.e. rejected "Duplicate of receipt #keep", kept
     * on record, its live Penny suggestion superseded. Before each copy goes, whatever the
     * kept receipt is missing (job, category, notes, line items…) is carried over from it
     * ("merge") — only into empty fields, and only while the kept one still waits.
     *
     * Refused unless every id is in ONE duplicate group with the kept one and every copy
     * is still waiting (draft / pending_approval). All-or-nothing.
     *
     * @param int[] $removeIds
     */
    public function removeCopies(int $keepId, array $removeIds, array $user): array
    {
        $removeIds = array_values(array_unique(array_filter(array_map('intval', $removeIds), fn($id) => $id > 0)));
        if (!$keepId || !$removeIds) return ['ok' => false, 'message' => 'Pick the receipt to keep and the copies to remove'];
        if (in_array($keepId, $removeIds, true)) return ['ok' => false, 'message' => "You can't keep and remove the same receipt"];
        if (count($removeIds) > 20) return ['ok' => false, 'message' => 'Too many copies in one go'];

        $all = array_merge([$keepId], $removeIds);
        $in = implode(',', array_fill(0, count($all), '?'));
        $s = $this->db->prepare("SELECT * FROM expenses WHERE id IN ({$in})");
        $s->execute($all);
        $rows = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $rows[(int)$r['id']] = $r;
        foreach ($all as $id) {
            if (!isset($rows[$id])) return ['ok' => false, 'message' => "Receipt #{$id} not found"];
        }
        foreach ($removeIds as $id) {
            if (!in_array($rows[$id]['status'], self::WAITING, true)) {
                return ['ok' => false, 'message' => "#{$id} is already " . str_replace('_', ' ', (string)$rows[$id]['status']) . ' — it stays. Keep that one instead.'];
            }
        }
        if (!$this->inOneGroup($keepId, $removeIds)) {
            return ['ok' => false, 'message' => "These aren't one duplicate group any more — refresh and try again"];
        }

        $keepWaits = in_array($rows[$keepId]['status'], self::WAITING, true);
        $carried = [];
        $merges = [];
        $own = !$this->db->inTransaction();
        if ($own) $this->db->beginTransaction();
        try {
            foreach ($removeIds as $copyId) {
                $took = ['fill' => [], 'lines' => false];
                if ($keepWaits) {
                    $fill = self::mergeFill($rows[$keepId], $rows[$copyId]);
                    if ($fill) {
                        // Through the expense gate (audited as the duplicates clean-up).
                        $this->gate()->apply($keepId, $fill, ['id' => (int)($user['id'] ?? 0) ?: null, 'kind' => 'user'], 'duplicates');
                        $rows[$keepId] = $fill + $rows[$keepId];
                        $carried = array_merge($carried, array_keys($fill));
                        $took['fill'] = $fill;
                    }
                    $copyLines = $this->lineIds($copyId);
                    if ($this->moveLineItemsIfMissing($copyId, $keepId, $user)) {
                        $carried[] = 'line items';
                        $took['lines'] = true;
                        $took['line_ids'] = $copyLines;   // Split off moves back only these, and only while they are all still there
                    }
                    if (empty($rows[$keepId]['receipt_media_id']) && !empty($rows[$copyId]['receipt_media_id'])) {
                        $rows[$keepId]['receipt_media_id'] = $rows[$copyId]['receipt_media_id'];   // removeCopy moves the photo
                        $carried[] = 'photo';
                    }
                }
                $res = $this->removeCopy($copyId, $keepId, $user, $took);
                if (empty($res['ok'])) throw new RuntimeException($res['message'] ?? "Couldn't set #{$copyId} aside");
                if (!empty($res['merge_id'])) $merges[$copyId] = (int)$res['merge_id'];
            }
            if ($own) $this->db->commit();
        } catch (Throwable $e) {
            if ($own && $this->db->inTransaction()) $this->db->rollBack();
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        $n = count($removeIds);
        $carried = array_values(array_unique(array_map([self::class, 'fieldLabel'], $carried)));
        return [
            'ok'      => true,
            'kept'    => $keepId,
            'removed' => $removeIds,
            'carried' => $carried,
            'merges'  => $merges,
            'undo'    => (bool)$merges,
            'message' => "Kept #{$keepId} — " . ($n === 1 ? 'the copy is' : "{$n} copies are") . ' set aside (kept on record, not deleted)'
                       . ($carried ? '; took the ' . implode(', ', $carried) . ' from ' . ($n === 1 ? 'it' : 'them') : '') . '.',
        ];
    }

    /** Are the kept receipt and every copy linked into one group (the same grouping the card shows)? */
    protected function inOneGroup(int $keepId, array $removeIds): bool
    {
        $ids = array_merge([$keepId], $removeIds);
        return self::sameGroup(self::groups($this->pairsFor($ids)), $ids);
    }

    /** The copy's line items move to the kept receipt when that has none. */
    private function moveLineItemsIfMissing(int $copyId, int $keepId, array $user = []): bool
    {
        try {
            $res = $this->gate()->apply($keepId, ['line_items_from' => $copyId], ['id' => (int)($user['id'] ?? 0) ?: null, 'kind' => 'user'], 'duplicates');
            return !empty($res['lines_moved']);
        } catch (PDOException $e) {
            return false;   // no line-items table here — nothing to carry
        }
    }

    private function gate(): ExpenseGate
    {
        if ($this->gate === null) {
            require_once __DIR__ . '/ExpenseGate.php';
            $this->gate = new ExpenseGate($this->db);
        }
        return $this->gate;
    }

    /** Other live expenses made from the very same photo — always the same receipt. */
    private function samePhoto(array $e): array
    {
        if (empty($e['receipt_media_id'])) return [];
        $s = $this->db->prepare("
            SELECT e.id, e.expense_date, e.total, e.status, e.vendor_name_raw, e.receipt_media_id, v.name AS vendor_name
            FROM expenses e LEFT JOIN vendors v ON v.id = e.vendor_id
            WHERE e.receipt_media_id = ? AND e.id != ? AND e.status != 'rejected'
        ");
        $s->execute([(int)$e['receipt_media_id'], (int)$e['id']]);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) $r['receipt_path'] = '/crm/api/serve-receipt.php?id=' . (int)$r['receipt_media_id'];
        unset($r);
        return $rows;
    }

    /** Owner: "not a duplicate" — never pair these two again (here or on the receipts page). */
    public function dismiss(int $a, int $b, array $user): array
    {
        if (!$a || !$b || $a === $b) return ['ok' => false, 'message' => 'Pick two receipts'];
        if (!$this->dismissalsReady()) return ['ok' => false, 'message' => 'Needs migration 1127'];
        $this->db->prepare("INSERT IGNORE INTO expense_duplicate_dismissals (expense_a, expense_b, dismissed_by) VALUES (?, ?, ?)")
           ->execute([min($a, $b), max($a, $b), (int)$user['id']]);
        // Penny flagged these as copies; the owner said they are two purchases (head_lessons, migration 1348).
        if (!defined('APP_ROOT')) require_once dirname(__DIR__, 3) . '/Core/paths.php';
        require_once APP_ROOT . '/Modules/Accounting/Services/PennyLessons.php';
        PennyLessons::expense($this->db, min($a, $b), 'duplicate', 'copy of #' . max($a, $b), 'not a duplicate', 'duplicate_dismiss', (int)$user['id']);
        return ['ok' => true, 'message' => 'Got it — not a duplicate. Both go on for approval.'];
    }

    public function mergesReady(): bool
    {
        if ($this->mergesReady === null) {
            try {
                $this->db->query("SELECT 1 FROM expense_merges WHERE 1 = 0");
                $this->mergesReady = true;
            } catch (Throwable $e) {
                $this->mergesReady = false;
            }
        }
        return $this->mergesReady;
    }

    /**
     * Split a merged copy off again (expense_merges, migration 1442). The copy goes back to the waiting
     * status it had (gate transition 'unmerge'); what the kept receipt took from it (empty fields filled,
     * its photo, its line items) goes back too while the kept one still waits and still holds it.
     *   $kind 'undo'  — a slip straight after the tap: the pair is asked about again, nothing is learned;
     *   $kind 'split' — they are two purchases after all: remembered as "not a duplicate" (a person's
     *                   dismissal) and Penny learns she was wrong (the strongest signal, Tim 2026-11-05).
     * All-or-nothing.
     */
    public function unmerge(int $copyId, array $user, string $kind = 'split'): array
    {
        $kind = $kind === 'undo' ? 'undo' : 'split';
        if (!$copyId) return ['ok' => false, 'message' => 'Pick the receipt to split off'];
        if (!$this->mergesReady()) return ['ok' => false, 'message' => 'Needs migration 1442'];
        $s = $this->db->prepare("SELECT * FROM expense_merges WHERE copy_id = ? AND unmerged_at IS NULL ORDER BY id DESC LIMIT 1");
        $s->execute([$copyId]);
        $m = $s->fetch(PDO::FETCH_ASSOC);
        if (!$m) return ['ok' => false, 'message' => "#{$copyId} isn't merged into another receipt — nothing to split off"];
        $keepId = (int)$m['kept_id'];
        $e = $this->db->prepare("SELECT * FROM expenses WHERE id IN (?, ?)");
        $e->execute([$copyId, $keepId]);
        $rows = [];
        foreach ($e->fetchAll(PDO::FETCH_ASSOC) as $r) $rows[(int)$r['id']] = $r;
        if (($rows[$copyId]['status'] ?? '') !== 'rejected') return ['ok' => false, 'message' => "#{$copyId} is no longer set aside — nothing to split off"];
        $before = json_decode((string)$m['copy_before_json'], true) ?: [];
        $status = in_array($before['status'] ?? '', self::WAITING, true) ? $before['status'] : 'draft';
        $actor = ['id' => (int)($user['id'] ?? 0) ?: null, 'kind' => 'user'];
        $keep = $rows[$keepId] ?? null;
        $keepWaits = $keep && in_array($keep['status'], self::WAITING, true);
        $took = (array)($before['took'] ?? []);

        $own = !$this->db->inTransaction();
        if ($own) $this->db->beginTransaction();
        try {
            // Claim the merge row first: a double tap / retry finds it already taken and stops here.
            $claim = $this->db->prepare("UPDATE expense_merges SET unmerged_by = ?, unmerged_at = ?, unmerge_kind = ? WHERE id = ? AND unmerged_at IS NULL");
            $claim->execute([$actor['id'], date('Y-m-d H:i:s'), $kind, (int)$m['id']]);
            if ($claim->rowCount() !== 1) throw new RuntimeException("#{$copyId} was already split off");
            $this->gate()->apply($copyId, [
                'status' => $status, 'approved_by' => $before['approved_by'] ?? null,
                'approved_at' => $before['approved_at'] ?? null, 'rejection_reason' => $before['rejection_reason'] ?? null,
            ], $actor, 'duplicates', ['transition' => 'unmerge']);
            $gaveBack = [];
            if ($keepWaits) {
                $back = self::takeBack($keep, (array)($took['fill'] ?? []), $took['photo'] ?? null);
                if ($back) {
                    $this->gate()->apply($keepId, $back, $actor, 'duplicates');
                    $gaveBack = array_keys($back);
                }
                // Lines go back only when the kept receipt still holds exactly the ones it took (an edit since keeps them there).
                $taken = array_map('intval', (array)($took['line_ids'] ?? []));
                if (!empty($took['lines']) && self::sameIds($taken, $this->lineIds($keepId))
                    && $this->moveLineItemsIfMissing($keepId, $copyId, $user)) $gaveBack[] = 'line items';
            }
            // Merge was a slip or wrong: the "Penny was right" lesson it wrote is taken back.
            $this->retractAgreement($copyId);
            if ($kind === 'split' && $this->dismissalsReady()) {
                $this->db->prepare("INSERT IGNORE INTO expense_duplicate_dismissals (expense_a, expense_b, dismissed_by) VALUES (?, ?, ?)")
                   ->execute([min($copyId, $keepId), max($copyId, $keepId), $actor['id']]);
            }
            if ($own) $this->db->commit();
        } catch (Throwable $ex) {
            if ($own && $this->db->inTransaction()) $this->db->rollBack();
            return ['ok' => false, 'message' => $ex->getMessage()];
        }
        if ($kind === 'split') {
            $this->lesson($copyId, 'copy of #' . $keepId, 'not a duplicate', 'duplicate_split_off', $actor['id'], (string)($m['evidence'] ?? ''));
        }
        $gaveBack = array_values(array_unique(array_map([self::class, 'fieldLabel'], $gaveBack)));
        $note = $gaveBack ? ' #' . $keepId . ' gave back the ' . implode(', ', $gaveBack) . '.'
              : (!$keepWaits && (!empty($took['fill']) || !empty($took['photo']) || !empty($took['lines'])) ? ' #' . $keepId . ' is in the books, so it keeps what it took.' : '');
        return ['ok' => true, 'copy_id' => $copyId, 'kept_id' => $keepId, 'status' => $status,
                'message' => ($kind === 'undo' ? "Undone — #{$copyId} is back with #{$keepId} to decide again." : "Split off — #{$copyId} is its own receipt again and goes on for approval.") . $note];
    }

    /**
     * Copies merged into this receipt that are still set aside — "Also on this purchase" on the kept receipt.
     * @return list<array{merge_id:int, copy_id:int, expense_date:?string, total:?string, receipt_media_id:?int,
     *                    merged_at:string, merged_by:?string, evidence_text:?string}>
     */
    public function mergedInto(int $keptId): array
    {
        if (!$keptId || !$this->mergesReady()) return [];
        $s = $this->db->prepare("
            SELECT m.id AS merge_id, m.copy_id, e.expense_date, e.total, e.receipt_media_id, m.merged_at,
                   u.full_name AS merged_by, m.evidence_text
            FROM expense_merges m
            JOIN expenses e ON e.id = m.copy_id
            LEFT JOIN users u ON u.id = m.merged_by
            WHERE m.kept_id = ? AND m.unmerged_at IS NULL AND e.status = 'rejected'
            ORDER BY m.id
        ");
        $s->execute([$keptId]);
        $out = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $r['merge_id'] = (int)$r['merge_id'];
            $r['copy_id'] = (int)$r['copy_id'];
            $r['receipt_media_id'] = $r['receipt_media_id'] ? (int)$r['receipt_media_id'] : null;
            $r['receipt_path'] = $r['receipt_media_id'] ? '/crm/api/serve-receipt.php?id=' . $r['receipt_media_id'] : null;
            $out[] = $r;
        }
        return $out;
    }

    /**
     * Penny's record on duplicates, per vendor (key = HeadLessons::normaliseKey(vendor name)):
     *   agreed         — pairs she called that a person merged (expense_merges still merged, plus the older
     *                    "Duplicate of receipt #X" rejects from before the table);
     *   said_different — pairs a person said are two purchases (expense_duplicate_dismissals with dismissed_by;
     *                    a Split off writes one too, so it counts here as well);
     *   split_off      — of those, the ones merged first and split off later.
     * Undo (a slip) counts nowhere. Read-only; each table is optional.
     * @return array<string, array{agreed:int, said_different:int, split_off:int, called:int}>
     */
    public function vendorRecords(): array
    {
        if ($this->records !== null) return $this->records;
        $rec = [];
        $add = function (?string $vendor, string $field) use (&$rec) {
            $k = HeadLessons::normaliseKey($vendor ?? '');
            if ($k === '') return;
            $rec[$k] = $rec[$k] ?? ['agreed' => 0, 'said_different' => 0, 'split_off' => 0, 'called' => 0];
            $rec[$k][$field]++;
            if ($field !== 'split_off') $rec[$k]['called']++;
        };
        $vendorOf = "COALESCE(v.name, e.vendor_name_raw)";
        try {
            $merged = $this->mergesReady();
            if ($merged) {
                foreach ($this->db->query("SELECT COALESCE(v.name, e.vendor_name_raw, kv.name, k.vendor_name_raw) AS vendor, m.unmerged_at, m.unmerge_kind
                                           FROM expense_merges m JOIN expenses e ON e.id = m.copy_id
                                           LEFT JOIN vendors v ON v.id = e.vendor_id
                                           LEFT JOIN expenses k ON k.id = m.kept_id LEFT JOIN vendors kv ON kv.id = k.vendor_id")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    if ($r['unmerged_at'] === null) $add($r['vendor'], 'agreed');
                    elseif ($r['unmerge_kind'] === 'split') $add($r['vendor'], 'split_off');
                }
            }
            foreach ($this->db->query("SELECT {$vendorOf} AS vendor FROM expenses e LEFT JOIN vendors v ON v.id = e.vendor_id
                                       WHERE e.status = 'rejected' AND e.rejection_reason LIKE 'Duplicate of receipt #%'"
                                       . ($merged ? " AND e.id NOT IN (SELECT copy_id FROM expense_merges)" : ''))->fetchAll(PDO::FETCH_COLUMN) as $v) {
                $add($v, 'agreed');
            }
            if ($this->dismissalsReady()) {
                foreach ($this->db->query("SELECT COALESCE(v.name, e.vendor_name_raw, bv.name, b.vendor_name_raw) AS vendor FROM expense_duplicate_dismissals d
                                           JOIN expenses e ON e.id = d.expense_a LEFT JOIN vendors v ON v.id = e.vendor_id
                                           LEFT JOIN expenses b ON b.id = d.expense_b LEFT JOIN vendors bv ON bv.id = b.vendor_id
                                           WHERE d.dismissed_by IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN) as $v) {
                    $add($v, 'said_different');
                }
            }
        } catch (Throwable $e) {
            error_log('Penny duplicate record: ' . $e->getMessage());
        }
        return $this->records = $rec;
    }

    /**
     * Groups from groups(), made ready for the card (phone and web, the same words): Penny's verdict, the
     * receipt she keeps and why, the copies, her record with this vendor, and the facts side by side.
     * Extra keys only — older app builds read members / pairs / why as before.
     */
    public function presentGroups(array $groups): array
    {
        if (!$groups) return [];
        $ids = [];
        foreach ($groups as $g) foreach ($g['members'] ?? [] as $m) $ids[] = (int)$m['id'];
        $ids = array_values(array_unique($ids));
        $facts = [];
        try {
            $rf = new ReceiptFactsService($this->db);
            if ($rf->ready()) $facts = $rf->forExpenses($ids);
        } catch (Throwable $e) {
            error_log('Duplicate facts: ' . $e->getMessage());
        }
        $linked = [];
        try {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $s = $this->db->prepare("SELECT matched_expense_id FROM accounting_transactions WHERE matched_expense_id IN ({$in})");
            $s->execute($ids);
            foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $id) $linked[(int)$id] = true;
            $s = $this->db->prepare("SELECT expense_id FROM bank_line_receipts WHERE expense_id IN ({$in})");
            $s->execute($ids);
            foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $id) $linked[(int)$id] = true;
        } catch (Throwable $e) {
            // no bank tables here — keep the older one
        }
        $records = $this->vendorRecords();
        $out = [];
        foreach ($groups as $g) $out[] = self::presentGroup($g, $facts, $linked, $records);
        return $out;
    }

    /** What the kept receipt took from the copy and still holds unchanged — the values to clear on a split. */
    public static function takeBack(array $keep, array $fill, ?int $photo): array
    {
        $back = [];
        foreach ($fill as $col => $val) {
            if (array_key_exists($col, $keep) && (string)($keep[$col] ?? '') === (string)$val) $back[$col] = null;
        }
        if ($photo && (int)($keep['receipt_media_id'] ?? 0) === $photo) $back['receipt_media_id'] = null;
        return $back;
    }

    /** @return int[] the line item ids on one receipt (none when there is no line-items table). */
    private function lineIds(int $expenseId): array
    {
        try {
            $s = $this->db->prepare("SELECT id FROM expense_line_items WHERE expense_id = ? ORDER BY id");
            $s->execute([$expenseId]);
            return array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable $e) {
            return [];
        }
    }

    public static function sameIds(array $a, array $b): bool
    {
        $a = array_map('intval', $a); $b = array_map('intval', $b);
        sort($a); sort($b);
        return $a !== [] && $a === $b;
    }

    /** Remove the agreement lesson a merge wrote for this copy (Undo and Split off: the owner did not agree after all). */
    private function retractAgreement(int $copyId): void
    {
        try {
            $this->db->prepare("DELETE FROM head_lessons WHERE head = 'penny' AND source = 'duplicate_merge' AND ref_type = 'expense' AND ref_id = ?")
               ->execute([$copyId]);
        } catch (Throwable $e) {
            // no head_lessons table (before migration 1348) — nothing was recorded
        }
    }

    private function evidenceFor(array $copy, array $keep): array
    {
        $facts = [];
        try {
            $rf = new ReceiptFactsService($this->db);
            if ($rf->ready()) $facts = $rf->forExpenses([(int)$copy['id'], (int)$keep['id']]);
        } catch (Throwable $e) { /* no facts — total and date only */ }
        return self::verdictFor([$keep, $copy], $facts);
    }

    private function lesson(int $copyId, string $said, string $correct, string $source, ?int $by, string $evidence, bool $agreement = false): void
    {
        if (!defined('APP_ROOT')) require_once dirname(__DIR__, 3) . '/Core/paths.php';
        require_once APP_ROOT . '/Modules/Accounting/Services/PennyLessons.php';
        PennyLessons::expense($this->db, $copyId, 'duplicate', $said, $correct, $source, $by, $evidence !== '' ? 'evidence: ' . $evidence : null, $agreement);
    }

    /**
     * Take back a person's "not a duplicate" (Penny's card: swipe back to an answered group, Undo — Tim 2026-11-06). The
     * pair is asked about again and the lesson that answer wrote is removed. Penny's own dismissals from printed facts
     * (dismissed_by NULL) are not touched; a pair split off after a merge is undone through unmerge(), not here.
     */
    public function undismiss(int $a, int $b, array $user): array
    {
        if (!$a || !$b || $a === $b) return ['ok' => false, 'message' => 'Pick two receipts'];
        if (!$this->dismissalsReady()) return ['ok' => false, 'message' => 'Needs migration 1127'];
        [$lo, $hi] = [min($a, $b), max($a, $b)];
        $del = $this->db->prepare("DELETE FROM expense_duplicate_dismissals WHERE expense_a = ? AND expense_b = ? AND dismissed_by IS NOT NULL");
        $del->execute([$lo, $hi]);
        if ($del->rowCount() < 1) return ['ok' => false, 'message' => "#{$lo} and #{$hi} weren't marked as two purchases by a person"];
        try {
            $this->db->prepare("DELETE FROM head_lessons WHERE head = 'penny' AND source = 'duplicate_dismiss' AND ref_type = 'expense' AND ref_id = ?")
               ->execute([$lo]);
        } catch (Throwable $e) {
            // no head_lessons table — nothing was recorded
        }
        return ['ok' => true, 'message' => "Undone — #{$lo} and #{$hi} are back to decide."];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Pure (unit tested)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Pairs → groups (receipts linked by any pair are one group: seven copies of one
     * receipt are one decision, not 21 pairs). Each group: members (oldest first) and
     * the pair keys inside it, for "not duplicates".
     * @return array<int, array{members: array, pairs: array}>
     */
    public static function groups(array $pairs): array
    {
        $parent = [];
        $find = function (int $x) use (&$parent, &$find): int {
            if (!isset($parent[$x])) $parent[$x] = $x;
            return $parent[$x] === $x ? $x : ($parent[$x] = $find($parent[$x]));
        };
        $rows = [];
        foreach ($pairs as $p) {
            $a = (int)$p['a']['id'];
            $b = (int)$p['b']['id'];
            $rows[$a] = ($rows[$a] ?? []) + $p['a'];
            $rows[$b] = ($rows[$b] ?? []) + $p['b'];
            $parent[$find($a)] = $find($b);
        }
        $groups = [];
        foreach ($pairs as $p) {
            $root = $find((int)$p['a']['id']);
            $groups[$root]['pairs'][] = [(int)$p['a']['id'], (int)$p['b']['id']];
            if (!empty($p['why'])) $groups[$root]['why'][$p['why']] = true;
        }
        foreach (array_keys($rows) as $id) {
            $groups[$find($id)]['members'][] = $rows[$id];
        }
        $out = [];
        foreach ($groups as $g) {
            usort($g['members'], fn($x, $y) => (int)$x['id'] <=> (int)$y['id']);
            $g['why'] = array_keys($g['why'] ?? []);   // e.g. "same ticket 43176009" — printed facts
            $out[] = $g;
        }
        return $out;
    }

    /** Columns a kept receipt takes from a removed copy when its own is empty. */
    public const MERGE_COLUMNS = ['job_id', 'property_id', 'contact_id', 'accounting_category', 'asset_tag',
                                  'payment_method', 'vendor_id', 'notes', 'description'];

    /**
     * What the kept receipt takes from a copy: each MERGE_COLUMNS value that is empty on
     * the kept one and set on the copy. Never overwrites. A column the kept row doesn't
     * have (migration not run) is skipped, and so is the offline queue's placeholder
     * description.
     * @return array<string, mixed> column => value
     */
    public static function mergeFill(array $keep, array $copy): array
    {
        $out = [];
        foreach (self::MERGE_COLUMNS as $col) {
            if (!array_key_exists($col, $keep)) continue;
            if (!self::isBlank($keep[$col]) || self::isBlank($copy[$col] ?? null)) continue;
            if ($col === 'description' && stripos((string)$copy[$col], 'Auto-saved from offline queue') === 0) continue;
            $out[$col] = $copy[$col];
        }
        return $out;
    }

    private static function isBlank($v): bool
    {
        return $v === null || $v === 0 || $v === '0' || (is_string($v) && trim($v) === '');
    }

    public static function fieldLabel(string $col): string
    {
        $labels = ['job_id' => 'job', 'property_id' => 'property', 'contact_id' => 'client',
                   'accounting_category' => 'category', 'asset_tag' => 'tag',
                   'payment_method' => 'payment method', 'vendor_id' => 'vendor'];
        return $labels[$col] ?? $col;
    }

    /** Are all these receipt ids members of one group from groups()? */
    public static function sameGroup(array $groups, array $ids): bool
    {
        $ids = array_map('intval', $ids);
        foreach ($groups as $g) {
            $members = array_map(fn($m) => (int)$m['id'], $g['members'] ?? []);
            if (!array_diff($ids, $members)) return true;
        }
        return false;
    }

    /**
     * findDuplicates() rows plus the receipts made from the same photo (no date/vendor
     * rule needed for those: one photo is one receipt), each id once.
     */
    public static function withSamePhoto(array $found, array $samePhoto): array
    {
        $seen = [];
        foreach ($found as $r) $seen[(int)$r['id']] = true;
        foreach ($samePhoto as $r) {
            if (!isset($seen[(int)$r['id']])) {
                $found[] = $r;
                $seen[(int)$r['id']] = true;
            }
        }
        return $found;
    }

    public static function key(int $a, int $b): string
    {
        return min($a, $b) . '-' . max($a, $b);
    }

    /**
     * @param array $mine       the waiting receipts checked
     * @param array $candidates expense id => findDuplicates() rows
     * @param array $dismissed  key() => true
     */
    public static function pairUp(array $mine, array $candidates, array $dismissed = []): array
    {
        $out = [];
        foreach ($mine as $e) {
            foreach ($candidates[(int)$e['id']] ?? [] as $c) {
                if (($c['status'] ?? '') === 'rejected') continue;
                $k = self::key((int)$e['id'], (int)$c['id']);
                if (isset($dismissed[$k]) || isset($out[$k])) continue;
                $bothWait = in_array($c['status'] ?? '', self::WAITING, true);
                [$a, $b] = ($bothWait && (int)$c['id'] < (int)$e['id']) ? [$c, $e] : [$e, $c];
                $out[$k] = ['a' => $a, 'b' => $b];
            }
        }
        return array_values($out);
    }

    public const EVIDENCE_ORDER = ['number', 'photo', 'time', 'total_date'];

    /**
     * Penny's verdict on a group, from printed facts (receipt_facts) and the photo. Pure.
     *   number     — a printed invoice / auth / ticket number is on both (ReceiptFactsService::duplicateVerdict 'same');
     *   photo      — the very same photo was sent in twice;
     *   time       — same printed time, no number to prove it;
     *   total_date — only the total and date match (how pairs are found): "nothing printed proves it either way".
     * @param array $members group members (id, receipt_media_id) @param array $facts id => receipt_facts row
     * @return array{kind:string, text:string}
     */
    public static function verdictFor(array $members, array $facts): array
    {
        $n = count($members);
        $ids = array_map(fn($m) => (int)$m['id'], $members);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $v = ReceiptFactsService::duplicateVerdict($facts[$ids[$i]] ?? null, $facts[$ids[$j]] ?? null);
                if ($v['verdict'] === 'same') {
                    $what = preg_replace('/^same\s+/i', '', $v['why']);
                    return ['kind' => 'number', 'text' => 'Same receipt: ' . $what . ($n > 2 ? " is on all {$n}." : ' is on both.')];
                }
            }
        }
        $media = array_map(fn($m) => (int)($m['receipt_media_id'] ?? 0), $members);
        if ($media && min($media) > 0 && count(array_unique($media)) === 1) {
            return ['kind' => 'photo', 'text' => 'Same photo sent in ' . ($n > 2 ? "{$n} times." : 'twice.')];
        }
        $times = array_map(fn($id) => $facts[$id]['time_first'] ?? null, $ids);
        if (!in_array(null, $times, true) && !in_array('', $times, true) && count(array_unique($times)) === 1) {
            $dates = array_map(fn($id, $m) => (string)($facts[$id]['printed_date'] ?? $m['expense_date'] ?? ''), $ids, $members);
            $sameDay = !in_array('', $dates, true) && count(array_unique($dates)) === 1;
            return ['kind' => 'time', 'text' => 'Same total' . ($sameDay ? ', date' : '') . ' and printed time (' . $times[0] . '). No number to prove it.'];
        }
        // Pairs are found within ±3 days, so "same date" is said only when every date really matches.
        $dates = array_map(fn($m) => (string)($facts[(int)$m['id']]['printed_date'] ?? $m['expense_date'] ?? ''), $members);
        $sameDay = !in_array('', $dates, true) && count(array_unique($dates)) === 1;
        return ['kind' => 'total_date', 'text' => $sameDay ? 'Same total and date. Nothing printed proves it either way.'
                                                             : 'Same total, dates a few days apart. Nothing printed proves it either way.'];
    }

    /**
     * Which receipt stays. Pure. One already in the books always stays (it can't be merged away); else the
     * one matched to a bank line; else the one sent in first. (Plan decision 2, the default until Tim says otherwise.)
     * @return array{id:int, reason:string}
     */
    public static function keeperFor(array $members, array $bankLinked): array
    {
        $sorted = $members;
        usort($sorted, fn($x, $y) => (int)$x['id'] <=> (int)$y['id']);
        foreach ($sorted as $m) {
            if (!in_array($m['status'] ?? '', self::WAITING, true)) return ['id' => (int)$m['id'], 'reason' => 'already in the books'];
        }
        foreach ($sorted as $m) {
            if (!empty($bankLinked[(int)$m['id']])) return ['id' => (int)$m['id'], 'reason' => 'matched to a bank line'];
        }
        return ['id' => (int)$sorted[0]['id'], 'reason' => 'sent in first'];
    }

    /** Penny's line on how sure she is with this vendor; '' until she has called a pair with it. Pure. */
    public static function confidenceLine(string $vendor, ?array $record): string
    {
        $vendor = trim($vendor) !== '' ? trim($vendor) : 'this vendor';
        $called = (int)($record['called'] ?? 0);
        // No record yet: say nothing (Tim 2026-11-06: "First Southlands receipt I've seen eats space unnecessarily").
        if ($called === 0) return '';
        $agreed = (int)($record['agreed'] ?? 0);
        return "With {$vendor} I've been right {$agreed} of {$called} time" . ($called === 1 ? '' : 's') . '.';
    }

    /**
     * The facts side by side, one row each. Pure. state: same (every value there and equal — a tick),
     * differs (two values that disagree — highlighted), missing (some not read — "–"), info (not compared).
     * A row nobody has a value for is left out.
     * @return list<array{label:string, values:list<?string>, state:string}>
     */
    public static function factRows(array $members, array $facts): array
    {
        $rows = [];
        $col = function (string $label, callable $value, ?callable $norm = null, bool $compare = true) use ($members, &$rows) {
            $vals = [];
            foreach ($members as $m) {
                $v = $value($m);
                $vals[] = ($v === null || trim((string)$v) === '') ? null : (string)$v;
            }
            if (count(array_filter($vals, fn($v) => $v !== null)) === 0) return;
            $state = 'info';
            if ($compare) {
                $present = array_values(array_filter($vals, fn($v) => $v !== null));
                $keys = array_unique(array_map($norm ?? fn($v) => strtolower(trim($v)), $present));
                $state = count($keys) > 1 ? 'differs' : (count($present) === count($vals) ? 'same' : 'missing');
            }
            $rows[] = ['label' => $label, 'values' => $vals, 'state' => $state];
        };
        $f = fn($m) => $facts[(int)$m['id']] ?? [];
        $col('Vendor', fn($m) => $m['vendor_name'] ?? $m['vendor_name_raw'] ?? null);
        $col('Date', function ($m) use ($f) {
            $d = $f($m)['printed_date'] ?? null ?: ($m['expense_date'] ?? null);
            return $d ? date('M j, Y', strtotime((string)$d)) : null;
        });
        $col('Time', function ($m) use ($f) {
            $x = $f($m);
            return !empty($x['time_first']) ? $x['time_first'] . (!empty($x['time_last']) ? '–' . $x['time_last'] : '') : null;
        });
        $col('Total', fn($m) => isset($m['total']) && $m['total'] !== '' ? '$' . number_format((float)$m['total'], 2) : null);
        $col('Number', function ($m) use ($f) {
            $x = $f($m);
            if (empty($x['doc_number'])) return null;
            $kind = strtolower((string)($x['doc_kind'] ?? ''));
            return ($kind !== '' ? ucfirst($kind) . ' ' : '') . $x['doc_number'];
        }, fn($v) => strtoupper(preg_replace('/[^A-Za-z0-9]/', '', preg_replace('/^\S+\s+/', '', $v))));
        $col('Card', fn($m) => !empty($f($m)['card_last4']) ? '••' . $f($m)['card_last4'] : null);
        $col('Status', fn($m) => in_array($m['status'] ?? '', self::WAITING, true) ? 'waiting' : 'in the books', null, false);
        $col('Sent in by', fn($m) => $m['submitted_by'] ?? null, null, false);
        return $rows;
    }

    /** One group for the card. Pure (presentGroups() loads what it needs). */
    public static function presentGroup(array $g, array $facts, array $bankLinked, array $records): array
    {
        $members = $g['members'] ?? [];
        usort($members, fn($x, $y) => (int)$x['id'] <=> (int)$y['id']);
        $g['members'] = $members;
        if (count($members) < 2) return $g;
        $verdict = self::verdictFor($members, $facts);
        $keep = self::keeperFor($members, $bankLinked);
        $copies = [];
        foreach ($members as $m) {
            if ((int)$m['id'] !== $keep['id'] && in_array($m['status'] ?? '', self::WAITING, true)) $copies[] = (int)$m['id'];
        }
        $vendor = (string)($members[0]['vendor_name'] ?? $members[0]['vendor_name_raw'] ?? '');
        // "Keep the other instead": only when the kept one still waits (one in the books always stays);
        // any other waiting receipt can take its place.
        $alt = $keep['reason'] === 'already in the books' ? [] : $copies;
        $g['verdict'] = $verdict['text'];
        $g['evidence'] = $verdict['kind'];
        $g['keep_id'] = $keep['id'];
        $g['keep_reason'] = $keep['reason'];
        $g['keep_line'] = 'Keeping #' . $keep['id'] . ' (' . $keep['reason'] . ')';
        $g['copy_ids'] = $copies;
        $g['merge_label'] = (count($copies) > 1 ? 'all into #' : 'into #') . $keep['id'];
        $g['can_swap'] = $alt;
        $rec = $records[HeadLessons::normaliseKey($vendor)] ?? null;
        $g['confidence'] = self::confidenceLine($vendor, $rec);
        // Her record with this vendor as numbers, for the strip's triangle (forming → held → earned)
        $g['record'] = ['agreed' => (int)($rec['agreed'] ?? 0), 'called' => (int)($rec['called'] ?? 0)];
        $g['facts'] = self::factRows($members, $facts);
        $g['member_ids'] = array_map(fn($m) => (int)$m['id'], $members);
        return $g;
    }
}
