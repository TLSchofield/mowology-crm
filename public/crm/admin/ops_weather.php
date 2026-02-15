<?php
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/weather-service.php';

requireLogin();
$user = getCurrentUser();

if (($user['role'] ?? '') !== 'admin') {
    header('Location: /crm/dashboard_appstack.php');
    exit;
}

// Fetch 7-day forecast with overnight lows for salt preview
$weekWeather = getWeekForecastWithOvernightLow('Vancouver', 'BC');

// Fetch hourly data for winter weather days (snow/ice/freeze/sub-zero)
$hourlyData = getHourlyForecastByCity('Vancouver', 'BC');
$winterHourly = []; // keyed by date => array of hourly blocks
if (!empty($hourlyData)) {
    foreach ($weekWeather as $date => $day) {
        $cond = strtolower($day['condition'] ?? '');
        $overnightLow = $day['overnight_low'] ?? ($day['temp_low'] ?? 99);
        $isWinter = ($overnightLow <= 0)
            || strpos($cond, 'snow') !== false
            || strpos($cond, 'ice') !== false
            || strpos($cond, 'freezing') !== false
            || strpos($cond, 'flurr') !== false
            || strpos($cond, 'rain/snow') !== false;
        if ($isWinter) {
            $winterHourly[$date] = [];
            foreach ($hourlyData as $block) {
                $blockDate = substr($block['hour'], 0, 10);
                if ($blockDate === $date) {
                    $winterHourly[$date][] = $block;
                }
            }
        }
    }
}

$pageTitle = 'Ops Weather Constraints';
$activePage = 'ops-weather';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

          <!-- Page Header -->
          <div class="mw-page-header">
            <div>
              <h1 class="h3 mb-0"><i data-feather="cloud-rain" style="width:28px;height:28px;"></i> Weather Operations</h1>
              <p class="text-muted mb-0">Forecast, salt alerts &amp; weather rules for scheduled visits</p>
            </div>
          </div>

          <!-- 7-Day Forecast (top of page) -->
          <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
              <div>
                <h5 class="card-title mb-0"><i data-feather="cloud" style="width:18px;height:18px;"></i> 7-Day Forecast — Vancouver</h5>
                <small class="text-muted">Source: Environment Canada · Days highlighted in blue trigger salt alerts</small>
              </div>
              <span class="badge badge-secondary" style="font-size:0.75rem;">Updated <?php echo date('g:ia'); ?></span>
            </div>
            <div class="card-body p-2">
              <div class="d-flex" style="gap:4px;overflow-x:auto;" id="saltForecastPreview">
                <?php
                $dayNames = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
                foreach ($weekWeather as $date => $day):
                  $high = $day['temp_high'] ?? 0;
                  $overnightLow = $day['overnight_low'] ?? ($day['temp_low'] ?? 0);
                  $cond = strtolower($day['condition'] ?? '');
                  $condDisplay = ucfirst($day['condition'] ?? 'Unknown');
                  $weatherEmoji = getWeatherIcon($day['condition'] ?? 'Clear');
                  $ts = strtotime($date);
                  $dayName = $dayNames[date('w', $ts)];
                  $monthDay = date('M j', $ts);
                  $isToday = ($date === date('Y-m-d'));
                  $precip = (int)($day['precipitation'] ?? 0);
                  $wind = (int)($day['wind'] ?? 0);
                  // Salt check uses overnight low (6pm→8am)
                  $isSaltDay = ($overnightLow <= 0)
                    || strpos($cond, 'snow') !== false
                    || strpos($cond, 'ice') !== false
                    || strpos($cond, 'freezing') !== false
                    || strpos($cond, 'flurr') !== false
                    || strpos($cond, 'rain/snow') !== false;
                  $hasHourly = isset($winterHourly[$date]) && !empty($winterHourly[$date]);
                ?>
                <div class="text-center flex-fill p-2 rounded <?php echo $isSaltDay ? 'salt-day-highlight' : ''; ?>"
                     style="min-width:110px;<?php echo $isSaltDay ? 'background:#e3f2fd;border:2px solid #42a5f5;' : ($isToday ? 'background:#f0f9f4;border:2px solid var(--mw-green);' : 'background:#f8f9fa;border:2px solid transparent;'); ?><?php if ($hasHourly): ?>cursor:pointer;<?php endif; ?>"
                     data-date="<?php echo $date; ?>"
                     data-low="<?php echo $overnightLow; ?>"
                     data-condition="<?php echo htmlspecialchars($cond); ?>"
                     <?php if ($hasHourly): ?>onclick="toggleWinterDetail('<?php echo $date; ?>')"<?php endif; ?>>
                  <div style="font-size:0.75rem;color:#666;font-weight:600;"><?php echo $dayName; ?><?php if ($isToday): ?> <span style="color:var(--mw-green);">TODAY</span><?php endif; ?></div>
                  <div style="font-weight:600;"><?php echo $monthDay; ?></div>
                  <div style="font-size:1.8rem;line-height:1.2;" class="my-1"><?php echo $weatherEmoji; ?></div>
                  <div style="font-size:0.8rem;color:#555;margin-bottom:2px;"><?php echo htmlspecialchars($condDisplay); ?></div>
                  <div style="font-size:0.95rem;font-weight:600;">
                    <span style="color:#c62828;"><?php echo $high; ?>°</span>
                    <span style="color:#999;">/</span>
                    <span style="color:#1565c0;"><?php echo $overnightLow; ?>°</span>
                  </div>
                  <?php if ($precip > 0): ?>
                  <div style="font-size:0.7rem;color:#666;">💧 <?php echo $precip; ?>%<?php if ($wind > 30): ?> · 💨 <?php echo $wind; ?><?php endif; ?></div>
                  <?php endif; ?>
                  <?php if ($isSaltDay): ?>
                  <div style="font-size:0.7rem;color:#1565c0;font-weight:700;margin-top:2px;">🧂 SALT</div>
                  <?php endif; ?>
                  <?php if ($hasHourly): ?>
                  <div style="font-size:0.65rem;color:#42a5f5;margin-top:3px;">▼ hourly detail</div>
                  <?php endif; ?>
                </div>
                <?php endforeach; ?>
              </div>
            </div>

            <?php
            // Render expanded hourly detail panels for winter days (auto-shown)
            // Groups consecutive hours with the same condition into bands
            foreach ($winterHourly as $date => $hours):
              if (empty($hours)) continue;
              $day = $weekWeather[$date] ?? [];
              $ts = strtotime($date);
              $dayLabel = $dayNames[date('w', $ts)] . ' ' . date('M j', $ts);
              $condDisplay = ucfirst($day['condition'] ?? 'Unknown');
              $overnightLow = $day['overnight_low'] ?? ($day['temp_low'] ?? 0);
              $high = $day['temp_high'] ?? 0;

              // Find the freezing window (hours at or below 0)
              $freezeStart = null;
              $freezeEnd = null;
              foreach ($hours as $h) {
                  if ($h['temp_c'] <= 0) {
                      $hr = substr($h['hour'], 11, 5);
                      if ($freezeStart === null) $freezeStart = $hr;
                      $freezeEnd = $hr;
                  }
              }

              // ── Group consecutive hours by condition ──
              $groups = [];
              $currentGroup = null;
              foreach ($hours as $h) {
                  $hCond = $h['condition'] ?? 'Unknown';
                  if ($currentGroup === null || $currentGroup['condition'] !== $hCond) {
                      // Start a new group
                      if ($currentGroup !== null) $groups[] = $currentGroup;
                      $currentGroup = [
                          'condition'  => $hCond,
                          'icon'       => $h['icon'] ?? getWeatherIcon($hCond),
                          'startHour'  => substr($h['hour'], 11, 5),
                          'endHour'    => substr($h['hour'], 11, 5),
                          'count'      => 1,
                          'tempMin'    => $h['temp_c'],
                          'tempMax'    => $h['temp_c'],
                          'popMax'     => $h['precip_chance_pct'] ?? 0,
                          'mmMax'      => $h['precip_mm'] ?? 0,
                          'mmTotal'    => $h['precip_mm'] ?? 0,
                          'windMax'    => $h['wind_kph'] ?? 0,
                      ];
                  } else {
                      // Extend current group
                      $currentGroup['endHour'] = substr($h['hour'], 11, 5);
                      $currentGroup['count']++;
                      $currentGroup['tempMin'] = min($currentGroup['tempMin'], $h['temp_c']);
                      $currentGroup['tempMax'] = max($currentGroup['tempMax'], $h['temp_c']);
                      $currentGroup['popMax']  = max($currentGroup['popMax'], $h['precip_chance_pct'] ?? 0);
                      $currentGroup['mmMax']   = max($currentGroup['mmMax'], $h['precip_mm'] ?? 0);
                      $currentGroup['mmTotal'] += ($h['precip_mm'] ?? 0);
                      $currentGroup['windMax'] = max($currentGroup['windMax'], $h['wind_kph'] ?? 0);
                  }
              }
              if ($currentGroup !== null) $groups[] = $currentGroup;
            ?>
            <div class="mw-winter-detail" id="winterDetail-<?php echo $date; ?>" style="background:#e8f0fe;border:2px solid #42a5f5;border-top:none;border-radius:0 0 6px 6px;margin:-4px 0 8px 0;padding:12px;">
              <div class="d-flex justify-content-between align-items-center mb-2">
                <div>
                  <strong style="color:#1565c0;">❄️ <?php echo $dayLabel; ?> — Winter Detail</strong>
                  <span class="ml-2" style="font-size:0.8rem;color:#555;"><?php echo htmlspecialchars($condDisplay); ?> · High <?php echo $high; ?>° / Overnight <?php echo $overnightLow; ?>°</span>
                </div>
                <div style="font-size:0.8rem;">
                  <?php if ($freezeStart !== null): ?>
                  <span style="color:#c62828;font-weight:600;">Freezing: <?php echo $freezeStart; ?> – <?php echo $freezeEnd; ?></span>
                  <?php endif; ?>
                  <button class="btn btn-sm btn-link p-0 ml-2" onclick="toggleWinterDetail('<?php echo $date; ?>')" style="color:#42a5f5;font-size:0.75rem;">collapse ▲</button>
                </div>
              </div>
              <div style="overflow-x:auto;">
                <table style="width:100%;font-size:0.8rem;border-collapse:collapse;min-width:600px;">
                  <thead>
                    <tr style="border-bottom:2px solid #90caf9;">
                      <th style="padding:6px 8px;text-align:left;color:#1565c0;">Time</th>
                      <th style="padding:6px 8px;text-align:left;color:#1565c0;">Condition</th>
                      <th style="padding:6px 8px;text-align:center;color:#1565c0;">Temp</th>
                      <th style="padding:6px 8px;text-align:center;color:#1565c0;">Precip</th>
                      <th style="padding:6px 8px;text-align:center;color:#1565c0;">Accum</th>
                      <th style="padding:6px 8px;text-align:center;color:#1565c0;">Wind</th>
                      <th style="padding:6px 8px;text-align:left;color:#1565c0;">Risk</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($groups as $g):
                      $gCondLower = strtolower($g['condition']);
                      $lowestTemp = $g['tempMin'];

                      // Time range display
                      $timeDisplay = ($g['count'] === 1)
                          ? $g['startHour']
                          : $g['startHour'] . ' – ' . $g['endHour'];
                      $durationLabel = ($g['count'] === 1) ? '1 hr' : $g['count'] . ' hrs';

                      // Temp display: show range if it varies, single value if constant
                      $tempDisplay = ($g['tempMin'] == $g['tempMax'])
                          ? $g['tempMin'] . '°'
                          : $g['tempMin'] . '° to ' . $g['tempMax'] . '°';

                      // Precip display
                      $precipDisplay = $g['popMax'] . '%';
                      if ($g['mmMax'] > 0) $precipDisplay .= ' (up to ' . round($g['mmMax'], 1) . ' mm/hr)';

                      // Accumulation over the band
                      $accumDisplay = ($g['mmTotal'] > 0) ? round($g['mmTotal'], 1) . ' mm' : '—';

                      // Wind
                      $windDisplay = round($g['windMax']) . ' km/h';

                      // Risks
                      $risks = [];
                      if ($lowestTemp <= 0) $risks[] = '🧊 Freeze';
                      if ($lowestTemp <= -5) $risks[] = '🥶 Severe';
                      if (strpos($gCondLower, 'snow') !== false || strpos($gCondLower, 'rain/snow') !== false) $risks[] = '❄️ Snow';
                      if (strpos($gCondLower, 'ice') !== false) $risks[] = '🧊 Ice';
                      if (strpos($gCondLower, 'freezing') !== false) $risks[] = '🧊 Freezing';
                      if ($g['windMax'] > 40) $risks[] = '💨 High wind';
                      $riskStr = !empty($risks) ? implode(', ', $risks) : '—';

                      // Row color based on worst temp in band
                      $rowBg = '';
                      if ($lowestTemp <= -5) $rowBg = 'background:#ffcdd2;';
                      elseif ($lowestTemp <= 0) $rowBg = 'background:#bbdefb;';
                      elseif (strpos($gCondLower, 'snow') !== false || strpos($gCondLower, 'ice') !== false || strpos($gCondLower, 'rain/snow') !== false) $rowBg = 'background:#e1f5fe;';
                    ?>
                    <tr style="border-bottom:1px solid #bbdefb;<?php echo $rowBg; ?>">
                      <td style="padding:6px 8px;font-weight:700;white-space:nowrap;">
                        <?php echo $timeDisplay; ?>
                        <span style="font-weight:400;color:#888;font-size:0.7rem;margin-left:4px;"><?php echo $durationLabel; ?></span>
                      </td>
                      <td style="padding:6px 8px;"><?php echo $g['icon']; ?> <?php echo htmlspecialchars($g['condition']); ?></td>
                      <td style="padding:6px 8px;text-align:center;font-weight:600;<?php echo $lowestTemp <= 0 ? 'color:#c62828;' : 'color:#333;'; ?>"><?php echo $tempDisplay; ?></td>
                      <td style="padding:6px 8px;text-align:center;"><?php echo $precipDisplay; ?></td>
                      <td style="padding:6px 8px;text-align:center;"><?php echo $accumDisplay; ?></td>
                      <td style="padding:6px 8px;text-align:center;"><?php echo $windDisplay; ?></td>
                      <td style="padding:6px 8px;font-size:0.75rem;"><?php echo $riskStr; ?></td>
                    </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </div>
            <?php endforeach; ?>
          </div>

          <!-- Cron Status Card -->
          <div class="card mb-3" id="cronStatusCard">
            <div class="card-body py-3">
              <div class="d-flex align-items-center justify-content-between flex-wrap" style="gap:1rem;">
                <div class="d-flex align-items-center" style="gap:0.75rem;">
                  <div id="cronStatusIcon" style="width:40px;height:40px;border-radius:50%;display:flex;align-items:center;justify-content:center;background:#f8f9fa;">
                    <i data-feather="clock" style="width:20px;height:20px;color:#6c757d;"></i>
                  </div>
                  <div>
                    <div class="d-flex align-items-center" style="gap:0.5rem;">
                      <strong>Weather Guard Cron</strong>
                      <span class="badge" id="cronStatusBadge" style="font-size:0.75rem;">Loading...</span>
                    </div>
                    <div id="cronStatusDetail" class="text-muted" style="font-size:0.85rem;">Checking cron status...</div>
                  </div>
                </div>
                <div class="d-flex align-items-center" style="gap:0.75rem;">
                  <div id="cronTodaySummary" style="font-size:0.85rem;"></div>
                  <button class="btn btn-sm btn-outline-primary" onclick="runCronNow()" id="runCronBtn" title="Run weather check now">
                    <i data-feather="play" style="width:14px;height:14px;"></i> Run Now
                  </button>
                  <button class="btn btn-sm btn-outline-secondary" onclick="toggleCronSetup()" id="cronSetupToggle" title="Show cron setup">
                    <i data-feather="terminal" style="width:14px;height:14px;"></i> Setup
                  </button>
                </div>
              </div>
              <!-- Cron Setup Panel (hidden by default) -->
              <div id="cronSetupPanel" style="display:none;margin-top:12px;border-top:1px solid #e9ecef;padding-top:12px;">
                <p class="mb-2" style="font-size:0.85rem;"><strong>cPanel Cron Job Setup</strong> — Add this in cPanel &rarr; Cron Jobs. Recommended: daily at noon.</p>
                <div class="mb-2">
                  <label style="font-size:0.8rem;color:#666;margin-bottom:2px;display:block;">Schedule (once daily at noon):</label>
                  <div class="d-flex align-items-center" style="gap:8px;">
                    <code style="background:#f1f3f5;padding:6px 12px;border-radius:4px;font-size:0.82rem;flex:1;word-break:break-all;user-select:all;">0 12 * * *</code>
                  </div>
                </div>
                <div class="mb-2">
                  <label style="font-size:0.8rem;color:#666;margin-bottom:2px;display:block;">Command (copy &amp; paste into cPanel):</label>
                  <div class="d-flex align-items-center" style="gap:8px;">
                    <code id="cronCommand" style="background:#f1f3f5;padding:6px 12px;border-radius:4px;font-size:0.82rem;flex:1;word-break:break-all;user-select:all;">/usr/local/bin/php /home/mowology/app/Modules/Jobs/Cron/weather_schedule_guard.php >> /home/mowology/logs/weather_cron.log 2>&1</code>
                    <button class="btn btn-sm btn-outline-secondary" onclick="copyCronCommand()" title="Copy to clipboard" style="white-space:nowrap;">
                      <i data-feather="copy" style="width:14px;height:14px;"></i> Copy
                    </button>
                  </div>
                </div>
                <div class="mb-0">
                  <label style="font-size:0.8rem;color:#666;margin-bottom:2px;display:block;">Alternative — HTTP trigger via curl:</label>
                  <div class="d-flex align-items-center" style="gap:8px;">
                    <code id="cronCurlCommand" style="background:#f1f3f5;padding:6px 12px;border-radius:4px;font-size:0.82rem;flex:1;word-break:break-all;user-select:all;">curl -s -X POST https://mowology.ca/crm/cron/weather_schedule_guard.php -b "PHPSESSID=CRON" >> /home/mowology/logs/weather_cron.log 2>&1</code>
                    <button class="btn btn-sm btn-outline-secondary" onclick="copyCurlCommand()" title="Copy to clipboard" style="white-space:nowrap;">
                      <i data-feather="copy" style="width:14px;height:14px;"></i> Copy
                    </button>
                  </div>
                  <small class="text-muted mt-1 d-block">Note: The HTTP method requires an authenticated admin session. The CLI method (above) is recommended for cPanel cron.</small>
                </div>
              </div>
            </div>
          </div>

          <!-- Tabs -->
          <ul class="nav nav-tabs mb-3" role="tablist">
            <li class="nav-item">
              <a class="nav-link active" data-toggle="tab" href="#tab-salt"><i data-feather="cloud-snow" style="width:16px;height:16px;"></i> Salt Operations</a>
            </li>
            <li class="nav-item">
              <a class="nav-link" data-toggle="tab" href="#tab-global">Global Defaults</a>
            </li>
            <li class="nav-item">
              <a class="nav-link" data-toggle="tab" href="#tab-services">Service Weather Rules</a>
            </li>
          </ul>

          <div class="tab-content">

            <!-- Tab 1: Salt Operations -->
            <div class="tab-pane fade show active" id="tab-salt" role="tabpanel">

              <!-- Salt Trigger Settings -->
              <div class="card mb-3">
                <div class="card-header">
                  <h5 class="card-title mb-0"><i data-feather="thermometer" style="width:18px;height:18px;"></i> Salt Trigger Settings</h5>
                  <small class="text-muted">When should salting operations be triggered?</small>
                </div>
                <div class="card-body">
                  <div class="row">
                    <div class="col-md-4">
                      <div class="form-group">
                        <label><strong>Salt when temperature drops below</strong></label>
                        <div class="input-group">
                          <input type="number" class="form-control" id="salt_trigger_temp" min="-20" max="10" step="1" value="0">
                          <div class="input-group-append"><span class="input-group-text">°C</span></div>
                        </div>
                        <small class="form-text text-muted">The forecast low must drop below this to trigger a salt alert</small>
                      </div>
                    </div>
                    <div class="col-md-8">
                      <label><strong>Also salt when forecast includes:</strong></label>
                      <div class="d-flex flex-wrap mt-2" style="gap:1rem;">
                        <label class="d-flex align-items-center" style="gap:0.4rem;cursor:pointer;">
                          <input type="checkbox" id="salt_on_snow" checked> ❄️ Snow
                        </label>
                        <label class="d-flex align-items-center" style="gap:0.4rem;cursor:pointer;">
                          <input type="checkbox" id="salt_on_freezing_rain" checked> 🧊 Freezing Rain
                        </label>
                        <label class="d-flex align-items-center" style="gap:0.4rem;cursor:pointer;">
                          <input type="checkbox" id="salt_on_ice_pellets" checked> 🌨️ Ice Pellets
                        </label>
                      </div>
                      <small class="form-text text-muted mt-2">If any of these conditions appear in the forecast, a salt alert fires regardless of temperature</small>
                    </div>
                  </div>
                </div>
              </div>

              <!-- Crew SMS Alerts -->
              <div class="card mb-3">
                <div class="card-header">
                  <h5 class="card-title mb-0"><i data-feather="message-circle" style="width:18px;height:18px;"></i> Crew SMS Alerts</h5>
                  <small class="text-muted">When should crew get a heads-up text about upcoming salt conditions?</small>
                </div>
                <div class="card-body">
                  <div class="row">
                    <div class="col-md-6">
                      <label><strong>Send heads-up alerts at:</strong></label>
                      <div class="mt-2">
                        <label class="d-flex align-items-center mb-2" style="gap:0.5rem;cursor:pointer;">
                          <input type="checkbox" id="salt_alert_7day"> 7 days before <span class="badge badge-secondary ml-1">Long-range</span>
                        </label>
                        <label class="d-flex align-items-center mb-2" style="gap:0.5rem;cursor:pointer;">
                          <input type="checkbox" id="salt_alert_48hr" checked> 48 hours before <span class="badge badge-info ml-1">Planning</span>
                        </label>
                        <label class="d-flex align-items-center mb-0" style="gap:0.5rem;cursor:pointer;">
                          <input type="checkbox" id="salt_alert_24hr" checked> 24 hours before <span class="badge badge-warning ml-1">Action</span>
                        </label>
                      </div>
                    </div>
                    <div class="col-md-6">
                      <label><strong>Quiet Hours</strong></label>
                      <div class="form-group mt-2">
                        <label>Don't text after:</label>
                        <input type="time" class="form-control" id="salt_quiet_hour" value="20:00" style="max-width:140px;">
                      </div>
                      <label class="d-flex align-items-center" style="gap:0.5rem;cursor:pointer;">
                        <input type="checkbox" id="salt_quiet_exception" checked> <strong>Exception:</strong> Always text if crew is clocked in
                      </label>
                      <small class="form-text text-muted mt-2">Each crew member can opt out of weather SMS on the <a href="/crm/team/">Team page</a></small>
                    </div>
                  </div>
                </div>
              </div>

              <!-- Test Alert Buttons -->
              <div class="card mb-3" style="border-left:4px solid var(--mw-orange);">
                <div class="card-header">
                  <h5 class="card-title mb-0"><i data-feather="send" style="width:18px;height:18px;"></i> Test Alerts</h5>
                  <small class="text-muted">Send yourself a test notification to verify email and SMS delivery</small>
                </div>
                <div class="card-body">
                  <div class="d-flex flex-wrap align-items-start" style="gap:1rem;">
                    <div>
                      <button class="btn btn-outline-primary" onclick="sendTestEmail()" id="testEmailBtn">
                        <i data-feather="mail" style="width:16px;height:16px;"></i> Send Test Email
                      </button>
                      <div id="testEmailResult" class="mt-2" style="font-size:0.85rem;"></div>
                    </div>
                    <div>
                      <button class="btn btn-outline-success" onclick="sendTestSms()" id="testSmsBtn">
                        <i data-feather="smartphone" style="width:16px;height:16px;"></i> Send Test SMS
                      </button>
                      <div id="testSmsResult" class="mt-2" style="font-size:0.85rem;"></div>
                    </div>
                  </div>
                  <small class="text-muted d-block mt-3">
                    <strong>Email</strong> sends to the admin email in Business Settings.
                    <strong>SMS</strong> sends to your phone number on your user profile.
                  </small>
                </div>
              </div>

              <!-- Save Button -->
              <div class="mb-4">
                <button type="button" class="btn btn-primary" onclick="saveSaltConfig()">
                  <i data-feather="save"></i> Save Salt Settings
                </button>
                <span id="saltSaveStatus" class="ml-3 text-muted"></span>
              </div>
            </div>

            <!-- Tab 2: Global Defaults -->
            <div class="tab-pane fade" id="tab-global" role="tabpanel">

              <!-- How It Works -->
              <div class="card mb-3" style="border-left:4px solid var(--mw-green);">
                <div class="card-body py-3">
                  <h6 class="mb-2" style="color:var(--mw-green);"><i data-feather="info" style="width:16px;height:16px;"></i> How Weather Guard Works</h6>
                  <p class="mb-2" style="font-size:0.9rem;">
                    The weather guard checks the forecast for your upcoming visits and flags anything that might be a problem. Here's the flow:
                  </p>
                  <ol class="mb-0" style="font-size:0.9rem;padding-left:1.2rem;">
                    <li class="mb-1"><strong>Set global defaults</strong> below — these are the fallback thresholds for all services.</li>
                    <li class="mb-1"><strong>Assign weather policies to your services</strong> in the <em>Service Weather Rules</em> tab — e.g. mark "Lawn Mowing" as <strong>Dry Only</strong>, or "Snow Removal" as <strong>Any Weather</strong>.</li>
                    <li class="mb-1">The guard runs daily, checks the hourly forecast for each visit, and flags anything that doesn't meet the rules.</li>
                    <li class="mb-0">Flagged visits appear in <a href="/crm/ops/weather_actions.php"><strong>Weather Ops</strong></a> where you can keep, reschedule, or dismiss them.</li>
                  </ol>
                </div>
              </div>
              <div class="card">
                <div class="card-header">
                  <h5 class="card-title mb-0">Default Weather Thresholds</h5>
                  <small class="text-muted">These defaults apply to any service that doesn't have its own rules set. You can override them per-service in the Service Weather Rules tab.</small>
                </div>
                <div class="card-body">
                  <form id="globalConstraintsForm">
                    <div class="row">
                      <div class="col-md-6">
                        <h6 class="mb-3" style="color:var(--mw-green);">Precipitation</h6>
                        <div class="form-group">
                          <label>Default Max Precip Chance (%)</label>
                          <input type="number" class="form-control" id="gc_max_precip_chance" min="0" max="100" step="5">
                          <small class="form-text text-muted">Visits with precip chance above this are flagged NOT_OK</small>
                        </div>
                        <div class="form-group">
                          <label>Default Max Precip mm/hr</label>
                          <input type="number" class="form-control" id="gc_max_precip_mm" min="0" max="50" step="0.5">
                          <small class="form-text text-muted">Hourly precipitation threshold (primary metric)</small>
                        </div>
                        <div class="form-group">
                          <label>Borderline Band — Low (%)</label>
                          <input type="number" class="form-control" id="gc_borderline_low" min="0" max="100" step="5">
                          <small class="form-text text-muted">Below this = OK, above = uncertain zone</small>
                        </div>
                        <div class="form-group">
                          <label>Borderline Band — High (%)</label>
                          <input type="number" class="form-control" id="gc_borderline_high" min="0" max="100" step="5">
                          <small class="form-text text-muted">Above this = NOT_OK, between low and high = BORDERLINE</small>
                        </div>
                      </div>
                      <div class="col-md-6">
                        <h6 class="mb-3" style="color:var(--mw-green);">Temperature &amp; Wind</h6>
                        <div class="form-group">
                          <label>Default Min Temperature (°C)</label>
                          <input type="number" class="form-control" id="gc_min_temp" min="-40" max="50" step="1">
                        </div>
                        <div class="form-group">
                          <label>Default Max Temperature (°C)</label>
                          <input type="number" class="form-control" id="gc_max_temp" min="-40" max="50" step="1">
                        </div>
                        <div class="form-group">
                          <label>Default Max Wind (km/h)</label>
                          <input type="number" class="form-control" id="gc_max_wind" min="0" max="200" step="5">
                        </div>
                      </div>
                    </div>

                    <hr>

                    <h6 class="mb-3" style="color:var(--mw-green);">Reschedule Behaviour</h6>
                    <div class="row">
                      <div class="col-md-4">
                        <div class="form-group">
                          <label>Default Move Window (hours)</label>
                          <input type="number" class="form-control" id="gc_move_window" min="1" max="168" step="1">
                          <small class="form-text text-muted">How far forward to search for alternate slots</small>
                        </div>
                      </div>
                      <div class="col-md-4">
                        <div class="form-group">
                          <label>Default Timeband Start</label>
                          <input type="time" class="form-control" id="gc_timeband_start">
                        </div>
                      </div>
                      <div class="col-md-4">
                        <div class="form-group">
                          <label>Default Timeband End</label>
                          <input type="time" class="form-control" id="gc_timeband_end">
                        </div>
                      </div>
                    </div>

                    <div class="row">
                      <div class="col-md-4">
                        <div class="form-group">
                          <label>Decision Time</label>
                          <input type="time" class="form-control" id="gc_decision_time">
                          <small class="form-text text-muted">When the daily weather check runs</small>
                        </div>
                      </div>
                      <div class="col-md-4">
                        <div class="form-group">
                          <label>Lookahead Days</label>
                          <input type="number" class="form-control" id="gc_lookahead_days" min="1" max="7" step="1">
                          <small class="form-text text-muted">How many days ahead to evaluate</small>
                        </div>
                      </div>
                      <div class="col-md-4">
                        <div class="form-group mt-4">
                          <label class="d-flex align-items-center" style="gap:0.5rem;">
                            <input type="checkbox" id="gc_auto_reschedule"> Enable Auto-Reschedule (globally)
                          </label>
                          <label class="d-flex align-items-center mt-2" style="gap:0.5rem;">
                            <input type="checkbox" id="gc_swap_suggestions"> Enable Swap Suggestions
                          </label>
                        </div>
                      </div>
                    </div>

                    <div class="mt-3">
                      <button type="button" class="btn btn-primary" onclick="saveGlobalConstraints()">
                        <i data-feather="save"></i> Save Global Defaults
                      </button>
                      <span id="globalSaveStatus" class="ml-3 text-muted"></span>
                    </div>
                  </form>
                </div>
              </div>
            </div>

            <!-- Tab 2: Service Weather Rules -->
            <div class="tab-pane fade" id="tab-services" role="tabpanel">

              <!-- Explainer -->
              <div class="card mb-3" style="border-left:4px solid var(--mw-orange);">
                <div class="card-body py-3">
                  <h6 class="mb-2" style="color:var(--mw-orange);"><i data-feather="zap" style="width:16px;height:16px;"></i> Per-Service Weather Policies</h6>
                  <p class="mb-2" style="font-size:0.9rem;">
                    Each service can have its own weather policy. For example, <strong>Lawn Mowing</strong> needs dry conditions, but <strong>Snow Removal</strong> is fine in any weather. Click the edit button on a service to set its policy.
                  </p>
                  <p class="mb-0" style="font-size:0.85rem;color:#666;">
                    Services set to <strong>Any Weather</strong> will never be flagged. Services with no policy set will use the global defaults from the first tab. Blank threshold fields also inherit global defaults.
                  </p>
                </div>
              </div>

              <div class="card">
                <div class="card-header">
                  <h5 class="card-title mb-0">Service Package Weather Rules</h5>
                </div>
                <div class="card-body p-0">
                  <div class="table-responsive">
                    <table class="table table-hover mb-0" id="serviceRulesTable">
                      <thead>
                        <tr>
                          <th>Service</th>
                          <th>Category</th>
                          <th>Policy</th>
                          <th>Max Precip %</th>
                          <th>Max mm/hr</th>
                          <th>Temp Range</th>
                          <th>Max Wind</th>
                          <th>Auto-Move</th>
                          <th></th>
                        </tr>
                      </thead>
                      <tbody id="serviceRulesBody">
                        <tr><td colspan="9" class="text-center text-muted py-4">Loading service packages...</td></tr>
                      </tbody>
                    </table>
                  </div>
                </div>
              </div>

              <!-- Edit Service Weather Modal -->
              <div class="modal fade" id="editServiceWeatherModal" tabindex="-1" role="dialog">
                <div class="modal-dialog" role="document">
                  <div class="modal-content">
                    <div class="modal-header">
                      <h5 class="modal-title" id="editServiceModalTitle">Edit Weather Rules</h5>
                      <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                    </div>
                    <div class="modal-body">
                      <form id="serviceWeatherForm">
                        <input type="hidden" id="sw_id">
                        <div class="form-group">
                          <label>Weather Policy</label>
                          <select class="form-control" id="sw_policy" onchange="togglePolicyFields()">
                            <option value="ANY">Any Weather — always good to go</option>
                            <option value="DRY_ONLY">Dry Only — needs no rain</option>
                            <option value="LIGHT_RAIN_OK">Light Rain OK — fine in drizzle, not heavy rain</option>
                            <option value="TEMP_LIMITED">Temperature Sensitive — too hot or cold is a problem</option>
                            <option value="WIND_LIMITED">Wind Sensitive — high wind is a problem</option>
                            <option value="CUSTOM">Custom — set your own thresholds</option>
                          </select>
                          <small class="form-text text-muted" id="sw_policy_hint"></small>
                        </div>

                        <div id="sw_thresholds">
                          <div class="row">
                            <div class="col-md-6">
                              <div class="form-group">
                                <label>Max Precip Chance (%)</label>
                                <input type="number" class="form-control" id="sw_precip_chance" min="0" max="100" step="5" placeholder="Use global">
                              </div>
                            </div>
                            <div class="col-md-6">
                              <div class="form-group">
                                <label>Max Precip mm/hr</label>
                                <input type="number" class="form-control" id="sw_precip_mm" min="0" max="50" step="0.5" placeholder="Use global">
                              </div>
                            </div>
                          </div>
                          <div class="row">
                            <div class="col-md-6">
                              <div class="form-group">
                                <label>Min Temp (°C)</label>
                                <input type="number" class="form-control" id="sw_min_temp" min="-40" max="50" placeholder="Use global">
                              </div>
                            </div>
                            <div class="col-md-6">
                              <div class="form-group">
                                <label>Max Temp (°C)</label>
                                <input type="number" class="form-control" id="sw_max_temp" min="-40" max="50" placeholder="Use global">
                              </div>
                            </div>
                          </div>
                          <div class="form-group">
                            <label>Max Wind (km/h)</label>
                            <input type="number" class="form-control" id="sw_max_wind" min="0" max="200" step="5" placeholder="Use global">
                          </div>
                        </div>

                        <hr>
                        <h6>If Weather Is Bad</h6>
                        <small class="text-muted d-block mb-3">What should happen when this service gets flagged? Leave blank to use global defaults.</small>
                        <div class="row">
                          <div class="col-md-4">
                            <div class="form-group">
                              <label>Move Window (hrs)</label>
                              <input type="number" class="form-control" id="sw_move_window" min="1" max="168" placeholder="Use global">
                              <small class="form-text text-muted">How far ahead to look for a clear slot</small>
                            </div>
                          </div>
                          <div class="col-md-4">
                            <div class="form-group">
                              <label>Earliest Start</label>
                              <input type="time" class="form-control" id="sw_timeband_start">
                            </div>
                          </div>
                          <div class="col-md-4">
                            <div class="form-group">
                              <label>Latest End</label>
                              <input type="time" class="form-control" id="sw_timeband_end">
                            </div>
                          </div>
                        </div>
                        <div class="form-group">
                          <label class="d-flex align-items-center" style="gap:0.5rem;">
                            <input type="checkbox" id="sw_auto_reschedule"> Auto-move to a clear slot when flagged
                          </label>
                          <small class="form-text text-muted ml-4">If off, flagged visits go to the Weather Ops action list for you to decide</small>
                        </div>
                        <div class="form-group">
                          <label class="d-flex align-items-center" style="gap:0.5rem;">
                            <input type="checkbox" id="sw_manual_uncertain" checked> Require my approval on borderline weather
                          </label>
                          <small class="form-text text-muted ml-4">Borderline = conditions are close to the threshold but not clearly bad</small>
                        </div>

                        <div class="mw-weather-preview p-3 mt-3" style="background:var(--mw-light);border-radius:6px;" id="sw_preview">
                          <!-- Preview line generated by JS -->
                        </div>
                      </form>
                    </div>
                    <div class="modal-footer">
                      <button class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                      <button class="btn btn-primary" onclick="saveServiceWeatherRules()">Save Rules</button>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <script>
          // ============================================================
          // Winter Detail Toggle
          // ============================================================
          function toggleWinterDetail(date) {
            var panel = document.getElementById('winterDetail-' + date);
            if (!panel) return;
            if (panel.style.display === 'none') {
              panel.style.display = 'block';
            } else {
              panel.style.display = 'none';
            }
          }

          // ============================================================
          // Global Constraints
          // ============================================================
          let globalConstraints = {};

          document.addEventListener('DOMContentLoaded', function() {
            loadCronStatus();
            loadSaltConfig();
            loadGlobalConstraints();
            loadServiceRules();
          });

          // ============================================================
          // Cron Status
          // ============================================================
          function loadCronStatus() {
            fetch('/crm/api/ops-settings.php?action=get-cron-status')
              .then(r => r.json())
              .then(data => {
                if (!data.success) {
                  setCronStatus('error', 'Error', data.error || 'Failed to load');
                  return;
                }
                if (!data.has_table) {
                  setCronStatus('warning', 'Not Migrated', 'Migration 202 has not run yet — weather_action_log table missing');
                  return;
                }
                if (!data.last_run) {
                  setCronStatus('warning', 'Never Run', 'The weather guard has never been executed. Set up a cron job or click Run Now.');
                  return;
                }

                var lastRun = new Date(data.last_run.replace(' ', 'T'));
                var now = new Date();
                var hoursAgo = Math.round((now - lastRun) / (1000 * 60 * 60));
                var timeAgo = hoursAgo < 1 ? 'just now' : hoursAgo === 1 ? '1 hour ago' : hoursAgo < 24 ? hoursAgo + ' hours ago' : Math.floor(hoursAgo / 24) + ' day(s) ago';

                var isStale = hoursAgo > 25;
                var status = isStale ? 'stale' : 'ok';
                var label = isStale ? 'Stale' : 'Active';
                var detail = 'Last run: ' + formatDateTime(data.last_run) + ' (' + timeAgo + ')';

                setCronStatus(status, label, detail);

                // Today summary
                var t = data.today || {};
                if (t.total > 0) {
                  var parts = [];
                  parts.push(t.total + ' evaluated');
                  if (t.ok > 0) parts.push('<span class="text-success">' + t.ok + ' OK</span>');
                  if (t.not_ok > 0) parts.push('<span class="text-danger">' + t.not_ok + ' flagged</span>');
                  if (t.borderline > 0) parts.push('<span style="color:#e68a00;">' + t.borderline + ' borderline</span>');
                  document.getElementById('cronTodaySummary').innerHTML = 'Today: ' + parts.join(' · ');
                } else {
                  document.getElementById('cronTodaySummary').innerHTML = '<span class="text-muted">No evaluations today</span>';
                }

                // Salt alert info
                if (data.last_salt_alert) {
                  var saltAgo = Math.round((now - new Date(data.last_salt_alert.replace(' ', 'T'))) / (1000 * 60 * 60));
                  var saltLabel = saltAgo < 24 ? 'today' : saltAgo < 48 ? 'yesterday' : Math.floor(saltAgo / 24) + 'd ago';
                  document.getElementById('cronStatusDetail').innerHTML += ' · Last salt alert: ' + saltLabel;
                }
              })
              .catch(err => {
                setCronStatus('error', 'Error', 'Could not check: ' + err.message);
              });
          }

          function setCronStatus(status, label, detail) {
            var badge = document.getElementById('cronStatusBadge');
            var icon = document.getElementById('cronStatusIcon');
            var detailEl = document.getElementById('cronStatusDetail');

            detailEl.innerHTML = detail;

            var colors = {
              ok:      { bg: '#d4edda', text: '#155724', iconBg: '#d4edda', iconColor: 'var(--mw-green)' },
              stale:   { bg: '#fff3cd', text: '#856404', iconBg: '#fff3cd', iconColor: '#e68a00' },
              warning: { bg: '#fff3cd', text: '#856404', iconBg: '#fff3cd', iconColor: '#e68a00' },
              error:   { bg: '#f8d7da', text: '#721c24', iconBg: '#f8d7da', iconColor: '#dc3545' },
            };
            var c = colors[status] || colors.warning;

            badge.textContent = label;
            badge.style.background = c.bg;
            badge.style.color = c.text;
            icon.style.background = c.iconBg;
            icon.querySelector('i, svg').style.color = c.iconColor;

            if (typeof feather !== 'undefined') feather.replace();
          }

          function formatDateTime(str) {
            if (!str) return '—';
            var d = new Date(str.replace(' ', 'T'));
            return d.toLocaleDateString('en-CA', { month: 'short', day: 'numeric' }) +
                   ' at ' + d.toLocaleTimeString('en-CA', { hour: '2-digit', minute: '2-digit' });
          }

          function runCronNow() {
            var btn = document.getElementById('runCronBtn');
            btn.disabled = true;
            btn.innerHTML = '<i data-feather="loader" style="width:14px;height:14px;"></i> Running...';
            if (typeof feather !== 'undefined') feather.replace();

            fetch('/crm/cron/weather_schedule_guard.php', { method: 'POST' })
              .then(r => r.json())
              .then(data => {
                btn.disabled = false;
                btn.innerHTML = '<i data-feather="play" style="width:14px;height:14px;"></i> Run Now';
                if (typeof feather !== 'undefined') feather.replace();

                showCronResults(data);
                loadCronStatus();
              })
              .catch(err => {
                btn.disabled = false;
                btn.innerHTML = '<i data-feather="play" style="width:14px;height:14px;"></i> Run Now';
                if (typeof feather !== 'undefined') feather.replace();
                showCronResults({ success: false, error: err.message });
              });
          }

          function showCronResults(data) {
            // Remove any existing results panel
            var existing = document.getElementById('cronResultsPanel');
            if (existing) existing.remove();

            var card = document.getElementById('cronStatusCard');
            var panel = document.createElement('div');
            panel.id = 'cronResultsPanel';
            panel.style.cssText = 'margin-top:12px;border-top:1px solid #e9ecef;padding-top:12px;';

            if (!data.success) {
              panel.innerHTML = '<div class="alert alert-danger mb-0 py-2"><strong>Error:</strong> ' + escapeHtml(data.error || 'Unknown error') + (data.file ? '<br><small>' + escapeHtml(data.file) + ':' + (data.line||'') + '</small>' : '') + '</div>';
              card.querySelector('.card-body').appendChild(panel);
              return;
            }

            var html = '<div class="row" style="font-size:0.85rem;">';

            // Visit evaluation summary
            html += '<div class="col-md-6"><h6 style="font-size:0.9rem;margin-bottom:8px;">📋 Visit Evaluation</h6>';
            html += '<table class="table table-sm table-borderless mb-2" style="font-size:0.85rem;">';
            html += '<tr><td>Lookahead window</td><td><strong>' + escapeHtml(data.lookahead || '—') + '</strong></td></tr>';
            html += '<tr><td>Total visits found</td><td><strong>' + (data.total_visits || 0) + '</strong></td></tr>';
            html += '<tr><td>Evaluated</td><td><strong>' + (data.evaluated || 0) + '</strong></td></tr>';
            html += '<tr><td>Skipped (already done today)</td><td>' + (data.skipped_dedup || 0) + '</td></tr>';
            if (data.ok > 0) html += '<tr><td>OK</td><td><span class="text-success">' + data.ok + '</span></td></tr>';
            if (data.not_ok > 0) html += '<tr><td>Flagged NOT_OK</td><td><span class="text-danger">' + data.not_ok + '</span></td></tr>';
            if (data.borderline > 0) html += '<tr><td>Borderline</td><td><span style="color:#e68a00;">' + data.borderline + '</span></td></tr>';
            if (data.auto_moved > 0) html += '<tr><td>Auto-rescheduled</td><td><span class="text-info">' + data.auto_moved + '</span></td></tr>';
            html += '</table>';

            // Visit errors
            if (data.errors && data.errors.length > 0) {
              html += '<div class="text-danger" style="font-size:0.8rem;">';
              data.errors.forEach(function(e) { html += '⚠ ' + escapeHtml(e) + '<br>'; });
              html += '</div>';
            }
            html += '</div>';

            // Salt operations summary
            html += '<div class="col-md-6"><h6 style="font-size:0.9rem;margin-bottom:8px;">❄️ Salt Operations</h6>';
            var s = data.salt || {};
            html += '<table class="table table-sm table-borderless mb-2" style="font-size:0.85rem;">';
            html += '<tr><td>Salt check ran</td><td><strong>' + (s.checked ? 'Yes' : 'No') + '</strong></td></tr>';
            html += '<tr><td>Salt days in forecast</td><td><strong>' + (s.salt_days !== undefined ? s.salt_days : '—') + '</strong></td></tr>';
            html += '<tr><td>Alerts sent</td><td><strong>' + (s.alerts_sent || 0) + '</strong></td></tr>';
            if (s.skipped > 0) html += '<tr><td>Skipped (dedup/quiet)</td><td>' + s.skipped + '</td></tr>';
            html += '</table>';

            if (s.forecast && s.forecast.length > 0) {
              html += '<div style="font-size:0.8rem;margin-top:4px;">';
              html += '<strong>Forecast vs trigger (≤ ' + (s.trigger_temp !== undefined ? s.trigger_temp : '0') + '°C):</strong><br>';
              s.forecast.forEach(function(f) {
                var icon = f.salt ? '❄️' : '✅';
                html += icon + ' ' + f.date + ': low ' + f.low + '°C, ' + escapeHtml(f.condition) + '<br>';
              });
              html += '</div>';
            } else if (s.salt_days === 0) {
              html += '<div class="text-muted" style="font-size:0.8rem;">No salt days detected. Current trigger: ≤ ' + (document.getElementById('salt_trigger_temp').value || '0') + '°C. Forecast lows are above this threshold.</div>';
            }

            if (s.skipped_reason) {
              html += '<div class="text-warning" style="font-size:0.8rem;">ℹ ' + escapeHtml(s.skipped_reason) + '</div>';
            }

            // Salt errors
            if (s.errors && s.errors.length > 0) {
              html += '<div class="text-danger" style="font-size:0.8rem;">';
              s.errors.forEach(function(e) { html += '⚠ ' + escapeHtml(e) + '<br>'; });
              html += '</div>';
            }
            html += '</div></div>';

            // Dismiss button
            html += '<div class="text-right mt-2"><button class="btn btn-sm btn-outline-secondary" onclick="document.getElementById(\'cronResultsPanel\').remove()">Dismiss</button></div>';

            panel.innerHTML = html;
            card.querySelector('.card-body').appendChild(panel);
          }

          function toggleCronSetup() {
            var panel = document.getElementById('cronSetupPanel');
            panel.style.display = panel.style.display === 'none' ? 'block' : 'none';
          }

          function copyCronCommand() {
            var text = document.getElementById('cronCommand').textContent;
            navigator.clipboard.writeText(text).then(function() {
              var btn = event.target.closest('button');
              var orig = btn.innerHTML;
              btn.innerHTML = '<i data-feather="check" style="width:14px;height:14px;"></i> Copied';
              if (typeof feather !== 'undefined') feather.replace();
              setTimeout(function() { btn.innerHTML = orig; if (typeof feather !== 'undefined') feather.replace(); }, 2000);
            });
          }

          function copyCurlCommand() {
            var text = document.getElementById('cronCurlCommand').textContent;
            navigator.clipboard.writeText(text).then(function() {
              var btn = event.target.closest('button');
              var orig = btn.innerHTML;
              btn.innerHTML = '<i data-feather="check" style="width:14px;height:14px;"></i> Copied';
              if (typeof feather !== 'undefined') feather.replace();
              setTimeout(function() { btn.innerHTML = orig; if (typeof feather !== 'undefined') feather.replace(); }, 2000);
            });
          }

          function loadGlobalConstraints() {
            fetch('/crm/api/ops-settings.php?action=get&key=weather_ops_constraints')
              .then(r => r.json())
              .then(data => {
                if (data.success && data.value) {
                  globalConstraints = data.value;
                  populateGlobalForm(globalConstraints);
                }
              })
              .catch(err => console.error('Error loading constraints:', err));
          }

          function populateGlobalForm(c) {
            document.getElementById('gc_max_precip_chance').value = c.default_max_precip_chance_pct ?? 50;
            document.getElementById('gc_max_precip_mm').value = c.default_max_precip_mm_per_hr ?? 2.5;
            document.getElementById('gc_borderline_low').value = c.borderline_precip_chance_low ?? 30;
            document.getElementById('gc_borderline_high').value = c.borderline_precip_chance_high ?? 50;
            document.getElementById('gc_min_temp').value = c.default_min_temp_c ?? -5;
            document.getElementById('gc_max_temp').value = c.default_max_temp_c ?? 40;
            document.getElementById('gc_max_wind').value = c.default_max_wind_kph ?? 50;
            document.getElementById('gc_move_window').value = c.default_move_window_hours ?? 48;
            document.getElementById('gc_timeband_start').value = c.default_timeband_start ?? '07:00';
            document.getElementById('gc_timeband_end').value = c.default_timeband_end ?? '18:00';
            document.getElementById('gc_decision_time').value = c.decision_time ?? '12:00';
            document.getElementById('gc_lookahead_days').value = c.lookahead_days ?? 2;
            document.getElementById('gc_auto_reschedule').checked = !!c.auto_reschedule_enabled;
            document.getElementById('gc_swap_suggestions').checked = c.swap_suggestions_enabled !== false;
          }

          function saveGlobalConstraints() {
            const constraints = {
              default_max_precip_chance_pct: parseInt(document.getElementById('gc_max_precip_chance').value) || 50,
              default_max_precip_mm_per_hr: parseFloat(document.getElementById('gc_max_precip_mm').value) || 2.5,
              borderline_precip_chance_low: parseInt(document.getElementById('gc_borderline_low').value) || 30,
              borderline_precip_chance_high: parseInt(document.getElementById('gc_borderline_high').value) || 50,
              default_min_temp_c: parseFloat(document.getElementById('gc_min_temp').value),
              default_max_temp_c: parseFloat(document.getElementById('gc_max_temp').value),
              default_max_wind_kph: parseFloat(document.getElementById('gc_max_wind').value) || 50,
              default_move_window_hours: parseInt(document.getElementById('gc_move_window').value) || 48,
              default_timeband_start: document.getElementById('gc_timeband_start').value || '07:00',
              default_timeband_end: document.getElementById('gc_timeband_end').value || '18:00',
              decision_time: document.getElementById('gc_decision_time').value || '12:00',
              lookahead_days: parseInt(document.getElementById('gc_lookahead_days').value) || 2,
              auto_reschedule_enabled: document.getElementById('gc_auto_reschedule').checked,
              swap_suggestions_enabled: document.getElementById('gc_swap_suggestions').checked,
            };

            fetch('/crm/api/ops-settings.php?action=save', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({
                key: 'weather_ops_constraints',
                value: constraints,
                description: 'Weather operations constraints: thresholds, borderline bands, reschedule defaults'
              })
            })
            .then(r => r.json())
            .then(data => {
              const status = document.getElementById('globalSaveStatus');
              if (data.success) {
                status.textContent = 'Saved!';
                status.style.color = 'var(--mw-green)';
              } else {
                status.textContent = 'Error: ' + (data.error || 'Unknown');
                status.style.color = '#dc3545';
              }
              setTimeout(() => { status.textContent = ''; }, 3000);
            })
            .catch(err => alert('Error: ' + err.message));
          }

          // ============================================================
          // Service Weather Rules
          // ============================================================
          let allServicePackages = [];

          function loadServiceRules() {
            fetch('/crm/api/ops-settings.php?action=get-service-weather-rules')
              .then(r => r.json())
              .then(data => {
                if (data.success) {
                  allServicePackages = data.packages;
                  renderServiceTable();
                }
              })
              .catch(err => console.error('Error loading service rules:', err));
          }

          function renderServiceTable() {
            const tbody = document.getElementById('serviceRulesBody');
            if (allServicePackages.length === 0) {
              tbody.innerHTML = '<tr><td colspan="9" class="text-center text-muted py-4">' +
                'No service packages found.<br><small>Create service packages in <a href="/crm/products/products-manager.php">Products</a> first, then come back here to set their weather rules.</small>' +
                '</td></tr>';
              return;
            }

            tbody.innerHTML = allServicePackages.map(sp => {
              const policy = sp.weather_policy || 'ANY';
              const policyClass = policy === 'ANY' ? 'badge-secondary' :
                                  policy === 'DRY_ONLY' ? 'badge-warning' :
                                  policy === 'CUSTOM' ? 'badge-info' : 'badge-primary';
              const label = policyLabels[policy] || policy;
              const tempRange = (sp.min_temp_c !== null || sp.max_temp_c !== null)
                ? (sp.min_temp_c ?? '?') + '° to ' + (sp.max_temp_c ?? '?') + '°'
                : '<span class="text-muted">global</span>';
              const precipDisplay = sp.max_precip_chance_pct !== null ? sp.max_precip_chance_pct + '%' : '<span class="text-muted">global</span>';
              const mmDisplay = sp.max_precip_mm_per_hr !== null ? sp.max_precip_mm_per_hr : '<span class="text-muted">global</span>';
              const windDisplay = sp.max_wind_kph !== null ? sp.max_wind_kph + ' km/h' : '<span class="text-muted">global</span>';

              return '<tr>' +
                '<td><strong>' + escapeHtml(sp.package_name) + '</strong>' +
                  (!sp.is_active ? ' <span class="badge badge-secondary">Inactive</span>' : '') +
                '</td>' +
                '<td>' + escapeHtml(sp.category || '—') + '</td>' +
                '<td><span class="badge ' + policyClass + '">' + label + '</span></td>' +
                '<td>' + (policy === 'ANY' ? '<span class="text-muted">n/a</span>' : precipDisplay) + '</td>' +
                '<td>' + (policy === 'ANY' ? '<span class="text-muted">n/a</span>' : mmDisplay) + '</td>' +
                '<td>' + (policy === 'ANY' ? '<span class="text-muted">n/a</span>' : tempRange) + '</td>' +
                '<td>' + (policy === 'ANY' ? '<span class="text-muted">n/a</span>' : windDisplay) + '</td>' +
                '<td>' + (sp.auto_reschedule ? '<span class="text-success">Yes</span>' : '<span class="text-muted">No</span>') + '</td>' +
                '<td><button class="btn btn-sm btn-outline-primary" onclick="editServiceWeather(' + sp.id + ')" title="Edit weather rules"><i data-feather="edit-2"></i></button></td>' +
              '</tr>';
            }).join('');

            if (typeof feather !== 'undefined') feather.replace();
          }

          function editServiceWeather(id) {
            const sp = allServicePackages.find(s => s.id == id);
            if (!sp) return;

            document.getElementById('editServiceModalTitle').textContent = 'Weather Rules: ' + sp.package_name;
            document.getElementById('sw_id').value = sp.id;
            document.getElementById('sw_policy').value = sp.weather_policy || 'ANY';
            document.getElementById('sw_precip_chance').value = sp.max_precip_chance_pct ?? '';
            document.getElementById('sw_precip_mm').value = sp.max_precip_mm_per_hr ?? '';
            document.getElementById('sw_min_temp').value = sp.min_temp_c ?? '';
            document.getElementById('sw_max_temp').value = sp.max_temp_c ?? '';
            document.getElementById('sw_max_wind').value = sp.max_wind_kph ?? '';
            document.getElementById('sw_move_window').value = sp.move_window_hours ?? '';
            document.getElementById('sw_timeband_start').value = sp.move_timeband_start ?? '';
            document.getElementById('sw_timeband_end').value = sp.move_timeband_end ?? '';
            document.getElementById('sw_auto_reschedule').checked = !!parseInt(sp.auto_reschedule);
            document.getElementById('sw_manual_uncertain').checked = parseInt(sp.require_manual_if_uncertain) !== 0;

            togglePolicyFields();
            updatePreview();
            $('#editServiceWeatherModal').modal('show');
          }

          const policyHints = {
            'ANY': 'This service won\'t be checked by the weather guard at all.',
            'DRY_ONLY': 'Flagged if there\'s any rain in the forecast during the visit window.',
            'LIGHT_RAIN_OK': 'Flagged only for heavy rain — light drizzle is fine.',
            'TEMP_LIMITED': 'Flagged if temperature is outside the allowed range.',
            'WIND_LIMITED': 'Flagged if wind speed exceeds the threshold.',
            'CUSTOM': 'Set your own combination of precipitation, temperature, and wind thresholds.',
          };

          const policyLabels = {
            'ANY': 'Any Weather',
            'DRY_ONLY': 'Dry Only',
            'LIGHT_RAIN_OK': 'Light Rain OK',
            'TEMP_LIMITED': 'Temp Sensitive',
            'WIND_LIMITED': 'Wind Sensitive',
            'CUSTOM': 'Custom',
          };

          function togglePolicyFields() {
            const policy = document.getElementById('sw_policy').value;
            const show = policy !== 'ANY';
            document.getElementById('sw_thresholds').style.display = show ? 'block' : 'none';
            document.getElementById('sw_policy_hint').textContent = policyHints[policy] || '';
            updatePreview();
          }

          function updatePreview() {
            const policy = document.getElementById('sw_policy').value;
            const preview = document.getElementById('sw_preview');
            let label = policyLabels[policy] || policy;
            let parts = [];

            if (policy !== 'ANY') {
              const pc = document.getElementById('sw_precip_chance').value;
              const mm = document.getElementById('sw_precip_mm').value;
              const mt = document.getElementById('sw_min_temp').value;
              const xt = document.getElementById('sw_max_temp').value;
              const w = document.getElementById('sw_max_wind').value;
              if (pc) parts.push('flag above ' + pc + '% rain chance');
              if (mm) parts.push('flag above ' + mm + 'mm/hr');
              if (mt) parts.push('too cold below ' + mt + '°C');
              if (xt) parts.push('too hot above ' + xt + '°C');
              if (w) parts.push('too windy above ' + w + ' km/h');
            }

            const detail = parts.length ? ' — ' + parts.join(', ') : '';
            preview.innerHTML = '<small class="text-muted">Summary:</small> <strong>' + label + '</strong>' + detail;
          }

          // Bind preview updates
          document.addEventListener('DOMContentLoaded', function() {
            ['sw_policy','sw_precip_chance','sw_precip_mm','sw_min_temp','sw_max_temp','sw_max_wind'].forEach(function(id) {
              var el = document.getElementById(id);
              if (el) el.addEventListener('input', updatePreview);
            });
          });

          function saveServiceWeatherRules() {
            const data = {
              id: parseInt(document.getElementById('sw_id').value),
              weather_policy: document.getElementById('sw_policy').value,
              max_precip_chance_pct: document.getElementById('sw_precip_chance').value || null,
              max_precip_mm_per_hr: document.getElementById('sw_precip_mm').value || null,
              min_temp_c: document.getElementById('sw_min_temp').value !== '' ? document.getElementById('sw_min_temp').value : null,
              max_temp_c: document.getElementById('sw_max_temp').value !== '' ? document.getElementById('sw_max_temp').value : null,
              max_wind_kph: document.getElementById('sw_max_wind').value || null,
              move_window_hours: document.getElementById('sw_move_window').value || null,
              move_timeband_start: document.getElementById('sw_timeband_start').value || null,
              move_timeband_end: document.getElementById('sw_timeband_end').value || null,
              auto_reschedule: document.getElementById('sw_auto_reschedule').checked ? 1 : 0,
              require_manual_if_uncertain: document.getElementById('sw_manual_uncertain').checked ? 1 : 0,
            };

            fetch('/crm/api/ops-settings.php?action=save-service-weather-rules', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify(data)
            })
            .then(r => r.json())
            .then(result => {
              if (result.success) {
                $('#editServiceWeatherModal').modal('hide');
                loadServiceRules();
              } else {
                alert('Error: ' + (result.error || 'Unknown'));
              }
            })
            .catch(err => alert('Error: ' + err.message));
          }

          function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
          }

          // ============================================================
          // Salt Operations Config
          // ============================================================
          let saltConfig = {};

          function loadSaltConfig() {
            fetch('/crm/api/ops-settings.php?action=get&key=salt_ops_config')
              .then(r => r.json())
              .then(data => {
                if (data.success && data.value) {
                  saltConfig = data.value;
                } else {
                  // Defaults
                  saltConfig = {
                    salt_trigger_temp_c: 0,
                    salt_on_snow: true,
                    salt_on_freezing_rain: true,
                    salt_on_ice_pellets: true,
                    alert_7day: false,
                    alert_48hr: true,
                    alert_24hr: true,
                    quiet_hour_start: '20:00',
                    quiet_unless_clocked_in: true
                  };
                }
                populateSaltForm(saltConfig);
                updateSaltPreview();
              })
              .catch(err => console.error('Error loading salt config:', err));
          }

          function populateSaltForm(c) {
            document.getElementById('salt_trigger_temp').value = c.salt_trigger_temp_c ?? 0;
            document.getElementById('salt_on_snow').checked = c.salt_on_snow !== false;
            document.getElementById('salt_on_freezing_rain').checked = c.salt_on_freezing_rain !== false;
            document.getElementById('salt_on_ice_pellets').checked = c.salt_on_ice_pellets !== false;
            document.getElementById('salt_alert_7day').checked = !!c.alert_7day;
            document.getElementById('salt_alert_48hr').checked = c.alert_48hr !== false;
            document.getElementById('salt_alert_24hr').checked = c.alert_24hr !== false;
            document.getElementById('salt_quiet_hour').value = c.quiet_hour_start || '20:00';
            document.getElementById('salt_quiet_exception').checked = c.quiet_unless_clocked_in !== false;
          }

          function saveSaltConfig() {
            const config = {
              salt_trigger_temp_c: parseFloat(document.getElementById('salt_trigger_temp').value) || 0,
              salt_on_snow: document.getElementById('salt_on_snow').checked,
              salt_on_freezing_rain: document.getElementById('salt_on_freezing_rain').checked,
              salt_on_ice_pellets: document.getElementById('salt_on_ice_pellets').checked,
              alert_7day: document.getElementById('salt_alert_7day').checked,
              alert_48hr: document.getElementById('salt_alert_48hr').checked,
              alert_24hr: document.getElementById('salt_alert_24hr').checked,
              quiet_hour_start: document.getElementById('salt_quiet_hour').value || '20:00',
              quiet_unless_clocked_in: document.getElementById('salt_quiet_exception').checked,
            };

            fetch('/crm/api/ops-settings.php?action=save', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({
                key: 'salt_ops_config',
                value: config,
                description: 'Salt operations configuration: trigger temp, conditions, SMS alerts, quiet hours'
              })
            })
            .then(r => r.json())
            .then(data => {
              const status = document.getElementById('saltSaveStatus');
              if (data.success) {
                saltConfig = config;
                status.textContent = 'Saved!';
                status.style.color = 'var(--mw-green)';
                updateSaltPreview();
              } else {
                status.textContent = 'Error: ' + (data.error || 'Unknown');
                status.style.color = '#dc3545';
              }
              setTimeout(() => { status.textContent = ''; }, 3000);
            })
            .catch(err => alert('Error: ' + err.message));
          }

          function updateSaltPreview() {
            // Re-evaluate forecast cards against current settings
            const triggerTemp = parseFloat(document.getElementById('salt_trigger_temp').value) || 0;
            const checkSnow = document.getElementById('salt_on_snow').checked;
            const checkFreezing = document.getElementById('salt_on_freezing_rain').checked;
            const checkIce = document.getElementById('salt_on_ice_pellets').checked;

            document.querySelectorAll('#saltForecastPreview > div').forEach(function(card) {
              const low = parseFloat(card.dataset.low);
              const cond = (card.dataset.condition || '').toLowerCase();
              const isToday = card.querySelector('[style*="var(--mw-green)"]') !== null;
              let isSaltDay = low <= triggerTemp;
              if (!isSaltDay && checkSnow && cond.indexOf('snow') !== -1) isSaltDay = true;
              if (!isSaltDay && checkFreezing && cond.indexOf('freezing') !== -1) isSaltDay = true;
              if (!isSaltDay && checkIce && cond.indexOf('ice') !== -1) isSaltDay = true;

              if (isSaltDay) {
                card.style.background = '#e3f2fd';
                card.style.border = '2px solid #42a5f5';
                card.classList.add('salt-day-highlight');
              } else if (isToday) {
                card.style.background = '#f0f9f4';
                card.style.border = '2px solid var(--mw-green)';
                card.classList.remove('salt-day-highlight');
              } else {
                card.style.background = '#f8f9fa';
                card.style.border = '2px solid transparent';
                card.classList.remove('salt-day-highlight');
              }

              // Toggle SALT label visibility
              var saltLabel = card.querySelector('div[style*="SALT"]') || Array.from(card.querySelectorAll('div')).find(function(el) { return el.textContent.trim() === '🧂 SALT'; });
              if (saltLabel) {
                saltLabel.style.display = isSaltDay ? '' : 'none';
              }
            });
          }

          // ============================================================
          // Test Alert Buttons
          // ============================================================
          function sendTestEmail() {
            var btn = document.getElementById('testEmailBtn');
            var result = document.getElementById('testEmailResult');
            btn.disabled = true;
            btn.innerHTML = '<i data-feather="loader" style="width:16px;height:16px;"></i> Sending...';
            if (typeof feather !== 'undefined') feather.replace();
            result.innerHTML = '';

            fetch('/crm/api/ops-settings.php?action=test-email', { method: 'POST' })
              .then(function(r) { return r.json(); })
              .then(function(data) {
                btn.disabled = false;
                btn.innerHTML = '<i data-feather="mail" style="width:16px;height:16px;"></i> Send Test Email';
                if (typeof feather !== 'undefined') feather.replace();

                if (data.success) {
                  result.innerHTML = '<span class="text-success">✅ Sent to ' + escapeHtml(data.to) + '</span>';
                } else {
                  result.innerHTML = '<span class="text-danger">❌ ' + escapeHtml(data.error || 'Failed') + '</span>';
                }
                setTimeout(function() { result.innerHTML = ''; }, 8000);
              })
              .catch(function(err) {
                btn.disabled = false;
                btn.innerHTML = '<i data-feather="mail" style="width:16px;height:16px;"></i> Send Test Email';
                if (typeof feather !== 'undefined') feather.replace();
                result.innerHTML = '<span class="text-danger">❌ ' + escapeHtml(err.message) + '</span>';
              });
          }

          function sendTestSms() {
            var btn = document.getElementById('testSmsBtn');
            var result = document.getElementById('testSmsResult');
            btn.disabled = true;
            btn.innerHTML = '<i data-feather="loader" style="width:16px;height:16px;"></i> Sending...';
            if (typeof feather !== 'undefined') feather.replace();
            result.innerHTML = '';

            fetch('/crm/api/ops-settings.php?action=test-sms', { method: 'POST' })
              .then(function(r) { return r.json(); })
              .then(function(data) {
                btn.disabled = false;
                btn.innerHTML = '<i data-feather="smartphone" style="width:16px;height:16px;"></i> Send Test SMS';
                if (typeof feather !== 'undefined') feather.replace();

                if (data.success) {
                  result.innerHTML = '<span class="text-success">✅ Sent to ' + escapeHtml(data.to) + '</span>';
                } else {
                  result.innerHTML = '<span class="text-danger">❌ ' + escapeHtml(data.error || 'Failed') + '</span>';
                }
                setTimeout(function() { result.innerHTML = ''; }, 8000);
              })
              .catch(function(err) {
                btn.disabled = false;
                btn.innerHTML = '<i data-feather="smartphone" style="width:16px;height:16px;"></i> Send Test SMS';
                if (typeof feather !== 'undefined') feather.replace();
                result.innerHTML = '<span class="text-danger">❌ ' + escapeHtml(err.message) + '</span>';
              });
          }

          // Live preview: update when user changes trigger settings
          document.addEventListener('DOMContentLoaded', function() {
            ['salt_trigger_temp','salt_on_snow','salt_on_freezing_rain','salt_on_ice_pellets'].forEach(function(id) {
              var el = document.getElementById(id);
              if (el) el.addEventListener('change', updateSaltPreview);
              if (el && el.type === 'number') el.addEventListener('input', updateSaltPreview);
            });
          });
          </script>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
