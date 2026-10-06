<?php
/**
 * DeadlineService — Charlie's compliance & renewals calendar (migration 1171).
 *
 * Keeps one open occurrence per active deadline (the next due date after the last one
 * dealt with), feeds the ones inside their reminder window into Charlie's brief as head
 * 'charlie', and records Done / Snooze / Not this year. Overdue occurrences stay open
 * until Tim marks them — a missed deadline never quietly rolls forward.
 * Charlie files and pays nothing: items carry a checklist and links only.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
require_once __DIR__ . '/DeadlineRules.php';

class DeadlineService
{
    public const CATEGORIES = ['tax', 'payroll', 'safety', 'licence', 'insurance', 'vehicle', 'equipment', 'contract', 'other'];
    public const PAGE = '/crm/foreman_calendar_appstack.php';

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
            return $this->db->query("SHOW TABLES LIKE 'charlie_deadlines'")->rowCount() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** Make sure every active, dated deadline has its next occurrence. Cheap; safe on every load. */
    public function sync(): void
    {
        $rows = $this->db->query("SELECT * FROM charlie_deadlines WHERE active = 1 AND rule <> ''")->fetchAll(PDO::FETCH_ASSOC);
        $openQ = $this->db->prepare("SELECT COUNT(*) FROM charlie_deadline_occurrences WHERE deadline_id = ? AND status IN ('open', 'snoozed')");
        $lastQ = $this->db->prepare("SELECT MAX(due_date) FROM charlie_deadline_occurrences WHERE deadline_id = ?");
        $ins = $this->db->prepare("INSERT IGNORE INTO charlie_deadline_occurrences (deadline_id, due_date) VALUES (?, ?)");
        foreach ($rows as $d) {
            $openQ->execute([(int)$d['id']]);
            if ((int)$openQ->fetchColumn() > 0) continue;
            $lastQ->execute([(int)$d['id']]);
            $last = $lastQ->fetchColumn();
            $next = self::nextOccurrence((string)$d['rule'], $last ?: null, $this->today(), $d['anchor_date'] ?: null);
            if ($next) $ins->execute([(int)$d['id'], $next]);
        }
    }

    /**
     * Pure: the next occurrence to open. First time (nothing dealt with yet): the next due
     * date on or after today, so nothing is overdue on day one. After that: the next date
     * after the last one dealt with — a later miss stays a miss.
     */
    public static function nextOccurrence(string $rule, ?string $lastDue, string $today, ?string $anchor = null): ?string
    {
        $from = $lastDue ? date('Y-m-d', strtotime($lastDue . ' +1 day')) : $today;
        return DeadlineRules::nextDue($rule, $from, $anchor);
    }

    /** Every deadline with its current occurrence, for the calendar page (sorted by due date). */
    public function all(): array
    {
        $this->sync();
        $rows = $this->db->query("
            SELECT d.*, o.id AS occ_id, o.due_date, o.status AS occ_status, o.snoozed_until
            FROM charlie_deadlines d
            LEFT JOIN charlie_deadline_occurrences o ON o.deadline_id = d.id AND o.status IN ('open', 'snoozed')
            ORDER BY d.active DESC, o.due_date IS NULL, o.due_date, d.title
        ")->fetchAll(PDO::FETCH_ASSOC);
        $today = $this->today();
        foreach ($rows as &$r) {
            $r['describe'] = DeadlineRules::describe((string)$r['rule']);
            $r['needs_setup'] = trim((string)$r['rule']) === '';
            if ($r['due_date']) {
                $r['priority'] = DeadlineRules::priority($today, $r['due_date'], (int)$r['lead_days']);
                $r['when'] = DeadlineRules::when($today, $r['due_date']);
                $r['overdue'] = $r['due_date'] < $today;
            } else {
                $r['priority'] = null;
                $r['when'] = $r['needs_setup'] ? 'Needs a date' : '';
                $r['overdue'] = false;
            }
        }
        return $rows;
    }

    public function history(int $limit = 30): array
    {
        return $this->db->query("
            SELECT o.*, d.title FROM charlie_deadline_occurrences o JOIN charlie_deadlines d ON d.id = o.deadline_id
            WHERE o.status IN ('done', 'skipped') ORDER BY COALESCE(o.done_at, o.due_date) DESC LIMIT " . max(1, min(200, $limit))
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Charlie's brief: occurrences inside their reminder window (not snoozed), overdue first. */
    public function brief(string $ownerFirstName = ''): array
    {
        $items = [];
        $setup = 0;
        foreach ($this->all() as $r) {
            if (!(int)$r['active']) continue;
            if ($r['needs_setup']) { $setup++; continue; }
            if (!$r['due_date'] || $r['priority'] === 3) continue;
            if ($r['occ_status'] === 'snoozed' && $r['snoozed_until'] && $r['snoozed_until'] > $this->today() && !$r['overdue']) continue;
            $items[] = self::item($r);
        }
        $over = count(array_filter($items, fn($i) => $i['overdue']));
        $headline = $over > 0
            ? $over . ' deadline' . ($over === 1 ? ' is' : 's are') . ' overdue'
            : ($items ? count($items) . ' deadline' . (count($items) === 1 ? '' : 's') . ' coming up' : 'No deadlines in the next few weeks');
        if ($setup > 0) $headline .= ' · ' . $setup . ' still need' . ($setup === 1 ? 's' : '') . ' a date';
        foreach ($items as &$i) unset($i['overdue']);
        return ['head' => 'charlie', 'headline' => $headline, 'items' => $items, 'count' => count($items)];
    }

    /** Pure: one calendar row → a brief item (stable key per due date). */
    public static function item(array $r): array
    {
        return [
            'key'      => 'charlie:deadline:' . $r['slug'] . ':' . $r['due_date'],
            'kind'     => 'charlie:deadline_' . $r['category'],
            'text'     => $r['title'] . ' — ' . $r['when'],
            'url'      => self::PAGE . '#d-' . $r['slug'],
            'priority' => (int)$r['priority'],
            'value'    => $r['amount_hint'] !== null ? (float)$r['amount_hint'] : null,
            'since'    => date('Y-m-d', strtotime($r['due_date'] . ' -' . (int)$r['lead_days'] . ' days')),
            'overdue'  => (bool)$r['overdue'],
        ];
    }

    /** Parse a brief key back to [slug, due_date]. */
    public static function parseKey(string $key): ?array
    {
        return preg_match('/^charlie:deadline:([a-z0-9_]+):(\d{4}-\d{2}-\d{2})$/', $key, $m) ? [$m[1], $m[2]] : null;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Tim's buttons
    // ─────────────────────────────────────────────────────────────────────────

    private function occurrence(int $occId): ?array
    {
        $s = $this->db->prepare("SELECT o.*, d.rule, d.anchor_date, d.title FROM charlie_deadline_occurrences o JOIN charlie_deadlines d ON d.id = o.deadline_id WHERE o.id = ?");
        $s->execute([$occId]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function occurrenceIdForKey(string $key): ?int
    {
        $p = self::parseKey($key);
        if (!$p) return null;
        $s = $this->db->prepare("SELECT o.id FROM charlie_deadline_occurrences o JOIN charlie_deadlines d ON d.id = o.deadline_id WHERE d.slug = ? AND o.due_date = ?");
        $s->execute($p);
        $id = $s->fetchColumn();
        return $id ? (int)$id : null;
    }

    /** @param string $what done | skip | snooze */
    public function mark(int $occId, string $what, int $userId, int $days = 3, string $note = ''): array
    {
        $o = $this->occurrence($occId);
        if (!$o) return ['ok' => false, 'message' => "I can't find that deadline"];
        if (!in_array($o['status'], ['open', 'snoozed'], true)) return ['ok' => false, 'message' => 'Already dealt with'];
        $note = mb_substr(trim($note), 0, 255) ?: null;
        if ($what === 'snooze') {
            $until = DeadlineRules::snoozeUntil($this->today(), (string)$o['due_date'], $days);
            if ($until <= $this->today()) return ['ok' => false, 'message' => "It's due too soon to put off"];
            $this->db->prepare("UPDATE charlie_deadline_occurrences SET status = 'snoozed', snoozed_until = ? WHERE id = ?")->execute([$until, $occId]);
            return ['ok' => true, 'message' => "I'll remind you again on " . date('D M j', strtotime($until)) . '.'];
        }
        if (!in_array($what, ['done', 'skip'], true)) return ['ok' => false, 'message' => 'Unknown action'];
        $this->db->prepare("UPDATE charlie_deadline_occurrences SET status = ?, done_at = NOW(), done_by = ?, note = ? WHERE id = ?")
            ->execute([$what === 'done' ? 'done' : 'skipped', $userId, $note, $occId]);
        $this->sync();
        $next = DeadlineRules::nextDue((string)$o['rule'], date('Y-m-d', strtotime($o['due_date'] . ' +1 day')), $o['anchor_date'] ?: null);
        return ['ok' => true, 'message' => ($what === 'done' ? 'Done — ' : 'Skipped this time — ')
            . ($next ? 'next one ' . date('M j, Y', strtotime($next)) . '.' : 'no more dates on this one.')];
    }

    /**
     * Add or edit a deadline from the calendar page's plain inputs.
     * @return array{ok: bool, message: string, id?: int}
     */
    public function save(array $f): array
    {
        $title = trim((string)($f['title'] ?? ''));
        if ($title === '') return ['ok' => false, 'message' => 'Give it a name'];
        $cat = in_array($f['category'] ?? '', self::CATEGORIES, true) ? $f['category'] : 'other';
        $rule = DeadlineRules::fromForm($f);
        $anchor = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($f['anchor_date'] ?? '')) ? $f['anchor_date'] : null;
        if (strpos($rule, 'every:') === 0 && !$anchor) return ['ok' => false, 'message' => 'Pick the date the repeat counts from'];
        if (!DeadlineRules::valid($rule, $anchor)) return ['ok' => false, 'message' => "That date doesn't work"];
        $lead = max(0, min(365, (int)($f['lead_days'] ?? 14)));
        $vals = [
            mb_substr($title, 0, 200), $cat, $rule, $anchor, $lead,
            mb_substr(trim((string)($f['prepare'] ?? '')), 0, 2000) ?: null,
            isset($f['amount_hint']) && is_numeric($f['amount_hint']) ? round((float)$f['amount_hint'], 2) : null,
            !empty($f['active']) ? 1 : 0,
        ];
        $id = (int)($f['id'] ?? 0);
        if ($id > 0) {
            $old = $this->db->prepare("SELECT rule, anchor_date FROM charlie_deadlines WHERE id = ?");
            $old->execute([$id]);
            $prev = $old->fetch(PDO::FETCH_ASSOC);
            if (!$prev) return ['ok' => false, 'message' => "I can't find that deadline"];
            $this->db->prepare("
                UPDATE charlie_deadlines SET title = ?, category = ?, rule = ?, anchor_date = ?, lead_days = ?, prepare = ?, amount_hint = ?, active = ?
                WHERE id = ?
            ")->execute(array_merge($vals, [$id]));
            if ($prev['rule'] !== $rule || (string)$prev['anchor_date'] !== (string)$anchor) {
                // The date changed: the open occurrence follows the new rule.
                $this->db->prepare("DELETE FROM charlie_deadline_occurrences WHERE deadline_id = ? AND status IN ('open', 'snoozed')")->execute([$id]);
            }
        } else {
            $slug = self::slug($title);
            $s = $this->db->prepare("SELECT COUNT(*) FROM charlie_deadlines WHERE slug = ?");
            for ($n = 2, $base = $slug; ; $n++) {
                $s->execute([$slug]);
                if ((int)$s->fetchColumn() === 0) break;
                $slug = substr($base, 0, 55) . '_' . $n;
            }
            $this->db->prepare("
                INSERT INTO charlie_deadlines (title, category, rule, anchor_date, lead_days, prepare, amount_hint, active, slug, source)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'tim')
            ")->execute(array_merge($vals, [$slug]));
            $id = (int)$this->db->lastInsertId();
        }
        $this->sync();
        return ['ok' => true, 'message' => 'Saved.', 'id' => $id];
    }

    public static function slug(string $title): string
    {
        $s = trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($title)), '_');
        return substr($s !== '' ? $s : 'deadline', 0, 60);
    }
}
