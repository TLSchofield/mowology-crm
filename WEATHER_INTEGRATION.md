# Weather Integration for Mowology CRM

## Overview

The Mowology CRM now includes integrated weather forecasting to help you schedule jobs based on weather conditions. This is critical for landscaping work where weather significantly impacts:

- Job scheduling (rain, snow, extreme temperatures)
- Work quality and safety
- Equipment operation
- Client satisfaction

## Features

### 1. **Job Schedule Calendar** (`/crm/jobs/schedule.php`)
- Weather forecast displayed above each day
- Shows temperature, conditions, precipitation %, and wind speed
- Color-coded work suitability indicators:
  - **Green (✓ Good)**: Ideal conditions for work (score ≥70)
  - **Yellow (⚠ Fair)**: Caution conditions (score 40-70)
  - **Red (✗ Poor)**: Not recommended (score <40)

### 2. **Dashboard Weather Widget** (`/crm/dashboard_appstack.php`)
- 7-day forecast overview
- Quick reference for planning
- Daily high/low temps, precipitation chance

### 3. **Work Suitability Scoring**
Automatically calculated based on:
- **Precipitation**: >60% = poor, >30% = fair
- **Temperature**: <5°C or >28°C reduces score
- **Wind**: >30 km/h = poor, >20 km/h = fair
- Base score: 100 (adjusted by conditions)

## Technical Details

### Weather Service Module
**Location**: `/crm/includes/weather-service.php`

#### Key Functions

```php
// Get forecast for specific day
getWeatherForecast($city, $province, $date): array
// Returns: ['temp_high', 'temp_low', 'condition', 'precipitation', 'wind', etc.]

// Get 7-day forecast
getWeekForecast($city, $province): array
// Returns: ['YYYY-MM-DD' => weather array, ...]

// Calculate work suitability (0-100)
getWorkSuitability(array $weather): int

// Format for display
formatWeatherDisplay(array $weather): string

// Get emoji icon for condition
getWeatherIcon(string $condition): string

// Get CSS class for styling
getWeatherClass(string $condition, int $precipitation): string
```

### Data Structure

Weather data array:
```php
[
    'temp_high'       => 15,           // °C
    'temp_low'        => 8,            // °C
    'condition'       => 'Cloudy',     // Text description
    'precipitation'   => 35,           // % chance of rain
    'icon'            => 'cloud',      // Icon identifier
    'wind'            => 15,           // km/h
    'wind_direction'  => 'W',          // Cardinal direction
    'humidity'        => 75,           // %
    'uv_index'        => 3             // 0-11 scale
]
```

### Caching

Weather data is cached for 1 hour to reduce API calls:
- Cache location: `/storage/cache/` (auto-created)
- Cache files named by md5 hash of cache key
- Auto-expires after 1 hour

## Styling

### CSS Classes Available

**Weather widgets**:
- `.mw-weather-widget` - General weather display
- `.mw-day-weather` - Calendar day forecast
- `.mw-dashboard-weather` - Dashboard forecast card

**Status indicators**:
- `.weather-good` - Green, ideal conditions
- `.weather-fair` - Yellow, caution conditions
- `.weather-poor` - Red, poor conditions

**Suitability badges**:
- `.mw-suitability-good`
- `.mw-suitability-fair`
- `.mw-suitability-poor`

All styles in `/crm/css/mowology-brand.css` under "Weather Integration" section.

## Integration Points

### Schedule Page
```php
require_once dirname(__DIR__) . '/includes/weather-service.php';

// In calendar day loop:
$weather = getWeatherForecast('Vancouver', 'BC', $dateStr);
$workSuitability = getWorkSuitability($weather);
```

### Dashboard
```php
require_once 'includes/weather-service.php';

// Get week forecast:
$weekWeather = getWeekForecast('Vancouver', 'BC');

// In display:
foreach ($weekWeather as $dateStr => $weather) {
    $icon = getWeatherIcon($weather['condition']);
    // Display...
}
```

## Current Implementation

### Data Source
Currently using **mock/simulated data** with realistic patterns:
- Default Vancouver weather based on historical patterns
- Weekend variations
- Future date predictions

### Production Upgrade Path

To connect to real Weather Network data:

1. **Option A: Weather Network API** (Recommended)
   - Register at weathernetwork.com
   - Replace `fetchWeatherData()` function
   - Parse API response into standard array format

2. **Option B: Web Scraping**
   - Parse Weather Network webpage HTML
   - Extract forecast data using simple regex/DOM parsing
   - Less reliable but no API key needed

3. **Option C: Third-party Weather API**
   - OpenWeatherMap (free tier available)
   - Weather API (weatherapi.com)
   - Replace `fetchWeatherData()` with API call

### Example API Integration
```php
function fetchWeatherData(string $city, string $province, string $date): array
{
    // Call Weather Network API or other service
    $apiUrl = "https://api.weathernetwork.com/forecast/{$city}/{$date}";
    $response = file_get_contents($apiUrl);
    $data = json_decode($response, true);

    return [
        'temp_high' => (int)$data['tempMax'],
        'temp_low' => (int)$data['tempMin'],
        'condition' => $data['condition'],
        'precipitation' => (int)$data['precipChance'],
        'wind' => (int)$data['windSpeed'],
        'wind_direction' => $data['windDirection'],
        'humidity' => (int)$data['humidity'],
        'uv_index' => (int)$data['uvIndex'],
    ];
}
```

## Usage Examples

### Example 1: Check if day is suitable for jobs
```php
$weather = getWeatherForecast('Vancouver', 'BC', '2026-02-10');
$score = getWorkSuitability($weather);

if ($score >= 70) {
    // Good day, show as available
} elseif ($score >= 40) {
    // Fair day, show with warning
} else {
    // Poor day, recommend reschedule
}
```

### Example 2: Display weather in job list
```php
$weather = getWeatherForecast('Vancouver', 'BC', $jobDate);
$formatted = formatWeatherDisplay($weather);
echo "Forecast: " . $formatted;
// Output: ☁️ 15°/8° Cloudy | 30% rain | 15 km/h
```

### Example 3: Custom location handling
```php
// Get city from property
$stmt = $db->prepare("SELECT city FROM properties WHERE id = ?");
$stmt->execute([$propertyId]);
$property = $stmt->fetch();

// Get forecast for property location
$weather = getWeatherForecast($property['city'], 'BC', $date);
```

## Future Enhancements

1. **Alerts & Notifications**
   - Email/SMS when severe weather forecast
   - Auto-notify customers of weather delays

2. **Historical Weather Data**
   - Track actual weather vs forecast accuracy
   - Build seasonal patterns
   - Improve future predictions

3. **Job-Specific Rules**
   - Define min/max temps per service type
   - Snow removal requires snow forecast
   - Hedge trimming needs low wind

4. **Location Support**
   - Different forecasts for different job locations
   - Service area weather summaries
   - Regional patterns

5. **Analytics Dashboard**
   - Weather impact on productivity
   - Revenue loss due to weather delays
   - Optimal scheduling patterns

## Troubleshooting

### Cache Directory Not Writable
If weather data doesn't cache:
```bash
mkdir -p /public/storage/cache
chmod 755 /public/storage/cache
```

### Location-Specific Forecast
Edit the city/province in calls:
```php
// For Burnaby jobs
$weather = getWeatherForecast('Burnaby', 'BC', $date);

// For Richmond jobs
$weather = getWeatherForecast('Richmond', 'BC', $date);
```

### Adjust Work Suitability Thresholds
Modify `getWorkSuitability()` function in weather-service.php:
```php
// Current: Reduce score by 0.8 per % precipitation
// Change to: $score -= ($weather['precipitation'] ?? 0) * 1.0; // More strict
```

## Files Modified/Created

- **Created**: `/crm/includes/weather-service.php` (Main weather service)
- **Modified**: `/crm/jobs/schedule.php` (Added weather display to calendar)
- **Modified**: `/crm/dashboard_appstack.php` (Added 7-day forecast widget)
- **Modified**: `/crm/css/mowology-brand.css` (Added weather styling)

## Notes

- Weather integration is **location-aware** for future multi-location support
- All weather data uses **24-hour time format** internally
- Temperatures displayed in **Celsius** (adjust as needed)
- Wind speeds in **km/h** (adjust for mph if needed)
- All timezone-aware calculations ready for future timezone support
