<?php
/**
 * CharlieForemanService — puts Charlie's pieces together for the card, the calendar page
 * and the cron: the decision inbox (1172/1173), urgent alerts (1173), deadlines (1171)
 * and the brief (1170). Every piece is optional — a migration not yet run just leaves
 * that piece out. Bad news is gathered first and handed to the brief so it leads.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
require_once __DIR__ . '/CharlieDeskService.php';
require_once __DIR__ . '/CharlieInboxService.php';
require_once __DIR__ . '/CharlieUrgentService.php';
require_once __DIR__ . '/DeadlineService.php';

class CharlieForemanService
{
    private PDO $db;
    private CharlieBriefService $briefs;
    public CharlieDeskService $desk;
    public CharlieInboxService $inbox;
    public CharlieUrgentService $urgent;
    public DeadlineService $deadlines;

    public function __construct(PDO $db, ?CharlieBriefService $briefs = null)
    {
        $this->db = $db;
        $this->briefs = $briefs ?? new CharlieBriefService($db);
        $this->desk = new CharlieDeskService($db, $this->briefs);
        $this->inbox = new CharlieInboxService($db, $this->briefs);
        $this->urgent = new CharlieUrgentService($db);
        $this->deadlines = new DeadlineService($db);
    }

    /**
     * The card's data: the brief (bad news first), the inbox and the urgent list.
     * @return array{view: array, inbox: ?array, urgent: array}
     */
    public function card(string $name): array
    {
        $inbox = null;
        $urgent = [];
        $bad = [];
        if ($this->urgent->ready()) {
            try {
                $urgent = $this->urgent->find();
                foreach ($urgent as $a) $bad[] = $a['text'];
            } catch (Throwable $e) {
                error_log('Charlie urgent: ' . $e->getMessage());
            }
        }
        if ($this->inbox->ready()) {
            try {
                $inbox = $this->inbox->view($name);
                $n = count($inbox['held']);
                if ($n > 0) $bad[] = $n . ' message' . ($n === 1 ? ' is' : 's are') . ' held by your rules — see Decisions.';
            } catch (Throwable $e) {
                error_log('Charlie inbox: ' . $e->getMessage());
            }
        }
        return ['view' => $this->desk->today($name, $bad), 'inbox' => $inbox, 'urgent' => $urgent];
    }

    /**
     * Email the owner about urgent things not yet sent. Returns how many went out.
     * Email only (push isn't confirmed working on production).
     */
    public function sendUrgent(array $owner, string $baseUrl, array $company): array
    {
        if (!$this->urgent->ready()) return ['sent' => 0, 'error' => null];
        $new = $this->urgent->unsent($this->urgent->find());
        if (!$new) return ['sent' => 0, 'error' => null];
        $to = trim((string)($owner['email'] ?? ''));
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL) || !function_exists('sendEmail')) {
            return ['sent' => 0, 'error' => 'Owner has no valid email'];
        }
        $mail = CharlieUrgentService::email($new, CharlieVoice::firstName($owner), $baseUrl);
        $html = class_exists('EmailWrapper') ? EmailWrapper::wrap($mail['body'], 'Open the dashboard', rtrim($baseUrl, '/') . '/crm/dashboard_appstack.php', $company) : $mail['body'];
        $res = sendEmail($to, $mail['subject'], $html, null, 'Charlie at ' . ($company['company_name'] ?? 'Mowology'));
        $err = !empty($res['success']) ? null : (string)($res['error'] ?? 'send failed');
        $this->urgent->record($new, $err);
        return ['sent' => $err === null ? count($new) : 0, 'error' => $err];
    }
}
