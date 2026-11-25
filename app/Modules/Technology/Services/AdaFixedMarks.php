<?php
/**
 * AdaFixedMarks — "Mark fixed" on Ada's System Health page (migration 1420).
 *
 * Tim marks a problem fixed; Ada then judges that job only on runs after the mark, says she is
 * waiting for its next run, and writes down how it turned out (cleared / came back). Ada's
 * checks still work without the table: every method fails soft and the page just has no button.
 * No namespace / no autoloader: require_once and `new AdaFixedMarks($db)`.
 */
class AdaFixedMarks
{
    private PDO $db;
    private ?bool $ready = null;

    public function __construct(PDO $db) { $this->db = $db; }

    public function ready(): bool
    {
        if ($this->ready !== null) return $this->ready;
        try {
            $this->db->query("SELECT 1 FROM ada_fixed_marks LIMIT 1");
            return $this->ready = true;
        } catch (Throwable $e) {
            return $this->ready = false;
        }
    }

    /** Record a mark. Replaces an open mark on the same system so there is only ever one. */
    public function mark(string $system, string $problem, string $evidence, ?int $userId, string $now): bool
    {
        if (!$this->ready() || $system === '') return false;
        $this->db->prepare("DELETE FROM ada_fixed_marks WHERE system_key = ? AND outcome IS NULL")->execute([$system]);
        $this->db->prepare("INSERT INTO ada_fixed_marks (system_key, problem, evidence_at_fix, fixed_at, fixed_by) VALUES (?, ?, ?, ?, ?)")
            ->execute([$system, mb_substr($problem, 0, 40), mb_substr($evidence, 0, 500), $now, $userId]);
        return true;
    }

    /** @return array<string, array{id: int, fixed_at: string}> open marks by system */
    public function open(): array
    {
        if (!$this->ready()) return [];
        $out = [];
        foreach ($this->db->query("SELECT id, system_key, fixed_at FROM ada_fixed_marks WHERE outcome IS NULL") as $r) {
            $out[(string)$r['system_key']] = ['id' => (int)$r['id'], 'fixed_at' => (string)$r['fixed_at']];
        }
        return $out;
    }

    public function close(int $id, string $outcome, string $evidence, string $now): void
    {
        if (!$this->ready() || !in_array($outcome, ['cleared', 'came_back'], true)) return;
        $this->db->prepare("UPDATE ada_fixed_marks SET outcome = ?, outcome_at = ?, outcome_evidence = ? WHERE id = ? AND outcome IS NULL")
            ->execute([$outcome, $now, mb_substr($evidence, 0, 500), $id]);
    }

    /** Most recent finished rechecks, newest first. */
    public function recent(int $limit = 8): array
    {
        if (!$this->ready()) return [];
        $st = $this->db->prepare("SELECT system_key AS system, problem, evidence_at_fix, fixed_at, outcome, outcome_at, outcome_evidence
                                  FROM ada_fixed_marks WHERE outcome IS NOT NULL ORDER BY outcome_at DESC LIMIT " . max(1, min(50, $limit)));
        $st->execute();
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
}
