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

// Dynamic logo from site record (set by bootstrap.php → cms_resolveSite)
$__siteRecord = $GLOBALS['__cms_site'] ?? [];
$logoPath = $__siteRecord['logo_path'] ?? '/assets/img/logo/mowology-logo.jpg';
$logoAlt = SITE_NAME;

// Dynamic nav: try cms_menus first, then fall back to hardcoded Mowology nav
$nav = null;
if (function_exists('cms_getSiteMenuItems') && defined('CMS_SITE_ID')) {
    $nav = cms_getSiteMenuItems('main', CMS_SITE_ID);
}
if (!$nav) {
    // Fallback: default Mowology navigation
    $nav = [
        ['key' => 'home',      'label' => 'Home',      'href' => '/'],
        ['key' => 'services',  'label' => 'Services',  'href' => '/services'],
        ['key' => 'portfolio', 'label' => 'Portfolio', 'href' => '/portfolio.php'],
        ['key' => 'about',     'label' => 'About',     'href' => '/about.php'],
        ['key' => 'contact',   'label' => 'Contact',   'href' => '/contact.php'],
    ];
}
unset($__siteRecord);
?>
<body>
<?php
// P3-G: Announcement Bar — render if enabled in cms_site_settings
try {
    if (function_exists('getDB')) {
        $__abRow = getDB()->query("SELECT setting_value FROM cms_site_settings WHERE setting_key = 'announcement_bar' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if ($__abRow) {
            $__ab = json_decode($__abRow['setting_value'], true);
            if (!empty($__ab['enabled']) && !empty($__ab['text'])) {
                $__style = match($__ab['style'] ?? 'green') {
                    'dark'   => 'background:#1A5F4A;color:#fff;',
                    'orange' => 'background:#e85d04;color:#fff;',
                    default  => 'background:#2D8659;color:#fff;',
                };
                echo '<div class="site-announcement-bar" style="' . $__style
                    . 'text-align:center;padding:8px 16px;font-size:0.9rem;line-height:1.4;">'
                    . htmlspecialchars($__ab['text'], ENT_QUOTES, 'UTF-8');
                if (!empty($__ab['link_url']) && !empty($__ab['link_text'])) {
                    echo ' <a href="' . htmlspecialchars($__ab['link_url'], ENT_QUOTES, 'UTF-8')
                        . '" style="color:inherit;font-weight:600;text-decoration:underline;margin-left:8px;">'
                        . htmlspecialchars($__ab['link_text'], ENT_QUOTES, 'UTF-8') . ' &rarr;</a>';
                }
                echo '</div>';
            }
        }
    }
} catch (\Throwable $__abEx) { /* Silently skip if table doesn't exist yet */ }
unset($__abRow, $__ab, $__style, $__abEx);
?>
  <header class="header">
    <nav class="navbar">
      <div class="container">
        <div class="nav-wrapper">
          <a href="/" class="logo">
            <img src="<?= h($logoPath) ?>" alt="<?= h($logoAlt) ?>" class="logo-icon" width="200" height="55">
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
