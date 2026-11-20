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
    public const ACCEPTANCE = 'Payment of this invoice confirms acceptance of these terms.';

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

    /** Paragraphs as tight blocks (not blank lines); ALL-CAPS one-liners are headings. */
    private static function paragraphs(string $text, callable $e): string
    {
        $out = '';
        foreach (preg_split('/\R{2,}/', trim($text)) ?: [] as $p) {
            $p = trim($p);
            if ($p === '') {
                continue;
            }
            $isHeading = strlen($p) <= 48 && $p === strtoupper($p);
            $out .= $isHeading
                ? '<div style="margin-top:3px; font-weight:bold; color:#334155;">' . $e($p) . '</div>'
                : '<div style="margin-top:1px;">' . nl2br($e($p)) . '</div>';
        }
        return $out;
    }

    /**
     * Split a terms body into two balanced halves at a paragraph break (blank line).
     *
     * @return array{0:string,1:string}
     */
    public static function twoColumns(string $body): array
    {
        $paras = preg_split('/\R{2,}/', $body) ?: [$body];
        if (count($paras) < 2) {
            return [$body, ''];
        }
        $total = strlen($body) + 70 * count($paras);
        $left = [];
        $acc = 0;
        foreach ($paras as $i => $p) {
            // Stop once the left column holds half the text, always leaving the right at least one paragraph.
            if ($acc >= $total / 2 || $i === count($paras) - 1) {
                break;
            }
            $left[] = $p;
            $acc += strlen($p) + 70;   // each paragraph also costs a line of spacing
        }
        $right = array_slice($paras, count($left));
        return [implode("\n\n", $left), implode("\n\n", $right)];
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
        $h .= '<tr><td style="background:#f1f5f9; padding:5px 10px;">'
            . '<span style="font-size:8pt; font-weight:bold; color:#1e3a5f; letter-spacing:1px;">SNOW &amp; ICE SERVICE TERMS</span>'
            . '<span style="color:#64748b;"> &nbsp;' . $e(self::sourceLine($terms)) . '</span></td></tr>';
        // Two columns (split between paragraphs at the half-way point) so the signed wording fits
        // on the invoice page without shrinking the type.
        [$left, $right] = self::twoColumns(trim($terms['body']));
        $h .= '<tr><td style="padding:0;"><table width="100%" cellpadding="0" cellspacing="0"><tr>'
            . '<td width="50%" style="vertical-align:top; padding:4px 8px 2px 10px;">' . self::paragraphs($left, $e) . '</td>'
            . '<td width="50%" style="vertical-align:top; padding:4px 10px 2px 8px;">' . self::paragraphs($right, $e) . '</td>'
            . '</tr></table></td></tr>';
        $h .= '<tr><td style="padding:3px 10px 7px 10px; font-weight:bold; color:#1e3a5f; font-size:7.5pt;">'
            . $e(self::ACCEPTANCE) . '</td></tr></table>';
        return $h;
    }
}
