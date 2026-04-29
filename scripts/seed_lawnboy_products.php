<?php
/**
 * One-time seeder: add Lawnboy Enterprises vendor products (vendor ID 32)
 * Web-triggered — delete after use.
 */
if (($_GET['key'] ?? '') !== 'lawnboy2026') { http_response_code(403); exit('forbidden'); }

$root = dirname(__DIR__);
require_once $root . '/app/Core/paths.php';
require_once PUBLIC_ROOT . '/app_config/config.php';

$db = Database::pdo();

$vendorId = 32;

// Wipe existing vendor products for clean run
$db->prepare("DELETE FROM vendor_products WHERE vendor_id = ?")->execute([$vendorId]);

$products = [
    // ── Mulch & Organics ──────────────────────────────────────────
    ['Fresh Bark Mulch',            'Mulch & Organics', 'yd', 41.00, '½ yd', 22.00, 5.00,  'FRESH BARK,BARK MULCH,BARK'],
    ['Composted Bark Mulch',        'Mulch & Organics', 'yd', 41.00, '½ yd', 22.00, 5.00,  'COMPOSTED BARK,COMPOST BARK,COMPOST MULCH'],
    ['Playground Wood Chip',        'Mulch & Organics', 'yd', 75.00, null,   null,  null,  'WOOD CHIP,WOODCHIP,PLAYGROUND CHIP'],
    ['Mushroom Manure',             'Mulch & Organics', 'yd', 41.00, '½ yd', 22.00, 5.00,  'MUSHROOM MAN,HORSE MANURE,MANURE'],
    ['Turf Blend',                  'Mulch & Organics', 'yd', 41.00, '½ yd', 22.00, 5.00,  'TURF BLD,TURF BLEND'],
    ['Garden Mix',                  'Mulch & Organics', 'yd', 41.00, '½ yd', 22.00, 5.00,  'GARDEN MX,GARDEN MIX'],
    ['Soil Amender',                'Mulch & Organics', 'yd', 41.00, '½ yd', 22.00, 5.00,  'SOIL AMEND,SOIL AMD,AMENDER'],
    // ── Rock & Aggregates ─────────────────────────────────────────
    ['Road Base',                   'Rock & Aggregates', 'yd', 66.00,  '½ yd', 35.00,  4.00,  'ROAD BAR,ROAD BACE,RD BASE,ROADBASE'],
    ['3/4" Lime Store Road Base',   'Rock & Aggregates', 'yd', 152.00, '½ yd', 81.00,  6.00,  'LIME RD BASE,LIME ROAD BASE,3/4 LIME,LIME STORE'],
    ['Navy Jack',                   'Rock & Aggregates', 'yd', 75.00,  '½ yd', 40.00,  4.00,  'NAVY JAK,NAVY JK'],
    ['Crusher Dust (7-9mm)',        'Rock & Aggregates', 'yd', 75.00,  '½ yd', 40.00,  4.00,  'CRUSHER DST,CRUSH DUST,CRUSHER DUST'],
    ['Bird Eye Rock (5mm)',         'Rock & Aggregates', 'yd', 84.00,  '½ yd', 45.00,  4.00,  'BIRD EYE,BIRD EYE RK,BIRD ROCK'],
    ['3/4" - 1" Crush Rock',       'Rock & Aggregates', 'yd', 82.00,  '½ yd', 44.00,  4.00,  'CRUSH ROCK,CRUSH RK,3/4 CRUSH,CRUSHED ROCK'],
    ['1" Recycled Crush Concrete', 'Rock & Aggregates', 'yd', 56.00,  '½ yd', 30.00,  4.00,  'RECYCLED CONCRETE,RECYCLED CRUSH,CRUSH CONCRETE'],
    ['1" River Rock',              'Rock & Aggregates', 'yd', 107.00, '½ yd', 59.00,  4.00,  '1 INCH RIVER,1" RIVER,RIVER RK 1'],
    ['2" River Rock',              'Rock & Aggregates', 'yd', 107.00, '½ yd', 59.00,  4.00,  '2 INCH RIVER,2" RIVER,RIVER RK 2'],
    ['2"-6" River Rock',           'Rock & Aggregates', 'yd', 120.00, '½ yd', 66.00,  6.00,  '2-6 RIVER,2 TO 6 RIVER,RIVER ROCK LARGE'],
    ['Washed Stucco Sand',         'Rock & Aggregates', 'yd', 91.00,  '½ yd', 49.00,  4.00,  'STUCCO SAND,WASHED SAND,STUCCO'],
    ['Sechelt Sand',               'Rock & Aggregates', 'yd', 56.00,  '½ yd', 30.00,  4.00,  'SECHELT,SECHLT SAND'],
    ['River Sand',                 'Rock & Aggregates', 'yd', 56.00,  '½ yd', 30.00,  4.00,  'RIVER SND,RVR SAND'],
    // ── Disposal ──────────────────────────────────────────────────
    ['Dump — Clean Soil',           'Disposal', 'yd', 47.00, '½ yd', 23.50, 2.00,  'DUMP SOIL,CLEAN SOIL,DMP SOIL'],
    ['Dump — Clean Concrete',       'Disposal', 'yd', 47.00, '½ yd', 23.50, 2.00,  'DUMP CONCRETE,CLEAN CONCRETE,DMP CONC'],
    ['Dump — Concrete w/Rebar',     'Disposal', 'yd', 68.00, '½ yd', 34.00, null,  'DUMP REBAR,CONCRETE REBAR,DMP REBAR'],
    ['Dump — Asphalt/Brick/Tile',   'Disposal', 'yd', 68.00, '½ yd', 34.00, 2.50,  'DUMP ASPHALT,ASPHALT DUMP,BRICK TILE'],
    // ── Sod & Turf ────────────────────────────────────────────────
    ['Sod / Turf Roll',             'Sod & Turf', 'roll', 7.30, null, null, null, 'SOD ROLL,TURF ROLL,SOD,TURF'],
];

$stmt = $db->prepare("
    INSERT INTO vendor_products (vendor_id, name, category, unit, price_per_unit, alt_unit, alt_price, bag_price, ocr_aliases)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
");

header('Content-Type: text/plain');
$added = 0;
foreach ($products as [$name, $cat, $unit, $price, $altUnit, $altPrice, $bagPrice, $aliases]) {
    $stmt->execute([$vendorId, $name, $cat, $unit, $price, $altUnit, $altPrice, $bagPrice, $aliases]);
    echo "+ $name\n";
    $added++;
}

echo "\nDone — $added products added to vendor $vendorId (Lawnboy)\n";
