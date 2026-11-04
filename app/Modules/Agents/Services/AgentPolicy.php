<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/Services/Llm/LlmGateway.php';

/**
 * AgentPolicy — what each head may do, per ACTION (Ittai audit 2026-10-27, stage 3).
 *
 * Levels: 0 observe · 1 recommend · 2 prepare (a person approves before it runs) · 3 limited autonomy ·
 * 4 delegated autonomy. Autonomy is set per action, so authorising "Penny may auto-apply a confirmed bank
 * rule" grants nothing about "Penny approves receipts". DEFAULTS is today's behaviour: every action is level 2
 * (the heads prepare, you click) except bank rules, which already act alone after two owner confirmations.
 * ops_settings / agent_action_policy rows override a default.
 *
 * Two switches with different strength:
 *   - the kill switch (agents_kill_all / agents_kill_<head> = 1) ALWAYS blocks, at once;
 *   - the level rules only block when agents_policy_enforce = 1. Until then check() answers what it WOULD
 *     decide and marks it shadow, and the caller carries on — so turning this on can never surprise anyone.
 *
 * Every read fails open (a database error never stops a human action); the kill switch is read the same way as
 * LlmGateway::killed. Global namespace; require_once it.
 */
final class AgentPolicy
{
    public const OBSERVE = 0, RECOMMEND = 1, PREPARE = 2, LIMITED = 3, DELEGATED = 4;

    /** action_key => level. Money-moving actions must stay at PREPARE until the owner raises them per action. */
    public const DEFAULTS = [
        'yui.send_reply'         => 2,
        'sam.send_followup'      => 2,
        'sam.accept_quote'       => 2,
        'otto.apply_suggestion'  => 2,
        'penny.approve_receipt'  => 2,
        'penny.bank_rule_apply'  => 3,
        'mia.approve_campaign'   => 2,
    ];
    public const DEFAULT_LEVEL = 2;
    /** Actions that move money or post to the books never exceed PREPARE from a default or a bad row. */
    public const MONEY_CEILING = ['penny.approve_receipt' => 2];

    public static function enforcing(PDO $db): bool
    {
        return self::setting($db, 'agents_policy_enforce') === '1';
    }

    public static function killed(PDO $db, string $head): bool
    {
        return LlmGateway::killed($db, $head);
    }

    /** The level in force for an action: a policy row, else the code default, capped by any ceiling. */
    public static function level(PDO $db, string $head, string $action): int
    {
        $lvl = self::DEFAULTS[$action] ?? self::DEFAULT_LEVEL;
        $row = self::row($db, $head, $action);
        if ($row !== null) {
            $lvl = max(0, min(4, (int)$row['level']));
        }
        if (isset(self::MONEY_CEILING[$action])) {
            $lvl = min($lvl, self::MONEY_CEILING[$action]);
        }
        return $lvl;
    }

    /**
     * @return array{allow:bool, shadow:bool, level:int, reason:string}
     * allow=false only for a kill switch, or (when enforcing) a disabled action / a level that cannot run unapproved.
     * shadow=true means "this is what the rules say, nothing was blocked".
     */
    public static function check(PDO $db, string $head, string $action, bool $humanApproved): array
    {
        $level = self::level($db, $head, $action);
        if (self::killed($db, $head)) {
            return ['allow' => false, 'shadow' => false, 'level' => $level, 'reason' => 'agents switched off'];
        }
        $reason = '';
        $would = true;
        $row = self::row($db, $head, $action);
        if ($row !== null && (int)$row['enabled'] === 0) {
            $would = false;
            $reason = 'action disabled';
        } elseif ($level <= self::RECOMMEND) {
            $would = false;
            $reason = 'level ' . $level . ' can only observe or recommend';
        } elseif ($level === self::PREPARE && !$humanApproved) {
            $would = false;
            $reason = 'level 2 needs a person to approve first';
        }
        if ($would) {
            return ['allow' => true, 'shadow' => false, 'level' => $level, 'reason' => ''];
        }
        $enforce = self::enforcing($db);
        return ['allow' => !$enforce, 'shadow' => !$enforce, 'level' => $level, 'reason' => $reason];
    }

    private static function row(PDO $db, string $head, string $action): ?array
    {
        try {
            $s = $db->prepare("SELECT level, enabled FROM agent_action_policy WHERE head = ? AND action_key = ? LIMIT 1");
            $s->execute([strtolower($head), $action]);
            $r = $s->fetch(PDO::FETCH_ASSOC);
            return $r ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }

    private static function setting(PDO $db, string $key): ?string
    {
        try {
            $s = $db->prepare("SELECT setting_value FROM ops_settings WHERE setting_key = ?");
            $s->execute([$key]);
            $v = $s->fetchColumn();
            return $v === false ? null : (string)$v;
        } catch (Throwable $e) {
            return null;
        }
    }
}
