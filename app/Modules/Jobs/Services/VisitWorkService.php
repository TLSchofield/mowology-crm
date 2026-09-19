<?php
declare(strict_types=1);

/**
 * VisitWorkService — proof-of-work data a crew member records on a visit:
 * checklist, materials used, and notes.
 *
 * Single source of truth for both surfaces, so they store identical shapes:
 * the mobile JWT endpoint (app/Modules/Schedule/Api/visit-work.php) and the web
 * crew workflow (public/crm/api/pow-actions.php: save_checklist, save_materials,
 * save_notes). Each caller passes its own audit source.
 *
 * Global-namespace, no autoloader: require_once this file, then `new VisitWorkService($db)`.
 */
class VisitWorkService
{
    public const NOTE_TYPES = ['general', 'customer_request', 'issue', 'follow_up', 'internal'];

    public const SOURCE_MOBILE = 'mobile';
    public const SOURCE_WEB    = 'web';

    /** @var PDO */
    private $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // ── Pure helpers ────────────────────────────────────────────────────────

    /** @return array<int, array{item:string, checked:bool, note:string}> */
    public static function sanitizeChecklist(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $label = substr(trim(strip_tags((string)($item['item'] ?? ''))), 0, 255);
            if ($label === '') {
                continue;
            }
            $out[] = [
                'item'    => $label,
                'checked' => !empty($item['checked']),
                'note'    => substr(strip_tags((string)($item['note'] ?? '')), 0, 500),
            ];
        }
        return $out;
    }

    /** @return array<int, array{name:string, qty:?float, unit:string, rate_per_unit:?float, note:string}> */
    public static function sanitizeMaterials(array $items): array
    {
        $out = [];
        foreach ($items as $m) {
            if (!is_array($m)) {
                continue;
            }
            $name = substr(trim(strip_tags((string)($m['name'] ?? ''))), 0, 255);
            if ($name === '') {
                continue;
            }
            $out[] = [
                'name'          => $name,
                'qty'           => is_numeric($m['qty'] ?? '') ? round((float)$m['qty'], 4) : null,
                'unit'          => substr(strip_tags((string)($m['unit'] ?? '')), 0, 50),
                'rate_per_unit' => is_numeric($m['rate_per_unit'] ?? '') ? round((float)$m['rate_per_unit'], 2) : null,
                'note'          => substr(strip_tags((string)($m['note'] ?? '')), 0, 500),
            ];
        }
        return $out;
    }

    /**
     * The checklist to show: what was saved on the visit, else the plan's
     * template seeded as unchecked rows (same rule as jobs/visit-work.php).
     */
    public static function resolveChecklist(?string $savedJson, ?string $templateJson): array
    {
        $saved = $savedJson ? json_decode($savedJson, true) : null;
        if (is_array($saved) && $saved !== []) {
            return self::sanitizeChecklist($saved);
        }
        $template = $templateJson ? json_decode($templateJson, true) : null;
        if (!is_array($template)) {
            return [];
        }
        $rows = [];
        foreach ($template as $t) {
            $rows[] = ['item' => is_array($t) ? ($t['item'] ?? '') : (string)$t, 'checked' => false, 'note' => ''];
        }
        return self::sanitizeChecklist($rows);
    }

    public static function normalizeNoteType(string $type): string
    {
        return in_array($type, self::NOTE_TYPES, true) ? $type : 'general';
    }

    /**
     * Who may record work on a visit: office roles, the assigned crew member,
     * or anyone crewed onto the visit's stop.
     */
    public static function canAccess(array $visit, int $userId, bool $isAdmin, array $stopCrewIds): bool
    {
        if ($isAdmin) {
            return true;
        }
        if ($userId < 1) {
            return false;   // an unassigned visit has crew id 0 — never let that match
        }
        if ((int)($visit['assigned_crew_id'] ?? 0) === $userId) {
            return true;
        }
        if ((int)($visit['stop_crew_id'] ?? 0) === $userId) {
            return true;
        }
        return in_array($userId, array_map('intval', $stopCrewIds), true);
    }

    // ── DB ──────────────────────────────────────────────────────────────────

    public function loadVisit(int $visitId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT v.id, v.stop_id, v.status, v.assigned_crew_id, v.locked_at,
                   v.checklist_json, v.materials_json,
                   p.checklist_template, cs.crew_id AS stop_crew_id
            FROM job_visits v
            JOIN job_plans p ON v.plan_id = p.id
            LEFT JOIN calendar_stops cs ON v.stop_id = cs.id
            WHERE v.id = ?
        ");
        $stmt->execute([$visitId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function stopCrewIds(?int $stopId): array
    {
        if (!$stopId) {
            return [];
        }
        $stmt = $this->db->prepare("SELECT user_id FROM calendar_stop_crew WHERE stop_id = ?");
        $stmt->execute([$stopId]);
        return array_map('intval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'user_id'));
    }

    /** Everything the mobile work screen needs in one read. */
    public function getState(array $visit): array
    {
        $materials = !empty($visit['materials_json']) ? json_decode($visit['materials_json'], true) : [];

        $stmt = $this->db->prepare("
            SELECT vn.id, vn.note_type, vn.content, vn.is_visible_to_customer, vn.created_at,
                   COALESCE(u.full_name, '') AS author
            FROM visit_notes vn
            LEFT JOIN users u ON vn.created_by = u.id
            WHERE vn.visit_id = ?
            ORDER BY vn.created_at DESC, vn.id DESC
        ");
        $stmt->execute([(int)$visit['id']]);

        $notes = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $n) {
            $notes[] = [
                'id'                  => (int)$n['id'],
                'note_type'           => $n['note_type'],
                'content'             => $n['content'],
                'visible_to_customer' => (bool)$n['is_visible_to_customer'],
                'created_at'          => $n['created_at'],
                'author'              => $n['author'] !== '' ? $n['author'] : null,
            ];
        }

        return [
            'visit_id'  => (int)$visit['id'],
            'locked'    => $visit['locked_at'] !== null,
            'checklist' => self::resolveChecklist($visit['checklist_json'] ?? null, $visit['checklist_template'] ?? null),
            'materials' => self::sanitizeMaterials(is_array($materials) ? $materials : []),
            'notes'     => $notes,
        ];
    }

    public function saveChecklist(int $visitId, int $userId, array $items, ?string $ip = null, string $source = self::SOURCE_MOBILE): int
    {
        $clean = self::sanitizeChecklist($items);
        $json  = json_encode($clean);
        $this->db->prepare("
            UPDATE job_visits SET
                checklist_json = ?,
                checklist_completed_at = NOW(),
                checklist_completed_by = ?,
                checklist_completed = ?
            WHERE id = ?
        ")->execute([$json, $userId, $json, $visitId]);

        $this->audit($visitId, $userId, 'checklist_saved', ['count' => count($clean), 'source' => $source], $ip);
        return count($clean);
    }

    public function saveMaterials(int $visitId, int $userId, array $items, ?string $ip = null, string $source = self::SOURCE_MOBILE): int
    {
        $clean = self::sanitizeMaterials($items);
        $this->db->prepare("UPDATE job_visits SET materials_json = ? WHERE id = ?")
                 ->execute([json_encode($clean), $visitId]);

        $this->audit($visitId, $userId, 'materials_saved', ['count' => count($clean), 'source' => $source], $ip);
        return count($clean);
    }

    /** @throws InvalidArgumentException when the note is empty */
    public function addNote(int $visitId, int $userId, string $content, string $noteType, bool $visibleToCustomer): int
    {
        $content = trim($content);
        if ($content === '') {
            throw new InvalidArgumentException('Note content required');
        }
        $this->db->prepare("
            INSERT INTO visit_notes (visit_id, note_type, content, is_visible_to_customer, created_by)
            VALUES (?, ?, ?, ?, ?)
        ")->execute([$visitId, self::normalizeNoteType($noteType), $content, $visibleToCustomer ? 1 : 0, $userId]);

        return (int)$this->db->lastInsertId();
    }

    private function audit(int $visitId, int $userId, string $action, array $payload, ?string $ip): void
    {
        try {
            $this->db->prepare("
                INSERT INTO visit_audit_log (visit_id, user_id, action, payload_json, ip_address)
                VALUES (?, ?, ?, ?, ?)
            ")->execute([$visitId, $userId, $action, json_encode($payload), $ip ? substr($ip, 0, 45) : null]);
        } catch (Throwable $e) {
            // The audit trail must never block the crew's save.
            error_log("VisitWorkService audit failed for visit {$visitId}: " . $e->getMessage());
        }
    }
}
