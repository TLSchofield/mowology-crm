<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

require_once __DIR__ . '/includes/legal/ittai-legal.php';   // version, effective date, legal entity

$pageTitle       = 'Terms of Service | Ittai';
$pageDescription = 'The terms that apply when your business uses Ittai.';
$activeNav       = '';
// Linked directly rather than via master.css: master.css differs on production (diff live
// before ever redeploying it), so this page does not depend on that bundle.
$extraHead       = '<link rel="stylesheet" href="/assets/css/pages/terms.css">';
?>
<?php require __DIR__ . '/includes/head.php'; ?>
<?php require __DIR__ . '/includes/header.php'; ?>

<div class="terms-hero">
    <div class="container">
        <h1>Terms of Service</h1>
        <p class="terms-updated">Version <?= htmlspecialchars(TERMS_VERSION, ENT_QUOTES) ?> &bull; Effective <?= htmlspecialchars(TERMS_EFFECTIVE, ENT_QUOTES) ?></p>
    </div>
</div>

<div class="container">
<div class="terms-layout">

    <?php require __DIR__ . '/includes/legal/ittai-terms-body.php'; ?>
</div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
