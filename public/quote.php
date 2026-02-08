<?php
/**
 * /quote → jobFlow-getQuote.php redirect
 * ───────────────────────────────────────
 * Short URL for all CTA buttons and campaign links.
 * Passes all query params through (service, property_type, src, promo, utm_*).
 *
 * Examples:
 *   /quote                                    → /jobFlow/jobFlow-getQuote.php
 *   /quote?service=hedge_trimming             → /jobFlow/jobFlow-getQuote.php?service=hedge_trimming
 *   /quote?service=maintenance&promo=spring25 → /jobFlow/jobFlow-getQuote.php?service=maintenance&promo=spring25
 */
$qs = $_SERVER['QUERY_STRING'] ?? '';
$target = '/jobFlow/jobFlow-getQuote.php';
if ($qs !== '') {
    $target .= '?' . $qs;
}
header('Location: ' . $target, true, 302);
exit();
