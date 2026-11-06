<?php
declare(strict_types=1);
// ittai.ca/terms/ and ittai.ca/privacy/ (2026-11-06) — the same text as mowology.ca/terms.php and
// /ittai-privacy.php (public/includes/legal/), in Ittai's own v1.2 look. The ittai.ca site is this folder
// (public_html/ittai.ca); its index.html is the coming-soon page and is not in the repo.
require_once dirname(__DIR__) . '/includes/legal/ittai-legal.php';

function ittaiLegalPage(string $kind): void
{
    $isTerms = $kind === 'terms';
    $title = $isTerms ? 'Terms of Service' : 'Privacy Policy';
    $meta = $isTerms ? 'Version ' . TERMS_VERSION . ' &bull; Effective ' . TERMS_EFFECTIVE
                     : 'Effective ' . ITTAI_PRIVACY_EFFECTIVE . ' &bull; ' . ITTAI_LEGAL_ENTITY . ' (Vancouver, BC)';
    $ittaiPrivacyHref = '/privacy/';
    header('Content-Type: text/html; charset=utf-8');
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $title ?> | Ittai</title>
<link rel="stylesheet" href="/legal.css">
</head>
<body>
<header class="it-top">
  <a class="it-brand" href="/" aria-label="Ittai home">
  <svg class="symbol" width="48" height="48" viewBox="0 0 64 64" role="img" aria-label="Ittai symbol">
    <g fill="none" stroke="currentColor" stroke-width="3.5" stroke-linecap="round" stroke-dasharray="75.34 24.66" stroke-dashoffset="-12.33">
      <path pathLength="100" d="M18.96 9.42 A6 6 0 0 1 21.96 8.62 L42.04 8.62 A6 6 0 0 1 45.04 9.42"/>
      <path pathLength="100" d="M45.04 9.42 A6 6 0 0 1 47.23 11.62 L57.27 29 A6 6 0 0 1 58.07 32"/>
      <path pathLength="100" d="M58.07 32 A6 6 0 0 1 57.27 35 L47.23 52.38 A6 6 0 0 1 45.04 54.58"/>
      <path pathLength="100" d="M45.04 54.58 A6 6 0 0 1 42.04 55.38 L21.96 55.38 A6 6 0 0 1 18.96 54.58"/>
      <path pathLength="100" d="M18.96 54.58 A6 6 0 0 1 16.77 52.38 L6.73 35 A6 6 0 0 1 5.93 32"/>
      <path pathLength="100" d="M5.93 32 A6 6 0 0 1 6.73 29 L16.77 11.62 A6 6 0 0 1 18.96 9.42"/>
    </g>
    <g stroke="currentColor" stroke-width="1.3">
      <line x1="32" y1="20" x2="32" y2="14"/><line x1="42.39" y1="26" x2="47.59" y2="23"/><line x1="42.39" y1="38" x2="47.59" y2="41"/>
      <line x1="32" y1="44" x2="32" y2="50"/><line x1="21.61" y1="38" x2="16.41" y2="41"/><line x1="21.61" y1="26" x2="16.41" y2="23"/>
    </g>
    <circle cx="32" cy="32" r="9" fill="none" stroke="currentColor" stroke-width="2.5"/>
  </svg>
    <span>Ittai</span>
  </a>
  <nav><a href="/terms/">Terms</a> <a href="/privacy/">Privacy</a></nav>
</header>
<div class="terms-hero">
  <h1><?= $title ?></h1>
  <p class="terms-updated"><?= $meta ?></p>
</div>
<div class="terms-layout">
<?php require dirname(__DIR__) . '/includes/legal/ittai-' . ($isTerms ? 'terms' : 'privacy') . '-body.php'; ?>
</div>
<footer class="it-foot">&copy; <?= date('Y') ?> <?= ITTAI_LEGAL_ENTITY ?></footer>
</body>
</html>
<?php
}
