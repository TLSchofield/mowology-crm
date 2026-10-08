<?php
declare(strict_types=1);

/**
 * Monday 2026-10-05 as an in-memory database: the truck and Nigel's phone at 2448 Larch St
 * (property 441, nothing scheduled — the yew hedge job), a dump run, then a scheduled visit at
 * 1900 Oak St (property 300). Nigel's home is also a client property (500) and must never flag.
 * Pings every 2 minutes, local time. Coordinates approximate.
 */
final class UnscheduledDayFixture
{
    public const DATE  = '2026-10-05';
    public const YARD  = [49.2543, -123.1262];
    public const LARCH = [49.2637, -123.1620];
    public const DUMP  = [49.2073, -123.1130];
    public const OAK   = [49.2650, -123.1440];
    public const HOME  = [49.2400, -123.0700];

    public static function pdo(): PDO
    {
        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        foreach ([
            'CREATE TABLE vehicle_location_pings (lat REAL, lng REAL, speed_kph REAL, recorded_at TEXT)',
            'CREATE TABLE ops_places (id INTEGER PRIMARY KEY, name TEXT, kind TEXT, lat REAL, lng REAL, radius_m INT, vendor_match TEXT, active INT)',
            'CREATE TABLE properties (id INTEGER PRIMARY KEY, latitude REAL, longitude REAL, address TEXT, property_name TEXT, site_contact_id INT)',
            'CREATE TABLE job_geofences (property_id INT, polygon_json TEXT, bbox_lat_min REAL, bbox_lat_max REAL, bbox_lng_min REAL, bbox_lng_max REAL)',
            'CREATE TABLE crew_location_history (crew_id INT, latitude REAL, longitude REAL, `timestamp` TEXT, is_office INT)',
            'CREATE TABLE time_clock_entries (user_id INT, clock_in TEXT, clock_in_lat REAL, clock_in_lng REAL, clock_out TEXT, clock_out_lat REAL, clock_out_lng REAL, status TEXT)',
            'CREATE TABLE job_time_entries (user_id INT, visit_id INT, start_time TEXT, start_lat REAL, start_lng REAL, end_time TEXT, end_lat REAL, end_lng REAL, status TEXT)',
            'CREATE TABLE ops_settings (setting_key TEXT, setting_value TEXT)',
            'CREATE TABLE users (id INTEGER PRIMARY KEY, full_name TEXT, device_type TEXT, home_lat REAL, home_lng REAL, home_radius_meters INT)',
            'CREATE TABLE job_plans (id INTEGER PRIMARY KEY, property_id INT, plan_number TEXT, title TEXT, service_type TEXT, is_recurring INT, estimated_duration_minutes INT, status TEXT)',
            'CREATE TABLE job_visits (id INTEGER PRIMARY KEY, plan_id INT, scheduled_date TEXT, status TEXT, completed_at TEXT)',
            'CREATE TABLE calendar_stops (id INTEGER PRIMARY KEY, property_id INT, stop_date TEXT, status TEXT)',
            'CREATE TABLE otto_lessons (scope TEXT, scope_key TEXT, value_json TEXT, PRIMARY KEY (scope, scope_key))',
            'CREATE TABLE contacts (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT)',
            'CREATE TABLE invoices (id INTEGER PRIMARY KEY, property_id INT, invoice_number TEXT, issue_date TEXT, total REAL, status TEXT)',
        ] as $ddl) $db->exec($ddl);

        $db->exec("INSERT INTO ops_places VALUES (1, 'Yard', 'yard', " . self::YARD[0] . ', ' . self::YARD[1] . ", 150, NULL, 1)");
        $db->exec("INSERT INTO ops_places VALUES (2, 'Vancouver Transfer Station', 'dump', " . self::DUMP[0] . ', ' . self::DUMP[1] . ", 200, NULL, 1)");
        $db->exec("INSERT INTO contacts VALUES (9, 'Marc', 'Nelitz')");
        $db->exec("INSERT INTO properties VALUES (441, " . self::LARCH[0] . ', ' . self::LARCH[1] . ", '2448 Larch St, Vancouver BC', '', 9)");
        $db->exec("INSERT INTO properties VALUES (300, " . self::OAK[0] . ', ' . self::OAK[1] . ", '1900 Oak St, Vancouver BC', '', NULL)");
        $db->exec("INSERT INTO properties VALUES (500, " . self::HOME[0] . ', ' . self::HOME[1] . ", '77 Home Rd, Vancouver BC', '', NULL)");
        $db->exec("INSERT INTO properties VALUES (22, 0, 0, '1 Failed Geocode', '', NULL)");
        $db->exec("INSERT INTO users VALUES (7, 'Nigel Brown', 'personal', " . self::HOME[0] . ', ' . self::HOME[1] . ", 200)");
        $db->exec("INSERT INTO users VALUES (8, 'DODGE RAM', 'truck', NULL, NULL, NULL)");
        $db->exec("INSERT INTO job_plans VALUES (82, 441, 'PLN-2026-0068', 'Hedge care', 'Hedge Trimming', 0, 120, 'active')");
        $db->exec("INSERT INTO job_plans VALUES (90, 300, 'PLN-2026-0012', 'Weekly lawn', 'Lawn Maintenance', 1, 45, 'active')");
        $db->exec("INSERT INTO job_visits VALUES (1, 90, '" . self::DATE . "', 'completed', '" . self::DATE . " 13:55:00')");
        $db->exec("INSERT INTO invoices VALUES (438, 441, 'INV-2026-0438', '2026-10-06', 540.75, 'sent')");

        // The truck: yard → Larch (3 h 30) → dump → Oak St (scheduled) → yard.
        $plan = [
            ['stay', self::YARD, '07:00', '07:50'],
            ['go', self::YARD, self::LARCH, '07:50', '08:10'],
            ['stay', self::LARCH, '08:10', '11:40'],
            ['go', self::LARCH, self::DUMP, '11:40', '12:04'],
            ['stay', self::DUMP, '12:04', '12:20'],
            ['go', self::DUMP, self::OAK, '12:20', '12:40'],
            ['stay', self::OAK, '12:40', '14:00'],
            ['go', self::OAK, self::YARD, '14:00', '14:20'],
            ['stay', self::YARD, '14:20', '15:00'],
        ];
        $ins = $db->prepare('INSERT INTO vehicle_location_pings VALUES (?, ?, ?, ?)');
        foreach (self::trail($plan) as $p) $ins->execute([$p[0], $p[1], $p[2], date('Y-m-d H:i:s', $p[3])]);

        // Nigel's phone: home before work (must not flag), Larch, Oak. A clock punch at the yard.
        $ph = $db->prepare('INSERT INTO crew_location_history VALUES (7, ?, ?, ?, 0)');
        foreach ([[self::HOME, '06:00', '06:50'], [self::LARCH, '08:14', '11:32'], [self::OAK, '12:44', '13:56']] as [$at, $a, $b]) {
            $k = 0;
            for ($t = strtotime(self::DATE . " $a"); $t <= strtotime(self::DATE . " $b"); $t += 120, $k++) {
                $j = (($k % 5) - 2) * 0.00012; // ±25 m jitter
                $ph->execute([$at[0] + $j, $at[1] - $j, date('Y-m-d H:i:s', $t)]);
            }
        }
        $db->exec("INSERT INTO time_clock_entries VALUES (7, '" . self::DATE . " 07:05:00', " . self::YARD[0] . ', ' . self::YARD[1] . ", '" . self::DATE . " 15:00:00', " . self::YARD[0] . ', ' . self::YARD[1] . ", 'completed')");
        return $db;
    }

    /** [lat, lng, speed, t] every 2 min along the plan. */
    public static function trail(array $plan): array
    {
        $out = [];
        $seen = [];
        foreach ($plan as $p) {
            if ($p[0] === 'stay') {
                [, $at, $a, $b] = $p;
                for ($t = strtotime(self::DATE . " $a"); $t <= strtotime(self::DATE . " $b"); $t += 120) {
                    if (isset($seen[$t])) continue;
                    $seen[$t] = true;
                    $out[] = [$at[0], $at[1], 0.0, $t];
                }
            } else {
                [, $from, $to, $a, $b] = $p;
                $t0 = strtotime(self::DATE . " $a");
                $t1 = strtotime(self::DATE . " $b");
                for ($t = $t0 + 120; $t < $t1; $t += 120) {
                    $f = ($t - $t0) / ($t1 - $t0);
                    $seen[$t] = true;
                    $out[] = [$from[0] + ($to[0] - $from[0]) * $f, $from[1] + ($to[1] - $from[1]) * $f, 40.0, $t];
                }
            }
        }
        return $out;
    }
}
