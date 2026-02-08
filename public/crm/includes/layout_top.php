<?php
if (!function_exists('h')) {
  function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
?>



<?php
// expects: $pageTitle (string)
if (!isset($pageTitle)) $pageTitle = 'CRM';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($pageTitle) ?></title>
  <link rel="stylesheet" href="/crm/assets/crm.css">
</head>
<body>
<div class="app">
