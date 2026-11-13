<?php
declare(strict_types=1);

$cfg = [
    'slug' => 'markup-margin',
    'title' => 'Markup vs margin calculator for landscaping jobs | Ittai',
    'heading' => 'Markup and margin',
    'description' => 'Turn a markup into the margin you actually keep, or a target margin into the markup to charge.',
    'rule' => "Markup is added to cost. Margin is what's left of the price.",
    'mistake' => 'Adding 30% and thinking you keep 30%. You keep 23%.',
    'varies' => 'Same everywhere. Remember GST/PST are on top of the price, not part of your margin.',
    'drawing' => '<svg viewBox="0 0 360 150" aria-label="Price split into cost and margin"><rect x="20" y="45" width="320" height="48"/><path d="M245 45v48M20 112h225m0-9v18M20 103v18"/><text x="105" y="75" fill="currentColor" stroke="none">cost</text><text x="274" y="75" fill="currentColor" stroke="none">margin</text><text x="105" y="140" fill="currentColor" stroke="none">markup is over cost</text></svg>',
    'form' => '<div class="toggle">'
        . '<label><input type="radio" name="mode" value="markup" checked> I know my markup</label>'
        . '<label><input type="radio" name="mode" value="margin"> I know the margin I want</label>'
        . '</div><div class="fields">'
        . '<div class="field"><label for="percent">Percent</label><div class="input-suffix">'
        . '<input id="percent" name="percent" type="number" min="0" step="0.01" value="30" required>'
        . '<span>%</span></div><p class="error" data-error="percent"></p></div>'
        . '<div class="field"><label for="cost">Optional cost</label><div class="input-prefix"><span>$</span>'
        . '<input id="cost" name="cost" type="number" min="0" step="0.01" value="400">'
        . '</div><p class="error" data-error="cost"></p></div></div>',
];

require dirname(__DIR__) . '/_inc/tool-page.php';
