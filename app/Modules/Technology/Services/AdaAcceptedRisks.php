<?php
declare(strict_types=1);

/**
 * AdaAcceptedRisks — the rows behind "Accept for 90 days" on System Health (migration 1431).
 * One open acceptance per system; released when Tim says so or when the check clears.
 * Decisions (level, can it be accepted, review date) are AdaRiskRegister's, pure and tested.
 *
 * No namespace / no autoloader: require_once and `new AdaAcceptedRisks($db)`.
 */
require_once __DIR__ . '/AdaRiskRegister.php';

class AdaAcceptedRisks
{
    private PDO $db;
    private ?bool $ready = null;

    public function __construct(PDO $db) { $this->db = $db; }

    public function ready(): bool
    {
        if ($this->ready !== null) return $this->ready;
        try {
            $this->db->query("SELECT 1 FROM ada_accepted_risks LIMIT 1");
            return $this->ready = true;
        } catch (Throwable $e) {
            return $this->ready = false;
        }
    }

    /** Accept a risk; replaces an open acceptance on the same system. */
    public function accept(string $system, string $reason, ?int $userId, string $userName, string $now, int $days = AdaRiskRegister::REVIEW_DAYS): bool
    {
        if (!$this->ready() || $system === '' || AdaRiskRegister::reasonProblem($reason) !== null) return false;
        $this->db->prepare("UPDATE ada_accepted_risks SET released_at = ?, released_how = 'replaced' WHERE system_key = ? AND released_at IS NULL")->execute([$now, $system]);
        $this->db->prepare("INSERT INTO ada_accepted_risks (system_key, reason, accepted_by, accepted_by_name, accepted_at, review_by) VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([$system, mb_substr(trim(preg_replace('/\s+/', ' ', $reason)), 0, AdaRiskRegister::REASON_MAX), $userId, mb_substr($userName, 0, 80), $now, AdaRiskRegister::reviewBy($now, $days)]);
        return true;
    }

    /** @return array<string, array{reason: string, accepted_by_name: string, accepted_at: string, review_by: string}> open acceptances by system */
    public function open(): array
    {
        if (!$this->ready()) return [];
        $out = [];
        foreach ($this->db->query("SELECT system_key, reason, accepted_by_name, accepted_at, review_by FROM ada_accepted_risks WHERE released_at IS NULL ORDER BY id") as $r) {
            $out[(string)$r['system_key']] = ['reason' => (string)$r['reason'], 'accepted_by_name' => (string)$r['accepted_by_name'],
                                              'accepted_at' => (string)$r['accepted_at'], 'review_by' => (string)$r['review_by']];
        }
        return $out;
    }

    /** Release: 'cleared' when the check went green on its own, 'released' when Tim pressed the button. */
    public function release(string $system, string $how, string $now): void
    {
        if (!$this->ready() || !in_array($how, ['cleared', 'released'], true)) return;
        $this->db->prepare("UPDATE ada_accepted_risks SET released_at = ?, released_how = ? WHERE system_key = ? AND released_at IS NULL")->execute([$now, $how, $system]);
    }
}
