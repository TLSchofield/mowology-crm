<?php
declare(strict_types=1);

require_once __DIR__ . '/AgentRegistry.php';
require_once __DIR__ . '/SkillRegistry.php';
require_once __DIR__ . '/AgentPolicy.php';
require_once __DIR__ . '/AgentEvals.php';
require_once __DIR__ . '/TaskService.php';

/**
 * AgentStudioService — read-only data for the Agent Studio page (Ittai audit 2026-10-27, stage 6).
 *
 * The Studio is a VIEW over the layers built in stages 1-5: who each head is (AgentRegistry), what it can do
 * (SkillRegistry), what it is allowed to do (AgentPolicy), what it is doing (TaskService), what it did and who
 * approved it (agent_activity), how well it does it (AgentEvals) and what it costs (llm_calls). It adds no logic of
 * its own and writes nothing. Every query fails soft to an empty result, so the page renders before the migrations
 * have been run (it says so) instead of failing.
 *
 * Global namespace; require_once it.
 */
final class AgentStudioService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** Which of the stage tables exist yet. @return array<string,bool> */
    public function readiness(): array
    {
        $out = [];
        foreach (['llm_calls' => 'Cost log (1400)', 'agent_activity' => 'Activity ledger (1401)', 'agent_tasks' => 'Tasks (1402)', 'agent_eval_runs' => 'Eval results (1403)'] as $t => $label) {
            try {
                $this->db->query("SELECT 1 FROM {$t} LIMIT 0");
                $out[$label] = true;
            } catch (Throwable $e) {
                $out[$label] = false;
            }
        }
        return $out;
    }

    /** @return array{kill_all:bool, enforcing:bool, killed_heads:string[]} */
    public function switches(): array
    {
        $killed = [];
        foreach (array_keys(AgentRegistry::heads()) as $h) {
            if (LlmGateway::killed($this->db, $h) && !LlmGateway::killed($this->db, '')) {
                $killed[] = $h;
            }
        }
        return [
            'kill_all'     => LlmGateway::killed($this->db, ''),
            'enforcing'    => AgentPolicy::enforcing($this->db),
            'killed_heads' => $killed,
        ];
    }

    /** One row per head for the overview. @return array<string, array<string,mixed>> */
    public function overview(int $days = 30): array
    {
        $tasks = [];
        try {
            $tasks = (new TaskService($this->db))->summary();
        } catch (Throwable $e) { /* table not there yet */ }
        $cost = $this->costByHead($days);
        $last = $this->lastActivityByHead();
        $out = [];
        foreach (AgentRegistry::heads() as $slug => $h) {
            $t = $tasks[$slug] ?? [];
            $open = (int)($t['open'] ?? 0) + (int)($t['working'] ?? 0) + (int)($t['waiting_approval'] ?? 0);
            $out[$slug] = $h + [
                'slug'        => $slug,
                'skills'      => count(SkillRegistry::forHead($slug)),
                'open_tasks'  => $open,
                'waiting'     => (int)($t['waiting_approval'] ?? 0),
                'failed'      => (int)($t['failed'] ?? 0),
                'calls'       => (int)($cost[$slug]['calls'] ?? 0),
                'cost_usd'    => (float)($cost[$slug]['cost_usd'] ?? 0),
                'last_action' => $last[$slug] ?? null,
            ];
        }
        return $out;
    }

    /** Policy rows for one head: every action it owns, with the level in force. */
    public function autonomy(string $head): array
    {
        $names = ['Observe', 'Recommend', 'Prepare (you approve)', 'Limited autonomy', 'Delegated autonomy'];
        $rows = [];
        foreach (AgentPolicy::DEFAULTS as $action => $default) {
            if (strpos($action, $head . '.') !== 0) {
                continue;
            }
            $lvl = AgentPolicy::level($this->db, $head, $action);
            $rows[] = ['action' => $action, 'level' => $lvl, 'label' => $names[$lvl] ?? (string)$lvl, 'default' => $default === $lvl];
        }
        return $rows;
    }

    /** Last run of each cron that wakes the head. @return array[] */
    public function triggers(string $head): array
    {
        $keys = AgentRegistry::heads()[$head]['crons'] ?? [];
        $out = [];
        foreach ($keys as $key) {
            $row = null;
            try {
                $s = $this->db->prepare("SELECT status, summary, ran_at FROM cron_runs WHERE cron_key = ? ORDER BY ran_at DESC, id DESC LIMIT 1");
                $s->execute([$key]);
                $row = $s->fetch(PDO::FETCH_ASSOC) ?: null;
            } catch (Throwable $e) { /* no cron_runs yet */ }
            $out[] = ['key' => $key, 'status' => $row['status'] ?? null, 'summary' => $row['summary'] ?? null, 'ran_at' => $row['ran_at'] ?? null];
        }
        return $out;
    }

    public function openTasks(string $head, int $limit = 15): array
    {
        try {
            return (new TaskService($this->db))->openFor($head, $limit);
        } catch (Throwable $e) {
            return [];
        }
    }

    public function activity(string $head, int $limit = 15): array
    {
        try {
            $s = $this->db->prepare("SELECT a.skill, a.action_key, a.entity_type, a.entity_id, a.decision, a.result, a.shadow, a.created_at, u.username AS approver
                                     FROM agent_activity a LEFT JOIN users u ON u.id = a.approver_user_id
                                     WHERE a.head = ? ORDER BY a.id DESC LIMIT " . max(1, min(100, $limit)));
            $s->execute([$head]);
            return $s->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }

    /** Cost lines for one head: skill x model over $days. @return array[] */
    public function cost(string $head, int $days = 30): array
    {
        try {
            $s = $this->db->prepare("SELECT skill, model, COUNT(*) calls, SUM(outcome <> 'ok') problems, SUM(input_tokens) tin, SUM(output_tokens) tout, SUM(cost_usd) usd
                                     FROM llm_calls WHERE head = ? AND created_at >= ? GROUP BY skill, model ORDER BY usd DESC, calls DESC");
            $s->execute([$head, date('Y-m-d H:i:s', time() - $days * 86400)]);
            return $s->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }

    /** @return array<string, array{calls:int, cost_usd:float}> */
    public function costByHead(int $days = 30): array
    {
        $out = [];
        try {
            $s = $this->db->prepare("SELECT head, COUNT(*) calls, SUM(cost_usd) usd FROM llm_calls WHERE created_at >= ? GROUP BY head");
            $s->execute([date('Y-m-d H:i:s', time() - $days * 86400)]);
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[(string)$r['head']] = ['calls' => (int)$r['calls'], 'cost_usd' => (float)$r['usd']];
            }
        } catch (Throwable $e) { /* table not there yet */ }
        return $out;
    }

    /** Spend by day across all heads. @return array<string,float> */
    public function costByDay(int $days = 14): array
    {
        $out = [];
        try {
            $s = $this->db->prepare("SELECT DATE(created_at) d, SUM(cost_usd) usd FROM llm_calls WHERE created_at >= ? GROUP BY DATE(created_at) ORDER BY d");
            $s->execute([date('Y-m-d 00:00:00', time() - $days * 86400)]);
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[(string)$r['d']] = (float)$r['usd'];
            }
        } catch (Throwable $e) { /* table not there yet */ }
        return $out;
    }

    /** @return array<string, string> head => latest created_at */
    private function lastActivityByHead(): array
    {
        $out = [];
        try {
            foreach ($this->db->query("SELECT head, MAX(created_at) t FROM agent_activity GROUP BY head")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[(string)$r['head']] = (string)$r['t'];
            }
        } catch (Throwable $e) { /* table not there yet */ }
        return $out;
    }

    public function evals(): array
    {
        return AgentEvals::latest($this->db);
    }
}
