<?php
/**
 * QuoteLineChoiceService — the client unticks the lines they don't want, then signs
 * (owner, 2026-10-10).
 *
 * Why: Monica at Macdonald PM emailed "council approved, but not the sprinkler line" about
 * QUO-2026-0073, and the office had to rebuild the quote by hand. On the quote page the
 * client can now untick a line; the total and GST update, and signing accepts exactly what
 * is ticked. The declined line stays on the quote, marked "declined by client", so the
 * record shows what was offered and what was turned down.
 *
 * Rules:
 *   - never on a contract quote (the contract and its route are built from every line);
 *   - only while the quote is out for signature ('sent' / 'viewed'), never after signing;
 *   - per quote: quotes.allow_line_decline 1/0, or NULL = the ops_settings default
 *     'quote_line_decline_default' (seeded on);
 *   - per line: the office can lock a line (client_locked) — and upsell lines keep their
 *     own add/remove boxes, optional lines are already outside the total;
 *   - at least one line must stay ticked (declining everything is "decline the quote").
 * A declined line is out of every total, never becomes a job or plan, and is not printed
 * on the PDF.
 *
 * Global-namespace service: require_once the file. Pure rules are static + tested.
 */
declare(strict_types=1);

class QuoteLineChoiceService
{
    public const TAX_RATE = 0.05;

    private PDO $db;
    private static array $ready = [];

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // ══════════════════════════════════════════════════════════════════════
    // PURE RULES (unit-tested)
    // ══════════════════════════════════════════════════════════════════════

    /** Is the client offered tick boxes on this quote? $default is the ops setting ('1'/'0'). */
    public static function allowed(array $quote, ?string $default): bool
    {
        if (!empty($quote['is_contract'])) return false;
        if (!in_array((string)($quote['status'] ?? ''), ['sent', 'viewed'], true)) return false;
        $own = $quote['allow_line_decline'] ?? null;
        $on  = ($own === null || $own === '') ? (string)$default === '1' : (string)$own === '1';
        return $on;
    }

    /** Can the client untick this line? (Upsells and optional lines have their own rules.) */
    public static function canDecline(array $line): bool
    {
        return empty($line['client_locked']) && empty($line['is_upsell']) && empty($line['is_optional'])
            && (float)($line['line_total'] ?? 0) > 0;
    }

    /** Billable lines: not optional, not declined. */
    public static function billable(array $line): bool
    {
        return empty($line['is_optional']) && empty($line['client_declined']);
    }

    /** Totals with declined and optional lines left out. */
    public static function totals(array $lines, float $taxRate = self::TAX_RATE): array
    {
        $sub = 0.0;
        foreach ($lines as $l) {
            if (self::billable($l)) $sub += (float)$l['line_total'];
        }
        $sub = round($sub, 2);
        $tax = round($sub * $taxRate, 2);
        return ['subtotal' => $sub, 'tax_rate' => $taxRate, 'tax_amount' => $tax, 'total' => round($sub + $tax, 2)];
    }

    /**
     * May this line be set declined / ticked again? Returns null when fine, else the reason
     * the client sees.
     */
    public static function refusal(array $lines, int $lineId, bool $declined): ?string
    {
        $line = null;
        foreach ($lines as $l) {
            if ((int)$l['id'] === $lineId) { $line = $l; break; }
        }
        if ($line === null) return 'That line is not on this quote.';
        if (!$declined) return null;   // ticking a line back on is always fine
        if (!self::canDecline($line)) return 'This line can\'t be removed — please call us if you\'d like to change it.';
        $left = 0;
        foreach ($lines as $l) {
            if ((int)$l['id'] !== $lineId && self::billable($l) && (float)$l['line_total'] > 0) $left++;
        }
        if ($left === 0) return 'At least one service has to stay ticked. To turn the whole quote down, just let us know.';
        return null;
    }

    // ══════════════════════════════════════════════════════════════════════
    // DB
    // ══════════════════════════════════════════════════════════════════════

    /** Migration 1315 has run (client_declined / client_locked / allow_line_decline exist). */
    public static function ready(PDO $db): bool
    {
        $k = spl_object_id($db);
        if (!isset(self::$ready[$k])) {
            try {
                $db->query('SELECT client_declined, client_locked FROM quote_line_items LIMIT 0');
                $db->query('SELECT allow_line_decline FROM quotes LIMIT 0');
                self::$ready[$k] = true;
            } catch (Throwable $e) {
                self::$ready[$k] = false;
            }
        }
        return self::$ready[$k];
    }

    /** SQL fragment for "not declined" on alias $a, or '' before migration 1315. */
    public static function notDeclinedSql(PDO $db, string $a = ''): string
    {
        return self::ready($db) ? ' AND COALESCE(' . ($a !== '' ? $a . '.' : '') . 'client_declined, 0) = 0' : '';
    }

    public function defaultSetting(): string
    {
        try {
            $v = $this->db->query("SELECT setting_value FROM ops_settings WHERE setting_key = 'quote_line_decline_default'")->fetchColumn();
            return $v === false ? '0' : (string)$v;
        } catch (Throwable $e) {
            return '0';
        }
    }

    public function allowedFor(array $quote): bool
    {
        return self::ready($this->db) && self::allowed($quote, $this->defaultSetting());
    }

    /**
     * The client ticks or unticks one line (token already checked by the caller).
     * @return array{success: bool, error?: string, totals?: array, declined?: bool}
     */
    public function setDeclined(array $quote, int $lineId, bool $declined, string $ip = ''): array
    {
        if (!$this->allowedFor($quote)) {
            return ['success' => false, 'error' => 'This quote can\'t be changed online.'];
        }
        $quoteId = (int)$quote['id'];
        $lines = $this->lines($quoteId);
        $why = self::refusal($lines, $lineId, $declined);
        if ($why !== null) return ['success' => false, 'error' => $why];

        $this->db->beginTransaction();
        try {
            $this->db->prepare("UPDATE quote_line_items SET client_declined = ? WHERE id = ? AND quote_id = ?")
                     ->execute([$declined ? 1 : 0, $lineId, $quoteId]);
            $totals = $this->recalc($quoteId);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            error_log('[quote line choice] ' . $e->getMessage());
            return ['success' => false, 'error' => 'Could not update the quote. Please try again.'];
        }
        $label = '';
        foreach ($lines as $l) {
            if ((int)$l['id'] === $lineId) $label = trim((string)($l['service_type'] ?: $l['description']));
        }
        try {
            $this->db->prepare("INSERT INTO activity_log (quote_id, action, details, ip_address, created_at) VALUES (?, ?, ?, ?, NOW())")
                     ->execute([$quoteId, $declined ? 'Client removed a line' : 'Client added a line back', $label, $ip]);
        } catch (Throwable $e) { /* non-critical */ }
        return ['success' => true, 'declined' => $declined, 'totals' => $totals];
    }

    /** Recalculate and store the quote's totals from its billable lines. */
    public function recalc(int $quoteId): array
    {
        $t = self::totals($this->lines($quoteId));
        $this->db->prepare("UPDATE quotes SET subtotal = ?, tax_amount = ?, amount = ?, total_amount = ? WHERE id = ?")
                 ->execute([$t['subtotal'], $t['tax_amount'], $t['total'], $t['total'], $quoteId]);
        return $t;
    }

    /** The lines the client turned down, for the office email and the activity log. */
    public function declinedLines(int $quoteId): array
    {
        if (!self::ready($this->db)) return [];
        $st = $this->db->prepare("SELECT * FROM quote_line_items WHERE quote_id = ? AND client_declined = 1 ORDER BY sort_order, id");
        $st->execute([$quoteId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** Office: allow / stop line choice on one quote (null = follow the default). */
    public function setAllowed(int $quoteId, ?bool $on): void
    {
        $this->db->prepare("UPDATE quotes SET allow_line_decline = ? WHERE id = ?")->execute([$on === null ? null : ($on ? 1 : 0), $quoteId]);
    }

    /** Office: lock a line so the client can't untick it. */
    public function setLocked(int $quoteId, int $lineId, bool $locked): void
    {
        $this->db->prepare("UPDATE quote_line_items SET client_locked = ? WHERE id = ? AND quote_id = ?")->execute([$locked ? 1 : 0, $lineId, $quoteId]);
    }

    private function lines(int $quoteId): array
    {
        $st = $this->db->prepare("SELECT * FROM quote_line_items WHERE quote_id = ? ORDER BY sort_order, id");
        $st->execute([$quoteId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
