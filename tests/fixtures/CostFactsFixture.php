<?php
declare(strict_types=1);

/**
 * The real 2026-10-07 Oakridge Gardens morning, as stored rows (after TripCostService priced it),
 * plus an in-memory SQLite schema for the shared-facts services.
 *
 *   Oakridge → Vancouver Transfer Station 9:49–10:01 (scale ticket $27) → Lawn Boy 10:15–10:24 → Oakridge
 *   left 09:36, back 10:35, ~9.9 km. Nigel $37/h + 15% burden, truck $0.70/km, one man.
 *   Dump leg:   drive 13 + 7 (half the hop) = 20 min, 12 min on site, 5.4 km
 *   Lawn Boy:   drive 7 + 11 = 18 min, 9 min on site, 4.5 km
 *   Lawn Boy receipt: "Black mulch 2 yd" $90 (Oakridge's quote has mulch) + "Grass seed" $45 (shop stock), $141.75 with GST.
 */
final class CostFactsFixture
{
    public const DATE = '2026-10-07';
    public const OAKRIDGE_PROPERTY = 40;
    public const OAKRIDGE_PLAN = 7;
    public const OAKRIDGE_VISIT = 70;
    public const OAKRIDGE_QUOTE = 12;
    public const DUMP_PLACE = 1;
    public const LAWNBOY_PLACE = 3;
    public const DUMP_RECEIPT = 501;
    public const LAWNBOY_RECEIPT = 502;
    public const NIGEL = 5;

    /** Priced exactly as TripCostService::cost() does. */
    public static function dumpCost(): array
    {
        return TripCostService::cost(20, 12, 5.4, 37.0, 15, 1, 0.70, 27.00);
    }

    public static function lawnBoyCost(): array
    {
        return TripCostService::cost(18, 9, 4.5, 37.0, 15, 1, 0.70, 141.75);
    }

    /** The two ops_trip_runs rows of the Oakridge run. */
    public static function runs(): array
    {
        $d = self::dumpCost();
        $l = self::lawnBoyCost();
        $base = ['run_date' => self::DATE, 'trip_key' => self::DATE . '@09:36', 'user_id' => self::NIGEL, 'crew_count' => 1, 'one_man' => 1,
                 'crew_override' => null, 'from_property_id' => self::OAKRIDGE_PROPERTY, 'return_property_id' => self::OAKRIDGE_PROPERTY,
                 'left_at' => self::DATE . ' 09:36:00', 'returned_at' => self::DATE . ' 10:35:00', 'rate_missing' => 0];
        return [
            $base + ['id' => 1, 'place_id' => self::DUMP_PLACE, 'place_name' => 'Vancouver Transfer Station', 'kind' => 'dump',
                     'arrived_at' => self::DATE . ' 09:49:00', 'departed_at' => self::DATE . ' 10:01:00',
                     'drive_min' => 20, 'onsite_min' => 12, 'km' => 5.4, 'labour_cost' => $d['labour'], 'truck_cost' => $d['truck'],
                     'receipt_cost' => 27.00, 'receipt_ids' => (string)self::DUMP_RECEIPT, 'total' => $d['total']],
            $base + ['id' => 2, 'place_id' => self::LAWNBOY_PLACE, 'place_name' => 'Lawn Boy', 'kind' => 'supplier',
                     'arrived_at' => self::DATE . ' 10:15:00', 'departed_at' => self::DATE . ' 10:24:00',
                     'drive_min' => 18, 'onsite_min' => 9, 'km' => 4.5, 'labour_cost' => $l['labour'], 'truck_cost' => $l['truck'],
                     'receipt_cost' => 141.75, 'receipt_ids' => (string)self::LAWNBOY_RECEIPT, 'total' => $l['total']],
        ];
    }

    /** Oakridge's quote: a cleanup and a mulch install; nothing for trips. */
    public static function quoteLines(): array
    {
        return [
            ['product_id' => 21, 'service_type' => 'Fall cleanup', 'description' => 'Leaves, beds, green waste hauled away', 'quantity' => 1, 'unit_price' => 480, 'line_total' => 480, 'product_name' => 'Seasonal cleanup'],
            ['product_id' => 33, 'service_type' => 'Black mulch install', 'description' => '2 yd black mulch, front beds', 'quantity' => 2, 'unit_price' => 95, 'line_total' => 190, 'product_name' => 'Black mulch (per yard)'],
        ];
    }

    public static function lawnBoyLines(): array
    {
        return [
            ['id' => 9001, 'expense_id' => self::LAWNBOY_RECEIPT, 'product_id' => null, 'name' => 'Black mulch 2 yd', 'line_total' => 90.00],
            ['id' => 9002, 'expense_id' => self::LAWNBOY_RECEIPT, 'product_id' => null, 'name' => 'Grass seed', 'line_total' => 45.00],
        ];
    }

    /** SQLite with the tables the shared-facts services read, loaded with the Oakridge morning. */
    public static function db(): PDO
    {
        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $db->exec("CREATE TABLE ops_places (id INTEGER PRIMARY KEY, name TEXT, kind TEXT)");
        $db->exec("CREATE TABLE ops_trip_runs (id INTEGER PRIMARY KEY, run_date TEXT, trip_key TEXT, place_id INT, kind TEXT, user_id INT,
                    crew_count INT, one_man INT, crew_override INT, from_property_id INT, return_property_id INT, left_at TEXT, arrived_at TEXT,
                    departed_at TEXT, returned_at TEXT, drive_min REAL, onsite_min REAL, km REAL, labour_cost REAL, truck_cost REAL,
                    receipt_cost REAL, receipt_ids TEXT, total REAL, rate_missing INT DEFAULT 0)");
        $db->exec("CREATE TABLE ops_cost_facts (id INTEGER PRIMARY KEY AUTOINCREMENT, fact_key TEXT UNIQUE, label TEXT, kind TEXT, place_id INT,
                    sample_n INT, median_onsite_min REAL, median_round_trip_min REAL, median_km REAL, median_trip_cost REAL, median_receipt REAL,
                    receipt_n INT, median_cost REAL, avg_cost REAL, last_run_date TEXT, updated_at TEXT)");
        $db->exec("CREATE TABLE ops_trip_job_costs (id INTEGER PRIMARY KEY AUTOINCREMENT, run_id INT, run_date TEXT, trip_key TEXT, kind TEXT,
                    property_id INT, job_plan_id INT, visit_id INT, source TEXT, expense_id INT, expense_line_id INT, amount REAL,
                    is_stock INT DEFAULT 0, label TEXT, created_at TEXT)");
        $db->exec("CREATE TABLE properties (id INTEGER PRIMARY KEY, property_name TEXT, address TEXT)");
        $db->exec("CREATE TABLE job_plans (id INTEGER PRIMARY KEY, quote_id INT, property_id INT, status TEXT)");
        $db->exec("CREATE TABLE job_visits (id INTEGER PRIMARY KEY, plan_id INT, scheduled_date TEXT, status TEXT)");
        $db->exec("CREATE TABLE products (id INTEGER PRIMARY KEY, name TEXT)");
        $db->exec("CREATE TABLE quote_line_items (id INTEGER PRIMARY KEY AUTOINCREMENT, quote_id INT, product_id INT, service_type TEXT,
                    description TEXT, quantity REAL, unit_price REAL, line_total REAL, sort_order INT DEFAULT 0)");
        $db->exec("CREATE TABLE expenses (id INTEGER PRIMARY KEY, expense_date TEXT, total REAL, job_id INT, status TEXT)");
        $db->exec("CREATE TABLE expense_line_items (id INTEGER PRIMARY KEY, expense_id INT, product_id INT, name TEXT, line_total REAL, sort_order INT DEFAULT 0)");

        $db->exec("INSERT INTO ops_places VALUES (1, 'Vancouver Transfer Station', 'dump'), (3, 'Lawn Boy', 'supplier')");
        $db->exec("INSERT INTO properties VALUES (40, 'Oakridge Gardens', '650 W 41st Ave')");
        $db->exec("INSERT INTO job_plans VALUES (7, 12, 40, 'active')");
        $db->exec("INSERT INTO job_visits VALUES (70, 7, '2026-10-07', 'completed')");
        $q = $db->prepare("INSERT INTO quote_line_items (quote_id, product_id, service_type, description, quantity, unit_price, line_total) VALUES (12, ?, ?, ?, ?, ?, ?)");
        $p = $db->prepare("INSERT INTO products (id, name) VALUES (?, ?)");
        foreach (self::quoteLines() as $l) {
            $q->execute([$l['product_id'], $l['service_type'], $l['description'], $l['quantity'], $l['unit_price'], $l['line_total']]);
            $p->execute([$l['product_id'], $l['product_name']]);
        }
        $db->exec("INSERT INTO expenses VALUES (501, '2026-10-07', 27.00, NULL, 'approved'), (502, '2026-10-07', 141.75, NULL, 'approved')");
        $li = $db->prepare("INSERT INTO expense_line_items (id, expense_id, product_id, name, line_total) VALUES (?, ?, ?, ?, ?)");
        foreach (self::lawnBoyLines() as $l) $li->execute([$l['id'], $l['expense_id'], $l['product_id'], $l['name'], $l['line_total']]);
        foreach (self::runs() as $r) self::insertRun($db, $r);
        return $db;
    }

    public static function insertRun(PDO $db, array $r): void
    {
        unset($r['place_name']);
        $cols = array_keys($r);
        $db->prepare("INSERT INTO ops_trip_runs (" . implode(',', $cols) . ") VALUES (" . implode(',', array_fill(0, count($cols), '?')) . ")")
           ->execute(array_values($r));
    }

    /** Two more one-man dump runs (so the dump fact reaches n = 3), a two-man run and a no-rate run that must be ignored. */
    public static function moreDumpRuns(): array
    {
        $mk = function (int $id, string $date, float $drive, float $onsite, float $km, ?float $rate, float $fee, int $people, ?string $rcpt) {
            $c = TripCostService::cost($drive, $onsite, $km, $rate, 15, $people, 0.70, $fee);
            return ['id' => $id, 'run_date' => $date, 'trip_key' => $date . '@13:00', 'place_id' => 1, 'place_name' => 'Vancouver Transfer Station',
                    'kind' => 'dump', 'user_id' => 5, 'crew_count' => $people, 'one_man' => $people === 1 ? 1 : 0, 'crew_override' => null,
                    'from_property_id' => 41, 'return_property_id' => 41, 'left_at' => "$date 13:00:00", 'arrived_at' => "$date 13:15:00",
                    'departed_at' => "$date 13:30:00", 'returned_at' => "$date 13:50:00", 'drive_min' => $drive, 'onsite_min' => $onsite, 'km' => $km,
                    'labour_cost' => $c['labour'], 'truck_cost' => $c['truck'], 'receipt_cost' => $fee, 'receipt_ids' => $rcpt,
                    'total' => $c['total'], 'rate_missing' => $c['rate_missing'] ? 1 : 0];
        };
        return [
            $mk(3, '2026-10-01', 24, 15, 10.0, 37.0, 31.00, 1, '601'),
            $mk(4, '2026-10-03', 18, 10, 8.0, 37.0, 0, 1, null),          // receipt not filed yet
            $mk(5, '2026-10-04', 22, 14, 9.0, 37.0, 25.00, 2, '602'),     // two-man — not in the facts
            $mk(6, '2026-10-05', 21, 11, 9.5, null, 22.00, 1, '603'),     // no pay rate — not in the facts
        ];
    }
}
