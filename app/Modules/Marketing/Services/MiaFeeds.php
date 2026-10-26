<?php
declare(strict_types=1);

require_once __DIR__ . '/MiaServiceKey.php';
require_once __DIR__ . '/MiaFunnelRules.php';

/**
 * Phase 3.1: read-only feeds Mia takes from the other heads. SELECT only, and no client, property or reviewer name or
 * comment text ever leaves this class: ids, counts and rates only (the text of feedback waits for the legal review, D10).
 * Each feed says what it could not see (`gaps`) so an empty answer is never mistaken for "nothing happened".
 */
final class MiaFeeds
{
    /** Photo-pair consent states that allow use (ba_pairs.consent_state: not_needed | needed | recorded). */
    public const CONSENT_OK = ['not_needed', 'recorded'];

    private PDO $db;

    public function __construct(PDO $db) { $this->db = $db; }

    /**
     * Otto: completed visits with an approved before/after pair whose consent allows use.
     * @return array{visits:array<int,array>, gaps:string[]}
     */
    public function completedVisitsWithApprovedPhotos(int $sinceDays = 30, int $limit = 50): array
    {
        $since = date('Y-m-d H:i:s', time() - max(1, $sinceDays) * 86400);
        $in = implode(',', array_fill(0, count(self::CONSENT_OK), '?'));
        $st = $this->db->prepare(
            "SELECT p.id AS pair_id, p.visit_id, p.service, p.area, p.consent_state, v.completed_at, v.service_type
               FROM ba_pairs p JOIN job_visits v ON v.id = p.visit_id
              WHERE p.status = 'approved' AND p.consent_state IN ($in)
                AND v.status = 'completed' AND v.completed_at >= ?
              ORDER BY v.completed_at DESC LIMIT " . (int)max(1, min(200, $limit))
        );
        $st->execute(array_merge(self::CONSENT_OK, [$since]));
        $rows = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rows[] = ['pair_id' => (int)$r['pair_id'], 'visit_id' => (int)$r['visit_id'], 'completed_at' => $r['completed_at'],
                       'service_key' => MiaServiceKey::keyFor($r['service'] ?: $r['service_type']), 'area' => $r['area'], 'consent' => $r['consent_state']];
        }
        return ['visits' => $rows, 'gaps' => $rows ? [] : ['no completed visit in the window has an approved pair with usable consent']];
    }

    /**
     * Penny: margin per neutral service key. Rows whose margin is unknown (no labor figure) are counted but never averaged.
     * @return array{services:array<string,array>, gaps:string[]}
     */
    public function marginByService(int $sinceDays = 365): array
    {
        $since = date('Y-m-d', time() - max(1, $sinceDays) * 86400);
        $st = $this->db->prepare("SELECT service_type, quoted_amount, margin_pct FROM visit_margin_snapshots WHERE visit_date >= ?");
        $st->execute([$since]);
        $by = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $k = MiaServiceKey::keyFor($r['service_type']);
            $by[$k] = $by[$k] ?? ['visits' => 0, 'with_margin' => 0, 'sum' => 0.0];
            $by[$k]['visits']++;
            if ($r['margin_pct'] !== null) { $by[$k]['with_margin']++; $by[$k]['sum'] += (float)$r['margin_pct']; }
        }
        $out = []; $gaps = [];
        foreach ($by as $k => $b) {
            $out[$k] = ['visits' => $b['visits'], 'with_margin' => $b['with_margin'], 'unknown' => $b['visits'] - $b['with_margin'],
                        'avg_margin_pct' => $b['with_margin'] > 0 ? round($b['sum'] / $b['with_margin'], 1) : null];
            if ($b['with_margin'] < 5) { $gaps[] = "$k: fewer than 5 visits with a known margin, too thin to act on"; }
        }
        ksort($out);
        return ['services' => $out, 'gaps' => $gaps];
    }

    /**
     * Sam: quote-request funnel by the agreed won rule.
     * @return array{funnel:array, by_service:array<string,array>, gaps:string[]}
     */
    public function funnel(int $sinceDays = 365): array
    {
        $since = date('Y-m-d H:i:s', time() - max(1, $sinceDays) * 86400);
        $st = $this->db->prepare("SELECT status, service_types FROM quote_requests WHERE created_at >= ?");
        $st->execute([$since]);
        $all = []; $perSvc = []; $noService = 0;
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $s = (string)$r['status'];
            $all[$s] = ($all[$s] ?? 0) + 1;
            $raw = trim((string)$r['service_types']);
            if ($raw === '') { $noService++; continue; }
            foreach (array_unique(array_map('MiaServiceKey::keyFor', preg_split('/\s*,\s*/', $raw))) as $k) {
                $perSvc[$k][$s] = ($perSvc[$k][$s] ?? 0) + 1;
            }
        }
        $by = [];
        foreach ($perSvc as $k => $counts) { $by[$k] = MiaFunnelRules::summarise($counts); }
        ksort($by);
        $gaps = [];
        if ($noService > 0) { $gaps[] = "$noService request(s) have no service recorded and are left out of by_service"; }
        return ['funnel' => MiaFunnelRules::summarise($all), 'by_service' => $by, 'gaps' => $gaps];
    }

    /**
     * Yui: review and feedback ratings only. Never names or comment text.
     * @return array{ratings:array, gaps:string[]}
     */
    public function feedbackSummary(int $sinceDays = 365): array
    {
        $since = date('Y-m-d H:i:s', time() - max(1, $sinceDays) * 86400);
        $res = ['client_feedback' => ['n' => 0, 'avg' => null], 'google_reviews' => ['n' => 0, 'avg' => null, 'unreplied' => 0]];
        $st = $this->db->prepare("SELECT COUNT(rating) n, AVG(rating) a FROM client_feedback WHERE created_at >= ? AND rating IS NOT NULL");
        $st->execute([$since]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        $res['client_feedback'] = ['n' => (int)$r['n'], 'avg' => $r['n'] ? round((float)$r['a'], 2) : null];
        $st = $this->db->prepare("SELECT COUNT(rating) n, AVG(rating) a, SUM(replied_at IS NULL) u FROM gbp_reviews WHERE created_at >= ? AND rating IS NOT NULL");
        $st->execute([$since]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        $res['google_reviews'] = ['n' => (int)$r['n'], 'avg' => $r['n'] ? round((float)$r['a'], 2) : null, 'unreplied' => (int)$r['u']];
        $gaps = [];
        if ($res['client_feedback']['n'] === 0) { $gaps[] = 'no client feedback ratings recorded'; }
        if ($res['google_reviews']['n'] === 0) { $gaps[] = 'no Google reviews synced (the Google API approval is pending)'; }
        return ['ratings' => $res, 'gaps' => $gaps];
    }
}
