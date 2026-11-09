<?php
require_once dirname(__DIR__, 3) . '/Services/Messaging/IdentityMail.php';

/**
 * MiaDeskService — Mia's desk on the dashboard: what she suggests, what Tim does with it,
 * and what came of it.
 *
 *   prepare()  find who's worth getting back in touch with (MiaFinder), draft a message for
 *              each (MiaWording), keep up to MAX_OPEN waiting; ask questions (MiaQuestionService);
 *              settle the results of earlier messages. Lazy: at most once every few hours.
 *   queue()    the waiting suggestions, for the card's carousel.
 *   decide()   Tim sends (as written or edited), skips with a reason, or snoozes. Mia NEVER
 *              sends on her own: a send only happens from Tim's click, and consent and Sam's
 *              open quotes are checked again at that moment. Sending waits for the person's
 *              best time (SendTimeService, migration 1437) unless Tim says now; sendDue()
 *              (campaign_sender cron) sends it then, checking consent and Sam once more.
 *              Payment thank-yous (MiaThanksService) are drafted here too, first in line.
 *   stats()    the card's numbers, including how the automatic review requests are doing.
 *   brief()    read-only summary for Charlie (Chief of Staff).
 *
 * Consent is a gate in the send path itself: ConsentLedgerService::allows() is asked right
 * before every send, and a message only goes in the CRM's branded wrapper that names the
 * sender and carries a working unsubscribe link (MiaWording::compose() refuses otherwise).
 * Email only: marketing texts are off in v1 — a text can't carry an unsubscribe link (the
 * carrier gateways drop links) and replies don't reach us. Logged in communication_log.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
require_once __DIR__ . '/MiaFinder.php';
require_once __DIR__ . '/MiaWording.php';
require_once __DIR__ . '/MiaQuestionService.php';
require_once __DIR__ . '/MiaThanksService.php';
require_once __DIR__ . '/SendTimeService.php';
require_once __DIR__ . '/TrackingLinks.php';
require_once dirname(__DIR__, 2) . '/Accounting/Services/EtransferNudgeService.php';
require_once dirname(__DIR__, 2) . '/Consent/Services/ConsentLedgerService.php';

class MiaDeskService
{
    public const MAX_OPEN = 8;
    public const EXPIRE_DAYS = 21;
    public const PREPARE_EVERY_HOURS = 4;
    public const SKIPS = ['not_fit' => 365, 'talked' => 90, 'not_now' => 60, 'never' => null];

    private PDO $db;
    private MiaFinder $finder;
    private ConsentLedgerService $ledger;
    private ?bool $queueReady = null;

    public const WORKS_MIN = 3;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->finder = new MiaFinder($db);
        $this->ledger = new ConsentLedgerService($db);
    }

    /** Both migrations: 1160 (Mia) and 1161 (the consent ledger she can't work without). */
    public function ready(): bool
    {
        try {
            return $this->db->query("SHOW TABLES LIKE 'mia_suggestions'")->fetchColumn() !== false
                && $this->ledger->ready();
        } catch (Throwable $e) {
            return false;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Preparing suggestions
    // ─────────────────────────────────────────────────────────────────────────

    /** @return array{created: int, questions: int, settled: int, skipped_run: bool} */
    public function prepare(bool $force = false, ?DateTimeImmutable $today = null): array
    {
        $today = $today ?? new DateTimeImmutable('today');
        if (MiaThanksService::enabled($this->db)) {
            $thanks = new MiaThanksService($this->db);
            $thanks->sweep($today);
            $thanks->tidy(new DateTimeImmutable());
        }
        $last = $this->finder->setting('mia_last_prepare', 0);
        if (!$force && $last > time() - self::PREPARE_EVERY_HOURS * 3600) {
            return ['created' => 0, 'questions' => 0, 'settled' => 0, 'skipped_run' => true];
        }
        $this->setSetting('mia_last_prepare', (string)time());

        $this->db->prepare("UPDATE mia_suggestions SET status = 'expired' WHERE status = 'open' AND created_at < ?")
            ->execute([$today->modify('-' . self::EXPIRE_DAYS . ' days')->format('Y-m-d')]);
        $settled = $this->settleOutcomes(new DateTimeImmutable());
        $this->ledger->refresh(); // backfill consent from the latest jobs, invoices and opt-ins

        $recent = $this->db->query("SELECT status, skip_reason FROM mia_suggestions WHERE kind = 'reconnect' AND status IN ('sent', 'skipped') ORDER BY decided_at DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
        $months = MiaFinder::tunedLapse($this->finder->setting('mia_lapsed_months', 12), $recent);
        $before = $this->finder->setting('mia_seasonal_before_days', 21);
        $after  = $this->finder->setting('mia_seasonal_after_days', 30);

        $candidates = [];
        foreach ([
            fn() => $this->finder->seasonal($today, $before, $after),
            fn() => $this->finder->pmQuiet($today),
            fn() => $this->finder->reconnect($months, $today),
            fn() => $this->finder->referral($today),
            fn() => $this->finder->consentAsk($this->ledger->expiringSoon($today, 6)),
        ] as $find) {
            try {
                $candidates = array_merge($candidates, $find());
            } catch (Throwable $e) {
                error_log('Mia finder: ' . $e->getMessage());
            }
        }
        $f = MiaFinder::filter(
            $candidates,
            $this->finder->samOwns($today),
            $this->finder->current($today),
            $this->finder->leaveAlone($today),
            $this->ledger->bulk(array_column($candidates, 'contact_id'), 'email', $today)
        );

        $pmNoContact = [];
        $created = 0;
        $room = self::MAX_OPEN - (int)$this->db->query("SELECT COUNT(*) FROM mia_suggestions WHERE status = 'open' AND kind <> 'payment_thanks'")->fetchColumn();
        foreach ($f['keep'] as $c) {
            if (!empty($c['needs_contact'])) {
                $pmNoContact[] = ['company_id' => $c['company_id'], 'company_name' => $c['company_name']] + $c['reason'];
                continue;
            }
            if ($room <= 0) continue;
            $draft = $this->draft($c, $today);
            $this->db->prepare("
                INSERT INTO mia_suggestions (kind, subject_key, contact_id, company_id, property_id, reason_json, draft_subject, draft_body, draft_sms)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                $c['kind'], $c['subject_key'], $c['contact_id'], $c['company_id'], $c['property_id'],
                json_encode($c['reason'] + ['value' => $c['value'], 'priority' => $c['priority'], 'sms_ok' => !empty($c['sms_ok'])]),
                $draft['subject'], $draft['body'], $draft['sms'],
            ]);
            $created++;
            $room--;
        }

        $questions = (new MiaQuestionService($this->db))->scan($today, $pmNoContact);
        try {
            require_once __DIR__ . '/MiaCampaignService.php';
            (new MiaCampaignService($this->db))->propose($today); // a proposal only — Tim approves
        } catch (Throwable $e) {
            error_log('Mia campaign proposal: ' . $e->getMessage());
        }
        return ['created' => $created, 'questions' => $questions, 'settled' => $settled, 'skipped_run' => false];
    }

    /** Message vars for one candidate (also used to learn Tim's wording back). */
    public function vars(array $c, DateTimeImmutable $today): array
    {
        $contact = $c['contact'] ?? [];
        $service = MiaWording::serviceLabel($c['reason']['service'] ?? null);
        $v = [
            'first_name'    => trim((string)($contact['first_name'] ?? '')) ?: 'there',
            'place'         => trim((string)($c['address'] ?? '')) ?: 'your property',
            'company'       => (string)($c['company_name'] ?? $c['reason']['company'] ?? ''),
            'service'       => $service,
            'service_lower' => strtolower($service),
            'last_when'     => MiaWording::when($c['reason']['last_done'] ?? null, $today),
            'season_line'   => MiaWording::seasonLine((int)$today->format('n')),
            'prior_visits'  => (string)($c['reason']['prior_visits'] ?? ''),
            'recent_visits' => (string)($c['reason']['recent_visits'] ?? ''),
            'consent_until' => !empty($c['reason']['consent_until']) ? date('F Y', strtotime($c['reason']['consent_until'])) : '',
        ];
        if ($c['kind'] === 'referral' && !empty($c['contact_id'])) {
            [$v['referral_link'], $v['reward_line']] = $this->referralBits((int)$c['contact_id']);
        }
        return $v;
    }

    /** @return array{subject: string, body: string, sms: string} */
    public function draft(array $c, DateTimeImmutable $today): array
    {
        $vars = $this->vars($c, $today);
        $t = MiaWording::template($this->db, $c['kind']);
        $topic = [
            'seasonal' => strtolower($vars['service']) . ' this year',
            'pm_quiet' => 'your properties',
            'referral' => 'a small favour',
            'consent_ask' => 'keeping in touch',
        ][$c['kind']] ?? 'your yard';
        return [
            'subject' => MiaWording::render($t['subject'], $vars),
            'body'    => MiaWording::render($t['body'], $vars),
            'sms'     => MiaWording::sms($vars['first_name'], $topic),
        ];
    }

    /** [link, reward sentence] — the customer's own referral link from the referral program. */
    private function referralBits(int $contactId): array
    {
        require_once APP_ROOT . '/Modules/Referrals/Services/ReferralRewardService.php';
        $link = ReferralRewardService::getOrCreateLink($contactId, $this->db);
        $reward = "we'll book you in for a free service, on us.";
        try {
            // Same reward the referral program will actually give (its own private rule).
            $m = new ReflectionMethod('ReferralRewardService', 'resolveRewardProduct');
            $p = $m->invoke(null, $contactId, $this->db);
            if (!empty($p['name'])) $reward = "we'll book you in for a free " . strtolower((string)$p['name']) . ', on us.';
        } catch (Throwable $e) {}
        return [_idSiteUrl() . '/quote?referral_code=' . $link['referral_code'], $reward];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // The carousel
    // ─────────────────────────────────────────────────────────────────────────

    public function queue(int $limit = self::MAX_OPEN): array
    {
        // Priority lives in reason_json, so the order is settled in PHP.
        $s = $this->db->prepare("
            SELECT s.*, c.first_name, c.last_name, c.email, c.mobile, c.phone, co.company_name, p.address
            FROM mia_suggestions s
            LEFT JOIN contacts c ON c.id = s.contact_id
            LEFT JOIN companies co ON co.id = s.company_id
            LEFT JOIN properties p ON p.id = s.property_id
            WHERE s.status = 'open'
            ORDER BY s.id
            LIMIT 50
        ");
        $s->execute();
        $out = [];
        // When each would go if Tim tapped now: their best time (SendTimeService), shown on the button.
        $times = $this->canQueue() ? new SendTimeService($this->db) : null;
        $now = new DateTimeImmutable();
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if ($times) {
                $slot = $times->thanksSlot((int)$r['contact_id'], !empty($r['company_id']), $now);
                $r['_send_at'] = $slot['at']->format('Y-m-d H:i:s');
                $r['_send_at_learned'] = $slot['learned'];
            }
            $out[] = self::card($r);
        }
        usort($out, fn($a, $b) => [$a['priority'], -$a['value']] <=> [$b['priority'], -$b['value']]);
        return array_slice($out, 0, $limit);
    }

    /** One suggestion as the card shows it. */
    public static function card(array $r): array
    {
        $reason = json_decode((string)$r['reason_json'], true) ?: [];
        $name = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
        return [
            'id'       => (int)$r['id'],
            'key'      => $r['subject_key'],
            'kind'     => $r['kind'],
            'name'     => $name,
            'company'  => $r['company_name'] ?? null,
            'address'  => $r['address'] ?? null,
            // A payment thank-you goes where the invoice's emails go (reason_json.recipient_email), so say that one.
            'email'    => ($reason['recipient_email'] ?? '') !== '' ? $reason['recipient_email'] : ($r['email'] ?? null),
            'why'      => self::why($r['kind'], $reason, $r['company_name'] ?? null),
            'priority' => (int)($reason['priority'] ?? 2),
            'value'    => (float)($reason['value'] ?? 0),
            'sms_ok'   => !empty($reason['sms_ok']),
            'subject'  => (string)$r['draft_subject'],
            'body'     => (string)$r['draft_body'],
            'sms'      => (string)$r['draft_sms'],
            'send_at'  => $r['_send_at'] ?? null,
            'send_at_learned' => !empty($r['_send_at_learned']),
            'status'   => (string)($r['status'] ?? 'open'),
            'url'      => MiaQuestionService::link($r['kind'] === 'pm_quiet' && $r['company_id'] ? 'mia:company:' . $r['company_id'] : $r['subject_key']),
        ];
    }

    /** Mia's one-line reason, in plain words. */
    public static function why(string $kind, array $r, ?string $company): string
    {
        $when = !empty($r['last_done']) ? date('M Y', strtotime($r['last_done'])) : null;
        $service = MiaWording::serviceLabel($r['service'] ?? null);
        switch ($kind) {
            case 'payment_thanks':
                $text = 'Paid $' . number_format((float)($r['amount'] ?? 0), 2) . '. ';
                if (!empty($r['ask']) && !empty($r['history'])) {
                    $h = $r['history'];
                    $text .= 'Fall cleanup ' . date('M Y', strtotime($h['date'])) . ' (' . ucfirst((string)$h['source']) . ' #' . $h['ref'] . ', $' . number_format((float)$h['amount'], 2) . '), nothing booked this year.';
                } else {
                    $why = ['no_history'=>'no fall cleanup around this time before','done_this_season'=>'a fall cleanup is already booked','overdue'=>'an invoice is over 60 days late','sam'=>'Sam has an open quote','no_consent'=>'no marketing consent','pm'=>'property manager','late'=>'too late for the ask','not_season'=>'outside fall','muted'=>'customer is muted','other_address'=>'the invoice goes to another address than the contact\'s own'];
                    $text .= 'Plain thank-you: ' . ($why[$r['why_no_ask'] ?? ''] ?? 'no seasonal ask') . '.';
                }
                if (!empty($r['nudge_notification_id'])) $text .= ' Also asks them to use the auto-deposit address.';
                return $text;
            case 'seasonal':
                return strtolower($service) === 'yard maintenance'
                    ? "Had work around this time last year ({$when}) and nothing booked since."
                    : "{$service} around this time last year ({$when}), not booked this year.";
            case 'pm_quiet':
                return sprintf('%s: %d visits this time last year, %d in the last 3 months.',
                    $company ?: 'This company', (int)($r['prior_visits'] ?? 0), (int)($r['recent_visits'] ?? 0));
            case 'consent_ask':
                return sprintf('Implied consent (from a %s) runs out %s, and there is no express consent. Ask while they are happy.',
                    ($r['consent_source'] ?? '') === 'paid_invoice' ? 'paid invoice' : 'completed job',
                    !empty($r['consent_until']) ? date('M j, Y', strtotime($r['consent_until'])) : 'soon');
            case 'referral':
                return !empty($r['reviewed'])
                    ? 'Left you a review and had work done recently. Not asked for a referral yet.'
                    : sprintf('A customer for %d seasons, with work done recently. Not asked for a referral yet.', (int)($r['seasons'] ?? 2));
            default:
                return "No work since {$when}" . (!empty($r['visits']) ? sprintf(' (%d visit%s before that).', $r['visits'], $r['visits'] == 1 ? '' : 's') : '.');
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Tim's decision
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @param string $action send | skip
     * @param array  $in     send: subject, body, sms (bool), sms_text · skip: reason
     */
    public function decide(int $id, string $action, array $in, array $user): array
    {
        $wanted = $action === 'unqueue' ? 'queued' : 'open';
        $s = $this->db->prepare("SELECT * FROM mia_suggestions WHERE id = ? AND status = ?");
        $s->execute([$id, $wanted]);
        $sg = $s->fetch(PDO::FETCH_ASSOC);
        if (!$sg) return ['ok' => false, 'error' => 'That one has already been dealt with.'];
        $uid = (int)($user['id'] ?? 0);

        if ($action === 'unqueue') {
            $this->db->prepare("UPDATE mia_suggestions SET status='open', scheduled_at=NULL WHERE id=? AND status='queued'")->execute([$id]);
            return ['ok'=>true,'status'=>'open'];
        }

        if ($action === 'skip') {
            $reason = (string)($in['reason'] ?? '');
            if (!array_key_exists($reason, self::SKIPS)) return ['ok' => false, 'error' => 'Pick a reason.'];
            $days = self::SKIPS[$reason];
            $until = $days === null ? null : date('Y-m-d', strtotime("+{$days} days"));
            $this->db->prepare("UPDATE mia_suggestions SET status = 'skipped', skip_reason = ?, decided_by = ?, decided_at = NOW() WHERE id = ?")
                ->execute([$reason, $uid, $id]);
            $this->db->prepare("
                REPLACE INTO mia_mutes (subject_key, until_date, reason, created_by) VALUES (?, ?, ?, ?)
            ")->execute([$sg['subject_key'], $until, $reason, $uid]);
            return ['ok' => true, 'status' => 'skipped', 'until' => $until];
        }
        if ($action !== 'send') return ['ok' => false, 'error' => 'Unknown action.'];

        // Every Mia email waits for the person's best time (Tim, 2026-11-05) unless Tim says "now".
        // Checked at the tap as at the send, so a refusal is said now, not hours later.
        if (empty($in['now']) && $this->canQueue()) {
            $subject = trim((string)($in['subject'] ?? ''));
            $body = trim(str_replace("\r\n", "\n", (string)($in['body'] ?? '')));
            if ($problem = $this->validateMessage($sg, $subject, $body, $in)) return $problem;
            if (!$this->isTransactional($sg, $subject, $body)) {
                $consent = $this->ledger->allows((int)$sg['contact_id'], 'email');
                if (empty($consent['ok'])) return ['ok' => false, 'error' => "Not queued: no marketing consent on record ({$consent['reason']})."];
                if (isset($this->finder->samOwns(new DateTimeImmutable('today'))[(int)$sg['contact_id']])) {
                    return ['ok' => false, 'error' => 'They have an open quote now — Sam has this one.'];
                }
            }
            $at = (new SendTimeService($this->db))->thanksSlot((int)$sg['contact_id'], !empty($sg['company_id']), new DateTimeImmutable())['at']->format('Y-m-d H:i:s');
            $this->db->prepare("
                UPDATE mia_suggestions SET status = 'queued', scheduled_at = ?, sent_subject = ?, sent_body = ?, decided_by = ?, decided_at = NOW()
                WHERE id = ? AND status = 'open'
            ")->execute([$at, $subject, $body, $uid, $id]);
            return ['ok' => true, 'status' => 'queued', 'scheduled_at' => $at];
        }
        if (!$this->canQueue()) return $this->send($sg, $in, $uid);
        // "Send now": claim it first, so a double tap or the web and the phone at once can't both send.
        $claim = $this->db->prepare("UPDATE mia_suggestions SET status = 'sending' WHERE id = ? AND status = 'open'");
        $claim->execute([$id]);
        if ($claim->rowCount() !== 1) return ['ok' => false, 'error' => 'That one has already been dealt with.'];
        try {
            $r = $this->send($sg, $in, $uid);
        } catch (Throwable $e) {
            $r = ['ok' => false, 'error' => 'The email did not go: ' . $e->getMessage()];
        }
        if (empty($r['ok'])) $this->db->prepare("UPDATE mia_suggestions SET status = 'open' WHERE id = ? AND status = 'sending'")->execute([$id]);
        return $r;
    }

    /** Migration 1437: the queue columns exist. */
    private function canQueue(): bool
    {
        if ($this->queueReady === null) {
            try {
                $this->db->query("SELECT scheduled_at FROM mia_suggestions LIMIT 0");
                $this->queueReady = true;
            } catch (Throwable $e) {
                $this->queueReady = false;
            }
        }
        return $this->queueReady;
    }

    /**
     * A plain payment thank-you, as drafted: it confirms a transaction, so it needs no marketing
     * consent (CASL). With the fall cleanup ask, or reworded by Tim, it's marketing and needs it.
     */
    private function isTransactional(array $sg, string $subject, string $body): bool
    {
        $reason = json_decode((string)$sg['reason_json'], true) ?: [];
        return $sg['kind'] === MiaThanksService::KIND && empty($reason['ask'])
            && $subject === trim((string)$sg['draft_subject']) && $body === trim((string)$sg['draft_body']);
    }

    private function validateMessage(array $sg, string $subject, string $body, array $in): ?array
    {
        if ($subject === '' || $body === '') return ['ok' => false, 'error' => 'The message needs a subject and some words.'];
        if (preg_match('/\{[a-z_]+\}/', $subject . $body)) return ['ok' => false, 'error' => 'There is still a {placeholder} in the message.'];
        if (!empty($in['sms'])) return ['ok' => false, 'error' => 'Marketing texts are off: a text cannot carry an unsubscribe link. Email only.'];
        if ($sg['kind'] === 'consent_ask' && strpos($body, MiaWording::CONFIRM_MARK) === false) return ['ok'=>false,'error'=>'Keep '.MiaWording::CONFIRM_MARK.' in the message — it becomes their own opt-in link.'];
        return null;
    }

    private function send(array $sg, array $in, int $uid): array
    {
        $subject = trim((string)($in['subject'] ?? ''));
        $body = trim(str_replace("\r\n", "\n", (string)($in['body'] ?? '')));
        if ($problem = $this->validateMessage($sg, $subject, $body, $in)) return $problem;

        $c = $this->db->prepare("SELECT * FROM contacts WHERE id = ?");
        $c->execute([(int)$sg['contact_id']]);
        $contact = $c->fetch(PDO::FETCH_ASSOC) ?: ['id' => (int)$sg['contact_id']];
        $reason = json_decode((string)$sg['reason_json'], true) ?: [];
        $recipientEmail = $sg['kind'] === MiaThanksService::KIND ? trim((string)($reason['recipient_email'] ?? '')) : '';
        if ($recipientEmail === '') $recipientEmail = trim((string)($contact['email'] ?? ''));
        if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'error' => 'No email address on file for them.'];
        $contact['email'] = $recipientEmail;

        require_once APP_ROOT . '/Services/Messaging/MessagingService.php';
        require_once APP_ROOT . '/Services/Messaging/TemplateRenderer.php';
        // The gate: no consent record, no send — checked now, not when the draft was made.
        $consent = $this->ledger->allows((int)$contact['id'], 'email');
        $transactional = $this->isTransactional($sg, $subject, $body);
        if (empty($consent['ok']) && !$transactional) {
            return ['ok' => false, 'error' => "Not sent: no marketing consent on record ({$consent['reason']})."];
        }
        if (isset($this->finder->samOwns(new DateTimeImmutable('today'))[(int)$contact['id']]) && !$transactional) {
            return ['ok' => false, 'error' => 'They have an open quote now — Sam has this one.'];
        }

        $sentBody = $body;
        if ($sg['kind'] === 'consent_ask') {
            require_once __DIR__ . '/OptinResendService.php';
            $optin = new OptinResendService($this->db);
            $sentBody = str_replace(MiaWording::CONFIRM_MARK, $optin->confirmUrl($optin->issueFreshToken((int)$contact['id'], (string)$contact['email'])), $body);
        }
        $html = MiaWording::compose($sentBody, generateUnsubscribeUrl((string)$contact['email']), emailCompanyDetails());
        if ($html === null) return ['ok' => false, 'error' => 'Not sent: the email must name the business and carry an unsubscribe link, and one was missing.'];
        $html .= '<img src="' . htmlspecialchars(TrackingLinks::miaPixel((int)$sg['id']), ENT_QUOTES, 'UTF-8') . '" width="1" height="1" alt="">';
        $r = sendEmail((string)$contact['email'], $subject, $html, null, MiaWording::ownerName() . ' at ' . MiaWording::businessName());
        if (empty($r['success'])) return ['ok' => false, 'error' => 'The email did not go: ' . ($r['error'] ?? 'unknown error')];
        $channels = 'email';
        $smsNote = null;
        $this->log($sg, $contact, 'email', $subject, $sentBody . "\n\n[Consent: {$consent['reason']} — {$consent['source']}]", $uid);

        $edited = (int)($subject !== trim((string)$sg['draft_subject']) || $body !== trim((string)$sg['draft_body']));
        $this->db->prepare("
            UPDATE mia_suggestions SET status = 'sent', edited = ?, sent_subject = ?, sent_body = ?, sent_channels = ?, decided_by = ?, decided_at = NOW(), sent_at=NOW(), send_error=NULL
            WHERE id = ?
        ")->execute([$edited, $subject, $body, $channels, $uid, (int)$sg['id']]);

        $learned = false;
        if ($edited) $learned = $this->learn($sg, $subject, $body);
        if ($sg['kind'] === 'referral') {
            try {
                $this->db->prepare("UPDATE referral_links SET invites_sent = invites_sent + 1, last_invite_sent_at = NOW() WHERE contact_id = ?")
                    ->execute([(int)$contact['id']]);
            } catch (Throwable $e) {}
        }
        if ($sg['kind'] === MiaThanksService::KIND) $this->recordNudge($sg);
        return ['ok' => true, 'status' => 'sent', 'channels' => $channels, 'learned' => $learned, 'noted' => $edited && !$learned, 'note' => $smsNote];
    }

    /**
     * The queue (campaign_sender cron, every run): send what Tim approved whose best time has come.
     * Each row is claimed first, so two runs never both send it. Consent and Sam are checked again:
     * a thank-you whose ask is no longer allowed goes as the plain thank-you instead of not at all.
     * @return array{sent: int, failed: int}
     */
    public function sendDue(DateTimeImmutable $now): array
    {
        $out = ['sent' => 0, 'failed' => 0];
        if (!$this->canQueue()) return $out;
        // A run that died mid-send: never resent blindly (it may have gone) — it fails visibly, as campaign_sends do.
        $this->db->prepare("
            UPDATE mia_suggestions SET status = 'failed', send_error = 'interrupted while sending — check the client''s history before resending'
            WHERE status = 'sending' AND COALESCE(scheduled_at, decided_at, created_at) < ?
        ")->execute([$now->modify('-1 hour')->format('Y-m-d H:i:s')]);
        $s = $this->db->prepare("SELECT * FROM mia_suggestions WHERE status = 'queued' AND scheduled_at <= ? ORDER BY scheduled_at, id LIMIT 20");
        $s->execute([$now->format('Y-m-d H:i:s')]);
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $sg) {
            $claim = $this->db->prepare("UPDATE mia_suggestions SET status = 'sending' WHERE id = ? AND status = 'queued'");
            $claim->execute([(int)$sg['id']]);
            if ($claim->rowCount() !== 1) continue;
            try {
                $reason = json_decode((string)$sg['reason_json'], true) ?: [];
                if ($sg['kind'] === MiaThanksService::KIND && !empty($reason['ask'])) {
                    $consent = $this->ledger->allows((int)$sg['contact_id'], 'email');
                    $sam = !empty($consent['ok']) && isset($this->finder->samOwns(new DateTimeImmutable('today'))[(int)$sg['contact_id']]);
                    if (empty($consent['ok']) || $sam) {
                        $d = (new MiaThanksService($this->db))->plainDraft($sg, $reason, $now, $sam ? 'sam' : 'no_consent');
                        $sg['reason_json'] = json_encode($d['reason']);
                        $sg['draft_subject'] = $sg['sent_subject'] = $d['subject'];
                        $sg['draft_body'] = $sg['sent_body'] = $d['body'];
                        $this->db->prepare("UPDATE mia_suggestions SET reason_json = ?, draft_subject = ?, draft_body = ? WHERE id = ?")
                            ->execute([$sg['reason_json'], $d['subject'], $d['body'], (int)$sg['id']]);
                    }
                }
                $r = $this->send($sg, ['subject' => (string)$sg['sent_subject'], 'body' => (string)$sg['sent_body'], 'now' => 1], (int)$sg['decided_by']);
                if (!empty($r['ok'])) {
                    $out['sent']++;
                } else {
                    $this->failSend((int)$sg['id'], (string)($r['error'] ?? 'send failed'));
                    $out['failed']++;
                }
            } catch (Throwable $e) {
                error_log('Mia sendDue #' . (int)$sg['id'] . ': ' . $e->getMessage());
                $this->failSend((int)$sg['id'], $e->getMessage());
                $out['failed']++;
            }
        }
        return $out;
    }

    private function failSend(int $id, string $error): void
    {
        try {
            $this->db->prepare("UPDATE mia_suggestions SET status = 'failed', send_error = ? WHERE id = ?")
                ->execute([mb_substr($error !== '' ? $error : 'send failed', 0, 255), $id]);
        } catch (Throwable $e) {
            error_log('Mia send failed and could not be marked: ' . $e->getMessage());
        }
    }

    /** The thank-you asked for the auto-deposit address: log it where Penny looks, so she never asks again. */
    private function recordNudge(array $sg): void
    {
        $r = json_decode((string)$sg['reason_json'], true) ?: [];
        if (empty($r['nudge_notification_id'])) return;
        $email = trim((string)($r['recipient_email'] ?? ''));
        try {
            $this->db->prepare("
                INSERT INTO etransfer_address_nudges (notification_id, contact_id, email, email_key, trigger_source, status, created_at, sent_at)
                VALUES (?, ?, ?, ?, 'mia', 'sent', NOW(), NOW())
            ")->execute([(int)$r['nudge_notification_id'], (int)$sg['contact_id'] ?: null, $email, mb_substr(strtolower($email), 0, 190)]);
        } catch (Throwable $e) {
            // Duplicate key: already logged. Anything else is only the log.
        }
    }

    /** Tim's edited wording becomes the template for this kind when it's reusable. */
    private function learn(array $sg, string $subject, string $body): bool
    {
        $c = $this->db->prepare("SELECT c.id AS contact_id, c.first_name, c.last_name FROM contacts c WHERE c.id = ?");
        $c->execute([(int)$sg['contact_id']]);
        $contact = $c->fetch(PDO::FETCH_ASSOC) ?: [];
        $place = null;
        if ($sg['property_id']) {
            $p = $this->db->prepare("SELECT address FROM properties WHERE id = ?");
            $p->execute([(int)$sg['property_id']]);
            $place = $p->fetchColumn() ?: null;
        }
        $company = null;
        if ($sg['company_id']) {
            $p = $this->db->prepare("SELECT company_name FROM companies WHERE id = ?");
            $p->execute([(int)$sg['company_id']]);
            $company = $p->fetchColumn() ?: null;
        }
        $reason = json_decode((string)$sg['reason_json'], true) ?: [];
        $vars = $this->vars([
            'kind' => 'learn', 'contact' => $contact, 'address' => $place, 'company_name' => $company,
            'reason' => $reason, 'contact_id' => $sg['contact_id'],
        ], new DateTimeImmutable(substr((string)$sg['created_at'], 0, 10) ?: 'today'));
        if ($sg['kind'] === 'referral' && preg_match('~https://mowology\.ca/quote\?referral_code=[A-Z0-9]+~', $body, $m)) {
            $vars['referral_link'] = $m[0];
        }
        $tBody = MiaWording::learnTemplate($sg['kind'], $body, $vars);
        if ($tBody === null) return false;
        // Only wording Tim has written alike twice becomes the template (2026-10-15: one edit used to replace it).
        if (!MiaWording::rememberCandidate($this->db, $sg['kind'], MiaWording::tokenise($subject, $vars), $tBody)) return false;
        MiaWording::saveTemplate($this->db, $sg['kind'], MiaWording::tokenise($subject, $vars), $tBody);
        return true;
    }

    private function log(array $sg, array $contact, string $type, ?string $subject, string $message, int $uid): void
    {
        try {
            $this->db->prepare("
                INSERT INTO communication_log (company_id, contact_id, property_id, type, direction, subject, message, to_email, status, created_by, created_at)
                VALUES (?, ?, ?, ?, 'outbound', ?, ?, ?, 'sent', ?, NOW())
            ")->execute([
                $sg['company_id'] ?: null, (int)$contact['id'], $sg['property_id'] ?: null, $type,
                $subject ?? 'Text from Mia (sent by Tim)', $message, $type === 'email' ? $contact['email'] : null, $uid ?: null,
            ]);
        } catch (Throwable $e) {
            error_log('Mia communication_log: ' . $e->getMessage());
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // What came of it
    // ─────────────────────────────────────────────────────────────────────────

    /** Settle the result of messages sent 30+ days ago, or earlier when something happened. */
    public function settleOutcomes(DateTimeImmutable $now): int
    {
        $rows = $this->db->query("SELECT id, kind, contact_id, company_id, decided_at FROM mia_suggestions WHERE status = 'sent' AND outcome IS NULL LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
        $hasSales = false;
        try { $hasSales = $this->db->query("SHOW TABLES LIKE 'sales_messages'")->fetchColumn() !== false; } catch (Throwable $e) {}
        $n = 0;
        foreach ($rows as $r) {
            $events = $this->events($r, $hasSales);
            $o = MiaFinder::outcome((string)$r['decided_at'], $events, $now);
            if (!$o) continue;
            $this->db->prepare("UPDATE mia_suggestions SET outcome = ?, outcome_at = ?, outcome_ref = ? WHERE id = ?")
                ->execute([$o['outcome'], $o['at'], substr($o['ref'], 0, 60), (int)$r['id']]);
            $n++;
        }
        return $n;
    }

    /** Quotes, new plans and replies for this person (or this company's buildings) since the send. */
    private function events(array $r, bool $hasSales): array
    {
        $since = (string)$r['decided_at'];
        if ($r['kind'] === 'pm_quiet' && $r['company_id']) {
            $props = "SELECT id FROM properties WHERE property_manager_id = " . (int)$r['company_id'];
        } else {
            $props = "SELECT id FROM properties WHERE site_contact_id = " . (int)$r['contact_id'];
        }
        $cid = (int)$r['contact_id'];
        $events = [];
        try {
            $q = $this->db->prepare("SELECT id, status, created_at FROM quotes WHERE created_at >= ? AND (contact_id = ? OR property_id IN ($props))");
            $q->execute([$since, $cid]);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $x) {
                $events[] = ['type' => in_array($x['status'], ['accepted', 'approved_verbal'], true) ? 'booked' : 'quote', 'at' => $x['created_at'], 'ref' => 'quote:' . $x['id']];
            }
            $p = $this->db->prepare("SELECT id, created_at FROM job_plans WHERE created_at >= ? AND property_id IN ($props)");
            $p->execute([$since]);
            foreach ($p->fetchAll(PDO::FETCH_ASSOC) as $x) $events[] = ['type' => 'booked', 'at' => $x['created_at'], 'ref' => 'plan:' . $x['id']];
            if ($hasSales && $cid) {
                $m = $this->db->prepare("SELECT id, sent_at FROM sales_messages WHERE contact_id = ? AND direction = 'inbound' AND sent_at >= ?");
                $m->execute([$cid, $since]);
                foreach ($m->fetchAll(PDO::FETCH_ASSOC) as $x) $events[] = ['type' => 'reply', 'at' => $x['sent_at'], 'ref' => 'message:' . $x['id']];
            }
        } catch (Throwable $e) {
            error_log('Mia outcomes: ' . $e->getMessage());
        }
        return $events;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Numbers
    // ─────────────────────────────────────────────────────────────────────────

    public function stats(?DateTimeImmutable $today = null): array
    {
        $today = $today ?? new DateTimeImmutable('today');
        $one = function (string $sql, array $p = []) {
            try { $s = $this->db->prepare($sql); $s->execute($p); return $s->fetchColumn(); } catch (Throwable $e) { return 0; }
        };
        $byKind = ['reconnect' => 0, 'seasonal' => 0, 'pm_quiet' => 0, 'referral' => 0, 'consent_ask' => 0];
        foreach ($this->db->query("SELECT kind, COUNT(*) n FROM mia_suggestions WHERE status = 'open' GROUP BY kind")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $byKind[$r['kind']] = (int)$r['n'];
        }
        // What works, per kind of message (the last year; a kind shows from WORKS_MIN sends): sent → booked/quoted, replied.
        $works = [];
        try {
            $w = $this->db->prepare("SELECT kind, COUNT(*) AS sent, SUM(outcome IN ('booked', 'quote')) AS won, SUM(outcome = 'reply') AS replied
                                     FROM mia_suggestions WHERE status = 'sent' AND decided_at >= ? GROUP BY kind");
            $w->execute([$today->modify('-365 days')->format('Y-m-d')]);
            foreach ($w->fetchAll(PDO::FETCH_ASSOC) as $r) {
                if ((int)$r['sent'] < self::WORKS_MIN) continue;
                $works[] = ['kind' => (string)$r['kind'], 'sent' => (int)$r['sent'], 'won' => (int)$r['won'], 'replied' => (int)$r['replied']];
            }
            usort($works, fn($a, $b) => [$b['won'] / $b['sent'], $b['sent']] <=> [$a['won'] / $a['sent'], $a['sent']]);
        } catch (Throwable $e) {
            $works = [];
        }
        $d90 = $today->modify('-90 days')->format('Y-m-d');
        $d30 = $today->modify('-30 days')->format('Y-m-d');
        return [
            'open'           => array_sum($byKind),
            'by_kind'        => $byKind,
            'sent_30'        => (int)$one("SELECT COUNT(*) FROM mia_suggestions WHERE status = 'sent' AND decided_at >= ?", [$d30]),
            'skipped_30'     => (int)$one("SELECT COUNT(*) FROM mia_suggestions WHERE status = 'skipped' AND decided_at >= ?", [$d30]),
            'won'            => (int)$one("SELECT COUNT(*) FROM mia_suggestions WHERE outcome IN ('booked', 'quote')"),
            'replies'        => (int)$one("SELECT COUNT(*) FROM mia_suggestions WHERE outcome = 'reply'"),
            'settled'        => (int)$one("SELECT COUNT(*) FROM mia_suggestions WHERE outcome IS NOT NULL"),
            'reviews_asked_90' => (int)$one("SELECT COUNT(*) FROM job_visits WHERE review_request_sent_at >= ?", [$d90]),
            'reviewed'       => (int)$one("SELECT COUNT(*) FROM contacts WHERE has_reviewed = 1"),
            'referrals_in'   => (int)$one("SELECT COUNT(*) FROM referrals WHERE status IN ('converted', 'rewarded')"),
            'referrals_open' => (int)$one("SELECT COUNT(*) FROM referrals WHERE status = 'pending'"),
            'questions'      => (int)$one("SELECT COUNT(*) FROM mia_questions WHERE status = 'open'"),
            'consent_ok'     => (int)$one("SELECT COUNT(DISTINCT contact_id) FROM consent_ledger WHERE channel = 'email' AND consent_type IN ('express', 'implied') AND (expires_at IS NULL OR expires_at > ?)", [$today->format('Y-m-d')]),
            'works'          => $works,
            'consent_express'=> (int)$one("SELECT COUNT(DISTINCT contact_id) FROM consent_ledger WHERE channel = 'email' AND consent_type = 'express'"),
        ];
    }

    /** What Mia says first on the card. */
    public static function headline(array $st, string $name, array $top = []): string
    {
        $hi = 'Hey' . ($name !== '' ? ' ' . $name : '') . ' — ';
        $k = $st['by_kind'];
        $bits = [];
        if ($k['seasonal']) $bits[] = sprintf('%d customer%s from this time last year %s booked again', $k['seasonal'], $k['seasonal'] === 1 ? '' : 's', $k['seasonal'] === 1 ? "hasn't" : "haven't");
        if ($k['pm_quiet']) $bits[] = sprintf('%d property manager%s %s gone quiet', $k['pm_quiet'], $k['pm_quiet'] === 1 ? '' : 's', $k['pm_quiet'] === 1 ? 'has' : 'have');
        if ($k['reconnect']) $bits[] = sprintf('%d past customer%s %s worth a hello', $k['reconnect'], $k['reconnect'] === 1 ? '' : 's', $k['reconnect'] === 1 ? 'is' : 'are');
        if ($k['referral']) $bits[] = sprintf('%d happy customer%s could send you a referral', $k['referral'], $k['referral'] === 1 ? '' : 's');
        if (!empty($k['consent_ask'])) $bits[] = sprintf('%d customer%s should be asked to keep hearing from you before their consent runs out', $k['consent_ask'], $k['consent_ask'] === 1 ? '' : 's');
        if (!$bits) {
            // Items under the line (a campaign to OK, a reply to answer) mean something does need Tim, so the line says so
            // (Tim, 2026-11-07: "nobody needs a message" sat above two things that did).
            $n = count($top);
            if ($n > 0) {
                return $hi . sprintf('no messages to send today, but %d thing%s need%s you.', $n, $n === 1 ? '' : 's', $n === 1 ? 's' : '');
            }
            return $hi . ($st['questions'] ? "nobody needs a message today. I've got a question for you below." : "nobody needs a message from you today. I'll keep watching.");
        }
        $last = array_pop($bits);
        return $hi . ($bits ? implode(', ', $bits) . ' and ' . $last : $last) . '. I\'ve drafted a message for each — you send.';
    }

    /**
     * Read-only summary for Charlie. Cheap: reads waiting suggestions, campaign replies that
     * still need a quote, and questions; never prepares, never sends.
     * @return array{head: string, headline: string, items: array, count: int}
     */
    public function brief(string $ownerFirstName): array
    {
        if (!$this->ready()) return ['head' => 'mia', 'headline' => '', 'items' => [], 'count' => 0];
        $st = $this->stats();
        $items = [];
        foreach ($this->queue(5) as $c) {
            $who = $c['kind'] === 'pm_quiet' && $c['company'] ? $c['company'] : ($c['name'] ?: 'A past customer');
            $items[] = [
                'key'      => $c['key'],
                'text'     => $who . ': ' . $c['why'],
                'url'      => '/crm/dashboard_appstack.php#mw-mia',
                'priority' => $c['priority'],
                'kind'     => $c['kind'],
                'value'    => $c['value'],
            ];
        }
        try {
            $camp = $this->db->query("SELECT campaign_key, name, audience_json FROM mia_campaigns WHERE status = 'proposed' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            if ($camp) {
                $n = (int)((json_decode((string)$camp['audience_json'], true) ?: [])['consented'] ?? 0);
                array_unshift($items, ['key' => 'mia:campaign:' . $camp['campaign_key'], 'text' => "Campaign ready for your OK: {$camp['name']} — {$n} people with consent.",
                    'url' => '/crm/dashboard_appstack.php#mw-mia', 'priority' => 1, 'kind' => 'campaign', 'value' => $n]);
            }
        } catch (Throwable $e) { /* no campaigns table yet */ }
        // Campaign replies are interest, so they are Sam's leads now (SalesDeskService::brief); Mia keeps
        // the campaign's results (sent / replied / quoted / booked) on her own card.
        foreach ((new MiaQuestionService($this->db))->open(2) as $q) {
            $items[] = ['key' => 'mia:question:' . $q['id'], 'text' => $q['question'], 'url' => $q['url'] ?? '/crm/dashboard_appstack.php#mw-mia', 'priority' => 3, 'kind' => 'question'];
        }
        // Channels: reviews to reply to, Google/social drafts, Facebook reconnect, website insight, listings.
        try {
            require_once __DIR__ . '/MiaChannelsService.php';
            foreach ((new MiaChannelsService($this->db))->briefItems() as $it) $items[] = $it;
        } catch (Throwable $e) { error_log('Mia channels brief: ' . $e->getMessage()); }
        // Search & Website (Phase 1.14): suggestions worth the owner's time, and a website Mia can no longer look at.
        // Quiet when there is nothing: see MiaQueueBrief.
        // Live since ff085d9d0 (its branch is not merged here yet): guarded so this file works with or without it.
        if (is_file(__DIR__ . '/MiaQueueBrief.php')) {
            try {
                require_once __DIR__ . '/MiaQueueBrief.php';
                foreach (MiaQueueBrief::items($this->db) as $it) $items[] = $it;
            } catch (Throwable $e) { error_log('Mia queue brief: ' . $e->getMessage()); }
        }
        return ['head' => 'mia', 'headline' => self::headline($st, $ownerFirstName, $items), 'items' => $items, 'count' => count($items)];
    }

    /** The owner's first name for "Hey Tim —". */
    public static function firstName(array $user): string
    {
        $n = trim((string)($user['first_name'] ?? '')) ?: (string)strtok(trim((string)($user['full_name'] ?? '')), ' ');
        return self::nameCase(trim($n));
    }

    /** "TIM" or "tim" → "Tim"; a name typed with its own mix of cases ("McKay", "DeShawn") is left as typed. */
    public static function nameCase(string $n): string
    {
        if ($n === '') return '';
        if ($n === mb_strtoupper($n) || $n === mb_strtolower($n)) return mb_convert_case(mb_strtolower($n), MB_CASE_TITLE);
        return ucfirst($n);
    }

    private function setSetting(string $key, string $value): void
    {
        try {
            $this->db->prepare("
                INSERT INTO ops_settings (setting_key, setting_value, description) VALUES (?, ?, 'Mia: internal')
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
            ")->execute([$key, $value]);
        } catch (Throwable $e) {}
    }
}
