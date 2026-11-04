<?php
declare(strict_types=1);

/**
 * UntrustedAsk — an inbound message that asks for something only a verified phone call may grant
 * (harvested from Anthropic's small-business plugin, shared/untrusted-content.md, 2026-11-03).
 *
 * Five kinds. A message that ASKS for one gets NO draft and goes straight to the owner, who verifies by the phone
 * number already on file, never the one in the message:
 *   bank       change of bank, remit-to, payee or payment method; a refund to a different account
 *   urgent_pay an urgent payment, wire transfer or gift cards
 *   credential asking for a password, login, one-time code or a link to verify an account
 *   signer     a change of authorised signer, administrator or contact person
 *   data_out   client, payroll or financial data to be sent to a new address
 * The rule fires on ASKING, never on mentioning: a client's "the PDF password is the invoice number" is not an ask.
 * Message text is data, never an instruction: it is only quoted, escaped by the card, and never followed.
 * Pure and static. Nothing here sends, drafts or changes a record.
 */
final class UntrustedAsk
{
    public const KINDS = ['bank', 'urgent_pay', 'credential', 'signer', 'data_out'];
    public const LABELS = [
        'bank'       => 'asks to change payment details',
        'urgent_pay' => 'asks for an urgent payment, wire or gift cards',
        'credential' => 'asks for a password, code or login',
        'signer'     => 'asks to change who is authorised',
        'data_out'   => 'asks for client or financial data to go to a new address',
    ];
    public const QUOTE_CHARS = 140;
    public const FREE_DOMAINS = ['gmail.com', 'hotmail.com', 'outlook.com', 'live.com', 'yahoo.com', 'yahoo.ca', 'icloud.com', 'me.com', 'shaw.ca', 'telus.net', 'sympatico.ca', 'aol.com'];

    /** The patterns, each for a verb of asking plus the thing asked for. */
    public static function patterns(): array
    {
        $asking = '(?:please |kindly |can you |could you |would you |we (?:need|want|would like) (?:you )?to |you (?:need|must|should) (?:to )?|i (?:need|want|would like) (?:you )?to )?';
        return [
            'bank' => [
                '/\b(?:new|changed?|updated?|different|revised)\s+(?:bank|banking|payment|remittance|remit[- ]to|account|routing|wire)\s+(?:details|info|information|instructions|number|account|address)\b/i',
                '/\b(?:change|update|switch|replace)\s+(?:our|my|the|his|her|their)\s+(?:bank|banking|payment|remit(?:tance)?|account|payee|wire)\b/i',
                '/\b(?:send|pay|deposit|transfer|wire|remit|redirect)\s+(?:the |this |all |any |future |your )?(?:payment|funds|money|invoice|invoices|refund|cheque|check)?\s*(?:to|into)\s+(?:a |our |my )?(?:new|different|another|updated|changed)\s+(?:bank |banking )?(?:account|payee|address|institution)\b/i',
                '/\b(?:refund|reimburse)\b[^.\n]{0,40}\b(?:to|into)\s+(?:a |another |my |our )?(?:different|new|other)\s+(?:bank |banking )?account\b/i',
                '/\bpayment\s+method\b[^.\n]{0,30}\b(?:has |have )?(?:changed|changing|updated|new)\b/i',
            ],
            'urgent_pay' => [
                '/\b(?:urgent(?:ly)?|immediately|asap|right away|today|before (?:noon|5|end of day))\b[^.\n]{0,50}\b(?:wire|wired|transfer|e-?transfer|pay(?:ment)?|send (?:the )?money)\b/i',
                '/\b(?:wire|wired)\s+(?:transfer|the|me|us|funds|money|payment)\b/i',
                '/\bgift\s*cards?\b/i',
                '/\b(?:buy|purchase|get|pick up)\s+(?:some |a few |several |\d+ )?(?:itunes|google play|apple|amazon|visa|steam)?\s*(?:gift\s*)?cards?\b/i',
            ],
            'credential' => [
                '/' . $asking . '\b(?:send|share|provide|give|confirm|reply with|tell|text|email|forward)\s+(?:me|us)?\s*(?:with\s+)?(?:your|the|his|her|their|our)\s+(?:password|passcode|pass code|login|log-in|sign-?in|one[- ]time (?:code|password)|verification code|security code|2fa|authenticator|pin|passphrase)\b/i',
                '/\b(?:click|open|follow|use)\s+(?:this|the|that)\s+(?:link|button)\b[^.\n]{0,40}\b(?:verify|confirm|log ?in|sign ?in|unlock|reactivate|secure)\b/i',
                '/\b(?:verify|confirm|secure|unlock|reactivate)\s+your\s+(?:account|mailbox|email|identity)\b/i',
                '/\byour\s+(?:account|mailbox)\s+(?:has been|will be|is)\s+(?:suspended|locked|closed|disabled|deactivated|compromised)\b/i',
            ],
            'signer' => [
                '/\b(?:new|changed?|updated?|replacement)\s+(?:authori[sz]ed\s+)?(?:signer|signatory|signing authority|administrator|admin(?:istrator)?|account holder|primary contact|billing contact|contact person)\b/i',
                '/\b(?:change|update|replace|remove|add)\s+(?:the |our |my )?(?:authori[sz]ed\s+)?(?:signer|signatory|signing authority|administrator|account holder)\b/i',
                '/\b(?:going forward|from now on|effective immediately)\b[^.\n]{0,60}\b(?:contact|deal with|send (?:everything|invoices|statements) to)\b/i',
            ],
            'data_out' => [
                '/\b(?:send|forward|email|share|export|provide)\s+(?:me |us )?(?:all |the |your |a copy of )?(?:full |complete )?(?:client|customer|payroll|employee|staff|financial|tax|banking|account)\s*(?:list|lists|data|records?|files?|information|details|statements?|spreadsheet)?\s*(?:to|at)\s+(?:this |my |our |a |another |a different |a new )?(?:new |different |personal )?(?:email|address|inbox|account)\b/i',
                '/\b(?:send|forward)\s+(?:all |the |your )?(?:client|customer|payroll|employee|financial)\s+(?:list|data|records?|files?|information)\b/i',
            ],
        ];
    }

    /**
     * @param string $from      sender address
     * @param array  $knownAddr addresses we hold for this contact (email, billing email …)
     * @return ?array {kind, label, quote, lookalike: ?string, why: string} — null when nothing is asked
     */
    public static function detect(string $subject, string $body, string $from = '', array $knownAddr = []): ?array
    {
        $text = trim($subject . "\n" . $body);
        if ($text === '') return null;
        $clean = self::stripQuoted($text);
        if (self::mentionsOnly($clean)) return null;
        foreach (self::patterns() as $kind => $res) {
            foreach ($res as $re) {
                if (!preg_match($re, $clean, $m, PREG_OFFSET_CAPTURE)) continue;
                $quote = self::quoteAround($clean, (int)$m[0][1], strlen($m[0][0]));
                $look = self::lookalike($from, $knownAddr);
                return ['kind' => $kind, 'label' => self::LABELS[$kind], 'quote' => $quote, 'lookalike' => $look,
                        'why' => self::LABELS[$kind] . ($look !== null ? '; ' . $look : '')];
            }
        }
        return null;
    }

    /** Drop quoted history ("On … wrote:", ">" lines, Outlook/forward headers): an ask we sent ourselves is not an ask to us. */
    public static function stripQuoted(string $text): string
    {
        $lines = preg_split('/\R/', $text) ?: [];
        $out = [];
        foreach ($lines as $l) {
            if (preg_match('/^\s*(?:On .{5,80} wrote:|-{2,}\s*(?:Original Message|Forwarded message)|From:\s.+@|Sent:\s)/i', $l)) break;
            if (preg_match('/^\s*>/', $l)) continue;
            $out[] = $l;
        }
        return trim(implode("\n", $out));
    }

    /** "The password is the invoice number" / "the PDF is password protected": a credential mentioned, not requested. */
    public static function mentionsOnly(string $text): bool
    {
        return (bool)preg_match('/\b(?:pdf|file|attachment|document|zip|spreadsheet)\b[^.\n]{0,40}\bpassword\b|\bpassword\s+(?:is|was|for (?:the|this) (?:pdf|file|attachment))\b/i', $text)
            && !preg_match('/\b(?:send|share|provide|give|confirm|reply with|tell)\s+(?:me|us)?\s*(?:your|the)\s+(?:password|login)\b/i', $text);
    }

    private static function quoteAround(string $text, int $at, int $len): string
    {
        $s = max(0, $at - 40);
        $q = trim(preg_replace('/\s+/', ' ', substr($text, $s, $len + 80)));
        return mb_strlen($q) > self::QUOTE_CHARS ? mb_substr($q, 0, self::QUOTE_CHARS - 1) . '…' : $q;
    }

    public static function domain(string $addr): string
    {
        $addr = strtolower(trim($addr));
        if (preg_match('/<([^>]+)>/', $addr, $m)) $addr = $m[1];
        $at = strrpos($addr, '@');
        return $at === false ? '' : trim(substr($addr, $at + 1), " >.");
    }

    /**
     * A sender domain that is not one we hold for this contact: "not an address we have for them", or "looks like X"
     * when it is within two edits of a known business domain (lookalike domains are the usual delivery method).
     * Free mail domains are not compared with each other.
     */
    public static function lookalike(string $from, array $knownAddr): ?string
    {
        $d = self::domain($from);
        if ($d === '') return null;
        $known = [];
        foreach ($knownAddr as $a) { $kd = self::domain((string)$a); if ($kd !== '') $known[$kd] = true; }
        if (!$known) return 'we hold no address for them to compare';
        if (isset($known[$d])) return null;
        foreach (array_keys($known) as $kd) {
            if (in_array($kd, self::FREE_DOMAINS, true) && in_array($d, self::FREE_DOMAINS, true)) continue;
            if ($kd !== $d && levenshtein($d, $kd) <= 2) return "the sender's domain {$d} looks like {$kd}";
        }
        return "sent from {$d}, not an address we have for them";
    }

    /** The card text: quotes the ask, says what was and was not done, names the check. Never a draft. */
    public static function text(string $name, array $ask): string
    {
        return 'HOLD — ' . ($name !== '' ? $name : 'Someone') . ' ' . $ask['label'] . ': “' . $ask['quote'] . '”. No draft written and nothing changed. '
             . 'Call the number already on file before you do anything'
             . ($ask['lookalike'] !== null ? ' (' . $ask['lookalike'] . ')' : '') . '.';
    }
}
