<?php
/**
 * QuoteFromPhoneService — a draft quote made on the phone (Add → Quote, 2026-11-08).
 *
 * Offline-safe by being a draft: it saves through QuoteService::create (status 'draft', its number from the server) and
 * lands in Sam's "Quotes to send", where sending stays a person's tap while online (QuoteSendService).
 */
declare(strict_types=1);

class QuoteFromPhoneService
{
    /** @var PDO */
    private $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * The price list the form picks from, kept on the phone for no-signal use.
     * @return array{gst_percent:float, products:array<int, array{id:int, name:string, unit:string, price:float}>}
     */
    public function priceList(): array
    {
        $rows = $this->db->query("
            SELECT p.id, p.name, p.base_price, u.abbreviation AS unit
            FROM products p LEFT JOIN unit_types u ON u.id = p.unit_type_id
            WHERE p.is_archived = 0
            ORDER BY p.name
            LIMIT 1000
        ")->fetchAll(PDO::FETCH_ASSOC);
        return [
            'gst_percent' => round(TaxEngine::gstRate($this->db) * 100, 2),
            'products'    => array_map(static function ($r) {
                return ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'unit' => (string)($r['unit'] ?? ''), 'price' => (float)$r['base_price']];
            }, $rows),
        ];
    }

    /**
     * Quote lines from the phone's: totals are worked out here, never taken from the phone. Pure.
     * @return array<int, array>|string the lines, or why they can't be used
     */
    public static function buildLines(array $lines, float $gstPercent)
    {
        if (!$lines) return 'A quote needs at least one line.';
        $out = [];
        foreach ($lines as $l) {
            $description = trim((string)($l['description'] ?? ''));
            $quantity = (float)($l['quantity'] ?? 0);
            $price = (float)($l['unit_price'] ?? -1);
            if ($description === '') return 'Every line needs a description.';
            if ($quantity <= 0) return 'Every line needs a quantity above zero.';
            if ($price < 0) return 'A line can\'t have a negative price.';
            $out[] = [
                'product_id'  => !empty($l['product_id']) ? (int)$l['product_id'] : null,
                'service_type' => 'Service',
                'description' => $description,
                'quantity'    => $quantity,
                'unit_type'   => trim((string)($l['unit_type'] ?? '')) ?: 'each',
                'unit_price'  => $price,
                'line_total'  => round($quantity * $price, 2),
                'tax_rate'    => $gstPercent,
            ];
        }
        return $out;
    }

    /**
     * @return array{success:bool, quote_id?:int, quote_number?:string, error?:string, status?:int}
     */
    public function create(array $input, int $userId): array
    {
        $title = trim((string)($input['title'] ?? ''));
        if ($title === '') return self::no('A quote needs a title.');
        if (mb_strlen($title) > 255) return self::no('That title is too long (255 characters at most).');

        $propertyId = (int)($input['property_id'] ?? 0);
        $s = $this->db->prepare("SELECT 1 FROM properties WHERE id = ?");
        $s->execute([$propertyId]);
        if (!$s->fetchColumn()) return self::no('That property could not be found.');

        $rate = TaxEngine::gstRate($this->db);
        $lines = self::buildLines(is_array($input['line_items'] ?? null) ? $input['line_items'] : [], round($rate * 100, 2));
        if (is_string($lines)) return self::no($lines);

        // No contact_id: QuoteRecipientService picks who a quote for this property goes to (the strata or manager's quote
        // contact, not always the site contact). The saved total uses the business's own GST rate, as the phone showed.
        $quoteId = $this->createQuote([
            'property_id' => $propertyId, 'tax_fraction' => $rate, 'title' => $title, 'service_type' => 'landscaping',
            'valid_until' => null, 'terms' => '', 'notes_customer' => trim((string)($input['notes'] ?? '')),
            'notes_internal' => 'Made on the phone.', 'description' => '', 'is_contract' => 0,
        ], $lines, $userId);
        return ['success' => true, 'quote_id' => $quoteId, 'quote_number' => $this->number($quoteId)];
    }

    /** Overridable so the checks can be tested without QuoteService's MySQL-only SQL. */
    protected function createQuote(array $data, array $lines, int $userId): int
    {
        return (new QuoteService($this->db))->create($data, $lines, $userId);
    }

    private function number(int $quoteId): string
    {
        $s = $this->db->prepare("SELECT quote_number FROM quotes WHERE id = ?");
        $s->execute([$quoteId]);
        return (string)$s->fetchColumn();
    }

    private static function no(string $error, int $status = 422): array
    {
        return ['success' => false, 'error' => $error, 'status' => $status];
    }
}
