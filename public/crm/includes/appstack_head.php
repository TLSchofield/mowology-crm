<?php
/**
 * Shared AppStack <head> — Single Source of Truth
 * ────────────────────────────────────────────────
 * Every CRM page includes this file first.
 * It outputs: DOCTYPE, <html>, <head>…</head>, <body>, wrapper div, and sidebar.
 *
 * Expected variables (set by each page before including):
 *   $pageTitle   — string shown in <title>  (defaults to '<business name> CRM')
 *   $activePage  — string matching sidebar key to highlight active nav item
 *   $user        — array from getCurrentUser() (optional, defaults gracefully)
 *   $extraHead   — extra markup injected before </head> (optional)
 *
 * Loads:
 *   1. AppStack classic.css  (vendor — never modify)
 *   2. mowology-brand.css    (brand override layer)
 *   3. Google Fonts (Montserrat + Open Sans — matches public site)
 *   4. Favicon set from /assets/favicon/
 */

// ── ERROR MODE (Fable review 2026-10-12) ─────────────────────────────────────
// Errors are LOGGED, never printed to staff. The full trace is shown only on a debug box
// (APP_DEBUG, never production) to an admin; everyone else gets a plain "something went wrong".
$__mwShowErrors = defined('APP_DEBUG') && APP_DEBUG && (($_SESSION['user_role'] ?? '') === 'admin');
ini_set('display_errors', $__mwShowErrors ? '1' : '0');
ini_set('display_startup_errors', $__mwShowErrors ? '1' : '0');
error_reporting(E_ALL);

// Capture uncaught exceptions: log them, then show a top-of-page banner instead of a blank screen
set_exception_handler(function (Throwable $e) use ($__mwShowErrors) {
    error_log('CRM uncaught ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine()
        . ' (' . ($_SERVER['REQUEST_URI'] ?? '') . ")\n" . $e->getTraceAsString());
    // Flush any partial output
    if (ob_get_level()) ob_end_clean();
    if (!headers_sent()) http_response_code(500);
    $detail = $__mwShowErrors
        ? '<h2>' . htmlspecialchars(get_class($e)) . ': ' . htmlspecialchars($e->getMessage()) . '</h2>'
          . '<pre>' . htmlspecialchars($e->getTraceAsString()) . '</pre>'
          . '<p class="mw-err-meta">in <strong>' . htmlspecialchars($e->getFile()) . '</strong> on line <strong>' . $e->getLine() . '</strong></p>'
        : '<h2>Something went wrong on this page.</h2>'
          . '<p class="mw-err-meta">The error has been logged. Please go back and try again, or tell the office if it keeps happening.</p>';
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<title>Error — CRM</title>'
       . '<style>body{font-family:system-ui,sans-serif;margin:0;background:#fff1f2;}'
       . '.mw-err{background:#fee2e2;border-bottom:3px solid #dc2626;padding:20px 28px;}'
       . '.mw-err h2{margin:0 0 8px;color:#991b1b;font-size:1.1rem;}'
       . '.mw-err pre{background:#fff;border:1px solid #fca5a5;border-radius:6px;padding:14px;'
       . 'font-size:0.78rem;overflow-x:auto;color:#1f2937;line-height:1.5;margin:8px 0 0;}'
       . '.mw-err-meta{font-size:0.8rem;color:#7f1d1d;margin-top:6px;}'
       . '</style></head><body>'
       . '<div class="mw-err">' . $detail . '</div></body></html>';
    exit(1);
});
// ── END ERROR MODE ────────────────────────────────────────────────────────────

if (defined('APP_ROOT')) require_once APP_ROOT . '/Services/Messaging/IdentityMail.php';
$__mwBizName = function_exists('_idFromName') ? _idFromName() : 'CRM';
if (!isset($pageTitle)) $pageTitle = $__mwBizName . ' CRM';
$extraHead  = $extraHead  ?? '';
$bodyClass  = $bodyClass  ?? '';
// Accounts pages share the ledger look (body.mw-books in mowology-brand.css; not .mw-acct — the crew Account tab owns that).
if (($activePage ?? '') === 'accounting') $bodyClass = trim($bodyClass . ' mw-books');
// Crew-lite: the Android app (ca.mowology.crew) sets the mw_native cookie on launch
// (app-launch.php / my-schedule.php). Inside the app there is no sidebar, no desktop
// chrome and no iOS splash — skip the markup, fonts and scripts that only the office
// uses. The topbar is kept (hidden by CSS) because the shared clock widget lives in it.
$crewLite   = !empty($_COOKIE['mw_native']) || !empty($crewLite);
// Crew-slim: a CREW page ($crewPage = true before this include: schedule, expenses, timesheet,
// account, search, time clock, vehicle log, work record) inside the app swaps the vendor set
// (classic.css 440 KB + mowology-brand.css 1 MB + app.js 3.5 MB) for the generated crew set
// (crew-base.css + crew-brand.css, scripts/build-crew-css.py) and mw-ui-shim.js. Office pages
// opened on the tablet keep the full bundle. Browsers outside the app never see this.
// Ledger-only: the outside accountant's login (AccountantFence) gets a Ledger sidebar and none of the
// crew chrome — no clock-in, location dot, receipt camera, search or bottom bar (2026-10-13).
$mwLedgerOnly = class_exists('AccountantFence') && AccountantFence::activeForSession();
$crewPage   = !empty($crewPage);
$crewSlim   = $crewLite && $crewPage;

// Idle timeout (Fable audit 2026-10-12): an office login (admin/manager) on an office page in a
// browser signs out after 30 minutes without a page load. Crew pages, the crew app (mw_native) and
// crew logins are exempt — a phone in a truck must not sign itself out mid-shift.
if (!$crewPage && !$crewLite && function_exists('checkSessionTimeout') && class_exists('RoleRules')
    && session_status() === PHP_SESSION_ACTIVE && RoleRules::hasIdleTimeout((string)($_SESSION['user_role'] ?? '')) && !headers_sent()) {
    checkSessionTimeout(1800);
}

/**
 * Return a URL with an automatic cache-busting query string derived from
 * the file's last-modified time.  No manual version bumping ever needed.
 * Falls back to current timestamp on first deploy before the file exists.
 *
 * Usage:  _av('/crm/css/mobile-nav.css')
 *         → '/crm/css/mobile-nav.css?v=1742400123'
 */
function _av(string $webPath): string {
    $disk = PUBLIC_ROOT . $webPath;
    $ts   = @filemtime($disk) ?: time();
    return $webPath . '?v=' . $ts;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no, viewport-fit=cover">
  <meta name="description" content="<?php echo htmlspecialchars($__mwBizName); ?> CRM - Client Management System">

  <!-- PWA / Mobile App -->
  <meta name="theme-color" content="#0D3B2E">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <meta name="apple-mobile-web-app-title" content="<?php echo htmlspecialchars($__mwBizName); ?>">
  <meta name="mobile-web-app-capable" content="yes">

  <title><?php echo htmlspecialchars($pageTitle); ?> - <?php echo htmlspecialchars($__mwBizName); ?> CRM</title>
  <script>window.MW_BUSINESS = <?php echo json_encode(function_exists('_idBrand') ? ['name' => html_entity_decode(_idBrand()['name'], ENT_QUOTES, 'UTF-8'), 'full' => html_entity_decode(_idBrand()['full'], ENT_QUOTES, 'UTF-8'), 'site' => html_entity_decode(_idBrand()['siteLabel'], ENT_QUOTES, 'UTF-8')] : ['name' => '', 'full' => '', 'site' => ''], JSON_HEX_TAG | JSON_HEX_AMP); ?>;</script>

  <!-- Favicon + PWA Icons -->
  <link rel="apple-touch-icon" sizes="180x180" href="/assets/favicon/apple-touch-icon.png">
  <link rel="icon" type="image/png" sizes="32x32" href="/assets/favicon/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="/assets/favicon/favicon-16x16.png">
  <link rel="manifest" href="/assets/favicon/site.webmanifest">

  <?php if (!$crewLite): ?>
  <!-- iOS Splash Screens (generated via /crm/pwa-splash.php) -->
  <!-- iPhone 15 Pro Max, 16 Plus (430x932) -->
  <link rel="apple-touch-startup-image" href="/crm/pwa-splash.php?w=1290&h=2796" media="(device-width: 430px) and (device-height: 932px) and (-webkit-device-pixel-ratio: 3)">
  <!-- iPhone 15 Pro, 15, 14 Pro (393x852) -->
  <link rel="apple-touch-startup-image" href="/crm/pwa-splash.php?w=1179&h=2556" media="(device-width: 393px) and (device-height: 852px) and (-webkit-device-pixel-ratio: 3)">
  <!-- iPhone 14 Plus, 13 Pro Max (428x926) -->
  <link rel="apple-touch-startup-image" href="/crm/pwa-splash.php?w=1284&h=2778" media="(device-width: 428px) and (device-height: 926px) and (-webkit-device-pixel-ratio: 3)">
  <!-- iPhone 14, 13, 13 Pro, 12, 12 Pro (390x844) -->
  <link rel="apple-touch-startup-image" href="/crm/pwa-splash.php?w=1170&h=2532" media="(device-width: 390px) and (device-height: 844px) and (-webkit-device-pixel-ratio: 3)">
  <!-- iPhone 13 mini, 12 mini (375x812) -->
  <link rel="apple-touch-startup-image" href="/crm/pwa-splash.php?w=1125&h=2436" media="(device-width: 375px) and (device-height: 812px) and (-webkit-device-pixel-ratio: 3)">
  <!-- iPhone 11 Pro Max, XS Max (414x896 @3x) -->
  <link rel="apple-touch-startup-image" href="/crm/pwa-splash.php?w=1242&h=2688" media="(device-width: 414px) and (device-height: 896px) and (-webkit-device-pixel-ratio: 3)">
  <!-- iPhone 11, XR (414x896 @2x) -->
  <link rel="apple-touch-startup-image" href="/crm/pwa-splash.php?w=828&h=1792" media="(device-width: 414px) and (device-height: 896px) and (-webkit-device-pixel-ratio: 2)">
  <!-- iPhone X, XS, 11 Pro (375x812) -->
  <link rel="apple-touch-startup-image" href="/crm/pwa-splash.php?w=1125&h=2436" media="(device-width: 375px) and (device-height: 812px) and (-webkit-device-pixel-ratio: 3)">
  <!-- iPhone 8 Plus (414x736) -->
  <link rel="apple-touch-startup-image" href="/crm/pwa-splash.php?w=1242&h=2208" media="(device-width: 414px) and (device-height: 736px) and (-webkit-device-pixel-ratio: 3)">
  <!-- iPhone SE, 8 (375x667) -->
  <link rel="apple-touch-startup-image" href="/crm/pwa-splash.php?w=750&h=1334" media="(device-width: 375px) and (device-height: 667px) and (-webkit-device-pixel-ratio: 2)">
  <!-- iPad Pro 12.9" (1024x1366) -->
  <link rel="apple-touch-startup-image" href="/crm/pwa-splash.php?w=2048&h=2732" media="(device-width: 1024px) and (device-height: 1366px) and (-webkit-device-pixel-ratio: 2)">
  <!-- iPad Pro 11" (834x1194) -->
  <link rel="apple-touch-startup-image" href="/crm/pwa-splash.php?w=1668&h=2388" media="(device-width: 834px) and (device-height: 1194px) and (-webkit-device-pixel-ratio: 2)">
  <!-- iPad Air, iPad 10.2" (820x1180 / 810x1080) -->
  <link rel="apple-touch-startup-image" href="/crm/pwa-splash.php?w=1640&h=2360" media="(device-width: 820px) and (device-height: 1180px) and (-webkit-device-pixel-ratio: 2)">
  <!-- iPad Mini (768x1024) -->
  <link rel="apple-touch-startup-image" href="/crm/pwa-splash.php?w=1536&h=2048" media="(device-width: 768px) and (device-height: 1024px) and (-webkit-device-pixel-ratio: 2)">

  <?php endif; ?>

  <!-- Fonts (matches public website) -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <?php if ($crewLite): ?>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <?php else: ?>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Montserrat:wght@400;500;600;700&family=Open+Sans:wght@400;600&display=swap" rel="stylesheet">
  <?php endif; ?>

  <!-- AppStack base (vendor — DO NOT MODIFY) -->
  <?php if ($crewSlim): ?>
  <link href="<?= _av('/crm/css/crew-base.css') ?>" rel="stylesheet">
  <?php else: ?>
  <link href="/crm/css/classic.css" rel="stylesheet">
  <?php endif; ?>

  <!-- Design tokens — single source of truth for colour, type, space,
       radius, shadow, motion. Loaded before brand.css so brand rules
       can use the tokens. -->
  <link href="<?= _av('/crm/css/tokens.css') ?>" rel="stylesheet">

  <!-- Mowology brand override -->
  <?php if ($crewSlim): ?>
  <link href="<?= _av('/crm/css/crew-brand.css') ?>" rel="stylesheet">
  <?php else: ?>
  <link href="<?= _av('/crm/css/mowology-brand.css') ?>" rel="stylesheet">
  <?php endif; ?>

  <!-- Global mobile navigation bars (top bar + bottom bar + slide-up menu) -->
  <link href="<?= _av('/crm/css/mobile-nav.css') ?>" rel="stylesheet">

  <!-- "Booked another day — doing it now?" sheet (mw-pull-forward.js, loaded in the footer) -->
  <link href="<?= _av('/crm/css/mw-pull-forward.css') ?>" rel="stylesheet">

  <!-- Feather Icons (self-hosted — no CDN dependency) -->
  <script src="<?= _av('/crm/js/feather.min.js') ?>"></script>

  <!-- Global Spotlight Search (office only — the app has its own Search tab) -->
  <?php if (!$crewLite && !$mwLedgerOnly): ?><script src="<?= _av('/crm/js/global-search.js') ?>" defer></script><?php endif; ?>

  <!-- Offline Action Queue: intercepts time-clock / pow-actions / job-timer
       fetch() calls when offline and queues them in IndexedDB for replay.
       Loads on every CRM page so queued actions sync on any page load. -->
  <script src="<?= _av('/crm/js/offline-queue.js') ?>" defer></script>

  <script>window.MW_USER_ROLE = '<?= htmlspecialchars($user['role'] ?? 'crew', ENT_QUOTES, 'UTF-8') ?>';</script>

  <!-- Global CSRF token for JSON-body fetch() calls (form POSTs already carry their own
       hidden csrf_token field via generateCSRFToken() in each form). -->
  <script>window.MW_USER_ID = <?= (int)($user['id'] ?? 0) ?>;</script>
  <script>window.MW_CSRF_TOKEN = '<?= htmlspecialchars(function_exists('generateCSRFToken') ? generateCSRFToken() : '', ENT_QUOTES, 'UTF-8') ?>';</script>

  <?php
  // Ittai design frame (owner, 2026-10-19): the "Try the new design" choice (it_design cookie, set on the
  // dashboard or the account menu) restyles the sidebar and top bar on every office page and groups the
  // menu by department head. Never in the crew app or the accountant's Ledger-only view. Off = today's CRM.
  $itShell = !$crewLite && empty($mwLedgerOnly) && (($_COOKIE['it_design'] ?? '') === '1');
  if ($itShell): ?>
  <link href="https://fonts.googleapis.com/css2?family=Zen+Kaku+Gothic+New:wght@500&amp;display=swap" rel="stylesheet">
  <link href="<?= _av('/crm/css/ittai-tokens.css') ?>" rel="stylesheet">
  <link href="<?= _av('/crm/css/ittai-shell.css') ?>" rel="stylesheet">
  <script src="<?= _av('/crm/js/ittai-shell.js') ?>" defer></script>
  <?php endif; ?>

  <?php echo $extraHead; ?>

  <!-- Service Worker registration — see /crm/js/sw-register.js.
       Registered from appstack_footer.php. The legacy /crm/sw.js
       registration (scope /crm/) was retired in April 2026 and is
       cleaned up by the new register script. -->

</head>
<body<?php $__bc = trim($bodyClass . ($crewLite ? ' mw-crew-lite' : '') . ($itShell ? ' it-shell' : '')); echo $__bc ? ' class="' . htmlspecialchars($__bc) . '"' : ''; ?>>
  <?php if (!$mwLedgerOnly) include __DIR__ . '/mobile-nav.php'; ?>
  <div class="wrapper">
    <?php if (!$crewLite) include __DIR__ . '/appstack_sidebar.php'; ?>

    <div class="main">
      <?php include __DIR__ . '/appstack_topbar.php'; ?>

      <main class="content">
        <div class="container-fluid p-0">
