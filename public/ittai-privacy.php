<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

// Ittai's own privacy policy (the app), separate from privacy.php (Mowology's landscaping clients).
// Linked from the Intuit app listing as the privacy policy URL (2026-11-06). Not reviewed by a lawyer.
// The services named in section 6 are the ones the code calls — re-check them when one is added or dropped.
require_once __DIR__ . '/includes/legal/ittai-legal.php';   // effective date, legal entity

$pageTitle       = 'Privacy Policy | Ittai';
$pageDescription = 'How Ittai collects, uses and protects business and personal information, including data from connected QuickBooks companies.';
$activeNav       = '';
// Same page styles as terms.php (master.css differs on production, so not via the bundle).
$extraHead       = '<link rel="stylesheet" href="/assets/css/pages/terms.css">';
?>
<?php require __DIR__ . '/includes/head.php'; ?>
<?php require __DIR__ . '/includes/header.php'; ?>

<div class="terms-hero">
    <div class="container">
        <h1>Ittai Privacy Policy</h1>
        <p class="terms-updated">Effective <?= ITTAI_PRIVACY_EFFECTIVE ?> &bull; <?= ITTAI_LEGAL_ENTITY ?> (Vancouver, BC)</p>
    </div>
</div>

<div class="container">
<div class="terms-layout">

    <?php require __DIR__ . '/includes/legal/ittai-privacy-body.php'; ?>
</div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
