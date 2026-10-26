<?php
/**
 * MiaActionService — the approval ledger (docs/mia/MIA-TOOLS.md: action.propose / action.decide).
 *
 * Every change Mia wants to make becomes a row in mia_actions. Only a signed-in person can approve or
 * reject it (decide() refuses a missing user, so a cron or a model can never approve). The approval is
 * bound to the exact draft by hash: if the draft changes afterwards, verifyApproval() says no and the owner
 * must approve again. Every step is appended to mia_action_events and is never deleted.
 *
 * Phase 1 has no executor. Nothing here publishes or sends anything.
 *
 * Every query binds site_id; rows of another site are invisible. Methods return result arrays and do not
 * throw (the constructor refuses site 0). Global namespace, no autoloader: require_once it.
 * PHP 7.4-compatible.
 */
declare(strict_types=1);

require_once __DIR__ . '/MiaAutonomyPolicy.php';
require_once __DIR__ . '/MiaMemoryService.php';

final class MiaActionService
{
    public const TYPES = ['seo_meta_update', 'page_draft', 'project_story', 'gbp_post', 'review_reply', 'internal_link', 'fix_technical'];
    public const SIDE_EFFECTS = ['none', 'internal', 'external_reversible', 'external_irreversible', 'spend'];
    public const OPEN_STATUSES = ['proposed', 'approved'];
    /** Tables a draft reference may point at. Anything else is refused. */
    public const DRAFT_TABLES = ['seo_recommendations', 'mia_suggestions', 'mia_campaigns', 'mia_channel_drafts', 'mia_content_drafts'];
    public const STALE_DAYS = 45;

    /** Words that promise a result. Benefits are estimates; they are never promised. */
    private const PROMISE = '/(?<!\w)(guarantee[sd]?|guaranteed|will\s+rank|ensure[sd]?\s+(?:a\s+)?rank|certain\s+to|number\s+one|no\.?\s*1|#\s*1|first\s+page|top\s+of\s+google|100\s*%|risk[- ]free)(?!\w)/i';

    /** @var PDO */
    private $db;
    /** @var int */
    private $siteId;
    /** @var MiaAutonomyPolicy */
    private $policy;
    /** @var MiaMemoryService */
    private $memory;
    /** @var callable fn(int $userId): bool  does this id belong to a real, active user? */
    private $userCheck;

    /**
     * The policy and memory services are built here from the same connection and site: they cannot be swapped
     * for ones pointing at another site or database.
     *
     * @param callable|null $userCheck  Defaults to looking the id up in the users table (fails closed).
     *        The CALLER (the API layer) is still responsible for authentication, CSRF and the
     *        marketing.approve permission: this class only refuses ids that are not real users.
     */
    public function __construct(PDO $db, int $siteId, ?callable $userCheck = null)
    {
        if ($siteId <= 0) { throw new InvalidArgumentException('MiaActionService needs a site'); }
        $this->db     = $db;
        $this->siteId = $siteId;
        $this->policy = new MiaAutonomyPolicy($db, $siteId);
        $this->memory = new MiaMemoryService($db, $siteId);
        $this->userCheck = $userCheck ?: function (int $id) use ($db): bool {
            try {
                $s = $db->prepare("SELECT 1 FROM users WHERE id = ?");
                $s->execute([$id]);
                return $s->fetchColumn() !== false;
            } catch (Throwable $e) {
                return false;                                  // cannot prove the user exists: refuse
            }
        };
    }

    // ---- pure helpers -------------------------------------------------------------------------

    /** Stable hash of the facts that justify an action. Callers pass COARSE facts (buckets, not live counters). */
    public static function hashEvidence(array $facts): string
    {
        $norm = function ($v) use (&$norm) {
            if (is_array($v)) {
                $isList = $v === [] || array_keys($v) === range(0, count($v) - 1);
                if (!$isList) { ksort($v); }
                return array_map($norm, $v);
            }
            return is_float($v) ? round($v, 4) : $v;
        };
        return sha1((string)json_encode($norm($facts)));
    }

    /** Hash of a draft's content at the moment of approval. */
    public static function hashDraft(string $content): string
    {
        return sha1($content);
    }

    /** @return string[] problems with an "expected benefit" sentence */
    public static function benefitProblems(string $text): array
    {
        return preg_match(self::PROMISE, $text) ? ['Benefits must be estimates, not promises'] : [];
    }

    // ---- propose ------------------------------------------------------------------------------

    /**
     * @param array $a type, side_effect, title, why, expected_benefit, risk, evidence(array), opportunity_key,
     *                 score, score_inputs(array), evidence_hash, draft_ref_table, draft_ref_id, preview_url
     * @return array{ok:bool,code:?string,error:?string,id:?int,existing:bool}
     */
    public function propose(array $a, ?DateTimeImmutable $now = null): array
    {
        $now  = $now ?: new DateTimeImmutable('now');
        $type = (string)($a['type'] ?? '');
        $side = (string)($a['side_effect'] ?? 'none');
        $key  = isset($a['opportunity_key']) ? trim((string)$a['opportunity_key']) : '';
        $title = trim((string)($a['title'] ?? ''));
        $why   = trim((string)($a['why'] ?? ''));
        $benefit = trim((string)($a['expected_benefit'] ?? ''));
        $risk  = trim((string)($a['risk'] ?? ''));
        $evidence = $a['evidence'] ?? [];

        if (!in_array($type, self::TYPES, true))            { return $this->fail('bad_type', 'Unknown action type'); }
        if (!in_array($side, self::SIDE_EFFECTS, true))     { return $this->fail('bad_side_effect', 'Unknown side effect'); }
        if ($title === '' || mb_strlen($title) > 160)       { return $this->fail('bad_title', 'Title is required (160 characters at most)'); }
        if ($why === '' || mb_strlen($why) > 600)           { return $this->fail('bad_why', 'A reason is required (600 characters at most)'); }
        if ($risk === '' || mb_strlen($risk) > 400)         { return $this->fail('bad_risk', 'A risk or uncertainty note is required'); }
        if (mb_strlen($benefit) > 400)                      { return $this->fail('bad_benefit', 'Benefit is too long'); }
        if (!is_array($evidence) || count($evidence) > 10)  { return $this->fail('bad_evidence', 'Evidence must be a list of at most 10 items'); }
        if ($key === '' || mb_strlen($key) > 120)           { return $this->fail('bad_key', 'An opportunity key is required'); }
        foreach ([$title, $why, $benefit] as $text) {
            if ($text !== '' && self::benefitProblems($text)) { return $this->fail('promise', 'Text promises a result: ' . self::benefitProblems($text)[0]); }
        }
        $refTable = isset($a['draft_ref_table']) && $a['draft_ref_table'] !== '' ? (string)$a['draft_ref_table'] : null;
        $refId    = isset($a['draft_ref_id']) ? (int)$a['draft_ref_id'] : null;
        if ($refTable !== null && (!in_array($refTable, self::DRAFT_TABLES, true) || !$refId)) {
            return $this->fail('bad_draft_ref', 'Draft reference not allowed');
        }
        $preview = isset($a['preview_url']) ? (string)$a['preview_url'] : null;
        if ($preview !== null && $preview !== '' && !preg_match('#^https://#i', $preview)) {
            return $this->fail('bad_preview', 'Preview must be an https address');
        }

        if (!$this->policy->mayPropose($type)) {
            return $this->fail('not_allowed', 'Mia is paused or set to observe only');
        }

        // The hash is ALWAYS derived here, from the coarse facts the caller says the idea rests on
        // (evidence_basis, falling back to the evidence itself) - a caller cannot pick its own hash to
        // slip past a rejection.
        $basis  = isset($a['evidence_basis']) && is_array($a['evidence_basis']) ? $a['evidence_basis'] : (array)$evidence;
        $evHash = self::hashEvidence($basis);

        $lock = $this->lockName($type, $key);
        if (!$this->acquire($lock)) { return $this->fail('busy', 'Another proposal for this is being saved. Try again.'); }
        try {
            return $this->atomic(function () use ($key, $type, $side, $title, $why, $benefit, $risk, $evidence, $a, $evHash, $refTable, $refId, $preview, $now) {
                // One open action per subject and type: a second proposal returns the first. Checked BEFORE
                // suppression so a decision landing in between cannot let a duplicate through.
                $s = $this->db->prepare("SELECT id FROM mia_actions WHERE site_id = ? AND opportunity_key = ? AND type = ? AND status IN ('proposed','approved') ORDER BY id DESC LIMIT 1");
                $s->execute([$this->siteId, $key, $type]);
                $existing = $s->fetchColumn();
                if ($existing !== false) {
                    return ['ok' => true, 'code' => null, 'error' => null, 'id' => (int)$existing, 'existing' => true];
                }
                $sup = $this->memory->suppression($key, $evHash, $now, $type);
                if ($sup['suppressed'] && $sup['reason'] === 'unreadable') { throw new RuntimeException('decision memory could not be read'); }
                if ($sup['suppressed']) {
                    return $this->fail('suppressed_' . $sup['reason'], 'Held back until ' . $sup['until'] . ' (' . $sup['reason'] . ')');
                }
                $this->db->prepare("INSERT INTO mia_actions
                    (site_id, opportunity_key, type, side_effect, status, title, why, expected_benefit, risk, evidence, score, score_inputs,
                     evidence_hash, draft_ref_table, draft_ref_id, preview_url, autonomy_level, proposed_by, proposed_at)
                    VALUES (?, ?, ?, ?, 'proposed', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'mia', ?)")
                    ->execute([
                        $this->siteId, $key, $type, $side, $title, $why, $benefit !== '' ? $benefit : null, $risk,
                        json_encode(array_values((array)$evidence)),
                        isset($a['score']) ? round((float)$a['score'], 2) : null,
                        isset($a['score_inputs']) ? json_encode($a['score_inputs']) : null,
                        $evHash, $refTable, $refId, $preview !== '' ? $preview : null,
                        $this->policy->levelFor($type), $now->format('Y-m-d H:i:s'),
                    ]);
                $id = (int)$this->db->lastInsertId();
                if (!$this->event($id, 'proposed', 'mia', null, $now)) { throw new RuntimeException('audit event could not be written'); }
                return ['ok' => true, 'code' => null, 'error' => null, 'id' => $id, 'existing' => false];
            });
        } catch (Throwable $e) {
            error_log('MiaActionService propose: ' . $e->getMessage());
            return $this->fail('db', 'Could not save the proposal');
        } finally {
            $this->release($lock);
        }
    }

    // ---- decide -------------------------------------------------------------------------------

    /**
     * The owner's decision. $userId must belong to a real user (the API layer authenticates the request and
     * checks marketing.approve): crons, events and model output have no user id, so they cannot approve.
     *
     * Approve REQUIRES $draftContent: the exact text the owner was shown. Its hash is stored, and the future
     * executor must hand verifyApproval() the content it reads from the draft row itself, not from a client.
     *
     * @param string $decision  approve | reject | later
     * @return array{ok:bool,code:?string,error:?string,id:?int,existing:bool}
     */
    public function decide(int $actionId, int $userId, string $decision, ?string $reasonCode = null, ?string $reasonText = null,
                           ?string $draftContent = null, ?DateTimeImmutable $now = null): array
    {
        $now = $now ?: new DateTimeImmutable('now');
        if ($userId <= 0 || !call_user_func($this->userCheck, $userId)) { return $this->fail('no_user', 'Only a signed-in person can decide'); }
        if (!in_array($decision, ['approve', 'reject', 'later'], true)) { return $this->fail('bad_decision', 'Unknown decision'); }
        if ($decision === 'approve' && ($draftContent === null || trim($draftContent) === '')) {
            return $this->fail('draft_required', 'Approval is tied to the exact text the owner saw');
        }
        if ($reasonCode !== null && !isset(MiaMemoryService::SUPPRESS_DAYS[$reasonCode])) { $reasonCode = 'other'; }

        try {
            return $this->atomic(function () use ($actionId, $userId, $decision, $reasonCode, $reasonText, $draftContent, $now) {
                $row = $this->row($actionId);
                if (!$row) { return $this->fail('not_found', 'No such action'); }
                if ($row['status'] !== 'proposed') { return $this->fail('not_open', 'This action is already ' . $row['status']); }

                $status = ['approve' => 'approved', 'reject' => 'rejected', 'later' => 'expired'][$decision];
                $hash   = $decision === 'approve' ? self::hashDraft((string)$draftContent) : null;

                // Guarded update: only a still-proposed row of this site changes, so two clicks cannot both win.
                $u = $this->db->prepare("UPDATE mia_actions SET status = ?, decided_by = ?, decided_at = ?, decision_note = ?, draft_hash = ?
                                          WHERE id = ? AND site_id = ? AND status = 'proposed'");
                $u->execute([$status, $userId, $now->format('Y-m-d H:i:s'), $reasonText !== null ? mb_substr($reasonText, 0, 400) : null,
                             $hash, $actionId, $this->siteId]);
                if ($u->rowCount() !== 1) { return $this->fail('not_open', 'This action was just decided'); }

                $logged = $this->event($actionId, $decision === 'later' ? 'snoozed' : $status, 'user:' . $userId,
                                       $reasonCode !== null ? $reasonCode . ($reasonText ? ': ' . $reasonText : '') : $reasonText, $now);
                $until = $this->memory->recordDecision((string)$row['opportunity_key'], (string)$row['type'], $row['evidence_hash'],
                                                       $decision === 'approve' ? 'approved' : ($decision === 'reject' ? 'rejected' : 'later'),
                                                       $reasonCode, $reasonText, $userId, $now);
                if (!$logged || $until === null) { throw new RuntimeException('audit or decision memory could not be written'); }
                return ['ok' => true, 'code' => null, 'error' => null, 'id' => $actionId, 'existing' => false];
            });
        } catch (Throwable $e) {
            error_log('MiaActionService decide: ' . $e->getMessage());
            return $this->fail('db', 'Could not save the decision');
        }
    }

    /**
     * For the future executor: is this action approved, by a person, for exactly this draft? Pass the draft
     * text the EXECUTOR read from the draft row (never text from a client). An edit after approval changes the
     * hash and voids the approval (the action goes back to 'proposed' and the change is logged once).
     */
    public function verifyApproval(int $actionId, string $currentDraftContent, ?DateTimeImmutable $now = null): array
    {
        $row = $this->row($actionId);
        if (!$row)                         { return $this->fail('not_found', 'No such action'); }
        if ($row['status'] !== 'approved') { return $this->fail('not_approved', 'Not approved'); }
        if (empty($row['decided_by']))     { return $this->fail('not_approved', 'No approving person on record'); }
        if ($row['draft_hash'] !== null && hash_equals((string)$row['draft_hash'], self::hashDraft($currentDraftContent))) {
            return ['ok' => true, 'code' => null, 'error' => null, 'id' => $actionId, 'existing' => false];
        }
        try {
            $this->atomic(function () use ($actionId, $now) {
                $u = $this->db->prepare("UPDATE mia_actions SET status = 'proposed', draft_hash = NULL WHERE id = ? AND site_id = ? AND status = 'approved'");
                $u->execute([$actionId, $this->siteId]);
                if ($u->rowCount() === 1 && !$this->event($actionId, 'reopened', 'system', 'Draft changed after approval', $now)) {
                    throw new RuntimeException('audit event could not be written');
                }
            });
        } catch (Throwable $e) {
            error_log('MiaActionService verifyApproval: ' . $e->getMessage());
        }
        return $this->fail('draft_changed', 'The draft changed after it was approved. Approve it again.');
    }

    // ---- read ---------------------------------------------------------------------------------

    public function get(int $actionId): ?array
    {
        return $this->row($actionId) ?: null;
    }

    /** Open actions, best score first. */
    public function listOpen(int $limit = 50): array
    {
        try {
            $s = $this->db->prepare("SELECT * FROM mia_actions WHERE site_id = ? AND status = 'proposed'
                                      ORDER BY (score IS NULL), score DESC, id DESC LIMIT " . max(1, min(200, $limit)));
            $s->execute([$this->siteId]);
            return $s->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }

    public function events(int $actionId): array
    {
        try {
            $s = $this->db->prepare("SELECT event, actor, detail, created_at FROM mia_action_events WHERE site_id = ? AND action_id = ? ORDER BY id");
            $s->execute([$this->siteId, $actionId]);
            return $s->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }

    public function counts(): array
    {
        try {
            $s = $this->db->prepare("SELECT status, COUNT(*) FROM mia_actions WHERE site_id = ? GROUP BY status");
            $s->execute([$this->siteId]);
            return $s->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    /** Proposals nobody looked at for STALE_DAYS stop cluttering the list. Returns how many expired. */
    public function expireStale(?DateTimeImmutable $now = null): int
    {
        $now = $now ?: new DateTimeImmutable('now');
        try {
            $cut = $now->modify('-' . self::STALE_DAYS . ' days')->format('Y-m-d H:i:s');
            $ids = $this->db->prepare("SELECT id FROM mia_actions WHERE site_id = ? AND status = 'proposed' AND proposed_at < ?");
            $ids->execute([$this->siteId, $cut]);
            $n = 0;
            foreach ($ids->fetchAll(PDO::FETCH_COLUMN) as $id) {
                $done = $this->atomic(function () use ($id, $now) {
                    $u = $this->db->prepare("UPDATE mia_actions SET status = 'expired' WHERE id = ? AND site_id = ? AND status = 'proposed'");
                    $u->execute([$id, $this->siteId]);
                    if ($u->rowCount() !== 1) { return false; }
                    if (!$this->event((int)$id, 'expired', 'system', 'No decision in ' . self::STALE_DAYS . ' days', $now)) { throw new RuntimeException('audit event could not be written'); }
                    return true;
                });
                if ($done) { $n++; }
            }
            return $n;
        } catch (Throwable $e) {
            return 0;
        }
    }

    // ---- internals ----------------------------------------------------------------------------

    /**
     * Two parallel proposals for the same subject must not both pass the "is one already open?" check.
     * MySQL: a named advisory lock for this site+type+subject. Other drivers (the SQLite used in tests) run
     * single-connection, so no lock is needed there.
     */
    private function lockName(string $type, string $key): string
    {
        return 'mia_prop_' . md5($this->siteId . '|' . $type . '|' . $key);
    }

    private function acquire(string $name): bool
    {
        if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') { return true; }
        try {
            $s = $this->db->prepare("SELECT GET_LOCK(?, 5)");
            $s->execute([$name]);
            return (int)$s->fetchColumn() === 1;
        } catch (Throwable $e) {
            return false;
        }
    }

    private function release(string $name): void
    {
        if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') { return; }
        try { $this->db->prepare("SELECT RELEASE_LOCK(?)")->execute([$name]); } catch (Throwable $e) { /* released on disconnect */ }
    }

    /** Run $fn all-or-nothing. Uses a transaction, or a savepoint when the caller already has one open. */
    private function atomic(callable $fn)
    {
        $own = !$this->db->inTransaction();
        $sp  = 'mia_sp_' . substr(md5((string)mt_rand()), 0, 8);
        if ($own) { $this->db->beginTransaction(); } else { $this->db->exec("SAVEPOINT {$sp}"); }
        try {
            $r = $fn();
            if ($own) { $this->db->commit(); } else { $this->db->exec("RELEASE SAVEPOINT {$sp}"); }
            return $r;
        } catch (Throwable $e) {
            try {
                if ($own) { if ($this->db->inTransaction()) { $this->db->rollBack(); } } else { $this->db->exec("ROLLBACK TO SAVEPOINT {$sp}"); }
            } catch (Throwable $ignore) { /* original error matters more */ }
            throw $e;
        }
    }

    private function row(int $id): ?array
    {
        try {
            $s = $this->db->prepare("SELECT * FROM mia_actions WHERE id = ? AND site_id = ?");
            $s->execute([$id, $this->siteId]);
            $r = $s->fetch(PDO::FETCH_ASSOC);
            return $r ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }

    private function event(int $actionId, string $event, string $actor, ?string $detail, ?DateTimeImmutable $now = null): bool
    {
        try {
            $this->db->prepare("INSERT INTO mia_action_events (site_id, action_id, event, actor, detail, created_at) VALUES (?, ?, ?, ?, ?, ?)")
                ->execute([$this->siteId, $actionId, $event, $actor, $detail !== null ? mb_substr($detail, 0, 1000) : null,
                           ($now ?: new DateTimeImmutable('now'))->format('Y-m-d H:i:s')]);
            return true;
        } catch (Throwable $e) {
            error_log('MiaActionService event: ' . $e->getMessage());
            return false;
        }
    }

    private function fail(string $code, string $error): array
    {
        return ['ok' => false, 'code' => $code, 'error' => $error, 'id' => null, 'existing' => false];
    }
}
