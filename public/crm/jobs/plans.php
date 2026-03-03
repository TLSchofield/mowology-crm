<?php
/**
 * Redirect: /crm/jobs/plans.php → /crm/jobs/index.php
 * The plans list lives at index.php; this alias keeps old links working.
 */
header('Location: /crm/jobs/index.php', true, 301);
exit;
