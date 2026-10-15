<?php
declare(strict_types=1);

/**
 * GifiRules — CRA GIFI (RC4088) mapping rules: sections, suggestions, the whole-dollar statements and
 * the validator that blocks a GIFI export (Accountant's Ledger step 1, 2026-10-13).
 *
 * Source for every code and rule here: CRA RC4088(E) Rev. 23, "General Index of Financial Information
 * (GIFI)", read 2026-10-13. Its "Validity check items": total assets = total liabilities + total
 * equity; total revenue − total expenses = net non-farming income; each GIFI must include 2599, 3499,
 * 3620, 8299, 9368, 3849 (when retained earnings are broken down) and 9999. Amounts in whole dollars.
 *
 * Pure (no database): GifiService passes account rows in. Tested in tests/Unit/Ledger/GifiRulesTest.php.
 *
 * An account row: id, code, name, type (asset|liability|equity|revenue|expense), sub_type,
 * gifi_code, gifi_status ('suggested'|'confirmed'|null),
 *   end    — balance at the fiscal year end, debit − credit, all postings to that date;
 *   period — activity inside the fiscal year, debit − credit, excluding the FY opening entry;
 *   lines  — number of journal lines in the year (or any line making up a non-zero end balance).
 *
 * Tenant-neutral: suggestions read the account's type, sub_type and name, never a Mowology code.
 */
final class GifiRules
{
    /** Items every GIFI must carry (RC4088 "Validity check items"), plus 3849 since we break down retained earnings. */
    public const REQUIRED = ['2599', '3499', '3620', '8299', '9368', '3849', '9999'];

    /** Total items: computed here, never mapped to an account. */
    public const TOTALS = ['1599', '2008', '2009', '2599', '3139', '3450', '3499', '3620', '3640', '3849',
                           '8089', '8299', '8518', '8519', '9367', '9368', '9369', '9970', '9999'];

    /** Retained-earnings items computed from the ledger (3660 start, 3680 net income). */
    public const COMPUTED_RE = ['3660', '3680'];

    /** Which part of the statements a code belongs to (by RC4088's ranges). */
    public static function sectionOf(string $code): ?string
    {
        if (!preg_match('/^\d{4}$/', $code)) return null;
        $n = (int)$code;
        if (in_array($code, self::TOTALS, true)) return 'total';
        if ($n >= 1000 && $n <= 2589) return 'asset';
        if ($n >= 2600 && $n <= 3460) return 'liability';
        if ($n >= 3500 && $n <= 3590) return 'equity';
        if ($n === 3600) return 'retained';
        if ($n >= 3660 && $n <= 3848) return 'retained_breakdown';
        if ($n >= 8000 && $n <= 8298) return 'revenue';
        if ($n >= 8300 && $n <= 8517) return 'cost_of_sales';
        if ($n >= 8520 && $n <= 9366) return 'expense';
        if ($n >= 9975 && $n <= 9998) return 'tax';
        return null;
    }

    /** Balance sheet or income statement for a section. */
    public static function statementOf(?string $section): ?string
    {
        if (in_array($section, ['asset', 'liability', 'equity', 'retained'], true)) return 'BS';
        if (in_array($section, ['revenue', 'cost_of_sales', 'expense', 'tax'], true)) return 'IS';
        if ($section === 'retained_breakdown') return 'RE';
        return null;
    }

    private static function accountStatement(string $type): string
    {
        return in_array($type, ['asset', 'liability', 'equity'], true) ? 'BS' : 'IS';
    }

    /**
     * A suggested GIFI code + lead-sheet group for an account, with how sure it is and why. Returns
     * code null when the account needs a person (owner draws, opening-balance equity, a loan of unknown
     * term, headers). Never "confirmed": a suggestion is stored as 'suggested'.
     * @return array{code: ?string, lead: ?string, confidence: string, note: string}
     */
    public static function suggest(array $a): array
    {
        $type = (string)($a['type'] ?? '');
        $sub  = strtolower((string)($a['sub_type'] ?? ''));
        $name = strtolower((string)($a['name'] ?? ''));
        $has  = static function (string ...$words) use ($name): bool {
            foreach ($words as $w) if (strpos($name, $w) !== false) return true;
            return false;
        };
        $s = static fn(?string $c, ?string $lead, string $conf, string $note) => ['code' => $c, 'lead' => $lead, 'confidence' => $conf, 'note' => $note];

        if (in_array($sub, ['header', 'cogs_header', 'opex_header'], true)) {
            return $s(null, null, 'none', 'A heading account. It should hold no postings — move them to a real account, or ask the accountant.');
        }
        switch ($type) {
            case 'asset':
                if ($sub === 'bank' || $has('chequing', 'savings', 'bank')) return $s('1002', 'A', 'high', 'Bank deposits in Canadian currency.');
                if ($has('cash', 'float', 'petty')) return $s('1001', 'A', 'high', 'Cash on hand.');
                if ($has('doubtful', 'allowance')) return $s('1061', 'B', 'high', 'Allowance for doubtful accounts (a negative asset).');
                if ($sub === 'receivable' && $has('tax')) return $s('1483', 'D', 'medium', 'Taxes recoverable/refundable.');
                if ($sub === 'receivable' || $has('receivable')) return $s('1062', 'B', 'high', 'Trade accounts receivable.');
                if ($sub === 'shareholder_loan' || $has('shareholder', 'director')) return $s('1301', 'G', 'medium', 'Due from an individual shareholder (current). Long-term would be 2180 — ask the accountant.');
                if ($has('instalment') && $has('tax')) return $s('1483', 'D', 'medium', 'Income tax instalments paid show as taxes recoverable until the return is filed — confirm with the accountant.');
                if ($sub === 'prepaid' || $has('prepaid')) return $s('1484', 'D', 'high', 'Prepaid expenses.');
                if ($has('inventory', 'stock')) return $s('1120', 'C', 'medium', 'Inventories.');
                if ($has('accumulated', 'amortization', 'depreciation')) return $s(null, 'F', 'none', 'Accumulated amortization: pick the code that matches its asset (1741 equipment, 1743 vehicles).');
                if ($has('vehicle', 'truck', 'trailer')) return $s('1742', 'F', 'medium', 'Motor vehicles.');
                if ($has('computer', 'software')) return $s('1774', 'F', 'medium', 'Computer equipment/software.');
                if ($sub === 'fixed' || $has('equipment', 'tools', 'machinery', 'furniture')) return $s('1740', 'F', 'medium', 'Machinery, equipment, furniture and fixtures. Vehicles kept here belong on 1742 — ask the accountant.');
                return $s('1480', 'D', 'low', 'Other current assets — a guess; confirm.');
            case 'liability':
                if ($sub === 'tax_itc' || $sub === 'tax_payable' || $has('gst', 'hst', 'pst', 'income tax')) return $s('2680', 'BB', 'high', 'Taxes payable (GST/HST and PST net of input tax credits; income tax).');
                if ($sub === 'gov_payable' && $has('source deduction', 'payroll', 'cpp', 'ei')) return $s('2620', 'CC', 'medium', 'Payroll source deductions owed: amounts payable and accrued liabilities.');
                if ($sub === 'gov_payable' || $has('government', 'agencies')) return $s('2680', 'BB', 'medium', 'Owed to government — taxes payable. Confirm what it holds.');
                if ($sub === 'loan' || $has('loan')) return $s(null, 'FF', 'none', 'A loan: long-term debt (3140) with the next 12 months as current portion (2920), or short-term debt (2700) — the accountant splits it.');
                if ($sub === 'credit_card' || $has('credit card', 'visa', 'mastercard')) return $s('2707', 'EE', 'high', 'Credit card loans.');
                if ($sub === 'payable' || $has('payable')) return $s('2621', 'AA', 'high', 'Trade payables.');
                if ($sub === 'unearned' || $has('deposit', 'unearned', 'deferred')) return $s('2961', 'HH', 'high', 'Deposits received.');
                if ($has('clearing', 'net pay')) return $s('2620', 'CC', 'low', 'A clearing account; should be zero at year end. Amounts payable if not.');
                if ($has('shareholder', 'director')) return $s('2781', 'GG', 'medium', 'Due to an individual shareholder (current).');
                return $s('2960', 'AA', 'low', 'Other current liabilities — a guess; confirm.');
            case 'equity':
                if ($sub === 'retained' || $has('retained')) return $s('3600', 'RE', 'high', 'Retained earnings: the year\'s net income is added to it here (3660 + 3680 − 3700 = 3849).');
                if ($has('dividend')) return $s('3700', 'RE', 'high', 'Dividends declared.');
                if ($sub === 'capital' || $has('share', 'capital')) return $s('3500', 'SS', 'medium', 'Common shares. If this is a contribution and not share capital, 3541 contributed surplus — ask the accountant.');
                if ($sub === 'draw' || $has('draw')) return $s(null, 'G', 'none', 'Owner draws in a corporation are usually a shareholder loan (1301) or dividends (3700) — the accountant decides.');
                if ($sub === 'opening' || $has('opening')) return $s(null, 'RE', 'none', 'Opening-balance equity should be zero; if not, the accountant says where it belongs.');
                return $s(null, 'SS', 'none', 'Equity account — the accountant decides.');
            case 'revenue':
                if ($has('interest', 'investment', 'dividend income')) return $s('8090', '20', 'medium', 'Investment revenue.');
                if ($has('other income', 'gain', 'rebate', 'grant')) return $s('8230', '20', 'low', 'Other revenue.');
                return $s('8000', '20', 'high', 'Trade sales of goods and services.');
            case 'expense':
                if ($has('meal', 'entertainment')) {
                    return $s('8523', '40', 'medium', 'Meals and entertainment (50% rule). Check the account holds only meals — Stripe fees have been posted to an account with this number.');
                }
                if ($has('subcontract')) return $s('8360', '30', 'high', 'Trades and sub-contracts (cost of sales).');
                if ($sub === 'labour' || $has('crew wages', 'labour', 'labor', 'direct wages')) return $s('8340', '30', 'high', 'Direct wages (cost of sales). Office wages would be 9060.');
                if ($has('cpp', 'employer', 'benefit')) return $s('8622', '40', 'high', "Employer's portion of employee benefits (CPP/EI).");
                // Before materials: "PPE & Safety Supplies" is gear the crew wears, not materials sold (2026-10-15).
                if ($sub === 'safety' || $has('safety', 'ppe')) return $s('9130', '40', 'medium', 'Supplies (safety gear / PPE the crew uses) — confirm.');
                if ($sub === 'materials' || $has('materials', 'supplies')) return $s('8320', '30', 'high', 'Purchases/cost of materials (cost of sales).');
                if ($has('equipment rental', 'rental')) return $s('8914', '40', 'medium', 'Equipment rental.');
                if ($has('fuel') && $has('vehicle')) return $s('9281', '40', 'medium', 'Vehicle expenses (fuel, tires, washing). If this account is fuel only for machines, 9224 fuel costs — ask.');
                if ($has('vehicle') && $has('insurance')) return $s('8690', '40', 'medium', 'Insurance (RC4088 lists vehicle insurance under 8690).');
                if ($has('vehicle') && $has('maint', 'repair')) return $s('8962', '40', 'high', 'Repairs and maintenance – vehicles.');
                if ($has('vehicle') && $has('loan')) return $s(null, '40', 'none', 'A loan payment is not an expense: principal reduces the loan, interest goes to 8714. Ask the accountant.');
                if ($has('vehicle')) return $s('9281', '40', 'medium', 'Vehicle expenses.');
                if ($has('maint', 'repair')) return $s('8964', '40', 'medium', 'Repairs and maintenance – machinery and equipment.');
                if ($has('insurance')) return $s('8690', '40', 'high', 'Insurance.');
                if ($has('marketing', 'advertis')) return $s('8520', '40', 'high', 'Advertising and promotion.');
                if ($has('office', 'admin')) return $s('8810', '40', 'medium', 'Office expenses.');
                if ($has('accounting', 'bookkeeping')) return $s('8862', '40', 'high', 'Accounting fees.');
                if ($has('professional', 'legal')) return $s('8860', '40', 'high', 'Professional fees.');
                if ($has('phone', 'telephone', 'internet')) return $s('9225', '40', 'medium', 'Telephone and telecommunications. Utilities proper are 9220 — split if both.');
                if ($has('utilit')) return $s('9220', '40', 'medium', 'Utilities.');
                if ($has('interest')) return $s('8710', '40', 'medium', 'Interest and bank charges (8714 if all on long-term debt).');
                if ($has('processing', 'merchant', 'stripe')) return $s('8716', '40', 'medium', 'Credit card charges (card processing fees).');
                if ($sub === 'banking' || $has('bank charge', 'bank fee')) return $s('8715', '40', 'high', 'Bank charges.');
                if ($has('bad debt', 'write-off', 'write off')) return $s('8590', '40', 'high', 'Bad debt expense.');
                if ($has('licen', 'membership', 'dues', 'business tax')) return $s('8760', '40', 'high', 'Business taxes, licences and memberships.');
                if ($has('rent')) return $s('8910', '40', 'medium', 'Rental.');
                if ($has('travel')) return $s('9200', '40', 'medium', 'Travel expenses.');
                if ($has('amortization', 'depreciation')) return $s('8670', '40', 'high', 'Amortization of tangible assets.');
                if ($has('small tools', 'tools')) return $s('9131', '40', 'medium', 'Small tools.');
                if ($has('wage', 'salar')) return $s('9060', '40', 'medium', 'Salaries and wages (not in cost of sales).');
                if ($has('income tax')) return $s('9990', '50', 'medium', 'Current income taxes.');
                if ($has('misc', 'other', 'general')) return $s('9270', '40', 'low', 'Other expenses — CRA prefers a specific code; the accountant may reclassify.');
                return $s('9270', '40', 'low', 'Other expenses — a guess; confirm.');
        }
        return $s(null, null, 'none', 'Unknown account type.');
    }

    /**
     * Does this account need a GIFI code? Yes when it has journal lines in the year, or a balance that
     * reports: the year-end balance for a balance-sheet account, the year's activity for an income one.
     * The one rule for the validator AND the mapping screen's counts (review 2026-10-13: they disagreed).
     */
    public static function isActive(array $a): bool
    {
        if ((int)($a['lines'] ?? 0) > 0) return true;
        $isBs = self::accountStatement((string)($a['type'] ?? '')) === 'BS';
        return abs(round((float)($isBs ? ($a['end'] ?? 0) : ($a['period'] ?? 0)), 2)) >= 0.005;
    }

    /** Whole dollars, half away from zero (RC4088: amounts in dollars, no cents). */
    public static function dollars(float $amount): int
    {
        return (int)round($amount, 0, PHP_ROUND_HALF_UP);
    }

    /**
     * Build the GIFI statements and validate them.
     *
     * @param array $accounts rows as described in the class comment
     * @param array $knownCodes code => name from gifi_codes (an empty list skips the "unknown code" check)
     * @param float $ledgerNetIncome net income from the trial balance for the same year (revenue − expenses)
     * @return array{lines: array, totals: array, problems: array, warnings: array, ok: bool, unmapped: int}
     */
    public static function build(array $accounts, array $knownCodes, float $ledgerNetIncome): array
    {
        $raw = [];        // code => float
        $from = [];       // code => [account codes]
        $problems = []; $warnings = [];
        $re = ['3660' => 0.0, '3740' => 0.0, '3700' => 0.0];
        $unmapped = 0;

        foreach ($accounts as $a) {
            $acct  = (string)$a['code'];
            $label = $acct . ' ' . (string)($a['name'] ?? '');
            $type  = (string)($a['type'] ?? '');
            $end   = round((float)($a['end'] ?? 0), 2);
            $per   = round((float)($a['period'] ?? 0), 2);
            $isBs  = self::accountStatement($type) === 'BS';
            // Income-statement balances from before this year that were never closed (no FY opening entry
            // for that year) belong to retained earnings at the start of the year.
            if (!$isBs) $re['3660'] += -($end - $per);
            if (!self::isActive($a)) continue;

            $code = trim((string)($a['gifi_code'] ?? ''));
            if ($code === '') {
                $unmapped++;
                $problems[] = ['kind' => 'unmapped', 'account' => $acct, 'message' => "$label has activity and no GIFI code."];
                continue;
            }
            if (($a['gifi_status'] ?? '') !== 'confirmed') {
                $problems[] = ['kind' => 'unconfirmed', 'account' => $acct, 'message' => "$label → $code is a suggestion nobody has confirmed."];
            }
            if ($knownCodes && !isset($knownCodes[$code])) {
                $problems[] = ['kind' => 'unknown_code', 'account' => $acct, 'message' => "$label → $code is not in the GIFI list."];
                continue;
            }
            $section = self::sectionOf($code);
            if ($section === null || $section === 'total' || in_array($code, self::COMPUTED_RE, true)) {
                $problems[] = ['kind' => 'total_code', 'account' => $acct, 'message' => "$label → $code is a total or computed item; map the account to a detail line."];
                continue;
            }
            $stmt = self::statementOf($section);
            if (($stmt === 'IS') !== !$isBs) {
                $problems[] = ['kind' => 'wrong_statement', 'account' => $acct,
                               'message' => "$label is a " . ($isBs ? 'balance-sheet' : 'income-statement') . " account mapped to $code on the " . ($stmt === 'IS' ? 'income statement' : 'balance sheet') . '.'];
                continue;
            }
            if ($isBs && $type === 'asset' && in_array($section, ['liability', 'equity', 'retained', 'retained_breakdown'], true)) {
                $warnings[] = "$label (an asset) reports under $code — fine for a bank account in overdraft, otherwise check.";
            }
            if ($isBs && $type !== 'asset' && $section === 'asset') {
                $warnings[] = "$label (a " . $type . ") reports under asset code $code — check.";
            }
            if (!$isBs && (($type === 'revenue') !== ($section === 'revenue'))) {
                $warnings[] = "$label (" . $type . ") reports under $code (" . $section . ') — check.';
            }

            if ($section === 'retained') {                 // the retained-earnings account itself
                $re['3660'] += -($end - $per);              // credit balance before this year's direct changes
                $re['3740'] += -$per;                       // direct changes to retained earnings this year
                $from['3600'][] = $acct;
                continue;
            }
            if ($section === 'retained_breakdown') {        // 3700 dividends, 3740 other items
                $re[$code] = ($re[$code] ?? 0.0) + ($code === '3700' ? $per : -$per);
                $from[$code][] = $acct;
                continue;
            }
            switch ($section) {
                case 'asset':         $amt = $end;  break;
                case 'liability':
                case 'equity':        $amt = -$end; break;
                case 'revenue':       $amt = -$per; break;
                case 'cost_of_sales': $amt = $code === '8500' ? -$per : $per; break;
                default:              $amt = $per;  break;   // expense, tax
            }
            $raw[$code] = ($raw[$code] ?? 0.0) + $amt;
            $from[$code][] = $acct;
        }

        // Whole dollars per line; totals are sums of the rounded lines (as CRA adds them).
        $lines = [];
        foreach ($raw as $code => $amt) $lines[(string)$code] = self::dollars($amt);
        foreach ($re as $code => $amt) if (abs($amt) >= 0.5 || $code === '3660') $lines[(string)$code] = self::dollars($amt);
        ksort($lines, SORT_STRING);

        $sum = static function (callable $pred) use ($lines): int {
            $t = 0;
            foreach ($lines as $c => $v) if ($pred((string)$c)) $t += $v;
            return $t;
        };
        $sec = static fn(string $c) => self::sectionOf($c);

        $t = [];
        $t['8299'] = $sum(fn($c) => $sec($c) === 'revenue');
        $t['8089'] = $sum(fn($c) => $sec($c) === 'revenue' && (int)$c < 8089);
        $t['8518'] = $sum(fn($c) => $sec($c) === 'cost_of_sales' && $c !== '8500') - ($lines['8500'] ?? 0);
        $t['8519'] = $t['8089'] - $t['8518'];
        $t['9367'] = $sum(fn($c) => $sec($c) === 'expense');
        $t['9368'] = $t['8518'] + $t['9367'];
        $t['9369'] = $t['8299'] - $t['9368'];
        $t['9970'] = $t['9369'];
        $t['9999'] = $t['9970'] - $sum(fn($c) => $sec($c) === 'tax');
        $t['3680'] = $t['9999'];
        $t['3849'] = ($lines['3660'] ?? 0) + $t['3680'] - ($lines['3700'] ?? 0) + ($lines['3740'] ?? 0);
        $t['3600'] = $t['3849'];
        $t['2599'] = $sum(fn($c) => $sec($c) === 'asset');
        $t['3499'] = $sum(fn($c) => $sec($c) === 'liability');
        $t['3620'] = $sum(fn($c) => $sec($c) === 'equity') + $t['3600'];
        $t['3640'] = $t['3499'] + $t['3620'];

        // Rounding to whole dollars can leave a few dollars between the two sides; more than that
        // means an account is on the wrong side or missing.
        $nLines = max(1, count($lines));
        $tol = max(1, (int)ceil($nLines / 2));
        $bsDiff = $t['2599'] - $t['3640'];
        if (abs($bsDiff) > $tol) {
            $problems[] = ['kind' => 'totals', 'account' => null,
                           'message' => "Total assets (2599) {$t['2599']} ≠ total liabilities (3499) {$t['3499']} + shareholder equity (3620) {$t['3620']}: off by $bsDiff."];
        } elseif ($bsDiff !== 0) {
            $warnings[] = "Whole-dollar rounding leaves 2599 and 3640 \$$bsDiff apart (expected from rounding each line); the accountant decides which line takes the dollar.";
        }
        $niDiff = $t['9999'] - self::dollars($ledgerNetIncome);
        if (abs($niDiff) > $tol) {
            $problems[] = ['kind' => 'totals', 'account' => null,
                           'message' => "Net income 9999 ({$t['9999']}) does not recompute to the ledger's net income (" . self::dollars($ledgerNetIncome) . "): off by $niDiff."];
        }
        foreach (self::REQUIRED as $req) {
            if (!array_key_exists($req, $t)) $problems[] = ['kind' => 'totals', 'account' => null, 'message' => "Required item $req is missing."];
        }

        return [
            'lines'    => $lines,
            'from'     => $from,
            'totals'   => $t,
            'problems' => $problems,
            'warnings' => $warnings,
            'unmapped' => $unmapped,
            'ok'       => $problems === [],
        ];
    }

    /** Lead-sheet group for a GIFI code when the account has none (letters for the balance sheet, numbers for income). */
    public static function leadFor(?string $code): ?string
    {
        if ($code === null || $code === '') return null;
        $n = (int)$code;
        if ($n >= 1000 && $n <= 1059) return 'A';
        if ($n >= 1060 && $n <= 1119) return 'B';
        if ($n >= 1120 && $n <= 1179) return 'C';
        if ($n >= 1180 && $n <= 1299) return 'D';
        if ($n >= 1300 && $n <= 1399) return 'G';
        if ($n >= 1400 && $n <= 1599) return 'D';
        if ($n >= 1600 && $n <= 2178) return 'F';
        if ($n >= 2180 && $n <= 2589) return 'G';
        if ($n >= 2600 && $n <= 2679) return 'AA';
        if ($n >= 2680 && $n <= 2699) return 'BB';
        if ($n >= 2700 && $n <= 2779) return 'EE';
        if ($n >= 2780 && $n <= 2899) return 'GG';
        if ($n >= 2900 && $n <= 3139) return 'HH';
        if ($n >= 3140 && $n <= 3499) return 'FF';
        if ($n >= 3500 && $n <= 3599) return 'SS';
        if ($n >= 3600 && $n <= 3849) return 'RE';
        if ($n >= 8000 && $n <= 8299) return '20';
        if ($n >= 8300 && $n <= 8519) return '30';
        if ($n >= 8520 && $n <= 9369) return '40';
        if ($n >= 9975) return '50';
        return null;
    }
}
