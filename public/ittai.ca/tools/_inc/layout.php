<?php
declare(strict_types=1);

/** Send the security and privacy headers shared by every tools page. */
function toolsHeaders(): void
{
    header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
    header('X-Content-Type-Options: nosniff');
    $referrerPolicy = defined('TOOLS_REFERRER_POLICY') ? (string) TOOLS_REFERRER_POLICY : 'strict-origin-when-cross-origin';
    header('Referrer-Policy: ' . $referrerPolicy);
}

/** Open the shared tools document and page wrapper. */
function toolsStart(string $title, string $description, string $canonical, string $name = ''): void
{
    toolsHeaders();
    $schema = [
        '@context' => 'https://schema.org',
        '@type' => 'WebApplication',
        'name' => $name ?: $title,
        'description' => $description,
        'applicationCategory' => 'BusinessApplication',
        'offers' => [
            '@type' => 'Offer',
            'price' => '0',
            'priceCurrency' => 'CAD',
        ],
    ];
    ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= h($title) ?></title>
    <meta name="description" content="<?= h($description) ?>">
    <link rel="canonical" href="<?= h($canonical) ?>">
    <link rel="stylesheet" href="/tools/_assets/tools.css">
    <script type="application/ld+json"><?= json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
</head>
<body>
<header class="site-head">
    <a class="brand" href="/tools/">
        <?php // The hexagon symbol + typed name, as on ittai.ca's home page: the wordmark is not public before the trade-mark filing (Tim, 2026-10-10). ?>
        <svg class="symbol" viewBox="0 0 64 64" aria-hidden="true" focusable="false">
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
</header>
<main class="wrap">
    <?php
}

/** Close the shared page wrapper and render the footer. */
function toolsEnd(): void
{
    ?>
</main>
<footer>
    <div class="wrap">
        <a href="/privacy/">Privacy</a>
        <a href="/terms/">Terms</a>
    </div>
</footer>
</body>
</html>
    <?php
}

/** Render a dollar input with its unit before the value. */
function moneyField(string $name, string $label, string $value = '', string $note = '', bool $required = false): void
{
    ?>
<div class="field">
    <label for="<?= h($name) ?>"><?= h($label) ?></label>
    <div class="input-prefix">
        <span>$</span>
        <input id="<?= h($name) ?>" name="<?= h($name) ?>" type="number" min="0" step="0.01" value="<?= h($value) ?>" <?= $required ? 'required' : '' ?> inputmode="decimal">
    </div>
    <?php if ($note): ?>
        <small><?= h($note) ?></small>
    <?php endif; ?>
    <p class="error" data-error="<?= h($name) ?>"></p>
</div>
    <?php
}

/** Render a numeric input with an optional unit after the value. */
function numberField(string $name, string $label, string $value, string $suffix = '', string $note = '', string $min = '0', string $max = '100', string $step = '0.01'): void
{
    ?>
<div class="field">
    <label for="<?= h($name) ?>"><?= h($label) ?></label>
    <div class="input-suffix">
        <input id="<?= h($name) ?>" name="<?= h($name) ?>" type="number" min="<?= h($min) ?>" max="<?= h($max) ?>" step="<?= h($step) ?>" value="<?= h($value) ?>" required inputmode="decimal">
        <?php if ($suffix): ?>
            <span><?= h($suffix) ?></span>
        <?php endif; ?>
    </div>
    <?php if ($note): ?>
        <small><?= h($note) ?></small>
    <?php endif; ?>
    <p class="error" data-error="<?= h($name) ?>"></p>
</div>
    <?php
}

/** Render the product link and optional result-email form. */
function nextStep(bool $email): void
{
    ?>
<section class="next" aria-label="Continue with Ittai">
    <p>Ittai works this out from your real time clock and receipts. <a href="/">See what Ittai does</a></p>
    <?php if ($email): ?>
        <form class="email-form" data-email-form>
            <input type="hidden" name="tool">
            <input type="hidden" name="inputs">
            <input type="hidden" name="t">
            <div class="field">
                <label for="email">Email</label>
                <input id="email" name="email" type="email" autocomplete="email" required>
            </div>
            <div class="hp" aria-hidden="true">
                <label>Leave this empty <input name="hp" tabindex="-1" autocomplete="off"></label>
            </div>
            <label class="check">
                <input type="checkbox" name="save">
                <span><?= h(SavedNumbersService::SAVE_TEXT) ?></span>
            </label>
            <p class="privacy-note">We send this one email. If you tick Save, we'll ask you to confirm in that email; your numbers are then kept until you delete them or don't use them for 12 months.</p>
            <button type="submit">Email me this result</button>
            <p class="form-status" role="status"></p>
        </form>
    <?php endif; ?>
</section>
    <?php
}
