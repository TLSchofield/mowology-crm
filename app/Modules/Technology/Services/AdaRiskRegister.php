<?php
declare(strict_types=1);

/**
 * AdaRiskRegister — the risk-register view of Ada's checks (harvested from Anthropic's operations
 * plugin, risk-assessment, 2026-11-03), pure and static.
 *
 *   level      = the plugin's 3×3: likelihood (red → High, amber → Medium, unknown → Low) × impact
 *                (cost of waiting: money/auth/backups/audit → High, crew in the field → Medium,
 *                the rest → Low) → Critical / High / Medium / Low, exactly its matrix.
 *   states     = Open (what Ada shows today) / Accepted (Tim took the risk, with a reason and a review
 *                date) / Mitigated-Closed (the check went green; the acceptance is released).
 *   rule       = a Critical check can never be accepted (audit-support: a deficiency in a pervasive
 *                control — auth, backups, audit chain, database rights — is a material weakness and
 *                stays in front of the owner).
 *
 * apply() leaves an accepted check on the page (colour kept, moved to "Accepted" with the reason and
 * the review date) but out of the counts, the headline, Charlie's brief and the urgent emails, until
 * its review date passes — then it comes back as before, saying when it was accepted and by whom.
 * Nothing here writes: AdaAcceptedRisks keeps the rows.
 */
final class AdaRiskRegister
{
    public const REVIEW_DAYS = 90;
    public const REASON_MAX = 300;

    public const CRITICAL = 'Critical';
    public const HIGH = 'High';
    public const MEDIUM = 'Medium';
    public const LOW = 'Low';

    /** The plugin's matrix: [likelihood][impact]. */
    public const MATRIX = [
        'High'   => ['High' => 'Critical', 'Medium' => 'High',   'Low' => 'Medium'],
        'Medium' => ['High' => 'High',     'Medium' => 'Medium', 'Low' => 'Low'],
        'Low'    => ['High' => 'Medium',   'Medium' => 'Low',    'Low' => 'Low'],
    ];

    public static function likelihood(string $state): ?string
    {
        if ($state === 'red') return 'High';
        if ($state === 'amber') return 'Medium';
        if ($state === 'unknown') return 'Low';
        return null;   // green / info: not a risk
    }

    /** Impact from the cost of waiting (AdaRules::costRank): 0 money/auth/backups/audit, 1 crew, 2 the rest. */
    public static function impact(int $costRank): string
    {
        return $costRank <= 0 ? 'High' : ($costRank === 1 ? 'Medium' : 'Low');
    }

    /** Critical / High / Medium / Low, or null for a check that is not a problem. */
    public static function level(string $state, int $costRank): ?string
    {
        $l = self::likelihood($state);
        if ($l === null) return null;
        return self::MATRIX[$l][self::impact($costRank)];
    }

    /** Only an open problem below Critical may be accepted. */
    public static function canAccept(array $check): bool
    {
        if (!in_array($check['state'] ?? '', ['red', 'amber', 'unknown'], true)) return false;
        return ($check['level'] ?? self::level((string)$check['state'], (int)($check['cost_rank'] ?? 2))) !== self::CRITICAL;
    }

    public static function reviewBy(string $acceptedAt, int $days = self::REVIEW_DAYS): string
    {
        return date('Y-m-d', strtotime(substr($acceptedAt, 0, 10) . " +{$days} days"));
    }

    /**
     * Stamp every check with cost_rank and level; split the accepted ones out.
     *
     * @param array $checks       Ada's checks (system, state, …)
     * @param array $acceptances  system => {reason, accepted_by_name, accepted_at, review_by}
     * @return array{active: array, accepted: array, released: string[]}
     *         active   = what the page, counts, brief and alerts use
     *         accepted = still a problem, accepted and within its review date (shown apart)
     *         released = systems whose acceptance should be released (the check is green/info now)
     */
    public static function apply(array $checks, array $acceptances, string $today): array
    {
        $active = []; $accepted = []; $released = [];
        foreach ($checks as $c) {
            $rank = AdaRules::costRank((string)($c['system'] ?? ''), (string)($c['group'] ?? ''));
            $c['cost_rank'] = $rank;
            $c['level'] = self::level((string)($c['state'] ?? ''), $rank);
            $c['acceptable'] = self::canAccept($c);
            $a = $acceptances[$c['system']] ?? null;
            if ($a === null) { $active[] = $c; continue; }
            if ($c['level'] === null) { $released[] = (string)$c['system']; $active[] = $c; continue; }   // mitigated: the check cleared
            $due = substr((string)($a['review_by'] ?? ''), 0, 10);
            $who = trim((string)($a['accepted_by_name'] ?? '')) ?: 'you';
            $on = date('M j', strtotime((string)$a['accepted_at']));
            if (!$c['acceptable']) {
                // Accepted while it was a lesser problem; it has since become Critical — back in front of the owner
                // (Codex 2026-11-03). The acceptance stays on record and resumes if it drops back below Critical.
                $c['evidence'] = rtrim((string)$c['evidence'], '.') . " — accepted on {$on} by {$who} (\"" . (string)$a['reason'] . '"), but it has since become Critical, so it is back.';
                $c['accepted_overdue'] = true;
                $active[] = $c;
                continue;
            }
            if ($due !== '' && $due < $today) {
                // Review overdue: back on the list, saying so.
                $c['evidence'] = rtrim((string)$c['evidence'], '.') . " — accepted on {$on} by {$who} (\"" . (string)$a['reason'] . "\"); the review was due " . date('M j', strtotime($due)) . '.';
                $c['accepted_overdue'] = true;
                $active[] = $c;
                continue;
            }
            $c['accepted'] = ['reason' => (string)$a['reason'], 'by' => $who, 'at' => (string)$a['accepted_at'], 'review_by' => $due];
            $accepted[] = $c;
        }
        return ['active' => $active, 'accepted' => $accepted, 'released' => $released];
    }

    /** The plain-words reason check: short, not empty, no secrets-looking strings. Null when fine. */
    public static function reasonProblem(string $reason): ?string
    {
        $reason = trim(preg_replace('/\s+/', ' ', $reason));
        if ($reason === '') return 'Say why the risk is accepted — one line.';
        if (mb_strlen($reason) > self::REASON_MAX) return 'Keep the reason under ' . self::REASON_MAX . ' characters.';
        if (preg_match('/\b[A-Za-z0-9_\-]{32,}\b/', $reason)) return 'That looks like a key or token — never put one in a reason.';
        return null;
    }
}
