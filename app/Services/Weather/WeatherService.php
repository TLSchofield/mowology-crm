<?php
/**
 * Weather Service - Integration with Open-Meteo API
 * Provides real weather forecasts for job scheduling and dashboard
 *
 * API: https://open-meteo.com/en/docs
 * - No API key required
 * - Free tier: 100,000 calls/day
 * - 7-day forecast available
 * - Returns WMO weather codes
 *
 * Usage:
 *   require_once APP_ROOT . '/Services/Weather/WeatherService.php';
 *   $forecast = getWeatherForecast('Vancouver', 'BC');
 *   $weekForecast = getWeekForecast('Vancouver', 'BC');
 *
 * Migrated from: public/crm/includes/weather-service.php
 */

declare(strict_types=1);

if (!defined('APP_ROOT')) {
    require_once dirname(__DIR__, 2) . '/Core/paths.php';
}

/**
 * Cache directory constant
 */
if (!defined('WEATHER_CACHE_DIR')) {
    define('WEATHER_CACHE_DIR', STORAGE_ROOT . '/cache/weather');
}

/**
 * Geocode cache for city/province -> lat/lng
 */
if (!defined('GEOCODE_CACHE_DIR')) {
    define('GEOCODE_CACHE_DIR', STORAGE_ROOT . '/cache/geocode');
}

/**
 * Get weather forecast for a specific date and location
 * Uses cached 7-day forecast when available (2-hour TTL)
 * Note: API can only provide current & future forecasts, not historical data
 * Past dates return fallback values
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

    // If requested date is in the past, return fallback (API doesn't provide historical)
    $today = date('Y-m-d');
    if ($date < $today) {
        return [
            'temp_high' => 12,
            'temp_low' => 8,
            'condition' => 'Historical',
            'precipitation' => 0,
            'icon' => '',
            'wind' => 0,
        ];
    }

    // Load entire week from cache or API
    $weekForecast = getWeekForecastCached($city, $province);

    // Return specific date or empty array if not found
    return $weekForecast[$date] ?? [
        'temp_high' => 12,
        'temp_low' => 8,
        'condition' => 'Unknown',
        'precipitation' => 0,
        'icon' => '',
        'wind' => 0,
    ];
}

/**
 * Get 7-day forecast for location (returns cached object)
 * Cache key format: weather_week_vancouver_bc
 */
function getWeekForecast(string $city = 'Vancouver', string $province = 'BC'): array
{
    return getWeekForecastCached($city, $province);
}

/**
 * Get 7-day forecast from cache or fetch from API
 * Caches entire week as single object for 2 hours
 */
function getWeekForecastCached(string $city = 'Vancouver', string $province = 'BC'): array
{
    $cacheKey = "weather_week_" . strtolower($city) . "_" . strtolower($province);

    // Try cache first
    $cached = getWeatherCache($cacheKey);
    if ($cached !== null) {
        $decoded = json_decode($cached, true);
        if (is_array($decoded) && !empty($decoded)) {
            return $decoded;
        }
    }

    // Fetch from API
    $forecast = fetchWeatherDataFromAPI($city, $province);

    // Cache for 2 hours
    if (!empty($forecast)) {
        setWeatherCache($cacheKey, json_encode($forecast), 7200);
    }

    return $forecast;
}

/**
 * Fetch weather from Open-Meteo API
 * Returns normalized 7-day forecast or empty array on failure
 *
 * @param string $city
 * @param string $province
 * @return array Keyed by YYYY-MM-DD with normalized weather data
 */
function fetchWeatherDataFromAPI(string $city, string $province): array
{
    // Get coordinates for city
    $coords = geocodeCity($city, $province);
    if (!$coords) {
        error_log("Weather API: Failed to geocode {$city}, {$province}");
        return [];
    }

    $latitude = $coords['latitude'];
    $longitude = $coords['longitude'];

    // Fetch forecast from Open-Meteo
    $url = sprintf(
        'https://api.open-meteo.com/v1/forecast?latitude=%f&longitude=%f&daily=weather_code,temperature_2m_max,temperature_2m_min,precipitation_sum,wind_speed_10m_max&temperature_unit=celsius&wind_speed_unit=kmh&timezone=auto',
        $latitude,
        $longitude
    );

    try {
        $response = httpGetRequest($url);
        if (!$response) {
            error_log("Weather API: Failed to fetch from Open-Meteo");
            return [];
        }

        $data = json_decode($response, true);
        if (!isset($data['daily']) || !isset($data['daily']['time'])) {
            error_log("Weather API: Invalid response structure from Open-Meteo");
            return [];
        }

        $daily = $data['daily'];
        $forecast = [];

        // Normalize each day
        for ($i = 0; $i < count($daily['time']); $i++) {
            $date = $daily['time'][$i];

            // Get WMO weather code and convert to condition string
            $wmoCode = (int)($daily['weather_code'][$i] ?? 0);
            $condition = wmoCodeToCondition($wmoCode);

            // Extract temperature, precipitation, wind
            $tempHigh = (int)round((float)($daily['temperature_2m_max'][$i] ?? 12));
            $tempLow = (int)round((float)($daily['temperature_2m_min'][$i] ?? 8));
            $precipitation = (int)round((float)($daily['precipitation_sum'][$i] ?? 0));
            $wind = (int)round((float)($daily['wind_speed_10m_max'][$i] ?? 0));

            // Clamp precipitation 0-100
            $precipitation = max(0, min(100, $precipitation));

            $forecast[$date] = [
                'temp_high' => $tempHigh,
                'temp_low' => $tempLow,
                'condition' => $condition,
                'precipitation' => $precipitation,
                'icon' => getWeatherIcon($condition),
                'wind' => $wind,
            ];
        }

        return $forecast;
    } catch (Throwable $e) {
        error_log("Weather API error: " . $e->getMessage());
        return [];
    }
}

/**
 * Geocode a city/province to lat/lng using Open-Meteo Geocoding API
 * Caches results for 30 days
 */
function geocodeCity(string $city, string $province): ?array
{
    $cacheKey = "geocode_" . strtolower($city) . "_" . strtolower($province);

    // Try cache first
    $cached = getGeocodeCache($cacheKey);
    if ($cached !== null) {
        $decoded = json_decode($cached, true);
        if (is_array($decoded) && isset($decoded['latitude'], $decoded['longitude'])) {
            return $decoded;
        }
    }

    // Fetch from API
    $url = sprintf(
        'https://geocoding-api.open-meteo.com/v1/search?name=%s&admin_1=%s&country=Canada&count=1&language=en',
        urlencode($city),
        urlencode($province)
    );

    try {
        $response = httpGetRequest($url);
        if (!$response) {
            return null;
        }

        $data = json_decode($response, true);
        if (!isset($data['results']) || empty($data['results'])) {
            return null;
        }

        $result = $data['results'][0];
        $coords = [
            'latitude' => (float)$result['latitude'],
            'longitude' => (float)$result['longitude'],
            'city' => $result['name'] ?? $city,
            'province' => $result['admin1'] ?? $province,
        ];

        // Cache for 30 days
        setGeocodeCache($cacheKey, json_encode($coords), 2592000);

        return $coords;
    } catch (Throwable $e) {
        error_log("Geocoding error: " . $e->getMessage());
        return null;
    }
}

/**
 * Convert WMO weather code to human-readable condition
 * Reference: https://www.weatherapi.com/docs/weather_codes.asp
 *
 * @param int $code WMO weather code (0-99)
 * @return string Condition string
 */
function wmoCodeToCondition(int $code): string
{
    // WMO codes are grouped:
    // 0-3: Clear/Cloudy
    // 45-48: Fog
    // 51-67: Rain
    // 71-77, 80-82: Snow/Sleet
    // 85-86: Snow showers
    // 80-82: Rain showers
    // 95-99: Thunderstorm

    if ($code === 0) return 'Clear';
    if ($code === 1 || $code === 2) return 'Partly Cloudy';
    if ($code === 3) return 'Overcast';
    if ($code === 45 || $code === 48) return 'Fog';
    if ($code >= 51 && $code <= 67) return 'Rain';
    if ($code >= 71 && $code <= 77) return 'Snow';
    if ($code === 80 || $code === 81) return 'Rain Showers';
    if ($code === 82) return 'Heavy Rain Showers';
    if ($code === 85 || $code === 86) return 'Snow Showers';
    if ($code >= 95 && $code <= 99) return 'Thunderstorm';

    return 'Unknown';
}

/**
 * Get weather cache
 */
function getWeatherCache(string $key): ?string
{
    ensureCacheDirectory(WEATHER_CACHE_DIR);

    $cacheFile = WEATHER_CACHE_DIR . '/' . md5($key) . '.json';

    if (!file_exists($cacheFile)) {
        return null;
    }

    // Check expiry
    $expFile = $cacheFile . '.exp';
    if (file_exists($expFile)) {
        $expiry = (int)file_get_contents($expFile);
        if ($expiry <= time()) {
            @unlink($cacheFile);
            @unlink($expFile);
            return null;
        }
    }

    return file_get_contents($cacheFile);
}

/**
 * Set weather cache
 */
function setWeatherCache(string $key, string $data, int $ttl = 7200): bool
{
    ensureCacheDirectory(WEATHER_CACHE_DIR);

    $cacheFile = WEATHER_CACHE_DIR . '/' . md5($key) . '.json';

    try {
        file_put_contents($cacheFile, $data, LOCK_EX);
        file_put_contents($cacheFile . '.exp', (string)(time() + $ttl), LOCK_EX);
        return true;
    } catch (Throwable $e) {
        error_log("Weather cache write error: " . $e->getMessage());
        return false;
    }
}

/**
 * Get geocode cache
 */
function getGeocodeCache(string $key): ?string
{
    ensureCacheDirectory(GEOCODE_CACHE_DIR);

    $cacheFile = GEOCODE_CACHE_DIR . '/' . md5($key) . '.json';

    if (!file_exists($cacheFile)) {
        return null;
    }

    // Check expiry
    $expFile = $cacheFile . '.exp';
    if (file_exists($expFile)) {
        $expiry = (int)file_get_contents($expFile);
        if ($expiry <= time()) {
            @unlink($cacheFile);
            @unlink($expFile);
            return null;
        }
    }

    return file_get_contents($cacheFile);
}

/**
 * Set geocode cache
 */
function setGeocodeCache(string $key, string $data, int $ttl = 2592000): bool
{
    ensureCacheDirectory(GEOCODE_CACHE_DIR);

    $cacheFile = GEOCODE_CACHE_DIR . '/' . md5($key) . '.json';

    try {
        file_put_contents($cacheFile, $data, LOCK_EX);
        file_put_contents($cacheFile . '.exp', (string)(time() + $ttl), LOCK_EX);
        return true;
    } catch (Throwable $e) {
        error_log("Geocode cache write error: " . $e->getMessage());
        return false;
    }
}

/**
 * Ensure cache directory exists and is writable
 */
function ensureCacheDirectory(string $dir): bool
{
    if (is_dir($dir) && is_writable($dir)) {
        return true;
    }

    try {
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return is_dir($dir) && is_writable($dir);
    } catch (Throwable $e) {
        error_log("Failed to create cache directory {$dir}: " . $e->getMessage());
        return false;
    }
}

/**
 * Get weather icon emoji for condition
 */
function getWeatherIcon(string $condition): string
{
    $condition = strtolower($condition);

    $icons = [
        'clear' => 'sunny',
        'sunny' => 'sunny',
        'partly cloudy' => 'partly-cloudy',
        'cloudy' => 'cloudy',
        'overcast' => 'cloudy',
        'rain' => 'rainy',
        'rainy' => 'rainy',
        'rain showers' => 'rainy',
        'heavy rain' => 'rainy',
        'heavy rain showers' => 'rainy',
        'showers' => 'rainy',
        'thunderstorm' => 'storm',
        'snow' => 'snow',
        'snowing' => 'snow',
        'snow showers' => 'snow',
        'sleet' => 'sleet',
        'fog' => 'fog',
        'mist' => 'fog',
        'wind' => 'windy',
        'windy' => 'windy',
    ];

    foreach ($icons as $key => $icon) {
        if (strpos($condition, $key) !== false) {
            return $icon;
        }
    }

    return 'partly-cloudy'; // Default
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

    // Temperature: ideally 10-20 C for outdoor work
    $temp = (int)(($weather['temp_high'] ?? 12 + $weather['temp_low'] ?? 8) / 2);
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

    return "{$icon} {$high}/{$low} {$condition} | {$precip}% rain | {$wind} km/h";
}

// ============================================================================
// HOURLY FORECAST FUNCTIONS (for weather-aware scheduling)
// ============================================================================

/**
 * Hourly forecast cache directory (same as weather cache)
 */
if (!defined('HOURLY_CACHE_DIR')) {
    define('HOURLY_CACHE_DIR', STORAGE_ROOT . '/cache/weather');
}

/**
 * Get hourly forecast for a specific location by lat/lng.
 * Returns normalized hourly blocks for the next 7 days.
 * Cached with 2-hour TTL (separate from daily cache).
 *
 * @param float $lat Latitude
 * @param float $lon Longitude
 * @return array Hourly blocks: [{hour, temp_c, precip_chance_pct, precip_mm, wind_kph, condition, icon}]
 */
function getHourlyForecast(float $lat, float $lon): array
{
    $cacheKey = "hourly_" . round($lat, 4) . "_" . round($lon, 4);

    $cached = getWeatherCache($cacheKey);
    if ($cached !== null) {
        $decoded = json_decode($cached, true);
        if (is_array($decoded) && !empty($decoded)) {
            return $decoded;
        }
    }

    $forecast = fetchHourlyForecastFromAPI($lat, $lon);

    if (!empty($forecast)) {
        setWeatherCache($cacheKey, json_encode($forecast), 7200);
    }

    return $forecast;
}

/**
 * Fetch hourly forecast from Open-Meteo API.
 * Requests: temperature, precipitation probability, precipitation amount,
 * wind speed, and weather code on an hourly basis.
 *
 * @param float $lat
 * @param float $lon
 * @return array Normalized hourly blocks
 */
function fetchHourlyForecastFromAPI(float $lat, float $lon): array
{
    $url = sprintf(
        'https://api.open-meteo.com/v1/forecast?latitude=%f&longitude=%f'
        . '&hourly=temperature_2m,precipitation_probability,precipitation,wind_speed_10m,weather_code'
        . '&temperature_unit=celsius&wind_speed_unit=kmh&timezone=auto',
        $lat,
        $lon
    );

    try {
        $response = httpGetRequest($url);
        if (!$response) {
            error_log("Hourly Weather API: Failed to fetch from Open-Meteo");
            return [];
        }

        $data = json_decode($response, true);
        if (!isset($data['hourly']) || !isset($data['hourly']['time'])) {
            error_log("Hourly Weather API: Invalid response structure");
            return [];
        }

        $hourly = $data['hourly'];
        $forecast = [];

        for ($i = 0; $i < count($hourly['time']); $i++) {
            $forecast[] = [
                'hour'               => $hourly['time'][$i],
                'temp_c'             => round((float)($hourly['temperature_2m'][$i] ?? 0), 1),
                'precip_chance_pct'  => (int)($hourly['precipitation_probability'][$i] ?? 0),
                'precip_mm'          => round((float)($hourly['precipitation'][$i] ?? 0), 2),
                'wind_kph'           => round((float)($hourly['wind_speed_10m'][$i] ?? 0), 1),
                'condition'          => wmoCodeToCondition((int)($hourly['weather_code'][$i] ?? 0)),
                'icon'               => getWeatherIcon(wmoCodeToCondition((int)($hourly['weather_code'][$i] ?? 0))),
            ];
        }

        return $forecast;
    } catch (Throwable $e) {
        error_log("Hourly Weather API error: " . $e->getMessage());
        return [];
    }
}

/**
 * Get hourly forecast for a specific visit window (date + time range).
 * Filters the full hourly forecast to only include hours within the window.
 *
 * @param float  $lat       Latitude
 * @param float  $lon       Longitude
 * @param string $date      Date in YYYY-MM-DD format
 * @param string $timeStart Start time in HH:MM format
 * @param string $timeEnd   End time in HH:MM format
 * @return array Filtered hourly blocks for the visit window
 */
function getHourlyForecastWindow(float $lat, float $lon, string $date, string $timeStart, string $timeEnd): array
{
    $allHourly = getHourlyForecast($lat, $lon);
    if (empty($allHourly)) {
        return [];
    }

    $windowStart = $date . 'T' . $timeStart;
    $windowEnd   = $date . 'T' . $timeEnd;

    $filtered = [];
    foreach ($allHourly as $block) {
        $blockHour = $block['hour'];
        // Open-Meteo returns ISO format: "2026-02-13T08:00"
        if ($blockHour >= $windowStart && $blockHour <= $windowEnd) {
            $filtered[] = $block;
        }
    }

    // If no exact matches (edge case), include the closest hour before start
    if (empty($filtered)) {
        $datePrefix = $date . 'T';
        foreach ($allHourly as $block) {
            if (strpos($block['hour'], $datePrefix) === 0) {
                $filtered[] = $block;
            }
        }
    }

    return $filtered;
}

/**
 * Get hourly forecast using city/province (geocodes first, then fetches).
 * Convenience wrapper for use outside lat/lng context.
 *
 * @param string $city
 * @param string $province
 * @return array Hourly blocks
 */
function getHourlyForecastByCity(string $city = 'Vancouver', string $province = 'BC'): array
{
    $coords = geocodeCity($city, $province);
    if (!$coords) {
        return [];
    }
    return getHourlyForecast($coords['latitude'], $coords['longitude']);
}

// ============================================================================
// HTTP HELPERS
// ============================================================================

/**
 * HTTP GET request helper with cURL fallback
 * Uses cURL if available (preferred), falls back to file_get_contents
 */
function httpGetRequest(string $url, int $timeout = 10): ?string
{
    // Try cURL first (most reliable)
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mowology CRM Weather Service');

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($response && $httpCode >= 200 && $httpCode < 300) {
            return $response;
        }
    }

    // Fallback to file_get_contents with stream context
    try {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $timeout,
                'user_agent' => 'Mowology CRM Weather Service',
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        if ($response) {
            return $response;
        }
    } catch (Throwable $e) {
        error_log("httpGetRequest error: " . $e->getMessage());
    }

    return null;
}
