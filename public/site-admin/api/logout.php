<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/auth.php';

siteLogout();
header('Location: /site-admin/login.php');
exit;
