<?php
declare(strict_types=1);

/**
 * ReceiptDocumentLinkService — a receipt kept as the DOCUMENT for an entry or bank line it does not itself book (migration 1421).
 *
 * A receipt printed in a filed year (2025) and captured again in 2026 is a second copy of an expense that is already in the filed
 * books through its bank line. It is rejected so it posts nothing, and its photo stays. This links it to that bank line and entry
 * so an auditor — or the accountant in the Ledger — can go from the entry to the paper.
 *
 * It is deliberately NOT accounting_transactions.matched_expense_id: the nightly ledger sync retires the own entry of any bank line
 * that carries that link (LedgerSyncService → retireLinkedBankEntries), which would remove the filed expense. Nothing reads THIS
 * table except the Ledger entry page and the lookups below. The note is shown on the Ledger entry page, which the outside
 * accountant can read: write it for an auditor.
 *
 * No namespace / no autoloader: require_once and `new`.
 */
class ReceiptDocumentLinkService
{
    public const RELATIONS = ['supports' => 'Supports this entry', 'copy_of' => 'Another copy of the receipt for this entry'];

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function ready(): bool
    {
        try {
            $this->db->query("SELECT 1 FROM receipt_document_links LIMIT 1");
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** The ONE live entry a bank line posted, or null — also null when there are several (don't guess which is authoritative). */
    public function liveEntryForBankLine(int $transactionId): ?int
    {
        $s = $this->db->prepare("SELECT id FROM journal_entries WHERE source_type IN ('bank_import', 'bank_deposit') AND source_id = ?
                                 AND status = 'posted' AND reversed_by_entry_id IS NULL ORDER BY id LIMIT 2");
        $s->execute([$transactionId]);
        $ids = $s->fetchAll(PDO::FETCH_COLUMN);
        return count($ids) === 1 ? (int)$ids[0] : null;
    }

    /** Is this entry one the bank line posted (so a supplied pair really belongs together)? */
    private function entryBelongsToBankLine(int $entryId, int $transactionId): bool
    {
        $s = $this->db->prepare("SELECT 1 FROM journal_entries WHERE id = ? AND source_type IN ('bank_import', 'bank_deposit') AND source_id = ?");
        $s->execute([$entryId, $transactionId]);
        return (bool)$s->fetchColumn();
    }

    /**
     * Link a receipt to the bank line (and its entry) it documents. Idempotent per (receipt, bank line).
     * @return array{ok: bool, message: string, id?: int}
     */
    public function link(int $expenseId, ?int $transactionId, ?int $entryId, string $relation, string $note, ?int $userId): array
    {
        if (!$this->ready()) return ['ok' => false, 'message' => 'Needs migration 1421'];
        if ($expenseId <= 0 || (!$transactionId && !$entryId)) return ['ok' => false, 'message' => 'Say which receipt and which bank line or entry'];
        if (!isset(self::RELATIONS[$relation])) return ['ok' => false, 'message' => 'Unknown relation'];
        if ($transactionId && $entryId && !$this->entryBelongsToBankLine($entryId, $transactionId)) {
            return ['ok' => false, 'message' => 'That entry was not posted by that bank line'];
        }
        if ($transactionId && !$entryId) $entryId = $this->liveEntryForBankLine($transactionId);
        $f = $this->db->prepare("SELECT id FROM receipt_document_links WHERE expense_id = ? AND " . ($transactionId ? "bank_transaction_id = ?" : "journal_entry_id = ?"));
        $f->execute([$expenseId, $transactionId ?: $entryId]);
        if ($ex = $f->fetchColumn()) return ['ok' => true, 'message' => 'Already linked', 'id' => (int)$ex];
        try {
            $this->db->prepare("INSERT INTO receipt_document_links (expense_id, bank_transaction_id, journal_entry_id, relation, note, created_by) VALUES (?, ?, ?, ?, ?, ?)")
                     ->execute([$expenseId, $transactionId ?: null, $entryId ?: null, $relation, mb_substr(trim($note), 0, 255) ?: null, $userId]);
        } catch (PDOException $e) {   // two links at once: the unique key stops the second — it is already linked
            $f->execute([$expenseId, $transactionId ?: $entryId]);
            if ($ex = $f->fetchColumn()) return ['ok' => true, 'message' => 'Already linked', 'id' => (int)$ex];
            throw $e;
        }
        return ['ok' => true, 'message' => 'Linked', 'id' => (int)$this->db->lastInsertId()];
    }

    /**
     * Documents for a journal entry: those linked to the entry itself, or to the bank line it posted.
     * @return list<array{id:int, expense_id:int, relation:string, relation_label:string, note:?string, vendor:string, expense_date:string, total:float, status:string, media_id:?int}>
     */
    public function forEntry(int $entryId, ?int $bankTransactionId = null): array
    {
        if (!$this->ready()) return [];
        $sql = "SELECT l.id, l.expense_id, l.relation, l.note, COALESCE(v.name, e.vendor_name_raw, '') AS vendor, e.expense_date, e.total, e.status, e.receipt_media_id AS media_id
                FROM receipt_document_links l JOIN expenses e ON e.id = l.expense_id LEFT JOIN vendors v ON v.id = e.vendor_id
                WHERE l.journal_entry_id = ?" . ($bankTransactionId ? " OR l.bank_transaction_id = ?" : "") . " ORDER BY l.id";
        $s = $this->db->prepare($sql);
        $s->execute($bankTransactionId ? [$entryId, $bankTransactionId] : [$entryId]);
        $out = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = ['id' => (int)$r['id'], 'expense_id' => (int)$r['expense_id'], 'relation' => (string)$r['relation'],
                      'relation_label' => self::RELATIONS[$r['relation']] ?? (string)$r['relation'], 'note' => $r['note'],
                      'vendor' => (string)$r['vendor'], 'expense_date' => substr((string)$r['expense_date'], 0, 10), 'total' => (float)$r['total'],
                      'status' => (string)$r['status'], 'media_id' => $r['media_id'] !== null ? (int)$r['media_id'] : null];
        }
        return $out;
    }

    /** Where a receipt is kept as a document (for the receipt's own page). @return list<array> */
    public function forExpense(int $expenseId): array
    {
        if (!$this->ready()) return [];
        $s = $this->db->prepare("SELECT id, bank_transaction_id, journal_entry_id, relation, note, created_at FROM receipt_document_links WHERE expense_id = ? ORDER BY id");
        $s->execute([$expenseId]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }
}
