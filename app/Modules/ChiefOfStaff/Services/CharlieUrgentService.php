<?php
/**
 * CharlieUrgentService — the only things Charlie interrupts Tim for (quiet by default).
 *
 *   payment — an autopay charge failed (or needs the customer to authenticate) for at
 *             least ops_settings charlie_urgent_payment_min dollars ($500), last 48 h
 *   weather — Otto's weather guard says a visit TODAY shouldn't go ahead (NOT_OK)
 *   invoice_pdf — an invoice email (send, contract billing, reminder, autopay notice) was
 *             NOT sent because its PDF could not be made (InvoicePdfGate, last 48 h);
 *             one alert per invoice
 * (client complaints join when Sam or Mia can flag them — phase B3.)
 *
 * Each alert is sent once (charlie_alerts.alert_key), by email to the owner through
 * sendEmail(). Push is not used: it isn't confirmed working on production.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
class CharlieUrgentService
{
    public const DEFAULT_PAYMENT_MIN = 500.0;
    public const PAYMENT_LOOKBACK_HOURS = 48;
    public const PDF_LOOKBACK_HOURS = 48;

    private PDO $db;
    private ?string $today;

    public function __construct(PDO $db, ?string $today = null)
    {
        $this->db = $db;
        $this->today = $today;
    }

    private function today(): string { return $this->today ?? date('Y-m-d'); }

    public function ready(): bool
    {
        try {
            return $this->db->query("SHOW TABLES LIKE 'charlie_alerts'")->rowCount() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function threshold(): float
    {
        try {
            $s = $this->db->prepare("SELECT setting_value FROM ops_settings WHERE setting_key = 'charlie_urgent_payment_min'");
            $s->execute();
            $v = $s->fetchColumn();
            return $v !== false && is_numeric($v) ? max(0.0, (float)$v) : self::DEFAULT_PAYMENT_MIN;
        } catch (Throwable $e) {
            return self::DEFAULT_PAYMENT_MIN;
        }
    }

    public function setThreshold(float $v): void
    {
        $this->db->prepare("
            INSERT INTO ops_settings (setting_key, setting_value, description) VALUES ('charlie_urgent_payment_min', ?, 'Charlie (Foreman): email Tim at once when a payment fails for at least this many dollars')
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
        ")->execute([(string)round(max(0, $v), 2)]);
    }

    /** @return array<int, array{key: string, kind: string, text: string, url: string}> */
    public function find(): array
    {
        $out = [];
        try {
            $s = $this->db->prepare("
                SELECT a.id, a.amount_cents, a.status, a.failure_message, a.created_at, i.invoice_number, i.id AS invoice_id,
                       TRIM(CONCAT(COALESCE(c.first_name, ''), ' ', COALESCE(c.last_name, ''))) AS who
                FROM autopay_attempts a
                LEFT JOIN invoices i ON i.id = a.invoice_id
                LEFT JOIN contacts c ON c.id = a.contact_id
                WHERE a.status IN ('failed', 'authentication_required') AND a.amount_cents >= ? AND a.created_at >= ?
                ORDER BY a.created_at DESC
            ");
            $s->execute([(int)round($this->threshold() * 100), date('Y-m-d H:i:s', strtotime('-' . self::PAYMENT_LOOKBACK_HOURS . ' hours'))]);
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $out[] = self::paymentAlert($r);
        } catch (Throwable $e) { /* no autopay table — nothing to watch */ }

        try {
            // Invoice emails held because the PDF could not be made (InvoicePdfGate rows).
            $s = $this->db->prepare("
                SELECT al.id, al.invoice_id, al.details, al.created_at, i.invoice_number, i.status
                FROM activity_log al
                LEFT JOIN invoices i ON i.id = al.invoice_id
                WHERE al.action = 'invoice_pdf_blocked' AND al.invoice_id IS NOT NULL AND al.created_at >= ?
                ORDER BY al.created_at DESC, al.id DESC
            ");
            $s->execute([date('Y-m-d H:i:s', strtotime('-' . self::PDF_LOOKBACK_HOURS . ' hours'))]);
            foreach (self::pdfBlockedAlerts($s->fetchAll(PDO::FETCH_ASSOC)) as $a) $out[] = $a;
        } catch (Throwable $e) { /* no activity_log — nothing to watch */ }

        if (defined('APP_ROOT') && is_file(APP_ROOT . '/Modules/Operations/Services/OpsDeskService.php')) {
            try {
                require_once APP_ROOT . '/Modules/Operations/Services/OpsDeskService.php';
                $o = new OpsDeskService($this->db);
                if (!method_exists($o, 'ready') || $o->ready()) {
                    foreach (self::weatherAlerts($o->current(false), $this->today()) as $a) $out[] = $a;
                }
            } catch (Throwable $e) {
                error_log('Charlie urgent: Otto unavailable: ' . $e->getMessage());
            }
        }
        return $out;
    }

    /** Pure: one failed autopay row → an alert. */
    public static function paymentAlert(array $r): array
    {
        $amt = '$' . number_format((int)$r['amount_cents'] / 100, 2);
        $who = trim((string)($r['who'] ?? '')) ?: 'a customer';
        $why = $r['status'] === 'authentication_required' ? 'the card needs the customer to approve it' : (trim((string)($r['failure_message'] ?? '')) ?: 'the card was declined');
        return [
            'key'  => 'urgent:payment:' . (int)$r['id'],
            'kind' => 'payment',
            'text' => "Autopay failed: {$amt} from {$who}" . (!empty($r['invoice_number']) ? ' (invoice ' . $r['invoice_number'] . ')' : '') . " — {$why}.",
            'url'  => !empty($r['invoice_id']) ? '/crm/invoices/view.php?id=' . (int)$r['invoice_id'] : '/crm/invoices/index.php',
        ];
    }

    /**
     * Pure: activity_log 'invoice_pdf_blocked' rows (newest first) → one alert per invoice.
     * Keyed per invoice, so Tim hears about each invoice once however many runs it fails.
     */
    public static function pdfBlockedAlerts(array $rows): array
    {
        $what = [
            'contract_billing'             => 'Monthly contract invoice %s not sent (kept as a draft; it retries on the next billing run)',
            'invoice_from_visit'           => 'Invoice %s not sent (kept as a draft)',
            'view_send'                    => 'Invoice %s not sent',
            'bulk_resend'                  => 'Invoice %s not resent',
            'schedule_invoice_create_send' => 'Invoice %s not sent (saved as a draft)',
            'reminder'                     => 'Payment reminder for invoice %s held (it retries on the next reminder run)',
            'autopay_auth_notice'          => 'Autopay "confirm with your bank" email for invoice %s not sent',
        ];
        $out = [];
        foreach ($rows as $r) {
            $id = (int)($r['invoice_id'] ?? 0);
            if ($id <= 0 || isset($out[$id])) continue;
            $d = json_decode((string)($r['details'] ?? ''), true);
            $ctx = is_array($d) ? (string)($d['context'] ?? '') : '';
            $num = trim((string)($r['invoice_number'] ?? '')) ?: ('#' . $id);
            $out[$id] = [
                'key'  => 'urgent:invoice_pdf:' . $id,
                'kind' => 'invoice_pdf',
                'text' => sprintf($what[$ctx] ?? 'Email for invoice %s not sent', $num) . ' — its PDF could not be made. Open it and press Regenerate PDF.',
                'url'  => '/crm/invoices/view.php?id=' . $id,
            ];
        }
        return array_values($out);
    }

    /** Pure: Otto's items → same-day weather alerts (today's visits the guard says shouldn't go). */
    public static function weatherAlerts(array $ottoItems, string $today): array
    {
        $out = [];
        foreach ($ottoItems as $it) {
            if (($it['kind'] ?? '') !== 'weather' || ($it['for_date'] ?? '') !== $today) continue;
            if ((($it['propose']['status'] ?? '') !== 'NOT_OK')) continue;
            $out[] = ['key' => 'urgent:weather:' . (int)$it['subject_id'] . ':' . $today, 'kind' => 'weather',
                      'text' => 'Weather today: ' . $it['text'], 'url' => (string)($it['url'] ?? '/crm/dashboard_appstack.php#mw-otto')];
        }
        return $out;
    }

    /** A failed alert is retried at most once an hour, and at most MAX_TRIES times in all (Fable review 2026-10-12). */
    public const MAX_TRIES = 3;
    public const RETRY_GAP_SECONDS = 3600;

    /** Alerts not yet sent (and, for ones that failed, due another try). */
    public function unsent(array $alerts, ?int $now = null): array
    {
        if (!$alerts) return [];
        $now = $now ?? time();
        $in = implode(',', array_fill(0, count($alerts), '?'));
        $s = $this->db->prepare("SELECT alert_key, sent_at, error FROM charlie_alerts WHERE alert_key IN ({$in})");
        $s->execute(array_column($alerts, 'key'));
        $rows = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $rows[$r['alert_key']] = $r;
        return array_values(array_filter($alerts, function ($a) use ($rows, $now) {
            $r = $rows[$a['key']] ?? null;
            if ($r === null) return true;
            if (!empty($r['sent_at'])) return false;
            return self::retryDue(self::failures((string)($r['error'] ?? '')), $now);
        }));
    }

    public function record(array $alerts, ?string $error, ?int $now = null): void
    {
        $now = $now ?? time();
        $prev = [];
        if ($error !== null && $alerts) {
            $in = implode(',', array_fill(0, count($alerts), '?'));
            $s = $this->db->prepare("SELECT alert_key, error FROM charlie_alerts WHERE alert_key IN ({$in}) AND sent_at IS NULL");
            $s->execute(array_column($alerts, 'key'));
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $prev[$r['alert_key']] = self::failures((string)$r['error'])['tries'];
        }
        $up = $this->db->prepare("
            INSERT INTO charlie_alerts (alert_key, kind, text, sent_at, channel, error) VALUES (?, ?, ?, ?, 'email', ?)
            ON DUPLICATE KEY UPDATE sent_at = VALUES(sent_at), error = VALUES(error)
        ");
        foreach ($alerts as $a) {
            $err = $error === null ? null : self::failureNote(($prev[$a['key']] ?? 0) + 1, $now, $error);
            $up->execute([$a['key'], $a['kind'], mb_substr($a['text'], 0, 500), $error === null ? date('Y-m-d H:i:s', $now) : null, $err]);
        }
    }

    /** Pure: "try 2 @1760300000: SMTP refused" — the try count and time live in the error column (no DDL). */
    public static function failureNote(int $tries, int $at, string $error): string
    {
        return mb_substr('try ' . $tries . ' @' . $at . ': ' . $error, 0, 255);
    }

    /** @return array{tries:int, at:int} — an old-style error with no count counts as one try, long ago. */
    public static function failures(string $error): array
    {
        if (preg_match('/^try (\d+) @(\d+):/', $error, $m)) return ['tries' => (int)$m[1], 'at' => (int)$m[2]];
        return ['tries' => $error === '' ? 0 : 1, 'at' => 0];
    }

    public static function retryDue(array $f, int $now): bool
    {
        if ($f['tries'] >= self::MAX_TRIES) return false;
        return $f['tries'] === 0 || $now - $f['at'] >= self::RETRY_GAP_SECONDS;
    }

    /** Pure: the interruption email (subject + inner HTML; wrap with EmailWrapper). */
    public static function email(array $alerts, string $name, string $baseUrl): array
    {
        $base = rtrim($baseUrl, '/');
        $e = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $one = count($alerts) === 1;
        $subject = $one ? 'Needs you now: ' . CharlieVoice::short($alerts[0]['text'], 70) : count($alerts) . ' things need you now';
        $font = "font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;";
        $html = '<p style="margin:0 0 14px;font-size:15px;color:#0D3B2E;' . $font . '">' . $e(CharlieVoice::hey($name))
              . ($one ? ' this one can\'t wait for tomorrow\'s brief:' : ' these can\'t wait for tomorrow\'s brief:') . '</p><ul style="margin:0 0 14px;padding-left:18px;font-size:15px;line-height:1.5;color:#0D3B2E;' . $font . '">';
        foreach ($alerts as $a) {
            $url = preg_match('#^https?://#', $a['url']) ? $a['url'] : $base . '/' . ltrim($a['url'], '/');
            $html .= '<li><a href="' . $e($url) . '" style="color:#0D3B2E;">' . $e($a['text']) . '</a></li>';
        }
        $html .= '</ul><p style="margin:0;font-size:13px;color:#4a6b5d;' . $font . '">I only email like this for payment failures, invoices that could not be sent and same-day weather. — Charlie</p>';
        return ['subject' => $subject, 'body' => $html];
    }
}
