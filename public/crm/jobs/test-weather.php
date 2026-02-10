<?php
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/weather-service.php';

requireLogin();
?>
<!DOCTYPE html>
<html>
<head><title>Weather Debug</title></head>
<body>
<h1>Weather Service Debug</h1>

<h2>Cache Directories</h2>
<pre>
WEATHER_CACHE_DIR: <?php echo WEATHER_CACHE_DIR; ?>
Exists: <?php echo is_dir(WEATHER_CACHE_DIR) ? 'YES' : 'NO'; ?>
Writable: <?php echo is_writable(WEATHER_CACHE_DIR) ? 'YES' : 'NO'; ?>

GEOCODE_CACHE_DIR: <?php echo GEOCODE_CACHE_DIR; ?>
Exists: <?php echo is_dir(GEOCODE_CACHE_DIR) ? 'YES' : 'NO'; ?>
Writable: <?php echo is_writable(GEOCODE_CACHE_DIR) ? 'YES' : 'NO'; ?>
</pre>

<h2>Today's 7-Day Forecast</h2>
<?php
$forecast = getWeekForecast('Vancouver', 'BC');
foreach ($forecast as $date => $data) {
    echo "{$date}: {$data['icon']} {$data['temp_high']}°/{$data['temp_low']}° {$data['condition']}<br>";
}
?>

<h2>Schedule Test (Feb 2-8 Week)</h2>
<?php
$startDate = '2026-02-02';
$todaysForecast = getWeekForecast('Vancouver', 'BC');
$weekWeather = [];
$currentDate = new DateTime($startDate);
$todayIndex = 0;
for ($i = 0; $i < 7; $i++) {
    $dateStr = $currentDate->format('Y-m-d');
    $forecastDate = date('Y-m-d', strtotime("+{$todayIndex} days"));
    $weekWeather[$dateStr] = $todaysForecast[$forecastDate] ?? [
        'temp_high' => 12,
        'temp_low' => 8,
        'condition' => 'Unknown',
        'precipitation' => 0,
        'icon' => '🌤️',
        'wind' => 0,
    ];
    echo "{$dateStr} -> {$forecastDate}: {$weekWeather[$dateStr]['icon']} {$weekWeather[$dateStr]['condition']}<br>";
    $currentDate->modify('+1 day');
    $todayIndex++;
}
?>
</body>
</html>
