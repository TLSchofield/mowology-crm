<?php
/**
 * MiaPublishService — applies an owner-approved title and description to the website, and undoes it (Phase 2.1).
 *
 * The rules, each enforced here and each covered by a test:
 *   - only an action in status 'approved' whose draft hash still matches the draft text is applied;
 *   - the page must be one the connector can really change, and must still hold the values Mia read (compare-and-set);
 *   - the live page must show those old values first, and the new ones afterwards (a saved change nobody can see is reported);
 *   - the before and after values are kept in mia_executions, so rollback restores exactly what was there;
 *   - rollback refuses if the page was edited again after Mia's change.
 * Nothing in here runs on a schedule. Every call starts from the owner's click.
 */
require_once __DIR__ . '/Site/WebsiteConnector.php';

class MiaPublishService
{
    private PDO $db;
    private int $siteId;
    private MiaActionService $actions;
    private WebsiteConnector $connector;
    /** @var callable fn(string $url): ?array{title:?string,meta_desc:?string}  null when the page could not be read */
    private $liveReader;

    public function __construct(PDO $db, int $siteId, MiaActionService $actions, WebsiteConnector $connector, ?callable $liveReader = null)
    {
        $this->db = $db;
        $this->siteId = $siteId;
        $this->actions = $actions;
        $this->connector = $connector;
        $this->liveReader = $liveReader ?: [self::class, 'readLive'];
    }

    /** Default live reader: a polite SSRF-safe fetch of the public page. */
    public static function readLive(string $url): ?array
    {
        if (!class_exists('SafeHttpFetcher') || !class_exists('SiteCrawler')) { return null; }
        $origin = parse_url($url, PHP_URL_SCHEME) . '://' . parse_url($url, PHP_URL_HOST);
        $r = (new SafeHttpFetcher())->fetch($url, ['allowed_types' => ['text/html', 'application/xhtml+xml'], 'max_bytes' => 1500000, 'timeout' => 10,
                                                   'headers' => ['Cache-Control' => 'no-cache'], 'same_host' => true]);
        if (empty($r['ok']) || (int)$r['status'] !== 200) { return null; }
        $x = SiteCrawler::extract((string)$r['body'], $url, $origin);
        return ['title' => $x['title'], 'meta_desc' => $x['meta_desc']];
    }

    private static function norm(?string $s): string
    {
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode((string)$s, ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
    }

    private function fail(string $code, string $error, array $extra = []): array
    {
        return ['ok' => false, 'code' => $code, 'error' => $error] + $extra;
    }

    /** Text the owner is shown before approving: what the page says now, what it would say. No change is made. */
    public function preview(int $actionId): array
    {
        $ctx = $this->load($actionId, false);
        if (!$ctx['ok']) { return $ctx; }
        $d = $ctx['draft'];
        return ['ok' => true, 'page_url' => $d['page_url'], 'can_apply' => $ctx['can_apply'], 'why_not' => $ctx['why_not'],
                'before' => ['title' => $d['current_title'], 'description' => $d['current_desc']],
                'after'  => ['title' => $d['title'], 'description' => $d['meta_desc']],
                'title_length' => mb_strlen((string)$d['title']), 'description_length' => mb_strlen((string)$d['meta_desc'])];
    }

    /** Load the action, its draft and the page, and say whether applying is possible right now. */
    private function load(int $actionId, bool $mustBeApproved): array
    {
        $a = $this->actions->get($actionId);
        if (!$a) { return $this->fail('not_found', 'No such action'); }
        if ($a['type'] !== 'seo_meta_update') { return $this->fail('not_supported', 'Only title and description changes can be applied so far.'); }
        if ($mustBeApproved && $a['status'] !== 'approved') { return $this->fail('not_approved', 'Approve it first. It is ' . $a['status'] . '.'); }
        if ($a['draft_ref_table'] !== 'mia_content_drafts' || empty($a['draft_ref_id'])) { return $this->fail('no_draft', 'There is no wording to apply.'); }
        $s = $this->db->prepare("SELECT * FROM mia_content_drafts WHERE id = ? AND site_id = ? AND action_id = ? AND status = 'draft'");
        $s->execute([(int)$a['draft_ref_id'], $this->siteId, $actionId]);
        $d = $s->fetch(PDO::FETCH_ASSOC);
        if (!$d) { return $this->fail('no_draft', 'The wording for this action is no longer available.'); }
        if (trim((string)$d['title']) === '' || trim((string)$d['meta_desc']) === '') { return $this->fail('no_draft', 'The wording is incomplete.'); }

        $loc = $this->connector->locate((string)$d['page_url']);
        $canApply = !empty($loc['ok']);
        $why = $canApply ? null : (string)($loc['reason'] ?? 'Mia cannot change that page.');
        $drift = null;
        if ($canApply) {
            $cur = $loc['current'];
            if (self::norm($cur['meta_title']) !== self::norm($d['current_title']) || self::norm($cur['meta_description']) !== self::norm($d['current_desc'])) {
                $canApply = false;
                $why = 'The page no longer says what Mia read when she wrote this. Ask her to look at it again.';
                $drift = 'page_changed';
            }
        }
        return ['ok' => true, 'action' => $a, 'draft' => $d, 'locate' => $loc, 'can_apply' => $canApply, 'why_not' => $why,
                'why_code' => $drift ?? ($canApply ? null : ($loc['code'] ?? 'cannot_apply'))];
    }

    /** @return array{ok:bool, code?:string, error?:string, execution_id?:int, status?:string, note?:string} */
    public function apply(int $actionId, int $userId, ?DateTimeImmutable $now = null): array
    {
        $now = $now ?: new DateTimeImmutable('now');
        $ctx = $this->load($actionId, true);
        if (!$ctx['ok']) { return $ctx; }
        $d = $ctx['draft'];

        $v = $this->actions->verifyApproval($actionId, MiaRecommendationWriter::draftText($d['title'], $d['meta_desc']), $now);
        if (empty($v['ok'])) { return $this->fail($v['code'] ?? 'not_approved', $v['error'] ?? 'Not approved'); }
        if (!$ctx['can_apply']) { return $this->fail($ctx['why_code'] ?? 'cannot_apply', (string)$ctx['why_not']); }

        // The live page must show the values Mia read. If a code file renders the page, the website editor's row is
        // not what visitors see and a change would be saved but invisible.
        $live = call_user_func($this->liveReader, (string)$d['page_url']);
        if ($live === null) { return $this->fail('live_unreadable', 'Could not read the live page just now, so nothing was changed. Try again in a minute.'); }
        if (self::norm($live['title']) !== self::norm($d['current_title']) || self::norm($live['meta_desc']) !== self::norm($d['current_desc'])) {
            return $this->fail('not_live_source', 'The live page does not show what the website editor holds for it, so a change there would not be seen. Apply the wording yourself.');
        }

        $ref = (string)$ctx['locate']['ref'];
        $before = $ctx['locate']['current'];
        $after = ['meta_title' => trim((string)$d['title']), 'meta_description' => trim((string)$d['meta_desc'])];
        $execId = 0;
        try {
            $this->db->beginTransaction();
            $r = $this->connector->applySeoMeta($ref, $before, $after, $userId);
            if (empty($r['ok'])) { throw new MiaPublishConflict((string)($r['error'] ?? 'The page could not be changed.')); }
            $this->db->prepare("INSERT INTO mia_executions (site_id, action_id, draft_id, website_id, connector, target_ref, page_url, before_json, after_json, status, applied_by, applied_at)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'applied', ?, ?)")
                ->execute([$this->siteId, $actionId, (int)$d['id'], (int)$d['website_id'], $this->connector->name(), $ref, $d['page_url'],
                           json_encode($before), json_encode($after), $userId, $now->format('Y-m-d H:i:s')]);
            $execId = (int)$this->db->lastInsertId();
            $this->db->prepare("UPDATE mia_content_drafts SET status = 'applied' WHERE id = ? AND site_id = ?")->execute([(int)$d['id'], $this->siteId]);
            if (!$this->actions->markExecuted($actionId, $userId, 'Applied to ' . $d['page_url'] . ' (execution #' . $execId . ')', $now)) {
                throw new MiaPublishConflict('This action was just changed by someone else. Nothing was applied.');
            }
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) { $this->db->rollBack(); }
            if ($e instanceof MiaPublishConflict) { return $this->fail('conflict', $e->getMessage()); }
            error_log('MiaPublishService apply: ' . get_class($e) . ' ' . $e->getMessage());
            return $this->fail('db', 'Mia could not apply the change. Nothing was changed.');
        }

        $this->connector->afterChange($ref);
        $res = $this->verifyLive($execId, $d, $after);
        return ['ok' => true, 'execution_id' => $execId] + $res;
    }

    /** Read the live page again and record whether it shows the new wording. */
    private function verifyLive(int $execId, array $draft, array $after): array
    {
        $live = null;
        try { $live = call_user_func($this->liveReader, (string)$draft['page_url']); } catch (Throwable $e) { error_log('MiaPublishService live read: ' . $e->getMessage()); }
        if ($live === null) { $status = 'unverified'; $note = 'The change is saved, but the live page could not be read to confirm it. Check it in a few minutes.'; }
        elseif (self::norm($live['title']) === self::norm($after['meta_title']) && self::norm($live['meta_desc']) === self::norm($after['meta_description'])) { $status = 'verified'; $note = 'The live page now shows the new title and description.'; }
        else { $status = 'unverified'; $note = 'The change is saved, but the live page still shows the old wording. It may be cached. Check it in a few minutes.'; }
        $this->db->prepare("UPDATE mia_executions SET status = ?, verify_note = ? WHERE id = ? AND site_id = ?")->execute([$status, $note, $execId, $this->siteId]);
        return ['status' => $status, 'note' => $note];
    }

    /** Put the page back exactly as it was before Mia's change. Refuses if the page was edited after that change. */
    public function rollback(int $executionId, int $userId, ?DateTimeImmutable $now = null): array
    {
        $now = $now ?: new DateTimeImmutable('now');
        $s = $this->db->prepare("SELECT * FROM mia_executions WHERE id = ? AND site_id = ?");
        $s->execute([$executionId, $this->siteId]);
        $x = $s->fetch(PDO::FETCH_ASSOC);
        if (!$x) { return $this->fail('not_found', 'No such change'); }
        if (!in_array($x['status'], ['applied', 'verified', 'unverified'], true)) { return $this->fail('not_undoable', 'That change is already ' . $x['status'] . '.'); }
        $before = json_decode((string)$x['before_json'], true);
        $after = json_decode((string)$x['after_json'], true);
        if (!is_array($before) || !is_array($after)) { return $this->fail('no_snapshot', 'The saved copy of the old wording is missing, so it cannot be restored here.'); }
        try {
            $this->db->beginTransaction();
            $r = $this->connector->applySeoMeta((string)$x['target_ref'], $after, $before, $userId);
            if (empty($r['ok'])) { throw new MiaPublishConflict('The page was edited after Mia changed it, so it was not rolled back. Edit it by hand in the website editor.'); }
            $u = $this->db->prepare("UPDATE mia_executions SET status = 'rolled_back', rolled_back_by = ?, rolled_back_at = ? WHERE id = ? AND site_id = ? AND status IN ('applied','verified','unverified')");
            $u->execute([$userId, $now->format('Y-m-d H:i:s'), $executionId, $this->siteId]);
            if ($u->rowCount() !== 1) { throw new MiaPublishConflict('That change was just undone by someone else.'); }
            $this->actions->markRolledBack((int)$x['action_id'], $userId, 'Undone: ' . $x['page_url'] . ' put back as it was (execution #' . $executionId . ')', $now);
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) { $this->db->rollBack(); }
            if ($e instanceof MiaPublishConflict) { return $this->fail('conflict', $e->getMessage()); }
            error_log('MiaPublishService rollback: ' . get_class($e) . ' ' . $e->getMessage());
            return $this->fail('db', 'Mia could not undo the change. Nothing was changed.');
        }
        $this->connector->afterChange((string)$x['target_ref']);
        return ['ok' => true, 'execution_id' => $executionId, 'status' => 'rolled_back'];
    }

    /** @return array[] newest first */
    public function executionsFor(int $actionId): array
    {
        $s = $this->db->prepare("SELECT id, status, page_url, verify_note, applied_at, rolled_back_at FROM mia_executions WHERE action_id = ? AND site_id = ? ORDER BY id DESC");
        $s->execute([$actionId, $this->siteId]);
        return $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

class MiaPublishConflict extends RuntimeException {}
