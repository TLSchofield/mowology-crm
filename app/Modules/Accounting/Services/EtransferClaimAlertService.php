<?php
/**
 * EtransferClaimAlertService — Penny pushes the owner when an e-Transfer has to be
 * claimed by hand ("Select your financial institution to deposit funds"), with the
 * deposit link, and reminds him 7 and 2 days before it expires (owner, 2026-10-08).
 *
 * An auto-deposited transfer lands in the bank by itself; a claim-type one sits at
 * Interac and goes back to the sender when it expires. Penny already reads both kinds
 * (EtransferInboxService) — this turns the claim ones into a push the owner can act on.
 *
 * Penny never deposits anything: the push only opens Interac's own deposit page.
 * Stops as soon as the bank shows the deposit, the owner marks it deposited, or it expires.
 *
 * Global-namespace service: require_once the file. Pure rules are static + tested.
 */
declare(strict_types=1);

class EtransferClaimAlertService
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
     * Which push a claim-type transfer is due today: 'new', 'remind7', 'remind2' or null.
     * Row fields: deposited_at, status, expires_on, claim_alerted_at, claim_reminded.
     */
    public static function due(array $row, string $today): ?string
    {
        if (!empty($row['deposited_at']) || ($row['status'] ?? '') === 'dismissed') {
            return null;
        }
        $expires = (string)($row['expires_on'] ?? '');
        if ($expires !== '' && $today > $expires) {
            return null; // gone back to the sender; nothing to tap
        }
        if (empty($row['claim_alerted_at'])) {
            return 'new';
        }
        if ($expires === '') {
            return null;
        }
        $days     = (int)floor((strtotime($expires) - strtotime($today)) / 86400);
        $reminded = (int)($row['claim_reminded'] ?? 0);
        if ($days <= 2 && $reminded < 2) return 'remind2';
        if ($days <= 7 && $reminded < 1) return 'remind7';
        return null;
    }

    /** [title, body] for the push. PushHeads turns "Penny: …" into "Penny [Books]". */
    public static function message(array $row, string $kind, string $today): array
    {
        $amount = '$' . number_format((float)($row['amount'] ?? 0), 2);
        $who    = trim((string)($row['sender_name'] ?? '')) ?: 'someone';
        $who    = ucwords(strtolower($who));
        $exp    = !empty($row['expires_on']) ? date('M j', strtotime((string)$row['expires_on'])) : null;
        if ($kind === 'new') {
            return ['Penny: deposit ' . $amount,
                    $amount . ' from ' . $who . ' needs depositing' . ($exp ? ' (expires ' . $exp . ')' : '') . '. Tap to deposit.'];
        }
        $days = !empty($row['expires_on']) ? (int)floor((strtotime((string)$row['expires_on']) - strtotime($today)) / 86400) : null;
        $when = $days === null ? 'soon' : ($days <= 0 ? 'today' : 'in ' . $days . ' day' . ($days === 1 ? '' : 's'));
        return ['Penny: still to deposit ' . $amount,
                $amount . ' from ' . $who . ' expires ' . $when . ($exp ? ' (' . $exp . ')' : '') . '. Tap to deposit.'];
    }

    /** Push payload: old app builds open Penny's card; 1.3.10+ opens the deposit link. */
    public static function payload(array $row): array
    {
        $data = ['open' => 'team', 'head' => 'penny', 'type' => 'etransfer_claim', 'notification_id' => (int)($row['id'] ?? 0)];
        $url  = (string)($row['deposit_url'] ?? '');
        if ($url !== '' && preg_match('#^https://etransfer\.interac\.ca/#i', $url)) {
            $data['url'] = $url;
        }
        return $data;
    }

    // ══════════════════════════════════════════════════════════════════════
    // DB
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Check every live claim-type transfer, mark the deposited ones, push what's due.
     * @return array{checked:int, deposited:int, pushed:int, disabled?:bool}
     */
    public function run(?string $today = null): array
    {
        $today = $today ?: date('Y-m-d');
        $out   = ['checked' => 0, 'deposited' => 0, 'pushed' => 0];
        if (!$this->enabled()) {
            return $out + ['disabled' => true];
        }
        try {
            $rows = $this->db->query("
                SELECT * FROM etransfer_notifications
                WHERE transfer_type = 'claim' AND deposited_at IS NULL AND status <> 'dismissed'
                  AND (expires_on IS NULL OR expires_on >= CURDATE() - INTERVAL 1 DAY)
                  AND created_at >= NOW() - INTERVAL 45 DAY
                ORDER BY id
            ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return $out + ['disabled' => true]; // migration 1312 not run
        }

        $recipients = $this->recipients();
        foreach ($rows as $row) {
            $out['checked']++;
            if ($this->bankShowsDeposit($row)) {
                $this->db->prepare("UPDATE etransfer_notifications SET deposited_at = NOW() WHERE id = ?")->execute([(int)$row['id']]);
                $out['deposited']++;
                continue;
            }
            $kind = self::due($row, $today);
            if ($kind === null || !$recipients) {
                continue;
            }
            [$title, $body] = self::message($row, $kind, $today);
            $this->push($recipients, $title, $body, self::payload($row));
            if ($kind === 'new') {
                $this->db->prepare("UPDATE etransfer_notifications SET claim_alerted_at = NOW() WHERE id = ?")->execute([(int)$row['id']]);
            } else {
                $this->db->prepare("UPDATE etransfer_notifications SET claim_reminded = ? WHERE id = ?")
                         ->execute([$kind === 'remind2' ? 2 : 1, (int)$row['id']]);
            }
            $out['pushed']++;
        }
        return $out;
    }

    /** The owner says it's deposited (or it's dealt with another way): no more pushes. */
    public function markDeposited(int $id): bool
    {
        $st = $this->db->prepare("UPDATE etransfer_notifications SET deposited_at = NOW() WHERE id = ? AND transfer_type = 'claim'");
        $st->execute([$id]);
        return $st->rowCount() > 0;
    }

    /** A test push to one user, using the newest claim with a deposit link when there is one. */
    public function testPush(int $userId): array
    {
        $row = null;
        try {
            $row = $this->db->query("
                SELECT * FROM etransfer_notifications
                WHERE transfer_type = 'claim' AND deposit_url IS NOT NULL
                ORDER BY id DESC LIMIT 1
            ")->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) {
        }
        $row = $row ?: ['id' => 0, 'amount' => 232.36, 'sender_name' => 'TOVE MARIE PASHKOWSKI',
                        'expires_on' => date('Y-m-d', strtotime('+14 days')), 'deposit_url' => 'https://etransfer.interac.ca/'];
        [$title, $body] = self::message($row, 'new', date('Y-m-d'));
        $this->push([$userId], $title, 'TEST — ' . $body, self::payload($row));
        return ['title' => $title, 'body' => 'TEST — ' . $body, 'has_link' => isset(self::payload($row)['url'])];
    }

    /**
     * Has the bank shown this deposit? Penny's own bank link, else an imported e-Transfer
     * credit of the same amount dated from the email to expiry, with the sender's name on
     * the bank line or as the only candidate in that window.
     */
    private function bankShowsDeposit(array $row): bool
    {
        if (!empty($row['bank_transaction_id'])) {
            return true;
        }
        $from = substr((string)($row['email_date'] ?: $row['created_at']), 0, 10);
        $to   = (string)($row['expires_on'] ?: date('Y-m-d', strtotime($from . ' +31 days')));
        $st = $this->db->prepare("
            SELECT at.id, at.description FROM accounting_transactions at
            WHERE at.reference_type = 'bank_import' AND at.amount > 0
              AND ABS(at.amount - ?) < 0.01
              AND at.transaction_date BETWEEN DATE_SUB(?, INTERVAL 1 DAY) AND ?
              AND at.description LIKE '%e-Transfer%'
        ");
        $st->execute([(float)$row['amount'], $from, $to]);
        $hits = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (!$hits) {
            return false;
        }
        $words = array_filter(preg_split('/\s+/', strtolower((string)$row['sender_name'])) ?: [], fn($w) => strlen($w) >= 3);
        foreach ($hits as $h) {
            foreach ($words as $w) {
                if (stripos((string)$h['description'], $w) !== false) return true;
            }
        }
        return count($hits) === 1;
    }

    /** Admin users get Penny's deposit pushes. */
    private function recipients(): array
    {
        try {
            return array_map('intval', $this->db->query("SELECT id FROM users WHERE role = 'admin' AND is_active = 1")->fetchAll(PDO::FETCH_COLUMN) ?: []);
        } catch (Throwable $e) {
            return [];
        }
    }

    private function push(array $userIds, string $title, string $body, array $data): void
    {
        if (!class_exists('PushDispatcher')) {
            require_once APP_ROOT . '/Services/Push/PushDispatcher.php';
        }
        PushDispatcher::notifyUsers($userIds, $title, $body, $data);
        // Send now rather than waiting for the push-drain cron: an expiring deposit is
        // exactly the push that shouldn't sit in a queue.
        try {
            if (!class_exists('ApnsService')) {
                require_once APP_ROOT . '/Services/Push/ApnsService.php';
            }
            PushDispatcher::drainQueue();
        } catch (Throwable $e) {
            error_log('[penny claim alert] drain failed: ' . $e->getMessage());
        }
    }

    private function enabled(): bool
    {
        try {
            $st = $this->db->prepare("SELECT setting_value FROM ops_settings WHERE setting_key = 'penny_claim_alerts_enabled'");
            $st->execute();
            $v = $st->fetchColumn();
            return $v !== false && (string)$v === '1';
        } catch (Throwable $e) {
            return false;
        }
    }
}
