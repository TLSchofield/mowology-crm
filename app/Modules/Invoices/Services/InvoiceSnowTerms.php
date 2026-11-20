<?php
/**
 * InvoiceSnowTerms — the "Snow & Ice Service Terms" block on a salt/snow route invoice.
 *
 * The terms the client agreed to travel with the invoice, so paying it is a second,
 * visible act of acceptance on top of the signed contract. The wording is the copy
 * sealed onto the contract version (ContractTermsService::termsForVersion), never the
 * live template — a template can be edited after the client agreed.
 *
 * "Signed on" is only claimed when a signed signature row exists for that version AND the
 * wording is a sealed snapshot. Anything weaker (a contract that predates migration 1119,
 * or one set up from a signed quote with no signature row) shows the terms without a date,
 * so the invoice never says something we cannot prove.
 *
 * Not a salt/snow route invoice, no contract, no terms → null, and the invoice renders as
 * before. Never throws.
 */
class InvoiceSnowTerms
{
    /**
     * Ittai v1.2 brand colours (docs/design/ittai-v1.2/tokens.css, light mode) for the salt/snow invoice.
     * The panels' own HTML (weather, GPS) is shared with other invoices and still carries the older
     * navy / Mowology greens, so the salt/snow layout maps them onto the Ittai tokens at render time.
     */
    public static function ittai(string $html): string
    {
        return strtr($html, [
            '#1e3a5f' => '#233A5E', '#1E3A5F' => '#233A5E',          // panel navy → --brand
            '#0d3b2e' => '#233A5E', '#0D3B2E' => '#233A5E',          // dark green → --brand
            '#2D8659' => '#233A5E', '#7FD858' => '#48534C',          // brand green / lime → --brand / --meta
            '#E8F3F0' => '#EEF1EE', '#ecfdf5' => '#EEF1EE',          // mint → --sunk
            '#991b1b' => '#982A1D',                                  // overdue → --status-blocked
            '#cbd5e1' => '#D9DFDA', '#e2e8f0' => '#D9DFDA',          // slate hairlines → --line
            '#64748b' => '#48534C', '#475569' => '#48534C', '#334155' => '#131D17', '#1f2937' => '#131D17', // text → --meta / --text
            '#f1f5f9' => '#EEF1EE', '#f8fafc' => '#F4F5F3',          // tints → --sunk / --page
        ]);
    }

    /** True when the invoice is for a salt/snow route plan. Never throws. */
    public static function isSnowInvoice(PDO $db, array $invoice): bool
    {
        $planId = (int)($invoice['plan_id'] ?? 0);
        if ($planId <= 0) {
            return false;
        }
        try {
            require_once __DIR__ . '/../../Contracts/Services/SnowContractService.php';
            return (new SnowContractService($db))->isRoutePlan($planId);
        } catch (Throwable $e) {
            error_log('InvoiceSnowTerms: ' . $e->getMessage());
            return false;
        }
    }

    /** @return array{body:string, snapshot:bool, signed_at:?string, template_version:?int}|null */
    public static function forInvoice(PDO $db, array $invoice): ?array
    {
        if (!self::isSnowInvoice($db, $invoice)) {
            return null;
        }
        $planId = (int)($invoice['plan_id'] ?? 0);
        try {
            require_once __DIR__ . '/../../Contracts/Services/ContractTermsService.php';

            $contractId = (int)($invoice['contract_id'] ?? 0);
            if ($contractId <= 0) {
                $s = $db->prepare('SELECT contract_id FROM job_plans WHERE id = ?');
                $s->execute([$planId]);
                $contractId = (int)($s->fetchColumn() ?: 0);
            }
            if ($contractId <= 0) {
                return null;
            }

            // The version the client actually signed: the latest SIGNED signature wins over the
            // contract's current version, so a later unsigned amendment never shows on an invoice
            // as if it had been agreed (Codex 2026-11-19). No signature at all → current version,
            // shown without a signing date.
            $sg = $db->prepare("SELECT contract_version, signed_at FROM contract_signatures
                                 WHERE contract_id = ? AND status = 'signed'
                                 ORDER BY signed_at DESC, id DESC LIMIT 1");
            $sg->execute([$contractId]);
            $signed  = $sg->fetch(PDO::FETCH_ASSOC) ?: null;
            $version = $signed ? (int)$signed['contract_version'] : 0;
            if ($version <= 0) {
                $v = $db->prepare('SELECT current_version FROM contracts WHERE id = ?');
                $v->execute([$contractId]);
                $version = (int)($v->fetchColumn() ?: 0);
            }
            if ($version <= 0) {
                return null;
            }

            $terms = (new ContractTermsService($db))->termsForVersion($contractId, $version);
            if (!$terms || trim((string)$terms['body']) === '') {
                return null;
            }

            // A signing date is claimed only for a sealed snapshot of the version that was signed.
            $signedAt = ($signed && !empty($terms['snapshot'])) ? (string)$signed['signed_at'] : null;

            return [
                'body'             => (string)$terms['body'],
                'snapshot'         => !empty($terms['snapshot']),
                'signed_at'        => $signedAt,
                'template_version' => $terms['template_version'] ?? null,
            ];
        } catch (Throwable $e) {
            error_log('InvoiceSnowTerms: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * The work the customer received, in plain words, from a stored line description
     * ("Salt Only Service - Thu Nov 19, 2026" → ['Salt application', 'Thu, Nov 19, 2026']).
     * The date is returned separately so the invoice shows it once. Unknown wording passes through.
     *
     * @return array{0:string,1:string} [work name, date or '']
     */
    public static function workAndDay(string $description, ?string $serviceDate = null): array
    {
        $day = '';
        if (preg_match('/\s+-\s+((?:Mon|Tue|Wed|Thu|Fri|Sat|Sun) [A-Z][a-z]{2} \d{1,2}, \d{4})\s*$/', $description, $m)) {
            $description = trim(substr($description, 0, -strlen($m[0])));
            $day = date('D, M j, Y', strtotime($m[1]));
        }
        if ($serviceDate !== null && $serviceDate !== '') {
            $day = date('D, M j, Y', strtotime($serviceDate));
        }
        // Only the quote's own boilerplate rate names are translated. Anything with extra
        // wording (e.g. "Salt and hand-shovel front stairs") passes through untouched so the
        // invoice never says less than what was billed (Codex 2026-11-19).
        $key = strtolower(trim($description));
        if ($key === 'salt only service' || $key === 'salted' || $key === 'salt application') {
            $name = 'Salt application';
        } elseif ($key === 'sub -5 degrees arctic freeze daily salt only service' || $key === 'arctic salt (-5°c)') {
            $name = 'Arctic salt application';
        } elseif ($key === 'snow removal' || $key === 'snow cleared') {
            $name = 'Snow clearing';
        } else {
            $name = $description;
        }
        return [$name, $day];
    }

    private static function isHeading(string $p): bool
    {
        return $p !== '' && strlen($p) <= 48 && $p === strtoupper($p);
    }

    /** Section headings and text with clear spacing; ALL-CAPS one-liners are headings. */
    private static function paragraphs(string $text, callable $e): string
    {
        $out = '';
        $first = true;
        foreach (preg_split('/\R{2,}/', trim($text)) ?: [] as $p) {
            $p = trim($p);
            if ($p === '') {
                continue;
            }
            if (self::isHeading($p)) {
                $out .= '<div style="' . ($first ? '' : 'margin-top:6px; ') . 'font-size:6.5pt; font-weight:bold; letter-spacing:0.6px; color:#1e3a5f; border-bottom:0.4pt solid #cbd5e1; padding-bottom:1px; margin-bottom:2px;">' . $e($p) . '</div>';
            } else {
                $out .= '<div style="margin-top:2px;">' . nl2br($e($p)) . '</div>';
            }
            $first = false;
        }
        return $out;
    }

    /**
     * Split a terms body into up to $n balanced columns. A section (an ALL-CAPS heading plus the paragraphs
     * under it) is never split across columns; with no headings every paragraph is its own unit. Joined with
     * a blank line the columns give back the body exactly, so no text is lost. Fewer units than $n → fewer columns.
     *
     * @return string[]
     */
    public static function columns(string $body, int $n = 3): array
    {
        $raw = array_values(array_filter(array_map('trim', preg_split('/\R{2,}/', $body) ?: [$body]), fn($p) => $p !== ''));
        $hasHeading = (bool)array_filter($raw, fn($p) => self::isHeading($p));
        $units = [];
        foreach ($raw as $p) {
            if (!$hasHeading || self::isHeading($p) || !$units) {
                $units[] = [$p];
            } else {
                $units[count($units) - 1][] = $p;
            }
        }
        $n = max(1, min($n, 3, count($units)));
        $weight = function (array $u): int {
            $w = 0;
            foreach ($u as $p) { $w += strlen($p) + 70; }   // every paragraph also costs a line of spacing
            return $w;
        };
        $w = array_map($weight, $units);
        $count = count($units);
        $groups = [range(0, $count - 1)];
        if ($n === 2) {
            $best = null;
            for ($i = 1; $i < $count; $i++) {
                $m = max(array_sum(array_slice($w, 0, $i)), array_sum(array_slice($w, $i)));
                if ($best === null || $m < $best[0]) { $best = [$m, $i]; }
            }
            $groups = [range(0, $best[1] - 1), range($best[1], $count - 1)];
        } elseif ($n === 3) {
            $best = null;
            for ($i = 1; $i < $count - 1; $i++) {
                for ($j = $i + 1; $j < $count; $j++) {
                    $m = max(array_sum(array_slice($w, 0, $i)), array_sum(array_slice($w, $i, $j - $i)), array_sum(array_slice($w, $j)));
                    if ($best === null || $m < $best[0]) { $best = [$m, $i, $j]; }
                }
            }
            $groups = [range(0, $best[1] - 1), range($best[1], $best[2] - 1), range($best[2], $count - 1)];
        }
        $cols = [];
        foreach ($groups as $g) {
            $paras = [];
            foreach ($g as $idx) { $paras = array_merge($paras, $units[$idx]); }
            $cols[] = implode("\n\n", $paras);
        }
        return $cols;
    }

    /** Line under the heading: which agreement this is, only as precisely as we can prove. */
    public static function sourceLine(array $terms): string
    {
        if (!empty($terms['signed_at'])) {
            return 'As agreed in your service contract signed ' . date('F j, Y', strtotime((string)$terms['signed_at'])) . '.';
        }
        return 'The terms of your snow & ice service agreement.';
    }

    /** The block as mPDF-safe HTML (tables, no flex). Empty string when there are no terms. */
    public static function html(?array $terms): string
    {
        if (!$terms) {
            return '';
        }
        $e = function ($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
        $h  = '<table width="100%" cellpadding="0" cellspacing="0" style="margin-top:8px; border:0.8pt solid #cbd5e1; font-size:7pt; color:#475569; line-height:1.35;">';
        $h .= '<tr><td style="background:#f1f5f9; padding:3px 10px;">'
            . '<span style="font-size:8pt; font-weight:bold; color:#1e3a5f; letter-spacing:1px;">SNOW &amp; ICE SERVICE TERMS</span>'
            . '<span style="color:#64748b;"> &nbsp;' . $e(self::sourceLine($terms)) . '</span></td></tr>';
        // Columns (split between paragraphs) so the signed wording fits on the invoice page at a
        // readable size instead of being shrunk.
        $cols = self::columns(trim($terms['body']), 3);
        $w = (int)floor(100 / max(1, count($cols)));
        $h .= '<tr><td style="padding:0;"><table width="100%" cellpadding="0" cellspacing="0"><tr>';
        foreach ($cols as $i => $c) {
            $h .= '<td width="' . $w . '%" style="vertical-align:top; padding:6px ' . ($i === count($cols) - 1 ? '12' : '9') . 'px 6px ' . ($i === 0 ? '12' : '9') . 'px;' . ($i > 0 ? ' border-left:0.4pt solid #e2e8f0;' : '') . '">'
                . self::paragraphs($c, $e) . '</td>';
        }
        $h .= '</tr></table></td></tr>';
        $h .= '</table>';
        // A div (not the table) carries the keep-together: mPDF shrinks a table to fit a page break
        // instead of moving it, which made the signed wording unreadably small.
        return self::ittai('<div style="page-break-inside:avoid;">' . $h . '</div>');
    }
}
