<?php
/**
 * AppointmentService — a booked time at a client's place that isn't a job visit (Tim, 2026-10-15).
 *
 * "I want to be able to add a quote visit to this enquiry, I verbally booked an appointment for tomorrow
 * 8:15." Schedule visits belong to a job plan and there is no job before the quote, so an appointment is
 * its own row (migration 1369). The schedule (web desktop + phone view, the crew app's offline copy, the
 * iPhone day list) shows appointments as their own cards beside the job stops — never as stops, so
 * completing visits, invoicing and photos never see them.
 *
 * One booked appointment per quote request: booking again moves it. A crew member sees only the ones
 * they are going to (same filter as getCalendarStops: $crewFilter = a user id, null = everyone).
 * kind is trade-neutral ('quote_visit'); add kinds rather than trade words.
 *
 * Global-namespace service: require_once the file, `new AppointmentService($db)`.
 */
declare(strict_types=1);
require_once dirname(__DIR__, 3) . '/Services/Messaging/IdentityMail.php';

class AppointmentService
{
    public const KINDS = ['quote_visit' => 'Quote visit'];
    public const STATUSES = ['booked', 'done', 'cancelled'];
    public const DEFAULT_MINUTES = 45;

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** Is the table there (before migration 1369 every caller shows nothing)? */
    public function ready(): bool
    {
        try { $this->db->query('SELECT 1 FROM appointments LIMIT 0'); return true; }
        catch (Throwable $e) { return false; }
    }

    /** "2026-10-16" + "8:15" → ['2026-10-16', '08:15:00'], or null when either is not a real date / time. Pure. */
    public static function cleanWhen(string $date, string $time): ?array
    {
        $d = DateTime::createFromFormat('!Y-m-d', trim($date));
        if (!$d || $d->format('Y-m-d') !== trim($date)) return null;
        if (!preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', trim($time), $m)) return null;
        $h = (int)$m[1]; $i = (int)$m[2];
        if ($h > 23 || $i > 59) return null;
        return [$d->format('Y-m-d'), sprintf('%02d:%02d:00', $h, $i)];
    }

    /** "Fri Oct 16, 8:15 am". Pure. */
    public static function label(string $date, string $time): string
    {
        $t = strtotime($date . ' ' . $time);
        return $t ? date('D M j', $t) . ', ' . date('g:i a', $t) : trim($date . ' ' . $time);
    }

    /**
     * Book a visit for a quote request (or move the one already booked).
     * @param array{date: string, time: string, assigned_to?: ?int, duration_minutes?: ?int, notes?: ?string, kind?: string} $in
     * @return array{ok: bool, id?: int, moved?: bool, error?: string}
     */
    public function bookForRequest(int $requestId, array $in, int $userId): array
    {
        $when = self::cleanWhen((string)($in['date'] ?? ''), (string)($in['time'] ?? ''));
        if (!$when) return ['ok' => false, 'error' => 'Pick a date and a time.'];
        $s = $this->db->prepare('SELECT id, contact_id, property_id FROM quote_requests WHERE id = ?');
        $s->execute([$requestId]);
        $req = $s->fetch(PDO::FETCH_ASSOC);
        if (!$req) return ['ok' => false, 'error' => 'That request no longer exists.'];
        $kind = array_key_exists((string)($in['kind'] ?? ''), self::KINDS) ? (string)$in['kind'] : 'quote_visit';
        $minutes = (int)($in['duration_minutes'] ?? 0);
        $minutes = $minutes >= 5 && $minutes <= 600 ? $minutes : self::DEFAULT_MINUTES;
        $assigned = (int)($in['assigned_to'] ?? 0) ?: $userId;
        $notesGiven = array_key_exists('notes', $in);   // the form always sends it: empty clears an old gate code
        $notes = trim((string)($in['notes'] ?? '')) ?: null;
        $now = date('Y-m-d H:i:s');

        $have = $this->forRequest($requestId);
        if ($have) {
            $this->db->prepare('UPDATE appointments SET appt_date = ?, appt_time = ?, duration_minutes = ?, assigned_to = ?,
                                       notes = ?, confirmed_at = NULL, updated_at = ? WHERE id = ?')
               ->execute([$when[0], $when[1], $minutes, $assigned, $notesGiven ? $notes : $have['notes'], $now, (int)$have['id']]);
            return ['ok' => true, 'id' => (int)$have['id'], 'moved' => true];
        }
        $this->db->prepare('INSERT INTO appointments (kind, quote_request_id, contact_id, property_id, appt_date, appt_time,
                                   duration_minutes, assigned_to, status, notes, created_by, created_at, updated_at)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, \'booked\', ?, ?, ?, ?)')
           ->execute([$kind, $requestId, $req['contact_id'] ?: null, $req['property_id'] ?: null, $when[0], $when[1],
                      $minutes, $assigned, $notes, $userId ?: null, $now, $now]);
        return ['ok' => true, 'id' => (int)$this->db->lastInsertId(), 'moved' => false];
    }

    /** booked → cancelled | done, only from booked (a done visit can't become cancelled, or back). @return bool whether it changed */
    public function setStatus(int $id, string $status): bool
    {
        if (!in_array($status, ['cancelled', 'done'], true)) return false;
        $u = $this->db->prepare("UPDATE appointments SET status = ?, updated_at = ? WHERE id = ? AND status = 'booked'");
        $u->execute([$status, date('Y-m-d H:i:s'), $id]);
        return $u->rowCount() > 0;
    }

    public function markConfirmed(int $id): void
    {
        $this->db->prepare('UPDATE appointments SET confirmed_at = ?, updated_at = ? WHERE id = ?')
           ->execute([date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), $id]);
    }

    /** The request's booked appointment (not cancelled or done), with who's going. */
    public function forRequest(int $requestId): ?array
    {
        $s = $this->db->prepare("SELECT a.*, u.full_name AS assigned_name FROM appointments a LEFT JOIN users u ON u.id = a.assigned_to
                                 WHERE a.quote_request_id = ? AND a.status = 'booked' ORDER BY a.id DESC LIMIT 1");
        $s->execute([$requestId]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    public function find(int $id): ?array
    {
        $rows = $this->rows('a.id = ?', [$id]);
        return $rows[0] ?? null;
    }

    /**
     * Booked appointments from $from to $to (inclusive), each shaped for the schedule, keyed by date.
     * @param ?int $crewFilter only this person's (crew), null = everyone's (office)
     * @return array<string, list<array>>
     */
    public function forRange(string $from, string $to, ?int $crewFilter = null): array
    {
        if (!$this->ready()) return [];
        $where = "a.status = 'booked' AND a.appt_date BETWEEN ? AND ?";
        $args = [$from, $to];
        if ($crewFilter) { $where .= ' AND a.assigned_to = ?'; $args[] = $crewFilter; }
        $out = [];
        foreach ($this->rows($where, $args) as $r) $out[$r['date']][] = $r;
        return $out;
    }

    private function rows(string $where, array $args): array
    {
        $s = $this->db->prepare("
            SELECT a.id, a.kind, a.quote_request_id, a.contact_id, a.property_id, a.appt_date, a.appt_time, a.duration_minutes,
                   a.assigned_to, a.status, a.notes, a.confirmed_at,
                   u.full_name AS assigned_name,
                   TRIM(CONCAT(COALESCE(c.first_name, ''), ' ', COALESCE(c.last_name, ''))) AS contact_name,
                   c.phone AS contact_phone, c.email AS contact_email,
                   p.address AS property_address, p.city AS property_city, p.latitude, p.longitude
            FROM appointments a
            LEFT JOIN users u ON u.id = a.assigned_to
            LEFT JOIN contacts c ON c.id = a.contact_id
            LEFT JOIN properties p ON p.id = a.property_id
            WHERE {$where}
            ORDER BY a.appt_date, a.appt_time, a.id");
        $s->execute($args);
        return array_map([self::class, 'shape'], $s->fetchAll(PDO::FETCH_ASSOC));
    }

    /** One row as every schedule shows it. Pure. */
    public static function shape(array $r): array
    {
        $lat = isset($r['latitude']) ? (float)$r['latitude'] : 0.0;
        $lng = isset($r['longitude']) ? (float)$r['longitude'] : 0.0;
        $hasCoords = abs($lat) > 0.0001 || abs($lng) > 0.0001;   // 0,0 is a failed geocode
        $kind = (string)($r['kind'] ?? 'quote_visit');
        $date = (string)$r['appt_date'];
        $time = substr((string)$r['appt_time'], 0, 5);
        return [
            'appointment_id'   => (int)$r['id'],
            'kind'             => $kind,
            'kind_label'       => self::KINDS[$kind] ?? 'Appointment',
            'date'             => $date,
            'time'             => $time,
            'time_label'       => date('g:i a', strtotime($date . ' ' . $time) ?: 0),
            'duration_minutes' => (int)$r['duration_minutes'],
            'status'           => (string)$r['status'],
            'quote_request_id' => isset($r['quote_request_id']) ? (int)$r['quote_request_id'] : null,
            'contact_id'       => isset($r['contact_id']) ? (int)$r['contact_id'] : null,
            'contact_name'     => trim((string)($r['contact_name'] ?? '')) ?: null,
            'contact_phone'    => !empty($r['contact_phone']) ? (string)$r['contact_phone'] : null,
            'contact_email'    => !empty($r['contact_email']) ? (string)$r['contact_email'] : null,
            'property_id'      => isset($r['property_id']) ? (int)$r['property_id'] : null,
            'property_address' => (string)($r['property_address'] ?? ''),
            'property_city'    => (string)($r['property_city'] ?? ''),
            'latitude'         => $hasCoords ? $lat : null,
            'longitude'        => $hasCoords ? $lng : null,
            'assigned_to'      => isset($r['assigned_to']) ? (int)$r['assigned_to'] : null,
            'assigned_name'    => !empty($r['assigned_name']) ? (string)$r['assigned_name'] : null,
            'notes'            => !empty($r['notes']) ? (string)$r['notes'] : null,
            'confirmed'        => !empty($r['confirmed_at']),
            'open_url'         => isset($r['quote_request_id']) && (int)$r['quote_request_id'] > 0
                ? '/crm/quote-workflow.php?request_id=' . (int)$r['quote_request_id'] : null,
        ];
    }

    /**
     * The confirmation text message: plain, no links or domains, at most 160 characters. Pure.
     * A dot in the company name could read as a domain to a carrier filter, so it is dropped.
     */
    public static function confirmationSms(array $a, string $company = '', string $phone = ''): string
    {
        if ($phone === '') $phone = _idPhone();
        // Nothing inserted may read as a link: dots, slashes and @ go from the company and client names too.
        $clean = fn(string $t): string => trim(preg_replace('/[^\p{L}\p{N} \'&-]+/u', '', $t) ?? '');
        $company = $clean($company) ?: _idFromName();
        $first = $clean((string)(strtok((string)($a['contact_name'] ?? ''), ' ') ?: ''));
        if (mb_strlen($first) > 20) $first = '';
        $when = date('D M j', strtotime($a['date']) ?: 0) . ' at ' . $a['time_label'];
        $what = strtolower($a['kind_label'] ?? 'visit');
        $tries = [
            "{$company}: Hi {$first}, confirming your {$what} on {$when}. Need to change it? Call or text {$phone}.",
            "{$company}: Confirming your {$what} on {$when}. To change it call {$phone}.",
            "{$company}: See you {$when}. Call {$phone} to change.",
        ];
        foreach ($tries as $m) {
            $m = preg_replace('/\s+/', ' ', str_replace('Hi ,', 'Hi,', $m));
            if (strlen($m) <= 160) return $m;
        }
        return mb_strcut($tries[2], 0, 160, 'UTF-8');
    }

    /** @return array{subject: string, html: string} the confirmation email (body only; the caller wraps it). Pure. */
    public static function confirmationEmail(array $a, string $company = '', string $phone = ''): array
    {
        if ($company === '') $company = _idFromName();
        if ($phone === '') $phone = _idPhone();
        $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $first = trim(strtok((string)($a['contact_name'] ?? ''), ' ') ?: '');
        $when = date('l, F j', strtotime($a['date']) ?: 0) . ' at ' . $a['time_label'];
        $what = strtolower($a['kind_label'] ?? 'visit');
        $where = trim($a['property_address'] . ($a['property_city'] !== '' ? ', ' . $a['property_city'] : ''));
        $html = '<p>Hi ' . $h($first ?: 'there') . ',</p>'
              . '<p>This confirms your ' . $h($what) . ' on <strong>' . $h($when) . '</strong>'
              . ($where !== '' ? ' at ' . $h($where) : '') . '.</p>'
              . '<p>We\'ll look at the property and send you a written quote afterwards. You don\'t need to be home, '
              . 'but if a gate or side yard is locked, please let us know.</p>'
              . '<p>Need a different time? Reply to this email' . ($phone !== '' ? ' or call ' . $h($phone) : '') . '.</p>'
              . '<p>Thanks,<br>' . $h($company) . '</p>';
        return ['subject' => ucfirst($what) . ' confirmed: ' . date('D M j', strtotime($a['date']) ?: 0) . ', ' . $a['time_label'], 'html' => $html];
    }
}
