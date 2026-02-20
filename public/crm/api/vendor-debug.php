<?php
/**
 * Temporary: Update Lawnboy OCR aliases with short-form variants
 * DELETE THIS FILE after use
 */
header('Content-Type: application/json');

$__dir = __DIR__;
for ($__i = 0; $__i < 5; $__i++) {
    $__dir = dirname($__dir);
    if (is_file($__dir . '/app/Core/paths.php')) {
        require_once $__dir . '/app/Core/paths.php';
        break;
    }
}

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
require_once CRM_INCLUDES . '/functions.php';
requireLogin();

$db = getDB();
$results = [];

// Update OCR aliases for Lawnboy products (vendor_id 44)
$updates = [
    'Sod Turf Roll'               => 'SOD, TURF, SOD TURF, TRF',
    'Fresh Bark Mulch'            => 'FRESH BARK, BARK MULCH, FRESH MULCH, BARK, MULCH, FRSH',
    'Composted Bark Mulch'        => 'COMPOSTED BARK, COMP BARK, COMPOST MULCH, COMP, COMPOST',
    'Playground Wood Chip'        => 'PLAYGROUND CHIP, WOOD CHIP, PLAY CHIP, CHIP',
    'Mushroom Manure'             => 'MUSHROOM, HORSE MANURE, MANURE, MUSH',
    'Turf Blend'                  => 'TURF BLEND, TURF MIX, BLEND',
    'Garden Mix'                  => 'GARDEN MIX, GARDEN SOIL, GARDEN, GARD',
    'Soil Amender'                => 'SOIL AMENDER, AMENDER, SOIL AMMENDER, AMEND',
    'Navy Jack'                   => 'NAVY JACK, NAVY JAC, NAVY, NVY, NAVYJACK, NAVYJAC',
    'Crusher Dust'                => 'CRUSHER DUST, CRUSH DUST, CRUSHER, CRSH',
    'Bird Eye Rock'               => 'BIRD EYE, BIRDEYE, BIRD EYE ROCK, BIRD',
    'Crush Rock'                  => 'CRUSH ROCK, CRUSHED ROCK, CRUSH',
    'River Rock 1"'               => 'RIVER ROCK 1, RIVER ROCK, RVR ROCK, ROCK 1',
    'River Rock 2"'               => 'RIVER ROCK 2, ROCK 2',
    'River Rock 2-6"'             => 'RIVER ROCK 2-6, LARGE RIVER ROCK, RIVER 2-6',
    'Recycled Crush Concrete'     => 'RECYCLED CRUSH, CRUSH CONCRETE, RECYCLED CONCRETE, RECYCLED',
    'Road Base'                   => 'ROAD BASE, ROAD BAR, ROAD BACE, RD BASE, RDBASE',
    'Washed Stucco Sand'          => 'STUCCO SAND, WASHED SAND, STUCO SAND, STUCCO',
    'Lime Store Road Base'        => 'LIME STORE, LIME ROAD BASE, LIME BASE, LIME',
];

$stmt = $db->prepare("UPDATE vendor_products SET ocr_aliases = ? WHERE vendor_id = 44 AND name = ?");
$updated = 0;
foreach ($updates as $name => $aliases) {
    $stmt->execute([$aliases, $name]);
    $updated += $stmt->rowCount();
}

$results['aliases_updated'] = $updated;
$results['total_updates'] = count($updates);

// Verify
$check = $db->query("SELECT name, ocr_aliases FROM vendor_products WHERE vendor_id = 44 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$results['products'] = $check;

echo json_encode($results, JSON_PRETTY_PRINT);
