<?php
/**
 * Seasonal Outlook Card — shared renderer
 * /crm/modules/weather/seasonal-outlook-card.php
 *
 * Renders the SeasonalOutlookService payload in two variants:
 *   'compact' — dashboard strip (months + season totals, no notes/sources)
 *   'full'    — Weather Actions page (full month table, notes, sources)
 *
 * Both variants render from the SAME array, so the dashboard can never drift
 * from the operations page. Presentation only — no queries, no business rules.
 *
 * Usage:
 *   require_once APP_ROOT . '/Modules/Jobs/Services/SeasonalOutlookService.php';
 *   require_once CRM_ROOT . '/modules/weather/seasonal-outlook-card.php';
 *   $outlook = (new SeasonalOutlookService(getDB()))->activeOutlook();
 *   if ($outlook) renderSeasonalOutlookCard($outlook, 'compact');
 */

declare(strict_types=1);

if (!function_exists('mwSeasonalEsc')) {
    function mwSeasonalEsc($v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('mwSeasonalTrend')) {
    /** Maps an anomaly to a class suffix so colour always follows the number. */
    function mwSeasonalTrend(float $projected, float $normal): string
    {
        $pct = SeasonalOutlookService::anomalyPct($projected, $normal);
        if ($pct === null || abs($pct) < 10) {
            return 'flat';
        }
        return $pct < 0 ? 'down' : 'up';
    }
}

if (!function_exists('renderSeasonalOutlookCard')) {
    /**
     * @param array  $o       Payload from SeasonalOutlookService::activeOutlook()
     * @param string $variant 'compact' | 'full'
     */
    function renderSeasonalOutlookCard(array $o, string $variant = 'compact'): void
    {
        $months = $o['months_ahead'] ?? [];
        if (!$months) {
            return;
        }
        $full   = ($variant === 'full');
        $t      = $o['totals'] ?? [];
        $peak   = $o['peak'] ?? null;
        $stale  = !empty($o['is_stale']);
        $e      = 'mwSeasonalEsc';
        ?>
        <div class="mw-seasonal <?php echo $full ? 'mw-seasonal-full' : 'mw-seasonal-compact'; ?>">

          <div class="mw-seasonal-header">
            <div class="mw-seasonal-title-wrap">
              <span class="mw-seasonal-title"><?php echo $e($o['label'] ?? 'Seasonal Outlook'); ?></span>
              <span class="mw-seasonal-region"><?php echo $e($o['region'] ?? ''); ?></span>
            </div>
            <div class="mw-seasonal-badges">
              <?php if (!empty($o['driver'])): ?>
                <span class="mw-seasonal-badge mw-seasonal-badge-driver"><?php echo $e($o['driver']); ?></span>
              <?php endif; ?>
              <?php if ($stale): ?>
                <span class="mw-seasonal-badge mw-seasonal-badge-stale"
                      title="Review date has passed — re-check the ENSO signal before relying on these numbers.">
                  Review overdue
                </span>
              <?php elseif (!empty($o['review_by'])): ?>
                <span class="mw-seasonal-badge mw-seasonal-badge-review">
                  Re-check <?php echo $e(date('M j', strtotime((string) $o['review_by']))); ?>
                </span>
              <?php endif; ?>
            </div>
          </div>

          <?php if (!empty($o['headline'])): ?>
            <p class="mw-seasonal-headline"><?php echo $e($o['headline']); ?></p>
          <?php endif; ?>

          <?php if (!empty($o['is_partial'])): ?>
            <p class="mw-seasonal-partial">Showing the months still ahead. Season totals below cover those months only.</p>
          <?php endif; ?>

          <?php if ($full): ?>
            <?php // ── Full month table ──────────────────────────────────── ?>
            <div class="table-responsive">
              <table class="table table-sm mw-seasonal-table mb-0">
                <thead>
                  <tr>
                    <th>Month</th>
                    <th class="text-right">Frost nights<br><small>min &le; 0&deg;C</small></th>
                    <th class="text-right">Normal</th>
                    <th class="text-right">Snow days</th>
                    <th class="text-right">Normal</th>
                    <th class="text-right">Days &ge; 2cm</th>
                    <th class="text-right">Snow (cm)</th>
                    <th>vs normal snow</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($months as $m): ?>
                    <tr class="<?php echo !empty($m['is_current']) ? 'mw-seasonal-row-current' : ''; ?>">
                      <td>
                        <?php echo $e($m['name']); ?>
                        <?php if (!empty($m['is_current'])): ?>
                          <span class="mw-seasonal-now">now</span>
                        <?php endif; ?>
                      </td>
                      <td class="text-right mw-seasonal-num"><?php echo number_format((float) $m['frost'], 1); ?></td>
                      <td class="text-right mw-seasonal-norm"><?php echo number_format((float) $m['normal_frost'], 1); ?></td>
                      <td class="text-right mw-seasonal-num"><?php echo number_format((float) $m['snow_days'], 1); ?></td>
                      <td class="text-right mw-seasonal-norm"><?php echo number_format((float) $m['normal_snow_days'], 1); ?></td>
                      <td class="text-right mw-seasonal-num"><?php echo number_format((float) $m['snow_days_2cm'], 1); ?></td>
                      <td class="text-right mw-seasonal-num"><?php echo number_format((float) $m['snow_cm'], 1); ?></td>
                      <td>
                        <span class="mw-seasonal-trend mw-seasonal-trend-<?php
                            echo $e(mwSeasonalTrend((float) $m['snow_cm'], (float) $m['normal_snow_cm'])); ?>">
                          <?php echo $e(SeasonalOutlookService::anomalyLabel(
                              (float) $m['snow_cm'], (float) $m['normal_snow_cm'])); ?>
                        </span>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
                <tfoot>
                  <tr>
                    <th>Total</th>
                    <th class="text-right"><?php echo number_format((float) ($t['frost'] ?? 0), 0); ?></th>
                    <th class="text-right mw-seasonal-norm"><?php echo number_format((float) ($t['normal_frost'] ?? 0), 0); ?></th>
                    <th class="text-right"><?php echo number_format((float) ($t['snow_days'] ?? 0), 1); ?></th>
                    <th class="text-right mw-seasonal-norm"><?php echo number_format((float) ($t['normal_snow_days'] ?? 0), 1); ?></th>
                    <th class="text-right"><?php echo number_format((float) ($t['snow_days_2cm'] ?? 0), 1); ?></th>
                    <th class="text-right"><?php echo number_format((float) ($t['snow_cm'] ?? 0), 1); ?></th>
                    <th>
                      <span class="mw-seasonal-trend mw-seasonal-trend-<?php
                          echo $e(mwSeasonalTrend((float) ($t['snow_cm'] ?? 0), (float) ($t['normal_snow_cm'] ?? 0))); ?>">
                        <?php echo $e(SeasonalOutlookService::anomalyLabel(
                            (float) ($t['snow_cm'] ?? 0), (float) ($t['normal_snow_cm'] ?? 0))); ?>
                      </span>
                    </th>
                  </tr>
                </tfoot>
              </table>
            </div>
          <?php else: ?>
            <?php // ── Compact month strip ───────────────────────────────── ?>
            <div class="mw-seasonal-strip">
              <?php foreach ($months as $m): ?>
                <div class="mw-seasonal-month <?php echo !empty($m['is_current']) ? 'mw-seasonal-month-current' : ''; ?>">
                  <div class="mw-seasonal-month-name"><?php echo $e(substr((string) $m['name'], 0, 3)); ?></div>
                  <div class="mw-seasonal-metric">
                    <span class="mw-seasonal-metric-val"><?php echo number_format((float) $m['frost'], 0); ?></span>
                    <span class="mw-seasonal-metric-lbl">frost</span>
                  </div>
                  <div class="mw-seasonal-metric">
                    <span class="mw-seasonal-metric-val mw-seasonal-snow"><?php echo number_format((float) $m['snow_days'], 1); ?></span>
                    <span class="mw-seasonal-metric-lbl">snow days</span>
                  </div>
                  <div class="mw-seasonal-metric-sub">
                    <?php echo number_format((float) $m['snow_cm'], 1); ?> cm
                    <span class="mw-seasonal-trend-dot mw-seasonal-trend-<?php
                        echo $e(mwSeasonalTrend((float) $m['snow_cm'], (float) $m['normal_snow_cm'])); ?>"></span>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

          <div class="mw-seasonal-footer">
            <div class="mw-seasonal-totals">
              <span class="mw-seasonal-total">
                <strong><?php echo number_format((float) ($t['frost'] ?? 0), 0); ?></strong> frost nights
                <em>(normal <?php echo number_format((float) ($t['normal_frost'] ?? 0), 0); ?>)</em>
              </span>
              <span class="mw-seasonal-total">
                <strong><?php echo number_format((float) ($t['snow_days'] ?? 0), 1); ?></strong> snow days
                <em>(normal <?php echo number_format((float) ($t['normal_snow_days'] ?? 0), 1); ?>)</em>
              </span>
              <?php if ($peak): ?>
                <span class="mw-seasonal-total mw-seasonal-peak">
                  Hold capacity for <strong><?php echo $e($peak['name']); ?></strong>
                </span>
              <?php endif; ?>
            </div>
            <?php if (!$full): ?>
              <a href="/crm/ops/weather_actions.php" class="mw-seasonal-link">
                Full outlook <i data-feather="arrow-right"></i>
              </a>
            <?php endif; ?>
          </div>

          <?php if ($full): ?>
            <?php if (!empty($o['driver_note'])): ?>
              <p class="mw-seasonal-driver-note"><?php echo $e($o['driver_note']); ?></p>
            <?php endif; ?>

            <?php if (!empty($o['notes'])): ?>
              <ul class="mw-seasonal-notes">
                <?php foreach ($o['notes'] as $note): ?>
                  <li><?php echo $e($note); ?></li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>

            <div class="mw-seasonal-meta">
              <span>Baseline: <?php echo $e($o['baseline'] ?? ''); ?></span>
              <span>Issued <?php echo $e(date('M j, Y', strtotime((string) ($o['issued'] ?? 'now')))); ?></span>
              <?php if (!empty($o['sources'])): ?>
                <span class="mw-seasonal-sources">
                  Sources:
                  <?php foreach ($o['sources'] as $i => $src): ?>
                    <?php echo $i > 0 ? ' &middot; ' : ''; ?>
                    <a href="<?php echo $e($src['url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo $e($src['label']); ?></a>
                  <?php endforeach; ?>
                </span>
              <?php endif; ?>
            </div>
          <?php endif; ?>

        </div>
        <?php
    }
}
