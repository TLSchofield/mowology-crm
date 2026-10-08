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

class DuplicateReceiptService
{
    public const WAITING = ['draft', 'pending_approval'];

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
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
     * Remove a copy: REJECT it as "Duplicate of receipt #X" — kept for the six-year
     * record, never deleted. Its photo moves to the one kept when that has none (a
     * waiting receipt only — an approved one is locked). Only a waiting receipt can be
     * removed this way.
     */
    public function removeCopy(int $copyId, int $keepId, array $user): array
    {
        if (!$copyId || !$keepId || $copyId === $keepId) return ['ok' => false, 'message' => 'Pick the copy and the one to keep'];
        $s = $this->db->prepare("SELECT id, status, receipt_media_id FROM expenses WHERE id IN (?, ?)");
        $s->execute([$copyId, $keepId]);
        $rows = array_column($s->fetchAll(PDO::FETCH_ASSOC), null, 'id');
        $copy = $rows[$copyId] ?? null;
        $keep = $rows[$keepId] ?? null;
        if (!$copy || !$keep) return ['ok' => false, 'message' => 'Receipt not found'];
        if (!in_array($copy['status'], self::WAITING, true)) return ['ok' => false, 'message' => "That one's already approved — remove the other instead"];
        if (empty($keep['receipt_media_id']) && !empty($copy['receipt_media_id']) && in_array($keep['status'], self::WAITING, true)) {
            $this->db->prepare("UPDATE expenses SET receipt_media_id = ? WHERE id = ?")->execute([(int)$copy['receipt_media_id'], $keepId]);
        }
        (new ExpenseApprovalService($this->db))->reject($copyId, $user, 'Duplicate of receipt #' . $keepId);
        $this->db->prepare("UPDATE expense_suggestions SET status = 'superseded' WHERE expense_id = ? AND source = 'live' AND status IN ('pending', 'error')")
           ->execute([$copyId]);
        return ['ok' => true, 'message' => "Done — #{$copyId} is set aside as a duplicate of #{$keepId} (kept on record, not deleted)."];
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
        $own = !$this->db->inTransaction();
        if ($own) $this->db->beginTransaction();
        try {
            foreach ($removeIds as $copyId) {
                if ($keepWaits) {
                    $fill = self::mergeFill($rows[$keepId], $rows[$copyId]);
                    if ($fill) {
                        $sets = implode(', ', array_map(fn($c) => "{$c} = ?", array_keys($fill)));
                        $this->db->prepare("UPDATE expenses SET {$sets} WHERE id = ?")
                           ->execute(array_merge(array_values($fill), [$keepId]));
                        $rows[$keepId] = $fill + $rows[$keepId];
                        $carried = array_merge($carried, array_keys($fill));
                    }
                    if ($this->moveLineItemsIfMissing($copyId, $keepId)) $carried[] = 'line items';
                    if (empty($rows[$keepId]['receipt_media_id']) && !empty($rows[$copyId]['receipt_media_id'])) {
                        $rows[$keepId]['receipt_media_id'] = $rows[$copyId]['receipt_media_id'];   // removeCopy moves the photo
                        $carried[] = 'photo';
                    }
                }
                $res = $this->removeCopy($copyId, $keepId, $user);
                if (empty($res['ok'])) throw new RuntimeException($res['message'] ?? "Couldn't set #{$copyId} aside");
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
    private function moveLineItemsIfMissing(int $copyId, int $keepId): bool
    {
        try {
            $c = $this->db->prepare("SELECT COUNT(*) FROM expense_line_items WHERE expense_id = ?");
            $c->execute([$keepId]);
            if ((int)$c->fetchColumn() > 0) return false;
            $u = $this->db->prepare("UPDATE expense_line_items SET expense_id = ? WHERE expense_id = ?");
            $u->execute([$keepId, $copyId]);
            return $u->rowCount() > 0;
        } catch (PDOException $e) {
            return false;   // no line-items table here — nothing to carry
        }
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
        return ['ok' => true, 'message' => 'Got it — not a duplicate. Both go on for approval.'];
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
}
