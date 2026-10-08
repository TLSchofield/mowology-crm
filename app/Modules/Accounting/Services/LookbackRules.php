<?php
/**
 * LookbackRules — Penny's free rule tier for the 2026 look-back review (backlog item 7).
 *
 * Pure: no DB, no I/O — every rule family is unit tested on fixtures
 * (tests/Unit/Accounting/LookbackRulesTest.php). LookbackService gathers the rows and
 * stores what these return as proposals; nothing here changes anything.
 *
 * A proposal is an array:
 *   family       receipt | bank | journal
 *   kind         see KINDS
 *   subject_type expense | bank | journal
 *   subject_id   expenses.id | accounting_transactions.id | journal_entries.id
 *   date         Y-m-d of the record (decides the period / GST quarter)
 *   title        one line, in Penny's words
 *   before       the fields as they are now (also the undo and the staleness check)
 *   after        the fields as proposed ([] = information only, nothing to apply)
 *   evidence     list of short reasons
 *   confidence   0..100 — "Approve all of this kind" only offers >= BULK_CONFIDENCE
 *   source       rules | learned | ai
 *   amount       $ the proposal reclassifies (positive)
 *   gst          change in claimable GST (ITC); negative = less claimed
 *
 * Tim's rules (2026-10-05): diesel → the Dodge Ram (Fuel, truck); regular gas under $50 →
 * equipment; EGO → probably equipment; meals: 50% ITC. Tax sanity: 5% GST; BC PST 7% on
 * taxable goods; no GST on landfill fees, transit fares, insurance, bank fees, interest.
 * Payroll / CRA remittance lines are left to the Wave payroll import (count + $ only).
 */
require_once __DIR__ . '/../../Expenses/Services/ReceiptBookkeeperRules.php';
require_once __DIR__ . '/../../Expenses/Services/ExpenseSplitService.php';

class LookbackRules
{
    public const YEAR = 2026;
    public const GST_RATE = 0.05;
    public const PST_RATE = 0.07;
    public const BULK_CONFIDENCE = 85;

    /** kind => [family, label, actionable] */
    public const KINDS = [
        'fuel_rule'         => ['receipt', 'Fuel rule (diesel → truck, gas → equipment)', true],
        'ego_rule'          => ['receipt', 'EGO item → equipment', true],
        'vendor_category'   => ['receipt', 'Category differs from this vendor\'s usual', true],
        'ai_category'       => ['receipt', 'Category Penny could not decide by rules', true],
        'meals_category'    => ['receipt', 'Food and drink filed outside Meals', true],
        'gst_exempt'        => ['receipt', 'GST claimed on a supply that has none', true],
        'gst_missing'       => ['receipt', 'GST not recorded on a taxable purchase', true],
        'gst_rate'          => ['receipt', 'GST is not 5% of the net', true],
        'pst_check'         => ['receipt', 'PST looks wrong', false],
        'split'             => ['receipt', 'Mixed receipt not split by line', true],
        'duplicate'         => ['receipt', 'Same receipt booked twice', true],
        'personal'          => ['receipt', 'Looks personal — owner draw / due from shareholder', true],
        'job'               => ['receipt', 'Materials with no job', true],
        'loan'              => ['bank', 'TD car loan → 2610 Loan Payable', true],
        'card_payment'      => ['bank', 'Credit-card payment → 2400', true],
        'savings'           => ['bank', 'Transfer to savings → 1020 / 1025', true],
        'payee_consistency' => ['bank', 'Same payee filed different ways', true],
        'default_account'   => ['bank', 'Still on a default account (4900 / 6900)', true],
        'payroll'           => ['bank', 'Payroll — handled by the Wave payroll import', false],
        'cra_tax'           => ['bank', 'CRA payment → income tax / instalments / GST instalments', true],
        'cra_other'         => ['bank', 'CRA payment of an unknown kind — never an expense', false],
        'deposit_invoice'   => ['bank', 'Deposit counted as income that matches an invoice — income clean-up', false],
        'missing_receipt'   => ['bank', 'Card / bank spend with no receipt — receipt chaser', false],
        'journal_account'   => ['journal', 'Journal account disagrees with its source', true],
        'journal_amount'    => ['journal', 'Journal amount disagrees with its source', true],
        'journal_gst'       => ['journal', 'Journal GST line disagrees with the receipt', true],
        'journal_orphan'    => ['journal', 'Journal entry for a deleted record', true],
        'journal_double'    => ['journal', 'Posted twice', true],
        'journal_unbalanced'=> ['journal', 'Unbalanced entry', false],
    ];

    /** Supplies with no GST (Tim's list + ETA exempt supplies). word regex => why */
    public const GST_EXEMPT = [
        '/\b(LANDFILL|TRANSFER STATION|ECO ?CENT(RE|ER)|TIPPING|SCALE TICKET|SOLID WASTE|VANCOUVER LANDFILL|DELTA LANDFILL)\b/' => 'landfill / tipping fees carry no GST',
        '/\b(TRANSLINK|COMPASS|SKYTRAIN|SEABUS|BC TRANSIT|TRANSIT FARE|BUS FARE)\b/' => 'public transit fares are GST-exempt',
        '/\b(ICBC|INSURANCE|INSUR|INTACT|AVIVA|WAWANESA|NORTHBRIDGE|PREMIUM)\b/' => 'insurance premiums are GST-exempt',
        '/\b(BANK FEE|SERVICE CHARGE|MONTHLY FEE|ACCOUNT FEE|NSF|OVERDRAFT|INTEREST CHARGE|INTEREST|E-?TRANSFER FEE)\b/' => 'bank fees and interest are GST-exempt',
    ];

    /** Personal-spend signals. regex => what it looks like */
    public const PERSONAL = [
        '/\b(GYM|FITNESS|GOODLIFE|ANYTIME FITNESS|FIT4LESS|YMCA|CROSSFIT|YOGA|PELOTON)\b/' => 'a gym / fitness membership',
        '/\b(NETFLIX|SPOTIFY|DISNEY\+?|CRAVE|PRIME VIDEO|YOUTUBE PREMIUM|APPLE MUSIC|HBO|PARAMOUNT\+?|AUDIBLE)\b/' => 'a streaming subscription',
        '/\b(SAVE[- ]ON[- ]FOODS|SAFEWAY|NO FRILLS|WHOLE FOODS|SUPERSTORE|T&T|IGA|COSTCO GROCERY|FRESHCO|URBAN FARE|NESTERS)\b/' => 'a grocery store',
        '/\b(BC LIQUOR|BCL|LIQUOR STORE|LIQUOR|CANNABIS|BEER STORE|WINE)\b/' => 'liquor / cannabis',
    ];
    /** Grocery-basket line words (a store that sells both: only personal when the lines say so). */
    public const GROCERY_WORDS = ['milk', 'eggs', 'bread', 'cereal', 'produce', 'yogurt', 'cheese', 'butter', 'bananas', 'apples', 'chicken',
                                  'beef', 'pasta', 'rice', 'grocery', 'groceries', 'deli', 'bakery'];

    /** Bank descriptions — payroll (left to the Wave payroll-report import: count + $ only). */
    public const PAYROLL = '/\b(WAVE\s*PYRL|WAVE\s*PAYROLL|PAYROLL|PYRL|SOURCE\s*DEDUCTIONS?|PD7A)\b/';
    /** Bank descriptions — a payment to CRA (never an expense). */
    public const CRA = '/(\bRECEIVER\s*GEN|\bCANADA\s*REVENUE|\bCRA\b|\bGOVT?\.?\s*(OF\s*)?CANADA|\bGOV\'T\s*CANADA|\bFED(ERAL)?\s*(PAYMENT|TAX)|\bBUSINESS\s*TAX(ES)?\s*PAYMENT)/';
    /**
     * 2026 CRA payments from the accountant's YE2025 cover letter (Sit Lim CPA, 2026-06-24):
     * amount => [account code, from date (inclusive) or null, what it is].
     */
    public const CRA_KNOWN = [
        ['amount' => 10454.00, 'code' => '2250', 'from' => null,         'what' => '2025 corporate income tax balance — clears Income Tax Payable'],
        ['amount' => 3690.00,  'code' => '1320', 'from' => null,         'what' => '2026 corporate income-tax instalment due by June 30, 2026'],
        ['amount' => 875.00,   'code' => '1320', 'from' => '2026-07-01', 'what' => '2026 corporate income-tax instalment ($875/month from July)'],
        ['amount' => 3500.00,  'code' => '2215', 'from' => '2026-06-01', 'what' => '2026 GST instalment ($3,500/month from June) — reduces GST owing'],
    ];
    public const LOAN = '/\b(TD\s*ON-?LINE\s*LOANS?|ONLINE\s*LOANS?|LOAN\s*(PAYMENT|PMT|PYMT))\b/';
    public const CARD_PAYMENT = '/(PAYMENT\s*-?\s*THANK\s*YOU|THANK\s*YOU\s*PAYMENT|\bVISA\s*(PAYMENT|PMT)|\bMASTERCARD\s*(PAYMENT|PMT)|\bMC\s*(PAYMENT|PMT)|CREDIT\s*CARD\s*(PAYMENT|PMT)|\bTD\s*VISA\b|\bAMEX\s*(PAYMENT|PMT)|CARDHOLDER\s*PAYMENT)/';
    public const SAVINGS_GST = '/\b(GST\s*RES(ERVES?)?|TAX\s*RESERVE)\b/';
    public const SAVINGS = '/(\bTFR-?TO\b|TRANSFER\s*TO\s*SAV|\bTO\s*SAVINGS\b|\bSAVINGS\b)/';

    // ─────────────────────────────────────────────────────────────────────────
    // Receipts
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Everything the rules say about one approved / forwarded receipt.
     * @param array $e expenses row + vendor_name, lines (list<{id,name,line_total}>), ocr_text, has_split (bool),
     *                 facts? (receipt_facts row)
     * @param array $ctx vendor_history: category(lower) => count of OTHER approved receipts from this vendor
     *                   vendor_gst_share: share (0..1) of this vendor's other receipts that show GST (null = unknown)
     *                   meals_categories, meals_rate, mapped_categories (lower list), lessons (lineKey => lesson)
     *                   learned_category: owner-confirmed / AI category for this vendor (lookback_rules) or null
     * @return list<array> proposals
     */
    public static function receipt(array $e, array $ctx = []): array
    {
        $out = [];
        $id = (int)$e['id'];
        $date = substr((string)$e['expense_date'], 0, 10);
        $total = round((float)$e['total'], 2);
        $gst = round((float)($e['gst_amount'] ?? 0), 2);
        $pst = round((float)($e['pst_amount'] ?? 0), 2);
        $net = round($total - $gst - $pst, 2);
        $cat = trim((string)($e['accounting_category'] ?? ''));
        $tag = $e['asset_tag'] ?? null;
        $vendor = trim((string)($e['vendor_name'] ?? '') ?: (string)($e['vendor_name_raw'] ?? ''));
        $lines = (array)($e['lines'] ?? []);
        $names = array_map(fn($l) => (string)($l['name'] ?? ''), $lines);
        $text = strtoupper($vendor . "\n" . (string)($e['description'] ?? '') . "\n" . implode("\n", $names));
        $ocr = strtoupper((string)($e['ocr_text'] ?? ''));
        $mealsCats = $ctx['meals_categories'] ?? ['Meals'];
        $mealsRate = (float)($ctx['meals_rate'] ?? 0.5);
        $isMeals = self::isMeals($cat, $mealsCats);
        $base = ['family' => 'receipt', 'subject_type' => 'expense', 'subject_id' => $id, 'date' => $date];
        $mk = function (string $kind, string $title, array $before, array $after, array $evidence, int $conf, float $amount, float $gstDelta, string $source = 'rules') use ($base) {
            return $base + ['kind' => $kind, 'title' => $title, 'before' => $before, 'after' => $after, 'evidence' => array_values(array_filter($evidence)),
                            'confidence' => max(0, min(100, $conf)), 'source' => $source, 'amount' => round(abs($amount), 2), 'gst' => round($gstDelta, 2)];
        };
        $gstFor = function (string $newCat) use ($gst, $isMeals, $mealsCats, $mealsRate): float {
            // Change in claimable GST when the category changes (Meals claims only $mealsRate).
            $now = $isMeals ? $gst * $mealsRate : $gst;
            $then = self::isMeals($newCat, $mealsCats) ? $gst * $mealsRate : $gst;
            if (strcasecmp($newCat, 'Personal') === 0) $then = 0.0;
            return round($then - $now, 2);
        };

        // 1. Duplicates are found across receipts (duplicates()); a personal receipt stops the rest.
        $personal = self::personalSignal($vendor . ' ' . (string)($e['description'] ?? ''), $names);
        if ($personal && strcasecmp($cat, 'Personal') !== 0) {
            $out[] = $mk('personal', 'Looks like ' . $personal . ' — owner draw / due from shareholder?',
                ['accounting_category' => $cat ?: null, 'gst_amount' => $gst, 'amount' => round((float)$e['amount'], 2)],
                ['accounting_category' => 'Personal', 'gst_amount' => 0.0, 'amount' => round($total - $pst, 2)],
                [$vendor . ' looks like ' . $personal . '.', 'A personal purchase is not a business expense and its GST cannot be claimed.', 'You decide — I never file this on my own.'],
                55, $total, -($isMeals ? $gst * $mealsRate : $gst));
            return $out;
        }

        // 2. Tim's fuel / EGO rules (ReceiptBookkeeperRules) — firm rules first.
        $ruleCat = null; $ruleTag = null; $ruleWhy = []; $firm = true; $ruleName = null;
        foreach (ReceiptBookkeeperRules::evaluate(['total' => $total, 'vendor' => $vendor], $ocr, $names) as $h) {
            if ($h['field'] === 'accounting_category') { $ruleCat = $h['value']; $ruleName = $h['rule']; }
            if ($h['field'] === 'asset_tag') $ruleTag = $h['value'];
            $ruleWhy[] = $h['reason'];
            if ($h['strength'] !== 'firm') $firm = false;
        }
        $hasFood = false; $allFood = (bool)$lines;
        foreach ($names as $n) {
            if (ExpenseSplitService::isFood($n)) $hasFood = true; else $allFood = false;
        }
        $categoryProposed = false;
        if ($ruleCat !== null && !($hasFood && !$allFood && $ruleName !== 'ego_rule' && count($lines) > 1)) {
            $want = ['accounting_category' => $ruleCat];
            if ($ruleTag !== null) $want['asset_tag'] = $ruleTag;
            $have = ['accounting_category' => $cat ?: null, 'asset_tag' => $tag];
            $diff = [];
            foreach ($want as $k => $v) if ((string)($have[$k] ?? '') !== (string)$v) $diff[$k] = $v;
            if ($diff && !$e['has_split']) {
                $kind = $ruleName === 'ego_equipment' ? 'ego_rule' : 'fuel_rule';
                $out[] = $mk($kind, ($kind === 'fuel_rule' ? 'Fuel rule: ' : 'EGO rule: ') . self::describeChange($have, $diff),
                    array_intersect_key($have, $diff), $diff, $ruleWhy, $firm ? 90 : 60, $total, isset($diff['accounting_category']) ? $gstFor($diff['accounting_category']) : 0.0);
                $categoryProposed = true;
            }
        }

        // 3. Food and drink: all lines food → Meals; mixed → split by line (below).
        if (!$categoryProposed && $allFood && count($lines) >= 1 && !$isMeals && !$e['has_split']) {
            $out[] = $mk('meals_category', 'All food and drink — Meals (half the GST is claimable)',
                ['accounting_category' => $cat ?: null], ['accounting_category' => 'Meals'],
                ['Every line is food or drink: ' . implode(', ', array_slice($names, 0, 4)) . '.', 'Meals and entertainment: 50% ITC (ETA s.236).'],
                85, $total, $gstFor('Meals'));
            $categoryProposed = true;
        }

        // 4. Split by line: two or more destinations among the lines (fuel + snacks, seed for stock + mulch for a job).
        if (!$e['has_split'] && count($lines) >= 2) {
            $split = self::splitProposal($e, $ctx);
            if ($split) {
                $out[] = $mk('split', 'Split by line: ' . $split['say'], ['allocations' => []], ['allocations' => $split['choices']],
                    $split['evidence'], $split['confidence'], $total, $split['gst_delta']);
                $categoryProposed = true;
            }
        }

        // 5. The vendor's usual category (vendor memory: Tim's own approvals) / an owner-confirmed or AI answer.
        if (!$categoryProposed && !$e['has_split']) {
            $learned = $ctx['learned_category'] ?? null;
            $major = self::majority((array)($ctx['vendor_history'] ?? []), 3, 0.75);
            if ($learned && !empty($learned['category']) && strcasecmp($learned['category'], $cat) !== 0) {
                $owner = ($learned['source'] ?? '') === 'owner';
                $out[] = $mk($owner ? 'vendor_category' : 'ai_category',
                    ($owner ? 'You filed ' : 'Penny thinks ') . $vendor . ' as ' . $learned['category'],
                    ['accounting_category' => $cat ?: null], ['accounting_category' => $learned['category']],
                    [$owner ? 'You confirmed ' . $learned['category'] . ' for ' . $vendor . ' in this review (' . (int)($learned['confirmations'] ?? 1) . '×).'
                            : (string)($learned['reason'] ?? 'Asked Claude once for this vendor; the answer is reused.')],
                    $owner ? 90 : (int)min(80, $learned['confidence'] ?? 60), $total, $gstFor($learned['category']), $owner ? 'learned' : 'ai');
                $categoryProposed = true;
            } elseif ($major && strcasecmp($major['key'], $cat) !== 0) {
                $out[] = $mk('vendor_category', $vendor . ' is usually ' . self::catLabel($major['key']),
                    ['accounting_category' => $cat ?: null], ['accounting_category' => self::catLabel($major['key'])],
                    ['You filed ' . $major['count'] . ' of ' . $major['of'] . ' other ' . $vendor . ' receipts as ' . self::catLabel($major['key']) . '.'],
                    $major['share'] >= 0.9 && $major['count'] >= 5 ? 85 : 72, $total, $gstFor(self::catLabel($major['key'])), 'learned');
                $categoryProposed = true;
            }
        }

        // 6. GST sanity.
        $exempt = self::gstExemptReason($text);
        $exemptConf = 88;
        if (!$exempt && strcasecmp($cat, 'Disposal/Dump') === 0) {
            $exempt = ['dump fees', 'municipal landfill fees carry no GST — a private yard does charge it, so check the receipt'];
            $exemptConf = 60;
        }
        if ($exempt && $gst > 0) {
            $out[] = $mk('gst_exempt', 'GST $' . number_format($gst, 2) . ' claimed on ' . $exempt[0],
                ['gst_amount' => $gst, 'amount' => round((float)$e['amount'], 2)], ['gst_amount' => 0.0, 'amount' => round($total - $pst, 2)],
                [ucfirst($exempt[1]) . '.', 'The $' . number_format($gst, 2) . ' becomes part of the cost.'], $exemptConf, $gst, -($isMeals ? $gst * $mealsRate : $gst));
        } elseif (!$exempt && $gst == 0.0 && $total >= 5 && strcasecmp($cat, 'Personal') !== 0
                  && ($ctx['vendor_gst_share'] ?? null) !== null && $ctx['vendor_gst_share'] >= 0.8 && preg_match('/\b(GST|HST)\b/', $ocr)) {
            $g = round(($total - $pst) / (1 + self::GST_RATE) * self::GST_RATE, 2);
            $out[] = $mk('gst_missing', 'GST not recorded — the receipt shows GST',
                ['gst_amount' => 0.0, 'amount' => round((float)$e['amount'], 2)], ['gst_amount' => $g, 'amount' => round($total - $pst - $g, 2)],
                ['The receipt text prints GST.', sprintf('%d%% of your other %s receipts carry GST.', (int)round($ctx['vendor_gst_share'] * 100), $vendor),
                 '5% of $' . number_format($total - $pst - $g, 2) . ' = $' . number_format($g, 2) . '.'],
                65, $g, $isMeals ? $g * $mealsRate : $g);
        } elseif (!$exempt && $gst > 0 && $net > 0) {
            $expected = round($net * self::GST_RATE, 2);
            if (abs($gst - $expected) > max(0.10, $expected * 0.10)) {
                $printed = self::printedAmount($expected, $ocr);
                $out[] = $mk('gst_rate', sprintf('GST $%.2f is %.1f%% of the net — 5%% would be $%.2f', $gst, $gst / $net * 100, $expected),
                    ['gst_amount' => $gst, 'amount' => round((float)$e['amount'], 2)], ['gst_amount' => $expected, 'amount' => round($total - $pst - $expected, 2)],
                    [$printed ? '$' . number_format($expected, 2) . ' is printed on the receipt.' : 'Check the receipt — a mixed GST-free line can explain it.'],
                    $printed ? 80 : 45, abs($gst - $expected), ($isMeals ? $mealsRate : 1) * ($expected - $gst));
            }
        }
        if ($pst > 0 && $net > 0 && $pst > round($net * self::PST_RATE, 2) + 0.10) {
            $out[] = $mk('pst_check', sprintf('PST $%.2f is more than 7%% of the net ($%.2f)', $pst, $net * self::PST_RATE), ['pst_amount' => $pst], [],
                ['BC PST is 7% on taxable goods only — GST and PST may be swapped.'], 40, $pst, 0.0);
        } elseif ($pst > 0 && strcasecmp($cat, 'Fuel') === 0 && !$e['has_split']) {
            $out[] = $mk('pst_check', 'PST on a fuel receipt', ['pst_amount' => $pst], [],
                ['BC charges motor-fuel tax, not PST, on fuel — the PST is probably on another line (oil, snacks).'], 40, $pst, 0.0);
        }
        return $out;
    }

    /**
     * Split by line for an unsplit receipt with 2+ lines, from Penny's split rules
     * (ExpenseSplitService::proposeLine: learned splits first, then fuel / food / stock).
     * @return ?array{choices: list<array>, say: string, evidence: list<string>, confidence: int, gst_delta: float}
     */
    public static function splitProposal(array $e, array $ctx = []): ?array
    {
        $header = ['accounting_category' => $e['accounting_category'] ?? null, 'job_id' => $e['job_id'] ?? null, 'asset_tag' => $e['asset_tag'] ?? null];
        $lessons = (array)($ctx['lessons'] ?? []);
        $choices = []; $why = []; $learned = 0;
        $parts = [];
        foreach ((array)$e['lines'] as $l) {
            $p = ExpenseSplitService::proposeLine($l, [], $lessons[ExpenseSplitService::lineKey((string)$l['name'])] ?? null, $header);
            if ($p['rule'] === 'learned') $learned++;
            $choices[] = ['line_item_id' => (int)$l['id'], 'job_id' => $p['job_id'], 'accounting_category' => $p['accounting_category'],
                          'asset_tag' => $p['asset_tag'], 'is_stock' => $p['is_stock'], 'pst_taxable' => null, 'reason' => $p['reason']];
            $dest = $p['is_stock'] ? 'stock' : ($p['job_id'] ? 'job' : ($p['accounting_category'] ?: 'receipt'));
            $parts[$dest][] = (string)$l['name'];
            if ($p['rule'] !== 'receipt') $why[$p['reason']] = true;
        }
        if (!ExpenseSplitService::isRealSplit($choices)) return null;
        // GST: the food share's GST is only half claimable.
        $mealsCats = $ctx['meals_categories'] ?? ['Meals'];
        $rate = (float)($ctx['meals_rate'] ?? 0.5);
        $nets = [];
        foreach ((array)$e['lines'] as $i => $l) $nets[] = ['key' => $i, 'net' => (float)$l['line_total']];
        $alloc = ExpenseSplitService::allocate($nets, (float)$e['total'], (float)($e['gst_amount'] ?? 0), (float)($e['pst_amount'] ?? 0));
        $headerMeals = self::isMeals((string)($e['accounting_category'] ?? ''), $mealsCats);
        $now = $headerMeals ? (float)($e['gst_amount'] ?? 0) * $rate : (float)($e['gst_amount'] ?? 0);
        $then = 0.0;
        foreach ($choices as $i => $c) {
            $g = (float)($alloc[$i]['gst'] ?? 0);
            $then += self::isMeals((string)$c['accounting_category'], $mealsCats) ? $g * $rate : $g;
        }
        $say = [];
        foreach ($parts as $dest => $ns) $say[] = $dest . ': ' . implode(', ', array_slice($ns, 0, 2)) . (count($ns) > 2 ? '…' : '');
        return ['choices' => $choices, 'say' => implode(' · ', $say), 'evidence' => array_keys($why) ?: ['The lines go to different places.'],
                'confidence' => $learned ? 85 : 75, 'gst_delta' => round($then - $now, 2)];
    }

    /**
     * Same receipt booked twice among approved / forwarded receipts.
     * @param list<array> $rows id, expense_date, total, vendor_key, receipt_media_id, status, facts?, gst_amount
     * @param array $dismissed "a:b" (a<b) => true — pairs Tim said are not duplicates
     * @return list<array> proposals: cancel the later copy
     */
    public static function duplicates(array $rows, array $dismissed = []): array
    {
        $out = [];
        $by = [];
        foreach ($rows as $r) $by[(string)$r['vendor_key']][] = $r;
        $taken = [];
        foreach ($by as $vk => $list) {
            if ($vk === '') continue;
            usort($list, fn($a, $b) => [$a['expense_date'], (int)$a['id']] <=> [$b['expense_date'], (int)$b['id']]);
            $n = count($list);
            for ($i = 0; $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    $a = $list[$i]; $b = $list[$j];
                    if (isset($taken[(int)$b['id']]) || isset($taken[(int)$a['id']])) continue;
                    $days = abs((strtotime((string)$a['expense_date']) - strtotime((string)$b['expense_date'])) / 86400);
                    if ($days > 3) break;
                    $k = min((int)$a['id'], (int)$b['id']) . ':' . max((int)$a['id'], (int)$b['id']);
                    if (isset($dismissed[$k])) continue;
                    if (abs((float)$a['total'] - (float)$b['total']) > 0.009) continue;
                    $why = null; $conf = 0;
                    if (!empty($a['receipt_media_id']) && (int)$a['receipt_media_id'] === (int)($b['receipt_media_id'] ?? 0)) {
                        $why = 'the same photo'; $conf = 97;
                    } else {
                        $v = class_exists('ReceiptFactsService')
                            ? ReceiptFactsService::duplicateVerdict($a['facts'] ?? null, $b['facts'] ?? null)
                            : self::factsVerdict($a['facts'] ?? null, $b['facts'] ?? null);
                        if ($v['verdict'] === 'different') continue;
                        if ($v['verdict'] === 'same') { $why = $v['why']; $conf = 95; }
                        elseif ($days <= 1) { $why = 'same vendor, same total, ' . ($days == 0 ? 'same day' : 'a day apart') . ' — no ticket number to tell them apart'; $conf = 65; }
                    }
                    if (!$why) continue;
                    $taken[(int)$b['id']] = true;
                    $out[] = ['family' => 'receipt', 'kind' => 'duplicate', 'subject_type' => 'expense', 'subject_id' => (int)$b['id'],
                              'date' => substr((string)$b['expense_date'], 0, 10),
                              'title' => 'Receipt #' . (int)$b['id'] . ' is a copy of #' . (int)$a['id'] . ' — cancel the copy',
                              'before' => ['status' => (string)$b['status'], 'copy_of' => (int)$a['id']],
                              'after' => ['status' => 'cancelled', 'copy_of' => (int)$a['id']],
                              'evidence' => ['$' . number_format((float)$b['total'], 2) . ' twice: ' . $why . '.', 'The copy\'s entry is reversed; #' . (int)$a['id'] . ' stays.'],
                              'confidence' => $conf, 'source' => 'rules', 'amount' => round((float)$b['total'], 2),
                              'gst' => -round((float)($b['gst_amount'] ?? 0), 2)];
                }
            }
        }
        return $out;
    }

    /** Fallback for duplicates() when ReceiptFactsService isn't loaded: ticket numbers only. */
    public static function factsVerdict(?array $a, ?array $b): array
    {
        $na = strtoupper(preg_replace('/[^A-Z0-9]/i', '', (string)($a['doc_number'] ?? '')));
        $nb = strtoupper(preg_replace('/[^A-Z0-9]/i', '', (string)($b['doc_number'] ?? '')));
        if ($na !== '' && $na === $nb) return ['verdict' => 'same', 'why' => 'same ticket ' . $a['doc_number']];
        if ($na !== '' && $nb !== '') return ['verdict' => 'different', 'why' => 'different tickets'];
        return ['verdict' => 'unknown', 'why' => ''];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Bank lines
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * What a single bank line's own description says (no history needed).
     * @param array $tx id, transaction_date, type, amount, gst_amount, description, code (current account code), money_in (bool)
     * @param array $codes code => {id, name, type} — accounts that exist
     * @return ?array a proposal (payroll is information only), or null
     */
    public static function bankLine(array $tx, array $codes): ?array
    {
        $d = strtoupper((string)$tx['description']);
        $code = (string)($tx['code'] ?? '');
        $amount = round(abs((float)$tx['amount']), 2);
        $base = ['family' => 'bank', 'subject_type' => 'bank', 'subject_id' => (int)$tx['id'], 'date' => substr((string)$tx['transaction_date'], 0, 10),
                 'source' => 'rules', 'amount' => $amount];
        if (preg_match(self::PAYROLL, $d)) {
            return $base + ['kind' => 'payroll', 'title' => 'Payroll — left to the Wave payroll import', 'before' => ['account' => $code], 'after' => [],
                            'evidence' => ['The Wave payroll-report import books gross wages, deductions, employer CPP/EI and remittances; this line will match a pay run.'],
                            'confidence' => 100, 'gst' => 0.0];
        }
        if (!empty($tx['money_in']) || ($tx['type'] ?? '') === 'income') return null;
        if (preg_match(self::CRA, $d)) return self::craLine($tx, $codes, $base);
        $gst = round((float)($tx['gst_amount'] ?? 0), 2);
        $to = null; $kind = null; $why = ''; $conf = 0;
        if (preg_match(self::LOAN, $d)) { $to = '2610'; $kind = 'loan'; $why = 'TD ON-LINE LOANS is the RAM 3500HD car loan — a liability, not an expense (Decision-Log 2026-10-07).'; $conf = 95; }
        elseif (preg_match(self::CARD_PAYMENT, $d)) { $to = '2400'; $kind = 'card_payment'; $why = 'Paying the credit card moves money to Credit Card Payable; the spending is on the card lines.'; $conf = 90; }
        elseif (preg_match(self::SAVINGS_GST, $d)) { $to = '1025'; $kind = 'savings'; $why = 'Money moved to the GST reserve savings account — a transfer, not a cost.'; $conf = 85; }
        elseif (preg_match(self::SAVINGS, $d)) { $to = '1020'; $kind = 'savings'; $why = 'Money moved to savings — a transfer, not a cost.'; $conf = 80; }
        if (!$to || $code === $to || !isset($codes[$to])) return null;
        if ($kind === 'savings' && in_array($code, ['1010', '1020', '1025'], true)) return null;
        return $base + ['kind' => $kind, 'title' => 'Move to ' . $to . ' ' . $codes[$to]['name'], 'before' => ['account' => $code], 'after' => ['account' => $to],
                        'evidence' => [$why, '"' . trim((string)$tx['description']) . '"'], 'confidence' => $conf,
                        'gst' => $gst > 0 ? -$gst : 0.0];
    }

    /**
     * A payment to CRA: by amount / date, the 2025 income-tax balance, a 2026 income-tax
     * instalment or a GST instalment (CRA_KNOWN). Anything else is listed, never filed as an
     * expense (payroll remittances come with the Wave payroll import).
     */
    public static function craLine(array $tx, array $codes, array $base = []): ?array
    {
        $code = (string)($tx['code'] ?? '');
        $amount = round(abs((float)$tx['amount']), 2);
        $date = substr((string)$tx['transaction_date'], 0, 10);
        $base = $base ?: ['family' => 'bank', 'subject_type' => 'bank', 'subject_id' => (int)$tx['id'], 'date' => $date, 'source' => 'rules', 'amount' => $amount];
        foreach (self::CRA_KNOWN as $k) {
            if (abs($amount - $k['amount']) > 0.009 || ($k['from'] !== null && $date < $k['from'])) continue;
            if ($code === $k['code'] || !isset($codes[$k['code']])) return null;
            return $base + ['kind' => 'cra_tax', 'title' => 'CRA $' . number_format($amount, 2) . ' → ' . $k['code'] . ' ' . $codes[$k['code']]['name'],
                            'before' => ['account' => $code], 'after' => ['account' => $k['code']],
                            'evidence' => [ucfirst($k['what']) . ' (accountant\'s YE2025 letter).', 'A tax payment is never a business expense.', '"' . trim((string)$tx['description']) . '"'],
                            'confidence' => 90, 'gst' => 0.0];
        }
        $expense = $code === '' || preg_match('/^[56]/', $code) || in_array($code, ['4900', '6900'], true);
        return $base + ['kind' => 'cra_other', 'title' => 'CRA $' . number_format($amount, 2) . ' — not one of the known amounts' . ($expense ? ' (sitting on an expense account)' : ''),
                        'before' => ['account' => $code], 'after' => [],
                        'evidence' => ['Not the 2025 balance ($10,454), an instalment ($3,690 / $875) or a GST instalment ($3,500).',
                                       'A payroll remittance will match the Wave payroll import; otherwise ask the accountant which CRA account it paid.'],
                        'confidence' => 100, 'gst' => 0.0];
    }

    /**
     * Same payee filed different ways, and lines still on a default account.
     * @param array $lines list of bank lines as in bankLine() + key (BankImportService::descriptionKey), linked (bool: tied to a receipt / invoice)
     * @param array $codes code => {id, name, type}
     * @param array $rules key => code (active owner-confirmed transaction_rules)
     * @return array{proposals: list<array>, ask: array<string, list<int>>} ask = payee key => line ids the rules can't decide (default account, no history)
     */
    public static function payees(array $lines, array $codes, array $rules = []): array
    {
        $groups = [];
        foreach ($lines as $l) {
            if (!empty($l['linked']) || !empty($l['money_in']) || ($l['type'] ?? '') === 'income') continue;
            if (preg_match(self::PAYROLL, strtoupper((string)$l['description'])) || preg_match(self::CRA, strtoupper((string)$l['description']))) continue;
            if (strlen((string)$l['key']) < 4) continue;
            $groups[(string)$l['key']][] = $l;
        }
        $out = []; $ask = [];
        foreach ($groups as $key => $ls) {
            $count = [];
            foreach ($ls as $l) {
                $c = (string)$l['code'];
                if ($c === '' || in_array($c, ['4900', '6900'], true)) continue;
                $count[$c] = ($count[$c] ?? 0) + 1;
            }
            $major = self::majority($count, 3, 0.75);
            $rule = $rules[$key] ?? null;
            foreach ($ls as $l) {
                $c = (string)$l['code'];
                $default = $c === '' || in_array($c, ['4900', '6900'], true);
                $to = null; $why = []; $conf = 0; $kind = $default ? 'default_account' : 'payee_consistency'; $src = 'rules';
                if ($rule && isset($codes[$rule]) && $rule !== $c) {
                    $to = $rule; $conf = 88; $src = 'learned';
                    $why[] = 'You confirmed this payee on ' . $rule . ' ' . $codes[$rule]['name'] . ' (Penny\'s bank rule).';
                } elseif ($major && $major['key'] !== $c && isset($codes[$major['key']])) {
                    $to = $major['key']; $conf = $major['share'] >= 0.9 ? 85 : 75;
                    $why[] = $major['count'] . ' of ' . $major['of'] . ' filed lines from this payee are on ' . $major['key'] . ' ' . $codes[$major['key']]['name'] . '.';
                }
                if ($to) {
                    $gst = round((float)($l['gst_amount'] ?? 0), 2);
                    $zero = in_array($codes[$to]['type'] ?? '', ['asset', 'liability', 'equity'], true);
                    $out[] = ['family' => 'bank', 'kind' => $kind, 'subject_type' => 'bank', 'subject_id' => (int)$l['id'],
                              'date' => substr((string)$l['transaction_date'], 0, 10),
                              'title' => ($default ? 'Off ' . $c . ' → ' : $c . ' → ') . $to . ' ' . $codes[$to]['name'],
                              'before' => ['account' => $c], 'after' => ['account' => $to],
                              'evidence' => array_merge($why, ['"' . trim((string)$l['description']) . '"']), 'confidence' => $conf, 'source' => $src,
                              'amount' => round(abs((float)$l['amount']), 2), 'gst' => $zero && $gst > 0 ? -$gst : 0.0];
                } elseif ($default) {
                    $ask[$key][] = (int)$l['id'];
                }
            }
        }
        return ['proposals' => $out, 'ask' => $ask];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Journal
    // ─────────────────────────────────────────────────────────────────────────

    /** Is an entry's debit = credit (to the cent)? @param list<array{debit, credit}> $lines */
    public static function balanced(array $lines): bool
    {
        $d = 0.0; $c = 0.0;
        foreach ($lines as $l) { $d += (float)$l['debit']; $c += (float)$l['credit']; }
        return abs(round($d - $c, 2)) < 0.005;
    }

    /**
     * Sources posted more than once (live entries, not reversed): keep the newest, reverse the rest.
     * @param list<array{id, source_type, source_id, entry_date}> $entries live entries
     * @return list<array{entry_id: int, keep: int, source_type: string, source_id: int, date: string}>
     */
    public static function doubles(array $entries): array
    {
        $by = [];
        foreach ($entries as $e) {
            if (!in_array($e['source_type'], ['expense', 'bank_import', 'invoice', 'payment'], true) || $e['source_id'] === null) continue;
            $by[$e['source_type'] . ':' . (int)$e['source_id']][] = $e;
        }
        $out = [];
        foreach ($by as $list) {
            if (count($list) < 2) continue;
            usort($list, fn($a, $b) => (int)$b['id'] <=> (int)$a['id']);
            $keep = (int)$list[0]['id'];
            foreach (array_slice($list, 1) as $e) {
                $out[] = ['entry_id' => (int)$e['id'], 'keep' => $keep, 'source_type' => (string)$e['source_type'], 'source_id' => (int)$e['source_id'],
                          'date' => substr((string)$e['entry_date'], 0, 10)];
            }
        }
        return $out;
    }

    /**
     * How a live entry differs from what its source posts now.
     * @param list<array{account_id, debit, credit}> $actual
     * @param list<array{account_id, debit, credit}> $expected
     * @return ?string null = the same | 'journal_gst' (the ITC line moved) | 'journal_amount' (totals differ) | 'journal_account'
     */
    public static function compareEntry(array $actual, array $expected, int $itcAccountId): ?string
    {
        $net = function (array $lines): array {
            $m = [];
            foreach ($lines as $l) {
                $k = (int)$l['account_id'];
                $m[$k] = round(($m[$k] ?? 0) + (float)($l['debit'] ?? 0) - (float)($l['credit'] ?? 0), 2);
            }
            return array_filter($m, fn($v) => abs($v) >= 0.005);
        };
        $a = $net($actual); $e = $net($expected);
        $diff = [];
        foreach (array_unique(array_merge(array_keys($a), array_keys($e))) as $k) {
            if (abs(($a[$k] ?? 0) - ($e[$k] ?? 0)) >= 0.005) $diff[] = $k;
        }
        if (!$diff) return null;
        if (in_array($itcAccountId, $diff, true)) return 'journal_gst';
        $tot = fn(array $lines) => round(array_sum(array_map(fn($l) => (float)($l['debit'] ?? 0), $lines)), 2);
        return abs($tot($actual) - $tot($expected)) >= 0.005 ? 'journal_amount' : 'journal_account';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GST per period
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * GST effect per 2026 quarter of the proposals, and what to say for a filed one.
     * @param list<array{date: string, gst: float, status: string}> $props
     * @param list<array{period_from, period_to, filed_on?}> $filings gst_filings rows
     */
    public static function gstByQuarter(array $props, array $filings, int $year = self::YEAR): array
    {
        $out = [];
        for ($q = 1; $q <= 4; $q++) {
            $from = sprintf('%04d-%02d-01', $year, $q * 3 - 2);
            $to = date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $year, $q * 3)));
            $open = 0.0; $applied = 0.0; $n = 0;
            foreach ($props as $p) {
                $d = (string)$p['date'];
                if ($d < $from || $d > $to || abs((float)$p['gst']) < 0.005) continue;
                if ($p['status'] === 'open') { $open += (float)$p['gst']; $n++; }
                elseif ($p['status'] === 'applied') $applied += (float)$p['gst'];
            }
            $filed = null;
            foreach ($filings as $f) {
                if ((string)$f['period_from'] <= $to && (string)$f['period_to'] >= $from) { $filed = $f; break; }
            }
            $say = '';
            if ($filed && (abs($open) >= 0.005 || abs($applied) >= 0.005)) {
                $say = 'Filed' . (!empty($filed['filed_on']) ? ' ' . $filed['filed_on'] : '') . ' — the return is not changed; adjust ITCs by $'
                     . number_format($open + $applied, 2) . ' on your next return.';
            }
            $out[] = ['label' => "Q{$q} {$year}", 'from' => $from, 'to' => $to, 'open' => round($open, 2), 'open_count' => $n,
                      'applied' => round($applied, 2), 'filed' => (bool)$filed, 'say' => $say];
        }
        return $out;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Small helpers
    // ─────────────────────────────────────────────────────────────────────────

    /** The value most of $counts agree on: at least $min and $share of the total. */
    public static function majority(array $counts, int $min, float $share): ?array
    {
        if (!$counts) return null;
        arsort($counts);
        $of = array_sum($counts);
        $key = (string)array_key_first($counts);
        $n = (int)$counts[$key];
        if ($n < $min || $of <= 0 || $n / $of < $share) return null;
        return ['key' => $key, 'count' => $n, 'of' => $of, 'share' => $n / $of];
    }

    /** 'Personal' etc. when the vendor / lines look personal. */
    public static function personalSignal(string $vendorText, array $lineNames = []): ?string
    {
        $t = strtoupper($vendorText);
        foreach (self::PERSONAL as $re => $what) {
            if (!preg_match($re, $t)) continue;
            if ($what === 'a grocery store' && $lineNames) {
                // A grocery store sells business things too (water, ice): personal only when the basket is groceries.
                $groc = 0;
                foreach ($lineNames as $n) {
                    $w = ' ' . preg_replace('/[^a-z]+/', ' ', strtolower($n)) . ' ';
                    foreach (self::GROCERY_WORDS as $g) if (strpos($w, ' ' . $g . ' ') !== false) { $groc++; break; }
                }
                if ($groc === 0) continue;
            }
            return $what;
        }
        return null;
    }

    /** [what, why] when the text names a supply with no GST. */
    public static function gstExemptReason(string $text): ?array
    {
        $t = strtoupper($text);
        foreach (self::GST_EXEMPT as $re => $why) {
            if (preg_match($re, $t, $m)) {
                $what = preg_match('/^[A-Z]{2,5}$/', $m[1]) ? $m[1] : ucwords(strtolower($m[1]));   // ICBC, NSF stay as printed
                return [$what . ($why === 'landfill / tipping fees carry no GST' ? ' fees' : ''), $why];
            }
        }
        return null;
    }

    public static function isMeals(?string $category, array $mealsCategories): bool
    {
        foreach ($mealsCategories as $m) if (strcasecmp(trim((string)$category), trim((string)$m)) === 0) return true;
        return false;
    }

    /** Is $amount printed in the receipt text (e.g. "GST 2.35")? */
    public static function printedAmount(float $amount, string $text): bool
    {
        return $amount > 0 && preg_match('/(?<![\d.])' . preg_quote(number_format($amount, 2, '.', ''), '/') . '(?!\d)/', $text) === 1;
    }

    /** 'fuel' → 'Fuel', 'tools/equipment' → 'Tools/Equipment' (the category list's spelling). */
    public static function catLabel(string $lower): string
    {
        $all = defined('EXPENSE_ACCOUNTING_CATEGORIES') ? EXPENSE_ACCOUNTING_CATEGORIES : [];
        foreach (array_merge($all, ['Personal']) as $c) if (strcasecmp($c, $lower) === 0) return $c;
        return implode('/', array_map('ucfirst', explode('/', strtolower($lower))));
    }

    private static function describeChange(array $have, array $diff): string
    {
        $bits = [];
        if (isset($diff['accounting_category'])) $bits[] = ($have['accounting_category'] ?: 'no category') . ' → ' . $diff['accounting_category'];
        if (isset($diff['asset_tag'])) $bits[] = 'for ' . ($have['asset_tag'] ?: '—') . ' → ' . $diff['asset_tag'];
        return implode(', ', $bits);
    }

    /** Stable hash of a proposal's "before": approving refuses when the record changed since. */
    public static function signature(array $before): string
    {
        ksort($before);
        return substr(sha1(json_encode(array_map(fn($v) => is_float($v) || is_int($v) ? round((float)$v, 2) : $v, $before))), 0, 16);
    }
}
