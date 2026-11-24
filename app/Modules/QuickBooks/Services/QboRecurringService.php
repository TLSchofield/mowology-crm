<?php
declare(strict_types=1);

require_once __DIR__ . '/QboConnectionService.php';

/** Reads QuickBooks recurring invoices and protects contracts from double billing. */
final class QboRecurringService
{
    private PDO $db;
    private ?QboConnectionService $conn;
    private int $siteId;
    private ?array $readResult = null;

    public function __construct(PDO $db, ?QboConnectionService $conn, int $siteId = 1)
    {
        $this->db = $db;
        $this->conn = $conn;
        $this->siteId = $siteId;
    }

    public static function normalise(array $row): ?array
    {
        if (isset($row['Invoice']) && is_array($row['Invoice'])) {
            $invoice = $row['Invoice'];
        } elseif (isset($row['RecurringInfo']) && is_array($row['RecurringInfo'])) {
            $invoice = $row;
        } else {
            return null;
        }
        $info = (array)($invoice['RecurringInfo'] ?? []);
        $schedule = (array)($info['ScheduleInfo'] ?? []);
        $total = (float)($invoice['TotalAmt'] ?? 0);
        $lineAmounts = [];
        $descriptions = [];
        foreach ((array)($invoice['Line'] ?? []) as $line) {
            if (!is_array($line)) continue;
            $description = trim((string)($line['Description'] ?? ''));
            if ($description !== '') $descriptions[] = $description;
            if (isset($line['SalesItemLineDetail'], $line['Amount'])) $lineAmounts[] = (float)$line['Amount'];
        }
        $subtotal = $lineAmounts ? array_sum($lineAmounts)
            : $total - (float)($invoice['TxnTaxDetail']['TotalTax'] ?? 0);
        $lines = mb_substr(implode(', ', $descriptions), 0, 200);
        return [
            'id' => (string)($invoice['Id'] ?? ''),
            'name' => trim((string)($info['Name'] ?? '')),
            'active' => ($info['Active'] ?? null) === true,
            'recur_type' => (string)($info['RecurType'] ?? ''),
            'customer_id' => (string)($invoice['CustomerRef']['value'] ?? ''),
            'customer_name' => (string)($invoice['CustomerRef']['name'] ?? ''),
            'total' => $total,
            'subtotal' => $subtotal,
            'interval' => (string)($schedule['IntervalType'] ?? ''),
            'every' => max(1, (int)($schedule['NumInterval'] ?? 1)),
            'day_of_month' => isset($schedule['DayOfMonth']) ? (int)$schedule['DayOfMonth'] : null,
            'start_date' => (string)($schedule['StartDate'] ?? ''),
            'end_date' => (string)($schedule['EndDate'] ?? ''),
            'next_date' => (string)($schedule['NextDate'] ?? ''),
            'lines' => $lines,
        ];
    }

    /** Makes or reminds invoices: switched on, scheduled, and its end date (if any) not passed. */
    public static function bills(array $n, ?string $today = null): bool
    {
        $end = substr((string)($n['end_date'] ?? ''), 0, 10);
        if ($end !== '' && $end < ($today ?? date('Y-m-d'))) return false;
        return !empty($n['active']) && strcasecmp((string)($n['recur_type'] ?? ''), 'UnScheduled') !== 0;
    }

    /** @return array{0:string,1:?string} */
    public static function cycleOf(array $n): array
    {
        $interval = (string)($n['interval'] ?? '');
        $every = max(1, (int)($n['every'] ?? 1));
        if (strcasecmp($interval, 'Monthly') === 0 && $every === 1) return ['monthly', null];
        if (strcasecmp($interval, 'Yearly') === 0 && $every === 1) return ['annual', 'Billed once a year in QuickBooks'];
        return ['custom', 'QuickBooks bills every ' . $every . ' ' . $interval];
    }

    /** @return array{ok:bool,rows:array,stale:bool,error?:string} */
    public function read(): array
    {
        if ($this->readResult !== null) return $this->readResult;
        $error = 'QuickBooks is not connected.';
        if ($this->conn !== null) {
            try {
                $result = $this->conn->client()->all('RecurringTransaction');
                if (!empty($result['ok'])) {
                    $rows = [];
                    foreach ((array)($result['rows'] ?? []) as $row) {
                        $normal = is_array($row) ? self::normalise($row) : null;
                        if ($normal !== null) $rows[] = $normal;
                    }
                    return $this->readResult = ['ok' => true, 'rows' => $rows, 'stale' => false];
                }
                $error = (string)($result['error'] ?? 'Recurring invoices could not be read.');
            } catch (Throwable $e) {
                $error = $e->getMessage() !== '' ? $e->getMessage() : get_class($e);
            }
        }
        try {
            $s = $this->db->prepare("SELECT data FROM qbo_onboarding_snapshot WHERE site_id = ? AND kind = 'recurring' ORDER BY id");
            $s->execute([$this->siteId]);
            $rows = [];
            foreach ($s->fetchAll(PDO::FETCH_COLUMN) ?: [] as $json) {
                $decoded = json_decode((string)$json, true);
                if (!is_array($decoded)) continue;
                $normal = isset($decoded['customer_id']) ? $decoded : self::normalise($decoded);
                if ($normal !== null) $rows[] = $normal;
            }
            if ($rows) return $this->readResult = ['ok' => true, 'rows' => $rows, 'stale' => true];
        } catch (Throwable $e) { /* no snapshot table or no saved recurring rows */ }
        return $this->readResult = ['ok' => false, 'rows' => [], 'stale' => false, 'error' => $error];
    }

    /** @return list<string> */
    public function customerIdsFor(int $contactId, int $propertyId, ?int $companyId): array
    {
        $keys = ['property:' . $propertyId, 'contact:' . $contactId];
        if ($companyId !== null && $companyId > 0) $keys[] = 'company:' . $companyId;
        try {
            $s = $this->db->prepare('SELECT id FROM clients WHERE legacy_contact_id = ? ORDER BY id');
            $s->execute([$contactId]);
            foreach ($s->fetchAll(PDO::FETCH_COLUMN) ?: [] as $id) $keys[] = 'client:' . (int)$id;
        } catch (Throwable $e) { /* schema variant */ }
        $ids = [];
        foreach (array_unique($keys) as $key) {
            try {
                $s = $this->db->prepare("SELECT qbo_id FROM qbo_links WHERE site_id = ? AND entity_type = 'client' AND entity_id = ? AND qbo_id IS NOT NULL");
                $s->execute([$this->siteId, $key]);
                foreach ($s->fetchAll(PDO::FETCH_COLUMN) ?: [] as $id) if ((string)$id !== '') $ids[] = (string)$id;
            } catch (Throwable $e) { /* one absent identity source must not disable the guard */ }
        }
        return array_values(array_unique($ids));
    }

    public function holdReason(array $contract): ?string
    {
        $ids = $this->customerIdsFor((int)$contract['contact_id'], (int)$contract['property_id'],
            isset($contract['company_id']) ? (int)$contract['company_id'] : null);
        if (!$ids) return null;
        $read = $this->read();
        if (!$read['ok']) return 'QuickBooks recurring invoices could not be read (' . (string)($read['error'] ?? 'unknown error') . ') — held so the client is not billed twice.';
        foreach ($read['rows'] as $row) {
            if (self::bills($row) && in_array((string)$row['customer_id'], $ids, true)) {
                return 'QuickBooks still bills this client with the recurring invoice "' . (string)$row['name'] . '" ($'
                    . number_format((float)$row['total'], 2) . ' ' . self::intervalWords($row)
                    . ') — pause it in QuickBooks, then this contract bills.'
                    // Stale = the live read failed and this is the last import's copy: say so, or a paused one looks still on.
                    . (!empty($read['stale']) ? ' (QuickBooks could not be read just now; this is from the last import — reconnect QuickBooks if it was already paused.)' : '');
            }
        }
        return null;
    }

    public function pausePrompt(): ?string
    {
        $read = $this->read();
        if (!$read['ok']) return null;
        $billable = array_values(array_filter($read['rows'], [self::class, 'bills']));
        if (!$billable) return null;
        $shown = array_slice($billable, 0, 5);
        $names = array_map(static function (array $row): string {
            return (string)$row['name'] . ' — $' . number_format((float)$row['total'], 2);
        }, $shown);
        $more = count($billable) - count($shown);
        if ($more > 0) $names[] = 'and ' . $more . ' more';
        $count = count($billable);
        return 'QuickBooks still makes ' . $count . ' recurring invoice' . ($count === 1 ? '' : 's') . ': '
            . implode(', ', $names) . '. Pause each one an Ittai contract replaces, or the client gets two invoices. '
            . "Ittai holds a contract's invoice while its QuickBooks one is on.";
    }

    private static function intervalWords(array $row): string
    {
        $every = max(1, (int)($row['every'] ?? 1));
        $interval = strtolower((string)($row['interval'] ?? 'interval'));
        if ($every === 1) {
            $singular = rtrim($interval, 'ly');
            $words = ['daily' => 'day', 'weekly' => 'week', 'monthly' => 'month', 'yearly' => 'year'];
            return 'every ' . ($words[$interval] ?? $singular);
        }
        $words = ['daily' => 'days', 'weekly' => 'weeks', 'monthly' => 'months', 'yearly' => 'years'];
        return 'every ' . $every . ' ' . ($words[$interval] ?? $interval);
    }
}
