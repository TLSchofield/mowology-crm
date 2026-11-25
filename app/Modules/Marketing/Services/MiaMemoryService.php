<?php
/**
 * MiaMemoryService — what Mia remembers about this business and about the owner's decisions
 * (docs/mia/MIA-DATABASE.md: mia_memory, mia_decisions). Structured records are the source of truth;
 * no conversation history is replayed.
 *
 *   - facts: brand voice, approved wording, things never to say, priorities (mia_memory, one row per kind+key);
 *   - decisions: approved / rejected / later per subject, with the evidence hash at the time, so a rejected
 *     idea is not proposed again until it has expired or its evidence has materially changed.
 *
 * Every query binds site_id. Methods do not throw: they return false/null/[] and log (the constructor refuses site 0).
 * Global namespace, no autoloader: require_once it. PHP 7.4-compatible.
 */
declare(strict_types=1);

final class MiaMemoryService
{
    public const KINDS = ['brand', 'approved_message', 'prohibited', 'priority', 'style'];

    /** How long a rejection holds, by reason. A different reason is a different kind of "no". */
    public const SUPPRESS_DAYS = [
        'not_our_service' => 365,
        'not_our_voice'   => 180,
        'bad_timing'      => 30,
        'other'           => 120,
    ];
    public const LATER_DAYS = 7;
    /** An approved subject is not proposed again for this long (it was done, or is waiting to be). */
    public const APPROVED_QUIET_DAYS = 45;

    /** @var PDO */
    private $db;
    /** @var int */
    private $siteId;

    public function __construct(PDO $db, int $siteId)
    {
        if ($siteId <= 0) { throw new InvalidArgumentException('MiaMemoryService needs a site'); }
        $this->db     = $db;
        $this->siteId = $siteId;
    }

    // ---- facts --------------------------------------------------------------------------------

    public function remember(string $kind, string $key, string $value, string $source = 'owner', ?int $userId = null): bool
    {
        $key = trim($key);
        if (!in_array($kind, self::KINDS, true) || $key === '' || mb_strlen($key) > 80 || trim($value) === '') { return false; }
        $source = $source === 'learned' ? 'learned' : 'owner';
        try {
            $s = $this->db->prepare("SELECT id, source FROM mia_memory WHERE site_id = ? AND kind = ? AND mem_key = ?");
            $s->execute([$this->siteId, $kind, $key]);
            $row = $s->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                // What the owner said is never overwritten by something Mia inferred.
                if ($row['source'] === 'owner' && $source === 'learned') { return false; }
                $this->db->prepare("UPDATE mia_memory SET mem_value = ?, source = ?, updated_by = ? WHERE id = ? AND site_id = ?")
                    ->execute([mb_substr($value, 0, 4000), $source, $userId, $row['id'], $this->siteId]);
            } else {
                $this->db->prepare("INSERT INTO mia_memory (site_id, kind, mem_key, mem_value, source, updated_by) VALUES (?, ?, ?, ?, ?, ?)")
                    ->execute([$this->siteId, $kind, $key, mb_substr($value, 0, 4000), $source, $userId]);
            }
            return true;
        } catch (Throwable $e) {
            error_log('MiaMemory remember: ' . $e->getMessage());
            return false;
        }
    }

    public function recall(string $kind, string $key): ?string
    {
        try {
            $s = $this->db->prepare("SELECT mem_value FROM mia_memory WHERE site_id = ? AND kind = ? AND mem_key = ?");
            $s->execute([$this->siteId, $kind, $key]);
            $v = $s->fetchColumn();
            return $v === false ? null : (string)$v;
        } catch (Throwable $e) {
            return null;
        }
    }

    /** @return array<string,string> key => value */
    public function all(string $kind): array
    {
        try {
            $s = $this->db->prepare("SELECT mem_key, mem_value FROM mia_memory WHERE site_id = ? AND kind = ? ORDER BY mem_key");
            $s->execute([$this->siteId, $kind]);
            return $s->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    public function forget(string $kind, string $key): bool
    {
        try {
            $s = $this->db->prepare("DELETE FROM mia_memory WHERE site_id = ? AND kind = ? AND mem_key = ?");
            $s->execute([$this->siteId, $kind, $key]);
            return $s->rowCount() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    // ---- decisions ----------------------------------------------------------------------------

    /**
     * Record what the owner decided. $decision: approved | rejected | later.
     * Returns the date the subject stays quiet until (Y-m-d), or null for none / on failure.
     */
    public function recordDecision(string $subjectKey, ?string $actionType, ?string $evidenceHash, string $decision,
                                   ?string $reasonCode, ?string $reasonText, ?int $userId, ?DateTimeImmutable $now = null): ?string
    {
        if (!in_array($decision, ['approved', 'rejected', 'later'], true) || $subjectKey === '') { return null; }
        $now = $now ?: new DateTimeImmutable('now');
        $days = 0;
        if ($decision === 'rejected') {
            $days = self::SUPPRESS_DAYS[$reasonCode ?? 'other'] ?? self::SUPPRESS_DAYS['other'];
        } elseif ($decision === 'later') {
            $days = self::LATER_DAYS;
        } elseif ($decision === 'approved') {
            $days = self::APPROVED_QUIET_DAYS;
        }
        $until = $now->modify("+{$days} days")->format('Y-m-d');
        try {
            $this->db->prepare("INSERT INTO mia_decisions (site_id, subject_key, action_type, evidence_hash, decision, reason_code, reason_text, decided_by, decided_at, suppress_until)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                ->execute([$this->siteId, mb_substr($subjectKey, 0, 120), $actionType, $evidenceHash, $decision,
                           $reasonCode !== null ? mb_substr($reasonCode, 0, 30) : null,
                           $reasonText !== null ? mb_substr($reasonText, 0, 400) : null,
                           $userId, $now->format('Y-m-d H:i:s'), $until]);
            return $until;
        } catch (Throwable $e) {
            error_log('MiaMemory recordDecision: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Should this subject stay quiet? Looks at every decision for this subject (and kind of action) whose quiet
     * period has not ended, newest first; the first one that APPLIES decides:
     *  - rejected with the same evidence hash  -> suppressed ('rejected')  (a different hash means the evidence
     *    materially changed, so that rejection does not apply and the search goes on to older rows);
     *  - later                                 -> suppressed ('later');
     *  - approved                              -> suppressed ('already_approved').
     * If the decisions cannot be read the answer is "suppressed" with reason 'unreadable' (fail closed).
     * @return array{suppressed:bool,reason:?string,until:?string}
     */
    public function suppression(string $subjectKey, ?string $evidenceHash, ?DateTimeImmutable $now = null, ?string $actionType = null): array
    {
        $today = ($now ?: new DateTimeImmutable('now'))->format('Y-m-d');
        try {
            $s = $this->db->prepare("SELECT decision, evidence_hash, suppress_until FROM mia_decisions
                                      WHERE site_id = ? AND subject_key = ? AND (? IS NULL OR action_type = ?)
                                        AND suppress_until IS NOT NULL AND suppress_until >= ?
                                      ORDER BY decided_at DESC, id DESC");
            $s->execute([$this->siteId, $subjectKey, $actionType, $actionType, $today]);
            $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('MiaMemory suppression: ' . $e->getMessage());
            return ['suppressed' => true, 'reason' => 'unreadable', 'until' => null];
        }
        foreach ($rows as $d) {
            if ($d['decision'] === 'rejected') {
                if ($evidenceHash !== null && $d['evidence_hash'] !== null && $d['evidence_hash'] !== $evidenceHash) { continue; }
                return ['suppressed' => true, 'reason' => 'rejected', 'until' => $d['suppress_until']];
            }
            if ($d['decision'] === 'later') {
                return ['suppressed' => true, 'reason' => 'later', 'until' => $d['suppress_until']];
            }
            return ['suppressed' => true, 'reason' => 'already_approved', 'until' => $d['suppress_until']];
        }
        return ['suppressed' => false, 'reason' => null, 'until' => null];
    }

    /** Recent decisions for the owner's history view and for prompting ("you rejected X because Y"). */
    public function recentDecisions(int $limit = 20): array
    {
        try {
            $s = $this->db->prepare("SELECT subject_key, action_type, decision, reason_code, reason_text, decided_at, suppress_until
                                       FROM mia_decisions WHERE site_id = ? ORDER BY decided_at DESC, id DESC LIMIT " . max(1, min(100, $limit)));
            $s->execute([$this->siteId]);
            return $s->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }
}
