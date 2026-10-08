<?php
/**
 * PennyBriefAdapter — Penny's brief for Charlie, built read-only from her own services.
 *
 * Penny has no brief() (her files belong to another session), so Charlie reads
 * BookkeeperDeskService::stats() and PennyQuestionService::open(). Both are plain
 * SELECTs. Never call scan() or prepare() from here: those write, and prepare() spends
 * AI credit.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
class PennyBriefAdapter
{
    /** GST below this isn't worth Tim's morning. */
    public const GST_FLOOR = 50.0;
    public const BIG_QUESTION = 200.0;
    public const BIG_BACKLOG = 10;

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function brief(string $ownerFirstName = ''): array
    {
        $brief = $this->books($ownerFirstName);
        // Clues spotted in money (a strata plan paying a building on file as a house) — priority 2.
        try {
            require_once APP_ROOT . '/Modules/Comms/Services/ClueService.php';
            $brief = ClueService::mergeBrief($brief, (new ClueService($this->db))->briefItems(ClueService::HEAD_PENNY));
        } catch (Throwable $e) {
            error_log('Penny brief (clues): ' . $e->getMessage());
        }
        // Last month's statements not in yet (from the 3rd) — read from the once-a-day check.
        try {
            require_once APP_ROOT . '/Modules/Accounting/Services/StatementCoverageService.php';
            $sc = new StatementCoverageService($this->db);
            if ($sc->ready()) {
                $items = StatementCoverageService::briefItems($sc->latest(), date('Y-m-d'));
                if ($items) {
                    $brief['items'] = array_merge((array)($brief['items'] ?? []), $items);
                    $brief['count'] = (int)($brief['count'] ?? 0) + count($items);
                }
            }
        } catch (Throwable $e) {
            error_log('Penny brief (statements): ' . $e->getMessage());
        }
        return $brief;
    }

    private function books(string $ownerFirstName): array
    {
        $svc = APP_ROOT . '/Modules/Expenses/Services/BookkeeperDeskService.php';
        $q = APP_ROOT . '/Modules/Expenses/Services/PennyQuestionService.php';
        if (!is_file($svc)) return self::fromStats(null, []);
        require_once $svc;
        $desk = new BookkeeperDeskService($this->db);
        if (!$desk->ready()) return self::fromStats(null, []);
        $stats = $desk->stats(null);
        $questions = [];
        if (is_file($q)) {
            require_once $q;
            $pq = new PennyQuestionService($this->db);
            if ($pq->ready()) $questions = $pq->open(5, $ownerFirstName);
        }
        return self::fromStats($stats, $questions);
    }

    /** Pure: Penny's numbers and open questions → the shared brief shape. */
    public static function fromStats(?array $stats, array $questions): array
    {
        $items = [];
        if ($stats) {
            $ready = (int)($stats['ready'] ?? 0);
            $drafts = (int)($stats['drafts'] ?? 0);
            $gst = (float)($stats['gst_stuck'] ?? 0);
            if ($ready > 0) {
                $items[] = ['key' => 'penny:receipts_ready', 'kind' => 'penny:receipts_ready', 'priority' => $ready >= self::BIG_BACKLOG ? 1 : 2,
                            'text' => $ready . ' receipt' . ($ready === 1 ? ' is' : 's are') . ' ready for you to approve',
                            'url' => '/crm/dashboard_appstack.php#mw-penny', 'value' => null];
            } elseif ($drafts > 0) {
                $items[] = ['key' => 'penny:drafts', 'kind' => 'penny:drafts', 'priority' => 3,
                            'text' => $drafts . ' draft receipt' . ($drafts === 1 ? '' : 's') . ' waiting in the backlog',
                            'url' => '/crm/expenses_appstack.php', 'value' => null];
            }
            if ($gst >= self::GST_FLOOR) {
                $items[] = ['key' => 'penny:gst_stuck', 'kind' => 'penny:gst_stuck', 'priority' => 2,
                            'text' => '$' . number_format($gst, 0) . ' of GST to claim back is sitting in unapproved receipts',
                            'url' => '/crm/dashboard_appstack.php#mw-penny', 'value' => round($gst, 2)];
            }
        }
        foreach ($questions as $q) {
            $amount = (float)($q['amount'] ?? 0);
            $items[] = ['key' => 'penny:question:' . (int)$q['id'], 'kind' => 'penny:unbilled', 'priority' => $amount >= self::BIG_QUESTION ? 1 : 2,
                        'text' => self::plainQuestion((string)($q['question'] ?? '')),
                        'url' => '/crm/dashboard_appstack.php#mw-pq', 'value' => round($amount, 2)];
        }
        $n = count($questions);
        $ready = (int)($stats['ready'] ?? 0);
        $bits = [];
        if ($ready > 0) $bits[] = $ready . ' receipt' . ($ready === 1 ? '' : 's') . ' ready';
        if ($n > 0) $bits[] = $n . ' billing question' . ($n === 1 ? '' : 's');
        return [
            'head'     => 'penny',
            'headline' => $bits ? ucfirst(implode(', ', $bits)) : 'The books are up to date',
            'items'    => $items,
            'count'    => count($items),
        ];
    }

    /** Penny's question without her greeting: Charlie does the greeting. */
    public static function plainQuestion(string $q): string
    {
        $q = preg_replace('/^Hey[^—]*—\s*/u', '', trim($q));
        $q = preg_replace('/\bI can\'t find\b/', "Penny can't find", $q);   // Charlie is the one speaking now
        return $q === '' ? 'Penny has a billing question' : mb_strtoupper(mb_substr($q, 0, 1)) . mb_substr($q, 1);
    }
}
