<?php
declare(strict_types=1);

/**
 * VisitEndorsementService — the crew's "heart" on a visit, one per person.
 *
 * A stop can carry several crew members. Each gets their own endorsement
 * (visit_endorsements, migration 1121). job_visits.is_flagged stays as the
 * COMBINED flag — true while anyone endorses — because review requests, the
 * portfolio queue and the social pipeline all read it.
 *
 * Until migration 1121 has run the table does not exist. The service then falls
 * back to the single flag, with one safety rule: a crew member can switch it ON
 * but only an admin can switch it OFF, so one person's tap can no longer erase
 * another person's endorsement.
 */
class VisitEndorsementService
{
    private PDO $db;
    private ?bool $hasTable = null;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** True once migration 1121 has been run. */
    public function perCrewEnabled(): bool
    {
        if ($this->hasTable === null) {
            try {
                $this->db->query("SELECT 1 FROM visit_endorsements LIMIT 1");
                $this->hasTable = true;
            } catch (Throwable $e) {
                $this->hasTable = false;
            }
        }
        return $this->hasTable;
    }

    /**
     * Toggle this user's endorsement.
     *
     * @return array{mine:bool,endorsed:bool,endorsed_by:string[],changed:bool,newly_endorsed:bool}
     *   mine           — this user endorses the visit after the call
     *   endorsed       — anyone does (the combined flag)
     *   newly_endorsed — the visit went from nobody to somebody: fire downstream hooks once
     * @throws Exception If the visit doesn't exist.
     */
    public function toggle(int $visitId, int $userId, bool $isAdmin = false): array
    {
        $stmt = $this->db->prepare("SELECT id, is_flagged FROM job_visits WHERE id = ?");
        $stmt->execute([$visitId]);
        $visit = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$visit) {
            throw new Exception('Visit not found');
        }
        $wasEndorsed = (bool)$visit['is_flagged'];

        if (!$this->perCrewEnabled()) {
            return $this->toggleSingleFlag($visitId, $wasEndorsed, $isAdmin);
        }

        $stmt = $this->db->prepare("SELECT id FROM visit_endorsements WHERE visit_id = ? AND user_id = ?");
        $stmt->execute([$visitId, $userId]);
        $mineId = $stmt->fetchColumn();

        if ($mineId) {
            $this->db->prepare("DELETE FROM visit_endorsements WHERE id = ?")->execute([$mineId]);
            $mine = false;
        } else {
            $this->db->prepare(
                "INSERT INTO visit_endorsements (visit_id, user_id, created_at) VALUES (?, ?, NOW())"
            )->execute([$visitId, $userId]);
            $mine = true;
        }

        $names    = $this->namesForVisit($visitId);
        $endorsed = count($names) > 0 || $this->countForVisit($visitId) > 0;
        $this->db->prepare("UPDATE job_visits SET is_flagged = ? WHERE id = ?")
                 ->execute([$endorsed ? 1 : 0, $visitId]);

        return [
            'mine'           => $mine,
            'endorsed'       => $endorsed,
            'endorsed_by'    => $names,
            'changed'        => true,
            'newly_endorsed' => $endorsed && !$wasEndorsed,
        ];
    }

    /** Pre-migration behaviour: crew can only switch the flag on. */
    private function toggleSingleFlag(int $visitId, bool $wasEndorsed, bool $isAdmin): array
    {
        if ($wasEndorsed && !$isAdmin) {
            return ['mine' => true, 'endorsed' => true, 'endorsed_by' => [], 'changed' => false, 'newly_endorsed' => false];
        }
        $new = !$wasEndorsed;
        $this->db->prepare("UPDATE job_visits SET is_flagged = ? WHERE id = ?")
                 ->execute([$new ? 1 : 0, $visitId]);

        return ['mine' => $new, 'endorsed' => $new, 'endorsed_by' => [], 'changed' => true, 'newly_endorsed' => $new];
    }

    private function countForVisit(int $visitId): int
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM visit_endorsements WHERE visit_id = ?");
        $stmt->execute([$visitId]);
        return (int)$stmt->fetchColumn();
    }

    /** @return string[] names, in the order they endorsed */
    public function namesForVisit(int $visitId): array
    {
        return $this->forVisits([$visitId])[$visitId]['names'] ?? [];
    }

    /**
     * Endorsements for many visits in one read (schedule day payloads).
     *
     * @param int[] $visitIds
     * @return array<int,array{user_ids:int[],names:string[]}> keyed by visit id; visits with
     *         none are absent. Empty before migration 1121.
     */
    public function forVisits(array $visitIds): array
    {
        $visitIds = array_values(array_unique(array_filter(array_map('intval', $visitIds))));
        if (!$visitIds || !$this->perCrewEnabled()) {
            return [];
        }
        $in   = implode(',', array_fill(0, count($visitIds), '?'));
        $stmt = $this->db->prepare("
            SELECT ve.visit_id, ve.user_id, u.full_name
            FROM visit_endorsements ve
            LEFT JOIN users u ON u.id = ve.user_id
            WHERE ve.visit_id IN ($in)
            ORDER BY ve.id ASC
        ");
        $stmt->execute($visitIds);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $vid = (int)$row['visit_id'];
            $out[$vid]['user_ids'][] = (int)$row['user_id'];
            $out[$vid]['names'][]    = trim((string)($row['full_name'] ?? '')) ?: 'Unknown';
        }
        return $out;
    }
}
