<?php
/**
 * Public Site <head> — Single Source of Truth
 * ────────────────────────────────────────────
 * Every public page includes this file inside <head>.
 * It outputs: DOCTYPE, <html>, <head>, and closes </head>.
 *
 * Required: bootstrap.php must be loaded BEFORE this file.
 *
 * Expected variables (set by each page before including):
 *   $pageTitle       (string)       — shown in <title> and og:title
 *   $pageDescription (string)       — meta description and og:description
 *   $pageKeywords    (string|null)  — meta keywords (optional)
 *   $pageImage       (string|null)  — og:image path, web-root relative (optional)
 *   $extraHead       (string|null)  — extra markup injected before </head> (optional)
 *
 * All variables gracefully default if unset.
 */

$pageTitle       = $pageTitle       ?? SITE_NAME;
$pageDescription = $pageDescription ?? '';
$pageKeywords    = $pageKeywords    ?? '';
$pageImage       = $pageImage       ?? '/assets/img/hero/hero-lawn-care-1920x1080.jpg';
$extraHead       = $extraHead       ?? '';

// Build canonical URL from request
$canonicalPath = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
$canonicalUrl  = SITE_URL . $canonicalPath;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">

  <title><?= h($pageTitle) ?></title>

  <?php if (trim($pageDescription) !== ''): ?>
  <meta name="description" content="<?= h($pageDescription) ?>">
  <?php endif; ?>

  <?php if (trim($pageKeywords) !== ''): ?>
  <meta name="keywords" content="<?= h($pageKeywords) ?>">
  <?php endif; ?>

  <link rel="canonical" href="<?= h($canonicalUrl) ?>">

  <!-- OpenGraph -->
  <meta property="og:type" content="website">
  <meta property="og:site_name" content="<?= h(SITE_NAME) ?>">
  <meta property="og:title" content="<?= h($pageTitle) ?>">
  <meta property="og:url" content="<?= h($canonicalUrl) ?>">
  <meta property="og:locale" content="<?= h(SITE_LOCALE) ?>">
  <?php if (trim($pageDescription) !== ''): ?>
  <meta property="og:description" content="<?= h($pageDescription) ?>">
  <?php endif; ?>
  <?php if (trim($pageImage) !== ''): ?>
  <meta property="og:image" content="<?= h(SITE_URL . $pageImage) ?>">
  <?php endif; ?>

  <!-- Twitter Card -->
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="<?= h($pageTitle) ?>">
  <?php if (trim($pageDescription) !== ''): ?>
  <meta name="twitter:description" content="<?= h($pageDescription) ?>">
  <?php endif; ?>
  <?php if (trim($pageImage) !== ''): ?>
  <meta name="twitter:image" content="<?= h(SITE_URL . $pageImage) ?>">
  <?php endif; ?>

  <!-- Favicon -->
  <link rel="apple-touch-icon" sizes="180x180" href="/assets/favicon/apple-touch-icon.png">
  <link rel="icon" type="image/png" sizes="32x32" href="/assets/favicon/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="/assets/favicon/favicon-16x16.png">
  <link rel="manifest" href="/assets/favicon/site.webmanifest">

  <!-- Fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,600;0,700;1,600&family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,500;0,9..40,600;1,9..40,400&display=swap" rel="stylesheet">

  <!-- Styles -->
  <link rel="stylesheet" href="/assets/css/master.css">

  <?= $extraHead ?>

  <!-- Analytics placeholder: paste GA / GTM snippet here when ready -->

</head>
