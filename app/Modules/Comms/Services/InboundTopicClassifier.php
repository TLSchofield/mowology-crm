<?php
/**
 * InboundTopicClassifier — which department head owns an inbound customer message (pure).
 *
 * Why (2026-10-08): Alena at Vancouver Management Ltd emailed "EFT Direct deposit form" (VML
 * offers direct deposit; fill in the attached form and email it to their AP person). It showed
 * up under SAM because VML had an open quote and every inbound email counted as a reply to it.
 * Billing mail is Penny's. One shared, cheap classifier now decides, everywhere a message is
 * assigned to a head (SalesInboxService::ingest stamps it; UnclaimedReplyService lanes;
 * SalesDeskService's "they replied" check; the re-route).
 *
 * Topics → heads:
 *   billing   → penny  invoice, statement, remittance, payment, EFT, direct deposit, PAD, void
 *                      cheque, banking form, accounts payable / AP, W-9, T4A, GST number,
 *                      receipt request, overdue, credit memo, refund …
 *   schedule  → otto   scheduling, reschedule, gate / access, missed visit, site problems, damage …
 *   sales     → sam    quote, estimate, proposal, pricing, new work; also a short clear "yes"
 *                      (Gaby's "Yes" to a fall cleanup — a sale to quote, UnclaimedReplyService)
 *   marketing → mia    reviews, testimonials, social, referrals, unsubscribe, newsletter
 *   general   → yui    everything else (client conversations)
 *
 * Rules, in order:
 *   1. A learned rule (Tim's "Move to…", InboundRouteService) for this sender + topic — the
 *      address first, then the business domain.
 *   2. Scores: each topic's words, subject hits weigh SUBJECT_WEIGHT, body hits 1. A billing
 *      sender (accounts@, ap@, payables@, billing@, invoices@ … or a display name "Accounts
 *      Payable") adds SENDER_WEIGHT to billing. Highest score wins; ties go in TIE_ORDER.
 *   3. Nothing scored: a clear short "yes" is Sam's; otherwise general (Yui).
 *
 * No namespace / no autoloader in production: require_once and call statically.
 */
class InboundTopicClassifier
{
    public const HEADS = ['penny', 'sam', 'otto', 'mia', 'yui'];
    public const TOPIC_HEAD = [
        'billing'   => 'penny',
        'sales'     => 'sam',
        'schedule'  => 'otto',
        'marketing' => 'mia',
        'general'   => 'yui',
    ];
    public const HEAD_NAMES = ['penny' => 'Penny', 'sam' => 'Sam', 'otto' => 'Otto', 'mia' => 'Mia', 'yui' => 'Yui'];
    public const SUBJECT_WEIGHT = 2;
    public const SENDER_WEIGHT = 3;
    /** On a tie, the first of these wins (money is never left with sales). */
    public const TIE_ORDER = ['billing', 'sales', 'schedule', 'marketing'];

    /** Mailbox names that are a billing department. */
    public const BILLING_LOCAL = '/^(accounts?|accounting|accountspayable|accounts[._-]?payable|ap|a[._-]?p|payables?|billing|bills?|invoices?|invoicing|remittances?|remit|payments?|finance|receivables?|ar)([._-].*)?$/i';
    /** Display names that are a billing department. */
    public const BILLING_NAME = '/\b(accounts?\s+payable|a\/p|payables|billing|invoic\w*|accounting|remittance)\b/i';

    /** Free-mail domains: never learned as a whole domain. */
    public const FREE_DOMAINS = ['gmail.com', 'hotmail.com', 'outlook.com', 'live.com', 'yahoo.com', 'yahoo.ca', 'icloud.com',
                                 'me.com', 'mac.com', 'shaw.ca', 'telus.net', 'rogers.com', 'msn.com', 'aol.com', 'protonmail.com', 'proton.me'];

    /** @return array<string, string[]> topic => regexes (case-insensitive unless noted) */
    public static function patterns(): array
    {
        return [
            'billing' => [
                '/\binvoic(e|es|ed|ing)\b|\bINV-\d{4}-\d+/i',
                '/\bstatements?\b(?!\s+of\s+work)/i',
                '/\bremit(tance)?s?\b/i',
                '/\bpay(ment|ments|able|ables)\b/i',
                '/\b(paid|unpaid)\b/i',
                '/\bEFTs?\b|\belectronic\s+funds?\s+transfer/i',
                '/\bdirect[\s-]+deposit/i',
                '/\bpre-?authori[sz]ed\s+(debit|payment)/i',
                '/\bvoid(ed)?\s+che(que|ck)\b/i',
                '/\bcheques?\b|\bby\s+check\b/i',
                '/\bbank(ing)?\s+(form|info|information|details|account|letter)\b/i',
                '/\baccounts?\s+payable\b|\baccounts?\s+receivable\b/i',
                '/\bW-?9\b|\bT4A\b|\bT5018\b/i',
                '/\b(GST|HST|business)\s*(number|#|no\.?|registration)\b/i',
                '/\breceipts?\b/i',
                '/\b(overdue|past\s+due|outstanding\s+balance|balance\s+(owing|due|owed))\b/i',
                '/\bcredit\s+(memo|note)s?\b/i',
                '/\brefunds?\b/i',
                '/\b(vendor|supplier)\s+(set\s*-?up|form|registration|onboarding|application)\b/i',
                '/\be-?transfer\b|\binterac\b/i',
            ],
            'billing_cs' => [
                '/\bA\/?P\b/',   // "AP" in capitals only — "ap" is too common a fragment
                '/\bPADs?\b/',   // pre-authorized debit, capitals only
            ],
            'sales' => [
                '/\bquot(e|es|ed|ation)\b|\bQUO-\d{4}-\d+/i',
                '/\bestimates?\b/i',
                '/\bproposals?\b/i',
                '/\b(pricing|price|prices|how\s+much|cost\s+to|costs?\s+for)\b/i',
                '/\bbids?\b|\btender\b/i',
                '/\b(new|additional|extra)\s+(work|project|job|service|contract)\b/i',
                '/\b(interested\s+in|looking\s+for\s+(someone|a\s+company|a\s+landscaper)|would\s+like\s+(a|to\s+get|to\s+have))\b/i',
                '/\b(can|could)\s+you\s+(do|take\s+on|provide|give\s+us)\b/i',
                '/\b(contract\s+renewal|renew\s+(the|our)\s+contract)\b/i',
            ],
            'schedule' => [
                '/\b(re)?schedul\w*\b/i',
                '/\bappointments?\b/i',
                '/\b(what|which)\s+(day|time)\b|\bwhen\s+(will|are)\s+(you|the\s+crew)\b/i',
                '/\b(gate|access|lockbox|key\s*code|gate\s*code|fob|parking)\b/i',
                '/\b(missed|skipped)\b|\bdid(n\'t|\s+not)\s+(come|show|cut|mow)\b|\bnot\s+done\b/i',
                '/\b(damage[ds]?|broke(n)?|leak(ing)?|flood(ed|ing)?|sprinklers?|irrigation)\b/i',
                '/\b(crew|crews|on\s+site|site\s+visit|visit)\b/i',
                '/\b(complain\w*|unhappy|not\s+happy|mess|messy)\b/i',
                '/\b(cancel(led|ed)?\s+(the\s+)?(visit|service|cut)|postpone|rain\s*(check|date)|snow\s+(removal|clearing)|salting)\b/i',
            ],
            'marketing' => [
                '/\breviews?\b|\btestimonials?\b/i',
                '/\b(instagram|facebook|social\s+media|tiktok|linkedin)\b/i',
                '/\b(refer(ral|red|ring)?|recommend(ed)?\s+you)\b/i',
                '/\bunsubscribe\b|\bnewsletter\b|\bmailing\s+list\b/i',
                '/\bsurvey\b/i',
            ],
        ];
    }

    /**
     * @param array{from?: string, from_name?: string, subject?: string, snippet?: string} $m
     * @param array<string, string> $learned  sender_key|topic => head (InboundRouteService::learnedRules())
     * @return array{head: string, topic: string, source: string, reason: string, scores: array<string, int>}
     *         source: 'learned' | 'rule'
     */
    public static function classify(array $m, array $learned = []): array
    {
        $fromHeader = (string)($m['from'] ?? '');
        $addr = self::address($fromHeader);
        $name = trim((string)($m['from_name'] ?? '')) ?: self::displayName($fromHeader);
        $subject = (string)($m['subject'] ?? '');
        $body = (string)($m['snippet'] ?? '');

        [$topic, $scores, $why] = self::topic($subject, $body, $addr, $name);
        $head = self::TOPIC_HEAD[$topic];

        foreach (self::senderKeys($addr) as $sk) {
            $h = $learned[$sk . '|' . $topic] ?? null;
            if ($h !== null && in_array($h, self::HEADS, true)) {
                return ['head' => $h, 'topic' => $topic, 'source' => 'learned',
                        'reason' => 'Tim moved ' . $topic . ' mail from ' . $sk . ' to ' . self::HEAD_NAMES[$h], 'scores' => $scores];
            }
        }
        return ['head' => $head, 'topic' => $topic, 'source' => 'rule', 'reason' => $why, 'scores' => $scores];
    }

    /**
     * The topic alone (no learned rules).
     * @return array{0: string, 1: array<string, int>, 2: string} [topic, scores, reason]
     */
    public static function topic(string $subject, string $body, string $addr = '', string $name = ''): array
    {
        $scores = ['billing' => 0, 'sales' => 0, 'schedule' => 0, 'marketing' => 0];
        $hits = ['billing' => [], 'sales' => [], 'schedule' => [], 'marketing' => []];
        foreach (self::patterns() as $key => $regexes) {
            $topic = $key === 'billing_cs' ? 'billing' : $key;
            foreach ($regexes as $re) {
                $w = 0;
                if (preg_match($re, $subject, $mm)) { $w += self::SUBJECT_WEIGHT; $hits[$topic][] = strtolower(trim($mm[0])); }
                elseif (preg_match($re, $body, $mm)) { $w += 1; $hits[$topic][] = strtolower(trim($mm[0])); }
                $scores[$topic] += $w;
            }
        }
        $senderHint = self::billingSender($addr, $name);
        if ($senderHint !== null) {
            $scores['billing'] += self::SENDER_WEIGHT;
            array_unshift($hits['billing'], $senderHint);
        }

        $best = null;
        foreach (self::TIE_ORDER as $t) {
            if ($scores[$t] > 0 && ($best === null || $scores[$t] > $scores[$best])) $best = $t;
        }
        if ($best === null) {
            if (class_exists('UnclaimedReplyService') && UnclaimedReplyService::isYes($body)) {
                return ['sales', $scores, 'a clear yes to work'];
            }
            return ['general', $scores, 'a client conversation'];
        }
        $words = array_slice(array_values(array_unique($hits[$best])), 0, 3);
        return [$best, $scores, $best . ': ' . implode(', ', $words)];
    }

    /** "accounts@x.ca" / "Accounts Payable <x@y>" → the hint, else null. */
    public static function billingSender(string $addr, string $name = ''): ?string
    {
        $local = (string)strtok(strtolower($addr), '@');
        if ($local !== '' && preg_match(self::BILLING_LOCAL, $local)) return 'sent from ' . $local . '@';
        if ($name !== '' && preg_match(self::BILLING_NAME, $name, $m)) return 'sent by "' . trim($m[0]) . '"';
        return null;
    }

    /** The address rule first, then the business domain ('@vml.ca'); free mail has no domain rule. */
    public static function senderKeys(string $addr): array
    {
        $addr = strtolower(trim($addr));
        if ($addr === '' || strpos($addr, '@') === false) return [];
        $domain = substr(strrchr($addr, '@'), 1);
        $keys = [$addr];
        if ($domain !== '' && !in_array($domain, self::FREE_DOMAINS, true)) $keys[] = '@' . $domain;
        return $keys;
    }

    public static function address(string $header): string
    {
        return preg_match('/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/i', $header, $m) ? strtolower($m[0]) : '';
    }

    public static function displayName(string $header): string
    {
        $n = trim((string)preg_replace('/<[^>]*>|[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/i', '', $header));
        return trim($n, " \t\"'");
    }

    public static function isHead(string $h): bool
    {
        return in_array($h, self::HEADS, true);
    }

    /** The topic a head stands for (used when a message is moved without a stored topic). */
    public static function headTopic(string $head): string
    {
        $t = array_search($head, self::TOPIC_HEAD, true);
        return $t === false ? 'general' : (string)$t;
    }
}
