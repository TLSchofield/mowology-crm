<?php
declare(strict_types=1);

/**
 * The one thing the first-week dashboard puts on the first screen, with its one button.
 * Chosen in this order, first that applies wins (approved by Tim 2026-11-20):
 *   no clients yet            -> add your first client
 *   clients but no quotes     -> write your first quote
 *   a quote waiting an answer -> follow up
 *   visits today              -> open today's schedule
 *   otherwise                 -> all caught up
 * pick() is pure; gather() reads the counts (any failed count reads as zero, so the page never breaks).
 */
final class FirstWeekFocus
{
    /**
     * @param array{clients?: int, quotes?: int, quotes_waiting?: int, visits_today?: int} $facts
     * @param array{clients?: bool, quotes?: bool, schedule?: bool} $can what this login may open (missing = allowed);
     *        a step the login can't open is skipped, and the button is dropped (href '') when nothing it can open fits
     * @return array{key: string, headline: string, detail: string, button: string, href: string}
     */
    public static function pick(array $facts, array $can = []): array
    {
        $canClients  = $can['clients']  ?? true;
        $canQuotes   = $can['quotes']   ?? true;
        $canSchedule = $can['schedule'] ?? true;
        $clients = (int)($facts['clients'] ?? 0);
        $quotes  = (int)($facts['quotes'] ?? 0);
        $waiting = (int)($facts['quotes_waiting'] ?? 0);
        $today   = (int)($facts['visits_today'] ?? 0);

        if ($clients === 0 && $canClients) {
            return ['key' => 'add_client', 'headline' => 'Add your first client',
                'detail' => 'Everything starts with a client: quotes, jobs and invoices all hang off them.',
                'button' => 'Add a client', 'href' => '/crm/clients_appstack.php?action=new'];
        }
        if ($clients > 0 && $quotes === 0 && $canQuotes) {
            return ['key' => 'first_quote', 'headline' => 'Write your first quote',
                'detail' => 'Pick a client, add what you will do and the price, then send it.',
                'button' => 'New quote', 'href' => '/crm/quotes/create.php'];
        }
        if ($waiting > 0 && $canQuotes) {
            return ['key' => 'follow_up', 'headline' => $waiting === 1 ? 'A quote is waiting for an answer' : $waiting . ' quotes are waiting for an answer',
                'detail' => 'A quick follow-up is often what turns a quote into a job.',
                'button' => 'Follow up', 'href' => '/crm/quotes/index.php?status=sent'];
        }
        if ($today > 0 && $canSchedule) {
            return ['key' => 'today', 'headline' => $today === 1 ? 'You have 1 visit today' : 'You have ' . $today . ' visits today',
                'detail' => 'See who is going where, and when.',
                'button' => 'Open today\'s schedule', 'href' => '/crm/jobs/schedule.php'];
        }
        return ['key' => 'caught_up', 'headline' => 'You\'re all caught up',
            'detail' => $canQuotes ? 'Nothing needs you right now. A new quote is the next step towards more work.' : 'Nothing needs you right now.',
            'button' => $canQuotes ? 'New quote' : '', 'href' => $canQuotes ? '/crm/quotes/create.php' : ''];
    }

    /** "Good morning, Sam." (first name only; no name, no comma). */
    public static function greeting(string $fullName, int $hour): string
    {
        $part = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
        $first = trim((string)(preg_split('/\s+/', trim($fullName))[0] ?? ''));
        return $first === '' ? $part . '.' : $part . ', ' . $first . '.';
    }

    /**
     * Counts for pick(). A failed client or quote count throws (the caller then shows the full dashboard rather
     * than a misleading "add your first client"); only today's visits may read as zero when that table is absent.
     * @return array{clients: int, quotes: int, quotes_waiting: int, visits_today: int}
     */
    public static function gather(PDO $db): array
    {
        $count = static fn(string $sql): int => (int)$db->query($sql)->fetchColumn();
        $visits = 0;
        try {
            $visits = $count("SELECT COUNT(*) FROM job_visits WHERE scheduled_date = CURDATE() AND status IN ('scheduled','in_progress')");
        } catch (Throwable $e) { /* no visits table yet */ }
        return [
            'clients'        => $count("SELECT COUNT(*) FROM contacts"),
            'quotes'         => $count("SELECT COUNT(*) FROM quotes"),
            'quotes_waiting' => $count("SELECT COUNT(*) FROM quotes WHERE status = 'sent'"),
            'visits_today'   => $visits,
        ];
    }
}
