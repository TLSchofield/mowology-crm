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
$extraHead = $extraHead ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
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

  <!-- Fonts (matches public website) -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&family=Open+Sans:wght@400;600&display=swap" rel="stylesheet">

  <!-- AppStack base (vendor — DO NOT MODIFY) -->
  <link href="/crm/css/classic.css" rel="stylesheet">

  <!-- Mowology brand override -->
  <link href="/crm/css/mowology-brand.css?v=20260214j" rel="stylesheet">

  <!-- Feather Icons (required for CRM UI) -->
  <script src="https://unpkg.com/feather-icons"></script>

  <?php echo $extraHead; ?>

</head>
<body>
  <div class="wrapper">
    <?php include __DIR__ . '/appstack_sidebar.php'; ?>

    <div class="main">
      <?php include __DIR__ . '/appstack_topbar.php'; ?>

      <main class="content">
        <div class="container-fluid p-0">
