<?php
/**
 * ReceiptCaptureService — a phone's receipt in ONE call (Android native receipts 1.7.0, 2026-10-16).
 *
 * The photo is read (ReceiptIntakeService::intake, the same reader as every other surface) and saved as a
 * pending_approval expense through ExpenseGate — capture-only: approval stays on the desk / Penny's queue.
 * Why one call and not receipt-upload + expense-save (the iPhone's two steps): a phone queue that sends while
 * the signal comes and goes can lose the first answer, send the photo again and make a second expense.
 * Here every receipt carries the phone's own reference (client_ref, receipt_capture_keys, migration 1370):
 * a repeat answers with the expense already made.
 *
 * What the crew typed wins over what was read: a typed total replaces the read one (and when they differ,
 * the read tax split is dropped — the office checks it); the note becomes the description; a visit picked
 * on the phone gives the job and property. Pure parts (cleanRef, crewFields, buildInput) are tested.
 * Tenant-neutral; global namespace (require_once + new).
 */
declare(strict_types=1);

class ReceiptCaptureService
{
    public const SOURCE = 'phone_capture';
    public const DEFAULT_DESCRIPTION = 'From the phone - please review';
    public const MAX_TOTAL = 100000.0;

    private PDO $db;
    /** @var callable(int, array, ?float, ?float): array */
    private $intake;
    private ?ExpenseGate $gate;
    /** @var callable(array): array */
    private $anomaly;
    /** @var callable(int, int): bool  may this person tag this visit (crew on it, own timer, or office) */
    private $mayUseVisit;

    public function __construct(PDO $db, ?callable $intake = null, ?ExpenseGate $gate = null, ?callable $anomaly = null,
                                ?callable $mayUseVisit = null)
    {
        $this->db = $db;
        $this->intake = $intake ?? function (int $userId, array $file, ?float $lat, ?float $lng): array {
            require_once APP_ROOT . '/Modules/Expenses/Services/ReceiptIntakeService.php';
            return (new ReceiptIntakeService($this->db))->intake($userId, $file, $lat, $lng, null);
        };
        $this->gate = $gate;
        $this->anomaly = $anomaly ?? function (array $data): array {
            require_once APP_ROOT . '/Services/Receipts/AnomalyDetector.php';
            return detectAnomalies($data, $this->db);
        };
        // The timer's rule (Codex review 2026-10-16): a crew login tags only a visit it is on.
        $this->mayUseVisit = $mayUseVisit ?? function (int $visitId, int $userId): bool {
            require_once APP_ROOT . '/Modules/Team/Services/TimeclockFunctions.php';
            return userMayActOnVisit($visitId, $userId);
        };
    }

    /** The phone's reference: 8–64 letters, digits, '-' or '_'; anything else is no reference. */
    public static function cleanRef($ref): ?string
    {
        $ref = trim((string)$ref);
        return preg_match('/^[A-Za-z0-9_-]{8,64}$/', $ref) ? $ref : null;
    }

    /**
     * The crew's own fields from the request → {total: ?float, note: string, visit_id: ?int, lat: ?float, lng: ?float}.
     * A total must be a positive amount under MAX_TOTAL ("12,50" reads as 12.50, as on the iPhone).
     */
    public static function crewFields(array $post): array
    {
        $raw = str_replace([',', '$', ' '], ['.', '', ''], trim((string)($post['total'] ?? '')));
        $total = is_numeric($raw) ? round((float)$raw, 2) : null;
        if ($total !== null && ($total <= 0 || $total >= self::MAX_TOTAL)) $total = null;
        $note = trim(preg_replace('/\s+/u', ' ', (string)($post['note'] ?? '')));
        $num = static fn($v, float $lim) => (is_numeric($v) && abs((float)$v) <= $lim) ? (float)$v : null;
        $lat = $num($post['lat'] ?? null, 90); $lng = $num($post['lng'] ?? null, 180);
        if ($lat === null || $lng === null || ($lat == 0.0 && $lng == 0.0)) { $lat = null; $lng = null; }
        return [
            'total'    => $total,
            'note'     => mb_substr($note, 0, 500),
            'visit_id' => ctype_digit((string)($post['visit_id'] ?? '')) && (int)$post['visit_id'] > 0 ? (int)$post['visit_id'] : null,
            'lat'      => $lat,
            'lng'      => $lng,
        ];
    }

    /**
     * The expense to save, from what was read (intake) and what the crew typed. Pure.
     * @param array      $intake ReceiptIntakeService::intake() result
     * @param array      $crew   crewFields()
     * @param array|null $visit  {plan_id, property_id} of the visit the crew picked
     */
    public static function buildInput(array $intake, array $crew, ?array $visit, string $today): array
    {
        $p = is_array($intake['parsed'] ?? null) ? $intake['parsed'] : [];
        $s = is_array($intake['suggestions'] ?? null) ? $intake['suggestions'] : [];
        $money = static fn($v) => is_numeric($v) ? round(max(0.0, (float)$v), 2) : 0.0;

        $readTotal = $money($p['total'] ?? null);
        $gst = $money($p['gst'] ?? null);
        $pst = $money($p['pst'] ?? null);
        $total = $readTotal;
        $typedDiffers = false;
        if ($crew['total'] !== null) {
            $typedDiffers = abs($crew['total'] - $readTotal) > 0.02;
            $total = $crew['total'];
        }
        if ($typedDiffers) {
            // The read split belongs to a different total; the office splits the tax on review.
            $gst = 0.0; $pst = 0.0; $sub = $total;
        } else {
            $sub = $money($p['subtotal'] ?? null);
            if ($sub <= 0 && $total > 0) $sub = round(max(0.0, $total - $gst - $pst), 2);
        }

        $date = (string)($p['date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date > date('Y-m-d', strtotime($today . ' +1 day'))) $date = $today;

        $lines = [];
        foreach ((array)($p['line_items'] ?? []) as $it) {
            if (!is_array($it)) continue;
            $lines[] = [
                'name'       => (string)($it['name'] ?? 'Unknown Item'),
                'ocr_name'   => (string)($it['ocr_name'] ?? $it['name'] ?? ''),
                'quantity'   => $it['quantity'] ?? 1,
                'unit_price' => $it['unit_price'] ?? null,
                'line_total' => $it['line_total'] ?? $it['amount'] ?? 0,
                'sku_raw'    => $it['sku_raw'] ?? null,
                'product_id' => $it['product_id'] ?? null,
            ];
        }

        $ocrText = (string)($intake['ocr_text'] ?? '');
        return [
            'expense_date'        => $date,
            'vendor_id'           => !empty($s['vendor_id']) ? (int)$s['vendor_id'] : null,
            'vendor_name_raw'     => (string)($s['vendor_name'] ?? $p['vendor_hint'] ?? ''),
            'payment_method'      => (string)($p['payment_method'] ?? ''),
            'amount'              => number_format($sub, 2, '.', ''),
            'gst_amount'          => number_format($gst, 2, '.', ''),
            'pst_amount'          => number_format($pst, 2, '.', ''),
            'total'               => $total,
            'accounting_category' => (string)($s['accounting_category'] ?? ''),
            'job_id'              => $visit && !empty($visit['plan_id']) ? (int)$visit['plan_id'] : null,
            'property_id'         => $visit && !empty($visit['property_id']) ? (int)$visit['property_id'] : null,
            'description'         => $crew['note'] !== '' ? $crew['note'] : self::DEFAULT_DESCRIPTION,
            'receipt_media_id'    => (int)($intake['media_id'] ?? 0),
            'receipt_lat'         => $crew['lat'],
            'receipt_lng'         => $crew['lng'],
            'raw_ocr_json'        => $ocrText !== '' ? $ocrText : null,
            'ocr_parsed'          => $p ? json_encode($p) : null,
            'line_items_source'   => $p['line_items_source'] ?? null,
            'line_items'          => $typedDiffers ? [] : $lines,
            'typed_total'         => $typedDiffers,
        ];
    }

    /**
     * Read the photo and save the receipt — or answer with the one already saved for this reference.
     * @return array{http: int, body: array}
     */
    public function capture(int $userId, array $file, array $crew, string $ref, string $today): array
    {
        $lock = 'rcap:' . $userId . ':' . $ref;
        $this->lock($lock);
        try {
            $s = $this->db->prepare("SELECT id, media_id, expense_id FROM receipt_capture_keys WHERE user_id = ? AND client_ref = ? LIMIT 1");
            $s->execute([$userId, $ref]);
            $key = $s->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($key && (int)$key['expense_id'] > 0) {
                return ['http' => 200, 'body' => ['success' => true, 'duplicate' => true] + $this->summary((int)$key['expense_id'])];
            }
            // The photo was stored last time but the answer stopped short: an expense made from it is this receipt.
            if ($key && (int)$key['media_id'] > 0) {
                $e = $this->db->prepare("SELECT id FROM expenses WHERE receipt_media_id = ? ORDER BY id LIMIT 1");
                $e->execute([(int)$key['media_id']]);
                $made = (int)($e->fetchColumn() ?: 0);
                if ($made > 0) {
                    $this->db->prepare("UPDATE receipt_capture_keys SET expense_id = ? WHERE id = ?")->execute([$made, (int)$key['id']]);
                    return ['http' => 200, 'body' => ['success' => true, 'duplicate' => true] + $this->summary($made)];
                }
            }

            $res = ($this->intake)($userId, $file, $crew['lat'], $crew['lng']);
            if (empty($res['success'])) {
                $code = (int)($res['http_code'] ?? 500);
                return ['http' => $code >= 400 ? $code : 500, 'body' => ['success' => false, 'error' => (string)($res['error'] ?? 'Upload failed')]];
            }
            // Claim the reference with the stored photo BEFORE the expense, so a save that dies half-way is found above.
            $mediaId = (int)($res['media_id'] ?? 0) ?: null;
            if ($key) {
                $this->db->prepare("UPDATE receipt_capture_keys SET media_id = ? WHERE id = ?")->execute([$mediaId, (int)$key['id']]);
                $keyId = (int)$key['id'];
            } else {
                $this->db->prepare("INSERT INTO receipt_capture_keys (user_id, client_ref, media_id, expense_id, source, created_at)
                                    VALUES (?, ?, ?, NULL, 'app', ?)")->execute([$userId, $ref, $mediaId, date('Y-m-d H:i:s')]);
                $keyId = (int)$this->db->lastInsertId();
            }

            // A visit this person may not tag is dropped, not refused: the receipt still saves, the office sets the job.
            $visit = $crew['visit_id'] && ($this->mayUseVisit)($crew['visit_id'], $userId) ? $this->visit($crew['visit_id'], $today) : null;
            $input = self::buildInput($res, $crew, $visit, $today);
            $flags = ''; $score = 0;
            try {
                $a = ($this->anomaly)(array_merge($input, ['created_by' => $userId]));
                $flags = (string)($a['flags'] ?? ''); $score = (int)($a['score'] ?? 0);
            } catch (Throwable $e) {
                error_log('ReceiptCaptureService anomaly: ' . $e->getMessage());
            }

            $ocrd = !empty($input['raw_ocr_json']) && !empty($input['ocr_parsed']);
            $gate = $this->gate ?? $this->defaultGate();
            $created = $gate->apply(null, ExpenseGate::rowFromInput($input, [
                'expense_date'  => $input['expense_date'],
                'total'         => $input['total'],
                'anomaly_flags' => $flags !== '' ? $flags : null,
                'anomaly_score' => $score,
                'status'        => 'pending_approval',
                'created_by'    => $userId,
            ]) + ($input['line_items'] ? ['line_items' => $input['line_items']] : []),
                ['id' => $userId, 'kind' => 'user'], self::SOURCE, [
                'ocr_parsed'  => $ocrd ? $input['ocr_parsed'] : null,
                'learn_lines' => $ocrd && !$input['typed_total'] ? $input : null,
                'price_intel' => !$input['typed_total'],
            ]);
            $expenseId = (int)$created['expense_id'];

            $this->db->prepare("UPDATE receipt_capture_keys SET expense_id = ? WHERE id = ?")->execute([$expenseId, $keyId]);

            return ['http' => 200, 'body' => ['success' => true, 'duplicate' => false] + $this->summary($expenseId)];
        } finally {
            $this->unlock($lock);
        }
    }

    /** What the phone shows on its Sent list. */
    private function summary(int $expenseId): array
    {
        $s = $this->db->prepare("SELECT id, expense_date, total, vendor_name_raw, status FROM expenses WHERE id = ?");
        $s->execute([$expenseId]);
        $r = $s->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'expense_id' => $expenseId,
            'date'       => $r['expense_date'] ?? null,
            'total'      => isset($r['total']) ? round((float)$r['total'], 2) : null,
            'vendor'     => (string)($r['vendor_name_raw'] ?? ''),
            'status'     => (string)($r['status'] ?? 'pending_approval'),
        ];
    }

    /** The picked visit's job (plan) and property — only a visit within two weeks of today. */
    private function visit(int $visitId, string $today): ?array
    {
        $s = $this->db->prepare("SELECT v.plan_id, p.property_id FROM job_visits v JOIN job_plans p ON p.id = v.plan_id
                                 WHERE v.id = ? AND v.scheduled_date BETWEEN ? AND ? LIMIT 1");
        $s->execute([$visitId, date('Y-m-d', strtotime($today . ' -14 days')), date('Y-m-d', strtotime($today . ' +14 days'))]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        return $r ? ['plan_id' => (int)$r['plan_id'], 'property_id' => (int)$r['property_id']] : null;
    }

    private function defaultGate(): ExpenseGate
    {
        require_once APP_ROOT . '/Modules/Expenses/Services/ExpenseGate.php';
        return new ExpenseGate($this->db);
    }

    private function lock(string $name): void
    {
        if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') return;
        $s = $this->db->prepare("SELECT GET_LOCK(?, 20)");
        $s->execute([$name]);
        if ((int)$s->fetchColumn() !== 1) throw new RuntimeException('Busy - try again');
    }

    private function unlock(string $name): void
    {
        if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') return;
        $this->db->prepare("SELECT RELEASE_LOCK(?)")->execute([$name]);
    }
}
