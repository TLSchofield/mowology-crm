<?php
/**
 * CodeSuggestionGate — may code book this receipt without asking the model?
 *
 * Pure decision + builder (no DB, no HTTP) so it is unit-testable and the rule is in one place:
 *
 *   eligible when
 *     - the local vendor is LEARNED: >= STREAK suggestions in a row accepted unchanged
 *       (Silver on Penny's brain; the live card calls these "vendors I know"), and
 *     - a category default exists (local vendor default, or the commons' shared/candidate
 *       default bridged to this tenant's categories), and
 *     - the reader captured subtotal/GST/PST/total that add up (code never does arithmetic
 *       that lands in the books), and
 *     - it is not Fuel without a learned asset tag (truck vs equipment is the owner's call), and
 *     - no firm owner rule contradicts the learned category.
 *
 *   build() returns a suggestion in exactly the model's schema (ReceiptBookkeeperService::schema())
 *   so check() validates it the same way and the review UI needs no new code path.
 */
declare(strict_types=1);

final class CodeSuggestionGate
{
    public const STREAK = 5;

    /**
     * @param array $ctx        ReceiptBookkeeperService::buildContext() output (current + prompt)
     * @param array $vendor     ['id','name','default_accounting_category','gst_exempt','streak'=>int,'asset_tag'=>?string]
     * @param array $hints      VendorKnowledgeService::hintsFor() — ['category_local'=>?, 'category_code'=>?, 'gst_exempt'=>?bool, 'source'=>'commons'|null]
     * @return array{ok:bool, reasons:string[], category:?string, asset_tag:string}
     */
    public static function decide(array $ctx, array $vendor, array $hints = []): array
    {
        $why = [];
        $streak = (int)($vendor['streak'] ?? 0);
        if ($streak < self::STREAK) $why[] = "vendor streak {$streak} < " . self::STREAK;

        $category = $vendor['default_accounting_category'] ?? null;
        $catFrom  = 'vendor default';
        if (!$category && !empty($hints['category_local'])) { $category = $hints['category_local']; $catFrom = 'commons'; }
        if (!$category) $why[] = 'no learned category';

        foreach ($ctx['prompt']['rule_hits'] ?? [] as $hit) {
            if (($hit['strength'] ?? '') === 'firm' && ($hit['field'] ?? '') === 'accounting_category' && $category && $hit['value'] !== $category) {
                $why[] = "owner's rule says {$hit['value']}, learned {$category}";
            }
        }

        $cur = $ctx['current'] ?? [];
        $nums = [];
        foreach (['subtotal', 'gst', 'pst', 'total'] as $f) {
            $nums[$f] = isset($cur[$f]) && is_numeric($cur[$f]) ? round((float)$cur[$f], 2) : null;
        }
        if ($nums['total'] === null || $nums['subtotal'] === null) $why[] = 'amounts not captured';
        else {
            $gst = $nums['gst'] ?? 0.0; $pst = $nums['pst'] ?? 0.0;
            if (abs($nums['subtotal'] + $gst + $pst - $nums['total']) > 0.03) $why[] = 'amounts do not add up';
        }

        $assetTag = 'none';
        if ($category === 'Fuel') {
            $assetTag = (string)($vendor['asset_tag'] ?? '');
            if (!in_array($assetTag, ['truck', 'equipment'], true)) $why[] = 'fuel without a learned asset tag';
        }

        return ['ok' => !$why, 'reasons' => $why ?: ["learned vendor ({$streak} in a row), category from {$catFrom}"], 'category' => $category, 'asset_tag' => $assetTag];
    }

    /** The suggestion, in the model's schema. Call only when decide()['ok']. */
    public static function build(array $ctx, array $vendor, array $decision): array
    {
        $cur = $ctx['current'] ?? [];
        $n = fn($f) => isset($cur[$f]) && is_numeric($cur[$f]) ? round((float)$cur[$f], 2) : 0.0;
        $streak = (int)($vendor['streak'] ?? 0);
        $why = "Same as the last {$streak} " . ($vendor['name'] ?? 'vendor') . " receipts you approved unchanged";
        $items = [];
        foreach ($cur['line_items'] ?? [] as $name) {
            if (is_string($name) && $name !== '') $items[] = ['name' => $name, 'amount' => 0.0];
        }
        return [
            'vendor'              => ['value' => (string)($cur['vendor'] ?? ($vendor['name'] ?? '')), 'reason' => 'Known vendor', 'confidence' => 'high'],
            'accounting_category' => ['value' => $decision['category'], 'reason' => $why, 'confidence' => 'high'],
            'asset_tag'           => ['value' => $decision['asset_tag'], 'reason' => $decision['asset_tag'] === 'none' ? 'Not fuel' : "Usual tag for this vendor", 'confidence' => 'high'],
            'job'                 => ['value' => null, 'reason' => 'Code does not guess jobs — pick one if it was for a job', 'confidence' => 'low'],
            'subtotal'            => ['value' => $n('subtotal'), 'reason' => 'As printed', 'confidence' => 'high'],
            'gst'                 => ['value' => $n('gst'), 'reason' => 'As printed', 'confidence' => 'high'],
            'pst'                 => ['value' => $n('pst'), 'reason' => 'As printed', 'confidence' => 'high'],
            'total'               => ['value' => $n('total'), 'reason' => 'As printed', 'confidence' => 'high'],
            'line_items'          => $items,
            'notes'               => 'Booked by code from what you taught me about this vendor — no AI used.',
        ];
    }
}
