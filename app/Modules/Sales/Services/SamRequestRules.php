<?php
/** Pure rules for deciding how confidently an inbound message requests work. */
require_once __DIR__ . '/CloserPricing.php';
require_once __DIR__ . '/../../Comms/Services/InboundTopicClassifier.php';

final class SamRequestRules
{
    public const NEGATIONS = [
        "don't need", 'do not need', 'no longer need', 'not this year', "won't need",
        'will not need', 'cancel', 'cancelling', 'canceling', 'stop the', 'skip this',
        'no thanks', 'not interested', "we're good", 'we are good', 'hold off',
    ];

    /** @return array{yes: bool, why: string} */
    public static function isRequest(string $subject, string $text): array
    {
        $topic = InboundTopicClassifier::topic($subject, $text);
        if ($topic[0] === 'sales') return ['yes' => true, 'why' => $topic[2]];

        // Customers commonly ask to "book a cleanup" rather than "book us in".
        // The shared classifier intentionally stays conservative, but here a booking
        // verb plus a service Sam recognises is an unambiguous work request.
        if (preg_match('/(?<![\pL\pN_])book(?:ed|ing)?(?![\pL\pN_])/iu', $text)
            && count(self::services($subject . "\n" . $text)) > 0) {
            return ['yes' => true, 'why' => 'booking request for a recognised service'];
        }
        return ['yes' => false, 'why' => $topic[2]];
    }

    /** @return string[] */
    public static function services(string $text): array
    {
        $parts = preg_split('/\R/', $text, 2);
        $subject = (string)($parts[0] ?? '');
        $words = count($parts) > 1 && InboundTopicClassifier::isReply($subject)
            ? (string)$parts[1]
            : $text;
        $services = CloserPricing::serviceKeys($words);

        // CloserPricing deliberately supports partial words for imported line items,
        // but that makes "hedge" also match "edge" in customer prose.
        if (in_array('edge', $services, true)
            && !preg_match('/(?<![\pL\pN_])edg(?:e|ing)(?![\pL\pN_])/iu', $words)) {
            $services = array_values(array_diff($services, ['edge']));
        }

        // In a customer's words "lawn" and "garden" name the place, not the job (replay 2026-11-05: "aeration for our
        // backyard lawn" read as aeration + mow; "my cedar hedge in my back garden" as hedge + beds + mow). Count mowing
        // and beds only from words that name the work; "trim" names hedge/tree work.
        if (in_array('mow', $services, true)
            && !preg_match('/(?<![\pL\pN_])(?:mow|mows|mowing|mowed|lawn ?(?:cut|cuts|cutting|care)|grass ?cut(?:ting)?)(?![\pL\pN_])/iu', $words)) {
            $services = array_values(array_diff($services, ['mow']));
        }
        if (in_array('beds', $services, true)
            && !preg_match('/(?<![\pL\pN_])(?:beds?|weed(?:s|ing)?|mulch(?:ing)?|garden ?(?:care|maintenance|work))(?![\pL\pN_])/iu', $words)) {
            $services = array_values(array_diff($services, ['beds']));
        }
        if (!in_array('hedge', $services, true) && preg_match('/(?<![\pL\pN_])trim(?:s|med|ming)?(?![\pL\pN_])/iu', $words)) {
            $services[] = 'hedge';
        }

        // Winter work is not in the Closer's (summer) price list, but it is the second most common quote (replay 2026-11-05).
        if (preg_match('/(?<![\pL\pN_])(?:snow|snow ?removal|plow(?:ing)?|plough(?:ing)?|shovel(?:l?ing)?|winter (?:season|service|contract)s?)(?![\pL\pN_])/iu', $words)) {
            $services[] = 'snow';
        }
        if (preg_match('/(?<![\pL\pN_])(?:salt(?:ing)?|pre-?salt(?:ing)?|de-?ic(?:e|ing)|ice melt)(?![\pL\pN_])/iu', $words)) {
            $services[] = 'salting';
        }

        return array_values(array_unique($services));
    }

    public static function negated(string $text): ?string
    {
        foreach (self::NEGATIONS as $phrase) {
            if (preg_match('/(?<![\pL\pN_])' . preg_quote($phrase, '/') . '(?![\pL\pN_])/iu', $text)) {
                return $phrase;
            }
        }
        return null;
    }

    /** @return array{level: string, reasons: string[]} */
    public static function level(array $e): array
    {
        $known = (bool)($e['known_client'] ?? false);
        $properties = (int)($e['properties'] ?? 0);
        $services = array_values((array)($e['services'] ?? []));
        $history = (bool)($e['has_history'] ?? false);
        $negation = $e['negation'] ?? null;
        $request = (bool)($e['is_request'] ?? false);
        $count = count($services);

        $reasons = [];
        $reasons[] = $known ? 'known client' : 'sender not a client';
        if ($properties === 1) {
            $reasons[] = 'one property';
        } elseif ($properties > 1) {
            $reasons[] = $properties . ' properties — which one?';
        } else {
            $reasons[] = 'no property found';
        }
        if ($count === 0) {
            $reasons[] = 'no service named';
        } elseif ($count === 1) {
            $reasons[] = 'asks for: ' . $services[0];
        } elseif ($count === 2) {
            $reasons[] = 'two services named';
        } else {
            $reasons[] = $count . ' services named';
        }
        if ($count === 1) {
            $reasons[] = $history ? 'done before at this property' : 'no history for this service';
        }
        if ($negation !== null) $reasons[] = 'says: ' . $negation;
        if (!$request) $reasons[] = 'not a work request';

        if (!$request || $negation !== null || !$known || $count === 0 || $count > 1) {
            $level = 'not_sure';
        } elseif ($properties === 1 && $history) {
            $level = 'sure';
        } else {
            $level = 'likely';
        }
        return ['level' => $level, 'reasons' => $reasons];
    }
}
