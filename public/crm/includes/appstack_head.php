<?php
/**
 * Shared AppStack <head> — Single Source of Truth
 * ────────────────────────────────────────────────
 * Every CRM page includes this file first.
 * It outputs: DOCTYPE, <html>, <head>…</head>, <body>, wrapper div, and sidebar.
 *
 * Expected variables (set by each page before including):
 *   $pageTitle   — string shown in <title>  (defaults to 'Mowology CRM')
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
if (!isset($pageTitle)) $pageTitle = 'Mowology CRM';
$extraHead  = $extraHead  ?? '';
$bodyClass  = $bodyClass  ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no, viewport-fit=cover">
  <meta name="description" content="Mowology CRM - Client Management System">

  <!-- PWA / Mobile App -->
  <meta name="theme-color" content="#2D8659">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <meta name="apple-mobile-web-app-title" content="Mowology">
  <meta name="mobile-web-app-capable" content="yes">

  <title><?php echo htmlspecialchars($pageTitle); ?> - Mowology CRM</title>

  <!-- Favicon + PWA Icons -->
  <link rel="apple-touch-icon" sizes="180x180" href="/assets/favicon/apple-touch-icon.png">
  <link rel="icon" type="image/png" sizes="32x32" href="/assets/favicon/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="/assets/favicon/favicon-16x16.png">
  <link rel="manifest" href="/assets/favicon/site.webmanifest">

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

  <!-- Fonts (matches public website) -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&family=Open+Sans:wght@400;600&display=swap" rel="stylesheet">

  <!-- AppStack base (vendor — DO NOT MODIFY) -->
  <link href="/crm/css/classic.css" rel="stylesheet">

  <!-- Mowology brand override -->
  <link href="/crm/css/mowology-brand.css?v=20260520a" rel="stylesheet">

  <!-- Global mobile navigation bars (top bar + bottom bar + slide-up menu) -->
  <link href="/crm/css/mobile-nav.css?v=20260303d" rel="stylesheet">

  <!-- JWT auth manager: offline login bypass + Bearer token injection.
       Must load synchronously (no defer) so the offline redirect fires
       before the page renders. -->
  <script src="/crm/js/mw-auth.js?v=20260519a"></script>
  <script src="/crm/js/mw-offline-db.js?v=20260519b"></script>
  <script src="/crm/js/mw-quiz-offline.js?v=20260519a"></script>

  <!-- Global Spotlight Search -->
  <script src="/crm/js/global-search.js?v=1" defer></script>

  <!-- Offline Action Queue: intercepts time-clock / pow-actions / job-timer
       fetch() calls when offline and queues them in IndexedDB for replay.
       Loads on every CRM page so queued actions sync on any page load. -->
  <script src="/crm/js/offline-queue.js?v=20260303d" defer></script>

  <script>window.MW_USER_ROLE = '<?= htmlspecialchars($user['role'] ?? 'crew', ENT_QUOTES, 'UTF-8') ?>';</script>

  <?php echo $extraHead; ?>

  <!-- Service Worker: caches CSS/JS so static assets load instantly after first visit -->
  <script>
  if ('serviceWorker' in navigator) {
      navigator.serviceWorker.register('/crm/sw.js', { scope: '/crm/' })
          .catch(function () {}); // silent fail — app works fine without SW
  }
  </script>

</head>
<body<?php echo $bodyClass ? ' class="' . htmlspecialchars($bodyClass) . '"' : ''; ?>>
  <?php include __DIR__ . '/mobile-nav.php'; ?>
  <div class="wrapper">
    <?php include __DIR__ . '/appstack_sidebar.php'; ?>

    <div class="main">
      <?php include __DIR__ . '/appstack_topbar.php'; ?>

      <main class="content">
        <div class="container-fluid p-0">
