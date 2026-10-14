<?php
/**
 * PaymentOnTheWay — is a client's payment for this invoice already in hand, just not recorded yet?
 * (Tim, 2026-10-14: "the invoice now has strong certainty of being paid — reminders and chasing should
 * be paused until statement upload or bank reconciliation".)
 *
 * An Interac e-Transfer email waiting on Penny's card (pending / partly recorded) that points at the
 * invoice: matched to it, its invoice hint, or any invoice number its memo names ("Invs 2026-0251 and
 * 0383" names 0383 too; stored in invoice_hints when the email is read). While one exists, nothing
 * chases the invoice — reminders, the overdue flip, overdue automations, autopay. Once the payment is recorded the invoice is paid and nothing chases it anyway;
 * the bank statement later confirms the deposit (InvoiceReconciliationService).
 *
 * One SQL fragment for every chaser, so they can't drift apart (they had four copies of the
 * matched-only check, and autopay had none).
 */
class PaymentOnTheWay
{
    /**
     * SQL: TRUE when a waiting e-Transfer points at invoice alias $i: matched to it, or its memo names it
     * (etransfer_notifications.invoice_hints, every number the memo names — migration 1363).
     * Use as "AND NOT " . sql('i'). MySQL 5.7 (FIND_IN_SET).
     */
    public static function sql(string $i = 'i', bool $withHints = true): string
    {
        $i = preg_replace('/[^A-Za-z0-9_]/', '', $i) ?: 'i';
        return "EXISTS (
            SELECT 1 FROM etransfer_notifications en
             WHERE en.status IN ('pending', 'partially_recorded')
               AND (en.matched_invoice_id = {$i}.id OR en.invoice_hint = {$i}.invoice_number"
            . ($withHints ? " OR FIND_IN_SET({$i}.invoice_number, en.invoice_hints) > 0" : '') . ")
        )";
    }

    /** Is the invoice_hints column there yet (migration 1363)? Callers pass it to sql(). */
    public static function hintsReady(PDO $db): bool
    {
        try {
            $db->query("SELECT invoice_hints FROM etransfer_notifications LIMIT 0");
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }
}
