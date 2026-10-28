<?php
declare(strict_types=1);

/**
 * InvoiceRejectionNotice — a client's accounts system saying it did not accept an invoice we emailed (Tim, 2026-10-28).
 *
 * LeapAP wrote back "MOWOLOGY LAWNS & LANDSCAPES LTD. emailed invoice was not accepted … Reason: No PDF or image attachments found …
 * Subject: Invoice INV-2026-0431: $456.84 due October 31, 2026". That notice arrived in the receipts inbox and was filed as a draft
 * EXPENSE, so nobody knew a client had refused an invoice. parse() recognises these notices (pure, tested); record() writes one
 * activity_log row on the invoice; InvoiceRejectionWatch turns it into an urgent alert for the owner.
 *
 * No namespace / no autoloader: require_once and call statically.
 */
final class InvoiceRejectionNotice
{
    public const ACTION = 'Invoice rejected by client';

    /**
     * @return array{invoice_number:string, reason:string, client:string}|null  null = not a rejection notice
     */
    public static function parse(string $subject, string $text): ?array
    {
        $hay = $subject . "\n" . $text;
        // "not accepted" / "rejected" / "could not be processed", about an invoice we sent.
        if (!preg_match('/\binvoice\b[^.\n]{0,80}\b(was not accepted|not accepted|was rejected|rejected|could not be (processed|accepted|imported))\b/i', $hay)
            && !preg_match('/\b(not accepted|rejected)\b[^.\n]{0,60}\binvoice\b/i', $hay)) {
            return null;
        }
        if (!preg_match('/\bINV-\d{4}-\d{3,5}\b/i', $hay, $m)) return null;   // can't say which invoice: not ours to act on
        $reason = '';
        if (preg_match('/\bReason:\s*([^\n]{3,200}?)(?:\s{2,}|\n|$)/i', $hay, $r)) $reason = trim($r[1]);
        if ($reason === '' && preg_match('/\bReason:\s*(.{3,160})/is', $hay, $r)) $reason = trim(preg_replace('/\s+/', ' ', $r[1]));
        // The first sentence is the reason; what follows is boilerplate ("Resubmit your invoice… Maximum file size…").
        $reason = trim((string)preg_split('/(?<=[.!?])\s+/', trim($reason), 2)[0]);
        $client = '';
        if (preg_match('/\bClient:\s*([^\n]{2,60})/i', $hay, $c)) $client = trim($c[1]);
        elseif (preg_match('/emailed to ([^\n]{2,60}?) was not accepted/i', $hay, $c)) $client = trim($c[1]);
        return [
            'invoice_number' => strtoupper($m[0]),
            'reason'         => mb_substr(preg_replace('/\s+/', ' ', $reason), 0, 200) ?: 'no reason given',
            'client'         => mb_substr($client, 0, 60),
        ];
    }

    /** Record it once per message on the invoice's activity. @return bool true when a row was written */
    public static function record(PDO $db, array $notice, string $messageKey): bool
    {
        try {
            $s = $db->prepare("SELECT id FROM invoices WHERE invoice_number = ? LIMIT 1");
            $s->execute([$notice['invoice_number']]);
            $id = (int)$s->fetchColumn();
            if ($id <= 0) return false;
            $detail = json_encode(['reason' => $notice['reason'], 'client' => $notice['client'], 'message' => mb_substr($messageKey, 0, 120)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $d = $db->prepare("SELECT 1 FROM activity_log WHERE invoice_id = ? AND action = ? AND details = ? LIMIT 1");
            $d->execute([$id, self::ACTION, $detail]);
            if ($d->fetchColumn()) return false;
            $db->prepare("INSERT INTO activity_log (user_id, invoice_id, action, details, created_at) VALUES (NULL, ?, ?, ?, ?)")->execute([$id, self::ACTION, $detail, date('Y-m-d H:i:s')]);
            return true;
        } catch (Throwable $e) {
            error_log('InvoiceRejectionNotice::record: ' . $e->getMessage());
            return false;
        }
    }
}
