<?php
/**
 * Service Area Map Block Renderer (P2-E)
 *
 * Renders an interactive Leaflet.js map of service areas.
 * No Google Maps API key needed — uses free OpenStreetMap tiles.
 * Config: { headline, areas[], center_lat, center_lng, zoom }
 */

$headline  = $config['headline']   ?? 'Our Service Areas';
$areas     = $config['areas']      ?? [];
$centerLat = (float)($config['center_lat'] ?? 49.2827);
$centerLng = (float)($config['center_lng'] ?? -123.1207);
$zoom      = (int)($config['zoom']          ?? 11);

// Default service areas if none configured
if (empty($areas)) {
    $areas = [
        ['name' => 'Vancouver',     'lat' => 49.2827, 'lng' => -123.1207],
        ['name' => 'Burnaby',       'lat' => 49.2488, 'lng' => -122.9805],
        ['name' => 'Richmond',      'lat' => 49.1666, 'lng' => -123.1336],
        ['name' => 'North Van',     'lat' => 49.3253, 'lng' => -123.0690],
        ['name' => 'Coquitlam',     'lat' => 49.2838, 'lng' => -122.7932],
        ['name' => 'Surrey',        'lat' => 49.1913, 'lng' => -122.8490],
        ['name' => 'Delta',         'lat' => 49.0847, 'lng' => -123.0579],
        ['name' => 'New Westminster', 'lat' => 49.2057, 'lng' => -122.9110],
    ];
}

$mapId = 'sam-map-' . substr(md5(uniqid('', true)), 0, 8);
$areasJson = json_encode(array_map(fn($a) => [
    'name' => $a['name'] ?? '',
    'lat'  => (float)($a['lat'] ?? 0),
    'lng'  => (float)($a['lng'] ?? 0),
], $areas), JSON_UNESCAPED_UNICODE);
?>

<section class="service-area-map-block section-pad">
  <div class="container">
    <?php if ($headline): ?>
    <h2 class="section-title text-center mb-4"><?php echo h($headline); ?></h2>
    <?php endif; ?>

    <!-- Area chips -->
    <div class="area-chips text-center mb-4">
      <?php foreach ($areas as $area): ?>
        <span class="area-chip"><?php echo h($area['name'] ?? ''); ?></span>
      <?php endforeach; ?>
    </div>

    <!-- Map -->
    <div id="<?php echo $mapId; ?>" style="height:420px; border-radius:12px; overflow:hidden; box-shadow:0 4px 16px rgba(0,0,0,0.12);"
         aria-label="Service area map"></div>
  </div>
</section>

<!-- Leaflet CSS / JS (loaded once via guard) -->
<?php if (!defined('LEAFLET_LOADED')): define('LEAFLET_LOADED', true); ?>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
      integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV/XN/WPeE=" crossorigin=""></script>
<style>
.area-chips { display:flex; flex-wrap:wrap; gap:8px; justify-content:center; }
.area-chip  { background:var(--mowology-light,#e8f3f0); color:var(--mowology-dark,#1A5F4A);
              padding:4px 14px; border-radius:20px; font-size:0.85rem; font-weight:600; }
</style>
<?php endif; ?>

<script>
(function() {
    var mapEl = document.getElementById('<?php echo $mapId; ?>');
    if (!mapEl || typeof L === 'undefined') return;

    var map = L.map('<?php echo $mapId; ?>', {
        center: [<?php echo $centerLat; ?>, <?php echo $centerLng; ?>],
        zoom: <?php echo $zoom; ?>,
        scrollWheelZoom: false,
    });

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
        maxZoom: 18,
    }).addTo(map);

    var greenIcon = L.divIcon({
        html: '<div style="background:#2D8659;width:14px;height:14px;border-radius:50%;border:2px solid #fff;box-shadow:0 2px 6px rgba(0,0,0,0.3);"></div>',
        className: '',
        iconSize: [14, 14],
        iconAnchor: [7, 7],
    });

    var areas = <?php echo $areasJson; ?>;
    areas.forEach(function(a) {
        if (a.lat && a.lng) {
            L.marker([a.lat, a.lng], { icon: greenIcon })
             .addTo(map)
             .bindPopup('<strong>' + a.name + '</strong><br><small>Service Area</small>');
        }
    });

    // Enable scroll zoom only when map is focused
    mapEl.addEventListener('click', function() { map.scrollWheelZoom.enable(); });
    mapEl.addEventListener('mouseleave', function() { map.scrollWheelZoom.disable(); });
})();
</script>
