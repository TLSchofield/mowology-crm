<?php
/**
 * MiaApiHandler — the new "approval queue" and "website" modes of Marketing/Api/mia.php (Phase 1.12).
 *
 *   GET  websites                 the connected websites, with how to prove ownership for pending ones
 *   GET  health                   per website: pages crawled, last crawl, open findings by severity, top problems, Search Console link
 *   POST connect_website          {address}               register a website, returns the token and instructions
 *   POST verify_website           {id, method?}           check the proof (meta | file | dns | any)
 *   POST disconnect_website       {id}
 *   GET  actions                  ?status=proposed|approved|rejected|expired|all   the queue, with the draft wording
 *   GET  action_detail            ?id=                    one proposal, its draft, its audit trail
 *   POST draft_edit               {id, title, meta_description}   the owner changes the wording (before approving)
 *   POST action_decide            {id, decision: approve|reject|later, reason_code?, reason_text?, seen_hash?}
 *
 * The endpoint file does login, the marketing.approve permission and the CSRF check BEFORE calling this class; this
 * class trusts none of what the browser sends beyond the ids and the owner's choices:
 *   - the signed-in user id comes from the session, never from the request;
 *   - approval is tied to the draft text read from the database, and the browser must send the hash of what it showed
 *     (seen_hash). If the wording changed since, the approval is refused (409) and nothing is approved;
 *   - nothing here publishes or sends anything: approving only records the owner's decision.
 * Every query goes through services that bind site_id. handle() returns [http status, body] and does not throw.
 *
 * Global namespace, no autoloader: require_once it. PHP 7.4-compatible.
 */
declare(strict_types=1);

require_once __DIR__ . '/MiaWebsiteService.php';
require_once __DIR__ . '/MiaActionService.php';
require_once __DIR__ . '/MiaMemoryService.php';
require_once __DIR__ . '/MiaRecommendationWriter.php';
require_once __DIR__ . '/MiaOpportunityScorer.php';
require_once __DIR__ . '/Site/SiteAuditRules.php';
require_once __DIR__ . '/Site/CmsConnector.php';
require_once __DIR__ . '/MiaPublishService.php';

final class MiaApiHandler
{
    public const MODES_GET  = ['websites', 'health', 'actions', 'action_detail'];
    public const MODES_POST = ['connect_website', 'verify_website', 'disconnect_website', 'draft_edit', 'action_decide', 'action_apply', 'action_rollback'];
    /** Single-tenant today: the platform has no "current tenant" lookup yet (docs/plans/ittai-backlog.md). */
    public const SITE_ID = 1;
    public const MAX_WEBSITES = 10;
    public const VERIFY_COOLDOWN_SECONDS = 10;
    public const TYPE_LABELS = [
        'seo_meta_update' => 'Page title and description', 'page_draft' => 'Page content', 'project_story' => 'Project story',
        'gbp_post' => 'Google Business post', 'review_reply' => 'Review reply', 'internal_link' => 'Internal links', 'fix_technical' => 'Website fix',
    ];

    /** @var PDO */
    private $db;
    /** @var int */
    private $siteId;
    /** @var int */
    private $userId;
    /** @var MiaWebsiteService */
    private $websites;
    /** @var MiaActionService */
    private $actions;
    /** @var callable|null fn(array $draft): MiaPublishService, replaced in tests */
    private $publisherFactory = null;

    public function __construct(PDO $db, int $userId, ?MiaWebsiteService $websites = null, ?MiaActionService $actions = null, int $siteId = self::SITE_ID)
    {
        if ($siteId <= 0) { throw new InvalidArgumentException('MiaApiHandler needs a site'); }
        $this->db       = $db;
        $this->siteId   = $siteId;
        $this->userId   = $userId;
        $this->websites = $websites ?: new MiaWebsiteService($db, $siteId);
        $this->actions  = $actions ?: new MiaActionService($db, $siteId);
    }

    public function setPublisherFactory(callable $f): void
    {
        $this->publisherFactory = $f;
    }

    /** Applying changes needs migration 1403. Everything else keeps working without it. */
    private function executionsReady(): bool
    {
        try { $this->db->query("SELECT 1 FROM mia_executions LIMIT 0"); return true; } catch (Throwable $e) { return false; }
    }

    /** The publisher for the website a draft belongs to, or null when that website is not connected and verified. */
    private function publisherFor(?array $draft): ?MiaPublishService
    {
        if (!$draft) { return null; }
        if ($this->publisherFactory !== null) { return call_user_func($this->publisherFactory, $draft); }
        $w = $this->db->prepare("SELECT host FROM mia_websites WHERE id = ? AND site_id = ? AND status = 'verified'");
        $w->execute([(int)$draft['website_id'], $this->siteId]);
        $host = $w->fetchColumn();
        if (!is_string($host) || $host === '') { return null; }
        return new MiaPublishService($this->db, $this->siteId, $this->actions, new CmsConnector($this->db, $host));
    }

    /** A request id: a positive integer, or a plain decimal string of one. Arrays, floats, signs and junk become 0 (= not found). */
    public static function id($v): int
    {
        if (is_int($v)) { return $v > 0 ? $v : 0; }
        if (is_string($v) && preg_match('/^[1-9][0-9]{0,9}$/', $v)) { return (int)$v; }
        return 0;
    }

    public static function handles(string $mode): bool
    {
        return in_array($mode, self::MODES_GET, true) || in_array($mode, self::MODES_POST, true);
    }

    /** @return array{0:int,1:array} http status and JSON body */
    public function handle(string $mode, string $method, array $query, array $input, ?DateTimeImmutable $now = null): array
    {
        $now = $now ?: new DateTimeImmutable('now');
        $isPost = $method === 'POST';
        if (in_array($mode, self::MODES_POST, true) && !$isPost) { return [405, ['ok' => false, 'error' => 'This needs a POST']]; }
        if (in_array($mode, self::MODES_GET, true) && $method !== 'GET') { return [405, ['ok' => false, 'error' => 'This needs a GET']]; }
        if ($this->userId <= 0) { return [403, ['ok' => false, 'error' => 'Not signed in']]; }
        if ($missing = $this->missingTables()) {
            return [503, ['ok' => false, 'code' => 'not_ready', 'error' => 'Mia\'s new tables are not set up yet (migrations 1400-1402 have not been run): ' . implode(', ', $missing)]];
        }
        try {
            switch ($mode) {
                case 'websites':           return $this->websitesList();
                case 'health':             return $this->health();
                case 'connect_website':    return $this->connect($input);
                case 'verify_website':     return $this->verify($input, $now);
                case 'disconnect_website': return $this->disconnect($input);
                case 'actions':            return $this->actionsList($query);
                case 'action_detail':      return $this->actionDetail(self::id($query['id'] ?? null));
                case 'draft_edit':         return $this->draftEdit($input, $now);
                case 'action_decide':      return $this->decide($input, $now);
                case 'action_apply':       return $this->applyAction($input, $now);
                case 'action_rollback':    return $this->rollbackAction($input, $now);
            }
            return [400, ['ok' => false, 'error' => 'Unknown mode']];
        } catch (Throwable $e) {
            error_log('MiaApiHandler ' . $mode . ': ' . get_class($e) . ' ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
            return [500, ['ok' => false, 'error' => 'Mia hit a problem. See the error log.']];
        }
    }

    /** @return string[] tables these modes need that do not exist yet */
    private function missingTables(): array
    {
        $missing = [];
        foreach (['mia_websites', 'mia_actions', 'mia_action_events', 'mia_decisions', 'mia_content_drafts'] as $t) {
            try { $this->db->query("SELECT 1 FROM {$t} LIMIT 0"); } catch (Throwable $e) { $missing[] = $t; }
        }
        return $missing;
    }

    // ---- websites -------------------------------------------------------------------------------------

    private function websitesList(): array
    {
        $out = [];
        foreach ($this->websites->listAll() as $w) {
            $row = ['id' => (int)$w['id'], 'address' => $w['base_url'], 'host' => $w['host'], 'status' => $w['status'],
                    'verified_via' => $w['verified_via'], 'verified_at' => $w['verified_at'], 'last_error' => $w['last_verify_error'],
                    'can_crawl' => $this->websites->isVerified((int)$w['id'])];
            if ($w['status'] !== 'verified') {                      // the proof is only needed (and only shown) until verified
                $full = $this->websites->get((int)$w['id']);
                $row['instructions'] = $full ? MiaWebsiteService::instructions((string)$w['host'], (string)$full['verify_token']) : null;
            }
            $out[] = $row;
        }
        return [200, ['ok' => true, 'websites' => $out]];
    }

    /** What Mia knows about each connected website: the Presence tab. Facts only; nothing is estimated here. */
    private function health(): array
    {
        $out = [];
        foreach ($this->websites->listAll() as $w) {
            $wid = (int)$w['id'];
            $row = ['id' => $wid, 'host' => $w['host'], 'status' => $w['status'], 'can_crawl' => $this->websites->isVerified($wid),
                    'pages' => 0, 'last_crawl' => null, 'findings' => ['high' => 0, 'medium' => 0, 'low' => 0], 'problems' => [], 'search_console' => ['connected' => false, 'last_data' => null]];
            try {
                $s = $this->db->prepare("SELECT COUNT(*) FROM mia_site_pages WHERE site_id = ? AND website_id = ? AND status_code = 200");
                $s->execute([$this->siteId, $wid]);
                $row['pages'] = (int)$s->fetchColumn();
                $a = $this->db->prepare("SELECT status, started_at, finished_at, summary FROM mia_audits WHERE site_id = ? AND website_id = ? AND kind = 'crawl' ORDER BY started_at DESC, id DESC LIMIT 1");
                $a->execute([$this->siteId, $wid]);
                $last = $a->fetch(PDO::FETCH_ASSOC);
                if ($last) { $row['last_crawl'] = ['status' => $last['status'], 'at' => $last['finished_at'] ?: $last['started_at'], 'summary' => $last['summary']]; }
                $f = $this->db->prepare("SELECT rule_key, severity, COUNT(*) AS n FROM mia_audit_findings WHERE site_id = ? AND website_id = ? AND resolved_at IS NULL AND severity <> 'info'
                                         GROUP BY rule_key, severity");
                $f->execute([$this->siteId, $wid]);
                $rank = ['high' => 0, 'medium' => 1, 'low' => 2];
                $groups = [];
                foreach ($f->fetchAll(PDO::FETCH_ASSOC) as $g) {
                    if (!isset($rank[$g['severity']])) { continue; }
                    $row['findings'][$g['severity']] += (int)$g['n'];
                    $meta = SiteAuditRules::RULES[$g['rule_key']] ?? null;
                    $groups[] = ['rule' => $g['rule_key'], 'severity' => $g['severity'], 'pages' => (int)$g['n'],
                                 'label' => $meta ? $meta[1] : $g['rule_key'], 'why' => $meta ? $meta[2] : ''];
                }
                usort($groups, function ($x, $y) use ($rank) { return [$rank[$x['severity']], -$x['pages'], $x['rule']] <=> [$rank[$y['severity']], -$y['pages'], $y['rule']]; });
                $row['problems'] = array_slice($groups, 0, 8);
                foreach ($this->db->query("SELECT id, site_url FROM gsc_properties")->fetchAll(PDO::FETCH_ASSOC) as $p) {
                    if (MiaOpportunityScorer::propertyMatchesHost((string)$p['site_url'], (string)$w['host'])) {
                        $row['search_console']['connected'] = true;
                        $d = $this->db->prepare("SELECT MAX(snapshot_date) FROM gsc_snapshots WHERE property_id = ?");
                        $d->execute([(int)$p['id']]);
                        $row['search_console']['last_data'] = $d->fetchColumn() ?: null;
                        break;
                    }
                }
            } catch (Throwable $e) {
                error_log('MiaApiHandler health: ' . $e->getMessage());              // tables from a later migration may be missing: show what we have
            }
            $out[] = $row;
        }
        return [200, ['ok' => true, 'websites' => $out]];
    }

    private function connect(array $in): array
    {
        $address = is_string($in['address'] ?? null) ? $in['address'] : '';
        if (count($this->websites->listAll()) >= self::MAX_WEBSITES) {
            return [422, ['ok' => false, 'code' => 'too_many', 'error' => 'That is the most websites that can be connected']];
        }
        $r = $this->websites->connect($address, $this->userId);
        if (!$r['ok']) { return [422, ['ok' => false, 'code' => $r['code'], 'error' => $r['error']]]; }
        return [200, ['ok' => true, 'id' => $r['id'], 'existing' => $r['existing'], 'instructions' => $r['instructions']]];
    }

    private function verify(array $in, DateTimeImmutable $now): array
    {
        $id = self::id($in['id'] ?? null);
        $w = $this->websites->get($id);
        if (!$w) { return [404, ['ok' => false, 'code' => 'not_found', 'error' => 'No such website']]; }
        // Claim the attempt atomically: parallel requests cannot all pass the cooldown.
        if (!$this->websites->claimVerifyAttempt($id, $now, self::VERIFY_COOLDOWN_SECONDS)) {
            return [429, ['ok' => false, 'code' => 'too_soon', 'error' => 'Please wait a few seconds before checking again']];
        }
        $method = is_string($in['method'] ?? null) ? $in['method'] : 'any';
        $r = $this->websites->verify($id, $method, $now);
        if ($r['ok']) { return [200, ['ok' => true, 'verified' => true, 'method' => $r['method']]]; }
        $http = in_array($r['code'], ['not_found'], true) ? 404 : 422;
        return [$http, ['ok' => false, 'verified' => false, 'code' => $r['code'], 'error' => $r['error']]];
    }

    private function disconnect(array $in): array
    {
        $ok = $this->websites->disconnect(self::id($in['id'] ?? null));
        return $ok ? [200, ['ok' => true]] : [404, ['ok' => false, 'code' => 'not_found', 'error' => 'No such website']];
    }

    // ---- the queue ------------------------------------------------------------------------------------

    private function actionsList(array $q): array
    {
        $status = array_key_exists('status', $q) ? $q['status'] : 'proposed';
        if (!is_string($status)) { return [422, ['ok' => false, 'error' => 'Unknown status']]; }
        if (!in_array($status, ['proposed', 'approved', 'rejected', 'expired', 'done', 'all'], true)) { return [422, ['ok' => false, 'error' => 'Unknown status']]; }
        $items = [];
        $rows = $this->actions->listByStatus($status, 50);
        if ($status === 'done') {                       // "Applied" also lists what was undone, so its history stays reachable
            $rows = array_merge($rows, $this->actions->listByStatus('rolled_back', 50));
            usort($rows, function ($x, $y) { return (int)$y['id'] <=> (int)$x['id']; });
            $rows = array_slice($rows, 0, 50);
        }
        foreach ($rows as $a) { $items[] = $this->view($a, false); }
        return [200, ['ok' => true, 'status' => $status, 'items' => $items, 'counts' => $this->actions->counts()]];
    }

    private function actionDetail(int $id): array
    {
        $a = $id > 0 ? $this->actions->get($id) : null;
        if (!$a) { return [404, ['ok' => false, 'code' => 'not_found', 'error' => 'No such proposal']]; }
        $v = $this->view($a, true);
        $v['events'] = $this->actions->events($id);
        return [200, ['ok' => true, 'item' => $v]];
    }

    /** What the browser shows for one proposal. Never includes hashes of the evidence or internal ids of other tables. */
    private function view(array $a, bool $full): array
    {
        $draft = $this->draftOf($a);
        $v = [
            'id' => (int)$a['id'], 'type' => $a['type'], 'type_label' => self::TYPE_LABELS[$a['type']] ?? $a['type'], 'status' => $a['status'],
            'title' => $a['title'], 'why' => $a['why'], 'expected_benefit' => $a['expected_benefit'], 'risk' => $a['risk'],
            'score' => $a['score'] !== null ? (float)$a['score'] : null, 'proposed_at' => $a['proposed_at'], 'decided_at' => $a['decided_at'],
            'decision_note' => $a['decision_note'], 'autonomy_level' => (int)$a['autonomy_level'],
            'can_decide' => $a['status'] === 'proposed',
            'draft' => $draft ? [
                'id' => (int)$draft['id'], 'version' => (int)$draft['version'], 'page_url' => $draft['page_url'],
                'current_title' => $draft['current_title'], 'current_description' => $draft['current_desc'],
                'title' => $draft['title'], 'meta_description' => $draft['meta_desc'], 'rationale' => $draft['rationale'],
                'seen_hash' => sha1(MiaRecommendationWriter::draftText($draft['title'], $draft['meta_desc'])),
            ] : null,
            'seen_hash' => sha1($this->contentFor($a, $draft)),
        ];
        if ($full && $a['type'] === 'seo_meta_update' && in_array($a['status'], ['approved', 'done', 'rolled_back'], true)) {
            $pub = $this->executionsReady() ? $this->publisherFor($draft) : null;
            if (!$this->executionsReady()) {
                if ($a['status'] === 'approved') { $v['apply'] = ['can_apply' => false, 'why_not' => 'Applying changes needs one more setup step (migration 1403) that has not been run yet.']; }
            } elseif ($pub) {
                if ($a['status'] === 'approved') {
                    $p = $pub->preview((int)$a['id']);
                    $v['apply'] = ['can_apply' => !empty($p['ok']) && !empty($p['can_apply']), 'why_not' => $p['ok'] ? $p['why_not'] : ($p['error'] ?? null)];
                }
                $v['executions'] = $pub->executionsFor((int)$a['id']);
            } elseif ($a['status'] === 'approved') {
                $v['apply'] = ['can_apply' => false, 'why_not' => 'The website is not connected and verified, so Mia cannot change it.'];
            }
        }
        if ($full) {
            $v['evidence'] = json_decode((string)$a['evidence'], true) ?: [];
            $v['score_inputs'] = json_decode((string)$a['score_inputs'], true) ?: null;
        }
        return $v;
    }

    /** The draft row an action points at, if any (must belong to this site and action). */
    private function draftOf(array $a): ?array
    {
        if ($a['draft_ref_table'] !== 'mia_content_drafts' || empty($a['draft_ref_id'])) { return null; }
        $s = $this->db->prepare("SELECT * FROM mia_content_drafts WHERE id = ? AND site_id = ? AND action_id = ? AND status IN ('draft', 'applied')");
        $s->execute([(int)$a['draft_ref_id'], $this->siteId, (int)$a['id']]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    /** The exact text an approval is bound to: the draft wording, or (no draft) the proposal as the owner read it. */
    private function contentFor(array $a, ?array $draft): string
    {
        if ($draft) { return MiaRecommendationWriter::draftText($draft['title'], $draft['meta_desc']); }
        return "ACTION: {$a['title']}\nWHY: {$a['why']}\nBENEFIT: {$a['expected_benefit']}\nRISK: {$a['risk']}";
    }

    // ---- owner edits and decisions -----------------------------------------------------------------------

    private function draftEdit(array $in, DateTimeImmutable $now): array
    {
        $id = self::id($in['id'] ?? null);
        $a = $id > 0 ? $this->actions->get($id) : null;
        if (!$a) { return [404, ['ok' => false, 'code' => 'not_found', 'error' => 'No such proposal']]; }
        if ($a['status'] !== 'proposed') { return [409, ['ok' => false, 'code' => 'not_open', 'error' => 'This proposal is already ' . $a['status']]]; }
        $draft = $this->draftOf($a);
        if (!$draft) { return [422, ['ok' => false, 'code' => 'no_draft', 'error' => 'This proposal has no wording to edit']]; }

        $clean = function ($v, int $min, int $max): ?string {
            if (!is_string($v)) { return null; }
            $v = trim(preg_replace('/\s+/u', ' ', $v) ?? '');
            if (preg_match('/[\x00-\x1f\x7f<>]/u', $v)) { return null; }
            $n = mb_strlen($v, 'UTF-8');
            return ($n >= $min && $n <= $max) ? $v : null;
        };
        $title = $clean($in['title'] ?? null, 5, 120);
        $desc  = $clean($in['meta_description'] ?? null, 20, 320);
        if ($title === null || $desc === null) {
            return [422, ['ok' => false, 'code' => 'bad_text', 'error' => 'Title (5-120 characters) and description (20-320 characters) are needed, without HTML']];
        }
        if ($title === $draft['title'] && $desc === $draft['meta_desc']) { return [200, ['ok' => true, 'unchanged' => true, 'item' => $this->view($a, false)]]; }

        // The edit and its audit entry succeed or fail together.
        $own = !$this->db->inTransaction();
        if ($own) { $this->db->beginTransaction(); }
        try {
            $locked = $this->actions->lockForEdit($id);                // an approval holds this lock while it checks and stores the wording
            if (!$locked || $locked['status'] !== 'proposed') { throw new RuntimeException('no longer open'); }
            $u = $this->db->prepare("UPDATE mia_content_drafts SET title = ?, meta_desc = ? WHERE id = ? AND site_id = ? AND action_id = ? AND status = 'draft'");
            $u->execute([$title, $desc, (int)$draft['id'], $this->siteId, $id]);
            if ($u->rowCount() !== 1 || !$this->actions->recordEdit($id, $this->userId, 'Owner edited the draft wording', $now)) {
                throw new RuntimeException('edit could not be recorded');
            }
            if ($own) { $this->db->commit(); }
        } catch (Throwable $e) {
            if ($own && $this->db->inTransaction()) { $this->db->rollBack(); }
            return [409, ['ok' => false, 'code' => 'not_open', 'error' => 'The wording could not be changed. The proposal may have just been decided.']];
        }
        return [200, ['ok' => true, 'item' => $this->view($this->actions->get($id) ?: $a, false)]];
    }

    private function decide(array $in, DateTimeImmutable $now): array
    {
        $id = self::id($in['id'] ?? null);
        $decision = is_string($in['decision'] ?? null) ? $in['decision'] : '';
        if (!in_array($decision, ['approve', 'reject', 'later'], true)) { return [422, ['ok' => false, 'code' => 'bad_decision', 'error' => 'Choose approve, reject or later']]; }
        $a = $id > 0 ? $this->actions->get($id) : null;
        if (!$a) { return [404, ['ok' => false, 'code' => 'not_found', 'error' => 'No such proposal']]; }

        $reasonCode = is_string($in['reason_code'] ?? null) ? $in['reason_code'] : null;
        $reasonText = is_string($in['reason_text'] ?? null) ? mb_substr(trim($in['reason_text']), 0, 400) : null;
        $content = null;
        if ($decision === 'approve') {
            $content = $this->contentFor($a, $this->draftOf($a));
            // The browser must have shown exactly this text. If the wording changed since (an edit in another tab,
            // a newer draft), nothing is approved and the owner looks again.
            $seen = is_string($in['seen_hash'] ?? null) ? $in['seen_hash'] : '';
            if ($seen === '' || !hash_equals(sha1($content), $seen)) {
                return [409, ['ok' => false, 'code' => 'changed', 'error' => 'The wording changed since you opened it. Please look at it again before approving.', 'item' => $this->view($a, false)]];
            }
        }
        $fresh = $decision === 'approve' ? function (array $row) { return $this->contentFor($row, $this->draftOf($row)); } : null;
        $r = $this->actions->decide($id, $this->userId, $decision, $reasonCode, $reasonText, $content, $now, $fresh);
        if (!$r['ok'] && $r['code'] === 'changed') {
            return [409, ['ok' => false, 'code' => 'changed', 'error' => 'The wording changed since you opened it. Please look at it again before approving.', 'item' => $this->view($this->actions->get($id) ?: $a, false)]];
        }
        if ($r['ok']) { return [200, ['ok' => true, 'item' => $this->view($this->actions->get($id) ?: $a, false), 'message' => $decision === 'approve' ? 'Approved. Nothing has changed on your website yet. Open it under Approved to apply it.' : 'Saved.']]; }
        $map = ['not_found' => 404, 'not_open' => 409, 'no_user' => 403, 'db' => 500];
        return [$map[$r['code']] ?? 422, ['ok' => false, 'code' => $r['code'], 'error' => $r['error']]];
    }

    // ---- applying an approved change ------------------------------------------------------------------------

    private function applyAction(array $in, DateTimeImmutable $now): array
    {
        if (!$this->executionsReady()) { return [503, ['ok' => false, 'code' => 'not_ready', 'error' => 'Applying changes needs migration 1403, which has not been run yet.']]; }
        $id = self::id($in['id'] ?? null);
        $a = $id > 0 ? $this->actions->get($id) : null;
        if (!$a) { return [404, ['ok' => false, 'code' => 'not_found', 'error' => 'No such proposal']]; }
        $pub = $this->publisherFor($this->draftOf($a));
        if (!$pub) { return [409, ['ok' => false, 'code' => 'no_website', 'error' => 'The website is not connected and verified, so Mia cannot change it.']]; }
        $r = $pub->apply($id, $this->userId, $now);
        if (empty($r['ok'])) {
            $map = ['not_found' => 404, 'not_approved' => 409, 'draft_changed' => 409, 'conflict' => 409, 'db' => 500];
            return [$map[$r['code'] ?? ''] ?? 422, ['ok' => false, 'code' => $r['code'] ?? 'failed', 'error' => $r['error'] ?? 'Could not apply it']];
        }
        return [200, ['ok' => true, 'status' => $r['status'], 'note' => $r['note'], 'item' => $this->view($this->actions->get($id) ?: $a, true)]];
    }

    private function rollbackAction(array $in, DateTimeImmutable $now): array
    {
        if (!$this->executionsReady()) { return [503, ['ok' => false, 'code' => 'not_ready', 'error' => 'Undoing changes needs migration 1403, which has not been run yet.']]; }
        $execId = self::id($in['execution_id'] ?? null);
        $s = $this->db->prepare("SELECT action_id FROM mia_executions WHERE id = ? AND site_id = ?");
        $s->execute([$execId, $this->siteId]);
        $actionId = (int)$s->fetchColumn();
        $a = $actionId > 0 ? $this->actions->get($actionId) : null;
        if (!$a) { return [404, ['ok' => false, 'code' => 'not_found', 'error' => 'No such change']]; }
        $pub = $this->publisherFor($this->draftOf($a));
        if (!$pub) { return [409, ['ok' => false, 'code' => 'no_website', 'error' => 'The website is not connected and verified, so Mia cannot undo it.']]; }
        $r = $pub->rollback($execId, $this->userId, $now);
        if (empty($r['ok'])) {
            $map = ['not_found' => 404, 'not_undoable' => 409, 'conflict' => 409, 'db' => 500];
            return [$map[$r['code'] ?? ''] ?? 422, ['ok' => false, 'code' => $r['code'] ?? 'failed', 'error' => $r['error'] ?? 'Could not undo it']];
        }
        return [200, ['ok' => true, 'status' => 'rolled_back', 'item' => $this->view($this->actions->get($actionId) ?: $a, true)]];
    }
}
