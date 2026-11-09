<?php
// The CRM's old second sign-in form (2026-11-06): it signed people in without the sign-in page's checks (captcha now,
// two-step sign-in next), so it only forwards to the one sign-in page. logout_secure.php and old bookmarks still land here.
header('Cache-Control: no-store');
header('Location: /loginAuth/login.php', true, 302);
exit();
