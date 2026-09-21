<?php
declare(strict_types=1);

/**
 * ClientVisibilityService — what a CLIENT is allowed to see about a visit.
 *
 * Contract clients (the plan belongs to a contract) pay for an outcome over a season, not for
 * hours. Showing them "4h 10m on site" or an arrive/depart pair invites the wrong conversation,
 * so two things are switchable for contract work, both OFF unless the office turns them on
 * (Settings → Client Portal):
 *
 *   contract_client_show_visit_report   the GPS-verified Service Visit Report page
 *   contract_client_show_visit_length   how long the crew was on site — the duration AND anything
 *                                       it can be worked out from (arrival + departure times,
 *                                       per-ping timestamps)
 *
 * Non-contract clients are unaffected. Staff previews always see everything.
 * The salt / snow liability report is a separate document and is NOT governed by this.
 *
 * One rule, asked by every client-facing surface: the portal, the visit report, the proof-of-work
 * page and PDF, and the completion email.
 */
class ClientVisibilityService
{
    public const KEY_REPORT = 'contract_client_show_visit_report';
    public const KEY_LENGTH = 'contract_client_show_visit_length';

    private PDO $db;
    /** @var array{report:bool,length:bool}|null */
    private ?array $settings = null;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * @return array{report:bool, length:bool}
     */
    public static function decide(bool $isContract, bool $showReportSetting, bool $showLengthSetting): array
    {
        if (!$isContract) {
            return ['report' => true, 'length' => true];
        }
        return ['report' => $showReportSetting, 'length' => $showLengthSetting];
    }

    /** A stored setting counts as ON only when it is explicitly truthy; missing = OFF. */
    public static function isOn($value): bool
    {
        return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
    }

    /** @return array{report:bool, length:bool} */
    public function settings(): array
    {
        if ($this->settings !== null) {
            return $this->settings;
        }
        $values = [];
        try {
            $stmt = $this->db->prepare("SELECT setting_key, setting_value FROM ops_settings WHERE setting_key IN (?, ?)");
            $stmt->execute([self::KEY_REPORT, self::KEY_LENGTH]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $values[$row['setting_key']] = $row['setting_value'];
            }
        } catch (Throwable $e) {
            // Fail CLOSED: if we cannot read the switch, a contract client sees less, not more.
            error_log('ClientVisibilityService: settings unreadable: ' . $e->getMessage());
        }
        return $this->settings = [
            'report' => self::isOn($values[self::KEY_REPORT] ?? ''),
            'length' => self::isOn($values[self::KEY_LENGTH] ?? ''),
        ];
    }

    /** For a row that already carries the plan's contract_id. */
    public function forContractId($contractId): array
    {
        $s = $this->settings();
        return self::decide(!empty($contractId), $s['report'], $s['length']);
    }

    /** For a visit id. An unknown visit is treated as contract work (show less). */
    public function forVisit(int $visitId): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT jp.contract_id FROM job_visits jv JOIN job_plans jp ON jp.id = jv.plan_id WHERE jv.id = ?
            ");
            $stmt->execute([$visitId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $this->forContractId($row === false ? 1 : $row['contract_id']);
        } catch (Throwable $e) {
            error_log('ClientVisibilityService: visit lookup failed: ' . $e->getMessage());
            return $this->forContractId(1);
        }
    }
}
