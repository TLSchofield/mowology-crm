<?php
// /customer/ has no page of its own: the portal is token-based (portal.php, invoice.php,
// quote.php, … reached from links in customer emails). A bare hit goes to the homepage.
// Replaces a stray Feb-2026 copy of the public homepage that fataled on a missing
// includes/bootstrap.php on every request (2026-10-16). Self-contained on purpose.
header('X-Robots-Tag: noindex');
header('Location: https://mowology.ca/', true, 302);
exit;
