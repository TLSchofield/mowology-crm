<?php
/**
 * QboDiscoveryService — read-only: what is in the QuickBooks company file? (2026-10-07)
 *
 * Tim has a QBO account but is not sure what the accountant's file holds. Before anything is
 * pushed, this reads (never writes):
 *   CompanyInfo       name, legal name, country, fiscal year start, company start date, SKU
 *   Preferences       home currency, multicurrency, sales tax on/off, BookCloseDate, account
 *                     numbers on/off, custom transaction numbers, ReportBasis
 *   Account           the whole chart of accounts (number, name, type, sub-type, balance, active)
 *   TaxCode / TaxRate the tax set-up (GST 5 %, GST/PST BC 12 %, …)
 *   counts            Invoice, Payment, SalesReceipt, Purchase, Bill, Deposit, Transfer,
 *                     JournalEntry per year (last YEARS_BACK years + "before"), plus
 *                     Customer / Vendor / Item totals
 *   first/last dates  per transaction entity
 *
 * gather() is the network half (≈ 60 small requests, well inside 500/min per realm);
 * summarise() is pure and unit-tested on a canned sandbox-shaped payload. The report is cached
 * in ops_settings (qbo_discovery_report / qbo_discovery_at) so the page is instant and Tim's
 * accountant can be shown the same numbers.
 */
declare(strict_types=1);

require_once __DIR__ . '/QboApiClient.php';

class QboDiscoveryService
{
    public const YEARS_BACK = 5;
    public const TXN_ENTITIES  = ['Invoice', 'Payment', 'SalesReceipt', 'Purchase', 'Bill', 'BillPayment', 'Deposit', 'Transfer', 'JournalEntry'];
    public const LIST_ENTITIES = ['Customer', 'Vendor', 'Item'];
    public const SETTING_REPORT = 'qbo_discovery_report';
    public const SETTING_AT     = 'qbo_discovery_at';

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** Hit QuickBooks. Returns the raw material summarise() wants. */
    public function gather(QboApiClient $api, int $thisYear): array
    {
        $raw = ['company' => [], 'preferences' => [], 'accounts' => [], 'tax_codes' => [], 'tax_rates' => [],
                'counts' => [], 'first' => [], 'last' => [], 'errors' => []];
        try { $raw['company'] = $api->get('companyinfo/' . $api->realmId())['CompanyInfo'] ?? []; }
        catch (Throwable $e) { $raw['errors'][] = 'CompanyInfo: ' . $e->getMessage(); }
        try { $raw['preferences'] = $api->get('preferences')['Preferences'] ?? []; }
        catch (Throwable $e) { $raw['errors'][] = 'Preferences: ' . $e->getMessage(); }
        try { $raw['accounts'] = $api->queryAll('Account'); }
        catch (Throwable $e) { $raw['errors'][] = 'Account: ' . $e->getMessage(); }
        try { $raw['tax_codes'] = $api->queryAll('TaxCode'); }
        catch (Throwable $e) { $raw['errors'][] = 'TaxCode: ' . $e->getMessage(); }
        try { $raw['tax_rates'] = $api->queryAll('TaxRate'); }
        catch (Throwable $e) { $raw['errors'][] = 'TaxRate: ' . $e->getMessage(); }
        try { $raw['items'] = $api->queryAll('Item', "Type = 'Service'"); }
        catch (Throwable $e) { $raw['errors'][] = 'Item: ' . $e->getMessage(); }

        $firstYear = $thisYear - self::YEARS_BACK + 1;
        foreach (self::TXN_ENTITIES as $entity) {
            $raw['counts'][$entity] = [];
            try {
                $raw['counts'][$entity]['before'] = $api->count($entity, "TxnDate < '{$firstYear}-01-01'");
                for ($y = $firstYear; $y <= $thisYear; $y++) {
                    $raw['counts'][$entity][(string)$y] = $api->count($entity, "TxnDate >= '{$y}-01-01' AND TxnDate < '" . ($y + 1) . "-01-01'");
                }
                $last  = $api->query("SELECT * FROM {$entity} ORDERBY TxnDate DESC MAXRESULTS 1");
                $first = $api->query("SELECT * FROM {$entity} ORDERBY TxnDate ASC MAXRESULTS 1");
                $raw['last'][$entity]  = $last[$entity][0]['TxnDate'] ?? null;
                $raw['first'][$entity] = $first[$entity][0]['TxnDate'] ?? null;
            } catch (Throwable $e) {
                $raw['errors'][] = $entity . ': ' . $e->getMessage();
            }
        }
        foreach (self::LIST_ENTITIES as $entity) {
            try { $raw['counts'][$entity] = ['total' => $api->count($entity)]; }
            catch (Throwable $e) { $raw['errors'][] = $entity . ': ' . $e->getMessage(); }
        }
        return $raw;
    }

    /** Raw → the report the page renders. Pure. */
    public static function summarise(array $raw, string $today): array
    {
        $ci = $raw['company'] ?? [];
        $pf = $raw['preferences'] ?? [];
        $nv = [];
        foreach (($ci['NameValue'] ?? []) as $pair) {
            if (isset($pair['Name'])) $nv[$pair['Name']] = $pair['Value'] ?? null;
        }
        $other = [];
        foreach (($pf['OtherPrefs']['NameValue'] ?? []) as $pair) {
            if (isset($pair['Name'])) $other[$pair['Name']] = $pair['Value'] ?? null;
        }
        $company = [
            'name'               => $ci['CompanyName'] ?? null,
            'legal_name'         => $ci['LegalName'] ?? null,
            'country'            => $ci['Country'] ?? null,
            'fiscal_year_start'  => $ci['FiscalYearStartMonth'] ?? ($pf['AccountingInfoPrefs']['FirstMonthOfFiscalYear'] ?? null),
            'company_start_date' => isset($ci['CompanyStartDate']) ? substr((string)$ci['CompanyStartDate'], 0, 10) : null,
            'sku'                => $nv['OfferingSku'] ?? null,
            'subscription'       => $nv['SubscriptionStatus'] ?? null,
            'industry'           => $nv['IndustryType'] ?? null,
            'email'              => $ci['Email']['Address'] ?? null,
            'home_currency'      => $pf['CurrencyPrefs']['HomeCurrency']['value'] ?? null,
            'multicurrency'      => (bool)($pf['CurrencyPrefs']['MultiCurrencyEnabled'] ?? false),
            'sales_tax_enabled'  => (bool)($pf['TaxPrefs']['UsingSalesTax'] ?? false),
            'book_close_date'    => $pf['AccountingInfoPrefs']['BookCloseDate'] ?? null,
            'use_account_numbers'=> (bool)($pf['AccountingInfoPrefs']['UseAccountNumbers'] ?? false),
            'custom_txn_numbers' => (bool)($pf['SalesFormsPrefs']['CustomTxnNumbers'] ?? false),
            'report_basis'       => $pf['ReportPrefs']['ReportBasis'] ?? null,
            'track_classes'      => (bool)($pf['AccountingInfoPrefs']['ClassTrackingPerTxn'] ?? false) || (bool)($pf['AccountingInfoPrefs']['ClassTrackingPerTxnLine'] ?? false),
            'tax_form'           => $pf['AccountingInfoPrefs']['TaxForm'] ?? null,
        ];

        $accounts = [];
        foreach (($raw['accounts'] ?? []) as $a) {
            $accounts[] = [
                'id'             => (string)($a['Id'] ?? ''),
                'num'            => $a['AcctNum'] ?? null,
                'name'           => $a['Name'] ?? '',
                'full_name'      => $a['FullyQualifiedName'] ?? ($a['Name'] ?? ''),
                'classification' => $a['Classification'] ?? null,
                'type'           => $a['AccountType'] ?? null,
                'sub_type'       => $a['AccountSubType'] ?? null,
                'balance'        => isset($a['CurrentBalance']) ? (float)$a['CurrentBalance'] : null,
                'active'         => !isset($a['Active']) || (bool)$a['Active'],
                'sub_account'    => (bool)($a['SubAccount'] ?? false),
                'tax_code'       => $a['TaxCodeRef']['value'] ?? null,
            ];
        }
        usort($accounts, static function ($x, $y) {
            $cx = $x['classification'] ?? ''; $cy = $y['classification'] ?? '';
            if ($cx !== $cy) return strcmp($cx, $cy);
            return strcmp((string)($x['num'] ?? $x['name']), (string)($y['num'] ?? $y['name']));
        });
        $byClass = [];
        foreach ($accounts as $a) {
            $k = $a['classification'] ?? 'Other';
            $byClass[$k] = ($byClass[$k] ?? 0) + 1;
        }

        $rateById = [];
        foreach (($raw['tax_rates'] ?? []) as $r) {
            $rateById[(string)($r['Id'] ?? '')] = ['name' => $r['Name'] ?? '', 'rate' => isset($r['RateValue']) ? (float)$r['RateValue'] : null, 'agency' => $r['AgencyRef']['value'] ?? null];
        }
        $taxCodes = [];
        foreach (($raw['tax_codes'] ?? []) as $t) {
            $sales = []; $purchase = [];
            foreach (($t['SalesTaxRateList']['TaxRateDetail'] ?? []) as $d) {
                $id = (string)($d['TaxRateRef']['value'] ?? '');
                $sales[] = ($rateById[$id]['name'] ?? ($d['TaxRateRef']['name'] ?? $id)) . (isset($rateById[$id]['rate']) ? ' ' . rtrim(rtrim(number_format($rateById[$id]['rate'], 2, '.', ''), '0'), '.') . '%' : '');
            }
            foreach (($t['PurchaseTaxRateList']['TaxRateDetail'] ?? []) as $d) {
                $id = (string)($d['TaxRateRef']['value'] ?? '');
                $purchase[] = ($rateById[$id]['name'] ?? ($d['TaxRateRef']['name'] ?? $id)) . (isset($rateById[$id]['rate']) ? ' ' . rtrim(rtrim(number_format($rateById[$id]['rate'], 2, '.', ''), '0'), '.') . '%' : '');
            }
            $taxCodes[] = [
                'id'       => (string)($t['Id'] ?? ''),
                'name'     => $t['Name'] ?? '',
                'active'   => !isset($t['Active']) || (bool)$t['Active'],
                'taxable'  => (bool)($t['Taxable'] ?? false),
                'sales'    => $sales,
                'purchase' => $purchase,
            ];
        }

        $items = [];
        foreach (($raw['items'] ?? []) as $it) {
            if (isset($it['Active']) && !$it['Active']) continue;
            $items[] = ['id' => (string)($it['Id'] ?? ''), 'name' => $it['FullyQualifiedName'] ?? ($it['Name'] ?? ''), 'type' => $it['Type'] ?? null,
                        'income_account' => $it['IncomeAccountRef']['name'] ?? null, 'price' => isset($it['UnitPrice']) ? (float)$it['UnitPrice'] : null];
        }

        $counts = $raw['counts'] ?? [];
        $years = [];
        foreach (self::TXN_ENTITIES as $e) {
            foreach (array_keys($counts[$e] ?? []) as $k) {
                if ($k !== 'before') $years[$k] = true;
            }
        }
        $years = array_map('strval', array_keys($years));   // PHP turns '2025' keys into ints; keep them as text
        sort($years);
        $txnTotal = 0;
        $perYear = [];
        foreach (self::TXN_ENTITIES as $e) {
            foreach (($counts[$e] ?? []) as $k => $n) {
                $txnTotal += (int)$n;
                $perYear[$k] = ($perYear[$k] ?? 0) + (int)$n;
            }
        }
        $lastAny = null;
        foreach (($raw['last'] ?? []) as $d) {
            if ($d && ($lastAny === null || $d > $lastAny)) $lastAny = $d;
        }
        $firstAny = null;
        foreach (($raw['first'] ?? []) as $d) {
            if ($d && ($firstAny === null || $d < $firstAny)) $firstAny = $d;
        }

        // ── Warnings in Tim's words ──
        $warnings = [];
        $country = strtoupper((string)($company['country'] ?? ''));
        if ($country !== '' && !in_array($country, ['CA', 'CAN', 'CANADA'], true)) {
            $warnings[] = ['level' => 'danger', 'text' => "The company file says country \"{$company['country']}\" — not Canada. A Canadian GST/PST set-up needs a Canadian file; check this is the right company (realm)."];
        }
        if (!$company['sales_tax_enabled']) {
            $warnings[] = ['level' => 'danger', 'text' => 'Sales tax is OFF in this file. It can only be switched on from the QuickBooks Taxes screen (not by the API); until then nothing with GST or PST can be entered.'];
        }
        $hasGst = false; $hasPst = false;
        foreach ($taxCodes as $t) {
            $n = strtoupper($t['name']);
            if (strpos($n, 'GST') !== false || strpos($n, 'HST') !== false) $hasGst = true;
            if (strpos($n, 'PST') !== false) $hasPst = true;
        }
        if ($company['sales_tax_enabled'] && !$hasGst) {
            $warnings[] = ['level' => 'warning', 'text' => 'No tax code with GST in its name. The push needs a 5 % GST code for purchases and sales.'];
        }
        if ($company['sales_tax_enabled'] && !$hasPst) {
            $warnings[] = ['level' => 'warning', 'text' => 'No tax code with PST in its name. BC purchases that carry PST (7 %) and PST on sales need a GST/PST BC code.'];
        }
        if (empty($company['book_close_date'])) {
            $warnings[] = ['level' => 'info', 'text' => 'No closing date (BookCloseDate) is set. Ask the accountant to close the books through 2025-12-31 once FY2025 is final — the sync refuses to write on or before that date.'];
        } elseif ($company['book_close_date'] < '2026-01-01') {   // closed through FY2025 or earlier = QboPushPlanner::PUSH_FROM already covers it
            $warnings[] = ['level' => 'info', 'text' => 'Books are closed through ' . $company['book_close_date'] . '. FY2025 is filed and locked in the CRM; nothing before 2026-01-01 will ever be pushed regardless.'];
        }
        if (!$company['use_account_numbers']) {
            $warnings[] = ['level' => 'warning', 'text' => 'Account numbers are off in this file, so the chart-of-accounts map can only match by name. Turning numbers on in QuickBooks (Settings → Advanced → Chart of accounts) makes the mapping reliable.'];
        }
        if ($company['multicurrency']) {
            $warnings[] = ['level' => 'warning', 'text' => 'Multicurrency is on: every transaction pushed must carry CurrencyRef (' . ($company['home_currency'] ?? 'CAD') . ').'];
        }
        if ($company['home_currency'] && strtoupper((string)$company['home_currency']) !== 'CAD') {
            $warnings[] = ['level' => 'danger', 'text' => 'Home currency is ' . $company['home_currency'] . ', not CAD.'];
        }
        if (!$company['custom_txn_numbers']) {
            $warnings[] = ['level' => 'info', 'text' => 'Custom transaction numbers are off: QuickBooks will number pushed invoices itself; the CRM invoice number goes in the private note. Turn it on if the accountant wants INV-2026-#### as the QBO number.'];
        }
        $thisYear = substr($today, 0, 4);
        if (($perYear[$thisYear] ?? 0) > 0) {
            $warnings[] = ['level' => 'warning', 'text' => "The file already has " . number_format($perYear[$thisYear]) . " transactions dated {$thisYear}. Whoever entered them (the accountant? a bank feed?) must stop before the push starts, or every record will exist twice."];
        }
        $lastYear = (string)((int)$thisYear - 1);
        if (($perYear[$lastYear] ?? 0) === 0 && $txnTotal > 0) {
            $warnings[] = ['level' => 'info', 'text' => "No {$lastYear} transactions in the file — the accountant kept {$lastYear} somewhere else, so the FY2026 opening balances are theirs to enter, not the sync's."];
        }
        if ($txnTotal === 0) {
            $warnings[] = ['level' => 'info', 'text' => 'The file has no transactions at all — a clean start. Opening balances for FY2026 still belong to the accountant.'];
        }
        foreach (($raw['errors'] ?? []) as $err) {
            $warnings[] = ['level' => 'warning', 'text' => 'Could not read: ' . $err];
        }

        return [
            'generated_at' => $today,
            'company'      => $company,
            'accounts'     => $accounts,
            'accounts_by_class' => $byClass,
            'tax_codes'    => $taxCodes,
            'tax_rates'    => array_values(array_map(static fn($id, $r) => ['id' => $id] + $r, array_keys($rateById), $rateById)),
            'items'        => $items,
            'years'        => $years,
            'counts'       => $counts,
            'per_year'     => $perYear,
            'txn_total'    => $txnTotal,
            'first_txn'    => $firstAny,
            'last_txn'     => $lastAny,
            'first'        => $raw['first'] ?? [],
            'last'         => $raw['last'] ?? [],
            'warnings'     => $warnings,
            'errors'       => $raw['errors'] ?? [],
        ];
    }

    /** Cache the report for the page (ops_settings). */
    public function store(array $report): void
    {
        $json = json_encode($report, JSON_UNESCAPED_UNICODE);
        $this->upsertSetting(self::SETTING_REPORT, $json, 'QuickBooks discovery report (read-only snapshot of the company file)');
        $this->upsertSetting(self::SETTING_AT, date('Y-m-d H:i:s'), 'When the QuickBooks discovery report was last refreshed');
    }

    /** @return array{report:?array,at:?string} */
    public function cached(): array
    {
        try {
            $stmt = $this->db->prepare("SELECT setting_key, setting_value FROM ops_settings WHERE setting_key IN (?, ?)");
            $stmt->execute([self::SETTING_REPORT, self::SETTING_AT]);
            $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
            $report = isset($rows[self::SETTING_REPORT]) ? json_decode((string)$rows[self::SETTING_REPORT], true) : null;
            return ['report' => is_array($report) ? $report : null, 'at' => $rows[self::SETTING_AT] ?? null];
        } catch (Throwable $e) {
            return ['report' => null, 'at' => null];
        }
    }

    private function upsertSetting(string $key, string $value, string $desc): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO ops_settings (setting_key, setting_value, description) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
        );
        $stmt->execute([$key, $value, $desc]);
    }
}
