<?php
/**
 * SnowWeeklyStatementService — one Monday statement per billing inbox for last
 * week's salt & snow runs.
 *
 * Every run is its own invoice, emailed with its Winter Service Record (the
 * liability record). A property manager with a dozen buildings can get dozens of
 * those a week, so on Monday they also get one statement: every run from last
 * Monday to Sunday, building by building, with the total — so they can pay the
 * week in one transfer if that suits them (owner, 2026-10-08).
 *
 * Runs from the already-scheduled generate_visits cron (a new cron line would sit
 * dormant until someone added it in cPanel). snow_weekly_statements makes it
 * once per inbox per week, whichever run gets there first.
 *
 * Global-namespace service: require_once the file. Pure rules are static + tested.
 */
declare(strict_types=1);

class SnowWeeklyStatementService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // ══════════════════════════════════════════════════════════════════════
    // PURE RULES (unit-tested)
    // ══════════════════════════════════════════════════════════════════════

    /**
     * The week a statement covers: the last full Monday–Sunday before $today.
     * @return array{start:string,end:string}
     */
    public static function lastWeek(string $today): array
    {
        $t     = new DateTimeImmutable($today);
        $thisMonday = $t->modify('monday this week');
        $start = $thisMonday->modify('-7 days');
        return ['start' => $start->format('Y-m-d'), 'end' => $thisMonday->modify('-1 day')->format('Y-m-d')];
    }

    /**
     * Group run invoices by the inbox they were billed to.
     *
     * @param array $rows each: email, invoice_id, invoice_number, visit_date, address, service, amount, balance, link
     * @return array<string, array{email:string, lines:array, total:float, balance:float}>
     */
    public static function group(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $email = strtolower(trim((string)($r['email'] ?? '')));
            if ($email === '') {
                continue;
            }
            if (!isset($out[$email])) {
                $out[$email] = ['email' => $email, 'lines' => [], 'total' => 0.0, 'balance' => 0.0];
            }
            $out[$email]['lines'][] = $r;
            $out[$email]['total']   = round($out[$email]['total'] + (float)$r['amount'], 2);
            $out[$email]['balance'] = round($out[$email]['balance'] + (float)$r['balance'], 2);
        }
        foreach ($out as &$g) {
            usort($g['lines'], static fn($a, $b) => [$a['visit_date'], $a['address']] <=> [$b['visit_date'], $b['address']]);
        }
        unset($g);
        return $out;
    }

    /** Email subject: "Salt & snow runs, Nov 9-15: 7 runs, $734.20". */
    public static function subject(array $week, array $group): string
    {
        $s = new DateTimeImmutable($week['start']);
        $e = new DateTimeImmutable($week['end']);
        $range = $s->format('M j') . '-' . ($s->format('M') === $e->format('M') ? $e->format('j') : $e->format('M j'));
        $n = count($group['lines']);
        return 'Salt & snow runs, ' . $range . ': ' . $n . ' run' . ($n === 1 ? '' : 's') . ', $' . number_format($group['total'], 2);
    }

    /** The statement body (inner HTML; EmailWrapper adds the branded shell). */
    public static function bodyHtml(array $week, array $group): string
    {
        $esc = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $p   = "margin:0 0 14px;font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;font-size:15px;line-height:1.6;color:#0D3B2E;";
        $td  = 'padding:6px 8px;border-bottom:1px solid #e5ede9;font-size:13px;color:#0D3B2E;vertical-align:top;';
        $th  = 'padding:6px 8px;border-bottom:2px solid #2D8659;font-size:12px;color:#4a6b5d;text-align:left;';
        $s   = new DateTimeImmutable($week['start']);
        $e   = new DateTimeImmutable($week['end']);

        $html  = '<p style="' . $p . '">Here are last week\'s salt and snow runs, ' . $esc($s->format('l M j')) . ' to ' . $esc($e->format('l M j')) . '.</p>';
        $html .= '<p style="' . $p . '">Each run is its own invoice and went to you on the day with its Winter Service Record: the weather we acted on, the GPS track and the photos. If it is easier, pay the week in one transfer for the total below and quote the invoice numbers.</p>';
        $html .= '<table role="presentation" cellspacing="0" cellpadding="0" border="0" style="width:100%;border-collapse:collapse;margin:0 0 16px;">';
        $html .= '<tr><th style="' . $th . '">Date</th><th style="' . $th . '">Building</th><th style="' . $th . '">Service</th><th style="' . $th . '">Invoice</th><th style="' . $th . 'text-align:right;">Amount</th></tr>';
        foreach ($group['lines'] as $l) {
            $inv = $esc($l['invoice_number']);
            if (!empty($l['link'])) {
                $inv = '<a href="' . $esc($l['link']) . '" style="color:#1A5F4A;">' . $inv . '</a>';
            }
            $html .= '<tr><td style="' . $td . 'white-space:nowrap;">' . $esc((new DateTimeImmutable($l['visit_date']))->format('D M j')) . '</td>'
                   . '<td style="' . $td . '">' . $esc($l['address']) . '</td>'
                   . '<td style="' . $td . '">' . $esc($l['service']) . '</td>'
                   . '<td style="' . $td . 'white-space:nowrap;">' . $inv . '</td>'
                   . '<td style="' . $td . 'text-align:right;white-space:nowrap;">$' . number_format((float)$l['amount'], 2) . '</td></tr>';
        }
        $html .= '<tr><td colspan="4" style="' . $td . 'font-weight:700;">Total for the week (incl. GST)</td>'
               . '<td style="' . $td . 'text-align:right;font-weight:700;white-space:nowrap;">$' . number_format($group['total'], 2) . '</td></tr>';
        if (abs($group['balance'] - $group['total']) >= 0.01) {
            $html .= '<tr><td colspan="4" style="' . $td . '">Still to pay</td>'
                   . '<td style="' . $td . 'text-align:right;white-space:nowrap;">$' . number_format($group['balance'], 2) . '</td></tr>';
        }
        $html .= '</table>';
        return $html;
    }

    // ══════════════════════════════════════════════════════════════════════
    // DB + send
    // ══════════════════════════════════════════════════════════════════════

    /** Run invoices for visits in the week, one row per (invoice, billing email). */
    public function runInvoices(array $week): array
    {
        $stmt = $this->db->prepare("
            SELECT ic.email_address AS email, i.id AS invoice_id, i.invoice_number, i.access_token,
                   jv.scheduled_date AS visit_date, COALESCE(p.address, i.service_address) AS address,
                   i.total AS amount, i.balance_due AS balance, vsc.choice
            FROM invoices i
            JOIN job_visits jv ON jv.id = i.visit_id
            JOIN job_plans jp  ON jp.id = jv.plan_id
            LEFT JOIN properties p ON p.id = jp.property_id
            JOIN invoice_contacts ic ON ic.invoice_id = i.id AND TRIM(ic.email_address) <> ''
            LEFT JOIN visit_service_choices vsc ON vsc.visit_id = jv.id
            WHERE jv.scheduled_date BETWEEN ? AND ?
              AND i.status NOT IN ('draft', 'cancelled', 'void')
              AND EXISTS (SELECT 1 FROM snow_route_rates r WHERE r.plan_id = jv.plan_id)
            ORDER BY jv.scheduled_date, p.address
        ");
        $stmt->execute([$week['start'], $week['end']]);
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $r['service'] = $r['choice'] ? SnowContractService::choiceLabel((string)$r['choice']) : 'Salt & snow';
            $r['link']    = $r['access_token'] ? 'https://mowology.ca/customer/invoice.php?token=' . urlencode((string)$r['access_token']) : null;
            $rows[] = $r;
        }
        return $rows;
    }

    /**
     * Send last week's statements that haven't gone yet. Safe to call every cron run.
     * @return array{week:array, sent:int, skipped:int, failed:int}
     */
    public function sendDue(string $today, ?callable $mailer = null): array
    {
        $week = self::lastWeek($today);
        $out  = ['week' => $week, 'sent' => 0, 'skipped' => 0, 'failed' => 0];
        if (!$this->enabled()) {
            return $out + ['disabled' => true];
        }
        foreach (self::group($this->runInvoices($week)) as $email => $group) {
            $done = $this->db->prepare("SELECT 1 FROM snow_weekly_statements WHERE recipient_email = ? AND week_start = ?");
            $done->execute([$email, $week['start']]);
            if ($done->fetchColumn()) {
                $out['skipped']++;
                continue;
            }
            $subject = self::subject($week, $group);
            if (!class_exists('EmailWrapper')) {
                require_once APP_ROOT . '/Services/Messaging/EmailWrapper.php';
            }
            $company = EmailWrapper::getCompanyInfo();
            $html    = EmailWrapper::wrap(self::bodyHtml($week, $group) . EmailWrapper::paymentInstructionsHtml(), null, null, $company);
            if (!$mailer && !function_exists('sendCrmEmail')) {
                require_once APP_ROOT . '/Services/Messaging/MessagingService.php';
            }
            $ok = $mailer ? (bool)$mailer($email, $subject, $html) : sendCrmEmail($email, $subject, $html);
            if (!$ok) {
                $out['failed']++;
                error_log("[snow weekly statement] send failed to {$email} for week {$week['start']}");
                continue;
            }
            $this->db->prepare("
                INSERT INTO snow_weekly_statements (recipient_email, week_start, runs, total, sent_at)
                VALUES (?, ?, ?, ?, NOW())
            ")->execute([$email, $week['start'], count($group['lines']), $group['total']]);
            $out['sent']++;
        }
        return $out;
    }

    private function enabled(): bool
    {
        try {
            $stmt = $this->db->prepare("SELECT setting_value FROM ops_settings WHERE setting_key = 'snow_weekly_statement_enabled'");
            $stmt->execute();
            $v = $stmt->fetchColumn();
            return $v === false ? false : (string)$v === '1';
        } catch (Throwable $e) {
            return false; // migration 1311 not run
        }
    }
}
