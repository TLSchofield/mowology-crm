<?php
declare(strict_types=1);

$field = function (string $name, string $label, string $value, string $unit, string $max = '100000'): string {
    $required = $name === 'minimum' ? '' : ' required';
    $step = $name === 'crew' ? '1' : '0.01';
    $min = $name === 'crew' ? '1' : '0';
    $max = $name === 'crew' ? '50' : $max;
    $input = '<input id="' . $name . '" name="' . $name . '" type="number" min="' . $min . '" max="' . $max . '" step="' . $step . '" value="' . $value . '"' . $required . '>';
    $control = $unit === '$'
        ? '<div class="input-prefix"><span>$</span>' . $input . '</div>'
        : '<div class="input-suffix">' . $input . '<span>' . $unit . '</span></div>';
    return '<div class="field"><label for="' . $name . '">' . $label . '</label>'
        . $control
        . '<p class="error" data-error="' . $name . '"></p></div>';
};

$cfg = [
    'slug' => 'mow-price',
    'title' => 'Lawn mowing price calculator: price by the minute | Ittai',
    'heading' => 'Price a mow from minutes',
    'description' => 'Price a mow from the minutes on site and the drive, for your crew size and target margin.',
    'rule' => 'Price the whole visit: time on site plus the drive to get there, for everyone on the crew.',
    'mistake' => 'Pricing only the minutes on the lawn, so every drive is free.',
    'varies' => 'Use the paid-hour cost here, not the billed-hour cost: the drive is already counted.',
    'drawing' => '<svg viewBox="0 0 360 150" aria-label="Drive route ending at a lawn"><path d="M20 90c60-70 110 50 180-15"/><rect x="205" y="35" width="130" height="85"/><path d="M220 50l100 55M250 40l80 45"/><text x="65" y="125" fill="currentColor" stroke="none">drive minutes</text><text x="225" y="140" fill="currentColor" stroke="none">on-site minutes</text></svg>',
    'form' => '<div class="fields">'
        . $field('onsite', 'Minutes on site', '35', 'min')
        . $field('crew', 'Crew size', '2', 'people')
        . $field('drive', 'Drive minutes to this stop', '12', 'min')
        . $field('cost_paid', 'Cost per paid hour per worker', '', '$')
        . $field('margin', 'Target margin', '30', '%', '99.99')
        . $field('minimum', 'Minimum charge (optional)', '55', '$')
        . '</div><p class="chain-note" data-chain-note></p>',
];

require dirname(__DIR__) . '/_inc/tool-page.php';
