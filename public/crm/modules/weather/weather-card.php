<?php
/**
 * Weather Card Generator Module
 * /crm/modules/weather/weather-card.php
 *
 * Generates a visual PNG weather summary card using PHP GD library.
 * Cards show hourly forecast data, status badge, and key metrics
 * for attachment to visits (billing/audit proof).
 *
 * Output: 600x300 PNG saved to /crm/uploads/weather_cards/
 */

declare(strict_types=1);

// Card dimensions
define('CARD_WIDTH', 600);
define('CARD_HEIGHT', 300);

// Upload directory for weather cards
define('WEATHER_CARD_DIR', dirname(dirname(__DIR__)) . '/uploads/weather_cards');

/**
 * Generate a weather card PNG for a visit.
 *
 * @param array  $snapshot     Weather snapshot data (from saveWeatherSnapshot)
 * @param string $visitNumber  Visit number for filename (e.g., PLN-2026-0001-V001)
 * @param string $date         Visit date YYYY-MM-DD
 * @param string $timeWindow   Time window string (e.g., "08:00 - 12:00")
 * @return string|null File path relative to /crm/ or null on failure
 */
function generateWeatherCard(array $snapshot, string $visitNumber, string $date, string $timeWindow = ''): ?string
{
    if (!extension_loaded('gd')) {
        error_log("Weather card: GD extension not available");
        return null;
    }

    // Ensure output directory exists
    if (!is_dir(WEATHER_CARD_DIR)) {
        @mkdir(WEATHER_CARD_DIR, 0755, true);
    }

    try {
        $img = imagecreatetruecolor(CARD_WIDTH, CARD_HEIGHT);
        if (!$img) return null;

        // Enable anti-aliasing
        imageantialias($img, true);

        // Colors
        $white     = imagecolorallocate($img, 255, 255, 255);
        $darkGray  = imagecolorallocate($img, 51, 51, 51);
        $medGray   = imagecolorallocate($img, 128, 128, 128);
        $lightGray = imagecolorallocate($img, 240, 240, 240);
        $green     = imagecolorallocate($img, 45, 134, 89);   // --mw-green
        $orange    = imagecolorallocate($img, 232, 93, 4);     // --mw-orange
        $red       = imagecolorallocate($img, 220, 53, 69);
        $blue      = imagecolorallocate($img, 52, 144, 220);
        $forest    = imagecolorallocate($img, 13, 59, 46);     // --mw-forest

        // Background
        imagefill($img, 0, 0, $white);

        // Header bar
        $status = $snapshot['status'] ?? 'UNKNOWN';
        $headerColor = $green;
        if ($status === 'NOT_OK') $headerColor = $red;
        elseif ($status === 'BORDERLINE') $headerColor = $orange;

        imagefilledrectangle($img, 0, 0, CARD_WIDTH, 50, $headerColor);

        // Header text
        $statusLabel = $status === 'OK' ? 'WEATHER OK' : ($status === 'NOT_OK' ? 'WEATHER ALERT' : 'BORDERLINE');
        imagestring($img, 5, 15, 8, $statusLabel, $white);
        imagestring($img, 3, 15, 30, "Visit: {$visitNumber}", $white);

        // Date + time on the right
        $dateStr = date('M j, Y', strtotime($date));
        $rightText = $dateStr . ($timeWindow ? "  {$timeWindow}" : '');
        $rightWidth = strlen($rightText) * imagefontwidth(3);
        imagestring($img, 3, CARD_WIDTH - $rightWidth - 15, 18, $rightText, $white);

        // Reason line
        $reason = $snapshot['reason'] ?? '';
        $summary = $snapshot['summary'] ?? '';
        if ($reason || $summary) {
            $reasonText = $reason ? str_replace('_', ' ', $reason) . ': ' : '';
            $reasonText .= $summary;
            // Truncate if too long
            if (strlen($reasonText) > 85) {
                $reasonText = substr($reasonText, 0, 82) . '...';
            }
            imagestring($img, 3, 15, 60, $reasonText, $darkGray);
        }

        // Hourly data bars
        $hourlyWindow = $snapshot['hourly_window'] ?? [];
        if (!empty($hourlyWindow)) {
            $barY = 85;
            $barHeight = 12;
            $maxBars = min(count($hourlyWindow), 12);
            $barWidth = (int)((CARD_WIDTH - 140) / max($maxBars, 1));

            // Labels
            imagestring($img, 2, 15, $barY, 'Precip %', $medGray);
            imagestring($img, 2, 15, $barY + 55, 'Wind km/h', $medGray);
            imagestring($img, 2, 15, $barY + 110, 'Temp C', $medGray);

            $x = 100;
            for ($i = 0; $i < $maxBars; $i++) {
                $block = $hourlyWindow[$i];
                $hour = substr($block['hour'] ?? '', 11, 5);

                // Hour label
                imagestring($img, 1, $x + 2, $barY - 12, $hour, $medGray);

                // Precipitation bar (0-100%)
                $precipPct = min(100, (int)($block['precip_chance_pct'] ?? 0));
                $precipHeight = (int)(($precipPct / 100) * 40);
                $precipColor = $precipPct > 50 ? $red : ($precipPct > 30 ? $orange : $blue);
                imagefilledrectangle($img, $x, $barY + 40 - $precipHeight, $x + $barWidth - 2, $barY + 40, $precipColor);
                if ($precipPct > 0) {
                    imagestring($img, 1, $x + 2, $barY + 42, "{$precipPct}", $medGray);
                }

                // Wind bar
                $windKph = min(80, (int)($block['wind_kph'] ?? 0));
                $windHeight = (int)(($windKph / 80) * 40);
                $windColor = $windKph > 40 ? $red : ($windKph > 25 ? $orange : $green);
                imagefilledrectangle($img, $x, $barY + 95 - $windHeight, $x + $barWidth - 2, $barY + 95, $windColor);
                if ($windKph > 0) {
                    imagestring($img, 1, $x + 2, $barY + 97, "{$windKph}", $medGray);
                }

                // Temperature dot
                $tempC = (float)($block['temp_c'] ?? 0);
                $tempY = $barY + 145 - (int)(($tempC + 10) / 50 * 30); // scale -10..40 to 30px
                $tempY = max($barY + 115, min($barY + 145, $tempY));
                $tempColor = $tempC < 0 ? $blue : ($tempC > 35 ? $red : $green);
                imagefilledellipse($img, $x + (int)($barWidth / 2), $tempY, 6, 6, $tempColor);
                imagestring($img, 1, $x + 2, $barY + 148, number_format($tempC, 0), $medGray);

                $x += $barWidth;
            }
        }

        // Footer
        $footerY = CARD_HEIGHT - 25;
        imagefilledrectangle($img, 0, $footerY, CARD_WIDTH, CARD_HEIGHT, $lightGray);
        $evalTime = $snapshot['evaluated_at'] ?? date('Y-m-d H:i:s');
        imagestring($img, 2, 15, $footerY + 6, "Evaluated: {$evalTime}  |  Mowology Weather Guard", $medGray);

        // Save PNG
        $safeVisitNumber = preg_replace('/[^A-Za-z0-9\-]/', '_', $visitNumber);
        $filename = "{$safeVisitNumber}_weather_" . date('Ymd') . ".png";
        $filepath = WEATHER_CARD_DIR . '/' . $filename;

        imagepng($img, $filepath, 6);
        imagedestroy($img);

        // Return path relative to /crm/
        return 'uploads/weather_cards/' . $filename;
    } catch (Throwable $e) {
        error_log("generateWeatherCard error: " . $e->getMessage());
        return null;
    }
}
