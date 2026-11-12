<?php
declare(strict_types=1);

$money = function (string $name, string $label, string $value = ''): string {
    $required = in_array($name, ['rate', 'cost_per_billed_hour'], true) ? ' required' : '';
    return '<div class="field"><label for="' . $name . '">' . $label . '</label>'
        . '<div class="input-prefix"><span>$</span><input id="' . $name . '" name="' . $name
        . '" type="number" min="0" step="0.01" value="' . $value . '"' . $required . '></div>'
        . '<p class="error" data-error="' . $name . '"></p></div>';
};

$cfg = [
    'slug' => 'break-even',
    'title' => 'Break-even calculator for a landscaping business | Ittai',
    'heading' => 'Break-even hours a month',
    'description' => 'How many billed hours a month cover your truck, insurance, phone, yard and your own pay.',
    'rule' => 'Every billed hour pays a slice of the fixed costs. Count the hours it takes.',
    'mistake' => "Leaving your own pay out of fixed costs, then wondering why the business 'makes money' and you don't.",
    'varies' => 'Insurance and truck costs vary a lot by area; use your own bills.',
    'drawing' => '<svg viewBox="0 0 360 150" aria-label="Billed hours crossing the fixed costs line"><path d="M25 120h310M25 120V20M25 65h310M30 115L310 25"/><circle cx="190" cy="65" r="6"/><text x="230" y="58" fill="currentColor" stroke="none">fixed costs</text></svg>',
    'form' => '<div class="fields">'
        . $money('truck_payment', 'Truck payment')
        . $money('insurance', 'Insurance')
        . $money('phone_software', 'Phone and software')
        . $money('yard_storage', 'Yard or storage')
        . $money('own_pay', 'Your own pay')
        . $money('other', 'Other')
        . $money('rate', 'Your hourly rate', '100')
        . $money('cost_per_billed_hour', 'Cost per billed hour')
        . '</div><p class="chain-note" data-chain-note></p>',
];

require dirname(__DIR__) . '/_inc/tool-page.php';
