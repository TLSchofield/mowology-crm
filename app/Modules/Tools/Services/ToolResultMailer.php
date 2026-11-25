<?php
declare(strict_types=1);

/** Renders server-calculated tool results for email. */
final class ToolResultMailer
{
    private ToolCalculator $calculator;

    /** Creates a tool result mailer. */
    public function __construct(?ToolCalculator $calculator = null)
    {
        $this->calculator = $calculator ?? new ToolCalculator();
    }

    /** Renders HTML and plain-text versions of a tool result. */
    public function render(string $tool, array $raw, ?string $confirmUrl = null, ?string $deleteUrl = null): array
    {
        $data = $this->calculator->calculate($tool, $raw);
        $sentence = $this->sentence($data);
        $working = $this->working($data);
        $inputs = $this->formattedInputs($data);
        $plainLines = [$sentence];
        if ($working !== []) {
            $plainLines[] = '';
            $plainLines[] = 'Working:';
            foreach ($working as $line) {
                $plainLines[] = $line;
            }
        }
        $plainLines[] = '';
        $plainLines[] = 'Inputs:';
        foreach ($inputs as $line) {
            $plainLines[] = $line;
        }
        $plainLines[] = '';
        $plainLines[] = 'Ittai works this out from your real time clock and receipts.';
        if ($confirmUrl !== null) {
            $plainLines[] = 'Confirm and save my numbers: ' . $confirmUrl;
        }
        if ($deleteUrl !== null) {
            $plainLines[] = 'Delete my saved numbers: ' . $deleteUrl;
        }
        $plainLines[] = '';
        $plainLines[] = $this->postalAddress();

        $html = '<p>' . $this->escape($sentence) . '</p>';
        if ($working !== []) {
            $html .= '<h2>Working</h2>' . $this->htmlList($working);
        }
        $html .= '<h2>Inputs</h2>' . $this->htmlList($inputs);
        $html .= '<p>Ittai works this out from your real time clock and receipts.</p>';
        if ($confirmUrl !== null) {
            $html .= '<p><a href="' . $this->escape($confirmUrl) . '">Confirm and save my numbers</a></p>';
        }
        if ($deleteUrl !== null) {
            $html .= '<p><a href="' . $this->escape($deleteUrl) . '">Delete my saved numbers</a></p>';
        }
        $html .= '<p>' . $this->escape($this->postalAddress()) . '</p>';

        return [
            'subject' => 'Your ' . $this->name($tool) . ' result from Ittai',
            'html' => $html,
            'plain' => implode("\n", $plainLines),
            'data' => $data,
        ];
    }

    /** Returns the same result sentence shown on the calculator page. */
    public function sentence(array $data): string
    {
        $result = $data['result'];
        if ($data['tool'] === 'labour-cost') {
            $sentence = 'Each hour you bill costs you $' . number_format($result['costBilled'], 2) . '.';
            if ($result['rate'] !== null) {
                if ($result['rate'] >= $result['costBilled']) {
                    $sentence .= ' At $' . number_format($result['rate'], 2) . ' you keep $'
                        . number_format($result['difference'], 2)
                        . ' of every billed hour, before your other costs.';
                } else {
                    $sentence .= ' At $' . number_format($result['rate'], 2) . ' you lose $'
                        . number_format($result['difference'], 2)
                        . ' on every hour you bill.';
                }
            }
            return $sentence;
        }
        if ($data['tool'] === 'markup-margin') {
            $sentence = $result['mode'] === 'markup'
                ? 'A ' . $this->percent($result['markup']) . ' markup keeps you ' . $this->percent($result['margin']) . ' of the price.'
                : 'To keep ' . $this->percent($result['margin']) . ', mark up by ' . $this->percent($result['markup']) . '.';
            if ($result['price'] !== null) {
                $sentence .= ' On $' . number_format($data['inputs']['cost'], 2)
                    . ' of plants, charge $' . number_format($result['price'], 2)
                    . ' and keep $' . number_format($result['profit'], 2) . '.';
            }
            return $sentence;
        }
        if ($data['tool'] === 'break-even') {
            return $result['hours'] === null
                ? 'At $' . number_format($data['inputs']['rate'], 2) . ' each hour costs more than it brings in, so no number of hours breaks even. Raise the rate or cut the cost per hour.'
                : 'You need ' . number_format($result['hours'], 1) . ' billed hours a month (about ' . number_format($result['perWeek'], 1) . ' a week) before you earn anything.';
        }

        $sentence = 'Charge $' . number_format($result['final'], 2)
            . ' for this mow. That\'s $' . number_format($result['perOnsiteMinute'], 2) . ' a minute on site.';
        if ($result['minimumApplied']) {
            $sentence .= ' Your minimum charge sets the price.';
        }
        return $sentence;
    }

    private function working(array $data): array
    {
        if ($data['tool'] !== 'labour-cost') {
            return [];
        }
        $input = $data['inputs'];
        $result = $data['result'];
        return [
            'Wage: $' . number_format($input['wage'], 2),
            'With vacation and stat: $' . number_format($result['withLeave'], 2),
            'With CPP, EI and WorkSafeBC: $' . number_format($result['withPayroll'], 2),
            'Plus truck: $' . number_format($result['costPaid'], 2),
            '÷ ' . $this->number($input['billable']) . '% billable share: $' . number_format($result['costBilled'], 2),
        ];
    }

    private function formattedInputs(array $data): array
    {
        $labels = ToolCalculator::labels($data['tool']);
        $lines = [];
        foreach ($data['inputs'] as $key => $value) {
            [$label, $unit] = $labels[$key];
            if ($key === 'mode') {
                $value = $value === 'margin' ? 'Target margin' : 'Known markup';
            }
            if ($unit === '$') {
                $display = '$' . number_format((float) $value, 2);
            } elseif ($unit === '%') {
                $display = $this->number((float) $value) . '%';
            } elseif ($unit !== '') {
                $display = $this->number((float) $value) . ' ' . $unit;
            } else {
                $display = (string) $value;
            }
            $lines[] = $label . ': ' . $display;
        }
        return $lines;
    }

    private function name(string $tool): string
    {
        return [
            'labour-cost' => 'labour cost',
            'markup-margin' => 'markup and margin',
            'break-even' => 'break-even',
            'mow-price' => 'mow price',
        ][$tool] ?? 'calculator';
    }

    private function percent(float $value): string
    {
        return $this->number($value * 100) . '%';
    }

    private function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
    }

    private function htmlList(array $lines): string
    {
        $html = '<ul>';
        foreach ($lines as $line) {
            $html .= '<li>' . $this->escape($line) . '</li>';
        }
        return $html . '</ul>';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    private function postalAddress(): string
    {
        return defined('ITTAI_MAIL_POSTAL') ? (string) ITTAI_MAIL_POSTAL : 'Ittai, Vancouver, BC';
    }
}
