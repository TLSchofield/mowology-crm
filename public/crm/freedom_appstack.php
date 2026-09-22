<?php
/**
 * Owner Freedom Dashboard
 * ─────────────────────────────────────────────────────────────────────────────
 * "If I stopped working tomorrow and bought back every hour I put in, could the
 * business still pay my 40-hour cheque?" One score, the levers that move it,
 * and the tracking that has to be in place for the score to be trusted.
 *
 * Thin controller: all maths lives in OwnerFreedomService.
 */
declare(strict_types=1);
require_once __DIR__ . '/../loginAuth/auth.php';
require_once __DIR__ . '/includes/functions.php';
requireLogin();
$user = getCurrentUser();
requirePermission('expenses.view');

require_once APP_ROOT . '/Modules/Accounting/Services/OwnerFreedomService.php';

$db      = getDB();
$service = new OwnerFreedomService($db);

// ── Period ────────────────────────────────────────────────────────────────────
$period = $_GET['period'] ?? 'last_90';
switch ($period) {
    case 'this_month': $dateFrom = date('Y-m-01');                              $dateTo = date('Y-m-d'); $periodLabel = 'This month';    break;
    case 'last_month': $dateFrom = date('Y-m-01', strtotime('first day of last month')); $dateTo = date('Y-m-t', strtotime('last day of last month')); $periodLabel = 'Last month'; break;
    case 'ytd':        $dateFrom = date('Y-01-01');                              $dateTo = date('Y-m-d'); $periodLabel = 'Year to date';  break;
    case 'last_12m':   $dateFrom = date('Y-m-d', strtotime('-365 days'));        $dateTo = date('Y-m-d'); $periodLabel = 'Last 12 months'; break;
    default:           $period = 'last_90'; $dateFrom = date('Y-m-d', strtotime('-89 days')); $dateTo = date('Y-m-d'); $periodLabel = 'Last 90 days';
}
$periods = ['this_month' => 'This month', 'last_month' => 'Last month', 'last_90' => '90 days', 'ytd' => 'YTD', 'last_12m' => '12 months'];

// ── Settings save ─────────────────────────────────────────────────────────────
$saveError = null;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['freedom_settings'])) {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $saveError = 'Your session expired — please try again.';
    } elseif (!isAdmin()) {
        $saveError = 'Only an admin can change these settings.';
    } else {
        try {
            $service->saveSettings($_POST, (int)$user['id']);
            header('Location: /crm/freedom_appstack.php?period=' . urlencode($period) . '&saved=1', true, 303);
            exit;
        } catch (Throwable $e) {
            error_log('OwnerFreedom saveSettings: ' . $e->getMessage());
            $saveError = 'Could not save settings.';
        }
    }
}

// ── Report ────────────────────────────────────────────────────────────────────
$report  = $service->report($dateFrom, $dateTo);
$m       = $report['metrics'];
$s       = $report['settings'];
$q       = $report['quality'];
$dirs    = $report['directions'];
$users   = $service->users();

$qCounts = ['ok' => 0, 'warn' => 0, 'fail' => 0, 'unknown' => 0];
foreach ($q as $item) { $qCounts[$item['status']] = ($qCounts[$item['status']] ?? 0) + 1; }

function fd_money(float $v, int $dec = 0): string { return ($v < 0 ? '-' : '') . '$' . number_format(abs($v), $dec); }
function fd_pct(?float $v): string { return $v === null ? '—' : number_format($v, 0) . '%'; }

$score      = $m['freedom_score'];
$stage      = $m['stage'];
$rankLabels = ['first' => 'Do first', 'lever' => 'Lever', 'risk' => 'Risk', 'cash' => 'Cash', 'done' => 'Protect'];

$pageTitle  = 'Owner Freedom';
$activePage = 'freedom';
$extraHead  = '<script src="https://unpkg.com/chart.js@4.4.7/dist/chart.umd.js"></script>';
?>
<?php include 'includes/appstack_head.php'; ?>

          <div class="mw-page-header">
              <div class="mw-page-header-left">
                  <h1 class="mw-page-title">Owner Freedom</h1>
                  <p class="mw-page-subtitle">Could the business keep paying your <?= number_format($s['target_hours_week'], 0) ?>-hour cheque if you stopped working? <?= h($periodLabel) ?>, <?= date('M j', strtotime($dateFrom)) ?> – <?= date('M j, Y', strtotime($dateTo)) ?>.</p>
              </div>
              <div class="mw-page-actions">
                  <div class="btn-group btn-group-sm mw-fd-periods" role="group" aria-label="Period">
                      <?php foreach ($periods as $key => $label): ?>
                          <a href="?period=<?= $key ?>" class="btn <?= $period === $key ? 'btn-primary' : 'btn-outline-secondary' ?>"><?= $label ?></a>
                      <?php endforeach; ?>
                  </div>
              </div>
          </div>

          <?php if (isset($_GET['saved'])): ?>
              <div class="alert alert-success py-2">Settings saved. The score below uses the new figures.</div>
          <?php endif; ?>
          <?php if ($saveError): ?>
              <div class="alert alert-danger py-2"><?= h($saveError) ?></div>
          <?php endif; ?>

          <!-- ── Hero: score + verdict ─────────────────────────────────────── -->
          <div class="card mw-fd-hero mw-fd-stage-<?= h($stage['key']) ?>">
              <div class="card-body">
                  <div class="mw-fd-hero-grid">
                      <div class="mw-fd-ring-wrap">
                          <canvas id="mwFdRing" width="190" height="190" aria-label="Freedom score"></canvas>
                          <div class="mw-fd-ring-label">
                              <div class="mw-fd-ring-score"><?= $score === null ? '—' : $score ?></div>
                              <div class="mw-fd-ring-sub">freedom score</div>
                          </div>
                      </div>
                      <div class="mw-fd-verdict">
                          <span class="mw-fd-stage-badge"><?= h($stage['label']) ?></span>
                          <?php if ($m['freedom_pct'] !== null): ?>
                              <h2 class="mw-fd-headline">
                                  If you stopped tomorrow and hired for your hours, the business would pay you
                                  <strong><?= fd_money((float)$m['available_week']) ?></strong> of your
                                  <strong><?= fd_money((float)$m['target_week']) ?></strong> a week.
                              </h2>
                              <p class="mw-fd-blurb"><?= h($stage['blurb']) ?></p>
                              <div class="mw-fd-keyfigs">
                                  <div class="mw-fd-keyfig">
                                      <div class="mw-fd-keyfig-val"><?= fd_money((float)$m['target_week']) ?></div>
                                      <div class="mw-fd-keyfig-lbl">your cheque / week</div>
                                  </div>
                                  <div class="mw-fd-keyfig">
                                      <div class="mw-fd-keyfig-val"><?= fd_money((float)$m['available_week']) ?></div>
                                      <div class="mw-fd-keyfig-lbl">business pays without you</div>
                                  </div>
                                  <div class="mw-fd-keyfig <?= $m['gap_week'] > 0 ? 'mw-fd-keyfig-gap' : 'mw-fd-keyfig-surplus' ?>">
                                      <div class="mw-fd-keyfig-val"><?= $m['gap_week'] > 0 ? fd_money((float)$m['gap_week']) : '+' . fd_money((float)$m['surplus_week']) ?></div>
                                      <div class="mw-fd-keyfig-lbl"><?= $m['gap_week'] > 0 ? 'gap to close / week' : 'surplus / week' ?></div>
                                  </div>
                                  <div class="mw-fd-keyfig">
                                      <div class="mw-fd-keyfig-val"><?= number_format((float)$m['owner_hours_week'], 1) ?> h</div>
                                      <div class="mw-fd-keyfig-lbl">you still work / week</div>
                                  </div>
                              </div>
                          <?php else: ?>
                              <h2 class="mw-fd-headline">Set your pay rate to score the business.</h2>
                              <p class="mw-fd-blurb"><?= h($stage['blurb']) ?></p>
                              <a href="#mwFreedomSettings" class="btn btn-primary btn-sm">Open settings</a>
                          <?php endif; ?>
                      </div>
                  </div>
              </div>
          </div>

          <!-- ── The four numbers behind the score ─────────────────────────── -->
          <div class="row mw-fd-stats">
              <div class="col-6 col-xl-3">
                  <div class="card stat-card mw-fd-stat">
                      <div class="card-body">
                          <h5 class="card-title">Your hours</h5>
                          <h1><?= number_format((float)$m['owner_hours_week'], 1) ?><small>h/wk</small></h1>
                          <div class="text-muted small"><?= number_format((float)$m['owner_field_hours_week'], 1) ?> h field · <?= number_format((float)$m['owner_admin_hours_week'], 1) ?> h office</div>
                      </div>
                  </div>
              </div>
              <div class="col-6 col-xl-3">
                  <div class="card stat-card mw-fd-stat">
                      <div class="card-body">
                          <h5 class="card-title">Profit before your pay</h5>
                          <h1><?= fd_money((float)$m['profit_before_owner_week']) ?><small>/wk</small></h1>
                          <div class="text-muted small"><?= fd_money((float)$m['revenue']) ?> invoiced − crew − expenses<?= $m['fixed_overhead'] > 0 ? ' − overhead' : '' ?></div>
                      </div>
                  </div>
              </div>
              <div class="col-6 col-xl-3">
                  <div class="card stat-card mw-fd-stat">
                      <div class="card-body">
                          <h5 class="card-title">Cost to replace you</h5>
                          <h1><?= fd_money((float)$m['replacement_cost_week']) ?><small>/wk</small></h1>
                          <div class="text-muted small">field at $<?= number_format((float)$m['field_replacement_rate'], 0) ?>/h · office at $<?= number_format((float)$m['admin_replacement_rate'], 0) ?>/h</div>
                      </div>
                  </div>
              </div>
              <div class="col-6 col-xl-3">
                  <div class="card stat-card mw-fd-stat <?= $m['gap_week'] > 0 ? 'mw-fd-stat-short' : 'mw-fd-stat-covered' ?>">
                      <div class="card-body">
                          <h5 class="card-title">Left for your cheque</h5>
                          <h1><?= fd_money((float)$m['available_week']) ?><small>/wk</small></h1>
                          <div class="text-muted small"><?= fd_pct($m['freedom_pct']) ?> of target · <?= fd_pct($m['coverage_now_pct']) ?> while you keep working</div>
                      </div>
                  </div>
              </div>
          </div>

          <?php if ($m['turnover_needed_season_week'] !== null): ?>
          <div class="card mw-fd-turnover">
              <div class="card-body">
                  <div class="mw-fd-turnover-grid">
                      <div>
                          <div class="mw-fd-turnover-lbl">Turnover needed to cover your work</div>
                          <div class="mw-fd-turnover-val"><?= fd_money((float)$m['turnover_needed_season_week']) ?><small>/week in season</small></div>
                          <div class="text-muted small"><?= fd_money((float)$m['turnover_needed_year']) ?> a year over <?= number_format((float)$m['season_weeks_year'], 0) ?> working weeks (<?= date('M', mktime(0,0,0,(int)$s['season_start_month'],1)) ?>–<?= date('M', mktime(0,0,0,(int)$s['season_end_month'],1)) ?>)</div>
                      </div>
                      <div>
                          <div class="mw-fd-turnover-lbl">You averaged</div>
                          <div class="mw-fd-turnover-val <?= (float)$m['turnover_gap_week'] > 0 ? 'mw-fd-turnover-short' : 'mw-fd-turnover-ok' ?>"><?= fd_money((float)$m['turnover_now_week']) ?><small>/week</small></div>
                          <div class="text-muted small"><?= (float)$m['turnover_gap_week'] > 0 ? fd_money((float)$m['turnover_gap_week']) . '/week short of the target' : 'Above the target in this period' ?></div>
                      </div>
                      <div class="mw-fd-turnover-stack">
                          <div class="mw-fd-turnover-lbl">What that turnover has to pay for, per year</div>
                          <div class="mw-fd-turnover-row"><span><?= $m['replacement_mode'] === 'planned' ? h(implode(' + ', array_map(fn($h) => $h['name'], $m['planned_hires']))) . ' (' . number_format((float)$m['planned_weekly'], 0) . '/wk loaded)' : 'Crew hired for your hours' ?></span><strong><?= fd_money((float)$m['cost_stack_year']['crew']) ?></strong></div>
                          <div class="mw-fd-turnover-row"><span>Your cheque, <?= number_format((float)$m['cheque_weeks_year'], 0) ?> weeks</span><strong><?= fd_money((float)$m['cost_stack_year']['cheque']) ?></strong></div>
                          <div class="mw-fd-turnover-row"><span>Fixed overhead<?= (float)$m['cost_stack_year']['overhead'] <= 0 ? ' (not entered)' : '' ?></span><strong><?= fd_money((float)$m['cost_stack_year']['overhead']) ?></strong></div>
                          <div class="mw-fd-turnover-row"><span><?= $m['planned_whole_crew'] ? 'Materials, fuel, other expenses' : 'Other crew, materials, fuel' ?>: <?= number_format((float)$m['variable_cost_pct'], 0) ?>% of every dollar</span><strong>variable</strong></div>
                      </div>
                  </div>
              </div>
          </div>
          <?php endif; ?>

          <div class="row">
              <!-- ── Directions ───────────────────────────────────────────── -->
              <div class="col-xl-7">
                  <div class="card mw-fd-directions">
                      <div class="card-header">
                          <h5 class="card-title mb-0">Directions</h5>
                          <span class="text-muted small">Ranked by what moves the score. Each one is sized in dollars or hours.</span>
                      </div>
                      <div class="card-body p-0">
                          <?php if (!$dirs): ?>
                              <p class="p-3 text-muted mb-0">Nothing to recommend for this period.</p>
                          <?php else: ?>
                              <ol class="mw-fd-dir-list">
                                  <?php foreach ($dirs as $i => $d): ?>
                                      <li class="mw-fd-dir mw-fd-dir-<?= h($d['rank']) ?>">
                                          <div class="mw-fd-dir-num"><?= $i + 1 ?></div>
                                          <div class="mw-fd-dir-body">
                                              <div class="mw-fd-dir-top">
                                                  <span class="mw-fd-dir-rank"><?= h($rankLabels[$d['rank']] ?? ucfirst($d['rank'])) ?></span>
                                                  <span class="mw-fd-dir-impact"><?= h($d['impact']) ?></span>
                                              </div>
                                              <h6 class="mw-fd-dir-title"><?= h($d['title']) ?></h6>
                                              <p class="mw-fd-dir-why"><?= h($d['why']) ?></p>
                                              <p class="mw-fd-dir-action"><strong>Do this:</strong> <?= h($d['action']) ?></p>
                                              <?php if (!empty($d['href'])): ?>
                                                  <a class="mw-fd-dir-link" href="<?= h($d['href']) ?>">Go <i data-feather="arrow-right" class="mw-fd-icon"></i></a>
                                              <?php endif; ?>
                                          </div>
                                      </li>
                                  <?php endforeach; ?>
                              </ol>
                          <?php endif; ?>
                      </div>
                  </div>
              </div>

              <!-- ── Where the money goes ─────────────────────────────────── -->
              <div class="col-xl-5">
                  <div class="card">
                      <div class="card-header">
                          <h5 class="card-title mb-0">Where a week's revenue goes</h5>
                          <span class="text-muted small">Average week in this period, ending at what is left for you.</span>
                      </div>
                      <div class="card-body">
                          <canvas id="mwFdWaterfall" height="260" aria-label="Revenue to owner pay waterfall"></canvas>
                      </div>
                  </div>

                  <div class="card">
                      <div class="card-header">
                          <h5 class="card-title mb-0">Revenue with you vs without you</h5>
                          <span class="text-muted small">Completed visits, by whether you were on site.</span>
                      </div>
                      <div class="card-body mw-fd-mix">
                          <div class="mw-fd-mix-chart"><canvas id="mwFdMix" width="150" height="150" aria-label="Owner-dependent revenue share"></canvas></div>
                          <div class="mw-fd-mix-figs">
                              <div class="mw-fd-mix-row"><span class="mw-fd-dot mw-fd-dot-owner"></span> With you on site <strong><?= fd_pct($m['owner_revenue_share_pct']) ?></strong> <span class="text-muted"><?= fd_money((float)$m['visit_revenue_owner']) ?></span></div>
                              <div class="mw-fd-mix-row"><span class="mw-fd-dot mw-fd-dot-crew"></span> Crew only <strong><?= $m['owner_revenue_share_pct'] === null ? '—' : fd_pct(100 - (float)$m['owner_revenue_share_pct']) ?></strong> <span class="text-muted"><?= fd_money((float)$m['visit_revenue_crew_only']) ?></span></div>
                              <hr class="my-2">
                              <div class="mw-fd-mix-row">Recurring share <strong><?= fd_pct($m['recurring_share_pct']) ?></strong> <span class="text-muted">goal 70%+</span></div>
                              <div class="mw-fd-mix-row">Biggest client <strong><?= fd_pct($m['top_client_share_pct']) ?></strong> <span class="text-muted"><?= $m['top_client_name'] ? h((string)$m['top_client_name']) : '' ?></span></div>
                              <div class="mw-fd-mix-row">Overdue <strong><?= fd_money((float)$m['overdue']) ?></strong> <span class="text-muted">of <?= fd_money((float)$m['outstanding']) ?> outstanding</span></div>
                          </div>
                      </div>
                  </div>
              </div>
          </div>

          <!-- ── Trend ────────────────────────────────────────────────────── -->
          <div class="card">
              <div class="card-header">
                  <h5 class="card-title mb-0">Twelve-month trend</h5>
                  <span class="text-muted small">Bars: share of your cheque the business could pay without you. Line: hours you actually worked per week. Freedom is bars up, line down.</span>
              </div>
              <div class="card-body">
                  <canvas id="mwFdTrend" height="280" aria-label="Freedom score and owner hours by month"></canvas>
              </div>
          </div>

          <!-- ── Tracking / data quality ───────────────────────────────────── -->
          <div class="card mw-fd-quality" id="mwFreedomQuality">
              <div class="card-header">
                  <div class="d-flex justify-content-between align-items-center flex-wrap">
                      <div>
                          <h5 class="card-title mb-0">What has to be tracked for this to be precise</h5>
                          <span class="text-muted small">The score is only as good as these. Red means the number above is a guess.</span>
                      </div>
                      <div class="mw-fd-q-summary">
                          <span class="mw-fd-q-pill mw-fd-q-ok"><?= $qCounts['ok'] ?> good</span>
                          <span class="mw-fd-q-pill mw-fd-q-warn"><?= $qCounts['warn'] ?> weak</span>
                          <span class="mw-fd-q-pill mw-fd-q-fail"><?= $qCounts['fail'] ?> missing</span>
                          <?php if ($qCounts['unknown']): ?><span class="mw-fd-q-pill mw-fd-q-unknown"><?= $qCounts['unknown'] ?> unreadable</span><?php endif; ?>
                      </div>
                  </div>
              </div>
              <div class="card-body p-0">
                  <div class="table-responsive">
                      <table class="table table-sm mb-0 mw-fd-q-table">
                          <thead><tr><th></th><th>Check</th><th>Now</th><th>Why it matters</th><th>What to do</th></tr></thead>
                          <tbody>
                          <?php foreach ($q as $item): ?>
                              <tr class="mw-fd-q-row-<?= h($item['status']) ?>">
                                  <td><span class="mw-fd-q-dot mw-fd-q-dot-<?= h($item['status']) ?>" title="<?= h($item['status']) ?>"></span></td>
                                  <td class="mw-fd-q-label"><?= h($item['label']) ?></td>
                                  <td class="mw-fd-q-detail"><?= h($item['detail']) ?></td>
                                  <td class="mw-fd-q-why"><?= h($item['why']) ?></td>
                                  <td class="mw-fd-q-fix"><?= h($item['fix']) ?><?php if ($item['href']): ?> <a href="<?= h($item['href']) ?>">Open</a><?php endif; ?></td>
                              </tr>
                          <?php endforeach; ?>
                          </tbody>
                      </table>
                  </div>
              </div>
          </div>

          <!-- ── Settings ─────────────────────────────────────────────────── -->
          <div class="card mw-fd-settings" id="mwFreedomSettings">
              <div class="card-header">
                  <h5 class="card-title mb-0">Settings</h5>
                  <span class="text-muted small">The cheque you draw, who you are in the data, and what it costs to buy back your hours.</span>
              </div>
              <div class="card-body">
                  <form method="post" action="/crm/freedom_appstack.php?period=<?= h($period) ?>">
                      <input type="hidden" name="csrf_token" value="<?= h(generateCSRFToken()) ?>">
                      <input type="hidden" name="freedom_settings" value="1">
                      <div class="form-row">
                          <div class="form-group col-md-4">
                              <label for="fdOwner">You are</label>
                              <select id="fdOwner" name="owner_user_id" class="form-control">
                                  <option value="0">— choose —</option>
                                  <?php foreach ($users as $u): ?>
                                      <option value="<?= (int)$u['id'] ?>" <?= (int)$u['id'] === (int)$s['owner_user_id'] ? 'selected' : '' ?>><?= h($u['full_name']) ?> (<?= h($u['role']) ?>)</option>
                                  <?php endforeach; ?>
                              </select>
                              <small class="form-text text-muted">Hours and visits for this user are the ones being replaced.</small>
                          </div>
                          <div class="form-group col-md-2">
                              <label for="fdHours">Paid hours / week</label>
                              <input id="fdHours" name="target_hours_week" type="number" step="0.5" min="0" class="form-control" value="<?= h((string)$s['target_hours_week']) ?>">
                          </div>
                          <div class="form-group col-md-2">
                              <label for="fdRate">Your rate $/h</label>
                              <input id="fdRate" name="owner_rate" type="number" step="0.01" min="0" class="form-control" value="<?= $s['owner_rate_explicit'] ? h((string)$s['owner_rate']) : '' ?>" placeholder="<?= h(number_format($s['owner_rate'], 2)) ?>">
                              <small class="form-text text-muted">Blank = your team pay rate.</small>
                          </div>
                          <div class="form-group col-md-4">
                              <label for="fdOverhead">Fixed overhead $/month</label>
                              <input id="fdOverhead" name="fixed_overhead_month" type="number" step="1" min="0" class="form-control" value="<?= h((string)$s['fixed_overhead_month']) ?>">
                              <small class="form-text text-muted">Insurance, phone, software, loans, leases — bills that never come in as receipts.</small>
                          </div>
                      </div>
                      <div class="form-row mw-fd-plan-row">
                          <div class="form-group col-md-3">
                              <label for="fdMode">Who replaces you</label>
                              <select id="fdMode" name="replacement_mode" class="form-control">
                                  <option value="hours" <?= $s['replacement_mode'] === 'hours' ? 'selected' : '' ?>>Buy back my logged hours at crew rates</option>
                                  <option value="planned" <?= $s['replacement_mode'] === 'planned' ? 'selected' : '' ?>>A named replacement crew</option>
                              </select>
                          </div>
                          <div class="form-group col-md-5">
                              <label for="fdHires">Replacement crew — name, $/h, hours/week</label>
                              <input id="fdHires" name="planned_hires" type="text" class="form-control" value="<?= h($s['planned_hires_raw']) ?>" placeholder="Nigel 28 40, Assistant 25 40">
                              <small class="form-text text-muted">One person per comma. Hours default to 40. Paid only in the season below.</small>
                              <div class="form-check mt-1">
                                  <input type="hidden" name="planned_whole_crew" value="0">
                                  <input class="form-check-input" type="checkbox" id="fdWholeCrew" name="planned_whole_crew" value="1" <?= $s['planned_whole_crew'] ? 'checked' : '' ?>>
                                  <label class="form-check-label small" for="fdWholeCrew">They are the whole crew (replace today's crew wages, don't add to them)</label>
                              </div>
                          </div>
                          <div class="form-group col-md-2">
                              <label for="fdSeasonStart">Season</label>
                              <div class="d-flex align-items-center mw-fd-season">
                                  <select id="fdSeasonStart" name="season_start_month" class="form-control form-control-sm">
                                      <?php for ($mo = 1; $mo <= 12; $mo++): ?><option value="<?= $mo ?>" <?= $mo === (int)$s['season_start_month'] ? 'selected' : '' ?>><?= date('M', mktime(0,0,0,$mo,1)) ?></option><?php endfor; ?>
                                  </select>
                                  <span class="mx-1">–</span>
                                  <select name="season_end_month" class="form-control form-control-sm" aria-label="Season end">
                                      <?php for ($mo = 1; $mo <= 12; $mo++): ?><option value="<?= $mo ?>" <?= $mo === (int)$s['season_end_month'] ? 'selected' : '' ?>><?= date('M', mktime(0,0,0,$mo,1)) ?></option><?php endfor; ?>
                                  </select>
                              </div>
                          </div>
                          <div class="form-group col-md-2">
                              <label for="fdChequeWeeks">Your cheque, weeks/yr</label>
                              <input id="fdChequeWeeks" name="cheque_weeks_year" type="number" step="1" min="1" max="52" class="form-control" value="<?= h((string)$s['cheque_weeks_year']) ?>">
                              <small class="form-text text-muted">52 = paid all winter too.</small>
                          </div>
                      </div>
                      <div class="form-row">
                          <div class="form-group col-md-3">
                              <label for="fdField">Field replacement $/h</label>
                              <input id="fdField" name="field_replacement_rate" type="number" step="0.01" min="0" class="form-control" value="<?= $s['field_replacement_rate'] !== null ? h((string)$s['field_replacement_rate']) : '' ?>" placeholder="crew average">
                              <small class="form-text text-muted">Blank = average crew wage. Used in "logged hours" mode.</small>
                          </div>
                          <div class="form-group col-md-3">
                              <label for="fdAdmin">Office replacement $/h</label>
                              <input id="fdAdmin" name="admin_replacement_rate" type="number" step="0.01" min="0" class="form-control" value="<?= h((string)$s['admin_replacement_rate']) ?>">
                              <small class="form-text text-muted">What a part-time admin costs.</small>
                          </div>
                          <div class="form-group col-md-3">
                              <label for="fdBurden">Payroll burden %</label>
                              <input id="fdBurden" name="burden_pct" type="number" step="0.5" min="0" class="form-control" value="<?= h((string)$s['burden_pct']) ?>">
                              <small class="form-text text-muted">CPP, EI, WorkSafeBC, vacation pay on top of wages.</small>
                          </div>
                          <div class="form-group col-md-3 d-flex align-items-end">
                              <button type="submit" class="btn btn-primary" <?= isAdmin() ? '' : 'disabled' ?>>Save &amp; recalculate</button>
                          </div>
                      </div>
                  </form>
              </div>
          </div>

          <script>
              window.MW_FREEDOM = <?= json_encode([
                  'score'   => $score,
                  'metrics' => $m,
                  'trend'   => $report['trend'],
              ], JSON_UNESCAPED_SLASHES) ?>;
          </script>
          <script src="/crm/js/freedom-dashboard.js?v=<?= (int)@filemtime(__DIR__ . '/js/freedom-dashboard.js') ?>"></script>

<?php include 'includes/appstack_footer.php'; ?>
