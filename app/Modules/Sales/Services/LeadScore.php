<?php
declare(strict_types=1);

/**
 * LeadScore — how warm a new lead is, and which of four buckets it falls in.
 *
 * Sits beside the dollar value Sam already ranks by. Heat is an additive 0–100 made of
 * engagement, fit and urgency (each 0–25) minus a recency penalty for leads we wrote to
 * recently, with every rule that fired named in `reasons`. The model and its numbers are
 * harvested from Anthropic's small-business plugin (lead-triage / speed-to-lead, 2026-11-03);
 * see docs/plans/2026-11-03-sam-lead-score-and-buckets.md for the mapping.
 *
 * Pure and static (no PDO): the desk gathers the signals, this class only reasons about them.
 *
 * Signals (all optional; absent is reported, never scored as zero evidence):
 *   inbound_at       last inbound email/SMS from this contact (sales_messages)
 *   outbound_at      last outbound touch to this contact
 *   outbound_count   outbound touches in the last 30 days
 *   opened_at        last quote open/view (only once a quote exists)
 *   repeat           bool — the contact has an accepted quote before
 *   not_lead         bool — the sender was learned as "not a lead"
 *   available        bool — false when the signal lookup failed (reasons say so)
 */
final class LeadScore
{
    /** Below this spread between the warmest and coolest lead the score has separated nothing. */
    public const FLAT_SPREAD = 10;

    /** A lead worth more than this many times the average accepted quote is hot on value alone. */
    public const BIG_VALUE_FACTOR = 1.5;

    public const BUCKETS = ['hot', 'qualified', 'unclear', 'out_of_scope'];

    /** An inbound message this soon after the lead was created is the enquiry itself, not a reply. */
    public const ENQUIRY_WINDOW_SECONDS = 3600;

    private const URGENT_WORDS = '/\b(urgent(?:ly)?|asap|as soon as possible|this week|deadline|budget (?:is )?approved|right away|immediately|emergency)\b/i';
    private const REFERRAL_WORDS = '/referr|friend|neighbou?r|word.of.mouth|recommend/i';

    /**
     * @param array $lead     quote_requests row: service_types, urgency, project_description, created_at, address, city, source
     * @param array $signals  see class doc
     * @param array $avgByService  service => average accepted amount, plus 'all'
     * @param float $value    the dollar value Sam already computed for this lead (0 when unknown)
     * @param string[] $offered  service tags the business offers (lower-case)
     * @return array{heat:int, engagement:int, fit:int, urgency:int, recency:int, bucket:string, reasons:string[], ask:?string, untouched:bool}
     */
    public static function score(array $lead, array $signals, array $avgByService, float $value, array $offered, string $now): array
    {
        $nowTs = strtotime($now);
        $reasons = [];
        $available = $signals['available'] ?? true;

        // ── Engagement 0–25 ───────────────────────────────────────────────────
        $eng = 0;
        // An inbound message within an hour of the lead's creation IS the enquiry (email leads are
        // made from it), not a reply to us — it earns nothing and is not called a reply.
        $createdTs = strtotime((string)($lead['created_at'] ?? $now)) ?: $nowTs;
        $inboundAt = $signals['inbound_at'] ?? null;
        if ($inboundAt !== null && $inboundAt !== '' && (strtotime($inboundAt) ?: 0) <= $createdTs + self::ENQUIRY_WINDOW_SECONDS) $inboundAt = null;
        $inboundDays = self::daysSince($inboundAt, $nowTs);
        $openedDays = self::daysSince($signals['opened_at'] ?? null, $nowTs);
        $outCount = (int)($signals['outbound_count'] ?? 0);
        if (!$available) {
            $reasons[] = 'no reply data (lookup failed)';
        } elseif ($inboundDays !== null && $inboundDays <= 14) {
            $eng += 15;
            $reasons[] = 'replied ' . self::agoWord($inboundDays);
        } elseif ($openedDays !== null && $openedDays <= 7) {
            $eng += 8;
            $reasons[] = 'opened the quote ' . self::agoWord($openedDays);
        }
        if ($available && $outCount > 3 && ($inboundDays === null || $inboundDays > 30)) {
            $eng -= 5;
            $reasons[] = $outCount . ' touches, no reply';
        }
        $eng = max(0, min(25, $eng));

        // ── Fit 0–25 ──────────────────────────────────────────────────────────
        $services = self::services((string)($lead['service_types'] ?? ''));
        $hasAddress = trim((string)($lead['address'] ?? '')) !== '' || trim((string)($lead['city'] ?? '')) !== '';
        $matchesAvg = $services && self::anyMatch($services, array_keys(array_diff_key($avgByService, ['all' => 1])));
        $matchesOffered = $services && self::anyMatch($services, $offered);
        if ($matchesAvg) {
            $fit = 25;
            $reasons[] = 'work we do and have priced';
        } elseif ($matchesOffered) {
            $fit = 15;
            $reasons[] = 'work we do';
        } elseif (!$services && $hasAddress) {
            $fit = 12;
            $reasons[] = 'address known, service not said';
        } else {
            $fit = 5;
            $reasons[] = $services ? 'not work we offer' : 'no service or address yet';
        }

        // ── Urgency 0–25 ──────────────────────────────────────────────────────
        $urg = 0;
        $flag = strtolower((string)($lead['urgency'] ?? ''));
        $desc = (string)($lead['project_description'] ?? '');
        $urgentNow = $flag === 'asap' || preg_match(self::URGENT_WORDS, $desc) === 1;
        if ($urgentNow) {
            $urg += 15;
            $reasons[] = $flag === 'asap' ? 'wants it asap' : 'says it is urgent';
        } elseif ($flag === 'soon') {
            $urg += 10;
            $reasons[] = 'wants it soon';
        }
        $ageDays = max(0.0, ($nowTs - strtotime((string)($lead['created_at'] ?? $now))) / 86400);
        if ($ageDays >= 7 && $ageDays <= 21) {
            $urg += 10;
            $reasons[] = (int)floor($ageDays) . ' days old — follow-up window';
        } elseif ($ageDays < 7) {
            $urg += 5;
        } elseif ($ageDays > 60) {
            $urg -= 5;
            $reasons[] = 'over 60 days old';
        }
        $urg = max(0, min(25, $urg));

        // ── Recency penalty (we wrote to them recently) ───────────────────────
        $rec = 0;
        $outDays = self::daysSince($signals['outbound_at'] ?? null, $nowTs);
        if ($outDays !== null) {
            if ($outDays < 1) { $rec = 25; $reasons[] = 'we wrote to them today'; }
            elseif ($outDays <= 3) { $rec = 10; $reasons[] = 'we wrote to them ' . self::agoWord($outDays); }
            elseif ($outDays <= 7) { $rec = 5; $reasons[] = 'we wrote to them ' . self::agoWord($outDays); }
        }

        $heat = max(0, min(100, $eng + $fit + $urg - $rec));

        // ── Bucket ────────────────────────────────────────────────────────────
        $repeat = !empty($signals['repeat']);
        $referral = preg_match(self::REFERRAL_WORDS, (string)($lead['source'] ?? '')) === 1;
        $avgAll = (float)($avgByService['all'] ?? 0);
        $bigValue = $avgAll > 0 && $value > self::BIG_VALUE_FACTOR * $avgAll;
        if ($repeat) $reasons[] = 'repeat customer';
        if ($referral) $reasons[] = 'referral';
        if ($bigValue) $reasons[] = 'well above our average job';

        $ask = null;
        if (!empty($signals['not_lead']) || ($services && !$matchesAvg && !$matchesOffered)) {
            $bucket = 'out_of_scope';
            $ask = self::declineText($lead);
        } elseif ($urgentNow || $repeat || $referral || $bigValue) {
            $bucket = 'hot';                       // urgency beats fit; torn → the more attentive bucket
        } elseif ($fit >= 15) {
            $bucket = 'qualified';
        } else {
            $bucket = 'unclear';
            $ask = self::oneQuestion($lead, $services !== [], $hasAddress);
        }

        return [
            'heat'       => $heat,
            'engagement' => $eng,
            'fit'        => $fit,
            'urgency'    => $urg,
            'recency'    => $rec,
            'bucket'     => $bucket,
            'reasons'    => $reasons,
            'ask'        => $ask,
            'untouched'  => $available && $outCount === 0 && ($signals['outbound_at'] ?? null) === null,
        ];
    }

    /**
     * Order scored leads. By heat (then value, then newest) when the heat has separated them;
     * otherwise by what the CRM does know — never present a flat score as a ranking.
     * Each item needs: heat, value, created_at, urgency, untouched.
     * @return array{ranked_by: 'heat'|'signal', items: array}
     */
    public static function order(array $items): array
    {
        if (!$items) return ['ranked_by' => 'heat', 'items' => []];
        $heats = array_map(fn($i) => (int)$i['heat'], $items);
        $flat = count($items) > 1 && (max($heats) - min($heats)) < self::FLAT_SPREAD;
        $urgRank = ['asap' => 0, 'soon' => 1];
        if ($flat) {
            usort($items, function ($a, $b) use ($urgRank) {
                $t = (int)!($a['untouched'] ?? false) <=> (int)!($b['untouched'] ?? false);   // untouched first
                if ($t !== 0) return $t;
                $u = ($urgRank[(string)$a['urgency']] ?? 2) <=> ($urgRank[(string)$b['urgency']] ?? 2);
                if ($u !== 0) return $u;
                $v = (float)$b['value'] <=> (float)$a['value'];
                if ($v !== 0) return $v;
                return strcmp((string)$b['created_at'], (string)$a['created_at']);
            });
            return ['ranked_by' => 'signal', 'items' => $items];
        }
        usort($items, function ($a, $b) {
            $h = (int)$b['heat'] <=> (int)$a['heat'];
            if ($h !== 0) return $h;
            $v = (float)$b['value'] <=> (float)$a['value'];
            if ($v !== 0) return $v;
            return strcmp((string)$b['created_at'], (string)$a['created_at']);
        });
        return ['ranked_by' => 'heat', 'items' => $items];
    }

    /** The one question whose answer sorts an unclear lead: what, where, or when. */
    public static function oneQuestion(array $lead, bool $hasService, bool $hasAddress): string
    {
        $first = trim((string)($lead['first_name'] ?? ''));
        $hi = 'Hi' . ($first !== '' ? ' ' . $first : '') . ', thanks for getting in touch. ';
        if (!$hasService) return $hi . 'What work are you after — mowing, garden beds, hedges, a clean-up, or something else?';
        if (!$hasAddress) return $hi . 'Where is the property? A street and city is enough for me to see if we are in your area.';
        return $hi . 'When were you hoping to have it done?';
    }

    /** An honest, short "not a fit" — still answered, in a small market that comes back as referrals. */
    public static function declineText(array $lead): string
    {
        $first = trim((string)($lead['first_name'] ?? ''));
        return 'Hi' . ($first !== '' ? ' ' . $first : '') . ', thanks for asking. That is not work we do, so I would rather say so '
            . 'than keep you waiting. If you would like, tell me a little more and I can point you to someone who does.';
    }

    /** @return string[] lower-case service tags, underscores and hyphens as spaces */
    public static function services(string $csv): array
    {
        $out = [];
        foreach (explode(',', strtolower($csv)) as $s) {
            $s = trim(str_replace(['_', '-'], ' ', $s));
            if ($s !== '') $out[] = $s;
        }
        return $out;
    }

    /** Substring match either way, the same loose rule Sam's value lookup uses. */
    private static function anyMatch(array $services, array $candidates): bool
    {
        foreach ($services as $s) {
            foreach ($candidates as $c) {
                $c = trim(str_replace(['_', '-'], ' ', strtolower((string)$c)));
                if ($c === '' || $s === '') continue;
                if (strpos($c, $s) !== false || strpos($s, $c) !== false) return true;
            }
        }
        return false;
    }

    private static function daysSince(?string $at, int $nowTs): ?float
    {
        if ($at === null || $at === '') return null;
        $ts = strtotime($at);
        if ($ts === false || $ts > $nowTs) return null;   // a future stamp is corrupt, not fresh
        return ($nowTs - $ts) / 86400;
    }

    private static function agoWord(float $days): string
    {
        if ($days < 1) return 'today';
        if ($days < 2) return 'yesterday';
        return (int)floor($days) . ' days ago';
    }
}
