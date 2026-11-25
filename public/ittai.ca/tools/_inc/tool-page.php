<?php
declare(strict_types=1);

define('ITTAI_DIRECTORY_MODE', true);
if (!defined('APP_ROOT')) {
    for ($directory = __DIR__, $index = 0; $index < 7; $index++, $directory = dirname($directory)) {
        if (is_file($directory . '/app/Core/paths.php')) {
            require_once $directory . '/app/Core/paths.php';
            break;
        }
    }
}
require_once APP_ROOT . '/Core/config.php';
require_once APP_ROOT . '/Modules/Tools/Services/SavedNumbersService.php';
require_once __DIR__ . '/layout.php';

$emailOn = defined('ITTAI_MAIL_FROM') && (string) ITTAI_MAIL_FROM !== '';
toolsStart(
    $cfg['title'],
    $cfg['description'],
    'https://ittai.ca/tools/' . $cfg['slug'] . '/',
    $cfg['heading']
);
?>
<article data-tool="<?= h($cfg['slug']) ?>">
    <div class="intro-card">
        <header class="hero">
            <a href="/tools/">All calculators</a>
            <h1><?= h($cfg['heading']) ?></h1>
            <p><?= h($cfg['description']) ?></p>
        </header>
        <section class="rule-card" aria-label="Pricing rule and context">
            <p class="rule-statement"><?= h($cfg['rule']) ?></p>
            <p class="mistake-label">The costly mistake</p>
            <p class="mistake-copy"><?= h($cfg['mistake']) ?></p>
            <div class="drawing"><?= $cfg['drawing'] ?></div>
            <details>
                <summary>Where these numbers come from</summary>
                <p><?= h($cfg['varies']) ?></p>
            </details>
        </section>
    </div>
    <div class="calculator-layout">
        <section class="calculator" aria-label="Calculator inputs">
            <form data-calculator novalidate><?= $cfg['form'] ?></form>
        </section>
        <section class="result" aria-label="Calculated result" aria-live="polite" data-full-result>
            <div data-result><p>Fill in the form to see your result.</p></div>
            <div data-working></div>
        </section>
    </div>
    <?php nextStep($emailOn); ?>
    <div class="result-bar" data-result-bar aria-live="polite" hidden></div>
</article>
<script type="module" src="/tools/_assets/tools.js"></script>
<?php toolsEnd(); ?>
