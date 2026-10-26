<?php
declare(strict_types=1);

/**
 * Phase 3.2: the events outbox. Something happened that Mia may want to look at (photos of a finished job became usable);
 * it is written once to mia_events, then a cron drains the table and turns each event into AT MOST ONE proposal.
 *
 *   sweep   asks the Otto feed which completed visits have approved, consented photos and emits one event per visit.
 *           (Sweeping instead of hooking visit completion keeps this off the crew and money path: a failure here can
 *           never block a visit from completing. emit() is public for a later hook.)
 *   drain   re-checks every gate with fresh data at the moment of deciding, because the world changes between emit and
 *           drain (a pair can be unapproved, consent withdrawn). Gate fails => the event is `skipped` with the reason and
 *           NOTHING reaches the owner's queue. Gates pass => MiaActionService::propose(), which already refuses a second
 *           open action for the same subject and respects pause / observe-only.
 *
 * Nothing is published or sent. A proposal is a question for the owner. No client, property or reviewer name is stored.
 * Global namespace, no autoloader: require_once it. PHP 7.4-compatible.
 */

require_once __DIR__ . '/MiaFeeds.php';
require_once __DIR__ . '/MiaActionService.php';

final class MiaEventService
{
    public const TYPE_PHOTOS_READY = 'photos.ready';
    public const MAX_ATTEMPTS = 3;
    public const TABLES = ['mia_events', 'mia_actions', 'mia_action_events', 'mia_decisions', 'mia_memory', 'ba_pairs', 'job_visits'];

    /** @var PDO */
    private $db;
    /** @var int */
    private $siteId;
    /** @var MiaActionService */
    private $actions;
    /** @var MiaFeeds */
    private $feeds;
    /** @var callable|null fn(int $actionId): void  writes the story wording for a new question; failure never undoes the question */
    private $drafter;

    public function __construct(PDO $db, int $siteId, ?MiaActionService $actions = null, ?MiaFeeds $feeds = null, ?callable $drafter = null)
    {
        $this->drafter = $drafter;
        if ($siteId <= 0) { throw new InvalidArgumentException('MiaEventService needs a site'); }
        $this->db = $db;
        $this->siteId = $siteId;
        $this->actions = $actions ?: new MiaActionService($db, $siteId);
        $this->feeds = $feeds ?: new MiaFeeds($db);
    }

    /**
     * Queue an event once. The same (type, subject) is never queued twice, whatever its status.
     * @return array{ok:bool, queued:bool, id:?int}
     */
    public function emit(string $type, string $subjectKey, array $payload = [], ?DateTimeImmutable $now = null): array
    {
        $now = $now ?: new DateTimeImmutable('now');
        if ($type === '' || strlen($type) > 40 || $subjectKey === '' || strlen($subjectKey) > 120) { return ['ok' => false, 'queued' => false, 'id' => null]; }
        $s = $this->db->prepare("SELECT id FROM mia_events WHERE site_id = ? AND event_type = ? AND subject_key = ?");
        $s->execute([$this->siteId, $type, $subjectKey]);
        $id = $s->fetchColumn();
        if ($id !== false) { return ['ok' => true, 'queued' => false, 'id' => (int)$id]; }
        try {
            $this->db->prepare("INSERT INTO mia_events (site_id, event_type, subject_key, payload, status, attempts, created_at) VALUES (?, ?, ?, ?, 'pending', 0, ?)")
                ->execute([$this->siteId, $type, $subjectKey, json_encode($payload), $now->format('Y-m-d H:i:s')]);
            return ['ok' => true, 'queued' => true, 'id' => (int)$this->db->lastInsertId()];
        } catch (PDOException $e) {
            // A second process won the race: the unique key held the line, which is the point of it.
            $s->execute([$this->siteId, $type, $subjectKey]);
            $id = $s->fetchColumn();
            if ($id !== false) { return ['ok' => true, 'queued' => false, 'id' => (int)$id]; }
            throw $e;
        }
    }

    /** Emit one event per visit that currently has usable photos. @return int events newly queued */
    public function sweep(int $sinceDays = 30, ?DateTimeImmutable $now = null): int
    {
        $n = 0;
        for ($page = 0; $page < 10; $page++) {                          // pages of 200, newest first; 2,000 visits is far beyond a month of work
            $rows = $this->feeds->completedVisitsWithApprovedPhotos($sinceDays, 200, null, $page * 200)['visits'];
            foreach ($rows as $v) {
                $r = $this->emit(self::TYPE_PHOTOS_READY, 'visit:' . (int)$v['visit_id'], ['visit_id' => (int)$v['visit_id'], 'service_key' => $v['service_key']], $now);
                if ($r['queued']) { $n++; }
            }
            if (count($rows) < 200) { break; }
        }
        return $n;
    }

    /**
     * @return array{swept:int, processed:int, proposed:int, skipped:int, failed:int, waiting:int, errors:string[]}
     */
    public function drain(int $limit = 20, ?DateTimeImmutable $now = null): array
    {
        $now = $now ?: new DateTimeImmutable('now');
        $r = ['swept' => 0, 'processed' => 0, 'proposed' => 0, 'skipped' => 0, 'failed' => 0, 'waiting' => 0, 'errors' => []];
        $r['swept'] = $this->sweep(30, $now);
        $st = $this->db->prepare("SELECT * FROM mia_events WHERE site_id = ? AND status = 'pending' ORDER BY id LIMIT " . (int)max(1, min(100, $limit)));
        $st->execute([$this->siteId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $ev) {
            $r['processed']++;
            try {
                $res = $this->handle($ev, $now);
            } catch (Throwable $e) {
                $att = (int)$ev['attempts'] + 1;
                $final = $att >= self::MAX_ATTEMPTS;
                $this->db->prepare("UPDATE mia_events SET attempts = ?, status = ?, outcome = ?, processed_at = ? WHERE id = ?")
                    ->execute([$att, $final ? 'failed' : 'pending', substr(get_class($e) . ': ' . $e->getMessage(), 0, 200), $now->format('Y-m-d H:i:s'), (int)$ev['id']]);
                if ($final) { $r['failed']++; }
                $r['errors'][] = 'event ' . (int)$ev['id'] . ': ' . get_class($e) . ': ' . substr($e->getMessage(), 0, 120);
                continue;
            }
            if ($res['status'] === 'wait') { $r['waiting']++; continue; }       // paused: stays pending, costs no attempt
            $this->db->prepare("UPDATE mia_events SET status = ?, outcome = ?, action_id = ?, attempts = attempts + 1, processed_at = ? WHERE id = ?")
                ->execute([$res['status'], substr($res['outcome'], 0, 200), $res['action_id'], $now->format('Y-m-d H:i:s'), (int)$ev['id']]);
            if ($res['status'] === 'done') { $r['proposed']++; } else { $r['skipped']++; }
        }
        return $r;
    }

    /** @return array{status:string,outcome:string,action_id:?int} */
    private function handle(array $ev, DateTimeImmutable $now): array
    {
        if ($ev['event_type'] !== self::TYPE_PHOTOS_READY) { return ['status' => 'skipped', 'outcome' => 'unknown event type', 'action_id' => null]; }
        $payload = json_decode((string)$ev['payload'], true) ?: [];
        $visitId = (int)($payload['visit_id'] ?? 0);
        if ($visitId <= 0) { return ['status' => 'skipped', 'outcome' => 'no visit on the event', 'action_id' => null]; }

        // Gates, checked again NOW against live data: the same feed Otto reads, so there is one definition of "usable photos".
        $rows = $this->feeds->completedVisitsWithApprovedPhotos(30, 1, $visitId)['visits'];
        $fresh = $rows ? $rows[0] : null;
        if ($fresh === null) {
            return ['status' => 'skipped', 'outcome' => 'gate failed: no approved photo pair with usable consent on a completed visit in the last 30 days', 'action_id' => null];
        }

        // One question per visit, ever: if an earlier run saved the action and died before marking the event, adopt that action
        // instead of proposing again (a decided action is no longer "open", so propose() alone would not stop a repeat).
        $prior = $this->db->prepare("SELECT id FROM mia_actions WHERE site_id = ? AND type = 'project_story' AND opportunity_key = ? ORDER BY id LIMIT 1");
        $prior->execute([$this->siteId, 'visit:' . $visitId]);
        $priorId = $prior->fetchColumn();
        if ($priorId !== false) { return ['status' => 'done', 'outcome' => 'an action for this visit already existed', 'action_id' => (int)$priorId]; }

        $p = $this->actions->propose([
            'type' => 'project_story',
            'side_effect' => 'none',
            'opportunity_key' => 'visit:' . $visitId,
            'title' => 'Finished job with approved photos could become a project story',
            'why' => 'A completed ' . str_replace('_', ' ', (string)$fresh['service_key']) . ' visit has before and after photos you approved, with consent covered. A short project story would give the website real work to show.',
            'expected_benefit' => 'Fresh, local proof of work for the website. The size of any effect is not known.',
            'risk' => 'Nothing is written or published until you approve a draft. Customer details are left out unless you add them.',
            'evidence' => [['visit_id' => $visitId, 'pair_id' => (int)$fresh['pair_id'], 'service_key' => $fresh['service_key'], 'completed_at' => $fresh['completed_at'], 'consent' => $fresh['consent']]],
            'evidence_basis' => ['visit' => $visitId, 'pair' => (int)$fresh['pair_id']],
        ], $now);
        if (!$p['ok']) {
            if ($p['code'] === 'db') { throw new RuntimeException('could not save the proposal'); }   // retry later
            if ($p['code'] === 'not_allowed') { return ['status' => 'wait', 'outcome' => 'Mia is paused or observe-only: left for later', 'action_id' => null]; }
            return ['status' => 'skipped', 'outcome' => 'not proposed: ' . (string)$p['code'], 'action_id' => null];
        }
        if (!$p['existing'] && $this->drafter !== null) {
            try { call_user_func($this->drafter, (int)$p['id']); } catch (Throwable $e) { error_log('[mia_events] story wording: ' . $e->getMessage()); }   // the question stands without wording
        }
        return ['status' => 'done', 'outcome' => $p['existing'] ? 'an open action for this visit already existed' : 'proposed', 'action_id' => (int)$p['id']];
    }

    /** The real wording step: one verified website, the real model gateway. Returns null when there is no website to attach to. */
    private static function storyDrafter(PDO $db, int $siteId): ?callable
    {
        $w = $db->prepare("SELECT id FROM mia_websites WHERE site_id = ? AND status = 'verified' ORDER BY id LIMIT 1");
        $w->execute([$siteId]);
        $websiteId = (int)$w->fetchColumn();
        if ($websiteId <= 0) { return null; }
        require_once __DIR__ . '/MiaStoryWriter.php';
        return function (int $actionId) use ($db, $siteId, $websiteId): void {
            $writer = new MiaStoryWriter($db, $siteId, new MiaLlmGateway($db, $siteId), new MiaActionService($db, $siteId));
            $r = $writer->prepare($actionId, $websiteId);
            if (!$r['ok']) { error_log('[mia_events] no story wording for action ' . $actionId . ': ' . (string)$r['code']); }
        };
    }

    /**
     * What the cron calls: drain every site that has a verified website. A failure on one site never stops the others.
     * @return array{status:string,summary:string,errors:string[]}
     */
    public static function drainAll(PDO $db, ?DateTimeImmutable $now = null): array
    {
        $now = $now ?: new DateTimeImmutable('now');
        $sites = $db->query("SELECT DISTINCT site_id FROM mia_websites WHERE status = 'verified' ORDER BY site_id")->fetchAll(PDO::FETCH_COLUMN);
        if (!$sites) { return ['status' => 'success', 'summary' => 'No verified websites to work on', 'errors' => []]; }
        $tot = ['swept' => 0, 'processed' => 0, 'proposed' => 0, 'skipped' => 0, 'failed' => 0, 'waiting' => 0]; $errors = [];
        foreach ($sites as $site) {
            try {
                $r = (new self($db, (int)$site, null, null, self::storyDrafter($db, (int)$site)))->drain(20, $now);
                foreach ($tot as $k => $_) { $tot[$k] += $r[$k]; }
                foreach ($r['errors'] as $e) { $errors[] = $e; }
            } catch (Throwable $e) {
                $errors[] = 'site ' . (int)$site . ': ' . get_class($e) . ': ' . substr($e->getMessage(), 0, 120) . ' at ' . basename($e->getFile()) . ':' . $e->getLine();
            }
        }
        $summary = sprintf('%d new event(s), %d looked at, %d proposed, %d skipped by the gates, %d waiting (Mia paused), %d failed', $tot['swept'], $tot['processed'], $tot['proposed'], $tot['skipped'], $tot['waiting'], $tot['failed']);
        if ($errors) { $summary .= '. Problems: ' . implode('; ', $errors); }
        $status = $errors ? ($tot['processed'] === 0 || $tot['failed'] > 0 ? 'error' : 'warning') : 'success';
        return ['status' => $status, 'summary' => $summary, 'errors' => $errors];
    }
}
