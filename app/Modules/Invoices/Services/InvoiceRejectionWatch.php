<?php
declare(strict_types=1);

require_once __DIR__ . '/InvoiceRejectionNotice.php';

/**
 * InvoiceRejectionWatch — Charlie's urgent alert for an invoice a client's accounts system refused (Tim, 2026-10-28).
 * One alert per notice (the key carries the activity row id), for notices from the last 5 days whose invoice is still unpaid.
 * The alert text is what the owner needs to act on: which invoice, which client, and the reason they gave.
 */
final class InvoiceRejectionWatch
{
    public const DAYS = 5;

    /** @return array<int,array{key:string,kind:string,text:string,url:string}> */
    public static function urgent(PDO $db): array
    {
        try {
            $s = $db->prepare("SELECT a.id, a.invoice_id, a.details, i.invoice_number, i.balance_due
                               FROM activity_log a JOIN invoices i ON i.id = a.invoice_id
                               WHERE a.action = ? AND a.created_at >= ? AND i.balance_due > 0.005 ORDER BY a.id DESC LIMIT 10");
            $s->execute([InvoiceRejectionNotice::ACTION, date('Y-m-d H:i:s', time() - self::DAYS * 86400)]);
            $out = [];
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $d = json_decode((string)$r['details'], true) ?: [];
                $who = trim((string)($d['client'] ?? ''));
                $out[] = [
                    'key'  => 'urgent:invoice_rejected:' . (int)$r['id'],
                    'kind' => 'invoice',
                    'text' => ($who !== '' ? $who . "'s accounts system" : "A client's accounts system") . ' did not accept ' . $r['invoice_number']
                              . ' (' . rtrim((string)($d['reason'] ?? 'no reason given'), '.') . '). It is still unpaid.',
                    'url'  => '/crm/invoices/view.php?id=' . (int)$r['invoice_id'],
                ];
            }
            return $out;
        } catch (Throwable $e) {
            return [];
        }
    }
}
