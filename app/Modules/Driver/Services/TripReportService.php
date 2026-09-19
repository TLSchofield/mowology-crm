<?php
declare(strict_types=1);

/**
 * TripReportService — commercial vehicle trip inspections (pre-trip / post-trip).
 *
 * WHO must do one is decided PER SHIFT, not per person. The duty to inspect and
 * log attaches to whoever drives the vehicle that day. The original design hung
 * it on users.is_driver — a permanent flag — so an owner who drives some days was
 * never asked (no log existed for those days) while a flagged employee was forced
 * through it even as a passenger. Here a person DECLARES at clock-in whether they
 * are driving, can change that mid-shift in either direction, and:
 *   - declaring "driving" requires a pre-trip inspection before they drive
 *   - an open trip (pre done, post not) must be closed before they clock out
 *   - "not driving" is recorded too, so the day's log shows who was NOT the driver
 *
 * The web flow (public/crm/api/trip-report.php) predates this service and still
 * writes the same table inline; its state machine is mirrored here exactly so a
 * trip opened on one surface can be closed on the other.
 *
 * Global-namespace, no autoloader: require_once, then `new TripReportService($db)`.
 */
class TripReportService
{
    /** Inspection items, in walk-around order. Keys are vehicle_trip_reports columns. */
    public const CHECKS = [
        'chk_leaks'          => 'Check beneath truck for leaks',
        'chk_hitch'          => 'Hitch secure & light plug attached',
        'chk_rear_gate'      => 'Rear truck gate secure',
        'chk_loads_secure'   => 'Loads secure',
        'chk_trailer_lights' => 'Trailer lights',
        'chk_truck_brakes'   => 'Truck brakes',
        'chk_truck_lights'   => 'Truck lights',
        'chk_mirrors'        => 'Mirrors',
        'chk_tire_pressure'  => 'Tire pressure',
        'chk_washer_wipers'  => 'Washer fluids & wipers',
    ];

    /** @var PDO */
    private $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // ── Pure rules ──────────────────────────────────────────────────────────

    /** 'none' (no trip today) | 'open' (pre-trip done, not closed) | 'closed'. */
    public static function tripState(?array $latest): string
    {
        if (!$latest || empty($latest['pre_trip_at'])) {
            return 'none';
        }
        return empty($latest['post_trip_at']) ? 'open' : 'closed';
    }

    /** @return array{checks: array<string,int>, odometer_start: ?int, defects_critical: string, defect_unhitch: int, defects_non_urgent: string, safe_to_drive: int} */
    public static function sanitizePreTrip(array $in): array
    {
        $checks = [];
        foreach (array_keys(self::CHECKS) as $field) {
            $checks[$field] = empty($in[$field]) ? 0 : 1;
        }
        return [
            'checks'             => $checks,
            'odometer_start'     => self::odometer($in['odometer_start'] ?? null),
            'defects_critical'   => self::text($in['defects_critical'] ?? '', 2000),
            'defect_unhitch'     => empty($in['defect_unhitch']) ? 0 : 1,
            'defects_non_urgent' => self::text($in['defects_non_urgent'] ?? '', 2000),
            'safe_to_drive'      => empty($in['safe_to_drive']) ? 0 : 1,
        ];
    }

    /**
     * May this vehicle be driven on the strength of this inspection?
     * A recorded critical defect overrides a ticked "safe to drive" box — the two
     * cannot both be true, and the cautious reading is the one that protects people.
     */
    public static function mayDrive(array $clean): bool
    {
        return $clean['safe_to_drive'] === 1 && $clean['defects_critical'] === '';
    }

    /** Items the driver left unticked — shown back to them and to the office. */
    public static function uncheckedLabels(array $clean): array
    {
        $out = [];
        foreach ($clean['checks'] as $field => $on) {
            if (!$on) {
                $out[] = self::CHECKS[$field];
            }
        }
        return $out;
    }

    public static function sanitizePostTrip(array $in): array
    {
        return [
            'odometer_end'        => self::odometer($in['odometer_end'] ?? null),
            'end_of_day_remarks'  => self::text($in['end_of_day_remarks'] ?? '', 2000),
            'hos_on_duty_driving' => self::text($in['hos_on_duty_driving'] ?? '', 100),
            'hos_on_duty_other'   => self::text($in['hos_on_duty_other'] ?? '', 100),
            'hos_off_duty'        => self::text($in['hos_off_duty'] ?? '', 100),
        ];
    }

    /** An end reading below the start reading is a typo, not a trip. */
    public static function odometerProblem(?int $start, ?int $end): ?string
    {
        if ($start !== null && $end !== null && $end < $start) {
            return "The end odometer ({$end}) is lower than the start ({$start}). Please check it.";
        }
        if ($start !== null && $end !== null && $end - $start > 1500) {
            return 'That is more than 1,500 km in one trip. Please check the odometer reading.';
        }
        return null;
    }

    /**
     * "ID|Label;ID|Label" → [['id'=>..,'label'=>..], …]. Malformed entries are skipped.
     */
    public static function parseVehicles(string $setting): array
    {
        $out = [];
        foreach (explode(';', $setting) as $entry) {
            $parts = array_map('trim', explode('|', $entry, 2));
            $id    = substr(preg_replace('/[^A-Za-z0-9 _\-]/', '', $parts[0] ?? ''), 0, 30);
            if ($id === '') {
                continue;
            }
            $out[] = ['id' => $id, 'label' => ($parts[1] ?? '') !== '' ? $parts[1] : $id];
        }
        return $out;
    }

    private static function odometer($v): ?int
    {
        if ($v === null || $v === '' || !is_numeric($v)) {
            return null;
        }
        $n = (int)$v;
        return ($n >= 0 && $n < 5000000) ? $n : null;
    }

    private static function text($v, int $max): string
    {
        return substr(trim(strip_tags((string)$v)), 0, $max);
    }

    // ── DB ──────────────────────────────────────────────────────────────────

    /** Fleet list: time_clock_settings.fleet_vehicles, else whatever the log has already seen. */
    public function vehicles(): array
    {
        $stmt = $this->db->prepare("SELECT setting_value FROM time_clock_settings WHERE setting_key = 'fleet_vehicles'");
        $stmt->execute();
        $configured = self::parseVehicles((string)($stmt->fetchColumn() ?: ''));
        if ($configured) {
            return $configured;
        }
        $rows = $this->db->query("
            SELECT vehicle_id, MAX(report_date) AS latest
            FROM vehicle_trip_reports GROUP BY vehicle_id ORDER BY latest DESC LIMIT 10
        ")->fetchAll(PDO::FETCH_ASSOC);
        return array_map(static fn (array $r): array => ['id' => $r['vehicle_id'], 'label' => $r['vehicle_id']], $rows);
    }

    public function latestForDriver(int $userId, string $date): ?array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM vehicle_trip_reports
            WHERE driver_id = ? AND report_date = ?
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute([$userId, $date]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Last closing odometer for a vehicle — pre-fills the next start reading. */
    public function lastOdometer(string $vehicleId): ?int
    {
        $stmt = $this->db->prepare("
            SELECT COALESCE(odometer_end, odometer_start) FROM vehicle_trip_reports
            WHERE vehicle_id = ? AND COALESCE(odometer_end, odometer_start) IS NOT NULL
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute([$vehicleId]);
        $v = $stmt->fetchColumn();
        return $v !== false && $v !== null ? (int)$v : null;
    }

    /** Everything the mobile screen needs in one read. */
    public function status(int $userId, string $date): array
    {
        $latest   = $this->latestForDriver($userId, $date);
        $state    = self::tripState($latest);
        $vehicles = $this->vehicles();
        $decl     = $this->latestDeclaration($userId, $date);

        $open = null;
        if ($state === 'open') {
            $open = [
                'report_id'      => (int)$latest['id'],
                'vehicle_id'     => $latest['vehicle_id'],
                'pre_trip_at'    => $latest['pre_trip_at'],
                'odometer_start' => $latest['odometer_start'] !== null ? (int)$latest['odometer_start'] : null,
                'safe_to_drive'  => (bool)$latest['safe_to_drive'],
            ];
        }

        return [
            'trip_state'      => $state,
            'open_trip'       => $open,
            // null = not asked yet this shift → the app should ask.
            'declared'        => $decl ? (bool)$decl['is_driving'] : ($state === 'open' ? true : null),
            'must_close_before_clock_out' => $state === 'open',
            'vehicles'        => $vehicles,
            'last_odometer'   => $vehicles ? $this->lastOdometer($vehicles[0]['id']) : null,
            'checks'          => array_map(
                static fn (string $k, string $label): array => ['field' => $k, 'label' => $label],
                array_keys(self::CHECKS), array_values(self::CHECKS)
            ),
        ];
    }

    /**
     * Mirrors the web state machine: re-saving while a trip is open updates it in
     * place; after a trip is closed (or none exists) a new trip_sequence row is made.
     *
     * @return array{report_id:int, may_drive:bool, unchecked:string[]}
     */
    public function savePreTrip(int $userId, string $vehicleId, array $input, string $date): array
    {
        $clean  = self::sanitizePreTrip($input);
        $latest = $this->latestForDriver($userId, $date);
        $checks = array_values($clean['checks']);

        if (self::tripState($latest) === 'open' || ($latest && empty($latest['pre_trip_at']) && empty($latest['post_trip_at']))) {
            $reportId = (int)$latest['id'];
            $this->db->prepare("
                UPDATE vehicle_trip_reports SET
                    vehicle_id = ?, pre_trip_at = IF(pre_trip_at IS NULL, NOW(), pre_trip_at), odometer_start = ?,
                    chk_leaks = ?, chk_hitch = ?, chk_rear_gate = ?, chk_loads_secure = ?, chk_trailer_lights = ?,
                    chk_truck_brakes = ?, chk_truck_lights = ?, chk_mirrors = ?, chk_tire_pressure = ?, chk_washer_wipers = ?,
                    defects_critical = ?, defect_unhitch = ?, defects_non_urgent = ?, safe_to_drive = ?,
                    status = IF(status = 'pre_pending', 'pre_complete', status)
                WHERE id = ? AND driver_id = ?
            ")->execute(array_merge(
                [$vehicleId, $clean['odometer_start']], $checks,
                [$clean['defects_critical'], $clean['defect_unhitch'], $clean['defects_non_urgent'], $clean['safe_to_drive'], $reportId, $userId]
            ));
        } else {
            $seq = $this->db->prepare("SELECT COALESCE(MAX(trip_sequence), 0) + 1 FROM vehicle_trip_reports WHERE driver_id = ? AND report_date = ?");
            $seq->execute([$userId, $date]);
            $this->db->prepare("
                INSERT INTO vehicle_trip_reports
                    (driver_id, vehicle_id, report_date, trip_sequence, pre_trip_at, odometer_start,
                     chk_leaks, chk_hitch, chk_rear_gate, chk_loads_secure, chk_trailer_lights,
                     chk_truck_brakes, chk_truck_lights, chk_mirrors, chk_tire_pressure, chk_washer_wipers,
                     defects_critical, defect_unhitch, defects_non_urgent, safe_to_drive, status)
                VALUES (?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pre_complete')
            ")->execute(array_merge(
                [$userId, $vehicleId, $date, (int)$seq->fetchColumn(), $clean['odometer_start']], $checks,
                [$clean['defects_critical'], $clean['defect_unhitch'], $clean['defects_non_urgent'], $clean['safe_to_drive']]
            ));
            $reportId = (int)$this->db->lastInsertId();
        }

        $this->regeneratePdf($reportId);
        return ['report_id' => $reportId, 'may_drive' => self::mayDrive($clean), 'unchecked' => self::uncheckedLabels($clean)];
    }

    /** @throws InvalidArgumentException when there is no open trip, or the odometer is implausible */
    public function savePostTrip(int $userId, array $input, string $date): int
    {
        $latest = $this->latestForDriver($userId, $date);
        if (self::tripState($latest) !== 'open') {
            throw new InvalidArgumentException('There is no open trip to close.');
        }
        $clean   = self::sanitizePostTrip($input);
        $problem = self::odometerProblem(
            $latest['odometer_start'] !== null ? (int)$latest['odometer_start'] : null, $clean['odometer_end']
        );
        if ($problem !== null && empty($input['confirm_odometer'])) {
            throw new InvalidArgumentException($problem);
        }

        $this->db->prepare("
            UPDATE vehicle_trip_reports SET
                post_trip_at = NOW(), odometer_end = ?, end_of_day_remarks = ?,
                hos_on_duty_driving = ?, hos_on_duty_other = ?, hos_off_duty = ?, status = 'complete'
            WHERE id = ? AND driver_id = ?
        ")->execute([
            $clean['odometer_end'], $clean['end_of_day_remarks'], $clean['hos_on_duty_driving'],
            $clean['hos_on_duty_other'], $clean['hos_off_duty'], (int)$latest['id'], $userId,
        ]);

        $this->regeneratePdf((int)$latest['id']);
        return (int)$latest['id'];
    }

    // ── Per-shift declaration (migration 1117; degrades to "not recorded") ───

    public function declare(int $userId, bool $isDriving, ?string $vehicleId, string $source): void
    {
        try {
            $this->db->prepare("
                INSERT INTO shift_driver_declarations (user_id, shift_date, is_driving, vehicle_id, source, declared_at)
                VALUES (?, CURDATE(), ?, ?, ?, NOW())
            ")->execute([$userId, $isDriving ? 1 : 0, $isDriving ? $vehicleId : null, substr($source, 0, 16)]);
        } catch (Throwable $e) {
            error_log('TripReportService::declare — migration 1117 not run? ' . $e->getMessage());
        }
    }

    public function latestDeclaration(int $userId, string $date): ?array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT is_driving, vehicle_id, declared_at FROM shift_driver_declarations
                WHERE user_id = ? AND shift_date = ? ORDER BY id DESC LIMIT 1
            ");
            $stmt->execute([$userId, $date]);
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * A driver recorded a critical defect or did not tick "safe to drive". The vehicle
     * should not move until someone has looked at it, so the office hears about it now —
     * not when they next open the trip reports page.
     */
    public function alertOfficeUnsafe(int $reportId, string $driverName): void
    {
        try {
            $stmt = $this->db->prepare("SELECT vehicle_id, defects_critical, defect_unhitch FROM vehicle_trip_reports WHERE id = ?");
            $stmt->execute([$reportId]);
            $r = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$r) {
                return;
            }
            $defect = $r['defects_critical'] !== null && $r['defects_critical'] !== '' ? $r['defects_critical'] : 'Driver did not confirm the vehicle is safe to drive.';
            $summary = "{$driverName} reported {$r['vehicle_id']} NOT safe to drive: {$defect}"
                     . (!empty($r['defect_unhitch']) ? ' (trailer unhitched)' : '');

            $ids = array_map('intval', array_column(
                $this->db->query("SELECT id FROM users WHERE is_active = 1 AND role IN ('admin', 'manager')")->fetchAll(PDO::FETCH_ASSOC), 'id'
            ));
            if ($ids && is_file(APP_ROOT . '/Services/Push/PushDispatcher.php')) {
                require_once APP_ROOT . '/Services/Push/ApnsService.php';
                require_once APP_ROOT . '/Services/Push/PushDispatcher.php';
                PushDispatcher::notifyUsers($ids, 'Vehicle not safe to drive', substr($summary, 0, 170), ['type' => 'trip_report_unsafe', 'report_id' => $reportId]);
            }

            if (is_file(CRM_INCLUDES . '/messaging.php')) {
                require_once CRM_INCLUDES . '/messaging.php';
                $biz = $this->db->query("SELECT * FROM business_settings WHERE id = 1")->fetch(PDO::FETCH_ASSOC) ?: [];
                $to  = trim((string)($biz['company_email'] ?? ''));
                if ($to !== '' && function_exists('sendCrmEmail')) {
                    sendCrmEmail(
                        $to,
                        "Vehicle not safe to drive: {$r['vehicle_id']}",
                        '<p><strong>' . htmlspecialchars($driverName) . '</strong> completed a pre-trip inspection on <strong>'
                        . htmlspecialchars((string)$r['vehicle_id']) . '</strong> and the vehicle was <strong>not</strong> cleared to drive.</p>'
                        . '<p>' . nl2br(htmlspecialchars($defect)) . '</p>'
                        . '<p>The driver has been told not to move it. Trip report #' . (int)$reportId . '.</p>',
                        null,
                        trim((string)($biz['company_name'] ?? '')) ?: 'Office'
                    );
                }
            }
        } catch (Throwable $e) {
            error_log("TripReportService::alertOfficeUnsafe failed for report {$reportId}: " . $e->getMessage());
        }
    }

    private function regeneratePdf(int $reportId): void
    {
        try {
            $file = APP_ROOT . '/Modules/Driver/TripReportPdf.php';
            if (is_file($file)) {
                require_once $file;
                TripReportPdf::generate($reportId, $this->db);
            }
        } catch (Throwable $e) {
            // The record is what matters legally; the PDF can be regenerated from it.
            error_log("TripReportService: PDF generation failed for report {$reportId}: " . $e->getMessage());
        }
    }
}
