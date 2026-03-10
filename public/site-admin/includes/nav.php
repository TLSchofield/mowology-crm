<?php
/**
 * Site Admin Sidebar + Topbar
 *
 * Outputs <body>, sidebar nav, and opens the main content area.
 * Closed by foot.php.
 *
 * Variables expected:
 *   $activePage  (string) — highlights current nav item
 *   $siteUser    (array)  — current logged-in site user
 */

$activePage = $activePage ?? '';
$siteUser   = $siteUser   ?? getSiteUser();
$siteName   = defined('SITE_NAME') ? SITE_NAME : 'My Site';
$siteRecord = $GLOBALS['__cms_site'] ?? [];
$logoPath   = $siteRecord['logo_path'] ?? '';

$nav = [
    ['key' => 'dashboard', 'label' => 'Dashboard',  'href' => '/site-admin/',          'icon' => 'grid'],
    ['key' => 'pages',     'label' => 'Pages',       'href' => '/site-admin/pages.php', 'icon' => 'file-text'],
    ['key' => 'media',     'label' => 'Media',       'href' => '/site-admin/media.php', 'icon' => 'image'],
    ['key' => 'menus',     'label' => 'Navigation',  'href' => '/site-admin/menus.php', 'icon' => 'menu'],
    ['key' => 'theme',     'label' => 'Theme',       'href' => '/site-admin/theme.php', 'icon' => 'sliders'],
    ['key' => 'seo',       'label' => 'SEO',         'href' => '/site-admin/seo.php',   'icon' => 'trending-up'],
    ['key' => 'settings',  'label' => 'Settings',    'href' => '/site-admin/settings.php', 'icon' => 'settings'],
];
?>
<body class="sa-body">

<div class="sa-layout">

  <!-- Sidebar -->
  <aside class="sa-sidebar">
    <div class="sa-sidebar-brand">
      <?php if ($logoPath): ?>
        <img src="<?= h($logoPath) ?>" alt="<?= h($siteName) ?>" class="sa-brand-logo">
      <?php endif; ?>
      <span class="sa-brand-name"><?= h($siteName) ?></span>
    </div>

    <nav class="sa-nav">
      <?php foreach ($nav as $item): ?>
        <?php $isActive = ($activePage === $item['key']); ?>
        <a href="<?= h($item['href']) ?>" class="sa-nav-item<?= $isActive ? ' active' : '' ?>">
          <i data-feather="<?= h($item['icon']) ?>"></i>
          <span><?= h($item['label']) ?></span>
        </a>
      <?php endforeach; ?>
    </nav>

    <div class="sa-sidebar-footer">
      <a href="/site-admin/api/logout.php" class="sa-nav-item sa-logout">
        <i data-feather="log-out"></i>
        <span>Log out</span>
      </a>
    </div>
  </aside>

  <!-- Main content area -->
  <div class="sa-main">

    <!-- Topbar -->
    <header class="sa-topbar">
      <button class="sa-menu-toggle" aria-label="Toggle sidebar">
        <i data-feather="menu"></i>
      </button>
      <div class="sa-topbar-title"><?= h($adminTitle ?? '') ?></div>
      <div class="sa-topbar-user">
        <?php if ($siteUser): ?>
          <span><?= h(trim(($siteUser['first_name'] ?? '') . ' ' . ($siteUser['last_name'] ?? ''))) ?: h($siteUser['email'] ?? '') ?></span>
        <?php endif; ?>
        <a href="<?= h(defined('SITE_URL') ? SITE_URL : '/') ?>" target="_blank" class="sa-view-site" title="View site">
          <i data-feather="external-link"></i>
        </a>
      </div>
    </header>

    <!-- Page content injected here -->
    <main class="sa-content">
