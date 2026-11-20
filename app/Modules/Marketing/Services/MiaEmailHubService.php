<?php
/**
 * MiaEmailHubService — Mia's email side as one place: the daily run and the card's summary.
 *
 *   daily()   the nightly cron (mia_email_daily): read the watering stage, propose what's due on
 *             the calendar, queue due reminders / last calls, relearn send times, and keep each
 *             calendar campaign's results by year. Each step runs on its own: one failing never
 *             stops the rest.
 *   card()    for Mia's card: watering stage, the next 3 calendar campaigns, running sequences,
 *             how many people have a learned send time, and what worked before.
 *
 * Results by year live in mia_calendar_results (calendar_key, season_year): sent, clicked,
 * replied, quoted, booked, booked_amount — the same definitions as MiaCampaignService::results()
 * (bookings first; opens are not kept here, they are too rough to learn from).
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
require_once __DIR__ . '/MiaCampaignService.php';
require_once __DIR__ . '/MiaCalendar.php';
require_once __DIR__ . '/WaterRestrictionService.php';
require_once __DIR__ . '/SendTimeService.php';
require_once __DIR__ . '/MiaSequenceService.php';

class MiaEmailHubService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function ready(): bool
    {
        return (new MiaCampaignService($this->db))->hubReady();
    }

    /** The nightly run. Returns one line per step for the cron log. */
    public function daily(?DateTimeImmutable $now = null, bool $fetchWater = true): array
    {
        $now = $now ?? new DateTimeImmutable();
        $today = $now->setTime(0, 0);
        $log = [];
        $step = function (string $name, callable $fn) use (&$log) {
            try {
                $log[] = $name . ': ' . $fn();
            } catch (Throwable $e) {
                $log[] = $name . ': FAILED ' . $e->getMessage();
                error_log("Mia email daily ($name): " . $e->getMessage());
            }
        };
        if ($fetchWater) {
            $step('water', function () {
                $r = (new WaterRestrictionService($this->db))->refresh();
                return $r['ok'] ? 'Stage ' . $r['stage'] . ' from ' . $r['source'] : 'kept last value (' . $r['error'] . ')';
            });
        }
        if (!$this->ready()) {
            $log[] = 'calendar: migration 1195 not run — skipped';
            return $log;
        }
        $step('propose', fn() => (new MiaCampaignService($this->db))->propose($today) . ' proposed');
        $step('sequences', function () use ($now) {
            $r = (new MiaSequenceService($this->db))->run($now);
            return $r['queued'] . ' follow-ups queued, ' . $r['stamped'] . ' replies/bookings stamped';
        });
        $step('send times', fn() => (new SendTimeService($this->db))->recompute($now) . ' contacts learned');
        $step('results', fn() => $this->snapshotResults() . ' campaigns tallied');
        return $log;
    }

    /** Keep each calendar campaign's results by year. */
    public function snapshotResults(): int
    {
        $camp = new MiaCampaignService($this->db);
        $rows = $this->db->query("SELECT id, calendar_key, season_year, marketing_campaign_id, sequence_state_json FROM mia_campaigns
                                  WHERE status = 'approved' AND marketing_campaign_id IS NOT NULL AND calendar_key IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);
        $up = $this->db->prepare("
            INSERT INTO mia_calendar_results (calendar_key, season_year, mia_campaign_id, sent, clicked, replied, quoted, booked, booked_amount, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE mia_campaign_id = VALUES(mia_campaign_id), sent = VALUES(sent), clicked = VALUES(clicked), replied = VALUES(replied),
                                    quoted = VALUES(quoted), booked = VALUES(booked), booked_amount = VALUES(booked_amount), updated_at = VALUES(updated_at)");
        $n = 0;
        foreach ($rows as $r) {
            $mc = (int)$r['marketing_campaign_id'];
            $res = $camp->results($mc);
            if (!$res) continue;
            $ids = [$mc];
            foreach (json_decode((string)($r['sequence_state_json'] ?? ''), true) ?: [] as $child) $ids[] = (int)$child;
            $in = implode(',', array_map('intval', $ids));
            $clicked = (int)$this->db->query("SELECT COUNT(DISTINCT contact_id) FROM campaign_sends WHERE campaign_id IN ($in) AND clicked_at IS NOT NULL")->fetchColumn();
            $up->execute([(string)$r['calendar_key'], (int)$r['season_year'], (int)$r['id'], (int)$res['sent'], $clicked, (int)$res['replied'],
                          (int)$res['quoted'], (int)$res['booked'], (float)$res['booked_amount'], date('Y-m-d H:i:s')]);
            $n++;
        }
        return $n;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // The card
    // ─────────────────────────────────────────────────────────────────────────

    public function card(?DateTimeImmutable $today = null): array
    {
        $today = $today ?? new DateTimeImmutable('today');
        $water = (new WaterRestrictionService($this->db))->current($today);
        $calendar = MiaCalendar::load($this->db);
        $status = [];
        try {
            foreach ($this->db->query("SELECT campaign_key, status FROM mia_campaigns")->fetchAll(PDO::FETCH_ASSOC) as $r) $status[$r['campaign_key']] = $r['status'];
        } catch (Throwable $e) {}
        $next = [];
        foreach (MiaCalendar::upcoming($calendar, $today, 3) as $u) {
            $next[] = self::nextLine($u['entry'], $u['occ'], $status[$u['occ']['key']] ?? null, $water, $today);
        }
        $results = [];
        try {
            $results = $this->db->query("SELECT * FROM mia_calendar_results ORDER BY season_year DESC, booked DESC")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {}
        return [
            'water'     => $water,
            'next'      => $next,
            'sequences' => (new MiaSequenceService($this->db))->summary($today),
            'learned'   => (new SendTimeService($this->db))->coverage(),
            'worked'    => self::whatWorked($results, $calendar),
        ];
    }

    /**
     * One upcoming campaign for the card.
     * @return array{name: string, when: string, state: string}
     */
    public static function nextLine(array $e, array $occ, ?string $status, array $water, DateTimeImmutable $today): array
    {
        $when = MiaCalendar::short($occ['send_from']) . '–' . MiaCalendar::short($occ['send_to']);
        $t = $today->format('Y-m-d');
        if ($status === 'proposed') $state = 'Waiting for your OK';
        elseif ($status === 'approved') $state = 'Approved';
        elseif ($status === 'dismissed') $state = 'Not this time';
        elseif ($status === 'expired') $state = 'Lapsed';
        else {
            $hold = MiaCalendar::conditionsHold($e, ['stage' => $water['stage'] ?? null, 'drought_year' => !empty($water['drought_year'])]);
            if ($occ['propose'] <= $t && !$hold['ok']) $state = $hold['reason'];
            elseif ($occ['propose'] <= $t) $state = 'Proposing now';
            else $state = 'I propose it ' . MiaCalendar::short($occ['propose']);
        }
        return ['name' => (string)$e['name'], 'when' => $when, 'state' => $state];
    }

    /**
     * "What worked": the best-booking calendar campaigns so far, one line each.
     * @param array $results mia_calendar_results rows
     */
    public static function whatWorked(array $results, array $calendar, int $n = 3): array
    {
        $rows = array_values(array_filter($results, fn($r) => (int)$r['sent'] > 0));
        usort($rows, fn($a, $b) => [(int)$b['booked'], (float)$b['booked_amount'], (int)$b['replied']] <=> [(int)$a['booked'], (float)$a['booked_amount'], (int)$a['replied']]);
        $out = [];
        foreach (array_slice($rows, 0, $n) as $r) {
            $name = $calendar[$r['calendar_key']]['name'] ?? ucfirst(str_replace('_', ' ', (string)$r['calendar_key']));
            $money = (float)$r['booked_amount'] > 0 ? ' ($' . number_format((float)$r['booked_amount'], 0) . ')' : '';
            $out[] = $name . ' ' . (int)$r['season_year'] . ': ' . (int)$r['booked'] . ' booked' . $money . ', '
                . (int)$r['replied'] . ' replied from ' . (int)$r['sent'] . ' sent';
        }
        return $out;
    }
}
