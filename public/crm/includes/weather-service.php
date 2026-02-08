<?php
/**
 * Weather Service - Integration with Weather Network
 * Provides weather forecasts for job scheduling and dashboard
 *
 * Usage:
 *   require_once __DIR__ . '/weather-service.php';
 *   $forecast = getWeatherForecast('Vancouver', 'BC');
 *   $weekForecast = getWeekForecast('Vancouver', 'BC');
 */

declare(strict_types=1);

/**
 * Get weather forecast for a specific date and location
 * Uses cached data when available (updates hourly)
 *
 * @param string $city City name
 * @param string $province Province/state code
 * @param string $date Date in YYYY-MM-DD format (null for today)
 * @return array Weather data: ['temp_high', 'temp_low', 'condition', 'precipitation', 'icon', 'wind']
 */
function getWeatherForecast(string $city = 'Vancouver', string $province = 'BC', ?string $date = null): array
{
    if (!$date) {
        $date = date('Y-m-d');
    }

    // Try cache first
    $cacheKey = "weather_{$city}_{$province}_{$date}";
    $cached = getWeatherCache($cacheKey);
    if ($cached) {
        return json_decode($cached, true) ?? [];
    }

    // Fetch from Weather Network (simulated with local data for now)
    // In production, this would call Weather Network API or scrape their site
    $weather = fetchWeatherData($city, $province, $date);

    // Cache for 1 hour
    setWeatherCache($cacheKey, json_encode($weather), 3600);

    return $weather;
}

/**
 * Get 7-day forecast for location
 */
function getWeekForecast(string $city = 'Vancouver', string $province = 'BC'): array
{
    $forecasts = [];

    for ($i = 0; $i < 7; $i++) {
        $date = date('Y-m-d', strtotime("+{$i} days"));
        $forecasts[$date] = getWeatherForecast($city, $province, $date);
    }

    return $forecasts;
}

/**
 * Fetch weather from Weather Network API/service
 *
 * @param string $city
 * @param string $province
 * @param string $date
 * @return array
 */
function fetchWeatherData(string $city, string $province, string $date): array
{
    // Mock data - replace with actual API call to Weather Network
    // Weather Network API: https://www.theweathernetwork.com/

    // Default Vancouver weather pattern for landscaping
    $defaults = [
        'temp_high' => 12,
        'temp_low' => 8,
        'condition' => 'Cloudy',
        'precipitation' => 30,      // % chance
        'icon' => 'cloud',
        'wind' => 15,               // km/h
        'wind_direction' => 'W',
        'humidity' => 75,
        'uv_index' => 3,
    ];

    // Simulate varying weather based on day of week
    $dayOfWeek = date('w', strtotime($date)); // 0=Sunday, 6=Saturday

    // Weekends sometimes worse (just example logic)
    if (in_array($dayOfWeek, [0, 6])) {
        $defaults['precipitation'] += 15;
        $defaults['temp_high'] -= 2;
    }

    // Add some randomness for future dates
    $daysAhead = (int)((strtotime($date) - time()) / 86400);
    if ($daysAhead > 0) {
        // Very rough prediction for days ahead
        $defaults['temp_high'] += rand(-3, 3);
        $defaults['temp_low'] += rand(-3, 3);
        $defaults['precipitation'] += rand(-10, 10);
    }

    return $defaults;
}

/**
 * Get weather cache
 */
function getWeatherCache(string $key): ?string
{
    $cacheDir = dirname(__DIR__) . '/../../storage/cache';

    // Create cache dir if needed
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0755, true);
    }

    $cacheFile = $cacheDir . '/' . md5($key) . '.weather';

    if (file_exists($cacheFile)) {
        $expiry = (int)file_get_contents($cacheFile . '.exp');
        if ($expiry > time()) {
            return file_get_contents($cacheFile);
        } else {
            @unlink($cacheFile);
            @unlink($cacheFile . '.exp');
        }
    }

    return null;
}

/**
 * Set weather cache
 */
function setWeatherCache(string $key, string $data, int $ttl = 3600): bool
{
    $cacheDir = dirname(__DIR__) . '/../../storage/cache';

    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0755, true);
    }

    $cacheFile = $cacheDir . '/' . md5($key) . '.weather';

    try {
        file_put_contents($cacheFile, $data);
        file_put_contents($cacheFile . '.exp', (string)(time() + $ttl));
        return true;
    } catch (Throwable $e) {
        error_log("Weather cache write error: " . $e->getMessage());
        return false;
    }
}

/**
 * Get weather icon emoji/class for condition
 */
function getWeatherIcon(string $condition): string
{
    $condition = strtolower($condition);

    $icons = [
        'sunny' => '☀️',
        'clear' => '☀️',
        'partly cloudy' => '⛅',
        'cloudy' => '☁️',
        'overcast' => '☁️',
        'rain' => '🌧️',
        'rainy' => '🌧️',
        'showers' => '🌧️',
        'thunderstorm' => '⛈️',
        'snow' => '❄️',
        'snowing' => '❄️',
        'sleet' => '🌨️',
        'fog' => '🌫️',
        'mist' => '🌫️',
        'wind' => '💨',
        'windy' => '💨',
    ];

    foreach ($icons as $key => $icon) {
        if (strpos($condition, $key) !== false) {
            return $icon;
        }
    }

    return '🌤️'; // Default
}

/**
 * Get weather CSS class for styling
 */
function getWeatherClass(string $condition, int $precipitation): string
{
    $condition = strtolower($condition);

    if ($precipitation > 60) {
        return 'weather-poor';      // Heavy rain - not ideal
    } elseif ($precipitation > 30) {
        return 'weather-fair';      // Moderate rain - caution
    } elseif (strpos($condition, 'snow') !== false || strpos($condition, 'thunderstorm') !== false) {
        return 'weather-poor';
    } elseif (strpos($condition, 'rain') !== false) {
        return 'weather-fair';
    } else {
        return 'weather-good';      // Clear/mostly clear
    }
}

/**
 * Get suitability score for landscaping work (0-100)
 * Higher = better for work
 */
function getWorkSuitability(array $weather): int
{
    $score = 100;

    // Precipitation reduces score significantly
    $score -= ($weather['precipitation'] ?? 0) * 0.8;

    // Temperature: ideally 10-20°C for outdoor work
    $temp = (int)(($weather['temp_high'] ?? 12) + ($weather['temp_low'] ?? 8)) / 2;
    if ($temp < 5 || $temp > 28) {
        $score -= 15;
    }

    // Wind: high wind not good for detail work
    $wind = (int)($weather['wind'] ?? 0);
    if ($wind > 30) {
        $score -= 20;
    } elseif ($wind > 20) {
        $score -= 10;
    }

    return max(0, min(100, (int)$score));
}

/**
 * Format weather for display
 */
function formatWeatherDisplay(array $weather): string
{
    $icon = getWeatherIcon($weather['condition'] ?? 'Clear');
    $high = (int)($weather['temp_high'] ?? 12);
    $low = (int)($weather['temp_low'] ?? 8);
    $condition = htmlspecialchars($weather['condition'] ?? 'Clear');
    $precip = (int)($weather['precipitation'] ?? 0);
    $wind = (int)($weather['wind'] ?? 0);

    return "{$icon} {$high}°/{$low}° {$condition} | {$precip}% rain | {$wind} km/h";
}
