<?php
/**
 * AuditTrail — the activity_log is never deleted from (Fable security audit #25, 2026-10-12).
 *
 * Deleting a quote or a company used to DELETE its activity_log rows first, so the history of
 * what happened to it vanished with it. Now the link is cut instead (the row stays, its
 * company_id / quote_id becomes NULL — the pattern api-quotes.php already used) and one
 * "… deleted" row is written that names the old id, so the orphaned rows can still be traced.
 *
 * No namespace / no autoloader in production: require_once it.
 */
class AuditTrail
{
    /**
     * Cut activity_log rows loose from companies about to be deleted. Call it inside the same
     * transaction as the DELETE, before it.
     *
     * @param int[] $ids
     * @return array<int, string> id => company name, for companyDeletedLines() after the delete
     */
    public static function detachCompanies(PDO $db, array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids), fn($i) => $i > 0));
        if (!$ids) return [];
        $in = implode(',', array_fill(0, count($ids), '?'));
        $names = [];
        $s = $db->prepare("SELECT id, company_name FROM companies WHERE id IN ({$in})");
        $s->execute($ids);
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $names[(int)$r['id']] = (string)$r['company_name'];
        $db->prepare("UPDATE activity_log SET company_id = NULL WHERE company_id IN ({$in})")->execute($ids);
        return $names;
    }

    /** Cut activity_log rows loose from a quote about to be deleted (the FK would SET NULL too). */
    public static function detachQuote(PDO $db, int $quoteId): void
    {
        $db->prepare("UPDATE activity_log SET quote_id = NULL WHERE quote_id = ?")->execute([$quoteId]);
    }

    /**
     * Pure: one 'Company deleted' line per company actually deleted.
     * @param array<int, string> $names  from detachCompanies()
     * @param int[]|null $deleted        ids really deleted (null = all of $names)
     * @return string[]
     */
    public static function companyDeletedLines(array $names, ?array $deleted = null): array
    {
        $out = [];
        foreach ($names as $id => $name) {
            if ($deleted !== null && !in_array((int)$id, array_map('intval', $deleted), true)) continue;
            $out[] = 'Deleted company #' . (int)$id . ' ' . ($name !== '' ? '"' . $name . '"' : '(no name)')
                   . ' — its earlier activity rows are kept with company_id cleared.';
        }
        return $out;
    }

    /** Write the 'Company deleted' rows (after commit). */
    public static function logCompaniesDeleted(?int $userId, array $names, ?array $deleted = null): void
    {
        if (!function_exists('logActivityExtended')) return;
        foreach (self::companyDeletedLines($names, $deleted) as $line) {
            logActivityExtended($userId, 'Company deleted', $line);
        }
    }
}
