<?php
/**
 * ExpenseGateHooks — what ExpenseGate does after a change: printed facts, the duplicate
 * check, Penny's learning and the books. One method per signal, so the gate calls each at
 * most once per change and a test can count the calls (subclass and override).
 *
 * Every hook is best-effort: a learning or facts failure is logged, never thrown — the
 * change itself is already saved. The books hooks (repost / unpost) are append-only:
 * the posted entry is reversed (LedgerService::reverseEntry) and the receipt posted again
 * through the same recipe the nightly sync uses (LedgerSyncService::expenseArgs).
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
class ExpenseGateHooks
{
    protected PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** Re-read what is printed on the receipt (receipt_facts, migration 1227). */
    public function facts(int $expenseId): void
    {
        try {
            require_once __DIR__ . '/ReceiptFactsService.php';
            ReceiptFactsService::refreshQuietly($this->db, $expenseId);
        } catch (Throwable $e) {
            error_log('Gate facts #' . $expenseId . ': ' . $e->getMessage());
        }
    }

    /**
     * Penny's missing-receipt chaser (migration 1245): a receipt that fits an open "missing
     * receipt" item closes it, whatever path it came in by.
     */
    public function chase(int $expenseId): void
    {
        try {
            require_once __DIR__ . '/MissingReceiptService.php';
            MissingReceiptService::onReceiptQuietly($this->db, $expenseId);
        } catch (Throwable $e) {
            error_log('Gate chase #' . $expenseId . ': ' . $e->getMessage());
        }
    }

    /** The duplicate check, run again on the receipt as it is now (no writes). */
    public function duplicates(int $expenseId): array
    {
        try {
            require_once __DIR__ . '/DuplicateReceiptService.php';
            return (new DuplicateReceiptService($this->db))->pairsFor([$expenseId]);
        } catch (Throwable $e) {
            return [];
        }
    }

    /** Keep what the user was shown at capture — header lessons are judged against it at approval. */
    public function storeBaseline(int $expenseId, $ocrParsed): void
    {
        try {
            $this->receiptLearning();
            storeCaptureBaseline($this->db, $expenseId, $ocrParsed);
        } catch (Throwable $e) {
            error_log('Gate baseline #' . $expenseId . ': ' . $e->getMessage());
        }
    }

    /** The save's line-item corrections (identity-keyed; safe per save). */
    public function learnLines(int $expenseId, array $row, array $payload): void
    {
        try {
            $this->receiptLearning();
            recordLineItemLessons($this->db, !empty($row['vendor_id']) ? (int)$row['vendor_id'] : null,
                                  $payload['vendor_name_raw'] ?? ($row['vendor_name_raw'] ?? null), $payload, $expenseId);
        } catch (Throwable $e) {
            error_log('Gate line lessons #' . $expenseId . ': ' . $e->getMessage());
        }
    }

    /**
     * One line renamed / added / removed: what it teaches the receipt reader (moved here from
     * ExpenseLineItemService). Receipts with a capture baseline learn their lines once, at
     * confirmation, so only the SKU / catalogue memory is updated for them here.
     */
    public function learnLineOp(string $op, ?array $before, ?array $after, array $expense): void
    {
        try {
            $this->receiptLearning();
            $expenseId = (int)($expense['id'] ?? 0);
            $vendorId = !empty($expense['vendor_id']) ? (int)$expense['vendor_id'] : null;
            $vendorName = $expense['vendor_name_raw'] ?? null;
            $baseline = $expenseId && expenseHasCaptureBaseline($this->db, $expenseId);
            if ($op === 'update' && $before && $after) {
                $lesson = ExpenseLineItemService::lessonForRename($before['ocr_name'] ?? null, (string)$before['name'], (string)$after['name']);
                if ($lesson && $vendorId && !$baseline) {
                    recordLineItemLesson($this->db, $vendorId, $vendorName, $lesson['type'], $lesson['ocr_value'], $lesson['corrected_value']);
                    updateLineItemProfileStats($this->db, $vendorId, 0, 1);
                }
                if (empty($before['product_id'])) proposeProductsForExpense($this->db, $expenseId);
            } elseif ($op === 'add' && $after) {
                if ($vendorId) {
                    if (!$baseline) {
                        recordLineItemLesson($this->db, $vendorId, $vendorName, 'line_item_missed', null, (string)$after['name']);
                        updateLineItemProfileStats($this->db, $vendorId, 0, 1);
                    }
                    if (!empty($after['product_id'])) {
                        try { teachVendorProduct($this->db, $vendorId, (string)$after['name'], (int)$after['product_id']); } catch (Throwable $e) {}
                    }
                    if (!empty($after['sku_raw'])) {
                        recordSkuLink($this->db, $vendorId, (string)$after['sku_raw'], !empty($after['product_id']) ? (int)$after['product_id'] : null, null, (string)$after['name']);
                    }
                }
                if (empty($after['product_id'])) proposeProductsForExpense($this->db, $expenseId);
            } elseif ($op === 'delete' && $before) {
                // Only OCR-derived rows teach "noise" — a hand-added row removed says nothing about the parser.
                $ocrName = trim((string)($before['ocr_name'] ?? ''));
                if ($ocrName === '' && empty($before['product_id'])) $ocrName = trim((string)$before['name']);
                if ($ocrName !== '' && $vendorId && !$baseline) {
                    recordLineItemLesson($this->db, $vendorId, $vendorName, 'line_item_noise', $ocrName, null);
                    updateLineItemProfileStats($this->db, $vendorId, 0, 1);
                }
            }
        } catch (Throwable $e) {
            error_log('Gate line-op lesson: ' . $e->getMessage());
        }
    }

    /** Line prices for the price-trend memory. */
    public function priceIntel(int $expenseId, int $vendorId, array $lines, string $date): void
    {
        try {
            require_once APP_ROOT . '/Services/Receipts/PriceIntelligence.php';
            recordLineItemPrices($expenseId, $vendorId, $lines, $date);
        } catch (Throwable $e) {
            error_log('Gate price intelligence #' . $expenseId . ': ' . $e->getMessage());
        }
    }

    /** Approved or sent: the header (and line) lessons, once per receipt. */
    public function learnConfirmed(int $expenseId, ?array $fallbackBaseline): void
    {
        try {
            $this->receiptLearning();
            learnFromConfirmedExpense($this->db, $expenseId, $fallbackBaseline);
            // Items taken from Penny's read over the reader's: the reader learns the layout (2026-10-13).
            if (function_exists('teachReaderFromPenny')) teachReaderFromPenny($this->db, $expenseId);
        } catch (Throwable $e) {
            error_log('Gate confirm lessons #' . $expenseId . ': ' . $e->getMessage());
        }
        // The vendor's phone / website / GST number and its store, from what the receipt prints (2026-10-15).
        try {
            require_once __DIR__ . '/VendorDetailsService.php';
            (new VendorDetailsService($this->db))->fillFromReceipt($expenseId);
        } catch (Throwable $e) {
            error_log('Gate vendor details #' . $expenseId . ': ' . $e->getMessage());
        }
    }

    /** The owner's split, remembered per vendor line (expense_split_lessons). */
    public function learnSplit(int $expenseId, array $allocations): void
    {
        try {
            (new ExpenseSplitService($this->db))->learn($expenseId, $allocations);
        } catch (Throwable $e) {
            error_log('Gate split lessons #' . $expenseId . ': ' . $e->getMessage());
        }
    }

    /**
     * The owner put a receipt in another category and a bank line carries it: that bank
     * description belongs on the category's account (BankRuleLearning, 2 confirmations).
     */
    public function learnBank(int $expenseId, array $row, ?int $userId): void
    {
        if (!$userId || empty($row['accounting_category'])) return;
        try {
            $t = $this->db->prepare("SELECT id FROM accounting_transactions WHERE matched_expense_id = ? LIMIT 1");
            $t->execute([$expenseId]);
            $txId = (int)$t->fetchColumn();
            if (!$txId) return;
            require_once APP_ROOT . '/Modules/Accounting/Services/LedgerAccountMap.php';
            require_once APP_ROOT . '/Modules/Accounting/Services/BankImportService.php';
            require_once APP_ROOT . '/Modules/Accounting/Services/BankRuleLearning.php';
            $code = (new LedgerAccountMap($this->db))->expenseCode($row['accounting_category'], $row['asset_tag'] ?? null, $row['vendor_name_raw'] ?? null);
            $a = $this->db->prepare("SELECT id FROM chart_of_accounts WHERE code = ? LIMIT 1");
            $a->execute([$code]);
            $accountId = (int)$a->fetchColumn();
            if ($accountId) (new BankRuleLearning($this->db))->learnFromCorrection($txId, $accountId, $userId);
        } catch (Throwable $e) {
            error_log('Gate bank lesson #' . $expenseId . ': ' . $e->getMessage());
        }
    }

    /**
     * A person overrode what the receipt said (head_lessons, migration 1348): one lesson per field.
     * Subject = the vendor as Penny read it. Never blocks the change (PennyLessons is quiet).
     */
    public function lessons(int $expenseId, array $before, array $after, array $fields, ?int $userId, string $source, ?array $allocations = null): void
    {
        try {
            require_once APP_ROOT . '/Modules/Accounting/Services/PennyLessons.php';
            // The vendor as it was (its record's name first, as PennyLessons::expense() does).
            $vendor = '';
            if (!empty($before['vendor_id'])) {
                $v = $this->db->prepare("SELECT name FROM vendors WHERE id = ?");
                $v->execute([(int)$before['vendor_id']]);
                $vendor = trim((string)$v->fetchColumn());
            }
            if ($vendor === '') $vendor = trim((string)($before['vendor_name_raw'] ?? ''));
            $vendor = $vendor !== '' ? $vendor : 'unknown vendor';
            $src = 'expense_gate:' . $source;
            // vendor_id + vendor_name_raw changed together = one vendor lesson.
            if (in_array('vendor_id', $fields, true) && in_array('vendor_name_raw', $fields, true)) {
                $fields = array_values(array_diff($fields, ['vendor_id']));
            }
            foreach ($fields as $f) {
                if ($f === 'allocations') {
                    $said = null;
                    $correct = $allocations ? array_map(fn($a) => array_intersect_key((array)$a,
                        array_flip(['label', 'job_id', 'accounting_category', 'asset_tag', 'is_stock'])), $allocations) : 'not split';
                    $field = 'split';
                } else {
                    $said = $before[$f] ?? null;
                    $correct = $after[$f] ?? null;
                    $field = $f === 'vendor_name_raw' ? 'vendor' : $f;
                }
                PennyLessons::subject($this->db, 'vendor', $vendor, $field, $said, $correct, $src, $userId, 'expense', $expenseId);
            }
        } catch (Throwable $e) {
            error_log('Gate lessons #' . $expenseId . ': ' . $e->getMessage());
        }
    }

    /** A posted receipt changed: reverse its entry and post it again as it is now. @return bool re-posted */
    public function repost(int $expenseId, ?int $userId, string $why, bool $strict = false): bool
    {
        try {
            require_once APP_ROOT . '/Modules/Accounting/Services/LedgerService.php';
            require_once APP_ROOT . '/Modules/Accounting/Services/LedgerSyncService.php';
            $ledger = new LedgerService($this->db);
            $entryId = $ledger->findEntryIdBySource('expense', $expenseId);
            if (!$entryId) return false;                     // not posted yet — the nightly sync posts it as it is now
            if (!$ledger->canRepostSource()) {
                error_log('Gate repost #' . $expenseId . ': migration 1131 not run — left for the repost runner');
                return false;
            }
            $s = $this->db->prepare("SELECT id, expense_date, total, gst_amount, pst_amount, accounting_category, payment_method,
                                            vendor_id, job_id, contact_id, asset_tag, vendor_name_raw,
                                            (SELECT v.name FROM vendors v WHERE v.id = expenses.vendor_id) AS vendor_name
                                     FROM expenses WHERE id = ?");
            $s->execute([$expenseId]);
            $row = $s->fetch(PDO::FETCH_ASSOC);
            // Reverse and repost as ONE change. In a locked month the reversal is dated today, and the
            // repost at the receipt's own date was refused — leaving the receipt out of the books
            // with the audit saying "re-posted" (review 2026-10-12). Now the replacement takes the
            // same date as its reversal, and a failure undoes both.
            $own = !$this->db->inTransaction();
            if ($own) $this->db->beginTransaction();
            try {
                $ledger->reverseEntry($entryId, $userId, $why);
                if ($row && (float)$row['total'] > 0) {
                    $args = (new LedgerSyncService($this->db, $ledger))->expenseArgs($row);
                    if (!empty($args['date']) && $ledger->isLocked((string)$args['date'])) $args['date'] = date('Y-m-d');
                    $args['created_by'] = $userId;
                    $args['proposed_by'] = 'owner';
                    $ledger->postExpense($args);
                }
                if ($own) $this->db->commit();
            } catch (Throwable $e) {
                if ($own && $this->db->inTransaction()) $this->db->rollBack();
                throw $e;
            }
            return true;
        } catch (Throwable $e) {
            // $strict (the gate inside a caller's transaction): throw, so the caller rolls back rather than
            // committing a reversal without its repost (2026-10-13).
            if ($strict) throw $e instanceof RuntimeException ? $e : new RuntimeException($e->getMessage(), 0, $e);
            error_log('Gate repost #' . $expenseId . ': ' . $e->getMessage());
            return false;
        }
    }

    /**
     * A posted receipt rejected / cancelled / deleted: reverse its entry.
     * $strict: rethrow instead of logging — a delete must not go ahead with its entry still live (2026-10-13).
     */
    public function unpost(int $expenseId, ?int $userId, string $why, bool $strict = false): bool
    {
        try {
            require_once APP_ROOT . '/Modules/Accounting/Services/LedgerService.php';
            $ledger = new LedgerService($this->db);
            $entryId = $ledger->findEntryIdBySource('expense', $expenseId);
            if (!$entryId) return false;
            $ledger->reverseEntry($entryId, $userId, $why);
            return true;
        } catch (Throwable $e) {
            if ($strict) throw $e instanceof RuntimeException ? $e : new RuntimeException($e->getMessage(), 0, $e);
            error_log('Gate unpost #' . $expenseId . ': ' . $e->getMessage());
            return false;
        }
    }

    private function receiptLearning(): void
    {
        if (!function_exists('learnFromConfirmedExpense')) require_once APP_ROOT . '/Services/Receipts/ReceiptLearning.php';
        if (!function_exists('proposeProductsForExpense')) require_once APP_ROOT . '/Services/Receipts/ExpenseLineItems.php';
        if (!class_exists('ExpenseLineItemService')) require_once __DIR__ . '/ExpenseLineItemService.php';
    }
}
