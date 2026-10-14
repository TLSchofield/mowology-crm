<?php
/**
 * PaymentClueService — a client's text about a payment, handed from Yui to Penny (2026-10-14).
 *
 * Client texts reach the CRM through the messages bridge (TextBridgeService → sales_messages,
 * mailbox 'imessage'). "Just sent you the e-Transfer", "Sent $65 for the lawn" — that is often the
 * only record of who a deposit is from: Interac emails go to whatever address the client typed
 * (Joseph Gallentine's $65 on 2026-09-17 reached no mailbox the CRM reads), and the bank line names
 * our own business ("e-Transfer credit … MOWOLOGY LAWNS AND LANDSCAPES LTD").
 *
 * clueFor() finds the client text that most likely announced a deposit: an inbound text that talks
 * about paying, sent from DAYS_BEFORE days before the deposit to DAYS_AFTER after. One that states
 * the deposit's exact amount wins; otherwise a single payment text in the window is a weaker clue;
 * two or more without an amount are a guess, so none. Penny shows it on the deposit card and ranks
 * that client's open invoices first (BankInvoiceMatchService::suggest). Nothing is recorded by it.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
class PaymentClueService
{
    public const DAYS_BEFORE = 10;
    public const DAYS_AFTER  = 2;

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** Pure: does a text talk about paying us? */
    public static function isPaymentText(string $text): bool
    {
        return (bool)preg_match('/\be-?\s?transfer|\binterac\b|\betransfer|\bsent\s+(you\s+)?(the\s+|a\s+|my\s+)?(payment|money|funds|cheque|\$)|\bpaid\s+(you|the\s+invoice|it)\b|\bcheque\b|\bpayment\s+(sent|is\s+on|went)/i', $text);
    }

    /** Pure: the dollar amount a text names, if exactly one. */
    public static function amountIn(string $text): ?float
    {
        if (!preg_match_all('/\$\s?(\d{1,3}(?:,\d{3})+|\d+)(\.\d{2})?\b/', $text, $m, PREG_SET_ORDER)) return null;
        $amounts = array_values(array_unique(array_map(fn($x) => round((float)(str_replace(',', '', $x[1]) . ($x[2] ?? '')), 2), $m)));
        return count($amounts) === 1 ? $amounts[0] : null;
    }

    /**
     * Pure: pick the clue for a deposit from candidate texts (each: contact_id, name, sent_at, snippet).
     * @return array{contact_id:int,name:string,sent_at:string,snippet:string,amount:?float,exact:bool}|null
     */
    public static function pick(array $texts, float $amount): ?array
    {
        $exact = []; $loose = [];
        foreach ($texts as $t) {
            if (!self::isPaymentText((string)$t['snippet'])) continue;
            $a = self::amountIn((string)$t['snippet']);
            $row = ['contact_id' => (int)$t['contact_id'], 'name' => trim((string)$t['name']), 'sent_at' => (string)$t['sent_at'],
                    'snippet' => (string)$t['snippet'], 'amount' => $a, 'exact' => $a !== null && abs($a - $amount) < 0.005];
            if ($row['exact']) $exact[$row['contact_id']] = $row;
            elseif ($a === null) $loose[$row['contact_id']] = $row;
        }
        if (count($exact) === 1) return array_values($exact)[0];
        if (!$exact && count($loose) === 1) return array_values($loose)[0];
        return null;   // nothing, or more than one client could be it: a guess, so no clue
    }

    /** The clue for a deposit on $date of $amount, or null. */
    public function clueFor(string $date, float $amount): ?array
    {
        try {
            $s = $this->db->prepare("
                SELECT m.contact_id, CONCAT(COALESCE(c.first_name, ''), ' ', COALESCE(c.last_name, '')) AS name, m.sent_at, m.snippet
                FROM sales_messages m
                JOIN contacts c ON c.id = m.contact_id
                WHERE m.mailbox = 'imessage' AND m.direction = 'inbound'
                  AND m.sent_at >= ? AND m.sent_at < ?
                ORDER BY m.sent_at DESC
                LIMIT 200
            ");
            $from = date('Y-m-d 00:00:00', strtotime($date . ' -' . self::DAYS_BEFORE . ' days'));
            $to   = date('Y-m-d 00:00:00', strtotime($date . ' +' . (self::DAYS_AFTER + 1) . ' days'));
            $s->execute([$from, $to]);
            return self::pick($s->fetchAll(PDO::FETCH_ASSOC), $amount);
        } catch (Throwable $e) {
            error_log('[PaymentClue] ' . $e->getMessage());   // no sales_messages yet: no clue, the card works as before
            return null;
        }
    }
}
