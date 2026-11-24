<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Fable security audit #25 (2026-10-12): audit rows are never deleted, and money/price
 * changes are logged before → after.
 */
final class AuditTrailTest extends TestCase
{
    public function test_diff_shows_only_changed_fields_money_normalised(): void
    {
        $c = AuditDiff::changes(
            ['base_price' => '45.0', 'min_price' => null, 'base_cost' => '10.00'],
            ['base_price' => 50, 'min_price' => '40', 'base_cost' => '10'],
            ['base_price', 'min_price', 'base_cost'],
            ['base_price', 'min_price', 'base_cost']
        );
        $this->assertSame(['base_price' => ['45.00', '50.00'], 'min_price' => [null, '40.00']], $c);
        $this->assertSame('base_price 45.00 → 50.00; min_price (none) → 40.00', AuditDiff::describe($c));
    }

    public function test_diff_nothing_changed_is_empty(): void
    {
        $c = AuditDiff::changes(['status' => 'paid', 'amount_paid' => '100'], ['status' => 'paid', 'amount_paid' => 100.0],
            ['status', 'amount_paid'], ['amount_paid']);
        $this->assertSame([], $c);
        $this->assertSame('', AuditDiff::describe($c));
    }

    public function test_detach_line_names_invoice_amount_and_before_after(): void
    {
        $line = InvoiceReconciliationService::detachAuditLine(812, [
            'invoice_id' => 10, 'invoice_number' => 'INV-2026-0010', 'amount' => 1194.39,
            'before' => ['status' => 'paid', 'amount_paid' => '1194.39', 'balance_due' => '0.00'],
            'after'  => ['status' => 'overdue', 'amount_paid' => 0, 'balance_due' => 1194.39],
        ]);
        $this->assertSame('Bank deposit #812 detached from invoice INV-2026-0010: $1,194.39 unapplied — '
            . 'status paid → overdue; amount_paid 1194.39 → 0.00; balance_due 0.00 → 1194.39', $line);
    }

    public function test_bank_rollback_lines_per_invoice_then_summary(): void
    {
        $lines = BankImportService::rollbackAuditLines(13, 5, [
            ['allocation', 7, ['invoice_id' => 7, 'amount' => '300.00', 'invoice_number' => 'INV-2026-0007'], null],
            ['invoice', 7, ['invoice_number' => 'INV-2026-0007', 'status' => 'paid', 'amount_paid' => '300', 'balance_due' => '0', 'payment_method' => 'e_transfer', 'paid_at' => '2026-10-01 00:00:00'],
                           ['id' => 7, 'status' => 'sent', 'amount_paid' => 0, 'balance_due' => 300, 'payment_method' => null, 'paid_at' => null]],
            ['invoice', 8, ['invoice_number' => 'INV-2026-0008', 'status' => 'sent', 'amount_paid' => '0', 'balance_due' => '50', 'payment_method' => null, 'paid_at' => null],
                           ['id' => 8, 'status' => 'sent', 'amount_paid' => '0.00', 'balance_due' => 50, 'payment_method' => null, 'paid_at' => null]],
        ]);
        $this->assertCount(3, $lines);   // invoice 8 did not change → no line
        $this->assertSame([7, 'Bank import session #13 undone: payment of $300.00 removed from invoice INV-2026-0007'], $lines[0]);
        $this->assertSame(7, $lines[1][0]);
        $this->assertStringContainsString('status paid → sent; amount_paid 300.00 → 0.00; balance_due 0.00 → 300.00; payment_method e_transfer → (none)', $lines[1][1]);
        $this->assertSame([null, 'Bank import session #13 rolled back: 5 ledger row(s) deleted, 2 invoice(s) restored, 1 payment allocation(s) removed.'], $lines[2]);
    }

    public function test_company_deleted_lines_keep_old_id_and_name(): void
    {
        $this->assertSame(
            ['Deleted company #4 "Dorset Strata" — its earlier activity rows are kept with company_id cleared.'],
            AuditTrail::companyDeletedLines([4 => 'Dorset Strata', 5 => 'Kept Co'], [4])
        );
    }

    /** No code path may delete activity_log rows again. */
    public function test_no_code_deletes_activity_log_rows(): void
    {
        $root = dirname(__DIR__, 3);
        $hits = [];
        foreach (['app', 'public'] as $dir) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                $p = $f->getPathname();
                if (substr($p, -4) !== '.php' || strpos($p, '/vendor/') !== false) continue;
                if (preg_match('/(DELETE\s+FROM|TRUNCATE(\s+TABLE)?)\s+`?activity_log`?\b/i', (string)file_get_contents($p))) {
                    $hits[] = substr($p, strlen($root) + 1);
                }
            }
        }
        $this->assertSame([], $hits, 'activity_log is append-only: cut the link (UPDATE … SET x_id = NULL) instead');
    }
}
