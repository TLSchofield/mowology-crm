<?php
/**
 * MiaAutonomyPolicy — how much Mia may do on her own, per kind of action (docs/mia/MIA-ARCHITECTURE.md §2.1).
 *
 *   0 Observe      reports only, no proposals
 *   1 Recommend    proposals the owner approves, one by one       <-- the only level Phase 1 allows
 *   2 Controlled   (reserved) low-risk internal work
 *   3 Delegated    (reserved) scoped, expiring grants
 *
 * Phase 1 clamps everything to level 1, whatever is stored: a stray setting must never be able to switch
 * on autonomous publishing before the executor, grants and rollback exist. Raising MAX_LEVEL is a code
 * change on purpose. The kill switch (ops_settings mia_paused = 1) stops new proposals.
 *
 * Enforced in PHP at propose/execute time — never in a prompt, never from model output.
 * Global namespace, no autoloader: require_once it.
 */
declare(strict_types=1);

final class MiaAutonomyPolicy
{
    public const OBSERVE = 0;
    public const RECOMMEND = 1;
    public const CONTROLLED = 2;
    public const DELEGATED = 3;

    /** Highest level this build will honour. */
    public const MAX_LEVEL = 1;
    public const DEFAULT_LEVEL = 1;

    /** Kinds of change that are never eligible for level 2 or 3, however it is configured later. */
    public const NEVER_AUTONOMOUS = ['home_page', 'navigation', 'legal_page', 'delete', 'pricing_text', 'spend'];

    /** @var PDO */
    private $db;
    /** @var int */
    private $siteId;

    public function __construct(PDO $db, int $siteId = 1)
    {
        if ($siteId <= 0) { throw new InvalidArgumentException('MiaAutonomyPolicy needs a site'); }
        $this->db     = $db;
        $this->siteId = $siteId;
    }

    public function isPaused(): bool
    {
        return $this->setting('mia_paused') === '1';
    }

    /** The level in force for an action type: specific setting, else the default, clamped to what this build allows. */
    public function levelFor(string $type): int
    {
        $raw = $this->setting('mia_autonomy_' . $type);
        if ($raw === null) { $raw = $this->setting('mia_autonomy_default'); }
        $level = ($raw !== null && ctype_digit($raw)) ? (int)$raw : self::DEFAULT_LEVEL;
        return max(self::OBSERVE, min(self::MAX_LEVEL, $level));
    }

    /** May Mia put a proposal in front of the owner? */
    public function mayPropose(string $type): bool
    {
        return !$this->isPaused() && $this->levelFor($type) >= self::RECOMMEND;
    }

    /**
     * May this action run without a fresh owner approval? Always false in Phase 1: every change waits
     * for a person. (Later: level 3 + a live grant + not in NEVER_AUTONOMOUS + within limits.)
     */
    public function mayRunWithoutApproval(string $type, string $sideEffect): bool
    {
        return false;
    }

    /**
     * ops_settings has no site column, so a per-site value lives under "mia_s<site>_<name>" and wins over the
     * shared "mia_..." key. The shared key is deliberate: it is the owner's global kill switch / default and
     * applies to every site that has no override of its own.
     */
    private function setting(string $key): ?string
    {
        try {
            $s = $this->db->prepare("SELECT setting_value FROM ops_settings WHERE setting_key = ?");
            foreach ([preg_replace('/^mia_/', 'mia_s' . $this->siteId . '_', $key), $key] as $k) {
                $s->execute([$k]);
                $v = $s->fetchColumn();
                if ($v !== false) { return trim((string)$v); }
            }
            return null;
        } catch (Throwable $e) {
            return null;
        }
    }
}
