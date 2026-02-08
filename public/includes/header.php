<?php
/**
 * Public Site Header + Navigation — Single Source of Truth
 * ────────────────────────────────────────────────────────
 * Opens <body> and renders the site navbar.
 *
 * Required: bootstrap.php must be loaded BEFORE this file.
 *
 * Expected variable:
 *   $activeNav (string) — one of: home, services, portfolio, about, contact
 */

$activeNav = $activeNav ?? '';

$nav = [
  ['key' => 'home',      'label' => 'Home',      'href' => '/'],
  ['key' => 'services',  'label' => 'Services',  'href' => '/services.php'],
  ['key' => 'portfolio', 'label' => 'Portfolio', 'href' => '/portfolio.php'],
  ['key' => 'about',     'label' => 'About',     'href' => '/about.php'],
  ['key' => 'contact',   'label' => 'Contact',   'href' => '/contact.php'],
];
?>
<body>
  <header class="header">
    <nav class="navbar">
      <div class="container">
        <div class="nav-wrapper">
          <a href="/" class="logo">
            <img src="/assets/img/logo/mowology-logo.jpg" alt="<?= h(SITE_NAME) ?>" class="logo-icon">
            <div class="logo-text-group">
              <span class="logo-text"><?= h(SITE_NAME) ?></span>
              <span class="logo-tagline"><?= h(SITE_TAGLINE) ?></span>
            </div>
          </a>

          <button class="mobile-menu-toggle" aria-label="Toggle menu">
            <span></span>
            <span></span>
            <span></span>
          </button>

          <ul class="nav-menu">
            <?php foreach ($nav as $item): ?>
              <?php $isActive = ($activeNav === $item['key']); ?>
              <li>
                <a href="<?= h($item['href']) ?>"<?= $isActive ? ' class="active"' : '' ?>>
                  <?= h($item['label']) ?>
                </a>
              </li>
            <?php endforeach; ?>

            <li>
              <a href="tel:<?= h(SITE_PHONE_TEL) ?>" class="nav-phone">📞 <?= h(SITE_PHONE_DISPLAY) ?></a>
            </li>
          </ul>
        </div>
      </div>
    </nav>
  </header>
