<?php
declare(strict_types=1);

define('ITTAI_DIRECTORY_MODE', true);
if (!defined('APP_ROOT')) {
    for ($directory = __DIR__, $index = 0; $index < 6; $index++, $directory = dirname($directory)) {
        if (is_file($directory . '/app/Core/paths.php')) {
            require_once $directory . '/app/Core/paths.php';
            break;
        }
    }
}
require_once APP_ROOT . '/Core/config.php';
require_once __DIR__ . '/_inc/layout.php';

toolsStart(
    'Free calculators for garden businesses | Ittai',
    'Four free calculators for garden businesses: labour cost, markup and margin, break-even hours and mowing prices.',
    'https://ittai.ca/tools/',
    'Free calculators for garden businesses'
);

$tools = [
    ['labour-cost', 'True cost of a labour hour', 'Price from the hours you can bill, not the hours you pay.'],
    ['markup-margin', 'Markup and margin', "Markup is added to cost. Margin is what's left of the price."],
    ['break-even', 'Break-even hours a month', 'Every billed hour pays a slice of the fixed costs. Count the hours it takes.'],
    ['mow-price', 'Price a mow from minutes', 'Price the whole visit: time on site plus the drive to get there, for everyone on the crew.'],
];
?>
<header class="hero hub-hero">
    <h1>Free calculators for garden businesses</h1>
    <p>Work out the numbers your prices depend on. Nothing you type leaves this page unless you ask for an email.</p>
</header>
<ul class="hub-list">
    <?php foreach ($tools as $tool): ?>
        <li>
            <a href="/tools/<?= h($tool[0]) ?>/">
                <span><?= h($tool[1]) ?></span>
                <small><?= h($tool[2]) ?></small>
            </a>
        </li>
    <?php endforeach; ?>
</ul>
<?php toolsEnd(); ?>
