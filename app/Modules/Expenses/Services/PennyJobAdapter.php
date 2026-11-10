<?php
declare(strict_types=1);

/**
 * Penny's duplicate-receipt job as the generic "job strip" the Ittai desktop shell prints at the top of a
 * head's pages (public/crm/includes/ittai-job.php). A new face on existing money logic only: the groups come
 * from DuplicateReceiptService::presentGroups() (the same ones the web Penny card shows) and the buttons carry
 * the exact requests bookkeeper-card.js sends to /crm/api/bookkeeper.php (dupe_merge, not_dupe, unmerge).
 * Nothing here writes anything.
 */
class PennyJobAdapter
{
    /** Cheap gate: does Penny have an open possible-twin question? Any failure means no. */
    /**
     * Cheap gate before the real check: two waiting receipts with the same total within three days (the coarse
     * rule ExpenseLookupService::findDuplicates starts from). Penny's "possible twin" questions are NOT the
     * source — they cover approved receipts only, so a waiting pair never showed (seen 2026-11-10).
     */
    public static function hasWork(PDO $db): bool
    {
        try {
            self::load();
            $in = "'" . implode("','", DuplicateReceiptService::WAITING) . "'";
            // Bounded like pairsInLine(): the newest 200 waiting receipts, so a big backlog cannot make every
            // Penny page pay for an unindexed self-join (review, 2026-11-10).
            $s = $db->query("SELECT 1 FROM (SELECT id, total, expense_date FROM expenses WHERE status IN ($in) ORDER BY id DESC LIMIT 200) e1
                             JOIN (SELECT id, total, expense_date FROM expenses WHERE status IN ($in) ORDER BY id DESC LIMIT 200) e2
                               ON e2.id > e1.id AND ABS(e2.total - e1.total) < 0.01
                              AND ABS(DATEDIFF(e2.expense_date, e1.expense_date)) <= 3
                             LIMIT 1");
            return (bool)$s->fetchColumn();
        } catch (Throwable $e) {
            error_log('PennyJobAdapter::hasWork: ' . $e->getMessage());
        }
        return false;
    }

    /** The waiting pairs as jobs, in the card's order; [] on any failure. */
    public static function jobs(PDO $db, array $user): array
    {
        try {
            self::load();
            $svc = new DuplicateReceiptService($db);
            $groups = $svc->presentGroups(DuplicateReceiptService::groups($svc->pairsInLine(60)));
            $groups = array_values(array_filter($groups, fn($g) => !empty($g['keep_id']) && !empty($g['copy_ids'])));
            $out = [];
            foreach ($groups as $i => $g) $out[] = self::fromGroup($g, $i + 1, count($groups));
            return $out;
        } catch (Throwable $e) {
            error_log('PennyJobAdapter::jobs: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * One presentGroup() group → the generic job shape. Pure. The first copy is the one compared; a group with
     * more copies still merges them all (copy_ids), as the web card does.
     * undo.requests: one unmerge per copy, sent in turn (bookkeeper-card.js undoDupe()).
     */
    public static function fromGroup(array $group, int $index, int $total): array
    {
        $members = array_values((array)($group['members'] ?? []));
        $keepId = (int)($group['keep_id'] ?? 0);
        $copyIds = array_values(array_map('intval', (array)($group['copy_ids'] ?? [])));
        $copyId = $copyIds[0] ?? 0;

        $keepAt = $copyAt = null;
        foreach ($members as $i => $m) {
            if ((int)($m['id'] ?? 0) === $keepId) $keepAt = $i;
            if ((int)($m['id'] ?? 0) === $copyId) $copyAt = $i;
        }
        $side = fn(?int $at, int $id): array => self::side($at !== null ? (array)$members[$at] : [], $id);

        $columns = [];
        foreach ((array)($group['facts'] ?? []) as $row) {
            $label = (string)($row['label'] ?? '');
            if ($label === '' || in_array($label, ['Status', 'Sent in by'], true)) continue;   // shown under the photos
            $vals = (array)($row['values'] ?? []);
            $state = (string)($row['state'] ?? 'info');
            $columns[] = [
                'label'  => $label,
                'top'    => self::text($keepAt !== null ? ($vals[$keepAt] ?? null) : null),
                'bottom' => self::text($copyAt !== null ? ($vals[$copyAt] ?? null) : null),
                'state'  => in_array($state, ['same', 'differs', 'missing', 'info'], true) ? $state : 'info',
            ];
        }

        $mergeLabel = (string)($group['merge_label'] ?? '');
        $pairs = [];
        foreach ((array)($group['pairs'] ?? []) as $p) $pairs[] = [(int)($p[0] ?? 0), (int)($p[1] ?? 0)];
        $undo = [];
        foreach ($copyIds as $id) $undo[] = ['mode' => 'unmerge', 'copy_id' => $id, 'kind' => 'undo'];

        return [
            'head'       => 'penny',
            'kind'       => 'duplicate',
            'key'        => 'dup:' . $keepId . ':' . implode('-', $copyIds),
            'title'      => 'Duplicate receipt? Penny thinks so',
            'verdict'    => (string)($group['verdict'] ?? ''),
            // Penny's confidence sentence, or her verdict when she has no track record with this vendor yet
            'confidence' => trim((string)($group['confidence'] ?? '')) !== '' ? trim((string)$group['confidence']) : trim((string)($group['verdict'] ?? '')),
            'keep'       => $side($keepAt, $keepId),
            'copy'       => $side($copyAt, $copyId),
            'columns'    => $columns,
            // The strip shows the first copy; when the merge would set aside more, the label says how many (review, 2026-11-10).
            'primary'    => ['label' => 'Merge', 'sub' => ($mergeLabel !== '' ? $mergeLabel : 'into #' . $keepId) . (count($copyIds) > 1 ? ' · ' . count($copyIds) . ' copies' : ''),
                             'request' => ['mode' => 'dupe_merge', 'keep_id' => $keepId, 'copy_ids' => $copyIds]],
            'secondary'  => ['label' => 'Different', 'sub' => count($members) > 2 ? 'keep them all' : 'keep both',
                             'request' => ['mode' => 'not_dupe', 'pairs' => $pairs]],
            'undo'       => ['requests' => $undo],
            'anchor'     => ['label' => 'Ledger', 'href' => '/crm/ledger/'],
            'index'      => $index,
            'total'      => $total,
        ];
    }

    /** One receipt of the pair for the strip: id, "#278 · in the books", who sent it, photo. */
    private static function side(array $m, int $id): array
    {
        $waiting = in_array((string)($m['status'] ?? ''), ['draft', 'pending_approval'], true);
        $path = (string)($m['receipt_path'] ?? '');
        if ($path === '' && !empty($m['receipt_media_id'])) $path = '/crm/api/serve-receipt.php?id=' . (int)$m['receipt_media_id'];
        return [
            'id'    => $id,
            'label' => '#' . $id . ' · ' . ($waiting ? 'waiting' : 'in the books'),
            'who'   => self::shortName((string)($m['submitted_by'] ?? '')),
            'image' => $path !== '' ? $path : null,
        ];
    }

    /** "Nigel Carter" → "Nigel C."; one word stays as it is; nobody → ''. */
    private static function shortName(string $full): string
    {
        $parts = preg_split('/\s+/', trim($full)) ?: [];
        $parts = array_values(array_filter($parts, fn($p) => $p !== ''));
        if (!$parts) return '';
        if (count($parts) === 1) return $parts[0];
        $last = (string)end($parts);
        return $parts[0] . ' ' . mb_substr($last, 0, 1) . '.';
    }

    private static function text($v): string
    {
        $s = trim((string)($v ?? ''));
        return $s !== '' ? $s : '–';
    }

    private static function load(): void
    {
        if (!defined('APP_ROOT')) return;   // tests load the services through bootstrap.php
        foreach (['PennyQuestionService', 'DuplicateReceiptService'] as $c) {
            if (!class_exists($c, false)) require_once APP_ROOT . '/Modules/Expenses/Services/' . $c . '.php';
        }
    }
}
