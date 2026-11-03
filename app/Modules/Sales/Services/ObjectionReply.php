<?php
declare(strict_types=1);

/**
 * ObjectionReply — what a reply really says, and a 3–5 sentence answer in the right shape
 * (sales plugin, handle-objection, harvested 2026-11-03). Six types: price/value, timing, competitor, risk/trust,
 * authority ("need to check with…"), status quo. An objection (a reason not to buy) is not a negotiation move (a reason to
 * buy cheaper) — price-shopping with intent to buy is 'negotiation'. Reply shape: acknowledge first; answer with the
 * client's own stated goal and ONE proof point; end with a forward question. The don't list: no over-discounting, no
 * feature dump, no arguing. Only approved proof points go in (the caller passes them). Pure and static — a draft, never sent.
 */
final class ObjectionReply
{
    private const PATTERNS = [
        'negotiation' => '/\b(best price|match|discount|cheaper if|knock|any room|lower price|price break)\b/i',
        'price'       => '/\b(too (?:much|expensive|high|steep)|can\'?t afford|over (?:our|my) budget|pric(?:e|ey)|expensive|cost)\b/i',
        'timing'      => '/\b(not (?:right )?now|next (?:year|season|spring|month)|later|hold off|wait until|busy|after)\b/i',
        'competitor'  => '/\b(another (?:company|quote|guy|landscaper)|other quotes?|someone else|went with|already have (?:a|someone))\b/i',
        'authority'   => '/\b(check with|ask my|run it by|my (?:wife|husband|partner|boss|strata|council|board)|the board|committee)\b/i',
        'risk'        => '/\b(not sure|worried|guarantee|what if|trust|reviews?|references?|insur(?:ed|ance)|damage)\b/i',
        'status_quo'  => '/\b(doing it (?:myself|ourselves)|we manage|happy as (?:is|we are)|don\'?t need|fine for now|current (?:guy|company))\b/i',
    ];

    /** @return array{type:string, confidence:string} */
    public static function classify(string $text): array
    {
        foreach (self::PATTERNS as $type => $re) if (preg_match($re, $text)) return ['type' => $type, 'confidence' => 'pattern'];
        return ['type' => 'unclear', 'confidence' => 'none'];
    }

    /**
     * @param array $ctx {first_name?, goal? (the client's own stated goal), proof? (one approved proof point), service?, signoff}
     */
    public static function draft(string $type, array $ctx): string
    {
        $first = trim((string)($ctx['first_name'] ?? '')) ?: 'there';
        $goal = trim((string)($ctx['goal'] ?? '')) ?: 'what you told me you wanted from the property';
        $proof = trim((string)($ctx['proof'] ?? ''));
        $service = trim((string)($ctx['service'] ?? 'the work'));
        $signoff = (string)($ctx['signoff'] ?? '');
        $proofLine = $proof !== '' ? " $proof" : '';
        switch ($type) {
            case 'price':
                $body = "Thanks for being straight about the price. The quote is built for $goal, and I would rather trim the scope with you than cut corners on it.$proofLine Which part matters most to you — if we started there, what would the right first step look like?";
                break;
            case 'negotiation':
                $body = "Happy to look at it with you. The price reflects $goal done properly, so instead of a discount I can show you where scope could come out without hurting the result.$proofLine Is it the total or the timing of payments that you would like to move?";
                break;
            case 'timing':
                $body = "No problem at all — the timing has to be right for you. For $service the best window is usually ahead of the season, so booking the slot now and starting later costs nothing.$proofLine When would you like me to check back?";
                break;
            case 'competitor':
                $body = "That makes sense; you should compare. The one thing I would ask is that the quotes cover the same scope, because that is where most of the difference hides.$proofLine Would it help if I marked up what is and is not in mine so you can line them up?";
                break;
            case 'authority':
                $body = "Of course — take whatever time that needs. If it helps, I can put the key points on one page for them: what is included, the price, and when it would happen.$proofLine Would a short summary make that conversation easier?";
                break;
            case 'risk':
                $body = "That is a fair thing to want to know before you commit. We are insured, and the quote says exactly what we do and when.$proofLine What would you need to see to feel comfortable going ahead?";
                break;
            case 'status_quo':
                $body = "Understood — if what you have is working, that is a good reason not to change. The quote is here whenever that changes, and I will not keep chasing.$proofLine Is there anything about the current arrangement you would change if you could?";
                break;
            default:
                $body = "Thanks for getting back to me. I want to make sure I have understood — could you tell me a little more about what is giving you pause?";
        }
        return "Hi $first,\n\n$body" . ($signoff !== '' ? "\n\n$signoff" : '');
    }
}
