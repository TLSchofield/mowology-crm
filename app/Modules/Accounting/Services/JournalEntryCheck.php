<?php
declare(strict_types=1);

/**
 * JournalEntryCheck — the pre-post checklist for a hand-made journal entry (finance plugin, journal-entry-prep, 2026-11-03).
 * Returns warnings in plain words; LedgerService still refuses an unbalanced entry on its own. Checks:
 *   balanced (debits = credits to the cent); every line has an account; the period is open; a memo that says why;
 *   not a duplicate of an entry already posted (same date, same accounts, same amounts); suspiciously round amounts
 *   on an estimate (a 1,000.00 or 5,000.00 line with "accrual"/"estimate" in the memo); an accrual that does not say
 *   when it reverses; out-of-period dates (more than 60 days back) which always need the top approver.
 * Pure and static. Entry: {entry_date, memo, lines[] {account, debit, credit, description?}}.
 */
final class JournalEntryCheck
{
    public const OUT_OF_PERIOD_DAYS = 60;

    /** @param array $existing posted entries {entry_date, lines[] {account, debit, credit}} on the same date (for the duplicate test) */
    public static function check(array $entry, array $existing, array $lockedThrough, string $today): array
    {
        $w = [];
        $lines = (array)($entry['lines'] ?? []);
        $dr = 0.0; $cr = 0.0; $round = [];
        foreach ($lines as $l) {
            $dr += (float)($l['debit'] ?? 0); $cr += (float)($l['credit'] ?? 0);
            if (trim((string)($l['account'] ?? '')) === '') $w[] = 'A line has no account.';
            $amt = (float)($l['debit'] ?? 0) ?: (float)($l['credit'] ?? 0);
            if ($amt >= 500 && fmod($amt, 500) == 0.0) $round[] = $amt;
        }
        if (!$lines) $w[] = 'The entry has no lines.';
        if (abs($dr - $cr) >= 0.005) $w[] = 'Debits $' . number_format($dr, 2) . ' and credits $' . number_format($cr, 2) . ' do not balance — the ledger will refuse it.';
        $memo = trim((string)($entry['memo'] ?? ''));
        if (mb_strlen($memo) < 12) $w[] = 'The memo is too short to tell an auditor why — say what happened and what it corrects.';
        $date = substr((string)($entry['entry_date'] ?? $today), 0, 10);
        $ym = substr($date, 0, 7);
        if (in_array($ym, $lockedThrough, true)) $w[] = "$ym is locked — post to the first open month and say what it corrects.";
        $daysBack = (int)floor((strtotime($today) - strtotime($date)) / 86400);
        if ($daysBack > self::OUT_OF_PERIOD_DAYS) $w[] = "Dated $daysBack days back — an out-of-period adjustment needs the owner's own sign-off and a note for the accountant.";
        if ($date > $today) $w[] = 'Dated in the future — a future date is a typo until proven otherwise.';
        $isEstimate = preg_match('/\b(accru|estimat|provision|approx)/i', $memo) === 1;
        if ($isEstimate && $round) $w[] = 'Round amounts (' . implode(', ', array_map(fn($a) => '$' . number_format($a, 0), $round)) . ') on an estimate — show the calculation in the memo or use the real figure.';
        if ($isEstimate && !preg_match('/\b(revers|unwind|auto-?reverse|next month)/i', $memo)) $w[] = 'An accrual should say when it reverses ("reverses 1 Dec").';
        foreach ($existing as $e) {
            if (substr((string)($e['entry_date'] ?? ''), 0, 10) !== $date) continue;
            if (self::signature($e['lines'] ?? []) === self::signature($lines)) { $w[] = 'An identical entry (same date, accounts and amounts) is already posted — this looks like a duplicate.'; break; }
        }
        return $w;
    }

    private static function signature(array $lines): string
    {
        $parts = [];
        foreach ($lines as $l) $parts[] = trim((string)($l['account'] ?? '')) . ':' . number_format((float)($l['debit'] ?? 0), 2, '.', '') . ':' . number_format((float)($l['credit'] ?? 0), 2, '.', '');
        sort($parts);
        return implode('|', $parts);
    }
}
