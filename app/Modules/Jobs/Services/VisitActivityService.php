<?php
declare(strict_types=1);

/**
 * VisitActivityService
 * ────────────────────
 * Read-only extras for the visit detail page: crew time entries, the
 * company truck's pings during the visit window, the linked invoice,
 * and the proof-of-work emails sent. Every method fails soft (returns
 * empty) so one missing table never blanks the whole page.
 */
class VisitActivityService
{
    /** Pad the visit window so pings just before arrival / after leaving show. */
    private const WINDOW_PAD_MIN = 15;

    /** @return array<int,array<string,mixed>> */
    public static function timeEntries(PDO $db, int $visitId): array
    {
        try {
            $st = $db->prepare("
                SELECT jte.id, jte.user_id, u.full_name AS crew_name,
                       jte.start_time, jte.end_time, jte.duration_minutes,
                       jte.start_lat, jte.start_lng, jte.end_lat, jte.end_lng,
                       jte.auto_started, jte.status, jte.notes
                FROM job_time_entries jte
                LEFT JOIN users u ON u.id = jte.user_id
                WHERE jte.visit_id = ?
                ORDER BY jte.start_time ASC
            ");
            $st->execute([$visitId]);
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('VisitActivityService::timeEntries ' . $e->getMessage());
            return [];
        }
    }

    /** Total billable minutes, ignoring void entries. */
    public static function totalMinutes(array $entries): int
    {
        $sum = 0;
        foreach ($entries as $e) {
            if (($e['status'] ?? '') === 'void') {
                continue;
            }
            $sum += (int)($e['duration_minutes'] ?? 0);
        }
        return $sum;
    }

    /**
     * Compute [start, end] datetime strings for the truck window from the
     * time entries, falling back to the visit's started/completed times.
     * @return array{0:?string,1:?string}
     */
    public static function window(array $entries, ?string $startedAt, ?string $completedAt): array
    {
        $starts = [];
        $ends = [];
        foreach ($entries as $e) {
            if (($e['status'] ?? '') === 'void') {
                continue;
            }
            if (!empty($e['start_time'])) {
                $starts[] = strtotime((string)$e['start_time']);
            }
            if (!empty($e['end_time'])) {
                $ends[] = strtotime((string)$e['end_time']);
            }
        }
        if (!$starts && $startedAt) {
            $starts[] = strtotime($startedAt);
        }
        if (!$ends && $completedAt) {
            $ends[] = strtotime($completedAt);
        }
        if (!$starts) {
            return [null, null];
        }
        $s = min($starts) - self::WINDOW_PAD_MIN * 60;
        $eBase = $ends ? max($ends) : max($starts) + 3600;
        $e = $eBase + self::WINDOW_PAD_MIN * 60;
        return [date('Y-m-d H:i:s', $s), date('Y-m-d H:i:s', $e)];
    }

    /**
     * Truck pings between $start and $end.
     * @return array{count:int,first:?string,last:?string,max_kph:?float,points:array<int,array<string,mixed>>}
     */
    public static function truckPings(PDO $db, ?string $start, ?string $end): array
    {
        $empty = ['count' => 0, 'first' => null, 'last' => null, 'max_kph' => null, 'points' => []];
        if (!$start || !$end) {
            return $empty;
        }
        try {
            $st = $db->prepare("
                SELECT lat, lng, speed_kph, recorded_at
                FROM vehicle_location_pings
                WHERE recorded_at BETWEEN ? AND ?
                ORDER BY recorded_at ASC
                LIMIT 500
            ");
            $st->execute([$start, $end]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('VisitActivityService::truckPings ' . $e->getMessage());
            return $empty;
        }
        if (!$rows) {
            return $empty;
        }
        $max = null;
        foreach ($rows as $r) {
            if ($r['speed_kph'] !== null && ($max === null || (float)$r['speed_kph'] > $max)) {
                $max = (float)$r['speed_kph'];
            }
        }
        return [
            'count'   => count($rows),
            'first'   => $rows[0]['recorded_at'],
            'last'    => $rows[count($rows) - 1]['recorded_at'],
            'max_kph' => $max,
            'points'  => $rows,
        ];
    }

    /** @return array<string,mixed>|null */
    public static function invoice(PDO $db, int $visitId): ?array
    {
        try {
            $st = $db->prepare("
                SELECT i.id, i.invoice_number, i.status, i.total, i.balance_due
                FROM job_visits jv
                JOIN invoices i ON i.id = jv.invoice_id
                WHERE jv.id = ?
            ");
            $st->execute([$visitId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable $e) {
            error_log('VisitActivityService::invoice ' . $e->getMessage());
            return null;
        }
    }

    /** @return array<int,array<string,mixed>> */
    public static function emails(PDO $db, int $visitId): array
    {
        try {
            $st = $db->prepare("
                SELECT recipient, sent_at, status, error_msg
                FROM pow_email_log
                WHERE visit_id = ?
                ORDER BY sent_at DESC
                LIMIT 20
            ");
            $st->execute([$visitId]);
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('VisitActivityService::emails ' . $e->getMessage());
            return [];
        }
    }
}
