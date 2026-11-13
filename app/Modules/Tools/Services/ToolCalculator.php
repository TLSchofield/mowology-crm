<?php
declare(strict_types=1);

/** Calculates and describes the public gardener tools. */
final class ToolCalculator
{
    private const FIELDS = [
        'labour-cost' => ['wage', 'vacation', 'stat', 'cpp', 'ei', 'wcb', 'billable', 'truck', 'rate'],
        'markup-margin' => ['mode', 'percent', 'cost'],
        'break-even' => [
            'truck_payment',
            'insurance',
            'phone_software',
            'yard_storage',
            'own_pay',
            'other',
            'rate',
            'cost_per_billed_hour',
        ],
        'mow-price' => ['onsite', 'crew', 'drive', 'cost_paid', 'margin', 'minimum'],
    ];

    /** Returns the supported tool slugs. */
    public static function tools(): array
    {
        return array_keys(self::FIELDS);
    }

    /** Returns display labels and units for a tool's inputs. */
    public static function labels(string $tool): array
    {
        $labels = [
            'labour-cost' => [
                'wage' => ['Wage per paid hour', '$'],
                'vacation' => ['Vacation pay', '%'],
                'stat' => ['Stat holiday pay', '%'],
                'cpp' => ['CPP employer', '%'],
                'ei' => ['EI employer', '%'],
                'wcb' => ['WorkSafeBC', '%'],
                'billable' => ['Billable share of paid hours', '%'],
                'truck' => ['Truck, fuel and gear per paid hour', '$'],
                'rate' => ['Your hourly rate', '$'],
            ],
            'markup-margin' => [
                'mode' => ['Calculation', ''],
                'percent' => ['Percent', '%'],
                'cost' => ['Cost', '$'],
            ],
            'break-even' => [
                'truck_payment' => ['Truck payment', '$'],
                'insurance' => ['Insurance', '$'],
                'phone_software' => ['Phone and software', '$'],
                'yard_storage' => ['Yard or storage', '$'],
                'own_pay' => ['Your own pay', '$'],
                'other' => ['Other fixed costs', '$'],
                'rate' => ['Your hourly rate', '$'],
                'cost_per_billed_hour' => ['Cost per billed hour', '$'],
            ],
            'mow-price' => [
                'onsite' => ['Minutes on site', 'min'],
                'crew' => ['Crew size', 'people'],
                'drive' => ['Drive minutes to this stop', 'min'],
                'cost_paid' => ['Cost per paid hour per worker', '$'],
                'margin' => ['Target margin', '%'],
                'minimum' => ['Minimum charge', '$'],
            ],
        ];

        if (!isset($labels[$tool])) {
            throw new InvalidArgumentException('Unknown tool');
        }

        return $labels[$tool];
    }

    /** Keeps only known numeric inputs for a tool. */
    public static function allowlist(string $tool, array $input): array
    {
        if (!isset(self::FIELDS[$tool])) {
            throw new InvalidArgumentException('Unknown tool');
        }

        $output = [];
        foreach (self::FIELDS[$tool] as $key) {
            if ($key === 'mode') {
                $output[$key] = (($input[$key] ?? '') === 'margin') ? 'margin' : 'markup';
                continue;
            }
            if (isset($input[$key]) && $input[$key] !== '' && is_numeric($input[$key])) {
                $output[$key] = (float) $input[$key];
            }
        }

        return $output;
    }

    /** Calculates one tool result from untrusted raw input. */
    public function calculate(string $tool, array $raw): array
    {
        $input = self::allowlist($tool, $raw);
        if ($tool === 'labour-cost') {
            return $this->labour($input);
        }
        if ($tool === 'markup-margin') {
            return $this->markup($input);
        }
        if ($tool === 'break-even') {
            return $this->breakEven($input);
        }
        if ($tool === 'mow-price') {
            return $this->mow($input);
        }

        throw new InvalidArgumentException('Unknown tool');
    }

    private function need(array $input, string $key, float $min, float $max): float
    {
        if (!isset($input[$key]) || $input[$key] < $min || $input[$key] > $max) {
            throw new InvalidArgumentException('Invalid ' . $key);
        }

        return (float) $input[$key];
    }

    private function optional(array $input, string $key, float $min, float $max): ?float
    {
        if (!isset($input[$key])) {
            return null;
        }

        return $this->need($input, $key, $min, $max);
    }

    private function pack(string $tool, array $inputs, array $result): array
    {
        return ['tool' => $tool, 'version' => 1, 'inputs' => $inputs, 'result' => $result];
    }

    private function labour(array $input): array
    {
        $wage = $this->need($input, 'wage', 0.01, 1000);
        $vacation = $this->need($input, 'vacation', 0, 100);
        $stat = $this->need($input, 'stat', 0, 100);
        $cpp = $this->need($input, 'cpp', 0, 100);
        $ei = $this->need($input, 'ei', 0, 100);
        $wcb = $this->need($input, 'wcb', 0, 100);
        $billable = $this->need($input, 'billable', 0.01, 100);
        $truck = $this->need($input, 'truck', 0, 10000);
        $rate = $this->optional($input, 'rate', 0, 10000);
        $withLeave = $wage * (1 + ($vacation + $stat) / 100);
        $withPayroll = $withLeave * (1 + ($cpp + $ei + $wcb) / 100);
        $burden = (1 + ($vacation + $stat) / 100) * (1 + ($cpp + $ei + $wcb) / 100) - 1;
        $costPaid = $wage * (1 + $burden) + $truck;
        $costBilled = $costPaid / ($billable / 100);

        return $this->pack('labour-cost', $input, [
            'burden' => $burden,
            'withLeave' => $withLeave,
            'withPayroll' => $withPayroll,
            'costPaid' => $costPaid,
            'costBilled' => $costBilled,
            'rate' => $rate,
            'difference' => $rate === null ? null : abs($rate - $costBilled),
        ]);
    }

    private function markup(array $input): array
    {
        $percent = $this->need($input, 'percent', 0, 100000);
        $cost = $this->optional($input, 'cost', 0, 100000000);
        $mode = $input['mode'] ?? 'markup';
        if ($mode === 'margin') {
            if ($percent >= 100) {
                throw new InvalidArgumentException('Margin must be below 100');
            }
            $margin = $percent / 100;
            $markup = $margin / (1 - $margin);
        } else {
            $markup = $percent / 100;
            $margin = $markup / (1 + $markup);
        }
        $price = $cost === null ? null : $cost * (1 + $markup);

        return $this->pack('markup-margin', $input, [
            'mode' => $mode,
            'markup' => $markup,
            'margin' => $margin,
            'price' => $price,
            'profit' => $price === null ? null : $price - $cost,
        ]);
    }

    private function breakEven(array $input): array
    {
        $fixed = 0.0;
        foreach (['truck_payment', 'insurance', 'phone_software', 'yard_storage', 'own_pay', 'other'] as $key) {
            $fixed += $this->optional($input, $key, 0, 100000000) ?? 0;
        }
        $rate = $this->need($input, 'rate', 0, 1000000);
        $cost = $this->need($input, 'cost_per_billed_hour', 0, 1000000);
        $contribution = $rate - $cost;

        return $this->pack('break-even', $input, [
            'fixed' => $fixed,
            'contribution' => $contribution,
            'hours' => $contribution > 0 ? $fixed / $contribution : null,
            'perWeek' => $contribution > 0 ? ($fixed / $contribution) * 12 / 52 : null,
        ]);
    }

    private function mow(array $input): array
    {
        $onsite = $this->need($input, 'onsite', 0.01, 100000);
        $crew = $this->need($input, 'crew', 1, 50);
        if (floor($crew) !== $crew) {
            throw new InvalidArgumentException('Invalid crew');
        }
        $drive = $this->need($input, 'drive', 0, 100000);
        $cost = $this->need($input, 'cost_paid', 0, 1000000);
        $margin = $this->need($input, 'margin', 0, 99.99);
        $minimum = $this->optional($input, 'minimum', 0, 1000000) ?? 0;
        $labour = ($onsite + $drive) * $crew * $cost / 60;
        $price = $labour / (1 - $margin / 100);
        $final = max($price, $minimum);

        return $this->pack('mow-price', $input, [
            'labour' => $labour,
            'price' => $price,
            'final' => $final,
            'perOnsiteMinute' => $final / $onsite,
            'minimumApplied' => $minimum > $price,
        ]);
    }
}
