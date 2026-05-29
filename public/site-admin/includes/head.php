<?php
/**
 * Site Admin <head> — outputs <!DOCTYPE> through </head>
 *
 * Variables expected (set before including):
 *   $adminTitle  (string) — page title suffix, e.g. "Pages"
 *   $extraHead   (string) — extra markup before </head> (optional)
 */

$adminTitle = $adminTitle ?? '';
$extraHead  = $extraHead  ?? '';
$siteName   = defined('SITE_NAME') ? SITE_NAME : 'Site Admin';
$fullTitle  = $adminTitle ? h($adminTitle) . ' — ' . h($siteName) : h($siteName) . ' Admin';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= $fullTitle ?></title>
  <meta name="robots" content="noindex, nofollow">

  <!-- Fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

  <!-- Icons (Feather — local, same as CRM) -->
  <script src="/crm/js/feather.min.js"></script>
  <script src="/crm/js/feather-helper.js"></script>

  <!-- Admin styles -->
  <link rel="stylesheet" href="/site-admin/css/admin.css">

  <?= $extraHead ?>
</head>
