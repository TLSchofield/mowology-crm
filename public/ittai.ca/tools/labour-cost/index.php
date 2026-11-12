<?php
declare(strict_types=1);

$field = function (string $name, string $label, string $value): string {
    $money = in_array($name, ['wage', 'truck', 'rate'], true);
    $required = $name === 'rate' ? '' : ' required';
    $control = $money
        ? '<div class="input-prefix"><span>$</span><input id="' . $name . '" name="' . $name . '" type="number" min="0" max="10000" step="0.001" value="' . $value . '"' . $required . '></div>'
        : '<div class="input-suffix"><input id="' . $name . '" name="' . $name . '" type="number" min="0" max="10000" step="0.001" value="' . $value . '"' . $required . '><span>%</span></div>';
    return '<div class="field"><label for="' . $name . '">' . $label . '</label>'
        . $control
        . '<p class="error" data-error="' . $name . '"></p></div>';
};

$cfg = [
    'slug' => 'labour-cost',
    'title' => 'Labour cost per hour calculator for landscapers (BC 2026) | Ittai',
    'heading' => 'True cost of a labour hour',
    'description' => "Work out what one billed hour of crew time really costs: wages, CPP, EI, vacation and stat pay, WorkSafeBC and the hours you can't bill.",
    'rule' => 'Price from the hours you can bill, not the hours you pay.',
    'mistake' => 'Dividing your costs by paid hours. Drive time, loading and dump runs are paid, but nobody pays you for them.',
    'varies' => "Rates are BC 2026 employer rates: CPP 5.95%, EI 2.282% (1.4 × 1.63%), WorkSafeBC 3.14% for landscaping (unit 764060). CPP stops at $74,600 a year and skips the first $3,500; EI stops at $68,900. Stat pay of 4.4% assumes a full-year worker and BC's 11 holidays; seasonal crews cost less. Other provinces differ: change any rate.",
    'drawing' => '<svg viewBox="0 0 360 150" aria-label="Paid time split into billed and unbilled time"><rect x="10" y="45" width="340" height="58"/><path d="M242 45v58"/><text x="94" y="80" fill="currentColor" stroke="none">billed</text><text x="258" y="69" fill="currentColor" stroke="none">drive</text><text x="252" y="94" fill="currentColor" stroke="none">load · dump</text></svg>',
    'form' => '<div class="fields">'
        . $field('wage', 'Wage per paid hour', '24.00')
        . $field('billable', 'Billable share of paid hours', '75')
        . $field('truck', 'Truck, fuel and gear per paid hour', '6.00')
        . $field('rate', 'Your hourly rate (optional)', '')
        . '</div><fieldset><legend>Employer costs (BC 2026 defaults, change any)</legend><div class="fields">'
        . $field('vacation', 'Vacation pay', '4')
        . $field('stat', 'Stat holiday pay', '4.4')
        . $field('cpp', 'CPP employer', '5.95')
        . $field('ei', 'EI employer', '2.282')
        . $field('wcb', 'WorkSafeBC', '3.14')
        . '</div><p class="source-line">CPP and EI: CRA 2026 rates · WorkSafeBC: unit 764060 landscaping, 2026 · Vacation and stat: BC Employment Standards · Checked 12 Nov 2026</p></fieldset>'
        . '<p>Working alone with no employees? Put in what you pay yourself, set CPP to 11.9%, and EI and WorkSafeBC to 0 unless you bought cover.</p>',
];

require dirname(__DIR__) . '/_inc/tool-page.php';
