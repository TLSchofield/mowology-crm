<?php
/**
 * TaskCreateService — a task made on the phone (Add → Task, 2026-11-08).
 *
 * For a client or for the business itself ("pick up salt": no client). Writes the same `tasks` row the web's
 * /crm/api/tasks.php action=create writes for a general task; purchase tasks (vendor, line items) stay on the web.
 * Pure of the request: the endpoint passes the decoded body and the signed-in user.
 */
declare(strict_types=1);

class TaskCreateService
{
    /** @var PDO */
    private $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** Only office roles hand a task to someone else; crew tasks are their own. */
    public static function canAssignOthers(array $user): bool
    {
        return in_array((string)($user['role'] ?? ''), ['admin', 'manager', 'owner'], true);
    }

    /**
     * What the form offers: the active people a task can go to.
     * @return array<int, array{id:int, name:string}>
     */
    public function people(array $user): array
    {
        if (!self::canAssignOthers($user)) {
            return [['id' => (int)$user['id'], 'name' => (string)($user['name'] ?? 'Me')]];
        }
        $rows = $this->db->query("SELECT id, full_name FROM users WHERE is_active = 1 ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);
        return array_map(static function ($r) { return ['id' => (int)$r['id'], 'name' => (string)$r['full_name']]; }, $rows);
    }

    /**
     * Clients and their properties, for picking one with no signal. The endpoint only calls this for clients.view:
     * crew phones never hold the whole client list.
     * @return array<int, array{contact_id:int, name:string, property_id:int, address:string}>
     */
    public function directory(): array
    {
        $rows = $this->db->query("
            SELECT p.id AS property_id, p.address, p.city, c.id AS contact_id, c.first_name, c.last_name
            FROM properties p JOIN contacts c ON c.id = p.site_contact_id
            WHERE p.status = 'active' AND c.is_active = 1
            ORDER BY c.first_name, c.last_name, p.address
        ")->fetchAll(PDO::FETCH_ASSOC);
        return array_map(static function ($r) {
            return [
                'contact_id'  => (int)$r['contact_id'],
                'name'        => trim($r['first_name'] . ' ' . $r['last_name']),
                'property_id' => (int)$r['property_id'],
                'address'     => trim($r['address'] . ($r['city'] ? ', ' . $r['city'] : '')),
            ];
        }, $rows);
    }

    /**
     * @return array{success:bool, task_id?:int, error?:string, status?:int}
     */
    public function create(array $input, array $user): array
    {
        $title = trim((string)($input['title'] ?? ''));
        if ($title === '') return self::no('A task needs a title.');
        if (mb_strlen($title) > 255) return self::no('That title is too long (255 characters at most).');

        $due = trim((string)($input['due_date'] ?? ''));
        if ($due !== '' && !self::isDate($due)) return self::no('The due date could not be read.');

        $me = (int)$user['id'];
        $assignee = !empty($input['assigned_to']) ? (int)$input['assigned_to'] : $me;
        if ($assignee !== $me && !self::canAssignOthers($user)) {
            return self::no('Only the office can give a task to someone else.', 403);
        }
        if ($assignee !== $me && !$this->exists('users', $assignee)) return self::no('That person could not be found.');

        $contact = !empty($input['contact_id']) ? (int)$input['contact_id'] : null;
        if ($contact !== null && !$this->exists('contacts', $contact)) return self::no('That client could not be found.');
        $property = !empty($input['property_id']) ? (int)$input['property_id'] : null;
        if ($property !== null && !$this->exists('properties', $property)) return self::no('That property could not be found.');

        $priority = in_array($input['priority'] ?? '', ['high', 'normal', 'low'], true) ? $input['priority'] : 'normal';
        $note = trim((string)($input['description'] ?? ''));

        $this->db->prepare("
            INSERT INTO tasks (title, description, due_date, priority, assigned_to, contact_id, property_id, created_by, task_type)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'general')
        ")->execute([$title, $note !== '' ? $note : null, $due !== '' ? $due : null, $priority, $assignee, $contact, $property, $me]);

        return ['success' => true, 'task_id' => (int)$this->db->lastInsertId()];
    }

    private function exists(string $table, int $id): bool
    {
        // $table comes only from the literals above.
        $s = $this->db->prepare("SELECT 1 FROM {$table} WHERE id = ?");
        $s->execute([$id]);
        return (bool)$s->fetchColumn();
    }

    private static function isDate(string $d): bool
    {
        $t = DateTime::createFromFormat('Y-m-d', $d);
        return $t !== false && $t->format('Y-m-d') === $d;
    }

    private static function no(string $error, int $status = 422): array
    {
        return ['success' => false, 'error' => $error, 'status' => $status];
    }
}
