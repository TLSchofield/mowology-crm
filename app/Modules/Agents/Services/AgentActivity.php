<?php
declare(strict_types=1);

require_once __DIR__ . '/AgentPolicy.php';
require_once dirname(__DIR__, 3) . '/Services/TenantContext.php';

/**
 * AgentActivity — the one ledger of what the heads did and who approved it (Ittai audit 2026-10-27, stage 3).
 *
 * Before this each head kept its own table (yui_actions, sam_followups, otto_suggestions, …), so nobody could
 * ask "what did the AI office do today, and what did I approve?". record() writes one row and NEVER throws;
 * run() wraps an existing action method: it asks AgentPolicy, runs the action, then records executed / failed /
 * blocked from the action's own result (['ok' => bool, 'message' => …] — the shape every head already returns).
 *
 *   return AgentActivity::run($this->db, ['head' => 'yui', 'action' => 'yui.send_reply', 'skill' => 'send_reply',
 *       'entity_type' => 'yui_item', 'entity_id' => $item['key'], 'user_id' => (int)$user['id'], 'approved' => true,
 *       'reversible' => false], fn() => $this->doSend($item, $in, $user, $owner));
 *
 * 'approved' is true when a person clicked (every current head action starts from a click).
 * Global namespace; require_once it.
 */
final class AgentActivity
{
    /** Write a ledger row. Returns the id, or null when it could not be written. Never throws. */
    public static function record(PDO $db, array $e): ?int
    {
        try {
            $db->prepare("INSERT INTO agent_activity (site_id, head, skill, action_key, entity_type, entity_id, decision, level_required,
                                                      approver_user_id, result, reversible, cost_usd, correlation_id, shadow, created_at)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
               ->execute([
                   (int)($e['site_id'] ?? TenantContext::siteId($db)),
                   mb_substr((string)($e['head'] ?? ''), 0, 24),
                   mb_substr((string)($e['skill'] ?? ''), 0, 48),
                   mb_substr((string)($e['action'] ?? ''), 0, 64),
                   mb_substr((string)($e['entity_type'] ?? ''), 0, 32),
                   mb_substr((string)($e['entity_id'] ?? ''), 0, 64),
                   (string)($e['decision'] ?? 'executed'),
                   isset($e['level']) ? (int)$e['level'] : null,
                   !empty($e['user_id']) ? (int)$e['user_id'] : null,
                   isset($e['result']) ? mb_substr((string)$e['result'], 0, 255) : null,
                   array_key_exists('reversible', $e) && $e['reversible'] !== null ? (int)(bool)$e['reversible'] : null,
                   $e['cost_usd'] ?? null,
                   isset($e['correlation_id']) ? mb_substr((string)$e['correlation_id'], 0, 64) : null,
                   !empty($e['shadow']) ? 1 : 0,
                   date('Y-m-d H:i:s'),
               ]);
            return (int)$db->lastInsertId();
        } catch (Throwable $ex) {
            error_log('AgentActivity: ' . $ex->getMessage());
            return null;
        }
    }

    /**
     * Run $do (the real action) under the policy and record what happened.
     * A kill switch (or, when enforcing, a policy block) returns ['ok' => false, 'blocked' => true, 'message' => …]
     * without running $do. Anything $do throws is recorded as failed and rethrown unchanged.
     *
     * @param array{head:string, action:string, skill?:string, entity_type?:string, entity_id?:string|int, user_id?:int,
     *              approved?:bool, reversible?:bool, correlation_id?:string} $meta
     */
    public static function run(PDO $db, array $meta, callable $do)
    {
        $head = (string)$meta['head'];
        $action = (string)$meta['action'];
        $policy = ['allow' => true, 'shadow' => false, 'level' => AgentPolicy::DEFAULT_LEVEL, 'reason' => ''];
        try {
            $policy = AgentPolicy::check($db, $head, $action, !empty($meta['approved']));
        } catch (Throwable $e) {
            error_log('AgentActivity policy: ' . $e->getMessage());
        }
        $base = $meta + ['level' => $policy['level'], 'shadow' => $policy['shadow']];

        if (!$policy['allow']) {
            self::record($db, ['decision' => 'blocked', 'result' => $policy['reason']] + $base);
            return ['ok' => false, 'blocked' => true, 'message' => 'This action is switched off right now (' . $policy['reason'] . ').'];
        }

        try {
            $res = $do();
        } catch (Throwable $e) {
            self::record($db, ['decision' => 'failed', 'result' => $e->getMessage()] + $base);
            throw $e;
        }
        $ok = !is_array($res) || !array_key_exists('ok', $res) || !empty($res['ok']);
        $msg = is_array($res) ? (string)($res['message'] ?? $res['error'] ?? '') : '';
        if ($policy['shadow'] && $policy['reason'] !== '') {
            $msg = trim('[shadow: ' . $policy['reason'] . '] ' . $msg);
        }
        self::record($db, ['decision' => $ok ? 'executed' : 'failed', 'result' => $msg !== '' ? $msg : null] + $base);
        return $res;
    }
}
