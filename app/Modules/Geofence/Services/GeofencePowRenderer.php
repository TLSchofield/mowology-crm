<?php
/**
 * GeofencePowRenderer — Injects geofence data into Proof-of-Work output.
 *
 * This is a read-only renderer. It does not modify any PoW tables.
 * Call geofencePowSection() to get an HTML snippet you can embed in
 * the PoW detail view or pass to PowPdfGenerator.
 *
 * How to integrate:
 *   In visit-detail.php / PowPdfGenerator.php, add:
 *
 *       if (function_exists('geofencePowSection')) {
 *           $geofenceHtml = geofencePowSection($visitId);
 *           // inject into PDF/HTML template
 *       }
 *
 * The section renders only if:
 *   - The geofence.pow_section_enabled setting is '1'
 *   - A session row exists for the visit with tracking_active = 1
 *
 * @package Mowology\Geofence
 */
declare(strict_types=1);

if (!function_exists('geofenceGetSession')) {
    // Load model if not already loaded
    $__geoModelPath = dirname(__DIR__) . '/Models/GeofenceModel.php';
    if (is_file($__geoModelPath)) {
        require_once $__geoModelPath;
    }
    unset($__geoModelPath);
}

/**
 * Generate the geofence HTML section for PoW output.
 *
 * Returns an HTML string (suitable for both screen and PDF via mPDF/Dompdf).
 * Returns an empty string if geofence tracking was not active or is disabled.
 *
 * @param int  $visitId
 * @param bool $forPdf   If true, uses inline styles (PDF-safe). If false, uses CSS classes.
 */
function geofencePowSection(int $visitId, bool $forPdf = false): string {
    if (!$visitId) return '';

    // Check master toggle
    $settings = function_exists('geofenceGetSettings') ? geofenceGetSettings() : [];
    if (($settings['geofence.pow_section_enabled'] ?? '1') !== '1') return '';

    // Load session
    $session = function_exists('geofenceGetSession') ? geofenceGetSession($visitId) : null;
    if (!$session || !$session['tracking_active']) return '';

    // Load events
    $events = function_exists('geofenceGetEvents') ? geofenceGetEvents($visitId) : [];

    // Format helpers
    $fmt = static function(int $seconds): string {
        $h = (int)($seconds / 3600);
        $m = (int)(($seconds % 3600) / 60);
        $s = $seconds % 60;
        if ($h > 0) return "{$h}h {$m}m";
        if ($m > 0) return "{$m}m {$s}s";
        return "{$s}s";
    };

    $confidence = $session['confidence_score'] !== null ? (int)$session['confidence_score'] : null;
    $inZoneSec  = (int)($session['in_zone_seconds'] ?? 0);
    $outZoneSec = (int)($session['out_zone_seconds'] ?? 0);
    $totalSec   = (int)($session['total_seconds'] ?? 0);
    $entries    = (int)($session['entry_count'] ?? 0);
    $exits      = (int)($session['exit_count'] ?? 0);
    $samples    = (int)($session['sample_count'] ?? 0);

    // Confidence colour
    $confColor = '#6c757d'; // grey = unknown
    if ($confidence !== null) {
        if ($confidence >= 85) $confColor = '#2D8659';       // green
        elseif ($confidence >= 60) $confColor = '#e8a020';   // amber
        else $confColor = '#dc3545';                          // red
    }

    $confLabel = $confidence !== null ? "{$confidence}%" : 'N/A';

    // Build event timeline rows
    $timelineRows = '';
    foreach ($events as $ev) {
        $icon  = $ev['event_type'] === 'entry' ? '→' : '←';
        $label = $ev['event_type'] === 'entry' ? 'Entered work zone' : 'Left work zone';
        $dur   = '';
        if ($ev['event_type'] === 'exit' && $ev['duration_seconds'] !== null) {
            $dur = ' <span style="color:#6c757d;font-size:0.85em;">(' . $fmt((int)$ev['duration_seconds']) . ' in zone)</span>';
        }
        $time = date('g:i:s A', strtotime($ev['event_time']));
        $timelineRows .= "
            <tr>
                <td style=\"padding:4px 8px;color:" . ($ev['event_type'] === 'entry' ? '#2D8659' : '#dc3545') . ";font-weight:600;\">{$icon}</td>
                <td style=\"padding:4px 8px;\">{$time}</td>
                <td style=\"padding:4px 8px;\">{$label}{$dur}</td>
            </tr>";
    }

    // Confidence bar (0–100)
    $barWidth = $confidence !== null ? $confidence : 0;

    ob_start();
    ?>
    <!-- ═══════════════════════════════════════════════════════════
         GEOFENCE TRACKING SECTION — Proof of Work
    ═══════════════════════════════════════════════════════════ -->
    <div class="mw-geofence-pow-section" style="margin:24px 0;border:1px solid #dee2e6;border-radius:8px;overflow:hidden;font-family:inherit;">

      <!-- Header -->
      <div style="background:#0D3B2E;color:#fff;padding:12px 16px;display:flex;align-items:center;gap:10px;">
        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"
             fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <polygon points="3 11 22 2 13 21 11 13 3 11"/>
        </svg>
        <strong style="font-size:0.95em;letter-spacing:.02em;">Work-Zone Compliance</strong>
        <span style="margin-left:auto;font-size:0.8em;opacity:.7;">Polygon Geofence Tracking</span>
      </div>

      <!-- Stats row -->
      <div style="display:flex;flex-wrap:wrap;background:#f8f9fa;border-bottom:1px solid #dee2e6;">

        <!-- Confidence Score -->
        <div style="flex:1;min-width:140px;padding:14px 16px;border-right:1px solid #dee2e6;">
          <div style="font-size:0.7em;color:#6c757d;text-transform:uppercase;letter-spacing:.06em;margin-bottom:4px;">Confidence Score</div>
          <div style="font-size:1.6em;font-weight:700;color:<?= $confColor ?>;"><?= $confLabel ?></div>
          <div style="margin-top:6px;height:5px;background:#e9ecef;border-radius:3px;overflow:hidden;">
            <div style="width:<?= $barWidth ?>%;height:100%;background:<?= $confColor ?>;border-radius:3px;transition:width .3s;"></div>
          </div>
        </div>

        <!-- In-zone time -->
        <div style="flex:1;min-width:120px;padding:14px 16px;border-right:1px solid #dee2e6;">
          <div style="font-size:0.7em;color:#6c757d;text-transform:uppercase;letter-spacing:.06em;margin-bottom:4px;">In Work Zone</div>
          <div style="font-size:1.3em;font-weight:700;color:#2D8659;"><?= $fmt($inZoneSec) ?></div>
        </div>

        <!-- Out-of-zone time -->
        <div style="flex:1;min-width:120px;padding:14px 16px;border-right:1px solid #dee2e6;">
          <div style="font-size:0.7em;color:#6c757d;text-transform:uppercase;letter-spacing:.06em;margin-bottom:4px;">Outside Zone</div>
          <div style="font-size:1.3em;font-weight:700;color:<?= $outZoneSec > 300 ? '#e8a020' : '#495057' ?>;"><?= $fmt($outZoneSec) ?></div>
        </div>

        <!-- Entries / Exits -->
        <div style="flex:1;min-width:120px;padding:14px 16px;border-right:1px solid #dee2e6;">
          <div style="font-size:0.7em;color:#6c757d;text-transform:uppercase;letter-spacing:.06em;margin-bottom:4px;">Entries / Exits</div>
          <div style="font-size:1.3em;font-weight:700;color:#495057;"><?= $entries ?> / <?= $exits ?></div>
        </div>

        <!-- GPS samples -->
        <div style="flex:1;min-width:120px;padding:14px 16px;">
          <div style="font-size:0.7em;color:#6c757d;text-transform:uppercase;letter-spacing:.06em;margin-bottom:4px;">GPS Samples</div>
          <div style="font-size:1.3em;font-weight:700;color:#495057;"><?= number_format($samples) ?></div>
        </div>

      </div>

      <!-- Entry/Exit timeline -->
      <?php if (!empty($events)): ?>
      <div style="padding:12px 16px;border-bottom:1px solid #dee2e6;">
        <div style="font-size:0.8em;font-weight:600;color:#495057;margin-bottom:8px;">Zone Entry / Exit Timeline</div>
        <table style="width:100%;border-collapse:collapse;font-size:0.85em;">
          <tbody>
            <?= $timelineRows ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>

      <!-- Footer note -->
      <div style="padding:8px 16px;font-size:0.75em;color:#6c757d;background:#fafafa;">
        Tracked via polygon geofence &bull;
        <?= $samples ?> GPS samples &bull;
        Total visit time: <?= $fmt($totalSec) ?> &bull;
        <?php if ($confidence !== null): ?>
          <?= $confidence >= 85 ? 'High confidence — crew worked within the defined zone.' : ($confidence >= 60 ? 'Moderate confidence — some time recorded outside zone.' : 'Low confidence — significant out-of-zone time recorded.') ?>
        <?php else: ?>
          Insufficient GPS data to compute confidence.
        <?php endif; ?>
      </div>

    </div>
    <?php
    return ob_get_clean();
}

/**
 * Returns a plain-text / Markdown-safe summary string for PDF footers.
 */
function geofencePowSummaryText(int $visitId): string {
    $session = function_exists('geofenceGetSession') ? geofenceGetSession($visitId) : null;
    if (!$session || !$session['tracking_active']) return '';

    $confidence = $session['confidence_score'] !== null ? (int)$session['confidence_score'] . '%' : 'N/A';
    $inMin      = (int)round((int)$session['in_zone_seconds'] / 60);

    return "Work-zone compliance: {$inMin} min in zone | Confidence: {$confidence}";
}
