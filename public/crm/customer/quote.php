<?php
/**
 * Legacy customer quote page — now a redirect to /customer/quote.php (Fable review 2026-10-12).
 *
 * This copy accepted any quote whose status was not 'accepted' (declined/expired/draft too), with no
 * valid_until check and no form nonce, and nothing links to it any more. The one signing page is
 * /customer/quote.php, which enforces those rules (QuoteService::canAcceptOnline()).
 */
$token = (string)($_GET['token'] ?? '');
$target = '/customer/quote.php' . ($token !== '' ? '?token=' . rawurlencode($token) : '');
header('Location: ' . $target, true, 301);
exit;
